<?php

namespace FluentCart\App\Services\Schema;

use FluentCart\Api\CurrencySettings;
use FluentCart\Api\ModuleSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Services\ProductReviewService;
use FluentCart\App\Services\Renderer\ProductReviewRenderer;
use FluentCart\Framework\Support\Arr;

/**
 * Product JSON-LD for the single product page.
 *
 * One schema.org Product node carrying what the product page sells — an
 * Offer per active variation, with price, currency and availability — and,
 * when the reviews module is on, the product's aggregate rating and the
 * individual reviews the page shows. Nothing is stored: the node is built
 * on every render from the product post, fct_product_variations, the
 * rating summary that recalculateProductRatings() maintains, and
 * fct_product_reviews. Change a price, sell out, approve or trash a review,
 * and the next request reflects it.
 *
 * Google's review snippet rules shape what goes in:
 *  - only content the reader can see on the page — approved, top-level
 *    reviews, as many as the list renders and never more than MAX_REVIEWS;
 *  - a Review needs a rating, so unrated reviews are left out of review[]
 *    while still counted in aggregateRating;
 *  - numeric ratingValue against bestRating 5 / worstRating 1, never the
 *    Poor/Excellent labels the UI shows.
 */
class ProductSchema
{
    /** Ceiling on review[] regardless of the store's page length. */
    public const MAX_REVIEWS = 20;

    /** Ceiling on the variations read for offers. */
    public const MAX_OFFERS = 100;

    /** Legacy hook name retained for existing integrations. */
    public const FILTER_HOOK = 'fluent_cart/review/json_ld';

    /**
     * The Product node for a product, or [] when there is nothing worth
     * emitting — no product, or nothing to sell and no approved reviews. A
     * Product node without offers, rating or review is not eligible for
     * anything, and emitting one would only invite a second, competing
     * node from an SEO plugin.
     *
     * @param int $postId
     * @return array
     */
    public static function get($postId): array
    {
        $postId = (int) $postId;

        /**
         * Enable Product JSON-LD generation. Return false to skip the entire
         * node before loading product, offer or review data. For example:
         * add_filter('fluent_cart/product/schema_enabled', '__return_false');
         *
         * @param bool $enabled Whether to generate schema. Defaults to true.
         * @param array $context Product post_id.
         */
        if (!apply_filters('fluent_cart/product/schema_enabled', true, ['post_id' => $postId])) {
            return [];
        }

        $product = $postId ? get_post($postId) : null;

        // Only what a visitor can see. A draft or pending product is a
        // preview for its editor; a private one is for logged-in readers; a
        // password-protected one shows its content to nobody until the
        // password is given — and the crawler gives none. Structured data
        // for any of them would publish prices and reviews the page keeps
        // back. 'private' is admitted because the buy button sells private
        // products to the readers who can open them (ProductVariation's
        // purchasability check), and a crawler never reaches the page.
        if (!$product
            || !in_array($product->post_status, ['publish', 'private'], true)
            || post_password_required($product)
        ) {
            return [];
        }

        $url = (string) get_permalink($product);
        $variations = static::activeVariations($postId);
        $offers = static::offersNode($variations, $url, static::offerBounds($postId));

        $summary = static::reviewsVisible($postId)
            ? ProductReviewService::getProductRatingSummary($postId)
            : ['total' => 0, 'average' => 0, 'breakdown' => []];
        $hasReviews = (int) Arr::get($summary, 'total', 0) > 0;

        if (!$offers && !$hasReviews) {
            return [];
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'Product',
            'name'     => static::text($product->post_title),
            'url'      => $url,
        ];

        $brands = static::brandNodes($postId);
        if ($brands) {
            $schema['brand'] = count($brands) === 1 ? $brands[0] : $brands;
        }

        $image = static::featuredImageUrl($postId, $variations);
        if ($image !== '') {
            $schema['image'] = [$image];
        }

        $description = static::description($product);
        if ($description !== '') {
            $schema['description'] = $description;
        }

        // A product-level SKU only when one variation is the product. With
        // several, each SKU sits on its own offer, and lifting the first
        // to the product would mislabel it.
        if ($offers && $offers['@type'] === 'Offer') {
            $sku = trim((string) $variations[0]->sku);
            if ($sku !== '') {
                $schema['sku'] = $sku;
            }
        }

        if ($offers) {
            $schema['offers'] = $offers;
        }

        if ($hasReviews) {
            $schema['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => (string) Arr::get($summary, 'average', 0),
                'reviewCount' => (int) Arr::get($summary, 'total', 0),
                'bestRating'  => '5',
                'worstRating' => '1',
            ];

            $reviews = static::reviewNodes($postId);
            if ($reviews) {
                $schema['review'] = $reviews;
            }
        }

        $context = [
            'post_id' => $postId,
            'summary' => $summary,
        ];

        /**
         * Filter the entire Product JSON-LD node, including offers and reviews.
         * Return an empty array to suppress output. For example:
         * add_filter('fluent_cart/product/json_ld', '__return_empty_array');
         *
         * @param array $schema Product node.
         * @param array $context Product post_id and visible review summary.
         */
        $schema = apply_filters('fluent_cart/product/json_ld', $schema, $context);

        // Keep the original hook last so existing customizations still apply.
        return apply_filters(static::FILTER_HOOK, $schema, $context);
    }

    /** Only brands assigned to this product, never the store name as a fallback. */
    protected static function brandNodes(int $postId): array
    {
        $terms = get_the_terms($postId, 'product-brands');
        if (!$terms || is_wp_error($terms)) {
            return [];
        }

        $brands = [];
        foreach ($terms as $term) {
            $name = static::text($term->name);
            if ($name !== '') {
                $brands[] = ['@type' => 'Brand', 'name' => $name];
            }
        }

        return $brands;
    }

    /**
     * Whether the page shows this product's reviews: the module on — the
     * gate TemplateActions puts before the reviews section — and then the
     * renderer's own policy: the store's Enable Product Reviews switch,
     * the product's toggle, and the single-page setting with its filter.
     * The same decision the list makes, so the schema never names a
     * review the page does not render.
     */
    protected static function reviewsVisible($postId): bool
    {
        return ModuleSettings::isActive('reviews')
            && ProductReviewRenderer::isVisibleFor($postId);
    }

    /**
     * The variations the product page offers, in its order. Active rows
     * only: a draft variation is not on the page, so it is not for sale.
     * The product and its detail come along in two queries because the
     * stock checks consult them.
     *
     * @param int $postId
     * @return ProductVariation[]
     */
    protected static function activeVariations($postId): array
    {
        $rows = ProductVariation::query()
            ->where('post_id', $postId)
            ->where('item_status', 'active')
            ->with(['product', 'product.detail'])
            ->orderBy('serial_index', 'ASC')
            ->orderBy('id', 'ASC')
            ->limit(static::MAX_OFFERS)
            ->get();

        return $rows ? $rows->all() : [];
    }

    /**
     * What the product sells as a whole, over every active variation and
     * not only the MAX_OFFERS the nested offers expand: how many, and the
     * cheapest and dearest. One aggregate query, so offerCount, lowPrice
     * and highPrice are right however many variations the product has.
     *
     * @param int $postId
     * @return array{count:int, low:float, high:float}
     */
    protected static function offerBounds($postId): array
    {
        $row = ProductVariation::query()
            ->where('post_id', $postId)
            ->where('item_status', 'active')
            ->selectRaw('COUNT(*) as offer_count, MIN(item_price) as low_price, MAX(item_price) as high_price')
            ->first();

        return [
            'count' => $row ? (int) $row->offer_count : 0,
            'low'   => $row ? (float) $row->low_price : 0.0,
            'high'  => $row ? (float) $row->high_price : 0.0,
        ];
    }

    /**
     * offers: one Offer for a single variation, an AggregateOffer for
     * several. The aggregate's count and price bounds come from every
     * active variation; its nested offers are the first MAX_OFFERS in
     * page order, so a product past that cap still states the complete
     * offering while the crawler is not handed hundreds of nodes. Prices
     * are the stored cents as a decimal string in the store currency —
     * never the formatted display price, which carries signs, separators
     * and translated digits the crawler cannot parse.
     *
     * @param ProductVariation[] $variations
     * @param string $url
     * @param array $bounds from offerBounds()
     * @return array
     */
    protected static function offersNode(array $variations, string $url, array $bounds): array
    {
        if (!$variations || (int) Arr::get($bounds, 'count', 0) < 1) {
            return [];
        }

        // CurrencySettings directly, not Helper::shopConfig(): that helper
        // also reads the store's shipping packages, which no offer needs.
        $currencySettings = CurrencySettings::get();
        $currency = strtoupper((string) Arr::get($currencySettings, 'currency', 'USD'));
        $decimals = Arr::get($currencySettings, 'is_zero_decimal') ? 0 : 2;

        // Availability the way the buy button decides it: with the stock
        // module off everything on the page is for sale, whatever the
        // stock columns hold; with it on, the product and the variation
        // both have to be in stock. Same rule as ProductRenderer's
        // direct-checkout button, so the schema never says OutOfStock for
        // a variation the page sells, or InStock for one it refuses.
        $stockManaged = ModuleSettings::isActive('stock_management');
        $product = $variations[0]->product;
        $detail = $product ? $product->detail : null;

        // The product-level flag, read from the detail already loaded with
        // the variations — the same rule as Product::isStock() short of its
        // bundle branch. That branch is not called here: it lazy-loads the
        // whole variants relation and queries the default variation's
        // children, and both are covered below, once, for every variation.
        $productInStock = !$stockManaged
            || !$detail
            || !$detail->manage_stock
            || $detail->stock_availability === Helper::IN_STOCK;

        // A bundle's stock is its children's. One query for every child of
        // every variation on the page, instead of one per variation from
        // isStock() on its own — a bundle with many variations is the case
        // MAX_OFFERS exists for. Non-bundles read nothing.
        $bundleChildren = $stockManaged && $product && $product->isBundleProduct()
            ? ProductVariation::loadBundleChildren($variations)
            : [];

        // Only pricing flags are needed here. TaxModule::getSettings() also
        // loads every EU VAT registration, which schema never uses.
        $taxSettings = wp_parse_args(get_option('fluent_cart_tax_configuration_settings', []), [
            'enable_tax' => 'no',
            'tax_inclusion' => 'included',
        ]);
        $offers = [];

        foreach ($variations as $variation) {
            $cents = (float) $variation->item_price;
            $inStock = !$stockManaged || ($productInStock && $variation->isStock($bundleChildren));

            $offer = [
                '@type'         => 'Offer',
                'url'           => $url,
                'price'         => static::price($cents, $decimals),
                'priceCurrency' => $currency,
                'availability'  => $inStock
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
            ];

            $priceSpecification = static::priceSpecification($variation, $offer, $taxSettings);
            if ($priceSpecification) {
                $offer['priceSpecification'] = $priceSpecification;
            }

            $title = static::text($variation->variation_title);
            if ($bounds['count'] > 1 && $title !== '') {
                $offer['name'] = $title;
            }

            $sku = trim((string) $variation->sku);
            if ($sku !== '') {
                $offer['sku'] = $sku;
            }

            $offers[] = $offer;
        }

        if ((int) $bounds['count'] === 1) {
            return $offers[0];
        }

        return [
            '@type'         => 'AggregateOffer',
            'url'           => $url,
            'priceCurrency' => $currency,
            'lowPrice'      => static::price($bounds['low'], $decimals),
            'highPrice'     => static::price($bounds['high'], $decimals),
            'offerCount'    => (int) $bounds['count'],
            'offers'        => $offers,
        ];
    }

    /**
     * Describe the stored offer price without applying visitor-specific taxes.
     * Variation tax overrides follow the same precedence as TaxCalculator.
     * Recurring prices use referenceQuantity for the period they purchase;
     * billingDuration is reserved for a known, finite payment term.
     */
    protected static function priceSpecification(ProductVariation $variation, array $offer, array $taxSettings): array
    {
        $subscription = $variation->payment_type === 'subscription';
        $taxEnabled = Arr::get($taxSettings, 'enable_tax', 'no') === 'yes';
        if (!$subscription && !$taxEnabled) {
            return [];
        }

        $specification = [
            '@type' => $subscription ? 'UnitPriceSpecification' : 'PriceSpecification',
            'price' => $offer['price'],
            'priceCurrency' => $offer['priceCurrency'],
        ];
        $otherInfo = $variation->other_info;

        if ($taxEnabled) {
            $inclusion = Arr::get($otherInfo, 'tax_inclusion');
            if (!in_array($inclusion, ['included', 'excluded'], true)) {
                $inclusion = Arr::get($taxSettings, 'tax_inclusion');
            }
            $specification['valueAddedTaxIncluded'] = $inclusion === 'included';
        }

        if ($subscription) {
            // Resolve the billing unit through the same filtered map as frontend terms.
            $intervalMaps = Helper::getAvailableSubscriptionIntervalMaps();
            $interval = Arr::get($otherInfo, 'repeat_interval');
            $billingUnit = Arr::get($intervalMaps, $interval, '');

            // Convert frontend units to UN/CEFACT units without localized labels.
            $periods = [
                'day' => [1, 'DAY'],
                'week' => [1, 'WEE'],
                'month' => [1, 'MON'],
                'quarter' => [3, 'MON'],
                'half_year' => [6, 'MON'],
                'year' => [1, 'ANN'],
            ];
            // Custom intervals with unknown units have no inferred duration.
            if (isset($periods[$billingUnit])) {
                list($quantity, $unit) = $periods[$billingUnit];
                $specification['unitCode'] = $unit;
                $specification['billingIncrement'] = $quantity;
                $specification['referenceQuantity'] = [
                    '@type' => 'QuantitativeValue',
                    'value' => $quantity,
                    'unitCode' => $unit,
                ];
                $times = (int) Arr::get($otherInfo, 'times', 0);
                if ($times > 0) {
                    $specification['billingDuration'] = $quantity * $times;
                }
            }
        }

        return $specification;
    }

    /**
     * Stored cents to a plain decimal string, the way Helper::toDecimal()
     * scales them but without its display formatting: "1999" → "19.99",
     * and "1999" → "20" in a zero-decimal currency.
     */
    protected static function price($cents, int $decimals): string
    {
        return number_format(((float) $cents) / 100, $decimals, '.', '');
    }

    /**
     * Echo the ld+json script tag for a product, or nothing.
     *
     * JSON_HEX_TAG turns < and > into \u003C / \u003E so a review body
     * holding "</script>" cannot close the tag early; the encoded form is
     * still valid JSON for the parser.
     *
     * @param int $postId
     */
    public static function render($postId): void
    {
        $schema = static::get($postId);

        if (!$schema) {
            return;
        }

        echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_HEX_TAG | JSON_HEX_AMP) . '</script>' . "\n";
    }

    /**
     * How many reviews review[] holds: the store's page length, since that
     * is what the first server-rendered page shows, capped at MAX_REVIEWS.
     */
    public static function reviewLimit(): int
    {
        $perPage = (int) Arr::get(ProductReviewService::getReviewSettings(), 'reviews_per_page', 10);

        return max(1, min(static::MAX_REVIEWS, $perPage));
    }

    /**
     * Review nodes in the order the list shows them by default: newest
     * first, id as the tie-breaker. Same predicates as the public listing
     * (ProductReviewResource::get with status=approved): top-level rows of
     * this product in approved status. Rated only, on top of that, because
     * a Review node without reviewRating fails validation.
     *
     * @param int $postId
     * @return array
     */
    protected static function reviewNodes($postId): array
    {
        $rows = ProductReview::query()
            ->select(['id', 'reviewer_name', 'title', 'review', 'rating', 'created_at'])
            ->topLevel()
            ->ofStatus(Status::REVIEW_APPROVED)
            ->ofProduct($postId)
            ->whereBetween('rating', [1, 5])
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit(static::reviewLimit())
            ->get();

        $nodes = [];

        foreach ($rows as $row) {
            $node = [
                '@type'  => 'Review',
                'author' => [
                    '@type' => 'Person',
                    'name'  => static::authorName($row->reviewer_name),
                ],
            ];

            $published = static::datePublished($row->created_at);
            if ($published !== '') {
                $node['datePublished'] = $published;
            }

            $title = static::text($row->title);
            if ($title !== '') {
                $node['name'] = $title;
            }

            $body = static::text($row->content);
            if ($body !== '') {
                $node['reviewBody'] = $body;
            }

            $node['reviewRating'] = [
                '@type'       => 'Rating',
                'ratingValue' => (string) (int) $row->rating,
                'bestRating'  => '5',
                'worstRating' => '1',
            ];

            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * Stored text as the reader sees it. Request sanitizers store "<" as
     * "&lt;" and the page's esc_html() leaves that entity alone, so the
     * reader sees "<"; JSON-LD is not HTML, so the entity has to be
     * decoded here or the crawler reads a literal "&lt;". Tags go first,
     * so an encoded "&lt;script&gt;" becomes plain text, which
     * JSON_HEX_TAG then keeps inert inside the script element.
     */
    protected static function text($value): string
    {
        $value = wp_strip_all_tags((string) $value);

        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * The page's reviewer line hides an empty name; the node still needs an
     * author, so an empty one reads as Anonymous.
     */
    protected static function authorName($name): string
    {
        $name = static::text($name);

        return $name !== '' ? $name : __('Anonymous', 'fluent-cart');
    }

    /**
     * created_at is stored in GMT; ISO 8601 with the explicit +00:00
     * offset so the crawler reads it as UTC and not the site's zone.
     */
    protected static function datePublished($createdAt): string
    {
        $createdAt = (string) $createdAt;
        if ($createdAt === '') {
            return '';
        }

        $timestamp = strtotime($createdAt . ' UTC');

        return $timestamp ? gmdate('c', $timestamp) : '';
    }

    /**
     * The picture the product page shows: the first gallery image, and
     * when the product has no gallery, the first variation's thumbnail —
     * the same fallback ProductRenderer::renderGalleryThumb() makes, so
     * the schema never names an image the page does not show, and never
     * goes without one the page does. The variations are the ones already
     * loaded for the offers; only their thumbnails are read, in one query,
     * and only when there is no gallery.
     *
     * @param int $postId
     * @param ProductVariation[] $variations
     */
    protected static function featuredImageUrl($postId, array $variations): string
    {
        $gallery = get_post_meta($postId, 'fluent-products-gallery-image', true);
        $url = static::firstMediaUrl($gallery);

        if ($url !== '' || !$variations) {
            return $url;
        }

        $variationIds = array_map(function ($variation) {
            return (int) $variation->id;
        }, $variations);

        $thumbnails = ProductMeta::query()
            ->select(['object_id', 'meta_value'])
            ->where('meta_key', 'product_thumbnail')
            ->whereIn('object_id', $variationIds)
            ->get()
            ->keyBy('object_id');

        foreach ($variationIds as $variationId) {
            $thumbnail = $thumbnails->get($variationId);
            $url = $thumbnail ? static::firstMediaUrl($thumbnail->meta_value) : '';

            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    /**
     * The url of the first entry in a media list, or '' when there is none.
     */
    protected static function firstMediaUrl($media): string
    {
        if (!is_array($media) || !$media) {
            return '';
        }

        $first = Arr::first($media);

        return is_array($first) ? trim((string) Arr::get($first, 'url', '')) : '';
    }

    /**
     * The excerpt when the merchant wrote one, else the opening of the
     * content — stripped of tags either way.
     */
    protected static function description($product): string
    {
        $excerpt = static::text($product->post_excerpt);
        if ($excerpt !== '') {
            return $excerpt;
        }

        $content = static::text(strip_shortcodes((string) $product->post_content));

        return $content !== '' ? wp_trim_words($content, 55, '') : '';
    }
}

<?php

namespace Tests\Integration\Services;

use FluentCart\Api\ModuleSettings;
use FluentCart\Api\Resource\ProductReviewResource;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Services\ProductReviewService;
use FluentCart\App\Services\Schema\ProductSchema;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * The Product JSON-LD a product page emits for its reviews.
 *
 * Built from the product post, the rating summary in detail.other_info and
 * fct_product_reviews on every render — nothing is stored. So the cases
 * are about what goes in: which reviews, in what order, how many, with
 * what fields, and what the node looks like when there is nothing to say.
 *
 * Spec: Cases/reviews.md — "Product schema"
 */
class ProductSchemaTest extends WPTestCase
{
    private ProductFactory $productFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->productFactory = new ProductFactory();
        $this->setReviewsPerPage(10);
    }

    public function tearDown(): void
    {
        remove_all_filters('fluent_cart/available_subscription_interval_options');
        remove_all_filters('fluent_cart/product/schema_enabled');
        remove_all_filters('fluent_cart/product/json_ld');
        remove_all_filters(ProductSchema::FILTER_HOOK);
        $this->setReviewsModule(['reviews_per_page' => 10, 'active' => 'yes']);
        $this->setModule('stock_management', ['active' => 'no']);
        parent::tearDown();
    }

    public function test_schema_can_be_disabled_per_product_before_building_the_node(): void
    {
        $disabled = $this->productFactory->create();
        $enabled = $this->productFactory->create();
        $this->assertNotEmpty(ProductSchema::get($disabled->ID));
        $nodeFilterCalled = false;

        add_filter('fluent_cart/product/schema_enabled', function ($value, $context) use ($disabled) {
            $this->assertTrue($value);
            return $context['post_id'] !== (int) $disabled->ID;
        }, 10, 2);
        add_filter('fluent_cart/product/json_ld', function ($schema) use (&$nodeFilterCalled) {
            $nodeFilterCalled = true;
            return $schema;
        });

        ob_start();
        ProductSchema::render($disabled->ID);
        $html = ob_get_clean();

        $this->assertSame('', $html);
        $this->assertFalse($nodeFilterCalled);
        $this->assertNotEmpty(ProductSchema::get($enabled->ID));
        $this->assertTrue($nodeFilterCalled);

        add_filter('fluent_cart/product/schema_enabled', '__return_true', 20);
        $this->assertNotEmpty(ProductSchema::get($disabled->ID));
    }

    public function test_product_schema_filters_share_context_and_preserve_legacy_customizations(): void
    {
        $product = $this->productFactory->create();
        $this->createReview($product);
        $contexts = [];

        add_filter('fluent_cart/product/json_ld', function ($schema, $context) use (&$contexts) {
            $contexts[] = $context;
            $schema['name'] = 'Product filter';
            return $schema;
        }, 10, 2);
        add_filter('fluent_cart/review/json_ld', function ($schema, $context) use (&$contexts) {
            $contexts[] = $context;
            $schema['name'] .= ' then legacy filter';
            return $schema;
        }, 10, 2);

        ob_start();
        ProductSchema::render($product->ID);
        $html = ob_get_clean();

        $this->assertStringContainsString('Product filter then legacy filter', $html);
        $this->assertCount(2, $contexts);
        $this->assertSame($contexts[0], $contexts[1]);
        $this->assertSame((int) $product->ID, $contexts[0]['post_id']);
        $this->assertArrayHasKey('summary', $contexts[0]);
    }

    public function test_either_product_schema_filter_can_suppress_the_script(): void
    {
        $product = $this->productFactory->create();
        $this->createReview($product);
        $this->assertNotEmpty(ProductSchema::get($product->ID));

        foreach (['fluent_cart/product/json_ld', 'fluent_cart/review/json_ld'] as $hook) {
            add_filter($hook, '__return_empty_array');
            ob_start();
            ProductSchema::render($product->ID);
            $html = ob_get_clean();
            remove_filter($hook, '__return_empty_array');

            $this->assertSame('', $html, $hook . ' should suppress the entire script');
        }
    }

    private function setReviewsPerPage(int $perPage): void
    {
        $this->setReviewsModule(['reviews_per_page' => $perPage]);
    }

    private function setReviewsModule(array $values): void
    {
        $this->setModule('reviews', $values);
    }

    private function setModule(string $module, array $values): void
    {
        $modules = ModuleSettings::getAllSettings(false);
        $modules[$module] = array_merge($modules[$module] ?? [], $values);
        ModuleSettings::saveSettings($modules);
        // getAllSettings() memoises; an uncached read refreshes the memo.
        ModuleSettings::getAllSettings(false);
        ProductReviewService::resetReviewSettingsCache();
    }

    private function createReview(Product $product, array $overrides = []): ProductReview
    {
        $review = ProductReviewResource::create(array_merge([
            'post_id'        => $product->ID,
            'rating'         => 5,
            'title'          => 'Schema spec',
            'content'        => 'The body of the review in the schema.',
            'status'         => Status::REVIEW_APPROVED,
            'reviewer_name'  => 'Schema Reviewer',
            'reviewer_email' => 'schema-reviewer@example.com',
        ], $overrides));

        $this->assertFalse(is_wp_error($review), 'fixture review should be created');

        return $review;
    }

    private function createReply(ProductReview $review): ProductReview
    {
        return $this->createReview(Product::query()->find($review->post_id), [
            'parent_id'      => $review->id,
            'is_admin_reply' => 1,
            'title'          => null,
            'content'        => 'A reply is not a review.',
            'reviewer_name'  => 'The Store',
        ]);
    }

    private function setCreatedAt(ProductReview $review, string $gmtDateTime): void
    {
        ProductReview::query()->where('id', $review->id)->update(['created_at' => $gmtDateTime]);
    }

    // ------------------------------------------------------------------
    // The node
    // ------------------------------------------------------------------

    /**
     * Spec: Cases/reviews.md [76]
     */
    public function test_a_product_with_approved_reviews_emits_one_product_node_with_its_aggregate_rating_and_each_review(): void
    {
        $product = $this->productFactory->create(['post_title' => 'Schema Product', 'post_excerpt' => 'A short pitch.']);
        $older = $this->createReview($product, ['rating' => 4, 'title' => 'Older', 'reviewer_name' => 'First Reviewer']);
        $newer = $this->createReview($product, ['rating' => 5, 'title' => 'Newer', 'reviewer_name' => 'Second Reviewer']);
        $this->setCreatedAt($older, '2026-09-01 10:00:00');
        $this->setCreatedAt($newer, '2026-09-10 10:00:00');

        $schema = ProductSchema::get($product->ID);

        $this->assertSame('https://schema.org', $schema['@context']);
        $this->assertSame('Product', $schema['@type']);
        $this->assertSame('Schema Product', $schema['name']);
        $this->assertSame(get_permalink($product->ID), $schema['url']);
        $this->assertSame('A short pitch.', $schema['description']);

        $this->assertSame([
            '@type'       => 'AggregateRating',
            'ratingValue' => '4.5',
            'reviewCount' => 2,
            'bestRating'  => '5',
            'worstRating' => '1',
        ], $schema['aggregateRating']);

        $this->assertCount(2, $schema['review']);
        $this->assertSame('Newer', $schema['review'][0]['name'], 'newest first, as the list shows them');
        $this->assertSame('Older', $schema['review'][1]['name']);

        $this->assertSame([
            '@type'         => 'Review',
            'author'        => ['@type' => 'Person', 'name' => 'Second Reviewer'],
            'datePublished' => '2026-09-10T10:00:00+00:00',
            'name'          => 'Newer',
            'reviewBody'    => 'The body of the review in the schema.',
            'reviewRating'  => [
                '@type'       => 'Rating',
                'ratingValue' => '5',
                'bestRating'  => '5',
                'worstRating' => '1',
            ],
        ], $schema['review'][0]);
    }

    /**
     * Spec: Cases/reviews.md [77]
     */
    public function test_only_approved_top_level_reviews_are_in_the_node(): void
    {
        $product = $this->productFactory->create();
        $approved = $this->createReview($product, ['title' => 'Approved']);
        $this->createReview($product, ['title' => 'Pending', 'status' => Status::REVIEW_PENDING]);
        $this->createReview($product, ['title' => 'Spam', 'status' => Status::REVIEW_SPAM]);
        $this->createReview($product, ['title' => 'Trash', 'status' => Status::REVIEW_TRASH]);
        $this->createReply($approved);

        $schema = ProductSchema::get($product->ID);

        $this->assertSame(1, $schema['aggregateRating']['reviewCount']);
        $this->assertSame(['Approved'], array_column($schema['review'], 'name'));
    }

    /**
     * Spec: Cases/reviews.md [78]
     */
    public function test_review_nodes_are_capped_at_the_page_length_and_never_past_the_ceiling(): void
    {
        $product = $this->productFactory->create();
        for ($i = 1; $i <= 12; $i++) {
            $review = $this->createReview($product, ['title' => 'Review ' . $i]);
            $this->setCreatedAt($review, sprintf('2026-08-%02d 10:00:00', $i));
        }

        $schema = ProductSchema::get($product->ID);

        $this->assertSame(12, $schema['aggregateRating']['reviewCount'], 'the aggregate counts every approved review');
        $this->assertCount(10, $schema['review'], 'review[] holds one page');
        $this->assertSame('Review 12', $schema['review'][0]['name']);
        $this->assertSame('Review 3', $schema['review'][9]['name']);

        $this->setReviewsPerPage(500);
        $this->assertSame(ProductSchema::MAX_REVIEWS, ProductSchema::reviewLimit());

        $this->setReviewsPerPage(0);
        $this->assertSame(1, ProductSchema::reviewLimit());
    }

    /**
     * Spec: Cases/reviews.md [79]
     */
    public function test_a_product_with_nothing_to_sell_and_no_approved_reviews_emits_nothing(): void
    {
        $product = (new ProductFactory())->withoutVariant()->create();
        $this->createReview($product, ['status' => Status::REVIEW_PENDING]);

        $this->assertSame([], ProductSchema::get($product->ID));

        // A draft variation is not on the page, so it is not an offer.
        ProductVariation::query()->create(array_merge($this->variantDefaults($product->ID), ['item_status' => 'draft']));
        $this->assertSame([], ProductSchema::get($product->ID));

        // A product the visitor cannot read emits nothing, however sellable:
        // a draft is a preview, and a password keeps the content back.
        $sellable = $this->productFactory->create(['post_status' => 'draft']);
        $this->assertSame([], ProductSchema::get($sellable->ID), 'draft');

        wp_update_post(['ID' => $sellable->ID, 'post_status' => 'publish', 'post_password' => 'secret']);
        $this->assertSame([], ProductSchema::get($sellable->ID), 'password-protected');

        wp_update_post(['ID' => $sellable->ID, 'post_password' => '']);
        $this->assertSame('Product', ProductSchema::get($sellable->ID)['@type'], 'published and open');
        $this->assertSame([], ProductSchema::get(0));
        $this->assertSame([], ProductSchema::get(PHP_INT_MAX));

        ob_start();
        ProductSchema::render($product->ID);
        $this->assertSame('', ob_get_clean());
    }

    /**
     * Spec: Cases/reviews.md [80]
     */
    public function test_the_rendered_tag_cannot_be_closed_by_a_review_body_entities_are_decoded_and_the_filter_shapes_the_node(): void
    {
        $product = $this->productFactory->create();
        $this->createReview($product, [
            'reviewer_name' => '',
            'content'       => 'Fine, 1 < 2 > 0 & done',
        ]);

        // Stored the way the request sanitizers store it: "<" as "&lt;".
        $encoded = $this->createReview($product, [
            'reviewer_name' => 'Ann &amp; Bob',
            'title'         => 'Size &lt; expected',
            'content'       => 'Runs small: a &lt; b &amp; c. &lt;script&gt;alert(1)&lt;/script&gt;',
        ]);
        $this->setCreatedAt($encoded, '2000-01-01 00:00:00');

        add_filter(ProductSchema::FILTER_HOOK, function ($schema, $context) {
            $schema['brand'] = ['@type' => 'Brand', 'name' => 'Filtered ' . $context['post_id']];
            return $schema;
        }, 10, 2);

        ob_start();
        ProductSchema::render($product->ID);
        $html = ob_get_clean();

        $this->assertStringStartsWith('<script type="application/ld+json">', $html);
        $this->assertSame(1, substr_count($html, '</script>'), 'the body cannot close the tag');

        $json = substr($html, strlen('<script type="application/ld+json">'), -strlen("</script>\n"));
        $this->assertStringNotContainsString('<', $json, 'angle brackets leave as \\u003C, never raw');
        $this->assertStringNotContainsString('>', $json);

        $decoded = json_decode($json, true);

        $this->assertSame('Fine, 1 < 2 > 0 & done', $decoded['review'][0]['reviewBody']);
        $this->assertSame('Anonymous', $decoded['review'][0]['author']['name']);
        $this->assertSame('Filtered ' . $product->ID, $decoded['brand']['name']);

        $this->assertSame('Ann & Bob', $decoded['review'][1]['author']['name'], 'entities read as the page shows them');
        $this->assertSame('Size < expected', $decoded['review'][1]['name']);
        $this->assertSame('Runs small: a < b & c. <script>alert(1)</script>', $decoded['review'][1]['reviewBody']);
    }

    /**
     * Spec: Cases/reviews.md [81]
     */
    public function test_image_and_sku_appear_only_when_the_product_has_one_to_give(): void
    {
        $product = $this->productFactory->create();
        $this->createReview($product);

        $schema = ProductSchema::get($product->ID);
        $this->assertArrayNotHasKey('image', $schema);
        $this->assertArrayNotHasKey('sku', $schema);

        // No gallery, but the variation has a thumbnail: the page shows it,
        // so the schema names it.
        $variation = ProductVariation::query()->where('post_id', $product->ID)->first();
        ProductMeta::query()->create([
            'object_id'   => $variation->id,
            'object_type' => 'product_variant_info',
            'meta_key'    => 'product_thumbnail',
            'meta_value'  => [['id' => 9, 'url' => 'https://example.com/variant.jpg']],
        ]);
        $this->assertSame(['https://example.com/variant.jpg'], ProductSchema::get($product->ID)['image']);

        // A gallery wins over the variation thumbnail.
        update_post_meta($product->ID, 'fluent-products-gallery-image', [
            ['id' => 1, 'url' => 'https://example.com/first.jpg'],
            ['id' => 2, 'url' => 'https://example.com/second.jpg'],
        ]);
        ProductVariation::query()->where('post_id', $product->ID)->update(['sku' => 'ONLY-1']);

        $schema = ProductSchema::get($product->ID);
        $this->assertSame(['https://example.com/first.jpg'], $schema['image']);
        $this->assertSame('ONLY-1', $schema['sku']);

        // A second SKU'd variation: no single SKU describes the product.
        $second = ProductVariation::query()->where('post_id', $product->ID)->first()->replicate();
        $second->sku = 'OTHER-2';
        $second->serial_index = 2;
        $second->save();

        $schema = ProductSchema::get($product->ID);
        $this->assertArrayNotHasKey('sku', $schema);
        $this->assertSame(['ONLY-1', 'OTHER-2'], array_column($schema['offers']['offers'], 'sku'), 'each SKU on its own offer');
    }

    public function test_subscription_offers_describe_each_billing_interval(): void
    {
        update_option('fluent_cart_tax_configuration_settings', ['enable_tax' => 'no']);
        $product = $this->productFactory->withoutVariant()->create();
        $periods = [
            ['daily', 1, 'DAY'], ['weekly', 1, 'WEE'],
            ['monthly', 1, 'MON'], ['quarterly', 3, 'MON'],
            ['half_yearly', 6, 'MON'], ['yearly', 1, 'ANN'],
        ];
        foreach ($periods as $index => $period) {
            ProductVariation::query()->create(array_merge($this->variantDefaults($product->ID), [
                'payment_type' => 'subscription',
                'serial_index' => $index,
                'item_price' => 700,
                'other_info' => ['repeat_interval' => $period[0], 'times' => 0],
            ]));
        }

        $offers = ProductSchema::get($product->ID)['offers']['offers'];
        foreach ($periods as $index => $period) {
            $spec = $offers[$index]['priceSpecification'];
            $this->assertSame('UnitPriceSpecification', $spec['@type']);
            $this->assertSame('7.00', $spec['price']);
            $this->assertSame($offers[$index]['priceCurrency'], $spec['priceCurrency']);
            $this->assertSame($period[2], $spec['unitCode']);
            $this->assertSame($period[1], $spec['billingIncrement']);
            $this->assertSame([
                '@type' => 'QuantitativeValue', 'value' => $period[1], 'unitCode' => $period[2],
            ], $spec['referenceQuantity']);
            $this->assertArrayNotHasKey('billingDuration', $spec);
            $this->assertArrayNotHasKey('valueAddedTaxIncluded', $spec);
        }
    }

    public function test_schema_uses_the_frontend_subscription_interval_mapping(): void
    {
        add_filter('fluent_cart/available_subscription_interval_options', function ($options) {
            $options[] = [
                'value' => 'custom_monthly',
                'map_value' => 'month',
                'label' => 'Custom monthly plan',
            ];
            return $options;
        });
        $product = $this->productFactory->withVariantAttributes([
            'payment_type' => 'subscription',
            'other_info' => ['repeat_interval' => 'custom_monthly', 'times' => 4],
        ])->create();

        $spec = ProductSchema::get($product->ID)['offers']['priceSpecification'];
        $this->assertSame('MON', $spec['unitCode']);
        $this->assertSame(1, $spec['referenceQuantity']['value']);
        $this->assertSame(4, $spec['billingDuration']);
    }

    public function test_finite_subscription_duration_and_unknown_intervals_are_not_guessed(): void
    {
        $product = $this->productFactory->withVariantAttributes([
            'payment_type' => 'subscription',
            'other_info' => ['repeat_interval' => 'quarterly', 'times' => 4],
        ])->create();
        $spec = ProductSchema::get($product->ID)['offers']['priceSpecification'];
        $this->assertSame(12, $spec['billingDuration']);
        $this->assertSame('MON', $spec['unitCode']);

        $variant = ProductVariation::query()->where('post_id', $product->ID)->first();
        $variant->other_info = ['repeat_interval' => 'custom_interval', 'times' => 4];
        $variant->save();
        $spec = ProductSchema::get($product->ID)['offers']['priceSpecification'];
        $this->assertArrayNotHasKey('billingDuration', $spec);
        $this->assertArrayNotHasKey('unitCode', $spec);
        $this->assertArrayNotHasKey('referenceQuantity', $spec);
    }

    public function test_schema_tax_settings_do_not_load_vat_registrations(): void
    {
        $product = $this->productFactory->create();
        foreach (['no', 'yes'] as $enabled) {
            update_option('fluent_cart_tax_configuration_settings', ['enable_tax' => $enabled]);
            $registrationQueries = [];
            $capture = function ($sql) use (&$registrationQueries) {
                if (strpos($sql, 'eu_vat_registration') !== false) {
                    $registrationQueries[] = $sql;
                }
                return $sql;
            };
            add_filter('query', $capture);
            try {
                $offer = ProductSchema::get($product->ID)['offers'];
            } finally {
                remove_filter('query', $capture);
            }
            $this->assertSame([], $registrationQueries);
            if ($enabled === 'yes') {
                $this->assertTrue($offer['priceSpecification']['valueAddedTaxIncluded'], 'Missing inclusion uses the store default.');
            } else {
                $this->assertArrayNotHasKey('priceSpecification', $offer);
            }
        }
    }

    public function test_vat_inclusion_follows_variation_overrides_and_active_tax_settings(): void
    {
        $product = $this->productFactory->create();
        $variant = ProductVariation::query()->where('post_id', $product->ID)->first();
        foreach (['included', 'excluded'] as $storeInclusion) {
            update_option('fluent_cart_tax_configuration_settings', [
                'enable_tax' => 'yes', 'tax_inclusion' => $storeInclusion,
            ]);
            foreach (['', 'included', 'excluded'] as $override) {
                $variant->other_info = ['tax_inclusion' => $override];
                $variant->save();
                $offer = ProductSchema::get($product->ID)['offers'];
                $spec = $offer['priceSpecification'];
                $this->assertSame('PriceSpecification', $spec['@type']);
                $this->assertSame(($override ?: $storeInclusion) === 'included', $spec['valueAddedTaxIncluded']);
                $this->assertSame($offer['price'], $spec['price']);
                $this->assertSame($offer['priceCurrency'], $spec['priceCurrency']);
                $this->assertArrayNotHasKey('billingDuration', $spec);
            }
        }
        update_option('fluent_cart_tax_configuration_settings', [
            'enable_tax' => 'no', 'tax_inclusion' => 'included',
        ]);
        $this->assertArrayNotHasKey('priceSpecification', ProductSchema::get($product->ID)['offers']);
    }

    public function test_assigned_brands_appear_in_schema_and_disappear_when_removed(): void
    {
        $product = $this->productFactory->create();
        $this->assertArrayNotHasKey('brand', ProductSchema::get($product->ID));
        $brand = self::factory()->term->create([
            'taxonomy' => 'product-brands', 'name' => 'Acme &amp; Sons',
        ]);
        $other = self::factory()->term->create([
            'taxonomy' => 'product-brands', 'name' => 'Partner',
        ]);
        self::factory()->term->create([
            'taxonomy' => 'product-brands', 'name' => 'Unassigned',
        ]);
        wp_set_object_terms($product->ID, [$brand], 'product-brands');
        $this->assertSame([
            '@type' => 'Brand', 'name' => 'Acme & Sons',
        ], ProductSchema::get($product->ID)['brand']);

        wp_set_object_terms($product->ID, [$brand, $other], 'product-brands');
        $this->assertEqualsCanonicalizing([
            ['@type' => 'Brand', 'name' => 'Acme & Sons'],
            ['@type' => 'Brand', 'name' => 'Partner'],
        ], ProductSchema::get($product->ID)['brand']);

        wp_set_object_terms($product->ID, [], 'product-brands');
        $this->assertArrayNotHasKey('brand', ProductSchema::get($product->ID));
    }

    public function test_product_schema_filter_can_override_assigned_brand(): void
    {
        $product = $this->productFactory->create();
        $brand = self::factory()->term->create([
            'taxonomy' => 'product-brands', 'name' => 'Assigned Brand',
        ]);
        wp_set_object_terms($product->ID, [$brand], 'product-brands');
        add_filter('fluent_cart/product/json_ld', function ($schema) {
            $this->assertSame('Assigned Brand', $schema['brand']['name']);
            $schema['brand']['name'] = 'Custom Brand';
            return $schema;
        });
        $this->assertSame('Custom Brand', ProductSchema::get($product->ID)['brand']['name']);
    }

    private function variantDefaults(int $postId): array
    {
        return [
            'post_id'          => $postId,
            'variation_title'  => 'Default',
            'serial_index'     => 0,
            'payment_type'     => 'onetime',
            'fulfillment_type' => 'digital',
            'item_status'      => 'active',
            'item_price'       => 2999,
            'compare_price'    => 0,
            'item_cost'        => 0,
            'manage_stock'     => 0,
            'stock_status'     => 'in-stock',
            'total_stock'      => 0,
            'available'        => 0,
            'committed'        => 0,
            'on_hold'          => 0,
            'backorders'       => 0,
            'other_info'       => ['payment_type' => 'onetime'],
        ];
    }

    // ------------------------------------------------------------------
    // Offers
    // ------------------------------------------------------------------

    /**
     * Spec: Cases/reviews.md [82]
     */
    public function test_a_single_variation_is_one_offer_with_the_stored_price_as_a_decimal(): void
    {
        // A tax configuration an earlier class committed would add a price
        // specification to the offer; pin tax off, as the sibling tests do.
        update_option('fluent_cart_tax_configuration_settings', ['enable_tax' => 'no']);
        $product = $this->productFactory->create();
        ProductVariation::query()->where('post_id', $product->ID)->update(['sku' => 'SNK-1', 'item_price' => 1999]);

        $schema = ProductSchema::get($product->ID);

        $this->assertArrayNotHasKey('aggregateRating', $schema, 'no reviews, no rating');
        $this->assertArrayNotHasKey('review', $schema);
        $this->assertSame('SNK-1', $schema['sku']);
        $this->assertSame([
            '@type'         => 'Offer',
            'url'           => get_permalink($product->ID),
            'price'         => '19.99',
            'priceCurrency' => 'USD',
            'availability'  => 'https://schema.org/InStock',
            'sku'           => 'SNK-1',
        ], $schema['offers']);
    }

    /**
     * Spec: Cases/reviews.md [83]
     */
    public function test_several_variations_are_an_aggregate_offer_around_each_one_in_page_order(): void
    {
        $this->setModule('stock_management', ['active' => 'yes']);
        $product = (new ProductFactory())->createWithVariants([
            ['variation_title' => 'Small', 'item_price' => 1000, 'sku' => 'S'],
            ['variation_title' => 'Large', 'item_price' => 2500, 'sku' => 'L', 'manage_stock' => 1, 'available' => 0],
            ['variation_title' => 'Draft', 'item_price' => 100, 'item_status' => 'draft'],
        ]);

        $offers = ProductSchema::get($product->ID)['offers'];

        $this->assertSame('AggregateOffer', $offers['@type']);
        $this->assertSame('10.00', $offers['lowPrice']);
        $this->assertSame('25.00', $offers['highPrice']);
        $this->assertSame(2, $offers['offerCount'], 'the draft variation is not for sale');
        $this->assertSame('USD', $offers['priceCurrency']);
        $this->assertSame(['Small', 'Large'], array_column($offers['offers'], 'name'));
        $this->assertSame(['https://schema.org/InStock', 'https://schema.org/OutOfStock'], array_column($offers['offers'], 'availability'));
        $this->assertArrayNotHasKey('sku', ProductSchema::get($product->ID), 'no single SKU describes the product');
    }

    /**
     * Spec: Cases/reviews.md [83d]
     */
    public function test_past_the_offer_cap_the_aggregate_still_describes_every_variation(): void
    {
        $product = (new ProductFactory())->withoutVariant()->create();
        $total = ProductSchema::MAX_OFFERS + 2;
        $rows = [];
        for ($i = 1; $i <= $total; $i++) {
            // The cheapest and the dearest sit past the cap, where a capped
            // aggregate would never see them.
            $price = $i === $total - 1 ? 100 : ($i === $total ? 99900 : 5000);
            $rows[] = array_merge($this->variantDefaults($product->ID), [
                'variation_title' => 'Option ' . $i,
                'serial_index'    => $i,
                'item_price'      => $price,
                'sku'             => 'OPT-' . $i,
            ]);
        }
        foreach ($rows as $row) {
            ProductVariation::query()->create($row);
        }

        $schema = ProductSchema::get($product->ID);
        $offers = $schema['offers'];

        $this->assertSame('AggregateOffer', $offers['@type']);
        $this->assertSame($total, $offers['offerCount'], 'every active variation is counted');
        $this->assertSame('1.00', $offers['lowPrice']);
        $this->assertSame('999.00', $offers['highPrice']);
        $this->assertCount(ProductSchema::MAX_OFFERS, $offers['offers'], 'the nested offers stop at the cap');
        $this->assertSame('Option 1', $offers['offers'][0]['name']);
        $this->assertArrayNotHasKey('sku', $schema);
    }

    /**
     * Spec: Cases/reviews.md [83a]
     */
    public function test_availability_follows_the_stock_module_the_way_the_buy_button_does(): void
    {
        $product = (new ProductFactory())->createWithVariants([
            ['variation_title' => 'Sold out', 'manage_stock' => 1, 'available' => 0],
        ]);
        ProductDetail::query()->where('post_id', $product->ID)->update(['manage_stock' => 1, 'stock_availability' => 'out-of-stock']);

        // Module off: the page sells it, whatever the stock columns say.
        $this->setModule('stock_management', ['active' => 'no']);
        $this->assertSame('https://schema.org/InStock', ProductSchema::get($product->ID)['offers']['availability']);

        // Module on: the product and the variation both have to be in stock.
        $this->setModule('stock_management', ['active' => 'yes']);
        $this->assertSame('https://schema.org/OutOfStock', ProductSchema::get($product->ID)['offers']['availability']);

        ProductVariation::query()->where('post_id', $product->ID)->update(['available' => 3]);
        $this->assertSame('https://schema.org/OutOfStock', ProductSchema::get($product->ID)['offers']['availability'], 'the product itself is still out of stock');

        ProductDetail::query()->where('post_id', $product->ID)->update(['stock_availability' => 'in-stock']);
        $this->assertSame('https://schema.org/InStock', ProductSchema::get($product->ID)['offers']['availability']);
    }

    /**
     * Spec: Cases/reviews.md [83b]
     */
    public function test_a_bundle_reads_every_child_once_however_many_variations_it_has(): void
    {
        global $wpdb;
        $this->setModule('stock_management', ['active' => 'yes']);

        $childFactory = new ProductFactory();
        $inStockChild = $childFactory->create();
        $soldOutChild = (new ProductFactory())->createWithVariants([['manage_stock' => 1, 'available' => 0]]);
        $okId = (int) ProductVariation::query()->where('post_id', $inStockChild->ID)->value('id');
        $soldOutId = (int) ProductVariation::query()->where('post_id', $soldOutChild->ID)->value('id');

        $one = (new ProductFactory())->createBundle([$okId]);
        $queriesBefore = $wpdb->num_queries;
        $this->assertSame('https://schema.org/InStock', ProductSchema::get($one->ID)['offers']['availability']);
        $queriesForOne = $wpdb->num_queries - $queriesBefore;

        $many = (new ProductFactory())->createBundle([$okId]);
        $bundleInfo = ['payment_type' => 'onetime', 'is_bundle_product' => 'yes', 'bundle_child_ids' => [$okId]];
        foreach ([1, 2, 3, 4] as $index) {
            ProductVariation::query()->create(array_merge($this->variantDefaults($many->ID), [
                'variation_title' => 'Bundle ' . $index,
                'serial_index'    => $index,
                'other_info'      => $index === 4
                    ? array_merge($bundleInfo, ['bundle_child_ids' => [$okId, $soldOutId]])
                    : $bundleInfo,
            ]));
        }

        $queriesBefore = $wpdb->num_queries;
        $offers = ProductSchema::get($many->ID)['offers'];
        $queriesForMany = $wpdb->num_queries - $queriesBefore;

        $this->assertSame(5, $offers['offerCount']);
        $this->assertSame(
            ['InStock', 'InStock', 'InStock', 'InStock', 'OutOfStock'],
            array_map(fn($o) => basename($o['availability']), $offers['offers']),
            'the bundle whose child is sold out is the one out of stock'
        );
        $this->assertSame($queriesForOne, $queriesForMany, 'five bundle variations cost the same queries as one');
    }

    /**
     * Spec: Cases/reviews.md [83c]
     */
    public function test_a_bundle_naming_a_deleted_child_is_out_of_stock_on_both_stock_paths(): void
    {
        $this->setModule('stock_management', ['active' => 'yes']);

        $child = (new ProductFactory())->create();
        $childId = (int) ProductVariation::query()->where('post_id', $child->ID)->value('id');
        $bundle = (new ProductFactory())->createBundle([$childId]);

        $this->assertSame('https://schema.org/InStock', ProductSchema::get($bundle->ID)['offers']['availability']);

        ProductVariation::query()->where('id', $childId)->delete();

        $this->assertSame('https://schema.org/OutOfStock', ProductSchema::get($bundle->ID)['offers']['availability'], 'the batched path');

        $variation = ProductVariation::query()->with('product.detail')->where('post_id', $bundle->ID)->first();
        $this->assertFalse($variation->isStock(), 'the query path agrees');
    }

    /**
     * Spec: Cases/reviews.md [84]
     */
    public function test_with_the_reviews_module_off_the_node_keeps_its_offers_and_drops_its_reviews(): void
    {
        $product = $this->productFactory->create();
        $this->createReview($product);

        $this->assertArrayHasKey('aggregateRating', ProductSchema::get($product->ID));

        $this->setReviewsModule(['active' => 'no']);
        $schema = ProductSchema::get($product->ID);

        $this->assertSame('Offer', $schema['offers']['@type']);
        $this->assertArrayNotHasKey('aggregateRating', $schema);
        $this->assertArrayNotHasKey('review', $schema);
    }

    /**
     * Spec: Cases/reviews.md [84a]
     */
    public function test_the_schema_hides_reviews_wherever_the_page_hides_them(): void
    {
        $product = $this->productFactory->create();
        $this->createReview($product);
        $this->assertArrayHasKey('review', ProductSchema::get($product->ID));

        // The product's own toggle, the same one the review list obeys.
        ProductDetail::query()->where('post_id', $product->ID)->update(['other_info' => ['reviews_enabled' => 'no']]);
        ProductReviewService::resetRuntimeCache();
        $schema = ProductSchema::get($product->ID);
        $this->assertArrayNotHasKey('aggregateRating', $schema);
        $this->assertArrayNotHasKey('review', $schema);
        $this->assertSame('Offer', $schema['offers']['@type'], 'the offer stays');

        // Back on, and then the single-product-page setting, which applies on
        // the product page itself.
        ProductDetail::query()->where('post_id', $product->ID)->update(['other_info' => []]);
        ProductReviewService::resetRuntimeCache();
        $this->assertArrayHasKey('review', ProductSchema::get($product->ID));

        $this->go_to(get_permalink($product->ID));
        $this->assertTrue(is_singular('fluent-products'), 'the product page');
        $this->assertArrayHasKey('review', ProductSchema::get($product->ID));

        add_filter('fluent_cart/single_product_page/show_reviews', '__return_false');
        $schema = ProductSchema::get($product->ID);
        remove_filter('fluent_cart/single_product_page/show_reviews', '__return_false');

        $this->assertArrayNotHasKey('review', $schema);
        $this->assertArrayNotHasKey('aggregateRating', $schema);
        $this->assertSame('Offer', $schema['offers']['@type']);
    }
}

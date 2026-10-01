<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\FrontendView;
use FluentCart\App\Services\ProductReviewService;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

/**
 * The public "review your order" page: everything a customer bought on one
 * order, each line with a way to review it.
 *
 * Reached the way the receipt is — ?fluent-cart=order-review&order_hash={uuid}
 * — so the emailed link works for a guest who has no account. The hash is the
 * credential; ProductReviewService::resolveOrderGrant() explains why holding
 * one is treated as proof of purchase.
 *
 * The per-product markup is NOT written here. Each row renders the real
 * fluent-cart/write-a-review-button block through render_block(), so this page
 * gets exactly what a page built in the editor gets — the block's attribute
 * validation, its wrapper attributes and supports, its render_block filters,
 * and underneath all of it ProductReviewRenderer with the store's permission
 * mode, star settings and Pro's photo fields.
 */
class OrderReviewRenderer
{
    /**
     * Line types that are not a product and so cannot be reviewed. Mirrors
     * Order::getProductItems() — a signup_fee line carries a post_id, so
     * excluding only 'fee' would let it win the per-product dedupe and title
     * the row from a fee line.
     */
    const NON_PRODUCT_LINES = ['fee', 'signup_fee'];

    /**
     * The list is rendered whole, deliberately: it is not paged and has no
     * cap. An order is a handful of products — five to ten at the very most
     * in practice — so every product the customer bought is on the one page
     * their email link opens, and nothing about the page depends on a query
     * variable. The grant, reviewed-state, toggle and post-cache lookups are
     * each one query for the whole order, so the cost of a long order is
     * the rows themselves.
     */

    /**
     * Set when the link is fine but the store is not taking reviews, so the
     * notice can say that rather than claim the order is missing.
     *
     * @var bool
     */
    protected $unavailable = false;

    protected $orderHash;

    /**
     * @var Order|null
     */
    protected $order = null;

    /**
     * @var bool whether to print the page's own <h1>
     */
    protected $showTitle = true;

    /**
     * The item of the row being rendered right now, read by the
     * renderer_options filter injected around the item loop. 0 for a row
     * that reviews the product as a whole.
     *
     * @var int
     */
    protected $currentItemId = 0;

    /**
     * @param string $orderHash
     * @param array  $options showTitle: false when a WP page already prints a
     *                        title above this markup (the shortcode path), so
     *                        the visitor does not read the same heading twice.
     */
    public function __construct($orderHash, array $options = [])
    {
        $this->orderHash = sanitize_text_field((string) $orderHash);

        if (array_key_exists('showTitle', $options)) {
            $this->showTitle = (bool) $options['showTitle'];
        }
    }

    /**
     * Echoes the page body. The caller wraps it in FrontendView.
     *
     * @return bool whether an order was found and rendered; the route
     *              answers 404 otherwise, the shortcode page keeps its own status
     */
    public function render()
    {
        // The URL carries a bearer credential, and the page is reached from
        // an email: nothing about it should be indexed. On the query route
        // wp_head() has not run yet, so this filter still reaches the head.
        // The store's chosen page prints its head before the shortcode runs;
        // AssetLoader::markOrderReviewPageNoIndex() flags that page at
        // template_redirect instead.
        add_filter('wp_robots', 'wp_robots_no_robots');

        // Before the guard: renderNotFound() prints .fct-order-review-* markup
        // too, and those classes live only in this stylesheet — enqueueing
        // after the early return left every bad link completely unstyled.
        Vite::enqueueStyle('fluent-cart-order-review', 'public/order-review/order-review.scss');

        if (!$this->resolveOrder()) {
            $this->renderNotFound();
            return false;
        }

        // Enqueued before FrontendView::make() runs, so wp_head() and
        // wp_footer() still have them to print. The form's own script and
        // stylesheet only: this page has no gallery, product card or review
        // list to drive, so the full single-product bundle stays off it.
        AssetLoader::loadReviewSubmissionFormAssets();

        // Swaps a row to its "already reviewed" state when ReviewForm.js
        // reports a success, so the overlay does not close onto a stale button.
        Vite::enqueueScript(
            'fluent-cart-order-review',
            'public/order-review/order-review.js',
            [],
            null,
            true
        );

        $items = $this->reviewableItems();

        // Only the rows that still offer a form. Counting every row would
        // promise "3 products are waiting for a review" above three lines each
        // saying the visitor already reviewed it.
        $pendingCount = 0;
        foreach ($items as $item) {
            if ($item['enabled'] && !$item['reviewed']) {
                $pendingCount++;
            }
        }

        ?>
        <div class="fct-order-review-page">
            <?php $this->renderHeader($pendingCount); ?>

            <?php if (!$items) : ?>
                <p class="fct-order-review-empty">
                    <?php esc_html_e('There is nothing to review on this order.', 'fluent-cart'); ?>
                </p>
            <?php else : ?>
                <?php $grantInjection = $this->injectGrantIntoRenderers(); ?>
                <ul class="fct-order-review-items">
                    <?php foreach ($items as $item) : ?>
                        <li class="fct-order-review-item"><?php $this->renderItem($item); ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php $this->releaseGrantInjection($grantInjection); ?>
            <?php endif; ?>
        </div>
        <?php
        return true;
    }

    /**
     * The order the hash names, if the store is willing to show it.
     *
     * A valid hash is enough to SEE the page. Being allowed to review from it
     * is a separate, stricter question that resolveOrderGrant() answers per
     * product — it additionally requires the order to have completed.
     *
     * Two rules rather than one, deliberately. The per-item CTA already
     * degrades correctly when no grant applies: a signed-in buyer still gets a
     * working button (they pass verified_buyers on their own), and everyone
     * else gets a log-in link rather than a button that would be rejected. So
     * an order still awaiting payment shows its products with a way in, rather
     * than a 404 that tells a paying customer nothing.
     *
     * @return bool
     */
    protected function resolveOrder(): bool
    {
        if ($this->orderHash === '') {
            return false;
        }

        $order = Order::query()
            ->with(['order_items', 'customer'])
            ->where('uuid', $this->orderHash)
            ->first();

        if (!$order) {
            return false;
        }

        // Reviews off store-wide means the page has nothing to offer at all
        // — said as that, not as a missing order: the link is fine.
        $settings = ProductReviewService::getReviewSettings();
        if (Arr::get($settings, 'reviews_enabled') !== 'yes') {
            $this->unavailable = true;
            return false;
        }

        $canView = apply_filters('fluent_cart/order_review/can_view', true, [
            'order' => $order,
        ]);

        if (!$canView) {
            return false;
        }

        $this->order = $order;

        // The rows below each ask whether the hash covers their product; the
        // order and its lines are already in hand, so the grant memo is
        // seeded from them and no row issues a query to find out.
        ProductReviewService::primeOrderGrant($order);

        return true;
    }

    /**
     * The same not-found view the receipt page shows for a bad link — the
     * shared frontend/not-found.php template with the 404 illustration and
     * home button — so the two emailed-link pages fail the same way.
     *
     * The receipt goes through FrontendView::renderNotFoundPage(), which
     * prints a whole document. This page already has its chrome from the
     * route or the shortcode, so the same template is rendered here as a
     * fragment with the same data shape that helper builds.
     */
    protected function renderNotFound()
    {
        FrontendView::enqueueNotFoundPageAssets();

        $assetBase = Vite::getAssetUrl();
        $notFoundImg = $assetBase . 'images/404.svg';

        // The link is fine but the store is not taking reviews: say that,
        // rather than claim the order is missing.
        if ($this->unavailable) {
            $title = __('Reviews are not available right now', 'fluent-cart');
            $text = __('This store is not accepting product reviews at the moment. Thank you for your order.', 'fluent-cart');
        } else {
            $title = __('Sorry, no order found.', 'fluent-cart');
            $text = __('The link appears to be invalid or expired. Check the link in your order email, or contact us if it keeps happening.', 'fluent-cart');
        }
        $buttonText = __('Go Back to Home Page', 'fluent-cart');

        ?>
        <div class="fct-order-review-page">
            <?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the view template
            echo App::view()->make('frontend/not-found.php', [
                'title'       => $title,
                'text'        => $text,
                'buttonText'  => $buttonText,
                'notFoundImg' => $notFoundImg,
                'buttonUrl'   => home_url(),
            ]);
            ?>
        </div>
        <?php
    }

    protected function renderHeader($itemCount)
    {
        $invoiceNo = $this->order->invoice_no;
        ?>
        <header class="fct-order-review-header">
            <?php if ($this->showTitle) : ?>
                <h1 class="fct-order-review-title"><?php esc_html_e('Review your order', 'fluent-cart'); ?></h1>
            <?php endif; ?>
            <p class="fct-order-review-subtitle" data-order-review-subtitle data-after-submission="<?php esc_attr_e('Tell us what you thought of the remaining products.', 'fluent-cart'); ?>">
                <?php
                if ($itemCount) {
                    echo esc_html(
                        sprintf(
                            /* translators: %1$d: number of products on the order awaiting a review */
                            _n(
                                'Tell us what you thought of your purchase. %1$d product is waiting for a review.',
                                'Tell us what you thought of your purchase. %1$d products are waiting for a review.',
                                $itemCount,
                                'fluent-cart'
                            ),
                            $itemCount
                        )
                    );
                } else {
                    esc_html_e('Thanks for your order.', 'fluent-cart');
                }
                ?>
            </p>

            <?php if ($itemCount) : ?>
                <?php // Swapped in by order-review.js once the last row is done. ?>
                <p class="fct-order-review-subtitle" data-order-review-all-done hidden>
                    <?php esc_html_e('Thanks for your order.', 'fluent-cart'); ?>
                </p>
            <?php endif; ?>

            <?php if ($invoiceNo) : ?>
                <p class="fct-order-review-order-no">
                    <?php
                    /* translators: %1$s: the order's invoice number */
                    printf(esc_html__('Order #%1$s', 'fluent-cart'), esc_html($invoiceNo));
                    ?>
                </p>
            <?php endif; ?>

        </header>
        <?php
    }

    /**
     * One row per reviewable thing on the order, with the state it is in.
     *
     * A variable product gets a row per distinct item bought — two variants
     * of one product are two purchases and two opinions, and each row's form
     * is bound to its item so the review records which one. A simple product
     * has nothing to tell apart: its lone default variation IS the product,
     * so it stays one row at product level (item 0) however many lines it
     * spans. ReviewForm.js keys its controllers on (product, item), so two
     * rows for one product never fight over a drawer.
     *
     * @return array<int, array{post_id:int, item_id:int, title:string, item_label:string, image:string, reviewed:bool, enabled:bool}>
     */
    protected function reviewableItems(): array
    {
        $items = [];

        // The distinct (product, variation) lines on the order, in the order
        // they were bought, with the title each line carried. Every lookup
        // below is one query for this whole set. A line whose variation
        // turns out not to be an item collapses to the product slot below.
        $lines = [];
        foreach ($this->order->order_items as $orderItem) {
            $postId = (int) $orderItem->post_id;
            if (!$postId || in_array($orderItem->payment_type, self::NON_PRODUCT_LINES, true)) {
                continue;
            }
            $key = $postId . ':' . (int) $orderItem->object_id;
            if (!isset($lines[$key])) {
                $lines[$key] = [
                    'post_id'   => $postId,
                    'object_id' => (int) $orderItem->object_id,
                    'title'     => (string) $orderItem->post_title,
                ];
            }
        }

        $pageLines = array_values($lines);

        $postIds = [];
        foreach ($pageLines as $line) {
            $postIds[$line['post_id']] = true;
        }
        $postIds = array_keys($postIds);

        $reviewedSlots = $this->reviewedSlots($postIds);

        // The per-product reviews toggle for every product on the order in
        // one query; each row's check, and the block each row renders, then
        // answer from memory.
        ProductReviewService::primeReviewsEnabled($postIds);

        $products = [];
        $variableProducts = [];
        if ($postIds) {
            $found = Product::query()
                ->where('post_status', 'publish')
                ->whereIn('ID', $postIds)
                ->get();

            foreach ($found as $foundProduct) {
                $products[(int) $foundProduct->ID] = $foundProduct;
            }

            // The loop below asks WordPress for each product's permalink and
            // thumbnail. The ORM model does not fill the WP post cache, so
            // without this every row costs a post, a meta and an attachment
            // lookup of its own: the posts and their meta in one pass each,
            // then the thumbnails those meta name, the same way.
            _prime_post_caches($postIds, false, true);
            $thumbnailIds = [];
            foreach ($postIds as $primedId) {
                $thumbnailId = (int) get_post_thumbnail_id($primedId);
                if ($thumbnailId) {
                    $thumbnailIds[] = $thumbnailId;
                }
            }
            if ($thumbnailIds) {
                _prime_post_caches(array_values(array_unique($thumbnailIds)), false, true);
            }

            // Which of them have items worth telling apart — one query for
            // the page, the same positive-only rule as
            // ProductReviewService::productHasItems().
            $variationTypes = ProductDetail::query()
                ->whereIn('post_id', $postIds)
                ->pluck('variation_type', 'post_id');

            foreach ($variationTypes as $detailPostId => $variationType) {
                if ($variationType && $variationType !== 'simple') {
                    $variableProducts[(int) $detailPostId] = true;
                }
            }
        }

        // The name of each item bought, read from the variation row the way
        // the product name is read from the post — one query for the page.
        // A variation that no longer exists simply has no name to show.
        $itemNames = [];
        $objectIds = [];
        foreach ($pageLines as $line) {
            if (isset($variableProducts[$line['post_id']]) && $line['object_id']) {
                $objectIds[] = $line['object_id'];
            }
        }
        if ($objectIds) {
            $itemNames = ProductVariation::query()
                ->whereIn('id', array_values(array_unique($objectIds)))
                ->pluck('variation_title', 'id')
                ->all();
        }

        foreach ($pageLines as $line) {
            $postId = $line['post_id'];

            // A product that is gone or unpublished cannot be reviewed and has
            // no page to link to, so it does not belong on this list at all.
            if (!isset($products[$postId])) {
                continue;
            }

            // An item slot only for a variation that still exists. Orders
            // outlive catalogue variations; a line whose variation is gone
            // files at product level rather than offering a form the server
            // would refuse, since the item can no longer be verified.
            $objectId = $line['object_id'];
            $itemId = isset($variableProducts[$postId], $itemNames[$objectId]) ? $objectId : 0;
            $slot = $postId . ':' . $itemId;

            if (isset($items[$slot])) {
                continue;
            }

            $product = $products[$postId];

            $items[$slot] = [
                'post_id'    => $postId,
                'item_id'    => $itemId,
                'title'      => $line['title'] !== '' ? $line['title'] : $product->post_title,
                // The item's current name, from the variation row.
                'item_label' => $itemId ? trim((string) ($itemNames[$itemId] ?? '')) : '',
                'image'      => $product->getMediaUrl('thumbnail') ?: Helper::getProductPlaceholderUrl(),
                'url'        => get_permalink($postId),
                'reviewed'   => isset($reviewedSlots[$slot]),
                'enabled'    => ProductReviewService::isReviewEnabledForProduct($postId),
            ];
        }

        return array_values($items);
    }

    /**
     * The (product, item) slots on this page the reviewing identity has
     * already filled, keyed "post:item" — item 0 for the product as a whole.
     *
     * @param int[] $postIds the products on the page being rendered
     * @return array<string, true>
     */
    protected function reviewedSlots(array $postIds): array
    {
        if (!$postIds) {
            return [];
        }

        // The identity a submission from this page is filed under — the
        // buyer, unless the visitor IS the buyer — so the reviewed state
        // here and the duplicate guard on submit can never disagree. An
        // order whose customer row is gone yields no identity for a guest;
        // that visitor gets the form and its typed fields.
        $identity = ProductReviewService::effectiveReviewerIdentity($this->order);

        // An order whose customer row is gone has no user and no email, but
        // its slot is the order itself — a review already filed from this
        // link must still read as reviewed, or the page offers a form the
        // duplicate guard is about to refuse.
        if (!$identity['user_id'] && !$identity['emails'] && !$identity['order_id']) {
            return [];
        }

        $query = ProductReviewService::scopeToIdentity(ProductReview::query(), $identity)
            ->whereIn('post_id', $postIds)
            ->whereIn('status', Status::getReviewDuplicateStatuses());

        $reviewed = [];
        foreach ($query->get(['post_id', 'item_id']) as $review) {
            $reviewed[(int) $review->post_id . ':' . (int) $review->item_id] = true;
        }

        return $reviewed;
    }

    protected function renderItem(array $item)
    {
        ?>
        <div class="fct-order-review-item-media">
            <img src="<?php echo esc_url($item['image']); ?>" alt="" width="72" height="72" loading="lazy"/>
        </div>
        <div class="fct-order-review-item-body">
            <h2 class="fct-order-review-item-title">
                <?php if ($item['url']) : ?>
                    <a href="<?php echo esc_url($item['url']); ?>"><?php echo esc_html($item['title']); ?></a>
                <?php else : ?>
                    <?php echo esc_html($item['title']); ?>
                <?php endif; ?>
                <?php if ($item['item_label'] !== '') : ?>
                    <span class="fct-order-review-item-variant"><?php echo esc_html($item['item_label']); ?></span>
                <?php endif; ?>
            </h2>

            <?php if (!$item['enabled']) : ?>
                <p class="fct-order-review-item-note">
                    <?php esc_html_e('Reviews are turned off for this product.', 'fluent-cart'); ?>
                </p>
            <?php elseif ($item['reviewed']) : ?>
                <p class="fct-order-review-item-note">
                    <?php esc_html_e('You’ve already reviewed this product. Thank you for your feedback!', 'fluent-cart'); ?>
                </p>
            <?php else : ?>
                <div class="fct-order-review-item-action" data-order-review-pending data-post-id="<?php echo esc_attr($item['post_id']); ?>" data-item-id="<?php echo esc_attr($item['item_id']); ?>">
                    <?php $this->renderItemForm($item['post_id'], $item['item_id']); ?>
                </div>
                <?php
                // Pre-rendered so the confirmation needs no strings in JS —
                // ReviewForm.js closes the overlay on success and the row
                // behind it would otherwise still offer a button that the
                // duplicate guard is about to refuse.
                ?>
                <p class="fct-order-review-item-note" data-order-review-done role="status" tabindex="-1" hidden>
                    <?php esc_html_e('You’ve already reviewed this product. Thank you for your feedback!', 'fluent-cart'); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * The trigger and the form for one product — the real Write a Review
     * block, rendered through render_block().
     *
     * Not a direct ProductReviewRenderer call, deliberately. Going through the
     * block means this page gets the same pipeline a page built in the editor
     * gets: the block's own attribute validation, its wrapper attributes and
     * block supports, and any render_block filter a site or add-on has
     * registered. A direct call would quietly diverge from the block the
     * moment either gained a feature.
     *
     * @param int $postId
     * @param int $itemId the order line's variation, 0 for the product
     * @return void
     */
    protected function renderItemForm($postId, $itemId = 0)
    {
        // Read by the renderer_options filter injected around the loop, so
        // the block — which has no item attribute — still hands the item to
        // the renderer it builds.
        $this->currentItemId = max(0, (int) $itemId);

        $attributes = apply_filters('fluent_cart/order_review/block_attributes', [
            // 'custom' is what makes the block honour product_id — on its
            // default setting it would look for a current product, and this
            // page is not a product page.
            'query_type' => 'custom',
            'product_id' => (int) $postId,
            'container'  => 'modal',
            // Every field at once: the customer came here to write, not
            // to be walked through a wizard.
            'layout'     => 'inline',
        ], [
            'post_id' => $postId,
            'item_id' => $this->currentItemId,
            'order'   => $this->order,
        ]);

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_block() output is escaped by the block's own render callback
        echo render_block([
            'blockName'    => 'fluent-cart/write-a-review-button',
            'attrs'        => $attributes,
            'innerBlocks'  => [],
            'innerHTML'    => '',
            'innerContent' => [],
        ]);

        $this->currentItemId = 0;
    }

    /**
     * Hand the order hash to every ProductReviewRenderer built while this page
     * renders.
     *
     * The block owns its own attributes and has no notion of an order, so the
     * grant reaches the renderer the way any other placement-level option
     * would — through the renderer_options filter the constructor already
     * applies. Added around the item loop and removed straight after, so it
     * cannot leak into anything else in the request.
     *
     * @return callable the filter callback, to pass back to releaseGrantInjection()
     */
    protected function injectGrantIntoRenderers(): callable
    {
        $orderHash = $this->orderHash;
        $page = $this;

        // Two parameters because the filter passes two (options, post id);
        // the post id is not needed here, but the registration below declares
        // two accepted args and the callback has to match that contract.
        $callback = static function ($options, $postId = 0) use ($orderHash, $page) {
            // resolveOrderGrant() re-checks that the hash actually covers the
            // product — and, with an item, the exact variation — being
            // rendered, so handing both to every renderer on this page grants
            // nothing extra: an unrelated product or item resolves null.
            $options['orderHash'] = $orderHash;
            $options['itemId'] = $page->currentItemId();

            // A row is rendered with a form only when reviewedProductIds()
            // found no review in its slot for the identity a submission is
            // filed under — which is the identity the form's edit-mode
            // lookup would ask about, on the same statuses. The answer is
            // therefore already known to be "none": saying so spares the
            // block one review query per row for its CTA and its form.
            $options['existingReview'] = null;

            return $options;
        };

        add_filter('fluent_cart/review/renderer_options', $callback, 10, 2);

        return $callback;
    }

    protected function releaseGrantInjection(callable $callback): void
    {
        remove_filter('fluent_cart/review/renderer_options', $callback, 10);
    }

    /**
     * The item of the row currently rendering — public only so the filter
     * closure above, which cannot see protected state, can read it.
     */
    public function currentItemId(): int
    {
        return $this->currentItemId;
    }
}

<?php

namespace FluentCart\App\Modules\Reviews;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Services\Permission\PermissionManager;
use FluentCart\App\Services\ProductReviewService;
use FluentCart\App\Services\Renderer\ProductReviewRenderer;
use FluentCart\Framework\Support\Arr;

class ReviewModule
{
    public function register()
    {
        $this->registerSidebarMenu();
        $this->registerProductPickerOptions();

        // Store-wide star colours set through `fluent_cart/reviews/star_colors`.
        add_action('wp_head', [__CLASS__, 'printStarColors'], 101);

        // Register reviews as a valid module key (settings managed via dedicated Product Reviews page)
        add_filter('fluent_cart/module_setting/fields', function ($fields) {
            $fields['reviews'] = [
                'title'  => __('Product Reviews', 'fluent-cart'),
                'hidden' => true,
            ];
            return $fields;
        });

        add_filter('fluent_cart/module_setting/default_values', function ($values) {
            if (empty($values['reviews']['active'])) {
                $values['reviews']['active'] = 'yes';
            }
            if (empty($values['reviews']['review_permission_mode'])) {
                $values['reviews']['review_permission_mode'] = 'verified_buyers';
            }
            if (empty($values['reviews']['auto_approve_reviews'])) {
                $values['reviews']['auto_approve_reviews'] = 'no';
            }
            if (empty($values['reviews']['show_verified_badge'])) {
                $values['reviews']['show_verified_badge'] = 'yes';
            }
            if (empty($values['reviews']['enable_star_rating'])) {
                $values['reviews']['enable_star_rating'] = 'yes';
            }
            if (empty($values['reviews']['star_rating_required'])) {
                $values['reviews']['star_rating_required'] = 'yes';
            }
            if (empty($values['reviews']['reviews_per_page'])) {
                $values['reviews']['reviews_per_page'] = 10;
            }
            return $values;
        });
    }

    /**
     * Print the star colours a `fluent_cart/reviews/star_colors` filter changed.
     *
     * Nothing is printed while the defaults stand; the stylesheets already
     * carry them.
     *
     * @return void
     */
    public static function printStarColors(): void
    {
        if (is_admin()) {
            return;
        }

        $css = ProductReviewRenderer::starColorCss();

        if ($css === '') {
            return;
        }

        // Safe unescaped: the property names are fixed and every value has
        // been through sanitize_hex_color() in ProductReviewRenderer::starColors().
        echo '<style id="fluent-cart-review-star-colors">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Products, each with its variations, for the add-review modal's picker.
     *
     * Registered against the advanced-filter remote endpoint rather than a
     * route of its own: that URL already exists for exactly this shape of
     * lookup, resolves its permission per data key, and is documented as
     * where a module registers keys it owns.
     *
     * The key is review-scoped deliberately. Reusing the products endpoint
     * would have meant products/view, which a reviews-only moderator does not
     * hold — they could open the reviews screen and the Add Review dialog but
     * be refused by its own product picker, unable to finish the workflow
     * their capability grants. That is what this key exists to prevent, and
     * ReviewProductLookupCest is its regression guard.
     *
     * The answer carries titles only. Prices, stock, payment type and every
     * other commercial field stay behind products/view: a moderator picks what
     * a review is about, and needs nothing else to.
     *
     * Variations ride along on the product row rather than costing a request
     * per expanded row, and only for a product whose variations are items
     * worth telling apart — ProductReviewService decides that, the same rule
     * resolveReviewItem() applies when the review is written, so the picker and
     * the write path can never disagree about which products have items.
     */
    protected function registerProductPickerOptions()
    {
        add_filter('fluent_cart/advanced_filter_options_review_products', function ($options, $args) {
            $limit = (int) Arr::get($args, 'limit', 20);
            $limit = $limit > 0 ? min($limit, 50) : 20;

            $query = Product::query()
                // Only what the picker renders. Product appends a thumbnail off
                // its detail row's gallery meta, which the options list never
                // shows — not selected, and the relation not loaded.
                ->select(['ID', 'post_title'])
                ->whereIn('post_status', Status::productAdminAllStatuses())
                ->orderBy('post_title', 'ASC')
                ->limit($limit);

            $search = trim((string) Arr::get($args, 'search', ''));
            if ($search) {
                $query->whereLike('post_title', $search);
            }

            // Named ids instead of a search: how a caller asks about products
            // it already knows — the Add Review dialog asks whether the
            // product it was opened on has variations at all. Capped like any
            // other user-supplied array.
            $includeIds = Arr::get($args, 'include_ids', '');
            $includeIds = is_array($includeIds) ? $includeIds : array_filter(explode(',', (string) $includeIds));
            $includeIds = array_slice(array_filter(array_map('intval', $includeIds)), 0, 50);
            if ($includeIds) {
                $query->whereIn('ID', $includeIds);
            }

            $products = $query->get();

            $postIds = [];
            foreach ($products as $product) {
                $postIds[] = (int) $product->ID;
            }

            $variationsByProduct = static::variationsForProducts($postIds);

            $data = [];
            foreach ($products as $product) {
                $postId = (int) $product->ID;
                $data[] = [
                    'id'         => $postId,
                    'title'      => (string) $product->post_title,
                    'variations' => Arr::get($variationsByProduct, $postId, []),
                ];
            }

            return ['data' => $data];
        }, 10, 2);

        // Answering only for this key, which is why the hook is named after it.
        add_filter('fluent_cart/advanced_filter_options_permission_review_products', function () {
            return PermissionManager::hasPermission(['reviews/manage']);
        });
    }

    /**
     * The variations of a page of products, keyed by product, in one query.
     *
     * Two queries for the whole page rather than two per row: one asking which
     * of these products have items at all, one for those products' variation
     * rows. A product that is not variation-typed is left out entirely, so its
     * lone default variation never surfaces as a choice.
     *
     * Which products have items is ProductReviewService's to say, not this
     * file's — the same rule resolveReviewItem() applies when the review is
     * written, so the picker cannot offer a variation the write path refuses.
     *
     * @param array $postIds
     * @return array<int, array<int, array{id:int,title:string}>>
     */
    protected static function variationsForProducts(array $postIds): array
    {
        $itemTyped = ProductReviewService::itemTypedProductIds($postIds);

        if (!$itemTyped) {
            return [];
        }

        $rows = ProductVariation::query()
            ->select(['id', 'post_id', 'variation_title'])
            ->whereIn('post_id', $itemTyped)
            ->orderBy('serial_index', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row->post_id][] = [
                'id'    => (int) $row->id,
                'title' => (string) $row->variation_title,
            ];
        }

        return $grouped;
    }

    /**
     * Show Reviews as a "↳" child under Products in the WP admin left
     * sidebar, mirroring the Attributes and Inventory children. Hidden by
     * default via inline CSS; useNavigationMenuUpdateService toggles it
     * visible whenever the active route's active_menu is 'products'.
     */
    protected function registerSidebarMenu()
    {
        add_action('admin_enqueue_scripts', function () {
            wp_register_style('fluent-cart-reviews-admin', false);
            wp_enqueue_style('fluent-cart-reviews-admin');
            wp_add_inline_style('fluent-cart-reviews-admin', '
                .toplevel_page_fluent-cart li.fluent_cart_reviews {
                    display: none;
                }
            ');
        });

        add_action('fluent_cart/admin_submenu_added', function () {
            global $submenu;
            if (!isset($submenu['fluent-cart'])) {
                return;
            }

            // Only while the store has reviews switched on. The screen behind
            // this entry redirects to the dashboard when the module is off, so
            // without this the sidebar offers a link that bounces whoever
            // clicks it. Read here rather than at registration time: the
            // sidebar is built per request, and a store that switches reviews
            // off must not need a second page load to see it go.
            if (!ModuleSettings::isActive('reviews')) {
                return;
            }

            // Granular gate, matching MenuHandler's own sidebar items: the WP
            // capability below only clears the admin bar — reviews/manage is
            // what actually authorizes the reviews screens.
            if (!PermissionManager::hasPermission(['reviews/manage'])) {
                return;
            }

            $capability = 'manage_options';
            if (!current_user_can('manage_options')) {
                $capability = PermissionManager::ADMIN_CAP;
            }

            $entry = [
                __('↳ Reviews', 'fluent-cart'),
                $capability,
                'admin.php?page=fluent-cart#/reviews',
                '',
                'fluent_cart_reviews',
            ];

            // Insert after the last item of the Products group so the order
            // reads Products → Attributes → Inventory → Reviews.
            $afterKeys = ['inventory', 'attributes', 'products'];
            $insertAfter = 'products';
            foreach ($afterKeys as $candidate) {
                if (isset($submenu['fluent-cart'][$candidate])) {
                    $insertAfter = $candidate;
                    break;
                }
            }

            $newSubmenu = [];
            foreach ($submenu['fluent-cart'] as $key => $item) {
                $newSubmenu[$key] = $item;
                if ($key === $insertAfter && !isset($newSubmenu['reviews'])) {
                    $newSubmenu['reviews'] = $entry;
                }
            }
            if (!isset($newSubmenu['reviews'])) {
                $newSubmenu['reviews'] = $entry;
            }
            $submenu['fluent-cart'] = $newSubmenu;
        }, 1000); // after the Attributes (999) and Inventory children are placed
    }
}

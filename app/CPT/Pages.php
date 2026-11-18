<?php

namespace FluentCart\App\CPT;

use FluentCart\Api\ModuleSettings;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Models\DynamicModel;
use FluentCart\App\Services\Renderer\ProductCardLayoutPresets;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;

class Pages
{

    /** Set when a store creates its Shop page, so block templates use layout cards for new stores only. */
    const LAYOUT_CARD_OPTION = 'fluent_cart_layout_card_defaults';

    protected static array $cachedPages = [];

    public function pages(): array
    {
        // Lets addons register their own generatable pages ('key' => title + content),
        // so the Pages Setup "+" create button and onboarding can build them.
        return apply_filters('fluent_cart/generatable_pages', $this->corePages());
    }

    public function corePages(): array
    {
        $pages = [
            'checkout'         => [
                'title'   => 'Checkout',
                'content' => '[fluent_cart_checkout]'
            ],
            'cart'             => [
                'title'   => 'Cart',
                'content' => '[fluent_cart_cart]'
            ],
            'receipt'          => [
                'title'   => 'Receipt',
                'content' => '[fluent_cart_receipt]'
            ],
            'order_review'     => [
                'title'   => 'Review Your Order',
                'content' => '[fluent_cart_order_review]'
            ],
            'shop'             => [
                'title'   => 'Shop',
                'content' => static::getShopPageContent()
            ],
            'customer_profile' => [
                'title'   => 'Account',
                'content' => '<!-- wp:fluent-cart/customer-profile /-->'
            ]
        ];

        // The order review page serves the reviews module. While the module
        // is off it is not a page the store is missing: the dashboard must
        // not ask for it, onboarding must not create it, and Pages Setup
        // does not offer it (StoreSettings::fields()). Read per call, so
        // switching the module on makes it wanted again at once.
        if (!ModuleSettings::isActive('reviews')) {
            unset($pages['order_review']);
        }

        return $pages;
    }


    public function createPages(array $exclude = []): array
    {
        $storeSettings = new StoreSettings();
        $createdPages = [];
        $pages = Arr::except($this->pages(), $exclude);
        foreach ($pages as $key => $page) {
            $title = $page['title'];
            $pageKey = "{$key}_page_id";

            $content = $page['content'];
            $info = [
                'post_title'   => $title,
                'post_content' => $content,
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_name'    => $title,
            ];
            $pageId = wp_insert_post($info);
            if ($key === 'shop' && $pageId) {
                static::markLayoutCardStore();
            }
            $storeSettings->set($pageKey, $pageId);
            $createdPages[$pageKey] = $pageId;


        }
        return $createdPages;
    }

    public static function markLayoutCardStore(): void
    {
        update_option(static::LAYOUT_CARD_OPTION, 'yes');
    }

    public function getGeneratablePage(bool $withDefaultId = false): array
    {
        $pages = $this->pages();
        if ($withDefaultId) {
            foreach ($pages as $key => $page) {
                $title = $page['title'];
                $pageKey = "{$key}_page_id";
                $page = (new DynamicModel([], 'posts'))
                    ->newQuery()
                    ->where('post_type', 'page')
                    ->where('post_status', 'publish')
                    ->where('post_content', 'LIKE', "%" . Str::of($page['content'])->remove('/-->') . '%')
                    ->orderByDesc('ID')
                    ->first();
                if (!empty($page)) {
                    $pages[$key]['page_id'] = $page->ID;
                }
            }
        }
        return $pages;
    }

    public static function getPages($searchBy, $ignoreCache = false)
    {
        $searchByKey = $searchBy ?? 'all';

        if (isset(static::$cachedPages[$searchByKey]) && !$ignoreCache) {
            return static::$cachedPages[$searchByKey];
        }


        $results = (new DynamicModel([], 'posts'))
            ->newQuery()
            ->select('ID', 'post_title')
            ->where('post_type', 'page')
            ->where('post_status', 'publish')
            ->where('post_title', 'LIKE', "%" . esc_sql($searchBy) . '%')
            ->get();


        $matchedPages = [];
        foreach ($results as $result) {
            $matchedPages[] = [
                'label' => $result->post_title . "( $result->ID )",
                'value' => $result->ID,
            ];
        }

        static::$cachedPages[$searchByKey] = $matchedPages;
        return $matchedPages;
    }

    public function handlePageDelete()
    {
        add_action('wp_trash_post', function ($post_id) {
            if (get_post_type($post_id) === 'page') {
                $settings = (new \FluentCart\Api\StoreSettings());
                $pageSettings = $settings->getPagesSettings();
                $allSettings = $settings->get();
                $pageId = $post_id;

                foreach ($pageSettings as $pageName => $pageSetting) {
                    if ($pageSetting == $pageId) {
                        $allSettings[$pageName] = '';
                    }
                }
                $settings->save($allSettings);
            }
        });
    }

    public static function isPage($pageId): bool
    {
        static $cachedIsPage = [];

        if (isset($cachedIsPage[$pageId])) {
            return $cachedIsPage[$pageId];
        }

        $post = get_post($pageId);
        if (empty($post) || $post->post_status !== 'publish') {
            $cachedIsPage[$pageId] = false;
            return false;
        }

        $cachedIsPage[$pageId] = $post->post_type == 'page';

        return $cachedIsPage[$pageId];
    }

    /**
     * The Products block's attributes on the Shop page: filters on, with the category, brand and price
     * filters. $withoutFilters names filters to leave out, e.g. the category filter on a category page.
     */
    public static function productsBlockAttributes(array $withoutFilters = []): string
    {
        $attributes = '{"colors":{},"enable_filter":true,"enable_wildcard_filter":true,"filters":{"product-categories":{"filter_type":"options","is_meta":true,"label":"Product Categories","enabled":true,"multiple":false},"product-brands":{"filter_type":"options","is_meta":true,"label":"Product Brands","enabled":true,"multiple":false},"price_range":{"filter_type":"range","is_meta":false,"label":"Price","enabled":true}}}';
        if (!$withoutFilters) {
            return $attributes;
        }

        // Objects, so an empty `colors` stays {} and not [].
        $decoded = json_decode($attributes);
        foreach ($withoutFilters as $filter) {
            unset($decoded->filters->{$filter});
        }

        return wp_json_encode($decoded);
    }

    /**
     * The Products block as the editor inserts it, with the filters on and the Classic layout card.
     * Written into a Shop page once, when it is created; existing Shop pages keep their content.
     */
    public static function getShopPageContent(): string
    {
        $card = ProductCardLayoutPresets::cardBlock('classic');
        $card['attrs']['card_width'] = '100%';
        $noResultHelp = sprintf(
            /* translators: %1$s, %2$s, %3$s, %4$s is the anchor tag */
            __('You can try %1$s clearing any filters %2$s or head to our %3$s store\'s home %4$s', 'fluent-cart'),
            '<a href="#">', '</a>', '<a href="#">', '</a>'
        );

        return '<!-- wp:fluent-cart/products ' . static::productsBlockAttributes() . ' -->
<div class="wp-block-fluent-cart-products"><div class="fct-products-wrapper" data-fluent-cart-shop-app="true" data-fluent-cart-product-wrapper=""><!-- wp:fluent-cart/shopapp-product-action-container -->
<div class="wp-block-fluent-cart-shopapp-product-action-container fluent-cart-shop-app-view-switcher"><!-- wp:fluent-cart/shopapp-product-view-switcher /-->

<!-- wp:fluent-cart/shopapp-product-filter-sort-by /--></div>
<!-- /wp:fluent-cart/shopapp-product-action-container -->

<!-- wp:fluent-cart/shopapp-product-container {"className":"fluent-product-container"} -->
<div class="wp-block-fluent-cart-shopapp-product-container fluent-product-container"><!-- wp:fluent-cart/shopapp-product-filter -->
<div class="fluent-cart-product-filter-wrapper"><!-- wp:fluent-cart/shopapp-product-filter-search-box /-->

<!-- wp:fluent-cart/shopapp-product-filter-filters /-->

<!-- wp:fluent-cart/shopapp-product-filter-button -->
<div class="fct-product-block-filter-item"><!-- wp:fluent-cart/shopapp-product-filter-apply-button /-->

<!-- wp:fluent-cart/shopapp-product-filter-reset-button /--></div>
<!-- /wp:fluent-cart/shopapp-product-filter-button --></div>
<!-- /wp:fluent-cart/shopapp-product-filter -->

<!-- wp:fluent-cart/shopapp-product-loop {"className":"fluent-product-loop","metadata":{"name":"Product Loop"}} -->
<div class="fluent-cart-product-loop fct-product-block-editor-product-card">' . serialize_block($card) . '</div>
<!-- /wp:fluent-cart/shopapp-product-loop -->

<!-- wp:fluent-cart/shopapp-product-no-result {"className":"fluent-product-no-result","metadata":{"name":"No Result"}} -->
<div class="fluent-cart-shop-no-result-wrap"><!-- wp:paragraph {"align":"center","fontSize":"large"} -->
<p class="has-text-align-center has-large-font-size">' . esc_html__('No results found', 'fluent-cart') . '</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center">' . wp_kses($noResultHelp, ['a' => ['href' => true]]) . '</p>
<!-- /wp:paragraph --></div>
<!-- /wp:fluent-cart/shopapp-product-no-result -->

<!-- wp:fluent-cart/shopapp-product-loader {"metadata":{"name":"Product Loader"}} -->
<div class="fluent-cart-product-loader"><!-- wp:fluent-cart/shopapp-product-spinner -->
<div class="fluent-cart-product-spinner"></div>
<!-- /wp:fluent-cart/shopapp-product-spinner --></div>
<!-- /wp:fluent-cart/shopapp-product-loader --></div>
<!-- /wp:fluent-cart/shopapp-product-container -->

<!-- wp:fluent-cart/product-paginator {"className":"fluent-product-paginator","metadata":{"name":"Paginator"}} -->
<div class="fluent-cart-product-paginator"><!-- wp:fluent-cart/product-paginator-info /-->

<!-- wp:fluent-cart/product-paginator-number /--></div>
<!-- /wp:fluent-cart/product-paginator --></div></div>
<!-- /wp:fluent-cart/products -->';
    }
}

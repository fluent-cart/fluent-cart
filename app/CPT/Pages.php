<?php

namespace FluentCart\App\CPT;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Models\DynamicModel;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;

class Pages
{

    protected static array $cachedPages = [];

    public function pages(): array
    {
        return [
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
            'shop'             => [
                'title'   => 'Shop',
                'content' => static::getShopPageContent()
            ],
            'customer_profile' => [
                'title'   => 'Account',
                'content' => '<!-- wp:fluent-cart/customer-profile /-->'
            ]
        ];
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
            $storeSettings->set($pageKey, $pageId);
            $createdPages[$pageKey] = $pageId;


        }
        return $createdPages;
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

    public static function getShopPageContent(): string
    {

        $fluentCartProductsBlock = "<!-- wp:fluent-cart/products {\"colors\":{},\"enable_filter\":true,\"enable_wildcard_filter\":true,\"filters\":{\"product-categories\":{\"filter_type\":\"options\",\"is_meta\":true,\"label\":\"Product Categories\",\"enabled\":true,\"multiple\":false},\"product-brands\":{\"filter_type\":\"options\",\"is_meta\":true,\"label\":\"Product Brands\",\"enabled\":true,\"multiple\":false},\"price_range\":{\"filter_type\":\"range\",\"is_meta\":false,\"label\":\"Price\",\"enabled\":true}}} -->
<div class=\"wp-block-fluent-cart-products\"><div class=\"fct-products-wrapper\" data-fluent-cart-shop-app=\"true\" data-fluent-cart-product-wrapper=\"\"><!-- wp:fluent-cart/shopapp-product-view-switcher {\"className\":\"fluent-product-view-switcher\",\"metadata\":{\"name\":\"View Switcher\"}} /-->

<!-- wp:fluent-cart/shopapp-product-container {\"className\":\"fluent-product-container\"} -->
<div class=\"wp-block-fluent-cart-shopapp-product-container fluent-product-container\"><!-- wp:fluent-cart/shopapp-product-filter -->
<div class=\"fluent-cart-product-filter-wrapper\"><!-- wp:fluent-cart/shopapp-product-filter-search-box /-->

<!-- wp:fluent-cart/shopapp-product-filter-filters /-->

<!-- wp:fluent-cart/shopapp-product-filter-button -->
<div class=\"fct-product-block-filter-item\"><!-- wp:fluent-cart/shopapp-product-filter-apply-button /-->

<!-- wp:fluent-cart/shopapp-product-filter-reset-button /--></div>
<!-- /wp:fluent-cart/shopapp-product-filter-button --></div>
<!-- /wp:fluent-cart/shopapp-product-filter -->

<!-- wp:fluent-cart/shopapp-product-loop {\"wp_client_id\":\"8c5b4ab5-1e93-4e68-867c-34ecd6250211\",\"last_changed\":\"2025-10-03T11:23:14.858Z\",\"className\":\"fluent-product-loop\",\"metadata\":{\"name\":\"Product Loop\"}} -->
<div class=\"fluent-cart-product-loop fct-product-block-editor-product-card\"><!-- wp:fluent-cart/shopapp-product-image -->
<div class=\"fluent-cart-product-image\"></div>
<!-- /wp:fluent-cart/shopapp-product-image -->

<!-- wp:fluent-cart/shopapp-product-title /-->

<!-- wp:fluent-cart/shopapp-product-price /-->

<!-- wp:fluent-cart/shopapp-product-buttons /--></div>
<!-- /wp:fluent-cart/shopapp-product-loop --></div>
<!-- /wp:fluent-cart/shopapp-product-container -->

<!-- wp:fluent-cart/product-paginator {\"className\":\"fluent-product-paginator\",\"metadata\":{\"name\":\"Paginator\"}} -->
<div class=\"fluent-cart-product-paginator\"><!-- wp:fluent-cart/product-paginator-info /-->

<!-- wp:fluent-cart/product-paginator-number /--></div>
<!-- /wp:fluent-cart/product-paginator -->

<!-- wp:fluent-cart/shopapp-product-no-result {\"className\":\"fluent-product-no-result\",\"metadata\":{\"name\":\"No Result\"}} -->
<div class=\"fct-product-block-filter-item\"><!-- wp:paragraph {\"align\":\"center\",\"fontSize\":\"large\"} -->
<p class=\"has-text-align-center has-large-font-size\">No results found</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {\"align\":\"center\"} -->
<p class=\"has-text-align-center\">You can try <a href=\"#\">clearing any filters</a> or head to our <a href=\"#\">store's home</a></p>
<!-- /wp:paragraph --></div>
<!-- /wp:fluent-cart/shopapp-product-no-result --></div></div>
<!-- /wp:fluent-cart/products -->";

// Example usage
        return $fluentCartProductsBlock;

    }


}

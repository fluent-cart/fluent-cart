<?php

namespace FluentCart\App\Hooks\Handlers\ShortCodes;

use FluentCart\Api\CurrencySettings;
use FluentCart\Api\Resource\ShopResource;
use FluentCart\Api\StoreSettings;
use FluentCart\Api\Taxonomy;
use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
//use FluentCart\App\Hooks\Handlers\ShortCodes\Buttons\AddToCartShortcode;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\ShopAppRenderer;
use FluentCart\App\Services\TemplateService;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;

class ShopAppHandler
{
    protected array $viewData = [];
    protected array $defaultViewData = [];
    protected array $shortcodeAttributes = [];

    protected string $slug = '';
    protected string $assetsPath = '';
    protected bool $shouldLoadRangePlugin = false;

    protected array $urlFilters = [];

    const SHORT_CODE = 'fluent_cart_products';

    public function register()
    {
        add_action('wp_enqueue_scripts', function () {
            if (App::request()->get('action') === 'elementor') {
                return;
            }

            if (TemplateService::getCurrentFcPageType() === 'shop') {
                $this->enqueueStyles();
            } else if (has_shortcode(get_the_content(), static::SHORT_CODE) || has_block('fluent-cart/products')) {
                $this->enqueueStyles();
            }
        }, 5);
        add_shortcode(static::SHORT_CODE, function ($shortcodeAttributes, $content, $block) {
            return $this->handelShortcodeCall($shortcodeAttributes);
        });
    }

    public function handelShortcodeCall($shortcodeAttributes)
    {
        $urlFilters = Arr::get(App::request()->all(), 'filters', []);
        if (!is_array($urlFilters)) {
            $urlFilters = [];
        }

        $this->urlFilters = $urlFilters;

        $this->buildPaths();
        $this->buildShortcodeAttributes($shortcodeAttributes);
        $this->buildFilters();
        $this->enqueueAssets();
        $this->prepareInitialViewData();

        return $this->renderView();
    }

    private function buildPaths()
    {
        $app = App::getInstance();
        $this->slug = $app->config->get('app.slug');
        $this->assetsPath = $app['url.assets'];
    }

    private function buildShortcodeAttributes($shortcodeAttributes)
    {
        $this->shortcodeAttributes = shortcode_atts(array(
            'per_page'                         => '10',
            // 'view_mode' => 'default',
            'view_mode'                        => 'grid',
            'with_cart'                        => 'no',
            'cats'                             => '',
            'exclude_carts'                    => '',
            'show_cat_filter'                  => 'no',
            'block_class'                      => '',
            'paginator'                        => 'scroll',
            'uid'                              => 'fluent_products_container_' . Helper::getUidSerial(),
            'enable_filter'                    => false,
            'enable_wildcard_filter'           => false,
            'enable_wildcard_for_post_content' => false,
            'default_filters'                  => json_encode(['enabled' => false]),
            'use_default_style'                => true,
            'search_grid_size'                 => 1,
            'product_grid_size'                => 4,
            'product_box_grid_size'            => 4,
            'colors'                           => json_encode([]),
            'price_format'                     => 'starts_from',
            'order_type'                       => 'DESC',
            'live_filter'                      => false,
            'custom_filters'                   => json_encode([]),
            'filters'                          => json_encode([]),
            'ids'                              => '',
            'exclude_ids'                      => '',
            'category'                         => '',
            'category_id'                      => '',
            'tag'                              => '',
            'tag_id'                           => '',
            'fulfillment_type'                 => '',
            'product_type'                     => '',
            'on_sale'                          => '',
            'sort_by'                          => '',
            'columns'                          => '',
            'orderby'                          => '',
            'order'                            => '',
            'limit'                            => '',
        ), $shortcodeAttributes, static::SHORT_CODE);

        // Alias: limit → per_page
        if (!empty($this->shortcodeAttributes['limit']) && is_numeric($this->shortcodeAttributes['limit'])) {
            $this->shortcodeAttributes['per_page'] = $this->shortcodeAttributes['limit'];
        }

        // Alias: columns → product_box_grid_size (controls the CSS grid columns)
        if (!empty($this->shortcodeAttributes['columns']) && is_numeric($this->shortcodeAttributes['columns'])) {
            $this->shortcodeAttributes['product_box_grid_size'] = $this->shortcodeAttributes['columns'];
        }

        // Map orderby+order → sort_by (WooCommerce-style)
        if (!empty($this->shortcodeAttributes['orderby']) && empty($this->shortcodeAttributes['sort_by'])) {
            $order = strtoupper($this->shortcodeAttributes['order'] ?: 'DESC');
            $orderbyMap = [
                'date'  => ['ASC' => 'date-oldest', 'DESC' => 'date-newest'],
                'title' => ['ASC' => 'name-asc', 'DESC' => 'name-desc'],
                'price' => ['ASC' => 'price-low', 'DESC' => 'price-high'],
                'id'    => ['ASC' => 'date-oldest', 'DESC' => 'date-newest'],
            ];
            $orderby = strtolower($this->shortcodeAttributes['orderby']);
            if (isset($orderbyMap[$orderby][$order])) {
                $this->shortcodeAttributes['sort_by'] = $orderbyMap[$orderby][$order];
            }
        }

        $this->shortcodeAttributes['per_page'] = is_numeric($this->shortcodeAttributes['per_page']) ? (int) $this->shortcodeAttributes['per_page'] : 10;

        $viewMode = $this->shortcodeAttributes['view_mode'];

        if (!($viewMode === 'list' || $viewMode === 'grid')) {
            $viewMode = 'grid';
        }

        $this->shortcodeAttributes['view_mode'] = $viewMode;

        $this->handelGridSizes('product_box_grid_size', 3);
        $this->handelGridSizes('search_grid_size', 2);
        $this->handelGridSizes('product_grid_size');

        if ($this->shortcodeAttributes['enable_filter'] && Arr::get($shortcodeAttributes, 'filters', false)) {
            if (is_array($shortcodeAttributes['filters'])) {
                $this->shortcodeAttributes['filters'] = $shortcodeAttributes['filters'];
            } else {
                $jsonData = stripslashes(html_entity_decode($shortcodeAttributes['filters']));
                $this->shortcodeAttributes['filters'] = json_decode($jsonData, true);
            }
        } else {
            $this->shortcodeAttributes['filters'] = [
                'enabled' => false
            ];
        }
    }

    public function handelGridSizes($key, $defaultValue = 6)
    {
        $this->shortcodeAttributes[$key] = intval($this->shortcodeAttributes[$key]);

        if (empty($this->shortcodeAttributes[$key])) {
            return;
        }

        if ($this->shortcodeAttributes[$key] > 6 || $this->shortcodeAttributes[$key] < 1) {
            $this->shortcodeAttributes[$key] = $defaultValue;
        }
    }

    private function buildFilters()
    {
        $this->viewData['filters'] = Arr::get($this->shortcodeAttributes, 'filters', []);

        $filters = [];


        foreach ($this->viewData['filters'] ?? [] as $key => $val) {
            //Filter Out The filters are disabled
            $enabled = Arr::get($val, 'enabled', false);

            $isEnabled = in_array($enabled, [true, '1', 'true'], true);


            if (!$isEnabled) {
                continue;
            }

            $filters[$key]['label'] = Arr::get($val, 'label', ucfirst($key));
            $filters[$key]['filter_type'] = Arr::get($val, 'filter_type', '');

            if (is_array($val) && Arr::get($val, 'enabled', false) !== false && Arr::get($val, 'is_meta', false) !== false) {
                $prefilled = Arr::get($this->urlFilters, $key);
                $filters[$key]['options'] = $this->getMetaFilterOptions($key, $prefilled);
            }

            if ($filters[$key]['filter_type'] === 'range') {
                $this->shouldLoadRangePlugin = true;
                $minValue = Helper::toDecimalWithoutComma(ProductDetail::query()->min('min_price'));
                $maxValue = Helper::toDecimalWithoutComma(ProductDetail::query()->max('max_price'));

                $minFromUrl = Arr::get($this->urlFilters, $key . '_from', 0);
                $maxFromUrl = Arr::get($this->urlFilters, $key . '_to', $maxValue);

                $filters[$key]['min_value'] = ($minFromUrl < $minValue) ? $minValue : (min($minFromUrl, $maxValue));
                $filters[$key]['max_value'] = ($maxFromUrl < 0) ? 0 : (min($maxFromUrl, $maxValue));

                $filters[$key]['min'] = $minValue;
                $filters[$key]['max'] = $maxValue;
            }
        }

        $this->viewData['filters'] = $filters;
    }

    private function getMetaFilterOptions($key, $prefilled = []): array
    {
        return Taxonomy::getFormattedTerms($key, false, null, 'value', 'label', $prefilled);
    }

    private function prepareInitialViewData()
    {

        $allProducts = $this->getInitialProducts();
        $this->defaultViewData = [
            'products'                         => Arr::get($allProducts, 'products', []),
            'placeholder_image'                => Vite::getAssetUrl('images/placeholder.svg'),
            'paginator'                        => $this->shortcodeAttributes['paginator'],
            'view_mode'                        => $this->shortcodeAttributes['view_mode'],
            'price_format'                     => $this->shortcodeAttributes['price_format'],
            'per_page'                         => $this->shortcodeAttributes['per_page'],
            'enable_filter'                    => $this->shortcodeAttributes['enable_filter'],
            'enable_wildcard_filter'           => $this->shortcodeAttributes['enable_wildcard_filter'],
            'enable_wildcard_for_post_content' => $this->shortcodeAttributes['enable_wildcard_for_post_content'],
            'default_filters'                  => $this->shortcodeAttributes['default_filters'],
            'custom_filters'                   => is_array($this->shortcodeAttributes['custom_filters'])? $this->shortcodeAttributes['custom_filters']: json_decode($this->shortcodeAttributes['custom_filters'], true),
            'use_default_style'                => $this->shortcodeAttributes['use_default_style'],
            'colors'                           => is_array($this->shortcodeAttributes['colors'])? $this->shortcodeAttributes['colors']:  json_decode($this->shortcodeAttributes['colors'], true),
            'store_settings'                   => new StoreSettings(),
            'filters'                          => $this->shortcodeAttributes['filters']
        ];
    }

    protected function getGridAttributes(): array
    {
        $product_grid_size = $this->shortcodeAttributes['product_grid_size'];
        $product_grid_size = empty($product_grid_size) ? 4 : $product_grid_size;

        $search_grid_size = $this->shortcodeAttributes['search_grid_size'];
        $search_grid_size = empty($search_grid_size) ? 1 : $search_grid_size;


        $product_default_grid_size = $product_grid_size;

        if (!empty($this->defaultViewData['enable_filter'])) {
            $product_default_grid_size += $search_grid_size;
        }
        return [
            'search_grid_size'          => $search_grid_size,
            'product_grid_size'         => $product_grid_size,
            'product_default_grid_size' => $product_default_grid_size,
            'product_box_grid_size'     => $this->shortcodeAttributes['product_box_grid_size'] ?? 0
        ];
    }

    public function getBlockClassName(): array
    {
        $blockClass = $this->shortcodeAttributes['block_class'];
        return [
            'block_class' => $blockClass,
        ];
    }

    private function getViewData(): array
    {
        return array_merge(
            $this->defaultViewData,
            $this->viewData,
            $this->getGridAttributes(),
            $this->getBlockClassName(),
            [
                'shortcode_settings' => $this->shortcodeAttributes,
            ]
        );
    }

    private function getInitialProducts()
    {
        $params = $this->getDefaultConfig();

        $products = ShopResource::get($params);

        return [
            'products' => ($products['products']->setCollection(
                $products['products']->getCollection()->transform(function ($product) {
                    $product->setAppends(['view_url', 'has_subscription']);
                    return $product;
                })
            )),
            'total'    => $products['total']
        ];
    }

    private function getDefaultConfig()
    {
        $paginatorMethod = $this->shortcodeAttributes['paginator'] === 'numbers' ? 'simple' : 'cursor';

        $defaultFilters = $this->shortcodeAttributes['default_filters'];
        $customFilters = $this->shortcodeAttributes['custom_filters'];

        $filters = $this->shortcodeAttributes['filters'];
        $enableFilters = Arr::get($filters, 'enabled', false) === true;


        $allowOutOfStock = (Arr::get($defaultFilters, 'enabled', false) === true) &&
            filter_var(Arr::get($defaultFilters, 'allow_out_of_stock', false), FILTER_VALIDATE_BOOLEAN);

        if (Arr::get($defaultFilters, 'enabled') != 1) {
            $defaultFilters = [];
        }

        $status = ["post_status" => ["column" => "post_status", "operator" => "in", "value" => ["publish"]]];

        $urlTerms = Helper::parseTermIdsForFilter($this->urlFilters);
        $defaultTerms = Helper::parseTermIdsForFilter($defaultFilters);
        $mergedTerms = Helper::mergeTermIdsForFilter($defaultTerms, $urlTerms);

        // --- Shortcode attribute: include/exclude IDs ---
        $includeIds = array_values(array_filter(array_map('intval', array_map('trim', explode(',', $this->shortcodeAttributes['ids'])))));
        $excludeIds = array_values(array_filter(array_map('intval', array_map('trim', explode(',', $this->shortcodeAttributes['exclude_ids'])))));

        // --- Shortcode attribute: category (by slug) and category_id ---
        $categoryTermIds = [];
        if (!empty($this->shortcodeAttributes['category'])) {
            $categoryTermIds = Taxonomy::getTermIdsBySlugs($this->shortcodeAttributes['category'], 'product-categories');
        }
        if (!empty($this->shortcodeAttributes['category_id'])) {
            $catIds = array_values(array_filter(array_map('intval', array_map('trim', explode(',', $this->shortcodeAttributes['category_id'])))));
            $categoryTermIds = array_unique(array_merge($categoryTermIds, $catIds));
        }
        if (!empty($categoryTermIds)) {
            $existing = Arr::get($mergedTerms, 'product-categories', []);
            $mergedTerms['product-categories'] = array_unique(array_merge($existing, $categoryTermIds));
        }

        // --- Shortcode attribute: tag (by slug) and tag_id ---
        $tagTermIds = [];
        if (!empty($this->shortcodeAttributes['tag'])) {
            $tagTermIds = Taxonomy::getTermIdsBySlugs($this->shortcodeAttributes['tag'], 'product-tags');
        }
        if (!empty($this->shortcodeAttributes['tag_id'])) {
            $tIds = array_values(array_filter(array_map('intval', array_map('trim', explode(',', $this->shortcodeAttributes['tag_id'])))));
            $tagTermIds = array_unique(array_merge($tagTermIds, $tIds));
        }
        if (!empty($tagTermIds)) {
            $existing = Arr::get($mergedTerms, 'product-tags', []);
            $mergedTerms['product-tags'] = array_unique(array_merge($existing, $tagTermIds));
        }

        // --- Shortcode attribute: sort_by ---
        if (!empty($this->shortcodeAttributes['sort_by'])) {
            $filters['sort_by'] = sanitize_text_field($this->shortcodeAttributes['sort_by']);
        }

        // --- Shortcode attribute: fulfillment_type / product_type, stock_availability, on_sale ---
        // product_type is an alias for fulfillment_type
        $productType = sanitize_text_field($this->shortcodeAttributes['product_type'] ?: $this->shortcodeAttributes['fulfillment_type']);
        $onSale = in_array(strtolower($this->shortcodeAttributes['on_sale']), ['yes', '1', 'true'], true);

        // merge $this->urlFilters and $filters
        $filters = array_merge($filters, $this->urlFilters);

        $params = [
            "select"                   => '*',
            "with"                     => ['detail', 'variants', 'categories', 'licensesMeta'],
            "selected_status"          => true,
            "status"                   => $status,
            "shop_app_default_filters" => $defaultFilters,
            "default_filters" => $defaultFilters,
            "taxonomy_filters"         => $mergedTerms,
            "paginate"                 => $this->shortcodeAttributes['per_page'],
            "per_page"                 => $this->shortcodeAttributes['per_page'],
            'filters'                  => $filters,
            'paginate_using'           => $paginatorMethod,
            'pagination_type'          => $paginatorMethod,
            'allow_out_of_stock'       => $allowOutOfStock,
            'order_type'               => $this->shortcodeAttributes['order_type'],
            'live_filter'              => $this->shortcodeAttributes['live_filter'],
            'enable_filters'           => $enableFilters,
            'custom_filters'           => $customFilters,
            'include_ids'              => $includeIds,
            'exclude_ids'              => $excludeIds,
            'product_type'             => $productType,
            'on_sale'                  => $onSale,
            'product_box_grid_size'    => $this->shortcodeAttributes['product_box_grid_size'],
            'view_mode'                => $this->shortcodeAttributes['view_mode'],
            'price_format'             => $this->shortcodeAttributes['price_format'],
        ];

        return $params;
    }

    public function renderView()
    {
        ob_start();
        (new ShopAppRenderer($this->getInitialProducts(), $this->getDefaultConfig()))
            ->render();
        return ob_get_clean();
    }

    public function enqueueAssets()
    {
        if (App::request()->get('action') === 'elementor') {
            return;
        }

        AssetLoader::loadProductArchiveAssets();


        //SingleProductHandler::enqueueAssets();
    }

    public function enqueueStyles()
    {

    }

    public function enqueueScripts()
    {

    }


    public function getTermIdsForFilter($defaultFilters): array
    {
        $ids = [];

        $taxonomies = Taxonomy::getTaxonomies();
        foreach ($taxonomies as $key => $taxonomy) {

            $termIds = Arr::get($defaultFilters, $key, '');


            if (is_array($termIds)) {
                $ids = array_merge($ids, $termIds);
                continue;
            }
            if (strlen($termIds) || Str::contains($termIds, ',')) {
                $termIds = explode(',', $termIds);

            } else {
                $termIds = [];
            }

            $ids = array_merge($ids, $termIds);

        }
        return $ids;
    }
}

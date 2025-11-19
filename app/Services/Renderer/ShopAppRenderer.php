<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\Api\Resource\ShopResource;
use FluentCart\Api\Taxonomy;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\Framework\Pagination\CursorPaginator;
use FluentCart\Framework\Support\Arr;

class ShopAppRenderer
{
    protected $viewMode = 'grid';

    protected $isFilterEnabled = false;

    protected $per_page = 10;

    protected $order_type = 'DESC';

    protected $order_by = 'id';

    protected $liveFilter = true;

    protected $priceFormat = 'starts_from';

    protected $paginator = 'scroll';
    protected $defaultFilters = [];

    protected $productBoxGridSize = 4;

    protected $config = [];

    protected $filters = [];

    protected $products = [];

    protected $customFilters = [];

    protected $total = 0;

    public function __construct($products = [], $config = [])
    {
        $defaultFilters = Arr::get($config, 'default_filters', []);
        $customFilters = Arr::get($config, 'custom_filters', []);
        $this->customFilters = $customFilters;
        $enableFilter = false;
        if (is_string($customFilters)) {
            $customFilters = json_decode($customFilters, true);
            $this->customFilters = $customFilters;
        }
        if (!empty($customFilters)) {
            $enableFilter = true;
            if (Arr::has($customFilters, 'enabled')) {
                $enableFilter = Arr::get($customFilters, 'enabled');
            }
        }
        $this->config = $config;
        $this->viewMode = $config['view_mode'] ?? 'grid';
        $this->isFilterEnabled = $enableFilter;
        $this->per_page = Arr::get($defaultFilters, 'per_page', 10);
        $this->order_type = Arr::get($defaultFilters, 'sort_type', 'DESC');
        $this->order_by = Arr::get($defaultFilters, 'sort_by', 'id');
        $this->liveFilter = Arr::get($this->customFilters, 'live_filter', true);
        $this->priceFormat = $config['price_format'] ?? 'starts_from';
        $this->paginator = Arr::get($config, 'pagination_type', false);

        if ($this->paginator === 'simple') {
            $this->paginator = 'numbers';
        } else if ($this->paginator === 'cursor') {
            $this->paginator = 'scroll';
        }
        $this->productBoxGridSize = $config['product_box_grid_size'] ?? 4;


        if (Arr::get($products, 'products', [])) {
            $this->products = Arr::get($products, 'products', []);
        } else {
            $this->products = $products;
        }

        if($this->products instanceof CursorPaginator){
            $this->total = 0;
        }else{
            $this->total = Arr::get($products, 'total', 0);
        }
        //$this->total = $products['total'];


        $this->defaultFilters = array_merge($this->defaultFilters, Arr::get($defaultFilters, 'tax_query', []));
        if (!empty($this->defaultFilters)) {
            $this->defaultFilters['enabled'] = true;
        }

        if (Arr::get($this->customFilters, 'price_range', false)) {
            $this->filters['price_range'] = [
                "filter_type" => "range",
                "is_meta"     => false,
                "label"       => "Price",
                "enabled"     => true,
            ];
        }


        if (isset($this->customFilters['taxonomies'])) {
            // Convert string to array if needed
            $taxonomies = $this->customFilters['taxonomies'];
            if (!is_array($taxonomies)) {
                $taxonomies = array_map('trim', explode(',', $taxonomies));
            }

            foreach ($taxonomies as $key => $taxonomy) {
                if (is_array($taxonomy)) {
                    foreach ($taxonomy as $taxonomyKey => $tax) {
                        $this->filters[$taxonomyKey] = [
                            'enabled'     => true,
                            'filter_type' => 'options',
                            'is_meta'     => true,
                            'label'       => Arr::get($tax, 'label'),
                            'multiple'    => false,
                            'options'     => []
                        ];
                        foreach ($tax['options'] as $option) {
                            $this->filters[$taxonomyKey]['options'][] = [
                                'value'    => $option['term_id'],
                                'label'    => $option['name'],
                                'parent'   => $option['parent'],
                                'children' => []
                            ];
                        }
                    }

                } else {
                    $this->filters[$taxonomy] = [
                        "filter_type" => "options",
                        "is_meta"     => true,
                        "label"       => ucfirst(str_replace('-', ' ', $taxonomy)),
                        "enabled"     => true,
                        "multiple"    => false,
                    ];
                }
            }
        }


        // Example of $this->filters
//        $this->filters = [
//          "product-categories" => [
//            "filter_type" => "options",
//            "is_meta" => true,
//            "label" => "Product Categories",
//            "enabled" => true,
//            "multiple" => false
//          ],
//          "product-types" => [
//            "filter_type" => "options",
//            "is_meta" => true,
//            "label" => "Product Types",
//            "enabled" => true,
//            "multiple" => false
//          ]
//        ];

    }

    public function render()
    {
        AssetLoader::loadProductArchiveAssets();
        $isFullWidth = !$this->isFilterEnabled ? ' fct-full-container-width ' : '';
        $renderer = new \FluentCart\App\Services\Renderer\ProductFilterRender($this->filters);

        $wrapperAttributes = [
            'class'                                  => 'fct-products-wrapper-inner mode-' . $this->viewMode . $isFullWidth,
            'data-fluent-cart-product-wrapper-inner' => '',
            'data-per-page'                          => $this->per_page,
            'data-order-type'                        => $this->order_type,
            'data-live-filter'                       => $this->liveFilter,
            'data-paginator'                         => $this->paginator,
            'data-default-filters'                   => wp_json_encode($this->defaultFilters)
        ];
        ?>
        <div class="fct-products-wrapper" data-fluent-cart-shop-app data-fluent-cart-product-wrapper role="main" aria-label="<?php esc_attr_e('Products', 'fluent-cart'); ?>">
            <?php $this->renderViewSwitcher(); ?>
            <div <?php RenderHelper::renderAtts($wrapperAttributes); ?>>
                <?php $this->renderFilter($renderer); ?>

                <div class="fct-products-container grid-columns-<?php echo esc_attr($this->productBoxGridSize); ?>"
                     data-fluent-cart-shop-app-product-list
                     role="list"
                     aria-label="<?php esc_attr_e('Product list', 'fluent-cart'); ?>"
                >
                    <?php
                    if ($this->products->count() !== 0) {
                        $this->renderProduct();
                    } else {
                        ProductRenderer::renderNoProductFound();
                    }
                    ?>
                </div>
            </div>

            <?php
            if ($this->paginator === 'numbers') {
                $this->renderPaginator();
            }
            ?>

        </div>
        <?php
    }

    public function renderViewSwitcher()
    {

        ?>
        <div class="fct-shop-view-switcher-wrap">
            <?php $this->renderViewSwitcherButton(); ?>
            <?php
                if ($this->isFilterEnabled) {
                    $this->renderSortByFilter();
                }
            ?>
        </div>
        <?php
    }

    public function renderViewSwitcherButton()
    {
        ?>
        <div class="fct-shop-view-switcher">
            <button type="button" data-fluent-cart-shop-app-grid-view-button=""
                    class="<?php echo $this->viewMode === 'grid' ? 'active' : ''; ?>"
                    title="<?php echo esc_attr__('Grid View', 'fluent-cart'); ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 14 14" fill="none">
                    <path
                            d="M12.4059 1.59412C13.3334 2.52162 13.3334 4.0144 13.3334 6.99996C13.3334 9.98552 13.3334 11.4783 12.4059 12.4058C11.4784 13.3333 9.98564 13.3333 7.00008 13.3333C4.01452 13.3333 2.52174 13.3333 1.59424 12.4058C0.666748 11.4783 0.666748 9.98552 0.666748 6.99996C0.666748 4.0144 0.666748 2.52162 1.59424 1.59412C2.52174 0.666626 4.01452 0.666626 7.00008 0.666626C9.98564 0.666626 11.4784 0.666626 12.4059 1.59412Z"
                            stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"></path>
                    <path d="M13.3335 7L0.66683 7" stroke="currentColor" stroke-linecap="round"></path>
                    <path d="M7 0.666626L7 13.3333" stroke="currentColor" stroke-linecap="round"></path>
                </svg>
            </button>
            <button type="button" data-fluent-cart-shop-app-list-view-button=""
                    class="<?php echo $this->viewMode === 'list' ? 'active' : ''; ?>"
                    title="<?php echo esc_attr__('List View', 'fluent-cart'); ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none">
                    <path
                            d="M1.33325 7.60008C1.33325 6.8279 1.49441 6.66675 2.26659 6.66675H13.7333C14.5054 6.66675 14.6666 6.8279 14.6666 7.60008V8.40008C14.6666 9.17226 14.5054 9.33341 13.7333 9.33341H2.26659C1.49441 9.33341 1.33325 9.17226 1.33325 8.40008V7.60008Z"
                            stroke="currentColor" stroke-linecap="round"></path>
                    <path
                            d="M1.33325 2.26671C1.33325 1.49453 1.49441 1.33337 2.26659 1.33337H13.7333C14.5054 1.33337 14.6666 1.49453 14.6666 2.26671V3.06671C14.6666 3.83889 14.5054 4.00004 13.7333 4.00004H2.26659C1.49441 4.00004 1.33325 3.83888 1.33325 3.06671V2.26671Z"
                            stroke="currentColor" stroke-linecap="round"></path>
                    <path
                            d="M1.33325 12.9333C1.33325 12.1612 1.49441 12 2.26659 12H13.7333C14.5054 12 14.6666 12.1612 14.6666 12.9333V13.7333C14.6666 14.5055 14.5054 14.6667 13.7333 14.6667H2.26659C1.49441 14.6667 1.33325 14.5055 1.33325 13.7333V12.9333Z"
                            stroke="currentColor" stroke-linecap="round"></path>
                </svg>
            </button>
        </div>

        <?php if ($this->isFilterEnabled) { ?>
        <button type="button"
                data-fluent-cart-shop-app-filter-toggle-button=""
                class="fct-shop-filter-toggle-button hide" title="Toggle List">
            <span><?php echo esc_html__('Filter', 'fluent-cart'); ?></span>
            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path
                        d="M2.5 4C1.67157 4 1 3.32843 1 2.5C1 1.67157 1.67157 1 2.5 1C3.32843 1 4 1.67157 4 2.5C4 3.32843 3.32843 4 2.5 4Z"
                        stroke="#2F3448" stroke-width="1.2"></path>
                <path
                        d="M9.5 11C10.3284 11 11 10.3284 11 9.5C11 8.67157 10.3284 8 9.5 8C8.67157 8 8 8.67157 8 9.5C8 10.3284 8.67157 11 9.5 11Z"
                        stroke="#2F3448" stroke-width="1.2"></path>
                <path d="M4 2.5L11 2.5" stroke="#2F3448" stroke-width="1.2" stroke-linecap="round"></path>
                <path d="M8 9.5L1 9.5" stroke="#2F3448" stroke-width="1.2" stroke-linecap="round"></path>
            </svg>
        </button>
    <?php } ?>
        <?php
    }

    public function renderSortByFilter()
    {

        $currentSort = $this->order_by . '-' . $this->order_type;
        $dropdownId = 'fct-sort-dropdown-' . uniqid();
        ?>
        <div class="fct-shop-sorting-container">
            <button
                    class="fct-sorting-toggle"
                    data-sort-toggle
                    type="button"
                    aria-expanded="false"
                    aria-controls="<?php echo esc_attr($dropdownId); ?>"
                    aria-haspopup="true">
                <span class="fct-sorting-label"><?php echo esc_html__('Sort By', 'fluent-cart'); ?></span>

                <svg class="fct-sorting-arrow" xmlns="http://www.w3.org/2000/svg" width="14" height="8" viewBox="0 0 14 8" fill="none" aria-hidden="true" focusable="false">
                    <path d="M1.5 1.25L6.29289 6.04289C6.62623 6.37623 6.79289 6.54289 7 6.54289C7.20711 6.54289 7.37377 6.37623 7.70711 6.04289L12.5 1.25" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>

            <div
                    class="fct-shop-sorting-dropdown"
                    data-sort-dropdown
                    id="<?php echo esc_attr($dropdownId); ?>"
                    role="menu"
                    aria-label="<?php echo esc_attr__('Sort products', 'fluent-cart'); ?>">
                <fieldset class="fct-shop-sorting">
                    <legend class="screen-reader-text">
                        <?php echo esc_html__('Sort products by', 'fluent-cart'); ?>
                    </legend>

                    <div class="fct-shop-sorting-item" data-sort-item role="none">
                        <label>
                            <input
                                    type="radio"
                                    name="sort_by"
                                    value="name-asc"
                                <?php checked($currentSort, 'name-asc'); ?>
                                    aria-label="<?php echo esc_attr__('Sort alphabetically A to Z', 'fluent-cart'); ?>">
                            <span class="fct-sorting-radio" aria-hidden="true"></span>
                            <span class="fct-sorting-radio-label">
                            <?php echo esc_html__('Alphabetical (A to Z)', 'fluent-cart'); ?>
                        </span>
                        </label>
                    </div>

                    <div class="fct-shop-sorting-item" data-sort-item role="none">
                        <label>
                            <input
                                    type="radio"
                                    name="sort_by"
                                    value="name-desc"
                                <?php checked($currentSort, 'name-desc'); ?>
                                    aria-label="<?php echo esc_attr__('Sort alphabetically Z to A', 'fluent-cart'); ?>">
                            <span class="fct-sorting-radio" aria-hidden="true"></span>
                            <span class="fct-sorting-radio-label">
                            <?php echo esc_html__('Alphabetical (Z to A)', 'fluent-cart'); ?>
                        </span>
                        </label>
                    </div>

                    <div class="fct-shop-sorting-item" data-sort-item role="none">
                        <label>
                            <input
                                    type="radio"
                                    name="sort_by"
                                    value="price-low"
                                <?php checked($currentSort, 'price-low'); ?>
                                    aria-label="<?php echo esc_attr__('Sort by price low to high', 'fluent-cart'); ?>">
                            <span class="fct-sorting-radio" aria-hidden="true"></span>
                            <span class="fct-sorting-radio-label">
                            <?php echo esc_html__('Price (Low to High)', 'fluent-cart'); ?>
                        </span>
                        </label>
                    </div>

                    <div class="fct-shop-sorting-item" data-sort-item role="none">
                        <label>
                            <input
                                    type="radio"
                                    name="sort_by"
                                    value="price-high"
                                <?php checked($currentSort, 'price-high'); ?>
                                    aria-label="<?php echo esc_attr__('Sort by price high to low', 'fluent-cart'); ?>">
                            <span class="fct-sorting-radio" aria-hidden="true"></span>
                            <span class="fct-sorting-radio-label">
                            <?php echo esc_html__('Price (High to Low)', 'fluent-cart'); ?>
                        </span>
                        </label>
                    </div>

                    <div class="fct-shop-sorting-item" data-sort-item role="none">
                        <label>
                            <input
                                    type="radio"
                                    name="sort_by"
                                    value="date-newest"
                                <?php checked($currentSort, 'date-newest'); ?>
                                    aria-label="<?php echo esc_attr__('Sort by date newest first', 'fluent-cart'); ?>">
                            <span class="fct-sorting-radio" aria-hidden="true"></span>
                            <span class="fct-sorting-radio-label">
                            <?php echo esc_html__('Date (Newest First)', 'fluent-cart'); ?>
                        </span>
                        </label>
                    </div>

                    <div class="fct-shop-sorting-item" data-sort-item role="none">
                        <label>
                            <input
                                    type="radio"
                                    name="sort_by"
                                    value="date-oldest"
                                <?php checked($currentSort, 'date-oldest'); ?>
                                    aria-label="<?php echo esc_attr__('Sort by date oldest first', 'fluent-cart'); ?>">
                            <span class="fct-sorting-radio" aria-hidden="true"></span>
                            <span class="fct-sorting-radio-label">
                            <?php echo esc_html__('Date (Oldest First)', 'fluent-cart'); ?>
                        </span>
                        </label>
                    </div>
                </fieldset>
            </div>
        </div>
        <?php
    }

    public function renderFilter($renderer)
    {
        if (!$this->isFilterEnabled || !$renderer) {
            return;
        }
        ?>
        <?php if ($this->isFilterEnabled) : ?>
            <div class="fct-shop-filter-wrapper fluent-cart-shop-app-filter-wrapper" data-fluent-cart-shop-app-filter-wrapper role="search" aria-label="<?php esc_attr_e('Product filters', 'fluent-cart'); ?>">
                <div class="fluent-cart-shop-app-filter-wrapper-inner">

                    <form class="fct-shop-filter-form" data-fluent-cart-product-filter-form role="search"
                          aria-label="<?php esc_attr_e('Product filter form', 'fluent-cart'); ?>">
                        <?php $renderer->renderSearch(); ?>
                        <?php $renderer->renderOptions(); ?>
                        <?php if (!$this->liveFilter) : ?>
                        <div class="fct-shop-filter-item">
                            <div class="fct-shop-button-group">
                                <button class="fct-shop-apply-filter-button wp-block-fluent-cart-shopapp-product-filter-apply-button">
                                    <?php esc_html_e('Apply Filter', 'fluent-cart'); ?>
                                </button>
                                <button class="fct-shop-reset-filter-button wp-block-fluent-cart-shopapp-product-filter-reset-button"
                                        data-fluent-cart-shop-app-reset-button="">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none">
                                        <path d="M14.2501 9.75V15.75M9.75012 9.75V15.75M20.2498 5.25L3.74976 5.25001M18.7501 5.25V19.5C18.7501 19.6989 18.6711 19.8897 18.5305 20.0303C18.3898 20.171 18.199 20.25 18.0001 20.25H6.00012C5.80121 20.25 5.61044 20.171 5.46979 20.0303C5.32914 19.8897 5.25012 19.6989 5.25012 19.5V5.25M15.7501 5.25V3.75C15.7501 3.35218 15.5921 2.97064 15.3108 2.68934C15.0295 2.40804 14.6479 2.25 14.2501 2.25H9.75012C9.3523 2.25 8.97077 2.40804 8.68946 2.68934C8.40816 2.97064 8.25012 3.35218 8.25012 3.75V5.25"
                                              stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                              stroke-linejoin="round"></path>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        <?php endif; ?>
        <?php
    }


    public function renderProduct()
    {
        $products = $this->products;

        $cursor = '';
        if ($products instanceof CursorPaginator) {
            $cursor = wp_parse_args(wp_parse_url($products->nextPageUrl(), PHP_URL_QUERY));
        }
        ?>
        <?php foreach ($products as $index => $product) {
        $cursorAttr = '';
        if ($index === 0) {
            $cursorAttr = Arr::get($cursor, 'cursor', '');
        }

        (new \FluentCart\App\Services\Renderer\ProductCardRender($product, ['cursor' => $cursorAttr]))->render();
        ?>
    <?php } ?>
        <?php
    }

    public function renderTitle($product = null)
    {
        if (!$product || !$product === null) {
            return '';
        }

        $render = new \FluentCart\App\Services\Renderer\ProductCardRender($product);
        ob_start();
        $render->renderTitle('class="fct-product-card-title"');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo ob_get_clean();
    }

    public function renderImage($product = null)
    {
        if (!$product || !$product === null) {
            return '';
        }

        $render = new \FluentCart\App\Services\Renderer\ProductCardRender($product);
        ob_start();
        $render->renderProductImage();
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo ob_get_clean();
    }

    public function renderPrice($product = null)
    {
        if (!$product || !$product === null) {
            return '';
        }

        $render = new \FluentCart\App\Services\Renderer\ProductCardRender($product);
        ob_start();
        $render->renderPrices('class="fct-product-card-prices"');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo ob_get_clean();
    }

    public function renderButton($product = null)
    {
        if (!$product || !$product === null) {
            return '';
        }

        $render = new \FluentCart\App\Services\Renderer\ProductCardRender($product);
        ob_start();
        $render->showBuyButton();
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo ob_get_clean();
    }

    private function getInitialProducts()
    {
        $params = $this->config;

        $products = ShopResource::get($params);

        return ($products['products']->setCollection(
            $products['products']->getCollection()->transform(function ($product) {
                $product->setAppends(['view_url', 'has_subscription']);
                return $product;
            })
        ));
    }

    public function renderPaginator()
    {
        $total = $this->total;
        $lastPage = max((int)ceil($total / $this->per_page), 1);
        $currentPage = $this->products->currentPage();
        $from = ($currentPage - 1) * $this->per_page + 1;
        $to = min($total, $currentPage * $this->per_page);
        $perPage = $this->products->perPage();
        ?>
        <div class="fct-shop-paginator">
            <?php $this->renderPaginatorResultWrapper(); ?>

            <?php $this->renderPerPageSelector(); ?>

        </div>
        <?php
    }

    public function renderPaginatorResultWrapper($atts = '')
    {
        $total = $this->total;

        $lastPage = max((int)ceil($total / $this->per_page), 1);
        $currentPage = $this->products->currentPage();
        $from = ($currentPage - 1) * $this->per_page + 1;
        $to = min($total, $currentPage * $this->per_page);
        $perPage = $this->products->perPage();
        ?>
        <div class="fct-shop-paginator-result-wrapper" aria-label="<?php echo esc_attr__('Pagination information', 'fluent-cart'); ?>">
            <div
                <?php echo !empty($atts) ? $atts : 'class="fct-shop-paginator-results wc-block-grid__fluent-cart-shop-app-paginator-results wp-block-fluent-cart-product-paginator-info"'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    role="status"
                    aria-live="polite"
                    aria-atomic="true">
                <?php
                printf(
                /* translators: 1: starting item number, 2: ending item number, 3: total number of items */
                    esc_html__('Showing %1$s to %2$s of %3$s Items', 'fluent-cart'),
                    '<span class="fct-shop-paginator-from" data-fluent-cart-shop-app-paginator-info-pagination-from="">' . esc_html($from) . '</span>',
                    '<span class="fct-shop-paginator-to" data-fluent-cart-shop-app-paginator-info-pagination-to="">' . esc_html($to) . '</span>',
                    '<span class="fct-shop-paginator-total" data-fluent-cart-shop-app-paginator-info-pagination-total="">' . esc_html($total) . '</span>'
                );
                ?>
            </div>

            <div class="fct-shop-per-page-selector">
                <label for="fct-per-page-select" class="screen-reader-text">
                    <?php echo esc_html__('Items per page', 'fluent-cart'); ?>
                </label>
                <select
                        id="fct-per-page-select"
                        name="per_page"
                        data-fluent-cart-shop-app-paginator-per-page-selector=""
                        aria-label="<?php echo esc_attr__('Select number of items per page', 'fluent-cart'); ?>">
                    <option value="10" <?php selected($perPage, 10); ?>>
                        <?php echo esc_html__('10 Per page', 'fluent-cart'); ?>
                    </option>
                    <option value="20" <?php selected($perPage, 20); ?>>
                        <?php echo esc_html__('20 Per page', 'fluent-cart'); ?>
                    </option>
                    <option value="30" <?php selected($perPage, 30); ?>>
                        <?php echo esc_html__('30 Per page', 'fluent-cart'); ?>
                    </option>
                </select>
            </div>
        </div>
        <?php
    }

    public function renderPerPageSelector()
    {
        $total = $this->total;
        $lastPage = max((int)ceil($total / $this->per_page), 1);
        $currentPage = $this->products->currentPage();
        $from = ($currentPage - 1) * $this->per_page + 1;
        $to = min($total, $currentPage * $this->per_page);
        $perPage = $this->products->perPage();

        if ($lastPage > 1) : ?>
        <ul class="fct-shop-paginator-pager"
            data-fluent-cart-shop-app-paginator-items-wrapper="">
            <?php for ($page = 1; $page <= $lastPage; $page++):
                $isCurrent = $page == $currentPage;
                $classes = $isCurrent ? 'active' : '';
                ?>
                <li class="pager-number <?php echo $page == $currentPage ? 'active' : ''; ?>">
                    <button
                       class="<?php echo esc_attr($classes); ?>"
                       data-fluent-cart-shop-app-paginator-item
                       data-page="<?php echo esc_attr($page); ?>"
                       <?php if ($isCurrent) : ?>aria-current="page"<?php endif; ?>
                    >
                        <?php echo esc_html($page); ?>
                    </button>
                </li>
            <?php endfor; ?>
        </ul>
        <?php
        endif;
    }

}

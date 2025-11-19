<?php

namespace FluentCart\App\Modules\Templating\Bricks\Elements;

use Bricks\Custom_Render_Element;
use Bricks\Helpers;
use Bricks\Query;
use FluentCart\App\Modules\Data\ProductDataSetup;
use FluentCart\App\Modules\Data\ProductQuery;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Modules\Templating\Bricks\BricksHelper;
use FluentCart\App\Services\Renderer\RenderHelper;
use FluentCart\App\Services\Renderer\ShopAppRenderer;
use FluentCart\Framework\Support\Arr;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class ProductsCollection extends Custom_Render_Element
{
    public $category = 'fluentcart';
    public $name = 'fct-products';
    public $icon = 'ti-archive';

    protected $cssRoot = '.fct-products-wrapper-inner .fct-products-container';

    public function enqueue_scripts()
    {
        AssetLoader::loadProductArchiveAssets();
    }

    public function get_label()
    {
        return esc_html__('Products', 'fluent-cart');
    }

    public function set_control_groups()
    {
        $this->control_groups['query'] = [
            'title' => esc_html__('Query', 'fluent-cart'),
            'tab'   => 'content',
        ];

        $this->control_groups['fields'] = [
            'title' => esc_html__('Fields', 'fluent-cart'),
            'tab'   => 'content',
        ];

        $this->control_groups['widgets'] = [
            'title' => esc_html__('Widgets', 'fluent-cart'),
            'tab'   => 'widgets',
        ];
    }

    public function set_controls()
    {
        // LAYOUT
        $this->controls['columns'] = [
            'tab'         => 'content',
            'label'       => esc_html__('Columns', 'fluent-cart'),
            'type'        => 'number',
            'min'         => 1,
            'max'         => 5,
            'breakpoints' => true,
            'placeholder' => 4,
            'rerender'    => true,
        ];

        $this->controls['gap'] = [
            'tab'         => 'content',
            'label'       => esc_html__('Gap', 'fluent-cart'),
            'type'        => 'number',
            'units'       => true,
            'css'         => [
                [
                    'selector' => $this->cssRoot,
                    'property' => 'gap',
                ],
            ],
            'placeholder' => 30,
        ];

        $this->controls['posts_per_page'] = [
            'tab'   => 'content',
            'label' => esc_html__('Products per page', 'fluent-cart'),
            'type'  => 'number',
            'min'   => -1,
            'step'  => 1,
        ];

        $this->controls['is_main_query'] = [
            'tab'    => 'content',
            'label'  => esc_html__('Is main query', 'fluent-cart'),
            'type'   => 'checkbox',
            'inline' => true,
        ];

        // QUERY
        $this->controls['orderby'] = [
            'tab'         => 'content',
            'label'       => esc_html__('Order by', 'fluent-cart'),
            'type'        => 'select',
            // id|date|title|price
            'options'     => [
                'price' => esc_html__('Price', 'fluent-cart'),
                'title' => esc_html__('Product Name', 'fluent-cart'),
                'date'  => esc_html__('Published date', 'fluent-cart'),
                'id'    => esc_html__('Product ID', 'fluent-cart')
            ],
            'inline'      => true,
            'placeholder' => esc_html__('Default', 'fluent-cart'),
        ];

        $this->controls['order'] = [
            'tab'         => 'content',
            'label'       => esc_html__('Order', 'fluent-cart'),
            'type'        => 'select',
            'options'     => [
                'ASC'  => esc_html__('Ascending', 'fluent-cart'),
                'DESC' => esc_html__('Descending', 'fluent-cart'),
            ],
            'inline'      => true,
            'placeholder' => esc_html__('Descending', 'fluent-cart'),
        ];

        $this->controls['main_query_info'] = [
            'tab'     => 'content',
            'group'   => 'query',
            'type'    => 'info',
            'content' => esc_html__('The query settings will be ignored when Is main query is enabled.', 'fluent-cart'),
        ];

        $this->controls['productType'] = [
            'tab'         => 'content',
            'group'       => 'query',
            'label'       => esc_html__('Product type', 'fluent-cart'),
            'type'        => 'select',
            // physical|digital|subscription|onetime|simple|variations
            'options'     => [
                'simple'       => esc_html__('Simple', 'fluent-cart'),
                'physical'     => esc_html__('Physical', 'fluent-cart'),
                'digital'      => esc_html__('Digital', 'fluent-cart'),
                'variations'   => esc_html__('Variations', 'fluent-cart'),
                'ontime'       => esc_html__('One-time', 'fluent-cart'),
                'subscription' => esc_html__('Subscriptions', 'fluent-cart'),
            ],
            'multiple'    => false,
            'placeholder' => esc_html__('all product types', 'fluent-cart'),
        ];

        $this->controls['include'] = [
            'tab'         => 'content',
            'group'       => 'query',
            'label'       => esc_html__('Include', 'fluent-cart'),
            'type'        => 'select',
            'optionsAjax' => [
                'action'   => 'bricks_get_posts',
                'postType' => 'fluent-products',
            ],
            'multiple'    => true,
            'searchable'  => true,
            'placeholder' => esc_html__('Select products', 'fluent-cart'),
        ];

        //phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
        $this->controls['exclude'] = [
            'tab'         => 'content',
            'group'       => 'query',
            'label'       => esc_html__('Exclude', 'fluent-cart'),
            'type'        => 'select',
            'optionsAjax' => [
                'action'   => 'bricks_get_posts',
                'postType' => 'fluent-products',
            ],
            'multiple'    => true,
            'searchable'  => true,
            'placeholder' => esc_html__('Select products', 'fluent-cart'),
        ];

        $this->controls['categories'] = [
            'tab'      => 'content',
            'group'    => 'query',
            'label'    => esc_html__('Product categories', 'fluent-cart'),
            'type'     => 'select',
            'options'  => BricksHelper::getCategoriesOptions(),
            'multiple' => true,
        ];

        $this->controls['onSale'] = [
            'tab'   => 'content',
            'group' => 'query',
            'label' => esc_html__('On sale Products only', 'fluent-cart'),
            'type'  => 'checkbox',
        ];

//        $this->controls['hideOutOfStock'] = [
//            'tab'   => 'content',
//            'group' => 'query',
//            'label' => esc_html__('Hide out of stock items', 'fluent-cart'),
//            'type'  => 'checkbox',
//        ];

        // FIELDS
        $fields = $this->get_post_fields();

        // Remove field settings
        unset($fields['fields']['fields']['overlay']);
        unset($fields['fields']['fields']['dynamicPadding']);
        unset($fields['fields']['fields']['dynamicBackground']);
        unset($fields['fields']['fields']['dynamicBorder']);

        // Set fields defaults fields set
        $fields['fields']['default'] = [
            [
                'dynamicData' => '{fct_product_image:link}',
                'id'          => Helpers::generate_random_id(false),
            ],
            [
                'dynamicData'   => '{post_title:link}',
                'tag'           => 'h5',
                'dynamicMargin' => [
                    'top'    => 15,
                    'bottom' => 5,
                ],
                'id'            => Helpers::generate_random_id(false),
            ],
            [
                'dynamicData' => '{fct_product_price}',
                'id'          => Helpers::generate_random_id(false),
            ],
            [
                'dynamicData' => '{fct_product_button}',
                'id'          => Helpers::generate_random_id(false),
            ]
        ];

        $this->controls = array_replace_recursive($this->controls, $fields);

        $this->controls['linkProduct'] = [
            'tab'         => 'content',
            'group'       => 'fields',
            'label'       => esc_html__('Link entire product', 'fluent-cart'),
            'type'        => 'checkbox',
            'inline'      => true,
            'description' => esc_html__('Only added if none of your product fields contains any links.', 'fluent-cart'),
        ];
    }

    public function render()
    {
        $settings = $this->settings;

        $this->setBricksQuery();

        $columns = (int)Arr::get($settings, 'columns', 4);
        if (!$columns) {
            $columns = 4;
        }

        if ($columns > 5) {
            $columns = 5;
        }

        $uuid = 'fc_bx_collection_' . $this->uid;
        if (!$this->is_frontend || !get_transient($uuid)) {
            // save the settings as transient
            set_transient($uuid, $settings, 48 * HOUR_IN_SECONDS);
        }

        $isMainQuery = Arr::get($settings, 'is_main_query', false) && $this->is_frontend;
        $args = array_filter([
            'paginate'      => 'simple',
            'is_main_query' => $isMainQuery,
            'sort_by'       => Arr::get($settings, 'orderby', 'date'),
            'sort_type'     => Arr::get($settings, 'order', 'desc'),
            'per_page'      => (int)Arr::get($settings, 'posts_per_page', 0),
        ]);

        if (!$isMainQuery) {
            $includeIds = Arr::get($settings, 'include', []);
            if ($includeIds) {
                $args['include_ids'] = $includeIds;
            }

            $excludeIds = Arr::get($settings, 'exclude', []);
            if ($excludeIds) {
                $args['exclude_ids'] = $excludeIds;
            }

            $productType = Arr::get($settings, 'productType', []);
            if ($productType) {
                $args['product_type'] = $productType;
            }

            $onSale = Arr::get($settings, 'onSale', false);

            if ($onSale) {
                $args['on_sale'] = true;
            }

            $hideOutOfStock = Arr::get($settings, 'hideOutOfStock', false);

            if ($hideOutOfStock) {
                $args['stock_status'] = 'in_stock';
            }

            $categories = Arr::get($settings, 'categories', []);
            if ($categories) {
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                $args['tax_query'] = [
                    'product-categories' => $categories,
                ];
            }
        }


        $productsQuery = (new ProductQuery($args));
        $products = $productsQuery->get();

        $defaultFilters = $productsQuery->getDefaultFilters();

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_attributes() handles escaping internally
        echo "<div {$this->render_attributes( '_root' )}>";

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_attributes() handles escaping internally
        echo "<div {$this->render_attributes( 'wrapper' )}>";

        $wrapperAttributes = [
            'class'                                  => 'fct-products-wrapper-inner mode-grid fct-full-container-width',
            'data-fluent-cart-product-wrapper-inner' => '',
            'data-per-page'                          => Arr::get($defaultFilters, 'per_page'),
            'data-order-type'                        => Arr::get($defaultFilters, 'sort_type'),
            'data-live-filter'                       => true,
            'data-paginator'                         => 'numbers',
            'data-default-filters'                   => wp_json_encode($defaultFilters)
        ];

        echo '<div class="fct-products-wrapper" data-fluent-cart-shop-app data-fluent-cart-product-wrapper>';
        ?>
    <div <?php RenderHelper::renderAtts($wrapperAttributes); ?>>
        <?php
        echo '<div data-fluent-cart-shop-app-product-list class="fct-products-container grid-columns-' . esc_attr($columns) . '">';
        // Default WooCommerce loop template

        ProductDataSetup::setProductsCache($products);
        $postIndex = 1;
        foreach ($products as $product) {
            $post = get_post($product->ID);
            setup_postdata($post);
            $this->set_loop_object($post);
            $this->render_fields($post, $postIndex);
            $this->next_iteration();
            $postIndex++;
        }
        wp_reset_postdata();

        $this->end_iteration();

        echo '</div>';

        echo '</div>';
        // Pagination print here.
        $renderer = new ShopAppRenderer($products, [
            'default_filters' => $defaultFilters,
            'pagination_type' => 'simple'
        ]);

        $renderer->renderPaginator();
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    private function setBricksQuery()
    {
        $query_object = new Query(
            [
                'id'       => $this->id,
                'name'     => $this->name,
                'settings' => $this->settings,
            ]
        );

        // Set $bricks_query (@since 1.10.2)
        $this->set_bricks_query($query_object);
        $this->start_iteration();
    }

    public function render_fields($post, $post_index)
    {
        BricksHelper::renderCollectionCard($this->settings, $post, $post_index, $this->uid);
    }

    public function renderAjaxContents($products, $settings)
    {

        $this->settings = $settings;

        $this->setBricksQuery();

        ProductDataSetup::setProductsCache($products);
        $postIndex = 1;
        foreach ($products as $product) {
            $post = get_post($product->ID);
            setup_postdata($post);
            $this->set_loop_object($post);
            $this->render_fields($post, $postIndex);
            $this->next_iteration();
            $postIndex++;
        }
        wp_reset_postdata();

        $this->end_iteration();
    }

}

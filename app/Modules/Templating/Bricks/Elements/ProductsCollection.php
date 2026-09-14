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
use FluentCart\Api\Taxonomy;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;
use FluentCart\App\Services\Renderer\ProductFilterRender;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class ProductsCollection extends Custom_Render_Element
{
    public $category = 'fluent-cart';
    public $name = 'fct-products';
    public $icon = 'ti-archive fluent-cart-element-icon';

    protected $cssRoot = '.fct-products-wrapper-inner .fct-products-container';

    public function enqueue_scripts()
    {
        AssetLoader::loadProductArchiveAssets();
        
        do_action('fluent_cart/advanced_variation/enqueue_assets');
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

        $this->control_groups['display'] = [
            'title' => esc_html__('Display', 'fluent-cart'),
            'tab'   => 'content',
        ];

        $this->control_groups['filter'] = [
            'title' => esc_html__('Filter', 'fluent-cart'),
            'tab'   => 'content',
        ];

        $this->control_groups['defaultFilter'] = [
            'title' => esc_html__('Default Filter', 'fluent-cart'),
            'tab'   => 'content',
        ];

        $this->control_groups['widgets'] = [
            'title' => esc_html__('Widgets', 'fluent-cart'),
            'tab'   => 'widgets',
        ];

        $this->control_groups['sale_badge'] = [
            'title' => esc_html__('Sale Badge', 'fluent-cart'),
            'tab'   => 'content',
        ];

        $this->control_groups['sale_badge_style'] = [
            'title' => esc_html__('Sale Badge', 'fluent-cart'),
            'tab'   => 'style',
        ];

        $this->control_groups['sold_out_badge'] = [
            'title' => esc_html__('Sold Out Badge', 'fluent-cart'),
            'tab'   => 'content',
        ];

        $this->control_groups['sold_out_badge_style'] = [
            'title' => esc_html__('Sold Out Badge', 'fluent-cart'),
            'tab'   => 'style',
        ];
    }

    public function set_controls()
    {
        // LAYOUT
        $this->controls['viewMode'] = [
            'tab'         => 'content',
            'group'       => 'display',
            'label'       => esc_html__('View Mode', 'fluent-cart'),
            'type'        => 'select',
            'options'     => [
                'grid' => esc_html__('Grid', 'fluent-cart'),
                'list' => esc_html__('List', 'fluent-cart'),
            ],
            'placeholder' => esc_html__('Grid', 'fluent-cart'),
            'rerender'    => true,
        ];

        $this->controls['showViewSwitcher'] = [
            'tab'     => 'content',
            'group'   => 'display',
            'label'   => esc_html__('Show View Switcher', 'fluent-cart'),
            'type'    => 'checkbox',
            'inline'  => true,
            'default' => true,
        ];

        $this->controls['paginationType'] = [
            'tab'         => 'content',
            'group'       => 'display',
            'label'       => esc_html__('Pagination Type', 'fluent-cart'),
            'type'        => 'select',
            'options'     => [
                'scroll'  => esc_html__('Scroll', 'fluent-cart'),
                'numbers' => esc_html__('Numbers', 'fluent-cart'),
            ],
            'placeholder' => esc_html__('Numbers', 'fluent-cart'),
        ];

        $this->controls['columns'] = [
            'tab'         => 'content',
            'label'       => esc_html__('Columns', 'fluent-cart'),
            'type'        => 'number',
            'min'         => 1,
            'max'         => 5,
            'breakpoints' => true,
            'placeholder' => 4,
            'css'         => [
                [
                    'selector' => $this->cssRoot,
                    'property' => '--grid-columns',
                ],
            ],
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
            'min'   => 1,
            'max'   => 100,
            'placeholder' => 10,
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

        $this->controls['allowOutOfStock'] = [
            'tab'     => 'content',
            'group'   => 'query',
            'label'   => esc_html__('Allow Out Of Stock', 'fluent-cart'),
            'type'    => 'checkbox',
        ];

        // FILTER
        $this->controls['enableFilter'] = [
            'tab'     => 'content',
            'group'   => 'filter',
            'label'   => esc_html__('Enable Filter', 'fluent-cart'),
            'type'    => 'checkbox',
            'inline'  => true,
        ];

        $this->controls['enableSortBy'] = [
            'tab'      => 'content',
            'group'    => 'filter',
            'label'    => esc_html__('Enable Sort By', 'fluent-cart'),
            'type'     => 'checkbox',
            'inline'   => true,
            'default'  => true,
            'required' => ['enableFilter', '=', true],
        ];

        $this->controls['liveFilter'] = [
            'tab'      => 'content',
            'group'    => 'filter',
            'label'    => esc_html__('Live Filter', 'fluent-cart'),
            'type'     => 'checkbox',
            'inline'   => true,
            'required' => ['enableFilter', '=', true],
        ];

        $this->controls['wildcardFilter'] = [
            'tab'      => 'content',
            'group'    => 'filter',
            'label'    => esc_html__('Wildcard Filter', 'fluent-cart'),
            'type'     => 'checkbox',
            'inline'   => true,
            'required' => ['enableFilter', '=', true],
        ];

        $taxonomies = Taxonomy::getTaxonomies();
        foreach ($taxonomies as $taxonomy) {
            $controlKey = sanitize_key(str_replace('-', '_', $taxonomy));
            $label = esc_html(Str::headline($taxonomy));
            $taxonomyName = esc_html(str_replace('product-', '', $taxonomy));

            $this->controls['taxonomy_' . $controlKey] = [
                'tab'      => 'content',
                'group'    => 'filter',
                'label'    => $label,
                'type'     => 'checkbox',
                'inline'   => true,
                'required' => ['enableFilter', '=', true],
            ];

            $this->controls['displayName_' . $controlKey] = [
                'tab'         => 'content',
                'group'       => 'filter',
                'label'       => esc_html__('Display Name', 'fluent-cart'),
                'type'        => 'text',
                'placeholder' => esc_html__('Custom filter label', 'fluent-cart'),
                'required'    => [
                    ['enableFilter', '=', true],
                    ['taxonomy_' . $controlKey, '=', true],
                ],
            ];

            /* translators: %1$s: taxonomy name (e.g. "categories", "brands") */
            $this->controls['showEmpty_' . $controlKey] = [
                'tab'         => 'content',
                'group'       => 'filter',
                'label'       => esc_html__('Show empty', 'fluent-cart'),
                'type'        => 'checkbox',
                'inline'      => true,
                'description' => sprintf(esc_html__('Display %1$s even if they have no products.', 'fluent-cart'), $taxonomyName),
                'required'    => [
                    ['enableFilter', '=', true],
                    ['taxonomy_' . $controlKey, '=', true],
                ],
            ];
        }

        $this->controls['priceRange'] = [
            'tab'      => 'content',
            'group'    => 'filter',
            'label'    => esc_html__( 'Price Range', 'fluent-cart' ),
            'type'     => 'checkbox',
            'inline'   => true,
            'required' => ['enableFilter', '=', true],
        ];

        $this->controls['displayNamePriceRange'] = [
            'tab'      => 'content',
            'group'    => 'filter',
            'label'    => esc_html__('Display Name', 'fluent-cart'),
            'type'     => 'text',
            'placeholder' => esc_html__('Custom filter label', 'fluent-cart'),
            'required' => [
                ['enableFilter', '=', true],
                ['priceRange', '=', true],
            ],
        ];

        // DEFAULT FILTER
        $this->controls['defaultFilterEnabled'] = [
            'tab'         => 'content',
            'group'       => 'defaultFilter',
            'label'       => esc_html__('Enable Default Filter', 'fluent-cart'),
            'type'        => 'checkbox',
            'inline'      => true,
            'description' => esc_html__('Presets applied to the product query by default, independent of the visitor-facing filter above. Ignored when Is main query is enabled.', 'fluent-cart'),
        ];

        $this->controls['defaultFilterAllowOutOfStock'] = [
            'tab'      => 'content',
            'group'    => 'defaultFilter',
            'label'    => esc_html__('Allow Out Of Stock', 'fluent-cart'),
            'type'     => 'checkbox',
            'inline'   => true,
            'required' => ['defaultFilterEnabled', '=', true],
        ];

        $this->controls['defaultFilterWildcard'] = [
            'tab'         => 'content',
            'group'       => 'defaultFilter',
            'label'       => esc_html__('Search', 'fluent-cart'),
            'type'        => 'text',
            'placeholder' => esc_html__('Preset search term', 'fluent-cart'),
            'required'    => ['defaultFilterEnabled', '=', true],
        ];

        foreach ($taxonomies as $taxonomy) {
            $controlKey = sanitize_key(str_replace('-', '_', $taxonomy));
            $label = esc_html(Str::headline($taxonomy));

            // Bricks' built-in terms AJAX endpoint (bounded to 100 results per
            // request, server-side searchable) — avoids eagerly loading and
            // flattening every term of every taxonomy on control registration.
            $this->controls['defaultFilterTaxonomy_' . $controlKey] = [
                'tab'         => 'content',
                'group'       => 'defaultFilter',
                'label'       => $label,
                'type'        => 'select',
                'multiple'    => true,
                'searchable'  => true,
                'optionsAjax' => [
                    'action'    => 'bricks_get_terms_options',
                    'postTypes' => ['fluent-products'],
                    'taxonomy'  => [$taxonomy],
                ],
                'placeholder' => esc_html__('Select terms', 'fluent-cart'),
                'required'    => ['defaultFilterEnabled', '=', true],
            ];
        }

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
                'dynamicData'   => '{fct_product_title:linked}',
                'tag'           => 'h5',
                'id'            => Helpers::generate_random_id(false),
            ],
            [
                'dynamicData' => '{fct_product_excerpt}',
                'tag'         => 'p',
                'id'          => Helpers::generate_random_id(false),
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

        // SALE BADGE
        $this->controls['showSaleBadge'] = [
            'tab'      => 'content',
            'group'    => 'sale_badge',
            'type'     => 'checkbox',
            'label'    => esc_html__('Show Sale Badge', 'fluent-cart'),
            'rerender' => true,
        ];

        $this->controls['saleBadgeText'] = [
            'tab'         => 'content',
            'group'       => 'sale_badge',
            'type'        => 'text',
            'label'       => esc_html__('Badge Text', 'fluent-cart'),
            'placeholder' => esc_html__('Sale!', 'fluent-cart'),
            'default'     => esc_html__('Sale!', 'fluent-cart'),
            'required'    => ['showSaleBadge', '=', true],
        ];

        $this->controls['saleBadgeShowPercentage'] = [
            'tab'      => 'content',
            'group'    => 'sale_badge',
            'type'     => 'checkbox',
            'label'    => esc_html__('Show discount percentage instead', 'fluent-cart'),
            'rerender' => true,
            'required' => ['showSaleBadge', '=', true],
        ];

        $this->controls['saleBadgePercentageText'] = [
            'tab'         => 'content',
            'group'       => 'sale_badge',
            'type'        => 'text',
            'label'       => esc_html__('Percentage Text', 'fluent-cart'),
            'description' => esc_html__('Use {percent} as a placeholder for the discount amount, e.g. -{percent}%', 'fluent-cart'),
            'placeholder' => '-{percent}%',
            'default'     => '-{percent}%',
            'required'    => [['showSaleBadge', '=', true], ['saleBadgeShowPercentage', '=', true]],
        ];

        $this->controls['saleBadgePriceSource'] = [
            'tab'      => 'content',
            'group'    => 'sale_badge',
            'type'     => 'select',
            'label'    => esc_html__('Price Source', 'fluent-cart'),
            'options'  => [
                'default_variant' => esc_html__('Default Variant', 'fluent-cart'),
                'best_discount'   => esc_html__('Best Discount (All Variants)', 'fluent-cart'),
            ],
            'default'  => 'default_variant',
            'inline'   => true,
            'required' => ['showSaleBadge', '=', true],
        ];

        $this->controls['saleBadgeShape'] = [
            'tab'      => 'content',
            'group'    => 'sale_badge',
            'type'     => 'select',
            'label'    => esc_html__('Badge Shape', 'fluent-cart'),
            'options'  => [
                'badge'  => esc_html__('Badge', 'fluent-cart'),
                'ribbon' => esc_html__('Ribbon', 'fluent-cart'),
            ],
            'default'  => 'badge',
            'inline'   => true,
            'required' => ['showSaleBadge', '=', true],
        ];

        $this->controls['saleBadgePosition'] = [
            'tab'      => 'content',
            'group'    => 'sale_badge',
            'type'     => 'select',
            'label'    => esc_html__('Position', 'fluent-cart'),
            'options'  => [
                'top-left'     => esc_html__('Top Left', 'fluent-cart'),
                'top-right'    => esc_html__('Top Right', 'fluent-cart'),
                'bottom-left'  => esc_html__('Bottom Left', 'fluent-cart'),
                'bottom-right' => esc_html__('Bottom Right', 'fluent-cart'),
            ],
            'default'  => 'top-left',
            'inline'   => true,
            'required' => ['showSaleBadge', '=', true],
        ];

        $this->controls['saleBadgeTypography'] = [
            'tab'      => 'style',
            'group'    => 'sale_badge_style',
            'type'     => 'typography',
            'label'    => esc_html__('Typography', 'fluent-cart'),
            'css'      => [
                ['property' => 'font', 'selector' => '.fct-sale-badge'],
            ],
            'required' => ['showSaleBadge', '=', true],
        ];

        $this->controls['saleBadgeBackgroundColor'] = [
            'tab'      => 'style',
            'group'    => 'sale_badge_style',
            'type'     => 'color',
            'label'    => esc_html__('Background Color', 'fluent-cart'),
            'css'      => [
                ['property' => 'background-color', 'selector' => '.fct-sale-badge'],
            ],
            'required' => ['showSaleBadge', '=', true],
        ];

        $this->controls['saleBadgeTextColor'] = [
            'tab'      => 'style',
            'group'    => 'sale_badge_style',
            'type'     => 'color',
            'label'    => esc_html__('Text Color', 'fluent-cart'),
            'css'      => [
                ['property' => 'color', 'selector' => '.fct-sale-badge'],
            ],
            'required' => ['showSaleBadge', '=', true],
        ];

        // SOLD OUT BADGE
        $this->controls['showSoldOutBadge'] = [
            'tab'      => 'content',
            'group'    => 'sold_out_badge',
            'type'     => 'checkbox',
            'label'    => esc_html__('Show Sold Out Badge', 'fluent-cart'),
            'rerender' => true,
        ];

        $this->controls['soldOutBadgeText'] = [
            'tab'         => 'content',
            'group'       => 'sold_out_badge',
            'type'        => 'text',
            'label'       => esc_html__('Badge Text', 'fluent-cart'),
            'placeholder' => esc_html__('Out of Stock', 'fluent-cart'),
            'default'     => esc_html__('Out of Stock', 'fluent-cart'),
            'required'    => ['showSoldOutBadge', '=', true],
        ];

        $this->controls['soldOutBadgeShape'] = [
            'tab'      => 'content',
            'group'    => 'sold_out_badge',
            'type'     => 'select',
            'label'    => esc_html__('Badge Shape', 'fluent-cart'),
            'options'  => [
                'badge'  => esc_html__('Badge', 'fluent-cart'),
                'ribbon' => esc_html__('Ribbon', 'fluent-cart'),
            ],
            'default'  => 'badge',
            'inline'   => true,
            'required' => ['showSoldOutBadge', '=', true],
        ];

        $this->controls['soldOutBadgePosition'] = [
            'tab'      => 'content',
            'group'    => 'sold_out_badge',
            'type'     => 'select',
            'label'    => esc_html__('Position', 'fluent-cart'),
            'options'  => [
                'top-left'     => esc_html__('Top Left', 'fluent-cart'),
                'top-right'    => esc_html__('Top Right', 'fluent-cart'),
                'bottom-left'  => esc_html__('Bottom Left', 'fluent-cart'),
                'bottom-right' => esc_html__('Bottom Right', 'fluent-cart'),
            ],
            'default'  => 'top-left',
            'inline'   => true,
            'required' => ['showSoldOutBadge', '=', true],
        ];

        $this->controls['soldOutBadgeTypography'] = [
            'tab'      => 'style',
            'group'    => 'sold_out_badge_style',
            'type'     => 'typography',
            'label'    => esc_html__('Typography', 'fluent-cart'),
            'css'      => [
                ['property' => 'font', 'selector' => '.fct-sold-out-badge'],
            ],
            'required' => ['showSoldOutBadge', '=', true],
        ];

        $this->controls['soldOutBadgeBackgroundColor'] = [
            'tab'      => 'style',
            'group'    => 'sold_out_badge_style',
            'type'     => 'color',
            'label'    => esc_html__('Background Color', 'fluent-cart'),
            'css'      => [
                ['property' => 'background-color', 'selector' => '.fct-sold-out-badge'],
            ],
            'required' => ['showSoldOutBadge', '=', true],
        ];

        $this->controls['soldOutBadgeTextColor'] = [
            'tab'      => 'style',
            'group'    => 'sold_out_badge_style',
            'type'     => 'color',
            'label'    => esc_html__('Text Color', 'fluent-cart'),
            'css'      => [
                ['property' => 'color', 'selector' => '.fct-sold-out-badge'],
            ],
            'required' => ['showSoldOutBadge', '=', true],
        ];
    }

    public function render()
    {
        $settings = $this->settings;

        $this->setBricksQuery();

        $viewMode = Arr::get($settings, 'viewMode', 'grid');
        $showViewSwitcher = !empty($settings['showViewSwitcher']);
        $paginationType = Arr::get($settings, 'paginationType', 'numbers');
        $isMainQuery = Arr::get($settings, 'is_main_query', false) && $this->is_frontend;
        $perPage = (int)Arr::get($settings, 'posts_per_page', 10);
        $enableFilter = !empty($settings['enableFilter']);
        $priceRange = !empty($settings['priceRange']);
        $liveFilter = !empty($settings['liveFilter']);

        $this->storeSettingsTransient($settings);
        
        if ($perPage <= 0) {
            $perPage = 10;
        }

        if ($perPage > 100) {
            $perPage = 100;
        }
        
        $args = $this->buildQueryArgs(
            $settings,
            $isMainQuery,
            $perPage,
            $viewMode
        );

        $productsQuery = (new ProductQuery($args));
        $products = $productsQuery->get();
        $defaultFilters = $productsQuery->getDefaultFilters();
        $ajaxDefaultFilters = $this->getAjaxDefaultFilters(
            $defaultFilters,
            $settings
        );
        
        $filters = $this->getFilters($settings);

        $wrapperClass = 'fct-products-wrapper-inner ' . ($viewMode === 'list' ? 'mode-list' : 'mode-grid') . (!$enableFilter ? ' fct-full-container-width' : '');

        $wrapperAttributes = [
            'class'                                  => $wrapperClass,
            'data-fluent-cart-product-wrapper-inner' => '',
            'data-per-page'                          => $perPage,
            'data-order-type'                        => Arr::get($defaultFilters, 'sort_type'),
            'data-live-filter'                       => $liveFilter,
            'data-paginator'                         => esc_attr($paginationType),
            'data-default-filters'                   => wp_json_encode($ajaxDefaultFilters)
        ];

        // Persist the element's query restrictions so the AJAX round-trip
        // (search, sort, pagination) re-applies them. Without these the
        // Paginator sends no include/exclude/type/on-sale params and the
        // server falls back to an unrestricted query — e.g. an excluded
        // product reappears as soon as the visitor searches for it.
        $includeIds = Arr::get($args, 'include_ids', []);
        if (!empty($includeIds)) {
            $wrapperAttributes['data-include-ids'] = wp_json_encode(array_map('intval', (array) $includeIds));
        }

        $excludeIds = Arr::get($args, 'exclude_ids', []);
        if (!empty($excludeIds)) {
            $wrapperAttributes['data-exclude-ids'] = wp_json_encode(array_map('intval', (array) $excludeIds));
        }

        $productTypeArg = Arr::get($args, 'product_type', '');
        if (!empty($productTypeArg)) {
            $wrapperAttributes['data-product-type'] = is_array($productTypeArg) ? implode(',', $productTypeArg) : $productTypeArg;
        }

        if (Arr::get($args, 'on_sale', false)) {
            $wrapperAttributes['data-on-sale'] = '1';
        }

        $productWrapperClasses = [
            'fct-products-wrapper',
            'fct-brick-products-wrapper',
        ];

        if (!$this->is_frontend) {
            $productWrapperClasses[] = 'fct-bricks-editor-mode';
        }

        $rendererConfig = [
            'view_mode' => $viewMode,
            'enable_wildcard_filter' => !empty($settings['wildcardFilter']),
            'custom_filters'  => [
                'enabled' => $enableFilter,
                'price_range' => $priceRange,
                'live_filter' => $liveFilter
            ],
        ];

        // Only override enable_sort_by if explicitly set in settings (key exists)
        if (array_key_exists('enableSortBy', $settings)) {
            $rendererConfig['enable_sort_by'] = !empty($settings['enableSortBy']);
        }

        $shopAppRenderer = new ShopAppRenderer($products, $rendererConfig);

        ?>
        <div 
            <?php 
                //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_attributes() handles escaping internally
                echo $this->render_attributes('_root'); 
            ?>
        >
            <div 
                <?php 
                    //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_attributes() handles escaping internally
                    echo $this->render_attributes('wrapper'); 
                ?>
            >
                <div 
                    class="<?php echo esc_attr(implode(' ', $productWrapperClasses)); ?>"
                    data-fluent-cart-shop-app
                    data-fluent-cart-product-wrapper
                >
                    <!-- View switcher -->
                    <?php
                        if ($showViewSwitcher) {
                            $shopAppRenderer->renderViewSwitcher();
                        }
                    ?>

                    <!-- Products Container -->
                    <div <?php RenderHelper::renderAtts($wrapperAttributes); ?>>
                        <!-- Filter render here -->
                        <?php
                            if ($enableFilter) {
                                $productFilterRenderer = new ProductFilterRender($filters);
                                $shopAppRenderer->renderFilter($productFilterRenderer);
                            }
                        ?>

                        <!-- Products -->
                        <div
                            data-fluent-cart-shop-app-product-list
                            class="fct-products-container"
                        >
                            <?php $this->renderProducts($products); ?>
                        </div>

                        <div class="fluent-cart-product-loader loader-hidden" data-fluent-cart-product-loader>
                            <div class="fluent-cart-product-spinner"></div>
                        </div>

                    </div>

                    <!-- Pagination -->
                    <?php
                        if ($paginationType !== 'scroll') {
                            $this->renderPagination(
                                $products,
                                $defaultFilters,
                                $paginationType,
                                $perPage,
                                $viewMode
                            );
                        }
                    ?>


                </div>
            </div>
        </div>

        <?php
    }

    /**
     * Store settings in transient.
     */
    private function storeSettingsTransient($settings)
    {
        $uuid = 'fc_bx_collection_' . $this->uid;
        if (!get_transient($uuid)) {
            // save the settings as transient
            set_transient($uuid, $settings, 48 * HOUR_IN_SECONDS);
        }
    }

    /**
     * Build query args.
     */
    private function buildQueryArgs($settings, $isMainQuery, $perPage, $viewMode) {
        $args = array_filter([
            'paginate'      => 'simple',
            'is_main_query' => $isMainQuery,
            'sort_by'       => Arr::get($settings, 'orderby', 'date'),
            'sort_type'     => Arr::get($settings, 'order', 'desc'),
            'per_page'      => $perPage,
            'view_mode'     => $viewMode,
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

            $allowOutOfStock = Arr::get($settings, 'allowOutOfStock', false);
            if ($allowOutOfStock) {
                $args['allow_out_of_stock'] = true;
            }

            $categories = Arr::get($settings, 'categories', []);
            if ($categories) {
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                $args['tax_query'] = [
                    'product-categories' => $categories,
                ];
            }

            if (!empty($settings['defaultFilterEnabled'])) {
                if (!empty($settings['defaultFilterAllowOutOfStock'])) {
                    $args['allow_out_of_stock'] = true;
                }

                $wildcard = trim((string) Arr::get($settings, 'defaultFilterWildcard', ''));
                if ($wildcard !== '') {
                    $args['search'] = sanitize_text_field($wildcard);
                }

                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                $taxQuery = Arr::get($args, 'tax_query', []);
                foreach (Taxonomy::getTaxonomies() as $taxonomy) {
                    $controlKey = sanitize_key(str_replace('-', '_', $taxonomy));
                    $selectedTerms = (array) Arr::get($settings, 'defaultFilterTaxonomy_' . $controlKey, []);
                    if (empty($selectedTerms)) {
                        continue;
                    }

                    // bricks_get_terms_options values are shaped "{taxonomy}::{term_id}",
                    // not plain term IDs — strip the prefix before use.
                    $termIds = array_values(array_filter(array_map(function ($value) {
                        $parts = explode('::', (string) $value);
                        return sanitize_key(end($parts));
                    }, $selectedTerms)));

                    if (!empty($termIds)) {
                        $existing = Arr::get($taxQuery, $taxonomy, []);
                        $taxQuery[$taxonomy] = array_values(array_unique(array_merge((array) $existing, $termIds)));
                    }
                }
                if (!empty($taxQuery)) {
                    //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                    $args['tax_query'] = $taxQuery;
                }
            }
        }

        return $args;
    }

    /**
     * Render product loop.
     */

    private function renderProducts($products) {
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

    /**
     * Render pagination.
     */
    private function renderPagination(
        $products,
        $defaultFilters,
        $paginationType,
        $perPage,
        $viewMode
    ) {
        $renderer = new ShopAppRenderer(
            [
                'products' => $products,
                'total'    => $products->total(),
            ],
            [
                'default_filters' => $defaultFilters,
                'pagination_type' => $paginationType,
                'per_page'        => $perPage,
                'view_mode'       => $viewMode,
            ]
        );

        $renderer->renderPaginator();
    }

    /**
     * Get available product filters.
     */
    private function getFilters(array $settings): array
    {
        $filters = [];
        $taxonomies = Taxonomy::getTaxonomies();

        foreach ($taxonomies as $taxonomy) {
            $controlKey = sanitize_key(str_replace('-', '_', $taxonomy));
            $defaultLabel = Str::headline($taxonomy);

            $enabledKey = 'taxonomy_' . $controlKey;
            $enabled = array_key_exists($enabledKey, $settings)
                ? !empty($settings[$enabledKey])
                : !empty($settings[$this->legacyControlKey($taxonomy)]);

            $labelKey = 'displayName_' . $controlKey;
            $label = array_key_exists($labelKey, $settings)
                ? ($settings[$labelKey] !== '' ? $settings[$labelKey] : $defaultLabel)
                : (!empty($settings[$this->legacyDisplayNameKey($taxonomy)])
                    ? $settings[$this->legacyDisplayNameKey($taxonomy)]
                    : $defaultLabel);

            $showEmptyKey = 'showEmpty_' . $controlKey;
            $showEmpty = array_key_exists($showEmptyKey, $settings)
                ? !empty($settings[$showEmptyKey])
                : !empty($settings[$this->legacyShowEmptyKey($taxonomy)]);

            $filters[$taxonomy] = [
                'filter_type' => 'options',
                'is_meta'     => true,
                'label'       => $label,
                'enabled'     => $enabled,
                'multiple'    => false,
                'show_empty'  => $showEmpty,
            ];
        }

        $priceRangeLabel = !empty($settings['displayNamePriceRange']) ? $settings['displayNamePriceRange'] : __('Price', 'fluent-cart');
        $filters['price_range'] = [
            'filter_type' => 'range',
            'is_meta'     => false,
            'label'       => $priceRangeLabel,
            'enabled'     => !empty($settings['priceRange']),
        ];

        return $filters;
    }

    private function legacyControlKey($taxonomy)
    {
        $map = [
            'product-categories' => 'productCategories',
            'product-brands'     => 'productBrands',
        ];
        return Arr::get($map, $taxonomy, '');
    }

    private function legacyDisplayNameKey($taxonomy)
    {
        $map = [
            'product-categories' => 'displayNameCategories',
            'product-brands'     => 'displayNameBrands',
        ];
        return Arr::get($map, $taxonomy, '');
    }

    private function legacyShowEmptyKey($taxonomy)
    {
        $map = [
            'product-categories' => 'showEmptyCategories',
            'product-brands'     => 'showEmptyBrands',
        ];
        return Arr::get($map, $taxonomy, '');
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

    private function getAjaxDefaultFilters(array $defaultFilters, array $settings): array
    {
        $filters = [];
        $taxQuery = Arr::get($defaultFilters, 'tax_query', []);

        if (!empty($taxQuery)) {
            foreach ($taxQuery as $taxonomy => $termIds) {
                $filters[$taxonomy] = $termIds;
            }
        }

        $wildcard = Arr::get($defaultFilters, 'search', '');
        if ($wildcard !== '') {
            $filters['wildcard'] = $wildcard;
        }

        $allowOutOfStock = !empty($settings['allowOutOfStock']) || (
            !empty($settings['defaultFilterEnabled']) &&
            !empty($settings['defaultFilterAllowOutOfStock'])
        );
        if ($allowOutOfStock) {
            $filters['allow_out_of_stock'] = true;
        }

        if (!empty($filters)) {
            $filters['enabled'] = true;
        }

        return $filters;
    }

}

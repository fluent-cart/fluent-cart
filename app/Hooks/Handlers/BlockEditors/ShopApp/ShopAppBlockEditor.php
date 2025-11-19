<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors\ShopApp;

use FluentCart\Api\Taxonomy;
use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Hooks\Handlers\BlockEditors\BlockEditor;
use FluentCart\App\Hooks\Handlers\BlockEditors\ShopApp\InnerBlocks\InnerBlocks;
use FluentCart\App\Hooks\Handlers\ShortCodes\ShopAppHandler;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Collection;
use FluentCart\Framework\Support\Str;

class ShopAppBlockEditor extends BlockEditor
{
    protected static string $editorName = 'products';

    protected ?string $localizationKey = 'fluent_cart_shop_app_block_editor_data';

    protected function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/ShopApp/ShopAppBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components']
            ]
        ];
    }

    protected function getStyles(): array
    {
        return ['admin/BlockEditor/ShopApp/style/shop-app-block-editor.css'];
    }

    public function init(): void
    {
        parent::init();

        $this->registerInnerBlocks();
    }

    public function registerInnerBlocks()
    {
        InnerBlocks::register();
    }

    protected function localizeData(): array
    {
        $taxonomies = Taxonomy::getTaxonomies();

        $taxonomies = Collection::make($taxonomies)
            ->map(function ($taxonomy) {
                return [
                    'name'   => $taxonomy,
                    'label'  => Str::headline($taxonomy),
                    'parent' => 0,
                    'terms'  => Taxonomy::getFormattedTerms($taxonomy, false, null, 'value', 'label'),
                ];
            });

        return [
            $this->getLocalizationKey()      => [
                'rest'               => Helper::getRestInfo(),
                'slug'               => $this->slugPrefix,
                'name'               => static::getEditorName(),
                'product_categories' => $this->getMetaFilterOptions('categories'),
                'trans'              => TransStrings::getShopAppBlockEditorString(),
                'taxonomies'         => $taxonomies,
                'title'              => __('Products', 'fluent-cart'),
            ],
            'fluent_cart_block_editor_asset' => [
                'placeholder_image' => Vite::getAssetUrl('images/placeholder.svg'),
            ],
            'fluent_cart_block_translation'  => TransStrings::blockStrings()
        ];
    }

    private function getMetaFilterOptions($key): array
    {
        $options = get_categories([
            'taxonomy'  => 'product-' . $key,
            'post_type' => FluentProducts::CPT_NAME
        ]);

        return Collection::make($options)->map(function ($meta) {
            return [
                'value' => $meta->term_id,
                'label' => $meta->name,
            ];
        })->toArray();
    }

    public function provideContext(): array
    {
        // in which name the data will be received => which attr
        return [
            'fluent-cart/paginator'              => 'paginator',
            'fluent-cart/per_page'               => 'per_page',
            'fluent-cart/enable_filter'          => 'enable_filter',
            'fluent-cart/product_box_grid_size'  => 'product_box_grid_size',
            'fluent-cart/view_mode'              => 'view_mode',
            'fluent-cart/filters'                => 'filters',
            'fluent-cart/default_filters'        => 'default_filters',
            'fluent-cart/order_type'             => 'order_type',
            'fluent-cart/order_by'             => 'order_by',
            'fluent-cart/live_filter'            => 'live_filter',
            'fluent-cart/price_format'           => 'price_format',
            'fluent-cart/enable_wildcard_filter' => 'enable_wildcard_filter',

        ];
    }

    public function render(array $shortCodeAttribute, $block = null, $content = null): string
    {
        AssetLoader::loadProductArchiveAssets();
        $filters = Arr::get($shortCodeAttribute, 'filters', []);
        $colors = Arr::get($shortCodeAttribute, 'colors', []);
        $default_filters = Arr::get($shortCodeAttribute, 'default_filters', [
            'enabled' => false,
        ]);

        $allowOutOfStock = Arr::get($default_filters, 'enabled', false) === true &&
            Arr::get($default_filters, 'allow_out_of_stock', false) === true;

        $taxonomies = Taxonomy::getTaxonomies();
        foreach ($taxonomies as $key => $taxonomy) {
            if (isset($default_filters[$key])) {
                if (is_array($default_filters[$key])) {
                    $default_filters[$key] = implode(',', $default_filters[$key]);
                } else {
                    $default_filters[$key] = '';
                }

            }
        }

        $enableFilter = Arr::get($shortCodeAttribute, 'enable_filter', 0);
        if ($enableFilter === 'false') {
            $enableFilter = 0;
        }

        $view = ("[" . ShopAppHandler::SHORT_CODE . "
            block_class='" . Arr::get($shortCodeAttribute, 'className', '') . "'
            per_page='" . Arr::get($shortCodeAttribute, 'per_page', 10) . "'
            order_type='" . Arr::get($shortCodeAttribute, 'order_type', 'DESC') . "'
            live_filter='" . Arr::get($shortCodeAttribute, 'live_filter', true) . "'
            view_mode='" . Arr::get($shortCodeAttribute, 'view_mode', '') . "'
            price_format='" . Arr::get($shortCodeAttribute, 'price_format', 'starts_from') . "'
            search_grid_size='" . Arr::get($shortCodeAttribute, 'search_grid_size', '') . "'
            product_grid_size='" . Arr::get($shortCodeAttribute, 'product_grid_size', '') . "'
            product_box_grid_size='" . Arr::get($shortCodeAttribute, 'product_box_grid_size', '') . "' 
            paginator='" . Arr::get($shortCodeAttribute, 'paginator', '') . "' 
            use_default_style='" . Arr::get($shortCodeAttribute, 'use_default_style', 1) . "' 
            enable_filter='" . $enableFilter . "'
            allow_out_of_stock='" . $allowOutOfStock . "' 
            enable_wildcard_filter='" . Arr::get($shortCodeAttribute, 'enable_wildcard_filter', 1) . "'
            " .
            (count($colors) ? "colors='" . (json_encode(Arr::get($shortCodeAttribute, 'colors', []))) . "'
            " : "") .
            "enable_wildcard_for_post_content='" . Arr::get($shortCodeAttribute, 'enable_wildcard_for_post_content', 0) . "'
            " .
            (count($filters) ? "filters='" . esc_attr((json_encode(Arr::get($shortCodeAttribute, 'filters', [])))) . "'
            " : "") .
            "default_filters='" . (json_encode($default_filters)) . "'
        ]");


        return $content;
    }
}

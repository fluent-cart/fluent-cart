<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors\RelatedProduct;

use FluentCart\App\Helpers\CurrenciesHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Hooks\Handlers\BlockEditors\BlockEditor;
use FluentCart\App\Hooks\Handlers\BlockEditors\RelatedProduct\InnerBlocks\InnerBlocks;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class RelatedProductBlockEditor extends BlockEditor
{
    protected static string $editorName = 'related-product';

    public function init(): void
    {
        parent::init();

        $this->registerInnerBlocks();
    }

    public function registerInnerBlocks()
    {
        InnerBlocks::register();
    }

    protected function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/RelatedProduct/RelatedProductBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components']
            ]
        ];
    }

    protected function getStyles(): array
    {
        return [
            'admin/BlockEditor/RelatedProduct/style/related-product-block-editor.scss'
        ];
    }

    protected function localizeData(): array
    {
        $currencyCode = Helper::shopConfig('currency');
        $currencySign = CurrenciesHelper::getCurrencySign($currencyCode);
        return [
            $this->getLocalizationKey() => [
                'slug'        => $this->slugPrefix,
                'name'        => static::getEditorName(),
                'title'       => __('Related Products', 'fluent-cart'),
                'description' => __('Display related products.', 'fluent-cart'),
                'currency_sign' => $currencySign,
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    public function provideContext(): array
    {
        return [
            'fluent-cart/related_product_ids'    => 'related_product_ids',
            'fluent-cart/related_products'       => 'related_products',
            'fluent-cart/product_id'             => 'product_id',
            'fluent-cart/order_by'               => 'order_by',
            'fluent-cart/query_type'             => 'query_type',
            'fluent-cart/related_by_categories'  => 'related_by_categories',
            'fluent-cart/related_by_brands'      => 'related_by_brands',
            'fluent-cart/columns'                => 'columns',
            'fluent-cart/posts_per_page'         => 'posts_per_page',
            'fluent-cart/show_image'             => 'show_image',
            'fluent-cart/show_title'             => 'show_title',
            'fluent-cart/show_price'             => 'show_price',
            'fluent-cart/show_button'            => 'show_button',
        ];
    }

    public function render(array $shortCodeAttribute, $block = null, $content = null): string
    {
        AssetLoader::loadProductArchiveAssets();

        $queryType = Arr::get($shortCodeAttribute, 'query_type', 'custom');

        if ($queryType === 'default') {
            // Default mode: uses current product from page context
            $product = fluent_cart_get_current_product();
            if (!$product) {
                return '';
            }
        } else {
            // Custom mode: requires a product_id from block attributes
            $productId = absint(Arr::get($shortCodeAttribute, 'product_id', 0));
            if (!$productId) {
                return '';
            }
        }

        // Return the rendered InnerBlocks content
        return $content;
    }
}

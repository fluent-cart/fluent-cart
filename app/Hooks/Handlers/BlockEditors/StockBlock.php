<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;


use FluentCart\App\Models\Product;
use FluentCart\App\Services\Renderer\ProductRenderer;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class StockBlock extends BlockEditor
{
    protected static string $editorName = 'stock';

    public function supports(): array
    {
        return [
            'html'       => false,
            'align'      => ['left', 'center', 'right'],
            'typography' => [
                'fontSize'   => true,
                'lineHeight' => true
            ],
            'spacing'    => [
                'margin' => true
            ],
            'color'      => [
                'text' => true,
            ]
        ];
    }

    protected function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/Stock/StockBlock.jsx',
                'dependencies' => ['wp-blocks', 'wp-components', 'wp-data', 'wp-block-editor', 'wp-element']
            ]
        ];
    }

    protected function getStyles(): array
    {
//        'admin/BlockEditor/Stock/style/stock-block-editor.scss'
        return [];
    }


    protected function localizeData(): array
    {
        return [
            $this->getLocalizationKey()     => [
                'slug'              => $this->slugPrefix,
                'name'              => static::getEditorName(),
                'title'             => __('Stock', 'fluent-cart'),
                'description'       => __('This block will display the stock.', 'fluent-cart'),
                'placeholder_image' => Vite::getAssetUrl('images/placeholder.svg'),
                'supports'          => $this->supports()
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    public function render(array $shortCodeAttribute, $block = null)
    {
        $product = null;
        $insideProductInfo = Arr::get($shortCodeAttribute, 'inside_product_info', 'no');
        $queryType = Arr::get($shortCodeAttribute, 'query_type', 'default');

        if ($insideProductInfo === 'yes' || $queryType === 'default') {
            $product = fluent_cart_get_current_product();

        } else {
            $productId = Arr::get($shortCodeAttribute, 'product_id', false);
            if ($productId) {
                $product = Product::query()->with(['variants'])->find($productId);
            }
        }

        if (!$product) {
            return '';
        }

        $wrapper_attributes = get_block_wrapper_attributes(
            [
                'class' => 'fct-product-card-title wc-block-grid__product-title',
            ]
        );

        ob_start();
        (new ProductRenderer($product))
            ->renderStockAvailability($wrapper_attributes);

        return ob_get_clean();
    }

}

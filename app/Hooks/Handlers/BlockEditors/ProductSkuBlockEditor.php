<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\Models\Product;
use FluentCart\App\Services\Renderer\ProductRenderer;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\Framework\Support\Arr;

class ProductSkuBlockEditor extends BlockEditor
{
    protected static string $editorName = 'product-sku';

    public function supports(): array
    {
        return [
            'html'                 => false,
            'align'                => ['left', 'center', 'right'],
            'typography'           => [
                'fontSize'                      => true,
                'lineHeight'                    => true,
                '__experimentalFontFamily'      => true,
                '__experimentalFontWeight'      => true,
                '__experimentalFontStyle'       => true,
                '__experimentalTextTransform'   => true,
                '__experimentalLetterSpacing'   => true,
                '__experimentalDefaultControls' => [
                    'fontSize' => true,
                ],
            ],
            'color'                => [
                'text'       => true,
                'background' => true,
            ],
            'spacing'              => [
                'margin'  => true,
                'padding' => true,
            ],
            '__experimentalBorder' => [
                'color'  => true,
                'radius' => true,
                'style'  => true,
                'width'  => true,
            ],
            'shadow'               => true,
        ];
    }

    protected function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/ProductSku/ProductSkuBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components']
            ]
        ];
    }

    protected function getStyles(): array
    {
        return [
            'admin/BlockEditor/ProductSku/style/product-sku-block-editor.scss'
        ];
    }

    protected function localizeData(): array
    {
        return [
            $this->getLocalizationKey()     => [
                'slug'        => $this->slugPrefix,
                'name'        => static::getEditorName(),
                'title'       => __('Product SKU', 'fluent-cart'),
                'description' => __('Displays the product SKU (Stock Keeping Unit).', 'fluent-cart'),
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    public function render(array $shortCodeAttribute, $block = null): string
    {
        $product = null;
        $insideProductInfo = Arr::get($shortCodeAttribute, 'inside_product_info', 'no');
        $queryType = Arr::get($shortCodeAttribute, 'query_type', 'default');

        if ($insideProductInfo === 'yes' || $queryType === 'default') {
            $product = fluent_cart_get_current_product();
        } else {
            $productId = Arr::get($shortCodeAttribute, 'product_id', false);
            if ($productId) {
                $product = Product::query()->with(['detail', 'variants'])->find($productId);
            }
        }

        if (!$product) {
            return '';
        }

        if (!$product->relationLoaded('variants')) {
            $product->load(['detail', 'variants']);
        }

        $showLabel = (bool) Arr::get($shortCodeAttribute, 'show_label', true);
        $label = sanitize_text_field(Arr::get($shortCodeAttribute, 'label', __('SKU:', 'fluent-cart')));

        $variant = null;
        $variantId = Arr::get($shortCodeAttribute, 'variant_id', '');
        if ($variantId) {
            $variant = $product->variants->firstWhere('id', (int) $variantId);
        }

        $wrapper_attributes = get_block_wrapper_attributes([
            'class' => 'fct-product-sku-info',
        ]);

        ob_start();
        (new ProductRenderer($product))->renderSku($wrapper_attributes, $showLabel, $label, $variant);

        return ob_get_clean();
    }
}

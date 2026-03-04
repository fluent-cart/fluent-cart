<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\ProductCardRender;
use FluentCart\App\Services\Renderer\ProductRenderer;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Models\Product;

class ProductTitleBlockEditor extends BlockEditor
{
    protected static string $editorName = 'product-title';

    public function supports(): array
    {
        return [
            'html'                 => false,
            'align'                => true,
            'typography'           => [
                'fontSize'                      => true,
                'lineHeight'                    => true,
                '__experimentalFontFamily'      => true,
                '__experimentalFontWeight'      => true,
                '__experimentalFontStyle'       => true,
                '__experimentalTextTransform'   => true,
                '__experimentalTextDecoration'  => true,
                '__experimentalLetterSpacing'   => true,
                '__experimentalDefaultControls' => [
                    'fontSize'   => true,
                    'lineHeight' => true,
                    'fontWeight' => true,
                ],
            ],
            'color'                => [
                'text'       => true,
                'background' => true,
                'link'       => true,
                'gradients'   => true,
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
                'source'       => 'admin/BlockEditor/ProductTitle/ProductTitleBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components']
            ]
        ];
    }

    protected function getStyles(): array
    {
        return [
            'admin/BlockEditor/ProductTitle/style/product-title-block-editor.scss'
        ];
    }

    protected function localizeData(): array
    {
        return [
            $this->getLocalizationKey()     => [
                'slug'              => $this->slugPrefix,
                'name'              => static::getEditorName(),
                'title'             => __('Product Title', 'fluent-cart'),
                'description'       => __('This block will display the product title.', 'fluent-cart'),
                'placeholder_image' => Vite::getAssetUrl('images/placeholder.svg')
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    public function render(array $shortCodeAttribute, $block = null)
    {
        AssetLoader::loadSingleProductAssets();
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

        $isLink = Arr::get($shortCodeAttribute, 'isLink', true);
        $target = Arr::get($shortCodeAttribute, 'linkTarget', '_self');

        $wrapper_attributes = get_block_wrapper_attributes([
            'class' => 'fct-product-card-title wc-block-grid__product-title',
        ]);

        ob_start();
        (new ProductCardRender($product))->renderTitle($wrapper_attributes, [
            'isLink' => $isLink,
            'target' => $target,
        ]);
        return ob_get_clean();
    }
}

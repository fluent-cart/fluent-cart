<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Models\Product;

class ProductDescriptionBlockEditor extends BlockEditor
{
    protected static string $editorName = 'product-description';

    public function ancestor(): array
    {
        return [
            'fluent-cart/products',
            'fluent-cart/product-carousel',
            'fluent-cart/product-info',
            'fluent-cart/related-product',
        ];
    }

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
                'gradients'  => true,
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

    public function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/ProductDescription/ProductDescriptionBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components']
            ]
        ];
    }

    public function getStyles(): array
    {
        return [
            'admin/BlockEditor/ProductDescription/style/product-description-block-editor.scss'
        ];
    }

    public function localizeData(): array
    {
        return [
            $this->getLocalizationKey()     => [
                'slug'              => $this->slugPrefix,
                'name'              => static::getEditorName(),
                'title'             => __('Product Description', 'fluent-cart'),
                'description'       => __('This block will display the full product description.', 'fluent-cart'),
                'placeholder_image' => Vite::getAssetUrl('images/placeholder.svg')
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    public function render(array $shortCodeAttribute, $block = null)
    {
        AssetLoader::loadSingleProductAssets();

        $productId = absint(Arr::get($shortCodeAttribute, 'product_id', 0));

        if ($productId) {
            $product = Product::query()->find($productId);
        } else {
            $product = fluent_cart_get_current_product();
        }

        if (!$product || empty($product->post_content)) {
            return '';
        }

        $wrapper_attributes = get_block_wrapper_attributes([
            'class' => 'fct-product-description',
        ]);

        return sprintf(
            '<div %s>%s</div>',
            $wrapper_attributes,
            wpautop(wp_kses_post($product->post_content))
        );
    }
}
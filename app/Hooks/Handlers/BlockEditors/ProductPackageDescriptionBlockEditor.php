<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\Models\Product;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\ProductCardRender;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\Framework\Support\Arr;

class ProductPackageDescriptionBlockEditor extends BlockEditor
{
    protected static string $editorName = 'product-package-description';

    public function ancestor(): array
    {
        return [
            'fluent-cart/product-info'
        ];
    }

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

    public function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/ProductPackageDescription/ProductPackageDescriptionBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components']
            ]
        ];
    }

    public function getStyles(): array
    {
        return [
            'admin/BlockEditor/ProductPackageDescription/style/product-package-description-block-editor.scss'
        ];
    }

    public function localizeData(): array
    {
        return [
            $this->getLocalizationKey()     => [
                'slug'        => $this->slugPrefix,
                'name'        => static::getEditorName(),
                'title'       => __('Product Package Description', 'fluent-cart'),
                'description' => __('Displays the product packaging details (name, type, dimensions, weight).', 'fluent-cart'),
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    public function render(array $shortCodeAttribute, $block = null): string
    {
        AssetLoader::loadSingleProductAssets();
        $product = fluent_cart_get_current_product();

        if (!$product) {
            $productId = Arr::get($shortCodeAttribute, 'product_id', false);
            if ($productId) {
                $product = Product::query()->with(['detail', 'variants'])->find($productId);
            }
        }

        if (!$product) {
            return '';
        }

        $showName = (bool) Arr::get($shortCodeAttribute, 'show_name', true);
        $showDimensions = (bool) Arr::get($shortCodeAttribute, 'show_dimensions', true);
        $showProductWeight = (bool) Arr::get($shortCodeAttribute, 'show_product_weight', true);
        $showTotalWeight = (bool) Arr::get($shortCodeAttribute, 'show_total_weight', true);

        $wrapper_attributes = get_block_wrapper_attributes([
            'class' => 'fct-package-description',
            'data-fluent-cart-package-description' => '',
        ]);

        ob_start();
        (new ProductCardRender($product))->renderPackageDescription(
            $wrapper_attributes,
            $showName,
            $showDimensions,
            $showProductWeight,
            $showTotalWeight
        );

        return ob_get_clean();
    }
}

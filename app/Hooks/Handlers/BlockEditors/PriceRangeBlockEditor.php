<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\ProductCardRender;
use FluentCart\App\Services\Renderer\ProductRenderer;
use FluentCart\App\Services\TemplateService;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Models\Product;

class PriceRangeBlockEditor extends BlockEditor
{
    protected static string $editorName = 'price-range';

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

    public function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/PriceRange/PriceRangeBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components']
            ]
        ];
    }

    public function getStyles(): array
    {
        return [
            'admin/BlockEditor/PriceRange/style/price-range-block-editor.scss'
        ];
    }

    public function localizeData(): array
    {
        return [
            $this->getLocalizationKey()     => [
                'slug'              => $this->slugPrefix,
                'name'              => static::getEditorName(),
                'title'             => __('Price Range', 'fluent-cart'),
                'description'       => __('This block will display the price range.', 'fluent-cart'),
                'placeholder_image' => Vite::getAssetUrl('images/placeholder.svg')
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    public function useContext()
    {
        return ['fluent-cart/price_format'];
    }

    public function render(array $shortCodeAttribute, $block = null)
    {
        AssetLoader::loadSingleProductAssets();
        $product = null;
        $insideProductInfo = Arr::get($shortCodeAttribute, 'inside_product_info', 'no');
        $queryType = Arr::get($shortCodeAttribute, 'query_type', 'default');

        if ($insideProductInfo === 'yes' || $queryType === 'default') {
            $product = fluent_cart_get_current_product();
        }

        if (!$product) {
            $productId = Arr::get($shortCodeAttribute, 'product_id', false);
            if ($productId) {
                $product = Product::query()->with(['detail', 'variants'])->find($productId);
            }
        }

        if (!$product) {
            return '';
        }

        $defaultPriceFormat = 'starts_from';
        if (is_singular('fluent-products') && TemplateService::getCurrentFcPageType() === 'single_product') {
            $defaultPriceFormat = 'range';
        }
        // Context from parent (ShopApp) takes priority, then block's own attribute, then default
        $attrPriceFormat = Arr::get($shortCodeAttribute, 'price_format', $defaultPriceFormat);
        $priceFormat = $block ? Arr::get($block->context ?? [], 'fluent-cart/price_format', $attrPriceFormat) : $attrPriceFormat;

        $wrapper_attributes = get_block_wrapper_attributes([
            'class' => 'fct-product-card-prices',
        ]);

        $render = new ProductCardRender($product, [
            'price_format' => $priceFormat,
        ]);
        ob_start();
        $render->renderPrices($wrapper_attributes);
        return ob_get_clean();
    }
}

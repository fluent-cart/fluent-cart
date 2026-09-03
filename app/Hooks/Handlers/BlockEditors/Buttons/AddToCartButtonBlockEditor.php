<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors\Buttons;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Hooks\Handlers\BlockEditors\BlockEditor;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\ProductRenderer;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class AddToCartButtonBlockEditor extends BlockEditor
{
    protected static string $editorName = 'add-to-cart-button';



    public function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/Buttons/AddToCartButtonBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components']
            ]
        ];
    }

    public function getStyles(): array
    {
        return [
            'admin/BlockEditor/Buttons/style/button-block-editor.scss'
        ];
    }


    public function supports(): array
    {
        return [
            'html'       => false,
            'typography' => [
                'fontSize'   => true,
                'lineHeight' => true
            ],
            'spacing'    => [
                'margin' => true,
                'padding' => true
            ],
            'color'      => [
                'text' => true,
                'background' => true,
            ],
            '__experimentalBorder' => [
                'color'  => true,
                'radius' => true,
                'style'  => true,
                'width'  => true,
            ],
            'shadow'     => true
        ];
    }

    public function localizeData(): array
    {
        return [
            $this->getLocalizationKey()     => [
                'slug'              => $this->slugPrefix,
                'name'              => static::getEditorName(),
                'title'             => __('Add to Cart', 'fluent-cart'),
                'description'       => __('A custom button block with product selection and automatic link assignment.', 'fluent-cart'),
                'placeholder_image' => Vite::getAssetUrl('images/placeholder.svg'),
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    public function render(array $shortCodeAttribute, $block = null)
    {
        AssetLoader::loadSingleProductAssets();
        $variantIds = Arr::get($shortCodeAttribute, 'variant_ids', []);

        $variantId  = Arr::get($variantIds, 0);

        if (!$variantId) {
            if(Helper::isAdminUser()){
                return '<p class="fct-admin-notice">' . esc_html__('No variant selected', 'fluent-cart') . '</p>';
            }

            return '';
        }

        $variant = ProductVariation::query()->find($variantId);

        if (!$variant) {
            if(Helper::isAdminUser()){
                return '<p class="fct-admin-notice">' . esc_html__('Invalid variant', 'fluent-cart') . '</p>';
            }

            return '';
        }

        $product = Product::query()->find($variant->post_id);

        if (!$product) {
            if(Helper::isAdminUser()){
                return '<p class="fct-admin-notice">' . esc_html__('Product not found', 'fluent-cart') . '</p>';
            }

            return '';
        }

        ob_start();
        // Pass the block's selected variant as the renderer's default variation
        // so the button's data-cart-id (and stock/price) reflect that variant.
        // Without this the renderer falls back to the product's first/default
        // variant, and the button always adds the wrong variant to the cart.
        (new ProductRenderer($product, ['default_variation_id' => $variant->id]))
            ->renderAddToCartButtonBlock($shortCodeAttribute);
        return ob_get_clean();
    }

}

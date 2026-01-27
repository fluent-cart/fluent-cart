<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors\Buttons;

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



    protected function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/Buttons/AddToCartButtonBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components']
            ]
        ];
    }

    protected function getStyles(): array
    {
        return [
            'admin/BlockEditor/Buttons/style/button-block-editor.scss'
        ];
    }


    protected function localizeData(): array
    {
        return [
            $this->getLocalizationKey()     => [
                'slug'              => $this->slugPrefix,
                'name'              => static::getEditorName(),
                'title'             => __('Add to Cart Button', 'fluent-cart'),
                'description'       => __('This block will display the Add to Cart button.', 'fluent-cart'),
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
            return __('No variant selected', 'fluent-cart');
        }

        $variant = ProductVariation::query()->find($variantId);

        if (!$variant) {
            return __('Invalid variant', 'fluent-cart');
        }

        $product = Product::query()->find($variant->post_id);

        if (!$product) {
            return __('Product not found', 'fluent-cart');
        }

        ob_start();
        (new ProductRenderer($product))->renderAddToCartButton();
        return ob_get_clean();
    }

}

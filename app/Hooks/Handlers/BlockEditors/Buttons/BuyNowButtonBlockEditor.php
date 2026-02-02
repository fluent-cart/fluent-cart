<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors\Buttons;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Hooks\Cart\WebCheckoutHandler;
use FluentCart\App\Hooks\Handlers\BlockEditors\BlockEditor;
use FluentCart\App\Http\Routes\WebRoutes;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\ModalCheckoutRenderer;
use FluentCart\App\Services\Renderer\ProductRenderer;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class BuyNowButtonBlockEditor extends BlockEditor
{
    protected static string $editorName = 'buy-now-button';

    protected function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/Buttons/BuyNowButtonBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components']
            ]
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
                'title'             => __('Product Button', 'fluent-cart'),
                'description'       => __('A custom button block with product selection and automatic link assignment.', 'fluent-cart'),
                'placeholder_image' => Vite::getAssetUrl('images/placeholder.svg'),
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    public function render(array $shortCodeAttribute, $block = null)
    {

        $enabledModalCheckout = Arr::get($shortCodeAttribute, 'enable_modal_checkout', false);
        AssetLoader::loadSingleProductAssets();

        $variantIds = Arr::get($shortCodeAttribute, 'variant_ids', []);
        $variantId  = Arr::get($variantIds, 0);

        if($enabledModalCheckout){
            add_action('wp_footer', function (){
                WebRoutes::renderModalCheckout();
                AssetLoader::loadModalCheckoutAssets();
            });
        }

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
        (new ProductRenderer($product))->renderBuyNowButtonBlock($shortCodeAttribute);
        return ob_get_clean();
    }


}

<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Hooks\Cart\CartLoader;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\CartDrawerRenderer;
use FluentCart\App\Services\Renderer\MiniCartRenderer;
use FluentCart\App\Services\Renderer\ProductRenderer;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class MiniCartBlockEditor extends BlockEditor
{
    protected static string $editorName = 'mini-cart';



    protected function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/Cart/MiniCartBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components']
            ]
        ];
    }

    protected function getStyles(): array
    {
        return [
            'admin/BlockEditor/Cart/style/mini-cart-block-editor.scss'
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

    protected function localizeData(): array
    {
        return [
            $this->getLocalizationKey()     => [
                'slug'              => $this->slugPrefix,
                'name'              => static::getEditorName(),
                'title'             => __('Mini Cart', 'fluent-cart'),
                'description'       => __('Display a button for shoppers to quickly view their cart.', 'fluent-cart'),
                'placeholder_image' => Vite::getAssetUrl('images/placeholder.svg'),
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    public function render(array $shortCodeAttribute, $block = null)
    {
        (new CartLoader())->registerDependency();
        AssetLoader::loadMiniCartAssets();

        $cart = CartHelper::getCart(null, false);
        $itemCount = 0;
        $cartData = [];

        if ($cart) {
            $cartData= $cart->cart_data ?? [];
            $itemCount = count($cartData);
        }


        $miniCartRenderer = new MiniCartRenderer($cartData, [
            'item_count' => $itemCount
        ]);

        ob_start();
        $miniCartRenderer->renderMiniCart($shortCodeAttribute);
        return ob_get_clean();

    }

}

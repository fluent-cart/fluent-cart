<?php

namespace FluentCart\App\Hooks\Handlers\ShortCodes;

use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Hooks\Cart\CartLoader;
use FluentCart\App\Hooks\Handlers\ShortCodes\ShortCode;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\CartDrawerRenderer;
use FluentCart\App\Services\Renderer\MiniCartRenderer;
use FluentCart\App\Services\Renderer\ProductRenderer;
use FluentCart\Framework\Support\Arr;

class MiniCartShortcode extends ShortCode
{
    protected static string $shortCodeName = 'fluent_cart_mini_cart';

    protected function getStyles(): array
    {
        return [
            'public/cart-drawer/mini-cart.scss',
        ];
    }


    public function render(?array $viewData = null)
    {
        $data = $viewData ?? $this->shortCodeAttributes ?? [];

        (new CartLoader())->registerDependency();
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

        $data['is_shortcode'] = true;

        $miniCartRenderer->renderMiniCart($data);
    }
}


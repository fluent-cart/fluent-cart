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

    public function getStyles(): array
    {
        return [
            'public/cart-drawer/mini-cart.scss',
        ];
    }


    public function render(?array $viewData = null)
    {
        $data = $viewData ?? $this->shortCodeAttributes ?? [];

        (new CartLoader())->registerDependency();
        $itemCount = 0;
        $cartData = [];

        // Hide mini cart count during instant checkout to avoid confusion
        if (!CartHelper::doingInstantCheckout()) {
            $cart = CartHelper::getCart(null, false);
            if ($cart) {
                $cartData = $cart->cart_data ?? [];
            }
        }

        $countMode = Arr::get($data, 'count_mode', 'distinct_products');

        if ($countMode === 'total_quantity') {
            foreach ($cartData as $item) {
                $itemCount += (int) ($item['quantity'] ?? 1);
            }
        } else {
            $itemCount = count($cartData);
        }

        $miniCartRenderer = new MiniCartRenderer($cartData, [
            'item_count' => $itemCount,
            'count_mode' => $countMode,
        ]);

        $data['is_shortcode'] = true;

        $miniCartRenderer->renderMiniCart($data);
    }
}


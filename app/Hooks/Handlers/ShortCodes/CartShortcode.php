<?php

namespace FluentCart\App\Hooks\Handlers\ShortCodes;

use FluentCart\Api\CurrencySettings;
use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Hooks\Cart\CartLoader;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\CartRenderer;

class CartShortcode extends ShortCode
{
    const SHORT_CODE = 'fluent_cart_cart';
    protected static string $shortCodeName = 'fluent_cart_cart';

    public static function register()
    {
        parent::register();

        add_action('wp_enqueue_scripts', function () {
            $action = App::request()->get('action') ?? '';
            if ($action === 'elementor') {
                return;
            }

            if (has_shortcode(get_the_content(), static::SHORT_CODE) || has_block('fluent-cart/cart_cart')) {
                (new static())->enqueueStyles();
            }
        }, 10);
    }

    public function render(?array $viewData = null)
    {
        AssetLoader::markFrontendAssetsRequired();
        $storeSettings = new StoreSettings();
        $cart = CartHelper::getCart();
        $cartItems = $cart->cart_data ?? [];
        $cartRenderer = new CartRenderer($cartItems);

        if (!$cart || !$cart->cart_data) {
            $cartRenderer->renderEmpty();
            return;
        }

        $cart->reValidateCoupons();
        $cartRenderer->render();
    }

    public function getStyles(): array
    {
        (new CartLoader())->enqueueStyle();

        return [];
    }

}

<?php

namespace FluentCart\App\Hooks\Cart;

use FluentCart\Api\CurrencySettings;
use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\UtmHelper;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\Renderer\CartRenderer;
use FluentCart\App\Services\Renderer\CartDrawerRenderer;

class CartLoader
{
    public function register()
    {
        add_action('wp_footer', [$this, 'init']);
    }

    public function init(): void
    {
        static $loadedOnce = false;

        if ($loadedOnce) {
            return;
        }

        $enableNavFloatingButton = apply_filters('fluent_cart/buttons/enable_floating_cart_button', true, []);

        if (!$enableNavFloatingButton) {
            return;
        }

        $loadedOnce = true;


        $this->registerDependency();

        if (self::shouldHideCartDrawer()) {
            return;
        }

        $cart = CartHelper::getCart(null, false);
        $itemCount = 0;

        if ($cart) {
            $itemCount = count($cart->cart_data ?? []);
        }

        $cartItems = Arr::get(CartResource::getStatus(), 'cart_data', []);

        if(empty($cartItems)){
            return;
        }

        (new CartDrawerRenderer($cartItems, [
            'item_count' => $itemCount
        ]))->render();
    }

    public function registerDependency(): void
    {
        AssetLoader::loadCartAssets();
    }

    public function enqueueStyle()
    {
        $app = fluentCart();
        $slug = $app->config->get('app.slug');

        Vite::enqueueStyle(
            $slug . '-fluentcart-drawer',
            'public/cart-drawer/cart-drawer.scss',
        );
    }

    public static function shouldHideCartDrawer()
    {
        global $post;
        $currentPageId = $post->ID ?? null;
        $storeSettings = new StoreSettings();
        $cartPageId = $storeSettings->getCheckoutPageId();
        $receiptPageId = $storeSettings->getReceiptPageId();

        if (!CartHelper::doingInstantCheckout() && $cartPageId != $currentPageId && $receiptPageId != $currentPageId) {
            return false;
        }
        return true;
    }
}

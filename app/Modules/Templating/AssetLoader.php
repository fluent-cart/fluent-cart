<?php

namespace FluentCart\App\Modules\Templating;

use FluentCart\Api\CurrencySettings;
use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\UtmHelper;
use FluentCart\App\Hooks\Cart\CartLoader;
use FluentCart\App\Hooks\Handlers\ShortCodes\CustomerProfileHandler;
use FluentCart\App\Models\Cart;
use FluentCart\App\Modules\Tax\TaxModule;
use FluentCart\App\Services\Localization\LocalizationManager;
use FluentCart\App\Services\Renderer\ProductFilterRender;
use FluentCart\App\Services\TemplateService;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Services\URL;
use FluentCart\App\Vite;
use FluentCart\App\Services\Renderer\CartRenderer;
use FluentCart\Framework\Support\Arr;
use FluentCart\Api\Resource\FrontendResource\CartResource;

class AssetLoader
{
    static $loadedAssets = [];

    public static function register()
    {
        add_action('wp_enqueue_scripts', [self::class, 'enqueueAssets']);
    }

    public static function enqueueAssets()
    {
        $pageType = TemplateService::getCurrentFcPageType();
        switch ($pageType) {
            case 'single_product':
                self::loadSingleProductAssets();
                break;
            case 'product_taxonomy':
            case 'shop':
                self::loadProductArchiveAssets();
                break;
            case 'customer_dashboard':
                self::loadCustomerDashboardGlobalAssets();
                break;
            case 'cart':
                self::loadCartAssets();
                break;
            case 'checkout':
                self::loadCheckoutAssets();
                break;
            default:
                return; // No need to load any assets
        }
    }

    public static function loadSingleProductAssets()
    {

        static $isLoaded = false;
        if ($isLoaded) {
            return;
        }
        self::loadProductCardAssets();
        $isLoaded = true;
        $singlePageScripts = [
            [
                'source'       => 'public/single-product/xzoom/xzoom.js',
                'dependencies' => [],
                'inFooter'     => true,
            ],
            [
                'source'       => 'public/single-product/SingleProduct.js',
                'dependencies' => [],
                'inFooter'     => true
            ],
        ];
        $localizeData = [
            'fluentcart_single_product_vars' => [
                'trans'                      => TransStrings::singleProductPageString(),
                'cart_button_text'           => apply_filters('fluent_cart/product/add_to_cart_text', __('Add To Cart', 'fluent-cart'), []),
                // App::storeSettings()->get('cart_button_text', __('Add to Cart', 'fluent-cart')),
                'out_of_stock_button_text'   => App::storeSettings()->get('out_of_stock_button_text', __('Out of Stock', 'fluent-cart')),
                'in_stock_status'            => Helper::IN_STOCK,
                'out_of_stock_status'        => Helper::OUT_OF_STOCK,
                'enable_image_zoom'          => (new StoreSettings())->get('enable_image_zoom_in_single_product'),
                'enable_image_zoom_in_modal' => (new StoreSettings())->get('enable_image_zoom_in_modal')
            ]
        ];
        Vite::enqueueAllScripts($singlePageScripts, 'fluent-cart-single-product-page', $localizeData);

        $singlePageStyles = [
            'public/single-product/single-product.scss',
            'public/single-product/similar-product.scss',
            'public/product-card/style/product-card.scss',
            'public/single-product/xzoom/xzoom.css',
            'public/buttons/add-to-cart/style/style.scss',
            'public/buttons/direct-checkout/style/style.scss'
        ];

        Vite::enqueueAllStyles($singlePageStyles, 'fluent-cart-single-product-page');


    }

    public static function loadProductCardAssets()
    {
        static $isLoaded = false;
        if ($isLoaded) {
            return;
        }
        $isLoaded = true;
        $singlePageStyles = [
            'public/product-card/style/product-card.scss',
        ];
        Vite::enqueueAllStyles($singlePageStyles, 'fluent-cart-product-card-page');
        Vite::enqueueScript(
            'fluent-cart-product-card-js',
            'public/product-card/product-card.js',
            []
        );
    }

    public static function loadProductArchiveAssets()
    {
        static $isLoaded = false;
        if ($isLoaded) {
            return;
        }
        $isLoaded = true;
        static::loadProductCardAssets();
        $app = App::getInstance();
        $slug = $app->config->get('app.slug');
        Vite::enqueueStyle(
            'fluentcart-product-card-page-css',
            'public/product-card/style/product-card.scss'
        );

        Vite::enqueueStyle(
            'fluentcart-single-product-css',
            'public/single-product/single-product.scss'
        );
        Vite::enqueueStyle(
            'fluentcart-similar-product-css',
            'public/single-product/similar-product.scss'
        );
        Vite::enqueueStyle(
            'fluentcart-add-to-cart-btn-css',
            'public/buttons/add-to-cart/style/style.scss'
        );
        Vite::enqueueStyle(
            'fluentcart-direct-checkout-btn-css',
            'public/buttons/direct-checkout/style/style.scss'
        );
        Vite::enqueueStyle(
            $slug . '-fluentcart-product-page-css',
            'public/product-page/style/shop-app.scss',
        );
        Vite::enqueueStaticStyle(
            $slug . '-fluentcart-product-filter-slider-css',
            'public/lib/nouislider/nouislider-15.7.1.css',
        );


        ob_start();
        ProductFilterRender::renderResponsiveFilter();
        $responsiveFilterWrapper = ob_get_clean();

        $assetsPath = $app['url.assets'];


        Vite::enqueueScript(
            $slug . '-fluentcart-product-page-js',
            'public/product-page/ShopApp.js',
            []
        )->with([
            'fluentcart_shop_vars' => [
                'rest'                         => Helper::getRestInfo(),
                'shop'                         => Helper::shopConfig(),
                'currency_settings'            => CurrencySettings::get(),
                'checkout_page'                => (new StoreSettings)->getCheckoutPage(),
                'cart_image'                   => $assetsPath . 'images/cart.svg',
                'cart_driver'                  => Helper::getCartDriver(),
                'responsive_filter_wrapper'    => $responsiveFilterWrapper,
                'is_admin_bar_showing'         => is_admin_bar_showing(),
                'responsive_filter_breakpoint' => apply_filters('shop_app_responsive_filter_breakpoint', 768)
            ],
            'fluentCartRestVars'   => [
                'rest'    => Helper::getRestInfo(),
                'ajaxurl' => admin_url('admin-ajax.php')
            ]
        ]);
        Vite::enqueueStaticScript(
            $slug . '-fluentcart-product-filter-slider',
            'public/lib/nouislider/nouislider-15.7.1.min.js',
            [$slug . '-fluentcart-product-page-js']
        );
        Vite::enqueueScript(
            'fluentcart-zoom-js',
            'public/single-product/xzoom/xzoom.js',
            []
        );

        Vite::enqueueScript(
            'fluentcart-single-product-js',
            'public/single-product/SingleProduct.js',
            []
        )->with([
            'fluentcart_single_product_vars' => [
                'trans'                    => TransStrings::singleProductPageString(),
                'cart_button_text'         => apply_filters('fluent_cart/product/add_to_cart_text', __('Add To Cart', 'fluent-cart'), []),
                // App::storeSettings()->get('cart_button_text', __('Add to Cart', 'fluent-cart')),
                'out_of_stock_button_text' => App::storeSettings()->get('out_of_stock_button_text', __('Out of Stock', 'fluent-cart')),
                'in_stock_status'          => Helper::IN_STOCK,
                'out_of_stock_status'      => Helper::OUT_OF_STOCK,
                'enable_image_zoom'        => (new StoreSettings())->get('enable_image_zoom_in_single_product')
            ]
        ]);
    }

    public static function loadCustomerDashboardGlobalAssets()
    {
        static $isLoaded = false;
        if ($isLoaded) {
            return;
        }
        $isLoaded = true;
        Vite::enqueueStyle('fluent-cart-customer-profile-global',
            'public/customer-profile/style/customer-profile-global.scss',
        );
    }

    public static function loadCustomerDashboardAssets()
    {
        static $isLoaded = false;
        if ($isLoaded) {
            return;
        }
        $isLoaded = true;
        Vite::enqueueStyle('fluentcart-customer-css',
            'public/customer-profile/style/customer-profile.scss'
        );

        Vite::enqueueScript(
            'fluentcart-customer-js',
            'public/customer-profile/Start.js',
            []
        )->with(CustomerProfileHandler::getLocalizationData());

        //will add script here/ skipping for now
    }

    public static function loadCartAssets()
    {
        static $isLoaded = false;
        if ($isLoaded) {
            return;
        }
        $isLoaded = true;
        $app = fluentCart();
        $slug = $app->config->get('app.slug');
        Vite::enqueueStaticScript(
            $slug . '-fluentcart-toastify-notify-style',
            'public/lib/toastify/toastify-js-1.12.0.js',
            [$slug . '-app',]
        );

        Vite::enqueueStaticStyle(
            $slug . '-fluentcart-toastify-notify-js',
            'public/lib/toastify/toastify.min-1.12.0.css',
        );

        $cart = CartHelper::getCart(null, false);

        $cartItemLayout = '';
        $emptyCartLayout = '';

        if (!App::request()->get(Helper::INSTANT_CHECKOUT_URL_PARAM)) {
            $cartItems = Arr::get(CartResource::getStatus(), 'cart_data', []);
            $cartRenderer = new CartRenderer($cartItems);

            ob_start();
            $cartRenderer->renderDummyItems();
            $cartItemLayout = ob_get_clean();
            ob_start();

            $cartRenderer->renderEmpty();

            $emptyCartLayout = ob_get_clean();
        }

        Vite::enqueueScript(
            $slug . '-app',
            'public/globals/FluentCartApp.js',
            []
        )->with([
            'fluentCartRestVars'     => [
                'rest'    => Helper::getRestInfo(),
                'ajaxurl' => admin_url('admin-ajax.php'),
            ],
            'fluentcart_drawer_vars' => [
                'placeholder_image'    => Vite::getAssetUrl('images/placeholder.svg'),
                'cart_item_layout'     => $cartItemLayout,
                'empty_cart_layout'    => $emptyCartLayout,
                'cart_driver'          => Helper::getCartDriver(),
                'cart_image'           => Vite::getAssetUrl('images/cart.svg'),
                'currency_settings'    => CurrencySettings::get(),
                'has_active_cart'      => !!$cart,
                'is_drawer_hidden'     => CartLoader::shouldHideCartDrawer(),
                'is_admin_bar_showing' => is_admin_bar_showing()
            ],
            'fluentcart_utm_vars'    => [
                'allowed_keys' => UtmHelper::allowedUtmParameterKey()
            ]
        ]);

        Vite::enqueueStyle(
            $slug . '-fluentcart-drawer',
            'public/cart-drawer/cart-drawer.scss',
        );
    }

    public static function loadCheckoutAssets($cart = null)
    {

        static $isLoaded = false;
        if ($isLoaded) {
            return;
        }
        $isLoaded = true;
        if (!$cart) {
            $cart = CartHelper::getCart();
        }

        static::loadCartAssets();

        if (!$cart) {
            return;
        }

        static $alreadyLoaded = false;
        if ($alreadyLoaded) {
            return;
        }

        $alreadyLoaded = true;

        Vite::enqueueAllStyles(
            [
                [
                    'source' => 'public/checkout/style/checkout.scss',
                ],
                [
                    'source' => 'public/components/select/style/style.scss',
                ]
            ],
            'checkout-page'
        );
        $scripts = [
            [
                'source'       => 'public/checkout/FluentCartCheckout.js',
                'dependencies' => ['fluent-cart-app'],
                'inFooter'     => true,
                'handle'       => 'fct-checkout'
            ],
            [
                'source'       => 'public/orderbump/orderbump.js',
                'dependencies' => ['fluent-cart-app'],
                'inFooter'     => true,
                'handle'       => 'fct-orderbump'
            ]
        ];
        Vite::enqueueAllScripts($scripts, 'fct-checkout');

        self::localizeCheckoutData($cart);
    }

    private static function localizeCheckoutData(Cart $cart)
    {
        static $isLoaded = false;
        if ($isLoaded) {
            return;
        }
        $isLoaded = true;
        $countryCode = (string)Arr::get($cart->checkout_data, 'form_data.billing_country');
        $countryInfo = [];
        if (!empty($countryCode)) {
            $countryInfo[$countryCode] = LocalizationManager::getCountryInfoFromRequest(null, $countryCode);
            $shippingCountryCode = (string)Arr::get($cart->checkout_data, 'form_data.shipping_country');
            if (Arr::get($cart->checkout_data, 'form_data.ship_to_different') === 'yes' && $shippingCountryCode !== $countryCode) {
                $countryInfo[$shippingCountryCode] = LocalizationManager::getCountryInfoFromRequest(null, $shippingCountryCode);
            }
        }

        $data = [
            'fluentcart_checkout_vars' => [
                'rest'                                         => Helper::getRestInfo(),
                'ajaxurl'                                      => admin_url('admin-ajax.php'),
                'is_all_digital'                               => !$cart->requireShipping(),
                'is_cart_locked'                               => $cart->checkout_data['is_locked'] ?? 'no',
                'disable_coupons'                              => $cart->checkout_data['disable_coupons'] ?? 'no',
                'payment_methods_with_custom_checkout_buttons' => apply_filters('fluent_cart/payment_methods_with_custom_checkout_buttons', []),
                'tax_settings'                                 => (new TaxModule())->getSettings(),
                'submit_button'                                => [
                    'text' => __('Place Order', 'fluent-cart'),
                ],
                'trans'                                        => TransStrings::checkoutPageString(),
                'payments_trans'                               => TransStrings::paymentsString()
            ],
            'fluentcart_checkout_info' => [
                'baseUrl'                => site_url(),
                'rest_url'               => Helper::getRestInfo()['url'],
                'checkout_nonce'         => wp_create_nonce('fluentcart'),
                'ajax_url'               => admin_url('admin-ajax.php'),
                'is_user_logged_in'      => is_user_logged_in(),
                'is_admin'               => current_user_can('manage_options'),
                'has_subscription'       => $cart->hasSubscription(),
                'rest'                   => Helper::getRestInfo(),
                'order_confirmation_url' => admin_url('admin-ajax.php?action=fluent_cart_place_order'),
                'order_information_url'  => URL::getApiUrl('/checkout/get-order-info'),
                'custom_checkout_url'    => URL::getApiUrl('/checkout'),
                'redirect_url'           => (new StoreSettings())->getCheckoutPage(),
                'store_country'          => (new StoreSettings())->get('store_country'),
                'country_info'           => $countryInfo,
                'is_zero_payment'        => $cart->isZeroPayment() ? 'yes' : 'no'
            ]
        ];

        foreach ($data as $key => $datum) {
            wp_localize_script('fct-checkout', $key, $datum);
        }
    }

    private static function markAssetLoaded($handle)
    {
        self::$loadedAssets[$handle] = true;
    }

    public static function enqueueProductInfoFrontendStyles()
    {
        static $isLoaded = false;
        if ($isLoaded) {
            return;
        }
        $isLoaded = true;
        // Enqueue frontend styles for the product info block
        Vite::enqueueStyle(
            'fluentcart-single-product',
            'public/single-product/single-product.scss'
        );

        // Enqueue related component styles
        Vite::enqueueStyle(
            'fluentcart-add-to-cart-btn-css',
            'public/buttons/add-to-cart/style/style.scss'
        );
        
        Vite::enqueueStyle(
            'fluentcart-direct-checkout-btn-css',
            'public/buttons/direct-checkout/style/style.scss'
        );
    }


    public static function enqueueThankYouPageAssets()
    {
        Vite::enqueueStyle(
            'fluentcart-thank-you-css',
            'public/receipt/style/thank_you.scss',
        );
    }

}

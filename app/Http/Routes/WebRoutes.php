<?php

namespace FluentCart\App\Http\Routes;

use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\Api\StoreSettings;
use FluentCart\App\App;

use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Hooks\Handlers\ShortCodes\Checkout\CheckoutPageHandler;
use FluentCart\App\Hooks\Handlers\ShortCodes\ReceiptHandler;
use FluentCart\App\Http\Controllers\WebController\FileDownloader;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\PayPalPartnerRenderer;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\FrontendView;
use FluentCart\App\Services\PrintService;
use FluentCart\App\Services\Renderer\CartRenderer;
use FluentCart\App\Services\TemplateService;
use FluentCart\App\Services\URL;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\Renderer\CheckoutRenderer;
use FluentCart\App\Services\Renderer\ModalCheckoutRenderer;

class WebRoutes
{
    public static function register()
    {

        add_action('init', function () {
            self::registerRoutes();
        });
    }

    public static function renderModalCheckout() {
        AssetLoader::loadModalCheckoutAssets();

        add_action('wp_footer', function () {
            if (
                isset($_SERVER['HTTP_SEC_FETCH_DEST']) &&
                $_SERVER['HTTP_SEC_FETCH_DEST'] === 'iframe'
            ) {
                return;
            }

            $cart = CartHelper::getCart();

            if (!$cart) {
                $cart = new Cart();
            }

            (new ModalCheckoutRenderer($cart))->render();
        });

        // Stop rendering when loaded inside an iframe

    }

    public static function registerRoutes()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
       $page = sanitize_text_field(wp_unslash($_REQUEST['fluent-cart'] ?? ''));

        if (empty($page)) {
            return;
        }

        $page = sanitize_text_field($page);

        if (!$page) {
            return;
        }

        if ($page === 'instant_checkout') {
            $requestData = App::request()->all();
            $variationId = sanitize_text_field(App::request()->get('item_id'));
            $quantity = App::request()->get('quantity', 1);
            // Raw request flag; actual validation and normalization
            // happens in CartResource.
            $isCustom = (bool)Arr::get($requestData, 'is_custom', false);

            if(!$isCustom) {
                if (!is_numeric($variationId)) {
                    return;
                } 
            }

            if (is_numeric($quantity)) {
                $quantity = intval($quantity);
                $quantity = max($quantity, 1);
            } else {
                $quantity = 1;
            }

            if($isCustom) {
                $variation = apply_filters('fluent_cart/cart/validate_custom_item', null, [
                    'item_id'          => $variationId,
                    'quantity'         => $quantity,
                    'is_custom'        => $isCustom,
                    'action'           => 'instant_checkout',
                ]);

                if (!is_object($variation)) {
                    $variation = (object) $variation;
                }

            } else {
                $variation = ProductVariation::query()->find($variationId);
            }

            if (empty($variation)) {
                return;
            }

            $soldIndividually = $isCustom
            ? !empty($variation->sold_individually)  
            : (bool) $variation->soldIndividually();

            if ($soldIndividually) {
                $quantity = 1;
            }

            $cart = CartResource::generateCartForInstantCheckout($variationId, $quantity, [
                'is_custom' => $isCustom,
                'variation' => $variation
            ]);

            if (is_wp_error($cart)) {
                (new CheckoutPageHandler())->enqueueStyles();
                ob_start();
                (new CartRenderer([]))->renderEmpty(
                    $cart->get_error_message()
                );
                $view = ob_get_clean();
                FrontendView::make(__('Product Not Found', 'fluent-cart'), $view);
                die();
            }

            $coupons = App::request()->get('coupons', '');
            if ($coupons) {
                $coupons = explode(',', $coupons);
                $coupons = array_map('sanitize_text_field', $coupons);
                $cart->applyCoupon($coupons);
            }

            $target_path = (new StoreSettings())->getCheckoutPage();

            $redirectTo = App::request()->get('redirect_to');
            if (!empty($redirectTo)) {
                $url = sanitize_url(wp_unslash($redirectTo));
                if (!empty($url) && filter_var($url, FILTER_VALIDATE_URL)) {
                    $target_path = $url;
                }
            }


            // Step 1: Get current query string and parse to array

            $queryArray = [];
            $queryString = Arr::get( App::request()->server(), 'QUERY_STRING');
            if ( isset($queryString) ) {
                parse_str($queryString, $queryArray);
            }

            unset($queryArray['fluent-cart']);
            unset($queryArray['item_id']);
            unset($queryArray['quantity']);
            unset($queryArray['coupons']);
            unset($queryArray['redirect_to']);

            if (isset($queryArray['is_custom'])) {
                unset($queryArray['is_custom']);
            }

            $queryArray['fct_cart_hash'] = $cart->cart_hash;

            $redirect_url = URL::appendQueryParams($target_path, $queryArray);

            wp_redirect($redirect_url);
            exit;
        }

        $served = self::handleMainRoutes($page);

        // Handle faker routes if enabled and not already served
        if (!$served && App::config()->get('using_faker') === true) {
            $served = FakerRoutes::handle($page);
        }

        if (has_action('fluent_cart_action_' . $page)) {
            do_action('fluent_cart_action_' . $page, App::request()->all());
            die();
        }

        if ($served) {
            die();
        }

        return '';
    }

    private static function handleMainRoutes($page): bool
    {
        $request = App::request();
        switch ($page) {
            case 'fluent_cart_payment_authenticate':
                (new PayPalPartnerRenderer($request->mode))->render(
                    $request->all()
                );
                break;
            case 'download-by-id':
            case 'download-file':
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in view template
                echo (new FileDownloader())->index(App::request());
                return true;

            case 'receipt':
                $receiptHandler = new ReceiptHandler();

                $view = $receiptHandler->renderRedirectPage([
                    'type' => 'receipt'
                ]);

                FrontendView::make(
                    __('Order Receipt', 'fluent-cart'),
                    $view,
                    [
                        'styles'         => [
                            'public/checkout/style/confirmation.scss'
                        ],
                        'scripts'        => [
                            [
                                'source'   => 'public/lib/printThis-2.0.0.min.js',
                                'isStatic' => true
                            ],
                            'public/print/Print.js'
                        ],
                        'enqueue_prefix' => 'fluent-cart-checkout-order-receipt',
                        'wp_head'        => false,
                        'wp_footer'      => false,
                    ]);
                return true;
                

            case 'print-invoice':
                return self::handlePrintRoute('invoice');

            case 'print-packing-slip':
                return self::handlePrintRoute('packingSlip');

            case 'print-delivery-slip':
                return self::handlePrintRoute('deliverySlip');

            case 'print-shipping-slip':
                return self::handlePrintRoute('shippingSlip');

            case 'print-dispatch-slip':
                return self::handlePrintRoute('dispatchSlip');
            
            case 'modal_checkout':
                return self::handleModalCheckout();

            default:
                return false;
        }
    }

    private static function handleModalCheckout(): bool
    {
        $variationId = App::request()->get('item_id');
        $quantity = App::request()->get('quantity', 1);

        if (!is_numeric($variationId)) {
            return false;
        }

        if (is_numeric($quantity)) {
            $quantity = intval($quantity);
            $quantity = max($quantity, 1);
        } else {
            $quantity = 1;
        }

        $variation = ProductVariation::query()->find($variationId);

        if (empty($variation)) {
            return false;
        }

        $soldIndividually = $variation->soldIndividually();

        if ($soldIndividually) {
            $quantity = 1;
        }

        $cart = CartResource::generateCartForInstantCheckout($variationId, $quantity);

        if ($cart) {
            $modalCheckoutRenderer = new ModalCheckoutRenderer($cart);
            ob_start();
            $modalCheckoutRenderer->renderForm();
            $checkoutContent = ob_get_clean();

            AssetLoader::loadCheckoutAssets($cart);

            Vite::enqueueStyle(
                'fluentcart-modal-checkout-iframe-css',
                'public/checkout/style/checkout-iframe.scss'
            );

//            Vite::enqueueScript(
//                'fluentcart-modal-checkout-form-js',
//                'public/checkout/ModalCheckoutForm.js',
//                []
//            );

            FrontendView::make(__('Checkout', 'fluent-cart'), $checkoutContent);
            die();
        }

        return false;


    }

    private static function handlePrintRoute($method): bool
    {
        $order = App::request()->get('order');
        if (!empty($order)) {
            PrintService::$method($order);
            return true;
        }
        return false;
    }
}

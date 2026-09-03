<?php

namespace FluentCart\App\Hooks\Handlers;

use FluentCart\App\App;
use FluentCart\App\Modules\PaymentMethods\AirwallexGateway\Airwallex;
use FluentCart\App\Modules\PaymentMethods\Cod\Cod;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\PayPal;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Stripe;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Connect\ConnectConfig;
use FluentCart\Framework\Support\Arr;
use FluentCart\Api\PaymentMethods;
use FluentCart\Framework\Support\Collection;

class GlobalPaymentHandler
{
    public function register()
    {
        $this->init();
    }

    public function init()
    {
        add_action('init', function () {
            $gateway = GatewayManager::getInstance();
            $gateway->register('stripe', new Stripe());
            $gateway->register('paypal', new PayPal());
            $gateway->register('offline_payment', new Cod());
            $gateway->register('airwallex', new Airwallex());

            $this->verifyStripeConnect();

            $this->appAuthenticator();
            //This hook will allow others to register their payment method with ours
            do_action('fluent_cart/register_payment_methods', [
                'gatewayManager' => $gateway
            ]);
        });

        add_action('fluent_cart_action_fct_payment_listener_ipn', function () {
            $this->initIpnListener();
        });
    }

    // IPN / Payment Webhook Listener
    public function initIpnListener(): void
    {
        $paymentMethod = App::request()->getSafe('method', 'sanitize_text_field');
        $gateway = GatewayManager::getInstance($paymentMethod);
        if (is_object($gateway) && method_exists($gateway, 'handleIPN')) {
            try {
                $gateway->handleIPN();
            } catch (\Throwable $e) {
                fluent_cart_error_log('IPN Handler Error: ' . $paymentMethod,
                    $e->getMessage() . '. Debug Trace: ' . $e->getTraceAsString()
                );
                wp_send_json([
                    'message' => sprintf(
                        /* translators: %s is the payment method name */
                        __('IPN processing failed. - %s', 'fluent-cart'),
                        $paymentMethod
                    )
                ], 500);
            }
        }
    }

    public function appAuthenticator()
    {
        $request = App::request()->all();
        if (isset($request['fct_app_authenticator'])) {
            $paymentMethod = sanitize_text_field($request['method']);

            if (GatewayManager::has($paymentMethod)) {
                $methodInstance = GatewayManager::getInstance($paymentMethod);
                if (method_exists($methodInstance, 'appAuthenticator')) {
                    $methodInstance->appAuthenticator($request);
                }
            }
        }
    }

    public function verifyStripeConnect()
    {
        $request = App::request()->all();
        if (isset($request['vendor_source']) && $request['vendor_source'] == 'fluent_cart') {
            if (isset($request['ff_stripe_connect']) && current_user_can('manage_options')) {
                $data = Arr::only($request, ['ff_stripe_connect', 'mode', 'state', 'code']);
                ConnectConfig::verifyAuthorizeSuccess($data);
            }

            wp_redirect(admin_url('admin.php?page=fluent-cart#/settings/payments/stripe'));
        }
    }

    public function disconnect($method, $mode)
    {
        if (GatewayManager::has($method)) {
            $methodInstance = GatewayManager::getInstance($method);
            if (method_exists($methodInstance, 'getConnectInfo')) {
                wp_send_json(
                    $methodInstance->disconnect($mode),
                    200
                );
            }
        }
    }

    public function getSettings($method): array
    {
        if (GatewayManager::has($method)) {
            $methodInstance = GatewayManager::getInstance($method);
            $filtered = Collection::make($methodInstance->fields())->filter(function ($item) {
                return Arr::get($item, 'visible', 'yes') === 'yes';
            })->toArray();

            $result = [
                'fields'   => $filtered,
                'settings' => $methodInstance->settings->get()
            ];

            // Add addon metadata if it's an addon
            $meta = $methodInstance->meta();

            if (isset($result['settings']) && empty(Arr::get($result, 'settings.checkout_label')) && Arr::has($meta, 'title')) {
                Arr::set($result, 'settings.checkout_label', $meta['title']);
            }

            if (Arr::get($meta, 'is_addon') === true) {
                $result['addon_info'] = [
                    'is_addon' => true,
                    'addon_source' => $meta['addon_source'] ?? [],
                ];
            }

            return $result;
        } else {
            throw new \Exception(esc_html__('No valid payment method found!', 'fluent-cart'));
        }
    }

    /**
     * @throws \Exception
     */
    public function getAll(): array
    {
        $gateways = (new PaymentMethods())->getAll();
        
        // Sort by saved order if available
        $savedOrder = get_option('fluent_cart_payment_methods_order', []);
        if (!empty($savedOrder) && is_array($savedOrder)) {
            $orderMap = array_flip($savedOrder);
            usort($gateways, function($a, $b) use ($orderMap) {
                $aOrder = $orderMap[$a['route']] ?? PHP_INT_MAX;
                $bOrder = $orderMap[$b['route']] ?? PHP_INT_MAX;
                return $aOrder <=> $bOrder;
            });
        }
        
        return $gateways;
    }
}

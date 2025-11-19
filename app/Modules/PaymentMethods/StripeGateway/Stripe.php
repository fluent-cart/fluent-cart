<?php

namespace FluentCart\App\Modules\PaymentMethods\StripeGateway;

use FluentCart\Api\CurrencySettings;
use FluentCart\Api\Orders;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\CartCheckoutHelper;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Hooks\Cart\WebCheckoutHandler;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\API\API;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Connect\ConnectConfig;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Webhook\IPN;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Webhook\Webhook;
use FluentCart\App\Services\CustomPayment\PaymentIntent;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class Stripe extends AbstractPaymentGateway
{

    private $methodSlug = 'stripe';

    public array $supportedFeatures = ['payment', 'refund', 'webhook', 'custom_payment', 'card_update', 'switch_payment_method', 'dispute_handler', 'subscriptions'];

    public BaseGatewaySettings $settings;

    public function __construct()
    {
        parent::__construct(
            new StripeSettingsBase(),
            new StripeSubscriptions()
        );

        add_action('fluent_cart_action_stripe_connect', function ($data) {
            ConnectConfig::handleConnect($data);
        });

    }

    public function boot()
    {
        (new IPN)->init();
        (new Confirmations)->init();
        add_filter('fluent_cart/payment_methods/stripe_pub_key', [$this, 'getPublicKey'], 10);
    }

    public function meta(): array
    {
        return [
            'title'              => 'Card',
            'route'              => 'stripe',
            'slug'               => 'stripe',
            'label'              => 'Stripe',
            'admin_title'        => 'Stripe',
            'description'        => __("Stripe's payments platform lets you accept credit cards, debit cards, and popular payment methods around the world all with a single integration.", "fluent-cart"),
            'logo'               => Vite::getAssetUrl('images/payment-methods/card.svg'),
            'icon'               => Vite::getAssetUrl('images/payment-methods/stripe-icon.svg'),
            'status'             => $this->settings->get('is_active') === 'yes',
            'brand_color'        => '#136196',
            'upcoming'           => false,
            'supported_features' => $this->supportedFeatures
        ];
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        $order = $paymentInstance->order;

        $storeName = (new StoreSettings())->get('store_name');

        $paymentArgs = array(
            'client_reference_id' => $order->uuid,
            'amount'              => (int)$paymentInstance->transaction->total,
            'currency'            => strtolower($paymentInstance->transaction->currency),
            'description'         => $storeName . ' #' . $order->invoice_no, // @todo: We will replace with order summary with item names later
            'customer_email'      => $paymentInstance->order->email,
            'success_url'         => $this->getSuccessUrl($paymentInstance->transaction),
            'custom_payment_url'  => PaymentHelper::getCustomPaymentLink($paymentInstance->order->uuid)
        );

        if ($paymentInstance->subscription) {
            return (new Processor())->handleSubscription($paymentInstance, $paymentArgs);
        }

        return (new Processor())->handleSinglePayment($paymentInstance, $paymentArgs);
    }

    public function processRefund($transaction, $amount, $args)
    {
        if (!$amount) {
            return new \WP_Error(
                'fluent_cart_stripe_refund_error',
                __('Refund amount is required.', 'fluent-cart')
            );
        }

        return \FluentCart\App\Modules\PaymentMethods\StripeGateway\StripeHelper::processRemoteRefund($transaction, $amount, $args);
    }

    public function webHookPaymentMethodName()
    {
        return $this->getMeta('route');
    }

    public function handleIPN(): void
    {
        (new IPN($this))->verifyAndProcess();
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        if (($this->settings->get('checkout_mode') ?? '') == 'hosted') {
            return [];
        };

        return [
            [
                'handle' => 'fluent-cart-checkout-sdk-stripe',
                'src'    => 'https://js.stripe.com/v3/',
            ],
            [
                'handle' => 'fluent-cart-checkout-handler-stripe',
                'src'    => Vite::getEnqueuePath('public/payment-methods/stripe-checkout.js'),
                'deps'   => ['fluent-cart-checkout-sdk-stripe']
            ]
        ];
    }

    public function getLocalizeData(): array
    {
        return [
            'fct_stripe_data' => [
                'translations' => [
                    'Payment module not available to checkout! Please reload again, or contact admin!' => __('Payment module not available to checkout! Please reload again, or contact admin!', 'fluent-cart'),
                    'See Errors' => __('See Errors', 'fluent-cart'),
                    'Pay Now' => __('Pay Now', 'fluent-cart'),
                    'Place Order' => __('Place Order', 'fluent-cart'),
                    'Card details are not valid!' => __('Card details are not valid!', 'fluent-cart'),
                    'Total amount is not valid, please add some items to cart!' => __('Total amount is not valid, please add some items to cart!', 'fluent-cart'),
                    'An error occurred while parsing the response.' => __('An error occurred while parsing the response.', 'fluent-cart'),
                    'Loading Payment Processor...' => __('Loading Payment Processor...', 'fluent-cart'),
                    'redirecting for action' => __('redirecting for action', 'fluent-cart'),
                ]
            ]
        ];
    }

    public static function beforeSettingsUpdate($data, $oldSettings): array
    {
        $provider = Arr::get($data, 'provider', 'connect');
        $mode = Arr::get($data, 'payment_mode', 'test');

        if ('connect' == $provider) {
            $data[$mode . '_secret_key'] = Helper::encryptKey(Arr::get($data, $mode . '_secret_key'));
        }

        if (Arr::get($data, 'provider') === 'api_keys') {
            $data['test_publishable_key'] = '';
            $data['live_publishable_key'] = '';
            $data['test_secret_key'] = '';
            $data['live_secret_key'] = '';
        }

        return $data;
    }

    public static function validateSettings($data): array
    {
        $mode = Arr::get($data, 'payment_mode', 'test');
        $provider = Arr::get($data, 'provider', 'connect');

        if ($provider === 'api_keys') {
            if ($mode === 'live') {
                $sk = defined('FCT_STRIPE_LIVE_SECRET_KEY') ? FCT_STRIPE_LIVE_SECRET_KEY : Arr::get($data, 'live_secret_key');
            } else {
                $sk = defined('FCT_STRIPE_TEST_SECRET_KEY') ? FCT_STRIPE_TEST_SECRET_KEY : Arr::get($data, 'test_secret_key');
            }
        } else {
            $sk = $mode === 'live' ? Arr::get($data, 'live_secret_key') : Arr::get($data, 'test_secret_key');
            if (empty($sk)) {
                $errorMessage = $mode === 'live' ? __('Stripe not connected in Live Mode!', 'fluent-cart') : __('Stripe not connected in Test Mode!!', 'fluent-cart');
                return [
                    'status'  => 'failed',
                    'message' => $errorMessage
                ];
            } else {
                return [
                    'status'  => 'success',
                    'message' => __('Stripe account already verified!', 'fluent-cart')
                ];
            }
        }

        if (empty($sk)) {
            return [
                'status'  => 'failed',
                'message' => __('Please provide a valid secret key!', 'fluent-cart')
            ];
        }

        if ($mode === 'live' && !str_contains($sk, 'sk_live')) {
            return [
                'status'  => 'failed',
                'message' => __('Please provide a valid LIVE secret key!', 'fluent-cart')
            ];
        } else if ($mode === 'test' && !str_contains($sk, 'sk_test')) {
            return [
                'status'  => 'failed',
                'message' => __('Please provide a valid TEST secret key!', 'fluent-cart')
            ];
        }

        $response = (new API)->remoteRequest('account', [], $sk, 'GET');

        if (isset($response['error'])) {
            return [
                'status'  => 'failed',
                'message' => $response['error']['message'] ? $response['error']['message'] : __('Invalid credentials!', 'fluent-cart')
            ];
        }

        if (!isset($response['id'])) {
            return [
                'status'  => 'failed',
                'message' => $response['error']['message'] ? $response['error']['message'] : __('Invalid credentials!', 'fluent-cart')
            ];
        }

        return [
            'status'  => 'success',
            'message' => __('Stripe account verified!', 'fluent-cart')
        ];
    }

    public function fields(): array
    {
        return array(
            'notice'              => [
                'value' => $this->renderStoreModeNotice(),
                'label' => __('Store Mode notice', 'fluent-cart'),
                'type'  => 'notice'
            ],
            'payment_mode'        => [
                'type'   => 'tabs',
                'schema' => [
                    [
                        'type'   => 'tab',
                        'label'  => __('Live credentials', 'fluent-cart'),
                        'value'  => 'live',
                        'schema' => []
                    ],
                    [
                        'type'   => 'tab',
                        'label'  => __('Test credentials', 'fluent-cart'),
                        'value'  => 'test',
                        'schema' => [],
                    ]
                ]
            ],
            'provider'            => array(
                'value' => apply_filters('fluent_cart_form_disable_stripe_connect', false, []) ? 'api_keys' : 'connect',
                'label' => __('Provider', 'fluent-cart'),
                'type'  => 'provider'
            ),
            'setup_guide'         => array(
                'value'   => '<h4>' . __('Or Setup keys manually.', 'fluent-cart') . '</h4><hr/>',
                'label'   => __('Or Setup keys manually', 'fluent-cart'),
                'type'    => 'html_attr',
                'visible' => 'no'
            ),
            'webhook_desc'        => array(
                'value' => Webhook::webhookInstruction(),
                'label' => __('Webhook URL', 'fluent-cart'),
                'type'  => 'html_attr'
            ),
            'test_active_methods' => [
                'value' => (new API())->getActivatedPaymentMethodsConfigs('test'),
                'label' => __('Activated Methods', 'fluent-cart'),
                'type'  => 'active_methods'
            ],
            'live_active_methods' => [
                'value' => (new API())->getActivatedPaymentMethodsConfigs('live'),
                'label' => __('Activated Methods', 'fluent-cart'),
                'type'  => 'active_methods'
            ],
        );

    }

    public function getPublicKey($pre = '')
    {
        return $this->settings->getPublicKey();
    }

    public function getTransactionUrl($url, $data): string
    {
        $transaction = Arr::get($data, 'transaction', null);
        if (!$transaction) {
            return $url;
        }

        if ($transaction->transaction_type === 'refund') {
            return 'https://dashboard.stripe.com/refunds/' . $transaction->vendor_charge_id;
        }

        return 'https://dashboard.stripe.com/payments/' . $transaction->vendor_charge_id;
    }

    public function getSubscriptionUrl($url, $data): string
    {
        return 'https://dashboard.stripe.com/subscriptions/' . Arr::get($data, 'vendor_subscription_id');
    }

    public function getOrderInfo($data)
    {

        $cart = CartHelper::getCart();
        $checkOutHelper = CartCheckoutHelper::make();
        $shippingChargeData = (new WebCheckoutHandler())->getShippingChargeData($cart);
        $shippingCharge = Arr::get($shippingChargeData, 'charge');
        $totalPrice = $checkOutHelper->getItemsAmountTotal(false) + $shippingCharge;

        $tax = $checkOutHelper->getCart()->checkout_data['tax_data'] ?? [];
        if (Arr::get($tax, 'tax_behavior', 0) == 1) {
            $totalPrice = $totalPrice + Arr::get($tax, 'tax_total', 0) + Arr::get($tax, 'shipping_tax', 0);
        }

        $items = $this->getCheckoutItems();

        $hasSubscription = $this->validateSubscriptions($items);

        $stripeSettings = new StripeSettingsBase();
        $publicKey = $stripeSettings->getPublicKey();

        if (empty($publicKey)) {
            $message = __('No Valid public key is not found!', 'fluent-cart');
            fluent_cart_add_log('Stripe Credential Validation', $message, 'error', ['log_type' => 'payment']);
            wp_send_json([
                'status'  => 'failed',
                'message' => $message
            ], 423);
        }

        $paymentArgs['public_key'] = $publicKey;

        $intentData = [
            'mode'               => 'payment',
            'amount'             => $totalPrice,
            'currency'           => strtolower(CurrencySettings::get('currency')),
            'automatic_payment_methods' => ['enabled' => true]
        ];

        if ($hasSubscription) {
            $intentData['mode'] = 'subscription';
            $intentData['setup_future_usage'] = 'off_session';
        } elseif (Arr::get($data, 'save_payment_method') === 'yes') {
            $intentData['setup_future_usage'] = 'on_session';
        }

        wp_send_json(
            [
                'status'       => 'success',
                'message'      => __('Order info retrieved!', 'fluent-cart'),
                'data'         => [],
                'payment_args' => $paymentArgs,
                'intent'       => $intentData,
            ],
            200
        );
    }

    public function getConnectInfo(): array
    {
        return ConnectConfig::getConnectConfig();
    }

    public function disconnect($mode): bool
    {
        return ConnectConfig::disconnect($mode);
    }

    public function getCounterDisputeUrl($transaction)
    {
        if (!$transaction->meta['dispute_id']) {
            return '';
        }

        return 'https://dashboard.stripe.com/disputes/' . $transaction->meta['dispute_id'];
    }

    public function acceptRemoteDispute($transaction, $args = [])
    {
        $disputeId = Arr::get($transaction->meta, 'dispute_id');
        if (!$disputeId) {
            $charge = (new API())->getStripeObject('payment_intents/' . $transaction->vendor_charge_id, ['expand' => ['latest_charge']]);

            if (is_wp_error($charge) || empty($charge['dispute'])) {
                new \WP_Error('No dispute ID found!', __('Please check stripe if the dispute is already accepted or not!', 'fluent-cart'));
            }

            $disputeId = Arr::get($charge, 'dispute', '');
        }

        $closeDispute = (new API())->createStripeObject('disputes/' . $disputeId . '/close');

        if (is_wp_error($closeDispute)) {
            return $closeDispute;
        }

        return $closeDispute;
    }

    public function isCurrencySupported(): bool
    {
       // stripe support all listed currencies except for IRR (Iranian Rial)
       return strtoupper(CurrencySettings::get('currency')) !== 'IRR';
    }

}

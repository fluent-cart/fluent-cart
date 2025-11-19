<?php

namespace FluentCart\App\Modules\PaymentMethods\PayPalGateway;

use FluentCart\Api\CurrencySettings;
use FluentCart\Api\Orders;
use FluentCart\App\App;
use FluentCart\App\Helpers\CartCheckoutHelper;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Hooks\Cart\WebCheckoutHandler;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\API;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\Webhook;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class PayPal extends AbstractPaymentGateway
{

    private $methodSlug = 'paypal';

    public array $supportedFeatures = ['payment', 'refund', 'webhook', 'custom_payment', 'card_update', 'switch_payment_method', 'dispute_handler', 'subscriptions'];


    public function __construct()
    {
        parent::__construct(
            new PayPalSettingsBase(),
            new PayPalSubscriptions()
        );

        add_filter('fluent_cart/payment_methods_with_custom_checkout_buttons', function ($methods) {
            $methods[] = 'paypal';
            return $methods;
        });
    }

    public function meta(): array
    {
        return [
            'title'       => 'PayPal',
            'route'       => 'paypal',
            'slug'        => 'paypal',
            'label'       => 'PayPal',
            'description' => __('PayPal is the faster, safer way to send and receive money or make an online payment. Get started or create a merchant account to accept payments.', 'fluent-cart'),
            'logo'        => Vite::getAssetUrl("images/payment-methods/paypal-icon.svg"),
            'icon'        => Vite::getAssetUrl("images/payment-methods/paypal-icon.svg"),
            'brand_color' => '#4f94d4',
            'status'      => $this->settings->get('is_active') === 'yes',
            'upcoming'    => false,
            'supported_features' => $this->supportedFeatures
        ];
    }

    public function boot()
    {
        (new IPN())->init();

        add_action('wp_ajax_nopriv_fluent_cart_confirm_paypal_payment', [$this, 'confirmPayPalSinglePayment']);
        add_action('wp_ajax_fluent_cart_confirm_paypal_payment', [$this, 'confirmPayPalSinglePayment']);

        add_action('wp_ajax_nopriv_fluent_cart_confirm_paypal_subscription', [$this, 'confirmPayPalSubscription']);
        add_action('wp_ajax_fluent_cart_confirm_paypal_subscription', [$this, 'confirmPayPalSubscription']);

        add_filter('fluent_cart/payment_methods/paypal_client_id', [$this, 'getClientId'], 10, 2);

        // add PayPal partner tags
        add_filter('script_loader_tag', function ($tag, $handle) {
            if ($handle === 'fluent-cart-checkout-sdk-paypal') {
                $tag = str_replace(
                    '<script ',
                    '<script data-partner-attribution-id="FLUENTCART_SP_PPCP" ', $tag
                );
            }
            return $tag;
        }, 1, 2);

    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        if ($paymentInstance->subscription) {
            return (new Processor())->handleSubscriptionPaymentFromPaymentInstance($paymentInstance, []);
        }

        return (new Processor())->handleSinglePayment($paymentInstance, []);
    }

    public function confirmPayPalSinglePayment()
    {
        if (empty(App::request()->get('payId')) || empty(App::request()->get('ref_id'))) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('No payId ID!', 'fluent-cart')
            ], 422);
        }

        $payPalReferenceId = sanitize_text_field(App::request()->get('payId'));
        $transactionHash = sanitize_text_field(App::request()->get('ref_id'));

        $payment_intent = API::verifyPayment($payPalReferenceId);

        if (is_wp_error($payment_intent)) {
            wp_send_json([
                'status'  => 'failed',
                'message' => $payment_intent->get_error_message(),
            ], 422);
        }

        $transaction = null;

        $intendedTransactionHash = Arr::get($payment_intent, 'purchase_units.0.reference_id', '');
        if ($intendedTransactionHash) {
            $transaction = OrderTransaction::query()
                ->where('uuid', $intendedTransactionHash)
                ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
                ->first();
        }

        if (!$transaction) {
            $transaction = OrderTransaction::query()
                ->where('uuid', $transactionHash)
                ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
                ->first();
        }

        if (!$transaction) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Transaction not found!', 'fluent-cart')
            ], 423);
        }

        $isPaid = Arr::get($payment_intent, 'status') === 'COMPLETED' || Arr::get($payment_intent, 'status') === 'APPROVED';

        if (!$isPaid) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Payment not completed!', 'fluent-cart')
            ], 422);
        }

        $paidAmount = 0;
        foreach (Arr::get($payment_intent, 'purchase_units', []) as $unit) {
            $paidAmount += Helper::toCent(Arr::get($unit, 'amount.value', 0));
        }

        if ($paidAmount != $transaction->total) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Paid amount does not match with transaction amount!', 'fluent-cart')
            ], 422);
        }

        $chargeId = Arr::get($payment_intent, 'purchase_units.0.payments.captures.0.id', '');

        // All Verified! Let's update the transaction and order
        (new Processor())->confirmPaymentSuccessByCharge($transaction, [
            'vendor_charge_id'    => $chargeId,
            'status'              => Status::TRANSACTION_SUCCEEDED,
            'total'               => $paidAmount,
            'payment_method_type' => 'PayPal',
            'meta'                => [
                'payer' => Arr::get($payment_intent, 'payer', [])
            ],
            'payment_source'      => Arr::get($payment_intent, 'payment_source', []),
        ]);


        wp_send_json([
            'status'       => 'success',
            'redirect_url' => $transaction->getReceiptPageUrl(true),
            'order'        => [
                'uuid' => $transaction->order->uuid
            ],
            'message'      => __('Payment has been paid successfully! Redirecting...', 'fluent-cart')
        ]);
    }

    public function confirmPayPalSubscription()
    {
        if (empty(App::request()->get('subscription_id')) || empty(App::request()->get('ref_id'))) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('No Subscription ID!', 'fluent-cart')
            ], 423);
        }

        $subscriptionId = sanitize_text_field(App::request()->get('subscription_id'));

        $paypalSubscription = API::getResource('billing/subscriptions/' . $subscriptionId);

        if (is_wp_error($paypalSubscription)) {
            wp_send_json([
                'message' => $paypalSubscription->get_error_message(),
                'status'  => 'failed',
            ], 422);
        }


        $status = Arr::get($paypalSubscription, 'status', '');

        if ($status != 'ACTIVE') {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Subscription is not active', 'fluent-cart')
            ], 423);
        }

        $transaction = OrderTransaction::query()->where('uuid', sanitize_text_field(App::request()->get('ref_id')))->first();

        if (!$transaction) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Transaction not found!', 'fluent-cart')
            ], 404);
        }

        $subscriptionModel = (new Processor())->activateSubscription($paypalSubscription, $transaction);

        wp_send_json([
            'status'       => 'success',
            'message'      => __('Subscription has been activated successfully!', 'fluent-cart'),
            'redirect_url' => $transaction->getReceiptPageUrl(true),
            'order'        => [
                'uuid' => $transaction->order->uuid
            ],
        ], 200);
    }

    public function getClientId($value, $args)
    {
        return $this->settings->getPublicKey();
    }

    public function handleIPN()
    {
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] != 'POST') {
            return;
        }

        (new IPN())->processWebhook();
        exit(200);
    }

    public function getTransactionUrl($url, $data)
    {
        if (Arr::get($data, 'payment_mode') === 'test') {
            return 'https://www.sandbox.paypal.com/activity/payment/' . Arr::get($data, 'vendor_charge_id');
        }

        return 'https://www.paypal.com/activity/payment/' . Arr::get($data, 'vendor_charge_id');
    }

    public function appAuthenticator($request)
    {
        ConnectConfig::parseConnectInfos($request);
    }

    public function getSubscriptionUrl($url, $data)
    {
        if (Arr::get($data, 'payment_mode') === 'test') {
            return 'https://www.sandbox.paypal.com/billing/subscriptions/' . Arr::get($data, 'vendor_subscription_id');
        }

        return 'https://www.paypal.com/billing/subscriptions' . Arr::get($data, 'vendor_subscription_id');
    }

    public static function beforeSettingsUpdate($data, $oldSettings): array
    {
        if (Arr::get($data, 'payment_mode') === 'live') {
            $data['live_client_secret'] = Helper::encryptKey($data['live_client_secret']);
        } else {
            $data['test_client_secret'] = Helper::encryptKey($data['test_client_secret']);
        }

        if (isset($data['define_test_keys'])) {
            unset($data['define_test_keys']);
        }
        if (isset($data['define_live_keys'])) {
            unset($data['define_live_keys']);
        }
        //clean existing access token if exist, fix for: api key change authentication error
        fluent_cart_update_option('_paypal_access_token_' . Arr::get($data, 'payment_mode'), []);

        return $data;
    }

    public function isEnabled(): bool
    {
        return $this->settings->isActive();
    }

    /**
     * Connect configuration should return
     */
    public function getConnectInfo()
    {
        return ConnectConfig::getConnectConfig();
    }

    public function disconnect($data)
    {
        return ConnectConfig::disconnect($data);
    }

    public function getWebhookInfo($mode = 'test')
    {
        $webhookId = $this->settings->get($mode . '_webhook_id');
        $webhookEvents = $this->settings->get($mode . '_webhook_events');

        if (!$webhookId || !$webhookEvents) {
            return false;
        }

        /**
         * return string
         * webhook url also in code formatted and add copy button
         * webhook id
         * webhook events (list of events, and every list item should be code formatted), if not empty
         * $webhookUrl = home_url('/wp-json/fluent-cart/v2/webhook?fct_payment_listener=1&method=paypal')
         */

        $webhookInfo = '';
        if ($webhookId) {
            $webhookInfo .= '<p><b>' . __('Webhook (No further setup needed) :', 'fluent-cart') . '</b><span style="color:green;">Your webhook <code class="copyable-content">' . $webhookId . '</code> is connected!</span> </p>';
        }
        if ($webhookEvents) {
            $webhookInfo .= '<p>' . __('and now watching for Webhook Events listed bellow:', 'fluent-cart') . '</p><p style="word-wrap: break-word;
                font-size: 12px;" class="copyable-content">';
            foreach ($webhookEvents as $event) {
                $webhookInfo .= $event['name'] . ' | ';
            }
            $webhookInfo .= '</p>';
        }

        return $webhookInfo;
    }

    public function fields()
    {
        $testSchema = [
            'webhook_instruction' => [
                'value' => Webhook::webhookInstruction(),
                'label' => __('Webhook Setup', 'fluent-cart'),
                'type'  => 'html_attr'
            ],
            'test_webhook_id'     => [
                'value'       => '',
                'placeholder' => 'Webhook ID',
                'required'    => true,
                'label'       => __('Test Webhook ID (Copy the webhook id and paste bellow)', 'fluent-cart'),
                'type'        => 'text'
            ],
        ];

        $liveSchema = [
            'webhook_instruction' => [
                'value' => Webhook::webhookInstruction(),
                'label' => __('Webhook Setup', 'fluent-cart'),
                'type'  => 'html_attr'
            ],
            'live_webhook_id'     => [
                'value'       => '',
                'placeholder' => 'Webhook ID',
                'required'    => true,
                'label'       => __('Live Webhook ID (Copy the webhook id and paste bellow)', 'fluent-cart'),
                'type'        => 'text'
            ],
        ];

        // if not defined property then no need to show webhook instruction
        if ($this->settings->getProviderType() !== 'api_keys') {
            $testSchema = [];
            $liveSchema = [];
        }

        $payPalFields = array(
            'notice'            => [
                'value' => $this->renderStoreModeNotice(),
                'label' => __('PayPal', 'fluent-cart'),
                'type'  => 'notice'
            ],
            'payment_mode'      => [
                'type'   => 'tabs',
                'schema' => [
                    [
                        'type'   => 'tab',
                        'label'  => __('Live credentials', 'fluent-cart'),
                        'value'  => 'live',
                        'schema' => $liveSchema
                    ],
                    [
                        'type'   => 'tab',
                        'label'  => __('Test credentials', 'fluent-cart'),
                        'value'  => 'test',
                        'schema' => $testSchema
                    ]
                ]
            ],
            'provider'          => array(
                'value' => $this->settings->getProviderType(),
                'label' => __('Provider', 'fluent-cart'),
                'type'  => 'provider'
            ),
            'webhook_info_test' => array(
                'info' => $this->getWebhookInfo('test'),
                'label' => __('Webhook Info', 'fluent-cart'),
                'type' => 'webhook_info',
                'mode' => 'test'
            ),
            'webhook_info_live' => array(
                'info'  => $this->getWebhookInfo('live'),
                'label' => __('Webhook Info', 'fluent-cart'),
                'type'  => 'webhook_info',
                'mode'  => 'live'
            ),
            'is_pro_item'       => array(
                'value' => 'no',
                'label' => __('PayPal', 'fluent-cart'),
                'type'  => 'validate'
            ),
        );

        return $payPalFields;
    }

    public function webHookPaymentMethodName()
    {
        return $this->methodSlug;
    }

    public static function validateSettings($data): array
    {
        $mode = Arr::get($data, 'payment_mode', 'test');
        $provider = Arr::get($data, 'provider', 'connect');

        if ($provider === 'api_keys') {
            if ($mode === 'live') {
                $clientId = defined('FCT_PAYPAL_LIVE_PUBLIC_KEY') ? FCT_PAYPAL_LIVE_PUBLIC_KEY : Arr::get($data, 'live_client_id');
                $clientSecret = defined('FCT_PAYPAL_LIVE_SECRET_KEY') ? FCT_PAYPAL_LIVE_SECRET_KEY : Arr::get($data, 'live_client_secret');
            } else {
                $clientId = defined('FCT_PAYPAL_TEST_PUBLIC_KEY') ? FCT_PAYPAL_TEST_PUBLIC_KEY : Arr::get($data, 'test_client_id');
                $clientSecret = defined('FCT_PAYPAL_TEST_SECRET_KEY') ? FCT_PAYPAL_TEST_SECRET_KEY : Arr::get($data, 'test_client_secret');
            }

            return static::validateApiCredentials($clientId, $clientSecret, $mode);

        }

        $clientId = Arr::get($data, "{$mode}_client_id");
        $clientSecret = Arr::get($data, "{$mode}_client_secret");

        if (!$clientId || !$clientSecret) {
            return [
                'status'  => 'failed',
                'message' => $mode === 'live' ? __('PayPal live credentials is required!', 'fluent-cart') : __('PayPal test credentials is required!!', 'fluent-cart'),
            ];
        }

        return [
            'status'  => 'success',
            'message' => __('Credentials are valid!', 'fluent-cart')
        ];

    }

    private static function validateApiCredentials($clientId, $clientSecret, $mode): array
    {
        $result = API::validateCredentials($clientId, $clientSecret, $mode);

        if (is_wp_error($result)) {
            return [
                'status'  => 'failed',
                'message' => $result->get_error_message()
            ];
        }

        return [
            'status'  => 'success',
            'message' => __('Credentials are valid!', 'fluent-cart')
        ];

    }

    /*
     * Default sdk enqueue version is the plugin version
     * if any sdk require a specific version, then override this method
     * or to remove a version, return null
     */
    public function getEnqueueVersion()
    {
        return null;
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        if ($this->settings->get('checkout_mode') !== 'paypal_pro') {
            return [];
        }

        $clientId = $this->settings->getPublicKey();
        $clientId = sanitize_text_field($clientId);

        $sdkSrc = 'https://www.paypal.com/sdk/js?client-id=' . $clientId;

        if ('yes' == $hasSubscription) {
            $sdkSrc = add_query_arg(array('vault' => 'true', 'intent' => 'subscription'), $sdkSrc);
        } else {
            $sdkSrc = add_query_arg(array('currency' => strtoupper(CurrencySettings::get('currency')), 'intent' => 'capture'), $sdkSrc);
        }
        $sdkSrc = apply_filters('fluent_cart/payments/paypal_sdk_src', $sdkSrc, []);

        return [
            [
                'handle' => 'fluent-cart-checkout-sdk-paypal',
                'src'    => $sdkSrc,
            ],
            [
                'handle' => 'fluent-cart-checkout-handler-paypal',
                'src'    => Vite::getEnqueuePath('public/payment-methods/paypal-checkout.js'),
                'deps'   => ['fluent-cart-checkout-sdk-paypal']
            ]
        ];
    }

    public function getLocalizeData(): array
    {
        return [
            'fct_paypal_data' => [
                'translations' => [
                    'uuid not found' => __('uuid not found', 'fluent-cart'),
                    'Choose any option to continue' => __('Choose any option to continue', 'fluent-cart'),
                    'An unknown error occurred' => __('An unknown error occurred', 'fluent-cart'),
                    'An error occurred while loading PayPal.' => __('An error occurred while loading PayPal.', 'fluent-cart'),
                    'Loading Payment Processor...' => __('Loading Payment Processor...', 'fluent-cart'),
                    'Order creation failed' => __('Order creation failed', 'fluent-cart'),
                    'Not proper order handler' => __('Not proper order handler', 'fluent-cart'),
                    'No Subscription ID' => __('No Subscription ID', 'fluent-cart'),
                    'no processing' => __('no processing', 'fluent-cart'),
                    'not proper order handler' => __('not proper order handler', 'fluent-cart'),
                ]
            ]
        ];
    }

    public function processRefund($transaction, $amount, $args)
    {
        if (!$amount) {
            return new \WP_Error(
                'fluent_cart_stripe_refund_error',
                __('Refund amount is required.', 'fluent-cart')
            );
        }

        return PayPalHelper::processRemoteRefund($transaction, $amount, $args);
    }

    public function getOrderInfo($data)
    {
        $cart = CartHelper::getCart();
        $checkOutHelper =  CartCheckoutHelper::make();
        $shippingChargeData = (new WebCheckoutHandler())->getShippingChargeData($cart);
        $shippingCharge = Arr::get($shippingChargeData, 'charge');
        $totalPrice = $checkOutHelper->getItemsAmountTotal(false) + $shippingCharge;

        $tax = $checkOutHelper->getCart()->checkout_data['tax_data'] ?? [];
        if (Arr::get($tax, 'tax_behavior', 0) == 1) {
            $totalPrice = $totalPrice + Arr::get($tax, 'tax_total', 0) + Arr::get($tax, 'shipping_tax', 0);
        }

        $items = $checkOutHelper->getItems();
        $hasSubscription = $this->validateSubscriptions($items);

        $clientId = $this->settings->getPublicKey();

        if (empty($clientId)) {
            $message = __('Please provide a valid Client Id!', 'fluent-cart');
            fluent_cart_add_log('PayPal Credential Validation', $message, 'error', ['log_type' => 'payment']);
            wp_send_json([
                'status'  => 'failed',
                'message' => __('No valid Client ID found!', 'fluent-cart')
            ], 422);
        }

        $paymentArgs['public_key'] = $clientId;

        $paymentDetails = [
            'mode'     => 'payment',
            'amount'   => Helper::toDecimalWithoutComma($totalPrice),
            'currency' => strtoupper(CurrencySettings::get('currency')),
        ];

        if ($hasSubscription) {
            $paymentDetails['mode'] = 'subscription';
        }

        $this->checkCurrencySupport();

        wp_send_json(
            [
                'data'         => [],
                'payment_args' => $paymentArgs,
                'message'      => __('Order info retrieved!', 'fluent-cart'),
                'intent'       => $paymentDetails,
            ],
            200
        );

    }

    public function checkCurrencySupport()
    {
        $currency = CurrencySettings::get('currency');

        if (!in_array(strtoupper($currency), self::getPaypalSupportedCurrency())) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('PayPal does not support the currency you are using!', 'fluent-cart')
            ], 422);
        }
    }

    public function isCurrencySupported(): bool
    {
        $currency = CurrencySettings::get('currency');
        return in_array(strtoupper($currency), self::getPaypalSupportedCurrency());
    }

    public static function getPaypalSupportedCurrency(): array
    {
        return [
            'USD', 'EUR', 'GBP', 'AUD', 'CAD', 'JPY', 'NZD', 'CHF', 'HKD', 'SGD', 'SEK', 'DKK', 'PLN', 'NOK', 'HUF', 'CZK', 'ILS', 'MXN', 'MYR', 'BRL', 'PHP', 'TWD', 'THB'
        ];
    }

    public function acceptRemoteDispute($transaction, $args = [])
    {
        $disputeId = Arr::get($transaction->meta, 'dispute_id');
        $dispute = (new API())->getResource('customer/disputes/'. $disputeId);

        if (!$disputeId) {
            $dispute = (new API())->getResource('customer/disputes/'. $disputeId);

            if (is_wp_error($dispute) || empty($dispute['dispute_id'])) {
                new \WP_Error('No dispute ID found!', __('Please check PayPal if the dispute is already accepted or not!', 'fluent-cart'));
            }

            $disputeId = Arr::get($dispute, 'dispute_id');
        }

        $note = Arr::get($args, 'dispute_note', 'Accepted full dispute claim!'); 

        $closeDispute = (new API())->createResource('customer/disputes/' . $disputeId . '/accept-claim', ['note' => $note]);

        if (is_wp_error($closeDispute)) {
            return $closeDispute;
        }

        return $closeDispute;
    }

}

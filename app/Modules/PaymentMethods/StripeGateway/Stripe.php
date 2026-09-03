<?php

namespace FluentCart\App\Modules\PaymentMethods\StripeGateway;

use FluentCart\Api\CurrencySettings;
use FluentCart\Api\Orders;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\CartCheckoutHelper;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\CurrenciesHelper;
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

    public array $supportedFeatures = ['payment', 'refund', 'webhook', 'custom_payment', 'card_update', 'switch_payment_method' => [
        'supported_gateways' => ['stripe', 'paypal'],
    ], 'dispute_handler', 'subscriptions', 'zero_recurring', 'system_subscription', 'manual_subscription', 'verify_vendor_ids'];

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
            'title'              => __('Card', 'fluent-cart'),
            'route'              => 'stripe',
            'slug'               => 'stripe',
            'label'              => 'Stripe',
            'admin_title'        => 'Stripe',
            'description'        => __("Stripe's payments platform lets you accept credit cards, debit cards, and popular payment methods around the world all with a single integration.", "fluent-cart"),
            'logo'               => Vite::getAssetUrl('images/payment-methods/card.svg'),
            'icon'               => Vite::getAssetUrl('images/payment-methods/stripe-icon.svg'),
            'status'             => $this->settings->get('is_active') === 'yes',
            'brand_color'        => '#635bff',
            'upcoming'           => false,
            'supported_features' => $this->supportedFeatures
        ];
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        $order = $paymentInstance->order;

        $storeName = (new StoreSettings())->get('store_name');

        $transactionCurrency = $paymentInstance->transaction->currency;
        $chargeAmount = (int)$paymentInstance->transaction->total;

        if ($transactionCurrency && CurrenciesHelper::isZeroDecimal($transactionCurrency)) {
            $chargeAmount = (int)round($chargeAmount / 100);
        }

        $paymentArgs = array(
            'client_reference_id' => $order->uuid,
            'amount'              => $chargeAmount,
            'currency'            => strtolower($transactionCurrency),
            'description'         => $storeName . ' #' . $order->invoice_no, // @todo: We will replace with order summary with item names later
            'customer_email'      => $paymentInstance->order->email,
            'success_url'         => $paymentInstance->transaction->getSuccessUrl(),
            'gateway_return_url'  => Processor::getOnsiteGatewayReturnUrl($paymentInstance->transaction),
            'custom_payment_url'  => PaymentHelper::getCustomPaymentLink($paymentInstance->order->uuid)
        );

        if ($paymentInstance->subscription) {
            $subscription = $paymentInstance->subscription;

            // Store-managed mode: charge the first order / renewal invoice one-time.
            // No Stripe subscription object, no manual→automatic conversion — the
            // invoice engine owns all future renewals.
            if ($this->shouldChargeSubscriptionAsOneTime($paymentInstance)) {
                // System subscriptions save the payment method for off-session
                // auto-charging of future renewal invoices (consent shown at checkout).
                if ($subscription->collection_method === 'system') {
                    // Nothing payable now (free trial): a $0 PaymentIntent is invalid —
                    // save the card via a SetupIntent instead. The trial-end invoice is
                    // then charged off-session like any other system renewal. Hosted mode
                    // never loads Stripe.js/Elements, so it needs a redirect-based
                    // Checkout Session (mode: setup) instead of a client-side SetupIntent.
                    if ((int) $paymentInstance->transaction->total <= 0) {
                        $checkoutMode = $this->settings->get('checkout_mode') ?? 'onsite';
                        if ($checkoutMode === 'hosted') {
                            return (new Processor())->handleHostedSetupOnlyCheckout($paymentInstance, $paymentArgs);
                        }

                        return (new Processor())->handleSetupOnlyPayment($paymentInstance, $paymentArgs);
                    }

                    $paymentArgs['setup_future_usage'] = 'off_session';
                }

                return (new Processor())->handleSinglePayment($paymentInstance, $paymentArgs);
            }

            if ($subscription->collection_method === 'manual') {
                $previousPaymentMethod = $subscription->current_payment_method;
                $conversionResult = $this->convertManualSubscription($paymentInstance, $paymentArgs);
                if (is_wp_error($conversionResult)) {
                    return $conversionResult;
                }
                
                $result = (new Processor())->handleSubscription($paymentInstance, $paymentArgs);

                if (is_wp_error($result)) {
                    $subscription->update([
                        'collection_method'      => 'manual',
                        'current_payment_method' => $previousPaymentMethod,
                    ]);
                } else {
                    $subscription->addLog(
                        'Converted to automatic billing',
                        sprintf('Subscription converted from manual to automatic billing via %s', 'Stripe'),
                        'info'
                    );
                    do_action('fluent_cart/subscription_converted_to_automatic', [
                        'subscription'   => $subscription,
                        'payment_method' => 'stripe',
                    ]);
                }
                return $result;
            }

            return (new Processor())->handleSubscription($paymentInstance, $paymentArgs);
        }

        return (new Processor())->handleSinglePayment($paymentInstance, $paymentArgs);
    }

    public function convertManualSubscription($paymentInstance, $paymentArgs)
    {
        $subscription = $paymentInstance->subscription;

        if (!$subscription || $subscription->collection_method !== 'manual') {
            return new \WP_Error('invalid_subscription', __('Subscription is not manual or does not exist', 'fluent-cart'));
        }

        if (in_array($subscription->status, ['completed'])) {
            return new \WP_Error('subscription_invalid_status', __('Cannot convert completed subscriptions', 'fluent-cart'));
        }

        $subscription->collection_method = 'automatic';
        $subscription->current_payment_method = 'stripe';
        $subscription->save();

        return true;
    }

    /**
     * Stripe can vault a card without charging — the onsite Elements flow uses a
     * client-side SetupIntent, hosted mode redirects to a Checkout Session in
     * `mode: setup` (Processor::handleHostedSetupOnlyCheckout()).
     */
    public function supportsSetupWithoutCharge(): bool
    {
        return true;
    }

    /**
     * Off-session charge of a system subscription's renewal invoice using the
     * stored token. Success flows through confirmPaymentSuccessByCharge so the
     * normal renewal-paid path (syncOrderStatuses / handleRenewalPaid) runs.
     *
     * @param PaymentInstance $paymentInstance
     * @param array $args ['attempt' => int]
     * @return true|'processing'|\WP_Error true = confirmed; 'processing' = charge
     *                                     accepted, webhook will confirm
     */
    public function chargeRenewal(PaymentInstance $paymentInstance, $args = [])
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $subscription = $paymentInstance->subscription;

        if (!$order || !$transaction || !$subscription) {
            return new \WP_Error('invalid_instance', __('Renewal invoice is missing its order, transaction, or subscription.', 'fluent-cart'));
        }

        $customerId = $subscription->vendor_customer_id;

        // Token read AT FIRE TIME — never snapshotted. The meta has two shapes in
        // the wild: vendor_method_id (confirmation paths) and
        // details.payment_method_id (card-switch flow) — accept both.
        $paymentMethodMeta = $subscription->getMeta('active_payment_method', []) ?: [];
        $token = Arr::get($paymentMethodMeta, 'vendor_method_id') ?: Arr::get($paymentMethodMeta, 'details.payment_method_id');

        if (!$customerId || !$token) {
            return new \WP_Error('missing_token', __('No saved payment method is available for this subscription.', 'fluent-cart'));
        }

        // The saved customer + payment method were created in the order's Stripe
        // mode; charging them requires that mode's secret key. If it is missing
        // (e.g. a live-mode order on a store configured with test keys only, common
        // on staging clones), fail with a clear message rather than sending an empty
        // Authorization header to Stripe.
        if ($keyError = $this->guardSecretKeyForMode($order->mode)) {
            return $keyError;
        }

        $chargeAmount = (int) $transaction->total;
        if ($transaction->currency && CurrenciesHelper::isZeroDecimal($transaction->currency)) {
            $chargeAmount = (int) round($chargeAmount / 100);
        }

        $intentData = [
            'amount'         => $chargeAmount,
            'currency'       => strtolower($transaction->currency),
            'customer'       => $customerId,
            'payment_method' => $token,
            'off_session'    => 'true',
            'confirm'        => 'true',
            'expand'         => ['latest_charge'],
            'metadata'       => apply_filters('fluent_cart/payments/stripe_metadata_onetime', [
                'fct_ref_id'      => $order->uuid,
                'Name'            => $order->customer ? $order->customer->full_name : '',
                'Email'           => $order->customer ? $order->customer->email : '',
                'order_reference' => 'fct_order_id_' . $order->id,
            ], [
                'order'       => $order,
                'transaction' => $transaction
            ]),
        ];

        $attempt = max(1, (int) Arr::get($args, 'attempt', 1));

        $intent = (new API())->createStripeObject('payment_intents', $intentData, $order->mode, [
            'Idempotency-Key' => 'fct_system_charge_' . $order->uuid . '_' . $attempt,
        ]);

        if (is_wp_error($intent)) {
            return $intent;
        }

        $intentStatus = Arr::get($intent, 'status');

        if ($intentStatus === 'succeeded') {
            $transaction->update(['vendor_charge_id' => Arr::get($intent, 'id')]);

            (new Confirmations())->confirmPaymentSuccessByCharge($transaction, [
                'charge'    => Arr::get($intent, 'latest_charge', []),
                'intent_id' => Arr::get($intent, 'id'),
            ]);

            return true;
        }

        if ($intentStatus === 'processing') {
            // Charge accepted but still settling (e.g. bank debits) — the webhook
            // confirms it; keep the invoice scheduled rather than failing it. The
            // distinct return keeps the success contract honest: the service fires
            // system_charge_succeeded only once the payment is actually confirmed.
            $transaction->update(['vendor_charge_id' => Arr::get($intent, 'id')]);
            return 'processing';
        }

        // requires_action (off-session SCA challenge), declines, and anything else:
        // the customer must pay interactively — surface the gateway's reason.
        $failureMessage = Arr::get($intent, 'last_payment_error.message');
        if (!$failureMessage) {
            $failureMessage = sprintf(
            /* translators: %1$s: Stripe payment intent status */
                __('Automatic charge could not be completed (status: %1$s).', 'fluent-cart'),
                $intentStatus ?: 'unknown'
            );
        }

        return new \WP_Error('charge_failed', $failureMessage);
    }

    /**
     * Re-check a processing off-session renewal charge. Recovers missed webhooks:
     * a settled intent is confirmed through confirmPaymentSuccessByCharge.
     *
     * @param PaymentInstance $paymentInstance
     * @return true|'processing'|\WP_Error
     */
    public function reconcileRenewalCharge(PaymentInstance $paymentInstance)
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;

        if (!$order || !$transaction || !$transaction->vendor_charge_id) {
            return new \WP_Error('missing_intent', __('No payment intent is recorded for this renewal order.', 'fluent-cart'));
        }

        // A missing key is a configuration problem, not a transient API failure —
        // surface it (the transient handling below would otherwise loop forever).
        if ($keyError = $this->guardSecretKeyForMode($order->mode)) {
            return $keyError;
        }

        $intent = (new API())->getStripeObject('payment_intents/' . $transaction->vendor_charge_id, [
            'expand' => ['latest_charge']
        ], $order->mode);

        if (is_wp_error($intent)) {
            // Transient API failure must not fail a possibly-settled payment —
            // report still-processing so the reconciliation loop retries later.
            return 'processing';
        }

        $intentStatus = Arr::get($intent, 'status');

        if ($intentStatus === 'succeeded') {
            (new Confirmations())->confirmPaymentSuccessByCharge($transaction, [
                'charge'    => Arr::get($intent, 'latest_charge', []),
                'intent_id' => Arr::get($intent, 'id'),
            ]);

            return true;
        }

        if ($intentStatus === 'processing') {
            return 'processing';
        }

        $failureMessage = Arr::get($intent, 'last_payment_error.message');
        if (!$failureMessage) {
            $failureMessage = sprintf(
            /* translators: %1$s: Stripe payment intent status */
                __('The pending payment could not be completed (status: %1$s).', 'fluent-cart'),
                $intentStatus ?: 'unknown'
            );
        }

        return new \WP_Error('charge_failed', $failureMessage);
    }

    public function syncRemoteTransaction(\FluentCart\App\Models\OrderTransaction $transaction)
    {
        return (new Confirmations())->syncRemoteTransaction($transaction);
    }

    /**
     * Ensure the Stripe secret key for the given order mode is configured before an
     * off-session charge / reconcile. Returns a clear WP_Error when it is missing —
     * otherwise Stripe replies with the opaque "You did not provide an API key"
     * message. Null when the key is present.
     *
     * @param string $mode The order's Stripe mode ('test' | 'live').
     * @return \WP_Error|null
     */
    private function guardSecretKeyForMode($mode)
    {
        if ((new StripeSettingsBase())->getApiKey($mode ?: 'current')) {
            return null;
        }

        return new \WP_Error('stripe_missing_api_key', sprintf(
        /* translators: %1$s: Stripe mode (test or live) */
            __('This subscription was created in %1$s mode, but no Stripe %1$s secret key is configured for this store. Add the matching Stripe keys in Payment Settings to charge the saved payment method.', 'fluent-cart'),
            $mode ?: 'current'
        ));
    }

    private function shouldRenderAsSubscriptionMode($hasSubscription): bool
    {
        // One-time-charged subscription payments (store-managed mode, or a renewal of
        // a store-managed-born subscription) go through handleSinglePayment, so
        // Elements must initialize with intent mode `payment`, not `subscription`.
        if (\FluentCart\App\Modules\Subscriptions\Services\SubscriptionManagementMode::currentCheckoutChargesOneTime()) {
            return false;
        }

        return $hasSubscription;
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
        $checkoutMode = $this->settings->get('checkout_mode') ?? 'onsite';
        
        if ($checkoutMode == 'hosted') {
            return [
                [
                    'handle' => 'fluent-cart-checkout-handler-stripe-hosted',
                    'src'    => Vite::getEnqueuePath('public/payment-methods/stripe-hosted-checkout.js'),
                ]
            ];
        }

        // For embedded/onsite mode, load Stripe SDK and full handler
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

    private function getStripeLocale(): string
    {
        $parts = explode('_', get_locale());
        $lang = strtolower($parts[0]);
        $region = isset($parts[1]) ? strtoupper($parts[1]) : '';

        if ($region) {
            $full = $lang . '-' . $region;
            if (in_array($full, ['en-GB', 'fr-CA', 'zh-HK', 'zh-TW', 'pt-BR', 'es-419'])) {
                return $full;
            }
        }

        return $lang ?: 'auto';
    }

    public function getLocalizeData(): array
    {        
        return [
            'fct_stripe_data' => [
                'locale'       => $this->getStripeLocale(),
                'translations' => [
                    'Payment module not available to checkout! Please reload again, or contact admin!' => __('Payment module not available to checkout! Please reload again, or contact admin!', 'fluent-cart'),
                    'See Errors' => __('See Errors', 'fluent-cart'),
                    'Pay Now' => __('Pay Now', 'fluent-cart'),
                    'Place Order' => __('Place Order', 'fluent-cart'),
                    'Card details are not valid!' => __('Card details are not valid!', 'fluent-cart'),
                    'Total amount is not valid, please add some items to cart!' => __('Total amount is not valid, please add some items to cart!', 'fluent-cart'),
                    'An error occurred while parsing the response.' => __('An error occurred while parsing the response.', 'fluent-cart'),
                    'An error occurred while loading the payment method.' => __('An error occurred while loading the payment method.', 'fluent-cart'),
                    'Loading Payment Processor...' => __('Loading Payment Processor...', 'fluent-cart'),
                    'redirecting for action' => __('redirecting for action', 'fluent-cart'),
                    'You will be redirected to Stripe to complete your payment securely.' => __('You will be redirected to Stripe to complete your payment securely.', 'fluent-cart'),
                    'Something went wrong' => __('Something went wrong', 'fluent-cart'),
                    'Payment confirmation failed' => __('Payment confirmation failed', 'fluent-cart'),
                ]
            ]
        ];
    }

    public static function beforeSettingsUpdate($data, $oldSettings): array
    {
        $provider = Arr::get($data, 'provider', 'connect');
        $mode = Arr::get($data, 'payment_mode', 'test');

        if ('connect' == $provider) {
            $currentKey = Arr::get($data, $mode . '_secret_key', '');
            $oldKey = Arr::get($oldSettings, $mode . '_secret_key', '');

            if ($currentKey !== $oldKey) {
                $data[$mode . '_secret_key'] = Helper::encryptKey($currentKey);
            }
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
                $errorMessage = $mode === 'live' ? __('Stripe not connected in live mode!', 'fluent-cart') : __('Stripe not connected in test mode!', 'fluent-cart');
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
        $disabled = false;
        $providerValue = apply_filters('fluent_cart/form_disable_stripe_connect', $disabled, []) ? 'api_keys' : 'connect';

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
                'value' => $providerValue,
                'label' => __('Provider', 'fluent-cart'),
                'type'  => 'provider'
            ),
            'setup_guide'         => array(
                'value'   => '<h4>' . __('Or Setup keys manually.', 'fluent-cart') . '</h4><hr/>',
                'label'   => __('Or Setup keys manually', 'fluent-cart'),
                'type'    => 'html_attr',
                'visible' => 'no'
            ),
            'checkout_mode'       => array(
                'value'   => 'onsite',
                'label'   => __('Checkout Mode', 'fluent-cart'),
                'type'    => 'radio',
                'options' => [
                    'onsite' => [
                        'label' => __('Embedded checkout (Recommended)', 'fluent-cart'),
                        'text'  => __('Renders inside your checkout page. Supports all standard card payments with a seamless branded experience.', 'fluent-cart'),
                        'icon'  => Vite::getAssetUrl('images/bill-line.svg')
                    ],
                    'hosted' => [
                        'label' => __('Stripe Hosted Checkout', 'fluent-cart'),
                        'text'  => __("Redirects to Stripe's hosted page. Required for Bank Transfers and methods not supported in embedded mode.", 'fluent-cart'),
                        'icon'  => Vite::getAssetUrl('images/external-link-line.svg')
                    ]
                ],
                'tooltip' => __('Choose between Embedded and Hosted checkout modes. Embedded mode is recommended for most use cases. For Bank transfers, use Hosted mode. (checkout.session.completed webhook event will be triggered only for hosted mode)', 'fluent-cart'),
                'description' => __("Embedded checkout is recommended for most use cases. For Bank transfers , or if any payment methods are not showing up on embedded checkout, try Hosted checkout. ('checkout.session.completed' webhook event will be triggered only for hosted checkout)", 'fluent-cart')
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
        // For hosted mode, we don't need to return intent data as checkout session is created on order placement
        
        /*
         * Filter the Stripe Elements appearance configuration
         * 
         * This filter allows developers to customize the appearance of Stripe Elements.
         * For example:
         * 
         * function stripe_appearance($appearance) {
         *     return array(
         *         'theme'  => 'night',
         *         'labels' => 'floating',
         *         'variables' => array(
         *             'colorPrimary' => '#0570de',
         *             'colorBackground' => '#ffffff',
         *             'colorText' => '#30313d',
         *             'colorDanger' => '#df1b41',
         *             'fontFamily' => 'Ideal Sans, system-ui, sans-serif',
         *             'spacingUnit' => '2px',
         *             'borderRadius' => '4px',
         *         )
         *     );
         * }
         * add_filter('fluent_cart/stripe_appearance', 'stripe_appearance', 10, 1);
         * 
         * @see https://docs.stripe.com/elements/appearance-api for all available options
         * @param array $appearance The appearance configuration
         * @return array The modified appearance configuration
         */
        if (($this->settings->get('checkout_mode') ?? 'onsite') == 'hosted') {
            // Same off-session consent contract as onsite checkout — the hosted
            // Checkout Session vaults the card via setup_future_usage too, so the
            // customer must see the same authorization copy before redirecting.
            $hasSubscription = $this->validateSubscriptions($this->getCheckoutItems());
            $systemConsent = '';
            $consentRequired = false;
            if (!$this->shouldRenderAsSubscriptionMode($hasSubscription)
                && \FluentCart\App\Modules\Subscriptions\Services\SubscriptionManagementMode::currentCheckoutIsSystem($this)
            ) {
                $systemConsent = __('Your payment method will be saved securely and charged automatically on each renewal date. You can update or replace it any time from your account.', 'fluent-cart');

                // Zero-payable (free trial): handleSetupOnlyPayment REQUIRES
                // _fct_system_consent=yes — same gate as the onsite setup-mode path.
                $cart = CartHelper::getCart();
                $payableNow = \FluentCart\App\Services\OrderService::getItemsAmountTotal($cart->cart_data ?? [], false, false);
                if ($payableNow <= 0) {
                    $consentRequired = true;
                }
            }

            wp_send_json(
                [
                    'status'       => 'success',
                    'message'      => __('Order info retrieved!', 'fluent-cart'),
                    'data'         => [],
                    'payment_args' => [
                        'checkout_mode' => 'hosted'
                    ],
                    'system_consent'    => $systemConsent,
                    'consent_required'  => $consentRequired,
                ],
                200
            );
        }

        $cart = CartHelper::getCart();
        $checkOutHelper = CartCheckoutHelper::make();
        $shippingChargeData = (new WebCheckoutHandler())->getShippingChargeData($cart);
        $shippingCharge = Arr::get($shippingChargeData, 'charge');
        $totalPrice = $checkOutHelper->getItemsAmountTotal(false) + $shippingCharge;

        $tax = $checkOutHelper->getCart()->checkout_data['tax_data'] ?? [];
        $taxBehavior = (int) Arr::get($tax, 'tax_behavior', 0);
        $storeTaxBehavior = (int) Arr::get($tax, 'store_tax_behavior', $taxBehavior);

        if ($taxBehavior === 1) {
            // Pure exclusive — add all tax including fee tax (tax_total contains both).
            $totalPrice = $totalPrice + (int) Arr::get($tax, 'tax_total', 0)
                                         + (int) Arr::get($tax, 'shipping_tax', 0);
        } elseif ($taxBehavior === 3) {
            // Mixed — add only exclusive product tax + fee/shipping if store is exclusive.
            $totalPrice = $totalPrice + (int) Arr::get($tax, 'exclusive_tax_total', 0);
            if ($storeTaxBehavior === 1) {
                $totalPrice = $totalPrice + (int) Arr::get($tax, 'fee_tax', 0)
                                     + (int) Arr::get($tax, 'shipping_tax', 0);
            }
        }

        $items = $this->getCheckoutItems();

        $hasSubscription = $this->validateSubscriptions($items);

        $stripeSettings = new StripeSettingsBase();
        $publicKey = $stripeSettings->getPublicKey();

        if (empty($publicKey)) {
            fluent_cart_add_log(
                'Stripe Credential Validation',
                sprintf('Stripe %s keys are missing or invalid.', $stripeSettings->getMode()),
                'error',
                ['log_type' => 'payment']
            );
            wp_send_json([
                'status'  => 'failed',
                'message' => __('No valid public key found! Please contact the site administrator.', 'fluent-cart')
            ], 422);
        }

        $paymentArgs['public_key'] = $publicKey;

        // Allow filtering the appearance configuration for Stripe Elements
        $appearance = ['theme' => 'stripe'];
        $appearance = apply_filters('fluent_cart/stripe_appearance', $appearance);

        $storeCurrency = CurrencySettings::get('currency');
        $intentAmount = (int)$totalPrice;

        if ($storeCurrency && CurrenciesHelper::isZeroDecimal($storeCurrency)) {
            $intentAmount = (int)($intentAmount / 100);
        }

        $intentData = [
            'mode'               => 'payment',
            'amount'             => $intentAmount,
            'currency'           => strtolower($storeCurrency),
            'automatic_payment_methods' => ['enabled' => true]
        ];

        // Determine if we should render as subscription mode
        // This considers global mode, auto-convert, and fallback settings
        $renderAsSubscription = $this->shouldRenderAsSubscriptionMode($hasSubscription);

        // System (auto-charged, store-billed) checkout: one-time payment intent that
        // also stores an off-session mandate, plus the save-and-auto-charge consent
        // notice rendered under the payment element.
        $systemConsent = '';
        $consentRequired = false;
        if (!$renderAsSubscription && \FluentCart\App\Modules\Subscriptions\Services\SubscriptionManagementMode::currentCheckoutIsSystem($this)) {
            $intentData['setup_future_usage'] = 'off_session';
            $systemConsent = __('Your payment method will be saved securely and charged automatically on each renewal date. You can update or replace it any time from your account.', 'fluent-cart');

            // Nothing payable now (free trial): Elements must initialize in SETUP
            // mode — a zero-amount payment mode is invalid — and consent becomes a
            // required checkbox: without a saved card the trial can never bill.
            $payableNow = \FluentCart\App\Services\OrderService::getItemsAmountTotal($cart->cart_data ?? [], false, false);
            if ($payableNow <= 0) {
                $intentData = [
                    'mode'     => 'setup',
                    'currency' => strtolower($storeCurrency),
                ];
                $consentRequired = true;
            }
        }

        if ($renderAsSubscription) {
            $intentData['mode'] = 'subscription';
            $intentData['setup_future_usage'] = 'off_session';
        } elseif (empty($intentData['setup_future_usage']) && Arr::get($data, 'save_payment_method') === 'yes') {
            $intentData['setup_future_usage'] = 'on_session';
        }

        // The browser cannot pass setup_future_usage per-request (this endpoint
        // receives no body), so extensions that vault cards (e.g. saved payment
        // methods) resolve it here, server-side. Must be matched on the actual
        // PaymentIntent at place-order (fluent_cart/payments/stripe_onetime_intent_args)
        // or Stripe rejects the confirmation for a setup_future_usage mismatch.
        $setupFutureUsage = apply_filters(
            'fluent_cart/stripe/client_setup_future_usage',
            Arr::get($intentData, 'setup_future_usage'),
            ['data' => $data, 'has_subscription' => $hasSubscription]
        );
        if ($setupFutureUsage) {
            $intentData['setup_future_usage'] = $setupFutureUsage;
        } else {
            unset($intentData['setup_future_usage']);
        }

        wp_send_json(
            [
                'status'         => 'success',
                'message'        => __('Order info retrieved!', 'fluent-cart'),
                'data'           => [],
                'payment_args'   => $paymentArgs,
                'intent'         => $intentData,
                'appearance'     => $appearance,
                'system_consent' => $systemConsent,
                'consent_required' => $consentRequired,
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

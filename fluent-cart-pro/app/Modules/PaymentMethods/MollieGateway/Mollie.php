<?php

namespace FluentCartPro\App\Modules\PaymentMethods\MollieGateway;

use FluentCart\Api\CurrencySettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCartPro\App\Modules\PaymentMethods\MollieGateway\API\MollieAPI;
use FluentCartPro\App\Modules\PaymentMethods\MollieGateway\Webhook\MollieIPN;
use FluentCartPro\App\Modules\PaymentMethods\MollieGateway\Confirmations;
use FluentCartPro\App\Utils\Enqueuer\Vite;

class Mollie extends AbstractPaymentGateway
{
    private $methodSlug = 'mollie';

    public array $supportedFeatures = [
        'payment',
        'refund',
        'webhook',
        'subscriptions',
        'upgrade_plan'
    ];

    public function __construct()
    {
        parent::__construct(new MollieSettingsBase(), new MollieSubscriptions());
    }

    public function meta(): array
    {

        $logo = Vite::getAssetUrl("images/payment-methods/mollie-logo.svg");
        
        return [
            'title'              => __('Mollie', 'fluent-cart-pro'),
            'route'              => $this->methodSlug,
            'slug'               => $this->methodSlug,
            'label'              => 'Mollie',
            'admin_title'        => 'Mollie',
            'description'        => __('Pay securely with Mollie - Credit Card, PayPal, SEPA, and more', 'fluent-cart-pro'),
            'logo'               => $logo,
            'icon'               => Vite::getAssetUrl("images/payment-methods/mollie-logo.svg"),
            'brand_color'        => '#5265e3',
            'status'             => $this->settings->get('is_active') === 'yes',
            'supported_features' => $this->supportedFeatures,
            'tag' => 'beta'
        ];
    }

    public function boot()
    {
        // Initialize IPN handler
        (new MollieIPN)->init();
        
        add_filter('fluent_cart/payment_methods/mollie_settings', [$this, 'getSettings'], 10, 2);

        (new Confirmations())->init();
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        $paymentArgs = [
            'success_url' => $this->getSuccessUrl($paymentInstance->transaction),
            'cancel_url' => $this->getCancelUrl(),
        ];

        if ($paymentInstance->subscription) {
            return (new MollieProcessor())->handleSubscription($paymentInstance, $paymentArgs);
        }

        return (new MollieProcessor())->handleSinglePayment($paymentInstance, $paymentArgs);
    }

    public function getOrderInfo($data)
    {

        $this->checkCurrencySupport();

        $mode = (new MollieSettingsBase())->getMode();
        $activeMethods = (new MollieAPI())->getActivatedPaymentMethodsConfigs(($mode));

        if (empty(Arr::get($activeMethods, 'activated_methods', []))) {
            $message = __('No Mollie payment methods are activated for Live mode. Please contact site owner.', 'fluent-cart-pro');

            if ($mode === 'test') {
                $message = __('No Mollie payment methods are activated for Test mode. Please contact site owner.', 'fluent-cart-pro');
            }
            wp_send_json([
                'status'  => 'failed',
                'message' => $message
            ], 422);
        }

        wp_send_json(
            [
                'status'       => 'success',
                'message'      => __('Order info retrieved!', 'fluent-cart-pro'),
                'data'         => [],
                'payment_args' => [
                    'activat_methods' => Arr::get($activeMethods, 'activated_methods', []),
                ],
            ],
            200
        );
    }

    public function checkCurrencySupport()
    {
        $currency = CurrencySettings::get('currency');

        if (!in_array(strtoupper($currency), self::getMollieSupportedCurrency())) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Mollie does not support the currency you are using!', 'fluent-cart')
            ], 422);
        }
    }

    public static function getMollieSupportedCurrency(): array
    {
        return [
            'AUD', 'BGN', 'BRL', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR',
            'GBP', 'HKD', 'HRK', 'HUF', 'ILS', 'JPY', 'MXN', 'MYR',
            'NOK', 'NZD', 'PHP', 'PLN', 'RON', 'SEK', 'SGD', 'USD'
        ];
    }

    public function handleIPN(): void
    {
        (new MollieIPN())->verifyAndProcess();
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        return [
            [
                'handle' => 'fluent-cart-pro-checkout-handler-mollie',
                'src'    => Vite::getEnqueuePath('public/payment-methods/mollie-checkout.js')
            ]
        ];
    }

    public function getEnqueueStyleSrc(): array
    {
        return [
        ];
    }

    public function getLocalizeData(): array
    {
        return [
            'fct_mollie_data' => [
                'translations' => [
                    'Redirecting to Mollie...' => __('Redirecting to Mollie...', 'fluent-cart-pro'),
                    'Pay Now' => __('Pay Now', 'fluent-cart-pro'),
                    'Place Order' => __('Place Order', 'fluent-cart-pro'),
                    'Loading payment methods...' => __('Loading payment methods...', 'fluent-cart-pro'),
                    'Available payment methods on Checkout' => __('Available payment methods on Checkout', 'fluent-cart-pro'),
                    'Pay securely with Mollie' => __('Pay securely with Mollie', 'fluent-cart-pro'),
                ]
            ]
        ];
    }

    public function webHookPaymentMethodName()
    {
        return $this->getMeta('route');
    }

    public function getTransactionUrl($url, $data): string
    {
        $transaction = Arr::get($data, 'transaction', null);
        if (!$transaction) {
            return 'https://www.mollie.com/dashboard/payments/';
        }

        $isLive = (new MollieSettingsBase())->getMode() === 'live';

        $paymentId = $transaction->vendor_charge_id;

        if ($transaction->status === 'refunded') {
            $parentTransaction = OrderTransaction::query()
                ->where('id', Arr::get($transaction->meta, 'parent_id'))
                ->first();
            if ($parentTransaction) {
                $paymentId = $parentTransaction->vendor_charge_id;
            } else {
                return 'https://www.mollie.com/dashboard/payments/';
            }
        }

        return MollieHelper::getTransactionUrl($paymentId, $isLive);
    }

    public function getSubscriptionUrl($url, $data): string
    {
        $subscription = Arr::get($data, 'subscription', null);
        if (!$subscription) {
            return '';
        }

        return MollieHelper::getSubscriptionUrl($subscription->id, (new MollieSettingsBase())->getMode() === 'live');
    }

    public function processRefund($transaction, $amount, $args)
    {
        if (!$amount) {
            return new \WP_Error(
                'fluent_cart_mollie_refund_error',
                __('Refund amount is required.', 'fluent-cart-pro')
            );
        }

        return MollieHelper::processRemoteRefund($transaction, $amount, $args);
    }

    public function fields(): array
    {
        $webhook_url = site_url('?fluent-cart=fct_payment_listener_ipn&method=mollie');
        $webhook_instructions = sprintf(
            '<div>
                <p><b>%1$s</b><code class="copyable-content">%2$s</code></p>
                <p>%3$s</p>
                <br>
                <h4>%4$s</h4>
                <br>
                <p>%5$s</p>
                <p>%6$s <a href="https://www.mollie.com/dashboard/developers/webhooks" target="_blank">%7$s</a></p>
                <p>%8$s <code class="copyable-content">%2$s</code></p>
                <p>%9$s</p>
            </div>',
            __('Webhook URL: ', 'fluent-cart-pro'),
            $webhook_url,
            __('You should configure your Mollie webhooks to get all updates of your payments remotely.', 'fluent-cart-pro'),
            __('How to configure?', 'fluent-cart-pro'),
            __('In your Mollie Dashboard:', 'fluent-cart-pro'),
            __('Go to Settings > Webhooks >', 'fluent-cart-pro'),
            __('Add webhook', 'fluent-cart-pro'),
            __('Enter The Webhook URL: ', 'fluent-cart-pro'),
            __('Select all events', 'fluent-cart-pro')
        );

        $betaNotice = __('Mollie payment gateway is currently in beta. Test properly before going live!', 'fluent-cart-pro');

        return array(
            'notice'              => [
                'value' => $this->renderStoreModeNotice(),
                'label' => __('Store Mode notice', 'fluent-cart-pro'),
                'type'  => 'notice'
            ],
            'beta_notice' => [
                'value' => '<p class="text-gray-500">
                        <svg class="w-4 h-4 inline-block" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024"><path fill="currentColor" d="M512 64a448 448 0 1 1 0 896.064A448 448 0 0 1 512 64m67.2 275.072c33.28 0 60.288-23.104 60.288-57.344s-27.072-57.344-60.288-57.344c-33.28 0-60.16 23.104-60.16 57.344s26.88 57.344 60.16 57.344M590.912 699.2c0-6.848 2.368-24.64 1.024-34.752l-52.608 60.544c-10.88 11.456-24.512 19.392-30.912 17.28a12.992 12.992 0 0 1-8.256-14.72l87.68-276.992c7.168-35.136-12.544-67.2-54.336-71.296-44.096 0-108.992 44.736-148.48 101.504 0 6.784-1.28 23.68.064 33.792l52.544-60.608c10.88-11.328 23.552-19.328 29.952-17.152a12.8 12.8 0 0 1 7.808 16.128L388.48 728.576c-10.048 32.256 8.96 63.872 55.04 71.04 67.84 0 107.904-43.648 147.456-100.416z"></path></svg>
                        ' . $betaNotice . '
                    </p>',
                'label' => __('Beta Notice', 'fluent-cart-pro'),
                'type' => 'html_attr'
            ],
            'payment_mode'        => [
                'type'   => 'tabs',
                'schema' => [
                    [
                        'type'   => 'tab',
                        'label'  => __('Live credentials', 'fluent-cart-pro'),
                        'value'  => 'live',
                        'schema' => [
                            'live_api_key' => array(
                                'value'       => '',
                                'label'       => __('Live API Key', 'fluent-cart-pro'),
                                'type'        => 'password',
                                'placeholder' => __('live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'fluent-cart-pro'),
                                'dependency'  => [
                                    'depends_on' => 'payment_mode',
                                    'operator'   => '=',
                                    'value'      => 'live'
                                ]
                            ),
                        ]
                    ],
                    [
                        'type'   => 'tab',
                        'label'  => __('Test credentials', 'fluent-cart-pro'),
                        'value'  => 'test',
                        'schema' => [
                            'test_api_key' => array(
                                'value'       => '',
                                'label'       => __('Test API Key', 'fluent-cart-pro'),
                                'type'        => 'password',
                                'placeholder' => __('test_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'fluent-cart-pro'),
                                'dependency'  => [
                                    'depends_on' => 'payment_mode',
                                    'operator'   => '=',
                                    'value'      => 'test'
                                ]
                            ),
                        ],
                    ],
                ]
            ],
//            'is_authorize_a_success_state' => [
//                'value' => '',
//                'label' => __('Authorize is a Success State', 'fluent-cart-pro-pro'),
//                'type'  => 'checkbox',
//                'tooltip' => __('If you want to use Authorize as a Success State, please enable this option.', 'fluent-cart-pro-pro')
//            ],
//            'webhook_desc'        => array(
//                'value' => $webhook_instructions,
//                'label' => __('Webhook URL', 'fluent-cart-pro'),
//                'type'  => 'html_attr'
//            ),

            'test_active_methods' => [
                'value' => (new MollieAPI())->getActivatedPaymentMethodsConfigs('test'),
                'label' => __('Activated Methods', 'fluent-cart-pro'),
                'type'  => 'active_methods'
            ],
            'live_active_methods' => [
                'value' => (new MollieAPI())->getActivatedPaymentMethodsConfigs('live'),
                'label' => __('Activated Methods', 'fluent-cart-pro'),
                'type'  => 'active_methods'
            ],
            'render_selected_methods_only' => [
                'disabled' => true,
                'value' => '',
                'label' => __('Render Selected Methods Only (Coming soon)', 'fluent-cart-pro'),
                'type'  => 'checkbox',
                'tooltip' => __('If enabled, only the selected Mollie payment methods will be displayed during checkout.', 'fluent-cart-pro')
            ],
            'required_checkout_fields' => [
                'value' => '<p class="text-gray-500">
                        <svg class="w-4 h-4 inline-block" xmlns="http://www.w3.org/org/2000/svg" viewBox="0 0 1024 1024"><path fill="currentColor" d="M512 64a448 448 0 1 1 0 896.064A448 448 0 0 1 512 64m67.2 275.072c33.28 0 60.288-23.104 60.288-57.344s-27.072-57.344-60.288-57.344c-33.28 0-60.16 23.104-60.16 57.344s26.88 57.344 60.16 57.344M590.912 699.2c0-6.848 2.368-24.64 1.024-34.752l-52.608 60.544c-10.88 11.456-24.512 19.392-30.912 17.28a12.992 12.992 0 0 1-8.256-14.72l87.68-276.992c7.168-35.136-12.544-67.2-54.336-71.296-44.096 0-108.992 44.736-148.48 101.504 0 6.784-1.28 23.68.064 33.792l52.544-60.608c10.88-11.328 23.552-19.328 29.952-17.152a12.8 12.8 0 0 1 7.808 16.128L388.48 728.576c-10.048 32.256 8.96 63.872 55.04 71.04 67.84 0 107.904-43.648 147.456-100.416z"></path></svg>
                        ' . __('Please make sure the following billing fields are enabled: City, State, Street Address, Post Code, Country. You can enable the fields in the checkout fields settings.', 'fluent-cart-pro') . '
                    </p>',
                'label' => __('Required Checkout Fields', 'fluent-cart-pro'),
                'type'  => 'html_attr'
            ]
        );
    }

    public static function validateSettings($data): array
    {
        return $data;
    }

    public static function beforeSettingsUpdate($data, $oldSettings): array
    {

        $mode = Arr::get($data, 'payment_mode', 'test');

        if ($mode == 'test') {
            $data['test_api_key'] = Helper::encryptKey($data['test_api_key']);
        } else{  
            $data['live_api_key'] = Helper::encryptKey($data['live_api_key']);
        }

        return $data;
    }

    public static function register():void
    {
        fluent_cart_api()->registerCustomPaymentMethod('mollie', new self());
    }

}

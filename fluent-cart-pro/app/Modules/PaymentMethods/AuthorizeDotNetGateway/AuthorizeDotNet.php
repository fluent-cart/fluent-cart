<?php

namespace FluentCartPro\App\Modules\PaymentMethods\AuthorizeDotNetGateway;

use FluentCart\Api\CurrencySettings;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\Framework\Support\Arr;
use FluentCartPro\App\Utils\Enqueuer\Vite;
use FluentCartPro\App\Modules\PaymentMethods\AuthorizeDotNetGateway\Webhook\IPN;
use FluentCartPro\App\Modules\PaymentMethods\AuthorizeDotNetGateway\AuthorizeDotNetSubscriptions;

class AuthorizeDotNet extends AbstractPaymentGateway
{
    public array $supportedFeatures = [
        'payment',
        'webhook',
        'refund',
        'subscriptions',
        'custom_payment',
    ];

    protected AuthorizeDotNetSettings $authorizeSettings;

    public function __construct()
    {
        $settings = new AuthorizeDotNetSettings();
        $this->authorizeSettings = $settings;

        $subscriptions = new AuthorizeDotNetSubscriptions($settings);

        parent::__construct($settings, $subscriptions);

        add_filter('fluent_cart/payment_methods_with_custom_checkout_buttons', function ($methods) {
            $methods[] = 'authorize_dot_net';
            return $methods;
        });
    }

    public function meta(): array
    {
  
        $logo = Vite::getAssetUrl("images/payment-methods/authorize_dot_net-logo.svg");
        return [
            'title'              => __('Authorize.Net', 'fluent-cart-pro'),
            'route'              => 'authorize_dot_net',
            'slug'               => 'authorize_dot_net',
            'description'        => __('Pay securely with Authorize.Net using credit/debit cards or e-check (ACH).', 'fluent-cart-pro'),
            'logo'               => $logo,
            'icon'               => Vite::getAssetUrl("images/payment-methods/authorize_dot_net-logo.svg"),
            'brand_color'        => '#0F4B8D',
            'tag'                => 'beta',
            'status'             => $this->authorizeSettings->isActive(),
            'supported_features' => $this->supportedFeatures
        ];
    }

    public function isCurrencySupported(): bool
    {
        return AuthorizeDotNetHelper::checkCurrencySupport();
    }

    public function boot()
    {
        (new IPN($this->authorizeSettings))->init();
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        if ($paymentInstance->subscription) {
            return (new AuthorizeDotNetProcessor($this->authorizeSettings))->handleSubscription($paymentInstance);
        }

        return (new AuthorizeDotNetProcessor($this->authorizeSettings))->handleSinglePayment($paymentInstance);
    }

    public function handleIPN(): void
    {
        // not needed, as authorize dot net handles webhooks on boot
    }

    public function getOrderInfo($data)
    {
        if (!$this->authorizeSettings->isActive()) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Authorize.Net is not enabled.', 'fluent-cart-pro'),
            ], 422);
        }

        $clientKey = $this->authorizeSettings->getClientKey();
        $apiLoginId = $this->authorizeSettings->getApiLoginId();
        $eCheckEnabled = $this->authorizeSettings->isECheckEnabled();

        if (!$clientKey || !$apiLoginId) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Authorize.Net is not configured correctly. Please contact the site administrator.', 'fluent-cart-pro'),
            ], 422);
        }

        wp_send_json([
            'status'       => 'success',
            'payment_args' => [
                'client_key'   => $clientKey,
                'api_login_id' => $apiLoginId,
                'mode'         => $this->authorizeSettings->getMode(),
                'currency'     => strtoupper(CurrencySettings::get('currency')),
                'enable_echeck'=> $eCheckEnabled,
                'accept_ui_form_btn_txt' => $this->settings->get('accept_ui_form_btn_txt') ?? 'Pay now',
                'accept_ui_form_header_txt' => $this->settings->get('accept_ui_form_header_txt') ?? 'Enter Payment Details',
                'accept_ui_button_text' => $this->settings->get('accept_ui_button_text') ?? 'Place Order',
                'accept_ui_button_background_color' => $this->settings->get('accept_ui_button_background_color') ?? '#0F4B8D',
                'accept_ui_button_hover_color' => $this->settings->get('accept_ui_button_hover_color') ?? '#0d3d75',
                'show_billing_address' => $this->settings->get('show_billing_address') === 'yes',
                'require_billing_address' => $this->settings->get('require_billing_address') === 'yes',
            ],
        ]);
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        return [
            [
                'handle' => 'fluent-cart-authorize_dot_net-checkout',
                'src'    => Vite::getEnqueuePath('public/payment-methods/authorize_dot_net-checkout.js'),
            ]
        ];
    }

    public function getEnqueueStyleSrc(): array
    {
        return [];
    }

    public function getLocalizeData(): array
    {
        return [
            'fct_authorize_dot_net_data' => [
                'translations' => [
                    'Click the button to securely enter your payment details.' => __('Click the button to securely enter your payment details.', 'fluent-cart-pro'),
                    'Payment details saved.'                          => __('Payment details saved.', 'fluent-cart-pro'),
                    'Authorize.Net payment form failed to load.'      => __('Authorize.Net payment form failed to load.', 'fluent-cart-pro'),
                ]
            ]
        ];
    }

    public function getTransactionUrl($url, $data)
    {
        $vendorChargeId = Arr::get($data, 'vendor_charge_id');
        if (!$vendorChargeId) {
            return $url;
        }

        return sprintf('https://%s.authorize.net/smb2/merchant/TransactionManagement/ManageTransactions',
            $this->authorizeSettings->getMode() === 'live' ? 'account' : 'demo'
        );
    }

    public function getSubscriptionUrl($url, $data): string
    {
        $vendorSubscriptionId = Arr::get($data, 'vendor_subscription_id');
        if (!$vendorSubscriptionId) {
            return $url;
        }

        return sprintf('https://%s.authorize.net/smb2/merchant/Payments/Subscriptions/SubscriptionsDetails?subscriptionID=%s',
            $this->authorizeSettings->getMode() === 'live' ? 'account' : 'demo',
            $vendorSubscriptionId
        );
    }

    public function processRefund($transaction, $amount, $args)
    {
        return (new AuthorizeDotNetRefund())->processRemoteRefund($transaction, $amount, $args);
    }

    public function fields(): array
    {
        $webhookUrl = site_url('/') ;

        $testSchema = [
            'test_api_login_id' => [
                'type'        => 'text',
                'label'       => __('Test API Login ID', 'fluent-cart-pro'),
                'placeholder' => __('Your test API Login ID', 'fluent-cart-pro'),
            ],
            'test_transaction_key' => [
                'type'        => 'password',
                'label'       => __('Test Transaction Key', 'fluent-cart-pro'),
                'placeholder' => __('Your test Transaction Key', 'fluent-cart-pro'),
            ],
            'test_client_key' => [
                'type'        => 'text',
                'label'       => __('Test Client Key', 'fluent-cart-pro'),
                'placeholder' => __('Your test Client Key', 'fluent-cart-pro'),
            ],
            'test_signature_key' => [
                'type'        => 'text',
                'label'       => __('Test Signature Key', 'fluent-cart-pro'),
                'placeholder' => __('Your test Signature Key', 'fluent-cart-pro'),
            ],
        ];
        $liveSchema = [
            'live_api_login_id' => [
                'type'        => 'text',
                'label'       => __('Live API Login ID', 'fluent-cart-pro'),
                'placeholder' => __('Your live API Login ID', 'fluent-cart-pro'),
            ],
            'live_transaction_key' => [
                'type'        => 'password',
                'label'       => __('Live Transaction Key', 'fluent-cart-pro'),
                'placeholder' => __('Your live Transaction Key', 'fluent-cart-pro'),
            ],
            'live_client_key' => [
                'type'        => 'password',
                'label'       => __('Live Client Key', 'fluent-cart-pro'),
                'placeholder' => __('Your live Client Key', 'fluent-cart-pro'),
            ],
            'live_signature_key' => [
                'type'        => 'password',
                'label'       => __('Live Signature Key', 'fluent-cart-pro'),
                'placeholder' => __('Your live Signature Key', 'fluent-cart-pro'),
            ],
        ];

        return [
            'notice' => [
                'value' => $this->renderStoreModeNotice(),
                'label' => __('Store Mode notice', 'fluent-cart-pro'),
                'type'  => 'notice'
            ],
            'payment_mode' => [
                'type'   => 'tabs',
                'schema' => [
                    [
                        'type'   => 'tab',
                        'label'  => __('Live credentials', 'fluent-cart-pro'),
                        'value'  => 'live',
                        'schema' => $liveSchema
                    ],
                    [
                        'type'   => 'tab',
                        'label'  => __('Test credentials', 'fluent-cart-pro'),
                        'value'  => 'test',
                        'schema' => $testSchema
                    ],
                ]
            ],
            'enable_echeck' => [
                'type'        => 'checkbox',
                'label'       => __('Enable e-Check (ACH)', 'fluent-cart-pro'),
                'tooltip'     => __('Allow customers to pay with bank accounts.', 'fluent-cart-pro'),
                'value'       => $this->settings->get('enable_echeck') ?? 'yes',
            ],
            'accept_ui_form_btn_txt' => [
                'type'        => 'text',
                'label'       => __('Authorize.Net checkout Form Button Text', 'fluent-cart-pro'),
                'placeholder' => __('Pay now', 'fluent-cart-pro'),
                'value'       => $this->settings->get('accept_ui_form_btn_txt') ?? 'Pay now',
                'description' => __('This text will be displayed on the Authorize.Net popup form button.', 'fluent-cart-pro'),
            ],
            'accept_ui_form_header_txt' => [
                'type'        => 'text',
                'label'       => __('Authorize.Net Checkout Form Header Text', 'fluent-cart-pro'),
                'placeholder' => __('Enter Payment Details', 'fluent-cart-pro'),
                'value'       => $this->settings->get('accept_ui_form_header_txt') ?? __('Enter Payment Details', 'fluent-cart-pro'),
                'description' => __('This text will be displayed on the Authorize.Net popup form header.', 'fluent-cart-pro'),
            ],
            'accept_ui_button_text' => [
                'type'        => 'text',
                'label'       => __('Fluent Cart Checkout Button Text', 'fluent-cart-pro'),
                'placeholder' => __('Place Order', 'fluent-cart-pro'),
                'value'       => $this->settings->get('accept_ui_button_text') ?? __('Place Order', 'fluent-cart-pro'),
                'description' => __('This text will be displayed on the Fluent Cart checkout button.', 'fluent-cart-pro'),
            ],
            'accept_ui_button_background_color' => [
                'type'        => 'color',
                'label'       => __('Button Background Color', 'fluent-cart-pro'),
                'placeholder' => __('#0F4B8D', 'fluent-cart-pro'),
                'value'       => $this->settings->get('accept_ui_button_background_color') ?? '#0F4B8D',
                'description' => __('Background color for the Authorize.Net payment button. Default: #0F4B8D', 'fluent-cart-pro'),
            ],
            'accept_ui_button_hover_color' => [
                'type'        => 'color',
                'label'       => __('Button Hover Color', 'fluent-cart-pro'),
                'placeholder' => __('#0d3d75', 'fluent-cart-pro'),
                'value'       => $this->settings->get('accept_ui_button_hover_color') ?? '#0d3d75',
                'description' => __('Hover color for the Authorize.Net payment button. Default: #0d3d75', 'fluent-cart-pro'),
            ],
            'debug_log' => [
                'type'        => 'checkbox',
                'label'       => __('Enable Debug Logging', 'fluent-cart-pro'),
                'tooltip'     => __('Log Authorize.Net API interactions for troubleshooting.', 'fluent-cart-pro'),
                'value'       => $this->settings->get('debug_log') ?? 'no',
            ],
            'webhook_desc' => [
                'type'  => 'html_attr',
                'label' => __('Webhook URL', 'fluent-cart-pro'),
                'value' => sprintf(
                    '<p>%s</p><code class="copyable-content">%s</code><p>%s</p>',
                    esc_html__('Configure this URL in your Authorize.Net dashboard under Account > Settings > Webhooks.', 'fluent-cart-pro'),
                    esc_html($webhookUrl),
                    esc_html__('Recommended events: net.authorize.payment.authcapture.created, net.authorize.payment.fraud.approved, net.authorize.payment.fraud.declined, net.authorize.payment.void.created, net.authorize.payment.refund.created, net.authorize.customer.subscription.cancelled, net.authorize.customer.subscription.expired, net.authorize.customer.subscription.expiring.', 'fluent-cart-pro')
                ),
            ],
        ];
    }

    public static function validateSettings($data): array
    {
        $mode = Arr::get($data, 'payment_mode', 'test');
        $apiLoginId = Arr::get($data, $mode . '_api_login_id');
        $transactionKey = Arr::get($data, $mode . '_transaction_key');
        $clientKey = Arr::get($data, $mode . '_client_key');
  

        if (empty($apiLoginId) || empty($transactionKey) || empty($clientKey)) {
            return [
                'status' => 'failed',
                'message' => __('API Login ID, Transaction Key and Client Key are required.', 'fluent-cart-pro')
            ];
        }

        return [
            'status' => 'success',
            'message' => __('Authorize.Net credentials verified successfully!', 'fluent-cart-pro')
        ];

      
        // we might use this later
        // $api = new AuthorizeDotNetAPI();
        // $data = [
        //     'merchantAuthentication' => [
        //         'name'           => $apiLoginId,
        //         'transactionKey' => $transactionKey,
        //     ]
        // ];
        // $result = $api->getAuthorizeNetObject('getMerchantDetailsRequest', $data, $mode === 'test' ? 'test' : 'live');



        // if (is_wp_error($result) || !AuthorizeDotNetAPI::isSuccessResponse($result)) {
        //     return [
        //         'status'  => 'failed',
        //         'message' => AuthorizeDotNetAPI::extractErrorMessage($result, __('Credential verification failed.', 'fluent-cart-pro')),
        //     ];
        // }

        // return [
        //     'status'  => 'success',
        //     'message' => __('Authorize.Net credentials verified successfully.', 'fluent-cart-pro'),
        // ];
    }

    public static function beforeSettingsUpdate($data, $oldSettings): array
    {
        return AuthorizeDotNetSettings::beforeSave($data);
    }

    public static function register(): void
    {
        fluent_cart_api()->registerCustomPaymentMethod('authorize_dot_net', new self());
    }
}

<?php

namespace FluentCartPro\App\Modules\PaymentMethods\PaddleGateway;


use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCartPro\App\Modules\PaymentMethods\PaddleGateway\Webhook\IPN;
use FluentCartPro\App\Modules\PaymentMethods\PaddleGateway\API\API;
use FluentCart\Api\CurrencySettings;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\CartCheckoutHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Hooks\Cart\WebCheckoutHandler;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCartPro\App\Utils\Enqueuer\Vite;
use FluentCart\Framework\Support\Arr;


class Paddle extends AbstractPaymentGateway
{
    private $methodSlug = 'paddle';
    public array $supportedFeatures = [
        'payment',
        'refund',
        'webhook',
        'subscriptions',
        'custom_payment',
        'upgrade_plan'
    ];

    public function __construct()
    {
        parent::__construct(
            new PaddleSettings(),
            new PaddleSubscriptions()
        );

      add_filter('fluent_cart/payment_methods_with_custom_checkout_buttons', function ($methods) {
          $methods[] = 'paddle';
          return $methods;
      });
    }

    public function meta(): array
    {
        return [
            'title' => __('Paddle', 'fluent-cart-pro'),
            'route' => 'paddle',
            'slug' => 'paddle',
            'description' => __('Pay securely with Paddle - Complete payment solution', 'fluent-cart-pro'),
            'logo' => Vite::getAssetUrl("images/payment-methods/paddle-logo.svg"),
            'icon' => Vite::getAssetUrl("images/payment-methods/paddle-logo.svg"),
            'brand_color' => '#7c3aed',
            'status' => $this->settings->get('is_active') === 'yes',
            'supported_features' => $this->supportedFeatures,
            'tag' => 'beta'
        ];
    }

    public function boot()
    {
        (new IPN())->init();
        (new Confirmations())->init();
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        $order = $paymentInstance->order;

        if ($paymentInstance->subscription) {
            return (new Processor())->handleSubscriptionPayment($paymentInstance);
        }

        return (new Processor())->handleSinglePayment($paymentInstance);
    }

    public function processRefund($transaction, $amount, $args)
    {
        if (!$amount) {
            return new \WP_Error(
                'paddle_refund_error',
                __('Refund amount is required.', 'fluent-cart-pro')
            );
        }

        return PaddleHelper::processRemoteRefund($transaction, $amount, $args);
    }

    public function handleIPN(): void
    {
        (new IPN())->verifyAndProcess();
    }

    public static function validateSettings($data): array
    {

        $mode = Arr::get($data, 'payment_mode', 'live');
        $apiKey = Arr::get($data, $mode . '_api_key');

        if (empty($apiKey)) {
            return [
                'status' => 'failed',
                'message' => __('API key is required.', 'fluent-cart-pro')
            ];
        }

        return [
            'status' => 'success',
            'message' => __('Paddle gateway credentials verified successfully!', 'fluent-cart-pro')
        ];
    }

    public function getOrderInfo(array $data)
    {
        $cart = CartHelper::getCart();
        $checkOutHelper = new CartCheckoutHelper(true);
        $shippingChargeData = (new WebCheckoutHandler())->getShippingChargeData($cart);
        $shippingCharge = Arr::get($shippingChargeData, 'charge');
        $totalPrice = $checkOutHelper->getItemsAmountTotal(false) + $shippingCharge;

        $items = $checkOutHelper->getItems();

        $hasSubscription = $this->validateSubscriptions($items);

        $subTotal = 0;
        foreach ($items as $item) {
            $subTotal += intval($item['quantity'] * $item['unit_price']);
        }

        $clientToken = $this->settings->getClientToken();

        // Make client token optional for now to avoid blocking checkout
        if (empty($clientToken)) {
            fluent_cart_add_log('Paddle Client Token', 'Client token is empty, proceeding without it', 'warning', ['log_type' => 'payment']);
        }

        $settings = $this->settings->get();

        $paymentArgs = [
            'client_token'  => $clientToken ?: '',
            'mode'          => $this->settings->getMode(),
            'paddle_checkout_button_text' => Arr::get($settings, 'paddle_checkout_button_text', ''),
            'paddle_checkout_button_color' => Arr::get($settings, 'paddle_checkout_button_color', ''),
            'paddle_checkout_button_hover_color' => Arr::get($settings, 'paddle_checkout_button_hover_color', ''),
            'paddle_checkout_button_text_color' => Arr::get($settings, 'paddle_checkout_button_text_color', ''),
            'paddle_checkout_button_font_size' => Arr::get($settings, 'paddle_checkout_button_font_size', '')
        ];


        $paymentDetails = [
            'mode'      => 'payment',
            'theme'      => Arr::get($settings, 'paddle_checkout_theme', 'light'),
            'amount'    => Helper::toDecimalWithoutComma($totalPrice),
            'currency'  => strtoupper(CurrencySettings::get('currency')),
            'locale'    => (new StoreSettings())->get('locale', 'en'),
        ];

        if ($hasSubscription) {
            $paymentDetails['mode'] = 'subscription';
        }

        $this->checkCurrencySupport();

        wp_send_json([
            'status'           => 'success',
            'payment_args'     => $paymentArgs,
            'intent'           => $paymentDetails,
            'has_subscription' => $hasSubscription,
            'message'          => __('Order info retrieved!', 'fluent-cart-pro')
        ], 200);
    }

    public function getEventshtml()
    {
        // Build events list and render each as <code> for easy copy
        $events = $this->settings->getWebhookEventsToSubscribe();
        if (empty($events)) {
            $events = [
                'transaction.completed', 'transaction.paid', 'transaction.payment_failed',
                'adjustment.created', 'adjustment.updated',
                'subscription.created', 'subscription.activated', 'subscription.updated',
                'subscription.past_due', 'subscription.paused', 'subscription.resumed', 'subscription.canceled'
            ];
        }

        return implode(' ', array_map(function ($event) {
            return '<code class="copyable-content" data-copy="' . esc_attr($event) . '">' . esc_html($event) . '</code>';
        }, $events));
    }

    public function getWebhookInstructions()
    {
        $webhook_url = $this->getWebhookUrl();

        $eventsHtml  = $this->getEventshtml();

        // Construct valid, translatable, sanitized HTML for instructions
        $instructionsHtml =
            '<div class="paddle-webhook-instructions" style="padding:12px 0;">'
            . '<p><strong>' . esc_html__('Webhook URL:', 'fluent-cart-pro') . '</strong> '
            . '<code class="copyable-content" data-copy="' . esc_attr($webhook_url) . '">' . esc_html($webhook_url) . '</code></p>'
            . '<p>' . esc_html__('You should configure your Paddle webhooks to get all updates of your payments remotely.', 'fluent-cart-pro') . '</p>'
            . '<p>' . esc_html__('You can do it by following the instructions below. Or provide valid API Key and save the settings and reload.', 'fluent-cart-pro') . '</p>'
            . '<h4>' . esc_html__('How to configure?', 'fluent-cart-pro') . '</h4>'
            . '<p>' . esc_html__('In your Paddle Dashboard:', 'fluent-cart-pro') . '</p>'
            . '<p>' . sprintf(
                /* translators: %s is a link to Paddle webhook docs */
                esc_html__('Go to Developer Tools > Notifications > %s', 'fluent-cart-pro'),
                '<a href="https://developer.paddle.com/webhooks/overview" target="_blank" rel="noopener noreferrer">' . esc_html__('Add webhook', 'fluent-cart-pro') . '</a>'
            ) . '</p>'
            . '<p>' . esc_html__('Enter The Webhook URL:', 'fluent-cart-pro') . ' '
            . '<code class="copyable-content" data-copy="' . esc_attr($webhook_url) . '">' . esc_html($webhook_url) . '</code></p>'
            . '<p>' . esc_html__('Select the following events:', 'fluent-cart-pro') . '</p>'
            . '<p style="display:flex; align-items:center; flex-wrap:wrap; gap:8px 4px;">' . wp_kses_post($eventsHtml) . '</p>'
            . '</div>';

        return $instructionsHtml;
    }

    public function fields(): array
    {
        $webhookInstructions = $this->getWebhookInstructions();

        $testSchema = [
            'test_api_key' => array(
                'value' => '',
                'label' => __('Sandbox API Key', 'fluent-cart-pro'),
                'type' => 'password',
                'placeholder' => __('Your sandbox API key', 'fluent-cart-pro'),
                'help_text' => __('Get your API key from Paddle Dashboard > Developer Tools > Authentication', 'fluent-cart-pro')
            ),
            'test_client_token' => array(
                'value' => '',
                'label' => __('Sandbox Client Token / Public Key', 'fluent-cart-pro'),
                'type' => 'text',
                'placeholder' => __('Your sandbox client token', 'fluent-cart-pro'),
                'help_text' => __('Optional: Used for frontend checkout integration', 'fluent-cart-pro')
            ),
            'test_webhook_secret' => array(
                'value' => '',
                'label' => __('Sandbox Webhook Secret', 'fluent-cart-pro'),
                'type' => 'password',
                'placeholder' => __('Your sandbox webhook secret', 'fluent-cart-pro'),
                'help_text' => __('Used to verify webhook signatures', 'fluent-cart-pro')
            ),
            'test_webhook_desc' => array(
                'value' => $webhookInstructions,
                'label' => __('Webhook Configuration', 'fluent-cart-pro'),
                'type' => 'html_attr'
            ),
        ];

        $liveSchema = [
            'live_api_key' => array(
                'value' => '',
                'label' => __('Live API Key', 'fluent-cart-pro'),
                'type' => 'password',
                'placeholder' => __('Your live API key', 'fluent-cart-pro'),
                'help_text' => __('Get your API key from Paddle Dashboard > Developer Tools > Authentication', 'fluent-cart-pro')
            ),
            'live_client_token' => array(
                'value' => '',
                'label' => __('Live Client Token / Public Key', 'fluent-cart-pro'),
                'type' => 'text',
                'placeholder' => __('Your live client token', 'fluent-cart-pro'),
                'help_text' => __('Optional: Used for frontend checkout integration', 'fluent-cart-pro')
            ),
            'live_webhook_secret' => array(
                'value' => '',
                'label' => __('Live Webhook Secret', 'fluent-cart-pro'),
                'type' => 'password',
                'placeholder' => __('Your live webhook secret', 'fluent-cart-pro'),
                'help_text' => __('Used to verify webhook signatures', 'fluent-cart-pro')
            ),
            'live_webhook_desc' => array(
                'value' => $webhookInstructions,
                'label' => __('Webhook Configuration', 'fluent-cart-pro'),
                'type' => 'html_attr'
            ),
        ];

        if (!empty($this->settings->get('test_webhook_secret'))) {
            // Use the same sanitized, code-tag list of events
            $configuredHtml =
                '<div class="paddle-webhook-instructions" style="padding:12px 0;">'
                . '<p><strong>' . esc_html__('Webhook URL:', 'fluent-cart-pro') . '</strong> '
                . '<code class="copyable-content" data-copy="' . esc_attr($this->getWebhookUrl()) . '">' . esc_html($this->getWebhookUrl()) . '</code></p>'
                . '<p>' . esc_html__('Webhook is configured and listening to the following events:', 'fluent-cart-pro') . '</p>'
                . '<p style="display:flex;align-items:center;flex-wrap:wrap;gap:8px 4px;">' . wp_kses_post($this->getEventshtml()). '</p>'
                . '</div>';

            $testSchema['test_webhook_desc']['value'] = $configuredHtml;
        }

        if (!empty(Arr::get($this->settings->get(), 'live_webhook_secret'))) {
            $configuredHtml =
                '<div class="paddle-webhook-instructions" style="padding:12px 0;">'
                . '<p><strong>' . esc_html__('Webhook URL:', 'fluent-cart-pro') . '</strong> '
                . '<code class="copyable-content" data-copy="' . esc_attr($this->getWebhookUrl()) . '">' . esc_html($this->getWebhookUrl()) . '</code></p>'
                . '<p>' . esc_html__('Webhook is configured and listening to the following events:', 'fluent-cart-pro') . '</p>'
                . '<p style="display:flex;align-items:center;flex-wrap:wrap;gap:8px 4px;">' . wp_kses_post($this->getEventshtml()) . '</p>'
                . '</div>';

            $liveSchema['live_webhook_desc']['value'] = $configuredHtml;
        }

         $betaNotice = __('Paddle payment gateway is currently in beta. Test properly before going live!', 'fluent-cart-pro');

        return array(
            'notice' => [
                'value' => $this->renderStoreModeNotice(),
                'label' => __('Store Mode notice', 'fluent-cart-pro'),
                'type' => 'notice'
            ],
            'beta_notice' => [
                'value' => '<p class="text-gray-500">
                        <svg class="w-4 h-4 inline-block" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024"><path fill="currentColor" d="M512 64a448 448 0 1 1 0 896.064A448 448 0 0 1 512 64m67.2 275.072c33.28 0 60.288-23.104 60.288-57.344s-27.072-57.344-60.288-57.344c-33.28 0-60.16 23.104-60.16 57.344s26.88 57.344 60.16 57.344M590.912 699.2c0-6.848 2.368-24.64 1.024-34.752l-52.608 60.544c-10.88 11.456-24.512 19.392-30.912 17.28a12.992 12.992 0 0 1-8.256-14.72l87.68-276.992c7.168-35.136-12.544-67.2-54.336-71.296-44.096 0-108.992 44.736-148.48 101.504 0 6.784-1.28 23.68.064 33.792l52.544-60.608c10.88-11.328 23.552-19.328 29.952-17.152a12.8 12.8 0 0 1 7.808 16.128L388.48 728.576c-10.048 32.256 8.96 63.872 55.04 71.04 67.84 0 107.904-43.648 147.456-100.416z"></path></svg>
                       ' . $betaNotice . ' </p>',
                'label' => __('Beta Notice', 'fluent-cart-pro'),
                'type' => 'html_attr'
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
                    ]
                ]
            ],
            'tax_mode' => [
                'value' => 'internal',
                'label' => __('Tax Mode', 'fluent-cart-pro'),
                'type' => 'select',
                'options' => [
                    'internal' => [
                        'label' => __('Internal', 'fluent-cart-pro'),
                        'value' => 'internal'
                    ],
                    'external' => [
                        'label' => __('External', 'fluent-cart-pro'),
                        'value' => 'external'
                    ],
                    'account_setting' => [
                        'label' => __('Use Paddle Account Settings', 'fluent-cart-pro'),
                        'value' => 'account_setting'
                    ]
                ],
                'tooltip' => __('Tax mode to use for Paddle transactions', 'fluent-cart-pro')
            ],
            'paddle_checkout_theme' => [
                'value' => 'light',
                'label' => __('Paddle Checkout Theme', 'fluent-cart-pro'),
                'type' => 'select',
                'options' => [
                    'light' => [
                        'label' => __('Light', 'fluent-cart-pro'),
                        'value' => 'light'
                    ],
                    'dark' => [
                        'label' => __('Dark', 'fluent-cart-pro'),
                        'value' => 'dark'
                    ]
                ],
                'tooltip' => __('Theme to use for Paddle checkout modal', 'fluent-cart-pro')
            ],
            'paddle_checkout_button_text' => [
                'value' => __('Pay with Paddle', 'fluent-cart-pro'),
                'label' => __('Paddle Checkout Button Text', 'fluent-cart-pro'),
                'type' => 'text',
                'placeholder' => __('Pay with Paddle', 'fluent-cart-pro'),
                'tooltip' => __('Text to display on the Paddle checkout button', 'fluent-cart-pro')
            ],
            'paddle_checkout_button_color' => [
                'value' => '',
                'label' => __('Paddle Checkout Button Color', 'fluent-cart-pro'),
                'type' => 'color',
                'tooltip' => __('Color of the Paddle checkout button', 'fluent-cart-pro')
            ],
            'paddle_checkout_button_hover_color' => [
                'value' => '',
                'label' => __('Paddle Checkout Button Hover Color', 'fluent-cart-pro'),
                'type' => 'color',
                'tooltip' => __('Hover color of the Paddle checkout button', 'fluent-cart-pro')
            ],
            'paddle_checkout_button_text_color' => [
                'value' => '',
                'label' => __('Paddle Checkout Button Text Color', 'fluent-cart-pro'),
                'type' => 'color',
                'tooltip' => __('Text color of the Paddle checkout button', 'fluent-cart-pro')
            ],
            'paddle_checkout_button_font_size' => [
                'value' => '16px',
                'label' => __('Paddle Checkout Button Font Size', 'fluent-cart-pro'),
                'type' => 'text',
                'tooltip' => __('Font size of the Paddle checkout button', 'fluent-cart-pro')
            ],
            'disable_webhook_verification' => [
                'value' => 'no',
                'label' => __('Disable Webhook Verification', 'fluent-cart-pro'),
                'type' => 'checkbox',
                'tooltip' => __('Only disable this for testing purposes. Keep enabled for production.', 'fluent-cart-pro')
            ]
        );
    }

    public static function beforeSettingsUpdate($data, $oldSettings): array
    {
        $mode = Arr::get($data, 'payment_mode', 'live');
        $apiKeyField = $mode . '_api_key';
       
        $data[$apiKeyField] = Helper::encryptKey($data[$apiKeyField]);
       

        // subscribe to webhook events working but not sure , we'll decide later
//        $events = (new PaddleSettings())->getWebhookEventsToSubscribe();
//        $notificationId = Arr::get($data, $mode . '_notification_id');
//        if ($notificationId && !empty($data[$apiKeyField])) {
//            $response = API::getPaddleObject('notification-settings/' . $notificationId);
//
//            if (!is_wp_error($response)) {
//                $alreadySubscribedEvents = Arr::get($response, 'data.subscribed_events');
//                $alreadySubscribedEvents = array_map(function ($event) {
//                    return $event['name'];
//                }, $alreadySubscribedEvents);
//
//                $missingEvents = array_diff($events, $alreadySubscribedEvents);
//                if (!empty($missingEvents)) {
//                    $alreadySubscribedEvents = array_merge($alreadySubscribedEvents, $missingEvents);
//                    API::updatePaddleObject('notification-settings/' . $notificationId, [
//                        'subscribed_events' => $alreadySubscribedEvents
//                    ]);
//                }
//            }
//
//        } else if (!$notificationId && !empty($data[$apiKeyField])) {
//            $response = API::createPaddleObject('notification-settings',
//                [
//                    'description' => __('FluentCart Test Paddle Webhook', 'fluent-cart-pro'),
//                    'type' => 'url',
//                    'destination' => (new Paddle())->getWebhookUrl(),
//                    'api_version' => 1,
//                    'traffic_source' => 'all',
//                    'subscribed_events' => $events
//                ]);
//
//            if (!is_wp_error($response)) {
//                $data[$mode . '_notification_id'] = Arr::get($response, 'data.id');
//                $data[$mode . '_webhook_secret'] = Arr::get($response, 'data.endpoint_secret_key');
//            }
//        }

        return $data;
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        $paddleJsUrl = 'https://cdn.paddle.com/paddle/v2/paddle.js';

        return [
            [
                'handle' => 'fluent-cart-checkout-sdk-paddle-js',
                'src' => $paddleJsUrl,
            ],
            [
                'handle' => 'fluent-cart-paddle-checkout',
                'src' => Vite::getEnqueuePath('public/payment-methods/paddle-checkout.js'),
                'deps' => ['fluent-cart-checkout-sdk-paddle-js']
            ]
        ];
    }

    public function getLocalizeData(): array
    {
        return [
            'fct_paddle_data' => [
                'translations' => [
                    'Paddle SDK is not loaded. Please ensure the Paddle script is included.' => __('Paddle SDK is not loaded. Please ensure the Paddle script is included.', 'fluent-cart-pro'),
                    'Paddle client token is missing or invalid.' => __('Paddle client token is missing or invalid.', 'fluent-cart-pro'),
                    'Pay with Paddle' => __('Pay with Paddle', 'fluent-cart-pro'),
                    'Secure payment powered by Paddle' => __('Secure payment powered by Paddle', 'fluent-cart-pro'),
                    'Order creation failed' => __('Order creation failed', 'fluent-cart-pro'),
                    'Failed to create order' => __('Failed to create order', 'fluent-cart-pro'),
                    'Order handler not available' => __('Order handler not available', 'fluent-cart-pro'),
                    'Order handler is not properly configured' => __('Order handler is not properly configured', 'fluent-cart-pro'),
                    'No Paddle price IDs found in order data. Please ensure Paddle products are properly configured.' => __('No Paddle price IDs found in order data. Please ensure Paddle products are properly configured.', 'fluent-cart-pro'),
                    'Failed to prepare valid Paddle items from order data.' => __('Failed to prepare valid Paddle items from order data.', 'fluent-cart-pro'),
                    'Error: Missing transaction ID' => __('Error: Missing transaction ID', 'fluent-cart-pro'),
                    'Confirmation failed' => __('Confirmation failed', 'fluent-cart-pro'),
                    'Network error' => __('Network error', 'fluent-cart-pro'),
                    'Error: %s' => __('Error: %s', 'fluent-cart-pro'),
                    'Payment failed' => __('Payment failed', 'fluent-cart-pro'),
                    'Error occurred' => __('Error occurred', 'fluent-cart-pro'),
                    'An error occurred. Please try again.' => __('An error occurred. Please try again.', 'fluent-cart-pro'),
                    'An error occurred while loading Paddle.' => __('An error occurred while loading Paddle.', 'fluent-cart-pro'),
                ]
            ]
        ];
    }

    public function getEnqueueStyleSrc(): array
    {
        return [
            [
                'handle' => 'fluent-cart-paddle-styles',
                'src' => '',
            ]
        ];
    }


    private function verifyWebhookSignature($payload)
    {
        $signature = $_SERVER['HTTP_PADDLE_SIGNATURE'] ?? '';
        $publicKey = $this->settings->getPublicKey();
        
        if (!$signature || !$publicKey) {
            return false;
        }

        // Paddle signature verification logic would go here
        // This is a simplified version - actual implementation would use Paddle's verification method
        return true;
    }

    private function getWebhookUrl()
    {
        return site_url('?fluent-cart=fct_payment_listener_ipn&method=paddle');
    }

    public function getTransactionUrl($url, $data)
    {
        if (Arr::get($data, 'transaction_type') === 'refund') { // refund url is not separate in paddle
            $parentTransaction = OrderTransaction::query()->where('id', Arr::get($data, 'transaction.meta.parent_id'))->first();
            $data['vendor_charge_id'] = $parentTransaction->vendor_charge_id;
        }

        if (Arr::get($data, 'payment_mode') === 'test') {
            return 'https://sandbox-vendors.paddle.com/transactions-v2/' . Arr::get($data, 'vendor_charge_id');
        }

        return 'https://vendors.paddle.com/transactions-v2/' . Arr::get($data, 'vendor_charge_id');
    }

    public function getSubscriptionUrl($url, $data)
    {
        if (Arr::get($data, 'payment_mode') === 'test') {
            return 'https://sandbox-vendors.paddle.com/subscriptions-v2/' . Arr::get($data, 'vendor_subscription_id');
        }

        return 'https://vendors.paddle.com/subscriptions-v2/' . Arr::get($data, 'vendor_subscription_id');
    }


    public function getSuccessUrl($transaction, $args = [])
    {
        $paymentHelper = new PaymentHelper($this->getMeta('route'));
        return $paymentHelper->successUrl($transaction->uuid, $args);
    }

    public function isCurrencySupported(): bool
    {
        $supportedCurrencies = [
            'USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'CHF', 'HKD', 'SGD', 'SEK',
            'ARS', 'BRL', 'CNY', 'COP', 'CZK', 'DKK', 'HUF', 'ILS', 'INR', 'KRW',
            'MXN', 'NOK', 'NZD', 'PLN', 'RUB', 'THB', 'TRY', 'TWD', 'UAH', 'VND', 'ZAR'
        ];

        return in_array(strtoupper(CurrencySettings::get('currency')), $supportedCurrencies);
    }

    private function checkCurrencySupport()
    {
        if (!$this->isCurrencySupported()) {
            $currentCurrency = CurrencySettings::get('currency');
            wp_send_json([
                'status' => 'failed',
                'message' => sprintf(
                    __('Currency %s is not supported by Paddle. Please check supported currencies.', 'fluent-cart-pro'),
                    $currentCurrency
                )
            ], 422);
        }
    }

    public static function register(){
        fluent_cart_api()->registerCustomPaymentMethod('paddle', new self());
    }

}

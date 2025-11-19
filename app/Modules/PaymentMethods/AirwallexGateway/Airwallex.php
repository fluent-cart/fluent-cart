<?php

namespace FluentCart\App\Modules\PaymentMethods\AirwallexGateway;

use FluentCart\App\App;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class Airwallex extends AbstractPaymentGateway
{
    public array $supportedFeatures = [
        'payment',
        'refund',
        'webhook'
    ];

    public function __construct()
    {
        parent::__construct(new AirwallexSettingsBase());
    }

    public function meta(): array
    {
        return [
            'title' => __('Airwallex', 'fluent-cart'),
            'route' => 'airwallex',
            'description' => __('Pay securely with Airwallex - Global payment processing', 'fluent-cart'),
            'logo' => Vite::getAssetUrl("images/payment-methods/airwallex-logo.svg"),
            'icon' => Vite::getAssetUrl("images/payment-methods/airwallex-logo.svg"),
            'brand_color' => '#6c5ce7',
            'status' => $this->settings->get('is_active') === 'yes',
            'upcoming' => true,
        ];
    }

    public function boot()
    {
        // init IPN related class/actions here
        add_filter('fluent_cart/payment_methods/airwallex_settings', [$this, 'getSettings'], 10, 2);
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        // todo: implement in future
        die();
    }

    public function refund($refundInfo, $order, $transaction)
    {
        // todo: implement in future
        die();
    }

    public function handleIPN(): void
    {
        $payload = json_decode(file_get_contents('php://input'), true); // will get from request after verification
        // Verify webhook signature
        if (!$this->verifyWebhookSignature($payload)) {
            http_response_code(401);
            exit('Unauthorized');
        }

        $eventType = $payload['name'] ?? '';
        
        switch ($eventType) {
            case 'payment_intent.succeeded':
                $this->handlePaymentSucceeded($payload);
                break;
            case 'payment_intent.failed':
                $this->handlePaymentFailed($payload);
                break;
            case 'refund.received':
                $this->handleRefundReceived($payload);
                break;
        }

        http_response_code(200);
        exit('OK');
    }

    public function getOrderInfo(array $data)
    {
        $items = $this->getCheckoutItems();

        $subTotal = 0;
        foreach ($items as $item) {
            $subTotal += intval($item['quantity'] * $item['unit_price']);
        }

        $paymentArgs = [
            'client_id' => $this->settings->getClientId(),
            'amount' => $subTotal,
            'currency' => $this->storeSettings->get('currency'),
        ];

        wp_send_json([
            'status' => 'success',
            'payment_args' => $paymentArgs,
            'has_subscription' => false
        ], 200);
    }

    public function fields()
    {
        $webhook_url = site_url() . '?fct_payment_listener=1&method=airwallex';
        $webhook_instructions = sprintf(
            '<div>
                <p><b>%1$s</b><code class="copyable-content">%2$s</code></p>
                <p>%3$s</p>
                <br>
                <h4>%4$s</h4>
                <br>
                <p>%5$s</p>
                <p>%6$s <a href="https://www.airwallex.com/app/settings/developer" target="_blank">%7$s</a></p>
                <p>%8$s <code class="copyable-content">%2$s</code></p>
                <p>%9$s</p>
            </div>',
            __('Webhook URL: ', 'fluent-cart'),                    // %1$s
            $webhook_url,                                          // %2$s (reused)
            __('You should configure your Airwallex webhooks to get all updates of your payments remotely.', 'fluent-cart'), // %3$s
            __('How to configure?', 'fluent-cart'),                // %4$s
            __('In your Airwallex Console:', 'fluent-cart'),       // %5$s
            __('Go to Developers > Webhooks >', 'fluent-cart'),    // %6$s
            __('Add webhook', 'fluent-cart'),                      // %7$s
            __('Enter The Webhook URL: ', 'fluent-cart'),          // %8$s
            __('Select payment events', 'fluent-cart')             // %9$s
        );

        return array(
//            'notice' => [
//                'value' => $this->getStoreModeNotice(),
//                'label' => __('Store Mode notice', 'fluent-cart'),
//                'type' => 'notice'
//            ],
            'upcoming' => [
                'value' => $this->isUpcoming(),
                'label' => __('Payment method is upcoming!', 'fluent-cart'),
                'type' => 'upcoming'
            ],
            'payment_mode' => [
                'type' => 'tabs',
                'schema' => [
                    [
                        'type' => 'tab',
                        'label' => __('Test credentials', 'fluent-cart'),
                        'value' => 'test',
                        'schema' => [
                            'test_client_id' => array(
                                'value' => '',
                                'label' => __('Test Client ID', 'fluent-cart'),
                                'type' => 'text',
                                'placeholder' => __('Your test client ID', 'fluent-cart'),
                                'dependency' => [
                                    'depends_on' => 'payment_mode',
                                    'operator' => '=',
                                    'value' => 'test'
                                ]
                            ),
                            'test_api_key' => array(
                                'value' => '',
                                'label' => __('Test API Key', 'fluent-cart'),
                                'type' => 'password',
                                'placeholder' => __('Your test API key', 'fluent-cart'),
                                'dependency' => [
                                    'depends_on' => 'payment_mode',
                                    'operator' => '=',
                                    'value' => 'test'
                                ]
                            ),
                        ],
                    ],
                    [
                        'type' => 'tab',
                        'label' => __('Live credentials', 'fluent-cart'),
                        'value' => 'live',
                        'schema' => [
                            'live_client_id' => array(
                                'value' => '',
                                'label' => __('Live Client ID', 'fluent-cart'),
                                'type' => 'text',
                                'placeholder' => __('Your live client ID', 'fluent-cart'),
                                'dependency' => [
                                    'depends_on' => 'payment_mode',
                                    'operator' => '=',
                                    'value' => 'live'
                                ]
                            ),
                            'live_api_key' => array(
                                'value' => '',
                                'label' => __('Live API Key', 'fluent-cart'),
                                'type' => 'password',
                                'placeholder' => __('Your live API key', 'fluent-cart'),
                                'dependency' => [
                                    'depends_on' => 'payment_mode',
                                    'operator' => '=',
                                    'value' => 'live'
                                ]
                            ),
                        ]
                    ]
                ]
            ],
            'webhook_desc' => array(
                'value' => $webhook_instructions,
                'label' => __('Webhook URL', 'fluent-cart'),
                'type' => 'html_attr'
            ),
        );
    }

    public static function validateSettings($data): array
    {
        $clientId = $data['client_id'] ?? '';
        $apiKey = $data['api_key'] ?? '';

        if (empty($clientId) || empty($apiKey)) {
            return [
                'status' => 'failed',
                'message' => __('Client ID and API Key are required', 'fluent-cart')
            ];
        }

        try {
            $testResponse = static::testApiConnection($clientId, $apiKey, $data['payment_mode'] ?? 'demo');
            
            return [
                'status' => 'success',
                'message' => __('Airwallex settings validated successfully', 'fluent-cart')
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage()
            ];
        }
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        $mode = $this->settings->getMode();
        $airwallexJsUrl = $mode === 'production' 
            ? 'https://checkout.airwallex.com/assets/elements.bundle.min.js'
            : 'https://checkout.airwallex.com/assets/elements.bundle.min.js';

        return [
            [
                'handle' => 'airwallex-elements-js',
                'src' => $airwallexJsUrl,
            ],
            [
                'handle' => 'fluent-cart-airwallex-checkout',
                'src' => Vite::getEnqueuePath('public/payment-methods/airwallex-checkout.js'),
                'deps' => ['airwallex-elements-js']
            ]
        ];
    }

    public function getEnqueueStyleSrc(): array
    {
        return [
            [
                'handle' => 'fluent-cart-airwallex-styles',
                'src' => Vite::getEnqueuePath('public/payment-methods/airwallex.css'),
            ]
        ];
    }

    private function getAccessToken()
    {
        $clientId = $this->settings->getClientId();
        $apiKey = $this->settings->getApiKey();
        $baseUrl = $this->getApiBaseUrl();

        $response = wp_remote_post("{$baseUrl}/api/v1/authentication/login", [
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'x-client-id' => $clientId,
                'x-api-key' => $apiKey
            ]),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            throw new \Exception(esc_html($response->get_error_message()));
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (wp_remote_retrieve_response_code($response) !== 201) {
            throw new \Exception(esc_html__('Failed to authenticate with Airwallex', 'fluent-cart'));
        }

        return $data['token'];
    }

    private function createAirwallexPaymentIntent($data, $accessToken)
    {
        $baseUrl = $this->getApiBaseUrl();
        
        $response = wp_remote_post("{$baseUrl}/api/v1/pa/payment_intents/create", [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($data),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            throw new \Exception(esc_html($response->get_error_message()));
        }

        $body = wp_remote_retrieve_body($response);
        $responseData = json_decode($body, true);

        if (wp_remote_retrieve_response_code($response) !== 201) {
            $error = $responseData['message'] ?? 'Unknown error';
            throw new \Exception(esc_html($error));
        }

        return $responseData;
    }

    private function processAirwallexRefund($refundData, $accessToken)
    {
        $baseUrl = $this->getApiBaseUrl();
        
        $response = wp_remote_post("{$baseUrl}/api/v1/pa/refunds/create", [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($refundData),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            throw new \Exception(esc_html($response->get_error_message()));
        }

        $body = wp_remote_retrieve_body($response);
        $responseData = json_decode($body, true);

        if (wp_remote_retrieve_response_code($response) !== 201) {
            $error = $responseData['message'] ?? 'Unknown error';
            throw new \Exception(esc_html($error));
        }

        return $responseData;
    }

    private static function testApiConnection($clientId, $apiKey, $mode)
    {
        $baseUrl = $mode === 'production' 
            ? 'https://api.airwallex.com'
            : 'https://api-demo.airwallex.com';

        $response = wp_remote_post("{$baseUrl}/api/v1/authentication/login", [
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'x-client-id' => $clientId,
                'x-api-key' => $apiKey
            ]),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            throw new \Exception(esc_html($response->get_error_message()));
        }

        if (wp_remote_retrieve_response_code($response) !== 201) {
            throw new \Exception(esc_html__('Invalid Airwallex credentials', 'fluent-cart'));
        }

        return true;
    }

    private function verifyWebhookSignature($payload)
    {
        $serverData = App::request()->server();
        $signature = Arr::get($serverData, 'HTTP_X_SIGNATURE', '');
        $timestamp = Arr::get($serverData, 'HTTP_X_TIMESTAMP', '');
        $webhookSecret = $this->settings->getWebhookSecret();
        
        if (!$signature || !$timestamp || !$webhookSecret) {
            return false;
        }

        $signedPayload = $timestamp . json_encode($payload);
        $expectedSignature = hash_hmac('sha256', $signedPayload, $webhookSecret);
        
        return hash_equals($expectedSignature, $signature);
    }

    private function getApiBaseUrl()
    {
        return $this->settings->getMode() === 'production' 
            ? 'https://api.airwallex.com'
            : 'https://api-demo.airwallex.com';
    }

    private function getWebhookUrl()
    {
        return site_url('?fluent_cart_payment_api_notify=airwallex');
    }

    public function webHookPaymentMethodName()
    {
        return $this->getMeta('route');
    }

    private function generateRequestId($order, $type = 'payment')
    {
        return $order->uuid . '_' . $type . '_' . time();
    }

    private function handlePaymentSucceeded($payload)
    {
        $paymentIntent = $payload['data']['object'] ?? [];
        $orderId = $paymentIntent['metadata']['order_id'] ?? '';
        
        if ($orderId) {
            $order = fluent_cart_get_order($orderId);
            if ($order) {
                $this->handlePaymentSuccess($paymentIntent, $order);
            }
        }
    }

    private function handlePaymentFailed($payload)
    {
        $paymentIntent = $payload['data']['object'] ?? [];
        $orderId = $paymentIntent['metadata']['order_id'] ?? '';
        
        if ($orderId) {
            $order = fluent_cart_get_order($orderId);
            if ($order) {
                $this->handlePaymentFailure($paymentIntent, $order);
            }
        }
    }

    private function handleRefundReceived($payload)
    {
        $refund = $payload['data']['object'] ?? [];
        // Process refund webhook logic here
    }

    private function handlePaymentSuccess($paymentIntent, $order)
    {
        $order->payment_status = 'paid';
        $order->status = 'processing';
        $order->vendor_charge_id = $paymentIntent['id'];
        $order->save();

        do_action('fluent_cart/payment_success', [
            'order' => $order,
            'payment_intent' => $paymentIntent
        ]);
    }

    private function handlePaymentFailure($paymentIntent, $order)
    {
        $order->payment_status = 'failed';
        $order->status = 'failed';
        $order->save();

        do_action('fluent_cart/payment_failed', [
            'order' => $order,
            'payment_intent' => $paymentIntent
        ]);
    }
}

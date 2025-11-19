<?php

namespace FluentCart\App\Modules\PaymentMethods\SquareGateway;

use FluentCart\App\App;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Vite;

class Square extends AbstractPaymentGateway
{
    public array $supportedFeatures = [
        'payment',
        'refund',
        'webhook'
    ];

    public function __construct()
    {
        parent::__construct(new SquareSettingsBase());
    }

    public function meta(): array
    {
        return [
            'title' => __('Square', 'fluent-cart'),
            'route' => 'square',
            'slug' => 'square',
            'description' => __('Pay securely with Square - Credit and Debit Cards', 'fluent-cart'),
            'logo' => Vite::getAssetUrl("images/payment-methods/square-logo.svg"),
            'icon' => Vite::getAssetUrl("images/payment-methods/square-logo.svg"),
            'brand_color' => '#3e4348',
            'status' => $this->settings->get('is_active') === 'yes',
            'upcoming' => true,
        ];
    }

    public function boot()
    {
        // init ipn related actions/class can be initiated here

        add_filter('fluent_cart/payment_methods/square_settings', [$this, 'getSettings'], 10, 2);
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

    public function handleIPN()
    {
        $payload = json_decode(file_get_contents('php://input'), true); // will get from request after verification
        $eventType = $payload['type'] ?? '';
        
        switch ($eventType) {
            case 'payment.updated':
                $this->handlePaymentUpdated($payload);
                break;
            case 'refund.updated':
                $this->handleRefundUpdated($payload);
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
            'application_id' => $this->settings->getApplicationId(),
            'location_id' => $this->settings->getLocationId(),
            'amount' => $subTotal,
            'currency' => $this->storeSettings->get('currency'),
        ];

        wp_send_json([
            'status' => 'success',
            'payment_args' => $paymentArgs,
            'has_subscription' => false
        ], 200);
    }

    public function fields(): array
    {
        $webhook_url = site_url() . '?fct_payment_listener=1&method=square';
        $webhook_instructions = sprintf(
            '<div>
                <p><b>%1$s</b><code class="copyable-content">%2$s</code></p>
                <p>%3$s</p>
                <br>
                <h4>%4$s</h4>
                <br>
                <p>%5$s</p>
                <p>%6$s <a href="https://developer.squareup.com/apps" target="_blank">%7$s</a></p>
                <p>%8$s <code class="copyable-content">%2$s</code></p>
                <p>%9$s</p>
            </div>',
            __('Webhook URL: ', 'fluent-cart'),                    // %1$s
            $webhook_url,                                          // %2$s (reused)
            __('You should configure your Square webhooks to get all updates of your payments remotely.', 'fluent-cart'), // %3$s
            __('How to configure?', 'fluent-cart'),                // %4$s
            __('In your Square Dashboard:', 'fluent-cart'),        // %5$s
            __('Go to Developer > Webhooks >', 'fluent-cart'),     // %6$s
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
                            'test_access_token' => array(
                                'value' => '',
                                'label' => __('Test Access Token', 'fluent-cart'),
                                'type' => 'password',
                                'placeholder' => __('EAAAxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'fluent-cart'),
                                'dependency' => [
                                    'depends_on' => 'payment_mode',
                                    'operator' => '=',
                                    'value' => 'test'
                                ]
                            ),
                            'test_application_id' => array(
                                'value' => '',
                                'label' => __('Test Application ID', 'fluent-cart'),
                                'type' => 'text',
                                'placeholder' => __('sandbox-sq0idb-xxxxxxxxxxxxxxxxxxxxxxxx', 'fluent-cart'),
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
                            'live_access_token' => array(
                                'value' => '',
                                'label' => __('Live Access Token', 'fluent-cart'),
                                'type' => 'password',
                                'placeholder' => __('EAAAxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'fluent-cart'),
                                'dependency' => [
                                    'depends_on' => 'payment_mode',
                                    'operator' => '=',
                                    'value' => 'live'
                                ]
                            ),
                            'live_application_id' => array(
                                'value' => '',
                                'label' => __('Live Application ID', 'fluent-cart'),
                                'type' => 'text',
                                'placeholder' => __('sq0idb-xxxxxxxxxxxxxxxxxxxxxxxx', 'fluent-cart'),
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
        $applicationId = $data['application_id'] ?? '';
        $accessToken = $data['access_token'] ?? '';
        $locationId = $data['location_id'] ?? '';

        if (empty($applicationId) || empty($accessToken) || empty($locationId)) {
            return [
                'status' => 'failed',
                'message' => __('Application ID, Access Token, and Location ID are required', 'fluent-cart')
            ];
        }

        try {
            $testResponse = static::testApiConnection($accessToken, $locationId, $data['payment_mode'] ?? 'sandbox');
            
            return [
                'status' => 'success',
                'message' => __('Square settings validated successfully', 'fluent-cart')
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
        $squareJsUrl = $mode === 'production' 
            ? 'https://js.squareup.com/v2/paymentform'
            : 'https://js.squareupsandbox.com/v2/paymentform';

        return [
            [
                'handle' => 'square-payment-form',
                'src' => $squareJsUrl,
            ],
            [
                'handle' => 'fluent-cart-square-checkout',
                'src' => Vite::getEnqueuePath('public/payment-methods/square-checkout.js'),
                'deps' => ['square-payment-form']
            ]
        ];
    }

    public function getEnqueueStyleSrc(): array
    {
        return [
            [
                'handle' => 'fluent-cart-square-styles',
                'src' => Vite::getEnqueuePath('public/payment-methods/square.css'),
            ]
        ];
    }

    private function createSquarePayment($data)
    {
        $accessToken = $this->settings->getAccessToken();
        $baseUrl = $this->getApiBaseUrl();
        
        $response = wp_remote_post("{$baseUrl}/v2/payments", [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
                'Square-Version' => '2023-10-18'
            ],
            'body' => json_encode($data),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            throw new \Exception(esc_html($response->get_error_message()));
        }

        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }

    private function processSquareRefund($refundData)
    {
        $accessToken = $this->settings->getAccessToken();
        $baseUrl = $this->getApiBaseUrl();
        
        $response = wp_remote_post("{$baseUrl}/v2/refunds", [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
                'Square-Version' => '2023-10-18'
            ],
            'body' => json_encode($refundData),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            throw new \Exception(esc_html($response->get_error_message()));
        }

        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }

    private static function testApiConnection($accessToken, $locationId, $mode)
    {
        $baseUrl = $mode === 'production' 
            ? 'https://connect.squareup.com'
            : 'https://connect.squareupsandbox.com';

        $response = wp_remote_get("{$baseUrl}/v2/locations/{$locationId}", [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Square-Version' => '2023-10-18'
            ],
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            throw new \Exception(esc_html($response->get_error_message()));
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (wp_remote_retrieve_response_code($response) !== 200 || isset($data['errors'])) {
            throw new \Exception(esc_html__('Invalid Square credentials or location ID', 'fluent-cart'));
        }

        return true;
    }

    private function getApiBaseUrl()
    {
        return $this->settings->getMode() === 'production' 
            ? 'https://connect.squareup.com'
            : 'https://connect.squareupsandbox.com';
    }

    private function getWebhookUrl()
    {
        return site_url('?fluent_cart_payment_api_notify=square');
    }

    public function webHookPaymentMethodName()
    {
        return $this->getMeta('route');
    }

    private function generateIdempotencyKey($order, $type = 'payment')
    {
        return md5($order->id . '_' . $type . '_' . time());
    }

    private function getSourceToken()
    {
        // This would be provided by the frontend Square payment form
        $sourceId = App::request()->get('source_id') ?? '';
        return sanitize_text_field($sourceId);
    }

    private function handlePaymentUpdated($payload)
    {
        // Handle payment status updates
        $payment = $payload['data']['object']['payment'] ?? [];
        $orderId = $payment['order_id'] ?? '';
        
        if ($orderId) {
            $order = fluent_cart_get_order_by_uuid($orderId);
            if ($order && $payment['status'] === 'COMPLETED') {
                $this->handlePaymentSuccess($payment, $order);
            }
        }
    }

    private function handleRefundUpdated($payload)
    {
        // Handle refund status updates
        $refund = $payload['data']['object']['refund'] ?? [];
        // Process refund update logic here
    }

    private function handlePaymentSuccess($payment, $order)
    {
        $order->payment_status = 'paid';
        $order->status = 'processing';
        $order->vendor_charge_id = $payment['id'];
        $order->save();

        do_action('fluent_cart/payment_success', [
            'order' => $order,
            'payment_intent' => $payment
        ]);
    }
}

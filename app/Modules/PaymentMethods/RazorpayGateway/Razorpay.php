<?php

namespace FluentCart\App\Modules\PaymentMethods\RazorpayGateway;

use FluentCart\App\App;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class Razorpay extends AbstractPaymentGateway
{
    public array $supportedFeatures = [
        'payment',
        'refund',
        'webhook'
    ];

    public function __construct()
    {
        parent::__construct(new RazorpaySettingsBase());
    }

    public function meta(): array
    {
        return [
            'title' => __('Razorpay', 'fluent-cart'),
            'route' => 'razorpay',
            'slug' => 'razorpay',
            'description' => __('Pay securely with Razorpay - UPI, Cards, NetBanking, and Wallets', 'fluent-cart'),
            'logo' => Vite::getAssetUrl("images/payment-methods/razorpay-logo.svg"),
            'icon' => Vite::getAssetUrl("images/payment-methods/razorpay-logo.svg"),
            'brand_color' => '#3395ff',
            'status' => $this->settings->get('is_active') === 'yes',
            'upcoming' => true,
        ];
    }

    public function boot()
    {
        // init ipn related actions/class
        add_filter('fluent_cart/payment_methods/razorpay_settings', [$this, 'getSettings'], 10, 2);
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
       // todo: will implement later
        die();
    }

    public function refund($refundInfo, $order, $transaction)
    {
        // todo: will implement later
        die();
    }

    public function handleIPN()
    {
        $payload = json_decode(file_get_contents('php://input'), true); // will get from request after verification
        // Verify webhook signature
        if (!$this->verifyWebhookSignature($payload)) {
            http_response_code(401);
            exit('Unauthorized');
        }

        $event = $payload['event'] ?? '';
        
        switch ($event) {
            case 'payment.captured':
                $this->handlePaymentCaptured($payload);
                break;
            case 'payment.failed':
                $this->handlePaymentFailed($payload);
                break;
            case 'refund.processed':
                $this->handleRefundProcessed($payload);
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
            'key' => $this->settings->getKeyId(),
            'amount' => $subTotal * 100, // Convert to paise
            'currency' => $this->storeSettings->get('currency'),
            'name' => $this->storeSettings->get('business_name'),
        ];

        wp_send_json([
            'status' => 'success',
            'payment_args' => $paymentArgs,
            'has_subscription' => false
        ], 200);
    }

    public function fields(): array
    {
        $webhook_url = site_url() . '?fct_payment_listener=1&method=razorpay';
        $webhook_instructions = sprintf(
            '<div>
                <p><b>%1$s</b><code class="copyable-content">%2$s</code></p>
                <p>%3$s</p>
                <br>
                <h4>%4$s</h4>
                <br>
                <p>%5$s</p>
                <p>%6$s <a href="https://dashboard.razorpay.com/app/webhooks" target="_blank">%7$s</a></p>
                <p>%8$s <code class="copyable-content">%2$s</code></p>
                <p>%9$s</p>
            </div>',
            __('Webhook URL: ', 'fluent-cart'),                    // %1$s
            $webhook_url,                                          // %2$s (reused)
            __('You should configure your Razorpay webhooks to get all updates of your payments remotely.', 'fluent-cart'), // %3$s
            __('How to configure?', 'fluent-cart'),                // %4$s
            __('In your Razorpay Dashboard:', 'fluent-cart'),      // %5$s
            __('Go to Settings > Webhooks >', 'fluent-cart'),      // %6$s
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
                            'test_key_id' => array(
                                'value' => '',
                                'label' => __('Test Key ID', 'fluent-cart'),
                                'type' => 'text',
                                'placeholder' => __('rzp_test_xxxxxxxxxxxxxxxx', 'fluent-cart'),
                                'dependency' => [
                                    'depends_on' => 'payment_mode',
                                    'operator' => '=',
                                    'value' => 'test'
                                ]
                            ),
                            'test_key_secret' => array(
                                'value' => '',
                                'label' => __('Test Key Secret', 'fluent-cart'),
                                'type' => 'password',
                                'placeholder' => __('Your test key secret', 'fluent-cart'),
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
                            'live_key_id' => array(
                                'value' => '',
                                'label' => __('Live Key ID', 'fluent-cart'),
                                'type' => 'text',
                                'placeholder' => __('rzp_live_xxxxxxxxxxxxxxxx', 'fluent-cart'),
                                'dependency' => [
                                    'depends_on' => 'payment_mode',
                                    'operator' => '=',
                                    'value' => 'live'
                                ]
                            ),
                            'live_key_secret' => array(
                                'value' => '',
                                'label' => __('Live Key Secret', 'fluent-cart'),
                                'type' => 'password',
                                'placeholder' => __('Your live key secret', 'fluent-cart'),
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
        $keyId = $data['key_id'] ?? '';
        $keySecret = $data['key_secret'] ?? '';

        if (empty($keyId) || empty($keySecret)) {
            return [
                'status' => 'failed',
                'message' => __('Key ID and Key Secret are required', 'fluent-cart')
            ];
        }

        if (!str_starts_with($keyId, 'rzp_test_') && !str_starts_with($keyId, 'rzp_live_')) {
            return [
                'status' => 'failed',
                'message' => __('Invalid Razorpay Key ID format', 'fluent-cart')
            ];
        }

        try {
            $testResponse = static::testApiConnection($keyId, $keySecret);
            
            return [
                'status' => 'success',
                'message' => __('Razorpay settings validated successfully', 'fluent-cart')
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
        return [
            [
                'handle' => 'razorpay-checkout-js',
                'src' => 'https://checkout.razorpay.com/v1/checkout.js',
            ],
            [
                'handle' => 'fluent-cart-razorpay-checkout',
                'src' => Vite::getEnqueuePath('public/payment-methods/razorpay-checkout.js'),
                'deps' => ['razorpay-checkout-js']
            ]
        ];
    }

    public function getEnqueueStyleSrc(): array
    {
        return [
            [
                'handle' => 'fluent-cart-razorpay-styles',
                'src' => Vite::getEnqueuePath('public/payment-methods/razorpay.css'),
            ]
        ];
    }

    private function createRazorpayOrder($data)
    {
        $keyId = $this->settings->getKeyId();
        $keySecret = $this->settings->getKeySecret();
        
        $response = wp_remote_post('https://api.razorpay.com/v1/orders', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($keyId . ':' . $keySecret),
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($data),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            throw new \Exception(esc_html($response->get_error_message()));
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (wp_remote_retrieve_response_code($response) !== 200) {
            $error = $data['error']['description'] ?? 'Unknown error';
            throw new \Exception(esc_html($error));
        }

        return $data;
    }

    private function processRazorpayRefund($paymentId, $refundData)
    {
        $keyId = $this->settings->getKeyId();
        $keySecret = $this->settings->getKeySecret();
        
        $response = wp_remote_post("https://api.razorpay.com/v1/payments/{$paymentId}/refund", [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($keyId . ':' . $keySecret),
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($refundData),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            throw new \Exception(esc_html($response->get_error_message()));
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (wp_remote_retrieve_response_code($response) !== 200) {
            $error = $data['error']['description'] ?? 'Unknown error';
            throw new \Exception(esc_html($error));
        }

        return $data;
    }

    private static function testApiConnection($keyId, $keySecret)
    {
        $response = wp_remote_get('https://api.razorpay.com/v1/payments', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($keyId . ':' . $keySecret)
            ],
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            throw new \Exception(esc_html($response->get_error_message()));
        }

        if (wp_remote_retrieve_response_code($response) !== 200) {
            throw new \Exception(esc_html__('Invalid Razorpay credentials', 'fluent-cart'));
        }

        return true;
    }

    private function verifyWebhookSignature($payload)
    {
        $serverData = App::request()->server();
        $signature = Arr::get($serverData, 'HTTP_X_RAZORPAY_SIGNATURE', '');
        $webhookSecret = $this->settings->getWebhookSecret();
        
        if (!$signature || !$webhookSecret) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', json_encode($payload), $webhookSecret);
        
        return hash_equals($expectedSignature, $signature);
    }

    private function getWebhookUrl()
    {
        return site_url('?fluent_cart_payment_api_notify=razorpay');
    }

    public function webHookPaymentMethodName()
    {
        return $this->getMeta('route');
    }

    private function handlePaymentCaptured($payload)
    {
        $payment = $payload['payload']['payment']['entity'] ?? [];
        $orderId = $payment['notes']['order_id'] ?? '';
        
        if ($orderId) {
            $order = fluent_cart_get_order($orderId);
            if ($order) {
                $this->handlePaymentSuccess($payment, $order);
            }
        }
    }

    private function handlePaymentFailed($payload)
    {
        $payment = $payload['payload']['payment']['entity'] ?? [];
        $orderId = $payment['notes']['order_id'] ?? '';
        
        if ($orderId) {
            $order = fluent_cart_get_order($orderId);
            if ($order) {
                $this->handlePaymentFailure($payment, $order);
            }
        }
    }

    private function handleRefundProcessed($payload)
    {
        $refund = $payload['payload']['refund']['entity'] ?? [];
        // Process refund webhook logic here
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

    private function handlePaymentFailure($payment, $order)
    {
        $order->payment_status = 'failed';
        $order->status = 'failed';
        $order->save();

        do_action('fluent_cart/payment_failed', [
            'order' => $order,
            'payment_intent' => $payment
        ]);
    }
}

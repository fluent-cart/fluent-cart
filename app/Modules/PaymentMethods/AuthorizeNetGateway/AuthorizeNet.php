<?php

namespace FluentCart\App\Modules\PaymentMethods\AuthorizeNetGateway;

use FluentCart\App\App;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Vite;

class AuthorizeNet extends AbstractPaymentGateway
{
    public array $supportedFeatures = [
        'payment',
        'refund',
        'webhook'
    ];

    public function __construct()
    {
        parent::__construct(new AuthorizeNetSettingsBase());
    }

    public function meta(): array
    {
        return [
            'title' => __('Authorize.Net', 'fluent-cart'),
            'route' => 'authorize_net',
            'description' => __('Pay securely with Authorize.Net - Credit and Debit Cards', 'fluent-cart'),
            'logo' => Vite::getAssetUrl("images/payment-methods/authorize-net-logo.svg"),
            'icon' => Vite::getAssetUrl("images/payment-methods/authorize-net-logo.svg"),
            'brand_color' => '#0066cc',
            'status' => $this->settings->get('is_active') === 'yes',
            'upcoming' => true,
        ];
    }

    public function boot()
    {
        // init IPN related class/actions here
        add_filter('fluent_cart/payment_methods/authorize_net_settings', [$this, 'getSettings'], 10, 2);
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        // @todo: will implement later
        die();
    }

    public function refund($refundInfo, $order, $transaction)
    {
        // @todo: will implement later
        die();
    }

    public function handleIPN()
    {
        $payload = json_decode(file_get_contents('php://input'), true); // will get from request after verification
        // Authorize.Net uses webhooks for transaction notifications
        $eventType = $payload['eventType'] ?? '';
        
        switch ($eventType) {
            case 'net.authorize.payment.authcapture.created':
                $this->handlePaymentCreated($payload);
                break;
            case 'net.authorize.payment.refund.created':
                $this->handleRefundCreated($payload);
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
            'client_key' => $this->settings->getClientKey(),
            'api_login_id' => $this->settings->getApiLoginId(),
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
        $webhook_url = site_url() . '?fct_payment_listener=1&method=authorize_net';
        $webhook_instructions = sprintf(
            '<div>
                <p><b>%1$s</b><code class="copyable-content">%2$s</code></p>
                <p>%3$s</p>
                <br>
                <h4>%4$s</h4>
                <br>
                <p>%5$s</p>
                <p>%6$s</p>
                <p>%7$s <code class="copyable-content">%2$s</code></p>
                <p>%8$s</p>
            </div>',
            __('Webhook URL: ', 'fluent-cart'),                    // %1$s
            $webhook_url,                                          // %2$s (reused)
            __('You should configure your Authorize.Net webhooks to get all updates of your payments remotely.', 'fluent-cart'), // %3$s
            __('How to configure?', 'fluent-cart'),                // %4$s
            __('In your Authorize.Net Merchant Interface:', 'fluent-cart'), // %5$s
            __('Go to Account > Settings > Webhooks', 'fluent-cart'), // %6$s
            __('Enter The Webhook URL: ', 'fluent-cart'),          // %7$s
            __('Select payment events', 'fluent-cart')             // %8$s
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
                            'test_api_login' => array(
                                'value' => '',
                                'label' => __('Test API Login ID', 'fluent-cart'),
                                'type' => 'text',
                                'placeholder' => __('Your test API Login ID', 'fluent-cart'),
                                'dependency' => [
                                    'depends_on' => 'payment_mode',
                                    'operator' => '=',
                                    'value' => 'test'
                                ]
                            ),
                            'test_transaction_key' => array(
                                'value' => '',
                                'label' => __('Test Transaction Key', 'fluent-cart'),
                                'type' => 'password',
                                'placeholder' => __('Your test transaction key', 'fluent-cart'),
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
                            'live_api_login' => array(
                                'value' => '',
                                'label' => __('Live API Login ID', 'fluent-cart'),
                                'type' => 'text',
                                'placeholder' => __('Your live API Login ID', 'fluent-cart'),
                                'dependency' => [
                                    'depends_on' => 'payment_mode',
                                    'operator' => '=',
                                    'value' => 'live'
                                ]
                            ),
                            'live_transaction_key' => array(
                                'value' => '',
                                'label' => __('Live Transaction Key', 'fluent-cart'),
                                'type' => 'password',
                                'placeholder' => __('Your live transaction key', 'fluent-cart'),
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
        $apiLoginId = $data['api_login_id'] ?? '';
        $transactionKey = $data['transaction_key'] ?? '';

        if (empty($apiLoginId) || empty($transactionKey)) {
            return [
                'status' => 'failed',
                'message' => __('API Login ID and Transaction Key are required', 'fluent-cart')
            ];
        }

        try {
            $testResponse = static::testApiConnection($apiLoginId, $transactionKey, $data['payment_mode'] ?? 'sandbox');
            
            return [
                'status' => 'success',
                'message' => __('Authorize.Net settings validated successfully', 'fluent-cart')
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
        $acceptJsUrl = $mode === 'production' 
            ? 'https://js.authorize.net/v1/Accept.js'
            : 'https://jstest.authorize.net/v1/Accept.js';

        return [
            [
                'handle' => 'authorize-net-accept-js',
                'src' => $acceptJsUrl,
            ],
            [
                'handle' => 'fluent-cart-authorize-net-checkout',
                'src' => Vite::getEnqueuePath('public/payment-methods/authorize-net-checkout.js'),
                'deps' => ['authorize-net-accept-js']
            ]
        ];
    }

    public function getEnqueueStyleSrc(): array
    {
        return [
            [
                'handle' => 'fluent-cart-authorize-net-styles',
                'src' => Vite::getEnqueuePath('public/payment-methods/authorize-net.css'),
            ]
        ];
    }

    private function createAuthorizeNetTransaction($data)
    {
        $apiUrl = $this->getApiUrl();
        
        $response = wp_remote_post("{$apiUrl}/xml/v1/request.api", [
            'headers' => [
                'Content-Type' => 'application/json'
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

    private function processAuthorizeNetRefund($refundData)
    {
        $apiUrl = $this->getApiUrl();
        
        $response = wp_remote_post("{$apiUrl}/xml/v1/request.api", [
            'headers' => [
                'Content-Type' => 'application/json'
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

    private static function testApiConnection($apiLoginId, $transactionKey, $mode)
    {
        $apiUrl = $mode === 'production' 
            ? 'https://api.authorize.net'
            : 'https://apitest.authorize.net';

        $testData = [
            'getMerchantDetailsRequest' => [
                'merchantAuthentication' => [
                    'name' => $apiLoginId,
                    'transactionKey' => $transactionKey
                ]
            ]
        ];

        $response = wp_remote_post("{$apiUrl}/xml/v1/request.api", [
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($testData),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            throw new \Exception(esc_html($response->get_error_message()));
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || $data['messages']['resultCode'] !== 'Ok') {
            throw new \Exception(esc_html__('Invalid Authorize.Net credentials', 'fluent-cart'));
        }

        return true;
    }

    private function getApiUrl()
    {
        return $this->settings->getMode() === 'production' 
            ? 'https://api.authorize.net'
            : 'https://apitest.authorize.net';
    }

    private function getWebhookUrl()
    {
        return site_url('?fluent_cart_payment_api_notify=authorize_net');
    }

    public function webHookPaymentMethodName()
    {
        return $this->getMeta('route');
    }

    private function getDataDescriptor()
    {
        // This would be provided by the frontend Accept.js
        return App::request()->get('data_descriptor') ?? '';
    }

    private function getDataValue()
    {
        // This would be provided by the frontend Accept.js
        return App::request()->get('data_value') ?? '';
    }

    private function handlePaymentCreated($payload)
    {
        // Handle payment creation webhook
        $transaction = $payload['payload'] ?? [];
        $orderId = $transaction['invoiceNumber'] ?? '';
        
        if ($orderId) {
            $order = fluent_cart_get_order_by_uuid($orderId);
            if ($order) {
                $this->handlePaymentSuccess($transaction, $order);
            }
        }
    }

    private function handleRefundCreated($payload)
    {
        // Handle refund creation webhook
        $refund = $payload['payload'] ?? [];
        // Process refund webhook logic here
    }

    private function handlePaymentSuccess($transaction, $order)
    {
        $order->payment_status = 'paid';
        $order->status = 'processing';
        $order->vendor_charge_id = $transaction['id'];
        $order->save();

        do_action('fluent_cart/payment_success', [
            'order' => $order,
            'payment_intent' => $transaction
        ]);
    }
}

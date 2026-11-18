<?php

namespace Tests\Integration\Http;

use FluentCart\App\Hooks\Handlers\GlobalPaymentHandler;
use FluentCart\App\Modules\PaymentMethods\Cod\Cod;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\PayPal;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Stripe;
use Tests\Support\RestTestCase;

class PaymentMethodTestConnectionTest extends RestTestCase
{
    private $httpMock;

    /** @var array<int, array{url:string, auth:string}> */
    private array $calls = [];

    /** @var array<string, array{0:int, 1:array}> url fragment => [status, body] */
    private array $responses = [];

    public function setUp(): void
    {
        parent::setUp();
        if (!GatewayManager::has('offline_payment')) {
            GatewayManager::getInstance()->register('offline_payment', new Cod());
        }

        $this->httpMock = function ($preempt, $args, $url) {
            foreach ($this->responses as $fragment => [$status, $body]) {
                if (strpos($url, $fragment) === false) {
                    continue;
                }
                $this->calls[] = ['url' => $url, 'auth' => (string) ($args['headers']['Authorization'] ?? '')];

                return [
                    'headers'  => [],
                    'body'     => wp_json_encode($body),
                    'response' => ['code' => $status, 'message' => ''],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }

            return $preempt;
        };
        add_filter('pre_http_request', $this->httpMock, 10, 3);
    }

    public function tearDown(): void
    {
        remove_filter('pre_http_request', $this->httpMock, 10);
        parent::tearDown();
    }

    private function postTest(string $method, string $mode): \WP_REST_Response
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/settings/payment-methods/test-connection');
        $request->set_body_params(['method' => $method, 'mode' => $mode]);

        return $this->dispatchRequest($request);
    }

    private function stripe(string $testKey, string $liveKey): Stripe
    {
        $gateway = new Stripe();
        $gateway->settings->settings['test_secret_key'] = $testKey;
        $gateway->settings->settings['live_secret_key'] = $liveKey;

        return $gateway;
    }

    public function test_only_gateways_that_override_the_method_can_test(): void
    {
        $this->assertFalse(GlobalPaymentHandler::canTestConnection(new Cod()));
        $this->assertTrue(GlobalPaymentHandler::canTestConnection(new Stripe()));
        $this->assertTrue(GlobalPaymentHandler::canTestConnection(new PayPal()));
    }

    public function test_settings_response_flags_testable_gateways(): void
    {
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/settings/payment-methods');
        $request->set_query_params(['method' => 'offline_payment']);

        $this->assertFalse($this->dispatchRequest($request)->get_data()['can_test_connection']);
    }

    public function test_route_rejects_an_unknown_mode(): void
    {
        $response = $this->postTest('offline_payment', 'sandbox');

        $this->assertSame(422, $response->get_status());
        $this->assertSame([], $this->calls);
    }

    public function test_route_rejects_a_gateway_without_a_test(): void
    {
        $response = $this->postTest('offline_payment', 'test');

        $this->assertSame(422, $response->get_status());
        $this->assertSame('Connection test is not available for this payment method.', $response->get_data()['message']);
    }

    public function test_stripe_accepts_a_test_key_in_test_mode(): void
    {
        $this->responses = ['api.stripe.com/v1/balance' => [200, ['livemode' => false, 'available' => [['currency' => 'eur']]]]];

        $result = $this->stripe('sk_test_suite', '')->testConnection('test');

        $this->assertSame('Stripe accepted the test key.', $result['message']);
        $this->assertCount(1, $this->calls);
    }

    public function test_stripe_rejects_a_live_key_saved_as_the_test_key(): void
    {
        $this->responses = ['api.stripe.com/v1/balance' => [200, ['livemode' => true, 'available' => []]]];

        $result = $this->stripe('sk_live_suite', '')->testConnection('test');

        $this->assertWPError($result);
        $this->assertSame('mode_mismatch', $result->get_error_code());
    }

    public function test_stripe_reports_a_missing_live_key_without_calling_stripe(): void
    {
        $result = $this->stripe('sk_test_suite', '')->testConnection('live');

        $this->assertWPError($result);
        $this->assertSame('missing_credentials', $result->get_error_code());
        $this->assertSame([], $this->calls);
    }

    public function test_stripe_passes_on_the_api_error(): void
    {
        $this->responses = ['api.stripe.com/v1/balance' => [401, ['error' => ['message' => 'Invalid API Key provided: sk_test_***']]]];

        $result = $this->stripe('sk_test_bad', '')->testConnection('test');

        $this->assertWPError($result);
        $this->assertStringContainsString('Invalid API Key', $result->get_error_message());
    }

    public function test_paypal_checks_the_credentials_against_the_host_of_the_mode(): void
    {
        $this->responses = [
            'api-m.sandbox.paypal.com/v1/oauth2/token' => [200, ['access_token' => 'A21-sandbox', 'expires_in' => 3600]],
            '//api.paypal.com/v1/oauth2/token'          => [401, ['error' => 'invalid_client', 'error_description' => 'Client Authentication failed']],
        ];

        $gateway = new PayPal();
        $gateway->settings->settings['test_client_id'] = 'sandbox-id';
        $gateway->settings->settings['test_client_secret'] = 'sandbox-secret';
        $gateway->settings->settings['live_client_id'] = 'sandbox-id';
        $gateway->settings->settings['live_client_secret'] = 'sandbox-secret';

        $test = $gateway->testConnection('test');
        $live = $gateway->testConnection('live');

        $this->assertSame('PayPal accepted the sandbox credentials.', $test['message']);
        $this->assertSame('Basic ' . base64_encode('sandbox-id:sandbox-secret'), $this->calls[0]['auth']);
        $this->assertWPError($live);
        $this->assertSame('Client Authentication failed', $live->get_error_message());
        $this->assertFalse(fluent_cart_get_option('_paypal_access_token_test', false, false));
    }

    public function test_paypal_reports_missing_credentials_without_calling_paypal(): void
    {
        $gateway = new PayPal();
        $gateway->settings->settings['live_client_id'] = '';
        $gateway->settings->settings['live_client_secret'] = '';

        $result = $gateway->testConnection('live');

        $this->assertWPError($result);
        $this->assertSame('missing_credentials', $result->get_error_code());
        $this->assertSame([], $this->calls);
    }
}

<?php

namespace Tests\Integration\Modules\PaymentMethods;

use FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\API;
use lucatume\WPBrowser\TestCase\WPTestCase;

class PayPalOrderCreateTest extends WPTestCase
{
    public function test_order_create_extra_args_are_optional_keeping_two_step_default(): void
    {
        // The renewal engine extended createOrder with $extraBody/$extraHeaders so
        // vault charges can send payment_source + PayPal-Request-Id (Processor::
        // chargeRenewal). Both MUST stay optional: a bare createOrder($purchaseUnit)
        // call remains the two-step order that deliberately omits the request id
        // (asserted by test_two_step_order_create_omits_paypal_request_id).
        $method = new \ReflectionMethod(API::class, 'createOrder');

        $this->assertSame(3, $method->getNumberOfParameters());
        $this->assertSame(1, $method->getNumberOfRequiredParameters(), 'extraBody/extraHeaders must stay optional');
        foreach (array_slice($method->getParameters(), 1) as $param) {
            $this->assertTrue($param->isDefaultValueAvailable(), "\${$param->getName()} must have a default");
            $this->assertSame([], $param->getDefaultValue(), "\${$param->getName()} must default to []");
        }
    }

    public function test_two_step_order_create_omits_paypal_request_id(): void
    {
        $orderRequest = [];
        $httpMock = $this->mockPayPalRequest('/v2/checkout/orders', $orderRequest, [
            'id'     => 'TEST-PAYPAL-ORDER',
            'status' => 'CREATED',
        ], 201);

        add_filter('pre_http_request', $httpMock, 10, 3);

        try {
            $result = API::createOrder([
                'reference_id' => 'transaction-uuid',
                'amount'       => [
                    'currency_code' => 'USD',
                    'value'         => '10.00',
                ],
            ]);
        } finally {
            remove_filter('pre_http_request', $httpMock, 10);
        }

        $this->assertSame('TEST-PAYPAL-ORDER', $result['id']);
        $this->assertIsArray($orderRequest);
        $this->assertArrayNotHasKey('PayPal-Request-Id', $orderRequest['headers']);
    }

    public function test_vault_charge_order_create_sends_payment_source_and_request_id(): void
    {
        // Mirrors Processor::chargeRenewal's exact call shape: payment_source
        // vault_id in the body, PayPal-Request-Id keyed by (order, attempt) in
        // the headers, and the legacy application_context stripped (it cannot be
        // combined with payment_source). Ignoring either extra argument, or
        // keeping application_context, fails here.
        $orderRequest = [];
        $httpMock = $this->mockPayPalRequest('/v2/checkout/orders', $orderRequest, [
            'id'     => 'TEST-PAYPAL-VAULT-ORDER',
            'status' => 'COMPLETED',
        ], 201);

        add_filter('pre_http_request', $httpMock, 10, 3);

        try {
            $result = API::createOrder([
                'reference_id' => 'transaction-uuid',
                'amount'       => [
                    'currency_code' => 'USD',
                    'value'         => '10.00',
                ],
            ], [
                'payment_source' => ['paypal' => ['vault_id' => 'vault-token-123']],
            ], [
                'PayPal-Request-Id' => 'fct_system_charge_55_2',
            ]);
        } finally {
            remove_filter('pre_http_request', $httpMock, 10);
        }

        $this->assertSame('TEST-PAYPAL-VAULT-ORDER', $result['id']);
        $this->assertIsArray($orderRequest);

        $body = json_decode($orderRequest['body'], true);
        $this->assertSame(
            'vault-token-123',
            $body['payment_source']['paypal']['vault_id'] ?? null,
            'vault charge must send payment_source.paypal.vault_id — dropping $extraBody breaks off-session renewals'
        );
        $this->assertArrayNotHasKey(
            'application_context',
            $body,
            'payment_source and the legacy application_context cannot be combined — createOrder must strip it'
        );
        $this->assertSame(
            'fct_system_charge_55_2',
            $orderRequest['headers']['PayPal-Request-Id'] ?? null,
            'vault charge must carry the (order, attempt)-keyed PayPal-Request-Id — dropping $extraHeaders allows duplicate charges on a scheduler double-fire'
        );
    }

    public function test_generic_post_request_can_send_paypal_request_id(): void
    {
        $refundRequest = [];
        $httpMock = $this->mockPayPalRequest('/v2/payments/captures/capture-id/refund', $refundRequest, [
            'id'     => 'TEST-PAYPAL-REFUND',
            'status' => 'COMPLETED',
        ], 201);

        add_filter('pre_http_request', $httpMock, 10, 3);

        try {
            $result = API::makeRequest(
                'payments/captures/capture-id/refund',
                'v2',
                'POST',
                [],
                'test',
                ['PayPal-Request-Id' => 'refund-request-id']
            );
        } finally {
            remove_filter('pre_http_request', $httpMock, 10);
        }

        $this->assertSame('TEST-PAYPAL-REFUND', $result['id']);
        $this->assertIsArray($refundRequest);
        $this->assertSame('refund-request-id', $refundRequest['headers']['PayPal-Request-Id']);
    }

    private function mockPayPalRequest(string $targetPath, &$capturedRequest, array $responseBody, int $status)
    {
        return function ($preempt, $args, $url) use ($targetPath, &$capturedRequest, $responseBody, $status) {
            if (strpos($url, '/v1/oauth2/token') !== false) {
                return $this->httpResponse([
                    'access_token' => 'test-access-token',
                    'expires_in'   => 3600,
                ]);
            }

            if (strpos($url, $targetPath) !== false) {
                $capturedRequest = $args;

                return $this->httpResponse($responseBody, $status);
            }

            return $preempt;
        };
    }

    private function httpResponse(array $body, int $status = 200): array
    {
        return [
            'headers'  => [],
            'body'     => wp_json_encode($body),
            'response' => [
                'code'    => $status,
                'message' => $status === 201 ? 'Created' : 'OK',
            ],
            'cookies'  => [],
            'filename' => null,
        ];
    }
}

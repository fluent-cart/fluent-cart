<?php

namespace Tests\Integration\Modules\PaymentMethods;

use FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\API;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Traits\MocksPayPalHttp;

/**
 * PayPal rejects a literal JSON [] request body with MALFORMED_REQUEST_JSON —
 * empty-args POSTs (the server-side capture fallback) must send {}.
 */
class PayPalApiEmptyBodyTest extends DatabaseTestCase
{
    use MocksPayPalHttp;

    /** @var array<int, string> raw bodies of intercepted PayPal POSTs */
    private array $sentBodies = [];

    /** @var callable|null */
    private $bodySpy = null;

    public function setUp(): void
    {
        parent::setUp();

        $this->installPayPalHttpMock();

        $this->sentBodies = [];
        $this->bodySpy = function ($preempt, $args, $url) {
            if (strpos($url, 'paypal.com') !== false && strpos($url, 'oauth2/token') === false) {
                $this->sentBodies[] = (string) ($args['body'] ?? '');
            }
            return $preempt;
        };
        add_filter('pre_http_request', $this->bodySpy, 9, 3);
    }

    public function tearDown(): void
    {
        if ($this->bodySpy) {
            remove_filter('pre_http_request', $this->bodySpy, 9);
            $this->bodySpy = null;
        }
        $this->removePayPalHttpMock();

        parent::tearDown();
    }

    public function test_capture_order_sends_empty_object_body(): void
    {
        $this->scriptPayPal('checkout/orders/PP-1/capture', 201, [
            'id'     => 'PP-1',
            'status' => 'COMPLETED',
        ]);

        API::captureOrder('PP-1');

        $this->assertCount(1, $this->sentBodies);
        $this->assertSame('{}', $this->sentBodies[0], 'an empty-args POST must serialize to {}, never []');
    }

    public function test_non_empty_body_is_unchanged(): void
    {
        $this->scriptPayPal('payments/captures/CAP-1/refund', 201, [
            'id'     => 'REFUND-1',
            'status' => 'COMPLETED',
        ]);

        API::makeRequest('payments/captures/CAP-1/refund', 'v2', 'POST', [
            'note_to_payer' => 'test',
        ]);

        $this->assertCount(1, $this->sentBodies);
        $this->assertSame('{"note_to_payer":"test"}', $this->sentBodies[0]);
    }
}

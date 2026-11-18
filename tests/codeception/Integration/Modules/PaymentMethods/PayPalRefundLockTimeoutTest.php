<?php

namespace Tests\Integration\Modules\PaymentMethods;

use FluentCart\App\Modules\PaymentMethods\PayPalGateway\IPN;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Doubles\FakeRefundGateway;
use Tests\Support\Traits\BuildsPayPalWebhookRequests;
use Tests\Support\Traits\BuildsRefundFixtures;
use Tests\Support\Traits\MocksPayPalHttp;

class PayPalRefundLockTimeoutTest extends DatabaseTestCase
{
    use BuildsPayPalWebhookRequests, BuildsRefundFixtures, MocksPayPalHttp;

    public function setUp(): void
    {
        parent::setUp();

        if (!defined('FCT_PAYPAL_TEST_WEBHOOK_ID')) {
            define('FCT_PAYPAL_TEST_WEBHOOK_ID', 'WH-FC-SEC-05-TEST');
        }

        FakeRefundGateway::register();
        $this->installPayPalHttpMock();
        $this->installPayPalSignatureHeaders();
    }

    public function tearDown(): void
    {
        $this->restorePayPalSignatureHeaders();
        $this->removePayPalHttpMock();

        parent::tearDown();
    }

    public function test_capture_refund_webhook_answers_500_while_the_order_lock_is_busy_and_records_on_redelivery(): void
    {
        $charge = $this->paidCharge(['vendor_charge_id' => 'CAPTURE-BUSY', 'payment_method' => 'paypal']);
        $lockName = 'fct_refund_' . $charge->order_id;

        $body = json_encode([
            'id'         => 'WH-EVT-1',
            'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
            'resource'   => [
                'id'     => 'REFUND-BUSY',
                'amount' => ['value' => '30.00', 'currency_code' => 'USD'],
                'links'  => [['rel' => 'up', 'href' => 'https://api.sandbox.paypal.com/v2/payments/captures/CAPTURE-BUSY']],
            ],
        ]);

        $rival = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        if (!$rival) {
            $this->fail('could not open a second DB connection to simulate the admin refund');
        }

        try {
            $row = mysqli_fetch_row(mysqli_query($rival, "SELECT GET_LOCK('" . mysqli_real_escape_string($rival, $lockName) . "', 5)"));
            $this->assertSame('1', (string) $row[0], 'the rival connection must hold the order refund lock');

            $this->scriptPayPal('verify-webhook-signature', 200, ['verification_status' => 'SUCCESS']);
            $status = (new IPN())->handleWebhookRequest($body);

            $this->assertSame(500, $status, 'a busy order lock must answer non-2xx so PayPal redelivers');
            $this->assertCount(0, $this->refundRows($charge->order_id));
        } finally {
            mysqli_query($rival, "SELECT RELEASE_LOCK('" . mysqli_real_escape_string($rival, $lockName) . "')");
            mysqli_close($rival);
        }

        $this->scriptPayPal('verify-webhook-signature', 200, ['verification_status' => 'SUCCESS']);
        $status = (new IPN())->handleWebhookRequest($body);

        $this->assertSame(200, $status);
        $this->assertCount(1, $this->refundRows($charge->order_id));
    }
}

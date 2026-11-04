<?php

namespace Tests\Integration\Modules\PaymentMethods;

use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\PayPalHelper;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Traits\MocksPayPalHttp;

class PayPalRefundStatusTest extends DatabaseTestCase
{
    use MocksPayPalHttp;

    public function setUp(): void
    {
        parent::setUp();
        $this->installPayPalHttpMock();
    }

    public function tearDown(): void
    {
        $this->removePayPalHttpMock();
        parent::tearDown();
    }

    /**
     * @dataProvider acceptedStatuses
     */
    public function test_accepted_refund_returns_its_id(string $status): void
    {
        $this->scriptPayPal('payments/captures/CAP-ACC/refund', 201, ['id' => 'REF-' . $status, 'status' => $status]);

        $this->assertSame('REF-' . $status, PayPalHelper::processRemoteRefund($this->capture('CAP-ACC'), 3000, []));
    }

    public function acceptedStatuses(): array
    {
        return [['COMPLETED'], ['PENDING']];
    }

    /**
     * @dataProvider rejectedStatuses
     */
    public function test_rejected_refund_is_an_error(string $status): void
    {
        $this->scriptPayPal('payments/captures/CAP-REJ/refund', 201, ['id' => 'REF-' . $status, 'status' => $status]);

        $this->assertInstanceOf(\WP_Error::class, PayPalHelper::processRemoteRefund($this->capture('CAP-REJ'), 3000, []));
    }

    public function rejectedStatuses(): array
    {
        return [['FAILED'], ['CANCELLED']];
    }

    private function capture(string $captureId): OrderTransaction
    {
        $transaction = new OrderTransaction();
        $transaction->vendor_charge_id = $captureId;
        $transaction->uuid = 'paypal-refund-status';
        $transaction->currency = 'USD';

        return $transaction;
    }
}

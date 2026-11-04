<?php

namespace Tests\Integration\Modules\PaymentMethods;

use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\StripeHelper;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Traits\ConfiguresStripeTestMode;

class StripeRefundStatusTest extends DatabaseTestCase
{
    use ConfiguresStripeTestMode;

    /** @var callable|null */
    private $stripeMock = null;

    public function setUp(): void
    {
        parent::setUp();
        $this->enableStripeTestMode();
    }

    public function tearDown(): void
    {
        if ($this->stripeMock) {
            remove_filter('pre_http_request', $this->stripeMock, 10);
        }
        parent::tearDown();
    }

    /**
     * @dataProvider acceptedStatuses
     */
    public function test_accepted_refund_returns_its_id(string $status): void
    {
        $this->stripeRefundAnswers($status);

        $this->assertSame('re_' . $status, StripeHelper::processRemoteRefund($this->charge(), 3000, []));
    }

    public function acceptedStatuses(): array
    {
        return [['succeeded'], ['pending'], ['requires_action']];
    }

    /**
     * @dataProvider rejectedStatuses
     */
    public function test_rejected_refund_is_an_error(string $status): void
    {
        $this->stripeRefundAnswers($status);

        $this->assertInstanceOf(\WP_Error::class, StripeHelper::processRemoteRefund($this->charge(), 3000, []));
    }

    public function rejectedStatuses(): array
    {
        return [['failed'], ['canceled']];
    }

    private function stripeRefundAnswers(string $status): void
    {
        $this->stripeMock = function ($response, $args, $url) use ($status) {
            if (strpos($url, 'api.stripe.com/v1/refunds') === false) {
                return $response;
            }

            return [
                'headers'  => [],
                'body'     => wp_json_encode(['id' => 're_' . $status, 'object' => 'refund', 'status' => $status]),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
            ];
        };
        add_filter('pre_http_request', $this->stripeMock, 10, 3);
    }

    private function charge(): OrderTransaction
    {
        $transaction = new OrderTransaction();
        $transaction->vendor_charge_id = 'pi_refund_status';
        $transaction->currency = 'USD';
        $transaction->payment_mode = 'test';

        return $transaction;
    }
}

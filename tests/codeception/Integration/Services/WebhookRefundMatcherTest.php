<?php

namespace Tests\Integration\Services;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Activity;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Webhook\IPN as StripeIPN;
use FluentCart\App\Services\Payments\Refund;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Doubles\FakeRefundGateway;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Traits\BuildsRefundFixtures;
use Tests\Support\Traits\ConfiguresStripeTestMode;

class WebhookRefundMatcherTest extends DatabaseTestCase
{
    use BuildsRefundFixtures, ConfiguresStripeTestMode;

    private array $eventCounters = [];

    public function setUp(): void
    {
        parent::setUp();
        FakeRefundGateway::register();
    }

    public function tearDown(): void
    {
        FakeRefundGateway::remove();
        foreach ($this->eventCounters as $counter) {
            remove_action('fluent_cart/order_refunded', $counter->listener);
        }
        parent::tearDown();
    }

    public function test_webhook_adopts_force_local_row_without_counting_it_again(): void
    {
        $charge = $this->paidCharge();
        (new Refund())->processRefund($charge, 3000, ['force_local' => 'true']);
        $events = $this->countEvents();

        $result = Refund::createOrRecordRefund(['vendor_charge_id' => 're_late', 'total' => 3000], $this->freshCharge($charge));

        $rows = $this->refundRows($charge->order_id);
        $this->assertCount(1, $rows);
        $this->assertSame($rows[0]->id, $result->id);
        $this->assertSame('re_late', $rows[0]->vendor_charge_id);
        $this->assertSame(Refund::ROUTE_FORCED_LOCAL, $rows[0]->meta['refund_route']);
        $this->assertSame(get_current_user_id(), (int) $rows[0]->meta['created_by']);
        $this->assertSame(3000, $this->chargeRefundedTotal($charge->id));
        $this->assertSame(3000, (int) Order::find($charge->order_id)->total_refund);
        $this->assertSame(0, $events->fired);
    }

    public function test_webhook_does_not_adopt_local_row_of_another_charge(): void
    {
        $charge = $this->paidCharge();
        $otherCharge = $this->secondCharge($charge);
        $otherRow = $this->localRefundRow($otherCharge, 3000);

        $result = Refund::createOrRecordRefund(['vendor_charge_id' => 're_other', 'total' => 3000], $charge);

        $this->assertNotSame($otherRow->id, $result->id);
        $this->assertCount(2, $this->refundRows($charge->order_id));
        $this->assertSame('', (string) OrderTransaction::query()->findOrFail($otherRow->id)->vendor_charge_id);
    }

    public function test_refund_on_one_charge_leaves_the_other_charge_total_alone(): void
    {
        $charge = $this->paidCharge();
        $otherCharge = $this->secondCharge($charge);
        $this->localRefundRow($otherCharge, 2000);
        $otherCharge->meta = ['refunded_total' => 2000];
        $otherCharge->save();

        Refund::createOrRecordRefund(['vendor_charge_id' => 're_first', 'total' => 3000], $charge);

        $this->assertSame(3000, $this->chargeRefundedTotal($charge->id));
        $this->assertSame(2000, $this->chargeRefundedTotal($otherCharge->id));
    }

    public function test_webhook_does_not_adopt_failed_local_row(): void
    {
        $charge = $this->paidCharge();
        $failed = $this->localRefundRow($charge, 3000, Status::TRANSACTION_FAILED);

        $result = Refund::createOrRecordRefund(['vendor_charge_id' => 're_new', 'total' => 3000], $charge);

        $this->assertNotSame($failed->id, $result->id);
        $this->assertCount(2, $this->refundRows($charge->order_id));
    }

    public function test_failed_webhook_refund_does_not_adopt_refunded_local_row(): void
    {
        $charge = $this->paidCharge();
        $local = $this->localRefundRow($charge, 3000);

        $result = Refund::createOrRecordRefund([
            'vendor_charge_id' => 're_failed',
            'total'            => 3000,
            'status'           => Status::TRANSACTION_FAILED,
        ], $charge);

        $this->assertNotSame($local->id, $result->id);
        $this->assertSame('', (string) OrderTransaction::find($local->id)->vendor_charge_id);
    }

    public function test_redelivered_webhook_is_a_no_op(): void
    {
        $charge = $this->paidCharge();
        $events = $this->countEvents();

        $first = Refund::createOrRecordRefund(['vendor_charge_id' => 're_same', 'total' => 3000], $charge);
        $second = Refund::createOrRecordRefund(['vendor_charge_id' => 're_same', 'total' => 3000], $this->freshCharge($charge));

        $this->assertSame($first->id, $second->id);
        $this->assertCount(1, $this->refundRows($charge->order_id));
        $this->assertSame(3000, $this->chargeRefundedTotal($charge->id));
        $this->assertSame(1, $events->fired);
    }

    public function test_pending_refund_is_not_counted_until_it_completes(): void
    {
        $charge = $this->paidCharge();
        $events = $this->countEvents();

        Refund::createOrRecordRefund([
            'vendor_charge_id' => 're_pending',
            'total'            => 3000,
            'status'           => Status::TRANSACTION_PENDING,
        ], $charge);

        $this->assertSame(0, $this->chargeRefundedTotal($charge->id));
        $this->assertSame(0, $events->fired);

        $completed = Refund::createOrRecordRefund([
            'vendor_charge_id' => 're_pending',
            'total'            => 3000,
            'status'           => Status::TRANSACTION_REFUNDED,
        ], $this->freshCharge($charge));

        $this->assertSame(Status::TRANSACTION_REFUNDED, $completed->status);
        $this->assertCount(1, $this->refundRows($charge->order_id));
        $this->assertSame(3000, $this->chargeRefundedTotal($charge->id));
        $this->assertSame(3000, (int) Order::find($charge->order_id)->total_refund);
        $this->assertSame(1, $events->fired);
    }

    public function test_pending_row_counted_at_insert_by_older_version_is_not_counted_again(): void
    {
        $charge = $this->paidCharge(['meta' => ['refunded_total' => 4000]]);
        $legacyRow = $this->localRefundRow($charge, 4000, Status::TRANSACTION_PENDING);
        $legacyRow->vendor_charge_id = 'adj_legacy';
        $legacyRow->save();
        $events = $this->countEvents();

        Refund::createOrRecordRefund([
            'vendor_charge_id' => 'adj_legacy',
            'total'            => 4000,
            'status'           => Status::TRANSACTION_REFUNDED,
        ], $this->freshCharge($charge));

        $this->assertSame(4000, $this->chargeRefundedTotal($charge->id));
        $this->assertSame(1, $events->fired);
    }

    public function test_pending_refund_that_fails_is_never_counted(): void
    {
        $charge = $this->paidCharge();
        Refund::createOrRecordRefund([
            'vendor_charge_id' => 're_rejected',
            'total'            => 3000,
            'status'           => Status::TRANSACTION_PENDING,
        ], $charge);

        $result = Refund::createOrRecordRefund([
            'vendor_charge_id' => 're_rejected',
            'total'            => 3000,
            'status'           => Status::TRANSACTION_FAILED,
        ], $this->freshCharge($charge));

        $this->assertSame(Status::TRANSACTION_FAILED, $result->status);
        $this->assertSame(0, $this->chargeRefundedTotal($charge->id));
    }

    public function test_delivery_holding_a_stale_pending_row_cannot_overwrite_or_recount_the_winner(): void
    {
        $charge = $this->paidCharge();
        Refund::createOrRecordRefund([
            'vendor_charge_id' => 're_race',
            'total'            => 3000,
            'status'           => Status::TRANSACTION_PENDING,
        ], $charge);
        $staleForFailure = $this->refundRows($charge->order_id)[0];
        $staleForCompletion = $this->refundRows($charge->order_id)[0];
        $events = $this->countEvents();

        Refund::createOrRecordRefund([
            'vendor_charge_id' => 're_race',
            'total'            => 3000,
            'status'           => Status::TRANSACTION_REFUNDED,
        ], $this->freshCharge($charge));

        $advance = new \ReflectionMethod(Refund::class, 'advanceRefundStatus');
        $advance->setAccessible(true);
        $advance->invoke(null, $staleForFailure, Status::TRANSACTION_FAILED, $this->freshCharge($charge));
        $advance->invoke(null, $staleForCompletion, Status::TRANSACTION_REFUNDED, $this->freshCharge($charge));

        $this->assertSame(Status::TRANSACTION_REFUNDED, OrderTransaction::query()->findOrFail($staleForFailure->id)->status);
        $this->assertSame(3000, $this->chargeRefundedTotal($charge->id));
        $this->assertSame(1, $events->fired);
    }

    public function test_redelivery_repairs_an_order_left_behind_by_an_interrupted_refund(): void
    {
        $charge = $this->paidCharge();
        $stranded = $this->localRefundRow($charge, 3000);
        $stranded->vendor_charge_id = 're_stranded';
        $stranded->save();
        $this->assertSame(0, (int) Order::find($charge->order_id)->total_refund);
        $events = $this->countEvents();

        Refund::createOrRecordRefund(['vendor_charge_id' => 're_stranded', 'total' => 3000], $this->freshCharge($charge));

        $order = Order::find($charge->order_id);
        $this->assertSame(3000, (int) $order->total_refund);
        $this->assertSame(Status::PAYMENT_PARTIALLY_REFUNDED, $order->payment_status);
        $this->assertSame(3000, $this->chargeRefundedTotal($charge->id));
        $this->assertSame(1, $events->fired);
        $activityRows = Activity::query()->where('module_id', $charge->order_id)->count();

        Refund::createOrRecordRefund(['vendor_charge_id' => 're_stranded', 'total' => 3000], $this->freshCharge($charge));

        $this->assertSame(3000, (int) Order::find($charge->order_id)->total_refund);
        $this->assertSame(1, $events->fired);
        $this->assertSame($activityRows, Activity::query()->where('module_id', $charge->order_id)->count());
    }

    public function test_late_pending_event_does_not_regress_a_completed_refund(): void
    {
        $charge = $this->paidCharge();
        Refund::createOrRecordRefund(['vendor_charge_id' => 're_done', 'total' => 3000], $charge);

        $result = Refund::createOrRecordRefund([
            'vendor_charge_id' => 're_done',
            'total'            => 3000,
            'status'           => Status::TRANSACTION_PENDING,
        ], $this->freshCharge($charge));

        $this->assertSame(Status::TRANSACTION_REFUNDED, $result->status);
        $this->assertSame(3000, $this->chargeRefundedTotal($charge->id));
    }

    public function test_adopted_row_takes_the_webhook_reason_when_it_has_none(): void
    {
        $charge = $this->paidCharge();
        $local = $this->localRefundRow($charge, 3000);

        Refund::createOrRecordRefund([
            'vendor_charge_id' => 're_reason',
            'total'            => 3000,
            'meta'             => ['reason' => 'requested_by_customer'],
        ], $charge);

        $this->assertSame('requested_by_customer', OrderTransaction::find($local->id)->meta['reason']);
    }

    public function test_late_pending_event_does_not_revive_a_failed_refund(): void
    {
        $charge = $this->paidCharge();
        Refund::createOrRecordRefund([
            'vendor_charge_id' => 're_rejected_first',
            'total'            => 3000,
            'status'           => Status::TRANSACTION_FAILED,
        ], $charge);

        $result = Refund::createOrRecordRefund([
            'vendor_charge_id' => 're_rejected_first',
            'total'            => 3000,
            'status'           => Status::TRANSACTION_PENDING,
        ], $this->freshCharge($charge));

        $this->assertSame(Status::TRANSACTION_FAILED, $result->status);
    }

    public function test_empty_vendor_refund_id_is_rejected(): void
    {
        $charge = $this->paidCharge();
        $this->localRefundRow($charge, 3000);

        $result = Refund::createOrRecordRefund(['vendor_charge_id' => '', 'total' => 3000], $charge);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertCount(1, $this->refundRows($charge->order_id));
    }

    public function test_stripe_webhook_adopts_force_local_row(): void
    {
        $charge = $this->paidCharge(['vendor_charge_id' => 'pi_forced']);
        (new Refund())->processRefund($charge, 3000, ['force_local' => 'true']);
        $events = $this->countEvents();

        $this->stripeChargeRefunded($charge, 're_stripe_late', 3000);

        $rows = $this->refundRows($charge->order_id);
        $this->assertCount(1, $rows);
        $this->assertSame('re_stripe_late', $rows[0]->vendor_charge_id);
        $this->assertSame(3000, $this->chargeRefundedTotal($charge->id));
        $this->assertSame(0, $events->fired);
    }

    public function test_stripe_webhook_does_not_announce_a_refund_the_admin_recorded_during_its_api_call(): void
    {
        $this->enableStripeTestMode();
        $charge = $this->paidCharge(['vendor_charge_id' => 'pi_race_10']);
        $events = $this->countEvents();

        $adminRefund = function ($response, $args, $url) use ($charge) {
            if (strpos($url, 'api.stripe.com') === false) {
                return $response;
            }
            FakeRefundGateway::$nextResult = 're_race_10';
            (new Refund())->processRefund(OrderTransaction::query()->findOrFail($charge->id), 3000);

            return [
                'headers'  => [],
                'body'     => wp_json_encode(['data' => [['id' => 're_race_10', 'amount' => 3000, 'created' => time()]]]),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
            ];
        };
        add_filter('pre_http_request', $adminRefund, 10, 3);

        $event = json_decode(json_encode([
            'livemode' => false,
            'data'     => ['object' => ['id' => 'ch_race_10', 'payment_intent' => 'pi_race_10', 'currency' => 'usd', 'refunds' => ['data' => []]]],
        ]));
        (new StripeIPN())->handleChargeRefunded(['event' => $event, 'order' => $charge->order]);
        remove_filter('pre_http_request', $adminRefund, 10);

        $this->assertCount(1, $this->refundRows($charge->order_id));
        $this->assertSame(3000, $this->chargeRefundedTotal($charge->id));
        $this->assertSame(1, $events->fired);
    }

    public function test_webhook_waits_for_the_order_refund_lock_and_records_nothing_while_it_is_held(): void
    {
        $charge = $this->paidCharge();
        $lockName = 'fct_refund_' . $charge->order_id;

        $rival = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        if (!$rival) {
            $this->markTestSkipped('could not open a second DB connection to simulate the admin refund');
        }

        try {
            $row = mysqli_fetch_row(mysqli_query($rival, "SELECT GET_LOCK('" . mysqli_real_escape_string($rival, $lockName) . "', 5)"));
            $this->assertSame('1', (string) $row[0], 'the rival connection must hold the order refund lock');

            $result = Refund::createOrRecordRefund(['vendor_charge_id' => 're_race', 'total' => 3000], $charge);

            $this->assertTrue(is_wp_error($result), 'a busy order lock must be reported so the gateway redelivers');
            $this->assertSame('refund_lock_timeout', $result->get_error_code());
            $this->assertCount(0, $this->refundRows($charge->order_id), 'nothing may be inserted while another path records a refund for the order');
        } finally {
            mysqli_query($rival, "SELECT RELEASE_LOCK('" . mysqli_real_escape_string($rival, $lockName) . "')");
        }

        try {
            $recorded = Refund::createOrRecordRefund(['vendor_charge_id' => 're_race', 'total' => 3000], $this->freshCharge($charge));

            $this->assertFalse(is_wp_error($recorded));
            $this->assertCount(1, $this->refundRows($charge->order_id));
            $this->assertSame(3000, $this->chargeRefundedTotal($charge->id));

            $free = mysqli_fetch_row(mysqli_query($rival, "SELECT IS_FREE_LOCK('" . mysqli_real_escape_string($rival, $lockName) . "')"));
            $this->assertSame('1', (string) $free[0], 'the order lock must be released after recording');
        } finally {
            mysqli_close($rival);
        }
    }

    public function test_stripe_webhook_answers_503_and_records_nothing_when_the_order_lock_stays_busy(): void
    {
        $charge = $this->paidCharge(['vendor_charge_id' => 'pi_busy']);
        $lockName = 'fct_refund_' . $charge->order_id;

        $rival = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        if (!$rival) {
            $this->markTestSkipped('could not open a second DB connection to simulate the admin refund');
        }

        $ipn = new class extends StripeIPN {
            protected function sendResponse($statusCode = 200, $message = 'Success')
            {
                throw new \RuntimeException((string) $statusCode);
            }
        };

        $event = json_decode(json_encode([
            'livemode' => false,
            'data'     => ['object' => [
                'id' => 'ch_busy', 'payment_intent' => 'pi_busy', 'currency' => 'usd',
                'refunds' => ['data' => [['id' => 're_busy', 'amount' => 3000, 'created' => time()]]],
            ]],
        ]));

        try {
            $row = mysqli_fetch_row(mysqli_query($rival, "SELECT GET_LOCK('" . mysqli_real_escape_string($rival, $lockName) . "', 5)"));
            $this->assertSame('1', (string) $row[0], 'the rival connection must hold the order refund lock');

            $status = null;
            try {
                $ipn->handleChargeRefunded(['event' => $event, 'order' => $charge->order]);
            } catch (\RuntimeException $e) {
                $status = $e->getMessage();
            }

            $this->assertSame('503', $status, 'a busy order lock must answer a retryable status so Stripe redelivers');
            $this->assertCount(0, $this->refundRows($charge->order_id));
        } finally {
            mysqli_query($rival, "SELECT RELEASE_LOCK('" . mysqli_real_escape_string($rival, $lockName) . "')");
            mysqli_close($rival);
        }

        $ipn->handleChargeRefunded(['event' => $event, 'order' => $charge->order]);

        $this->assertCount(1, $this->refundRows($charge->order_id));
        $this->assertSame(3000, $this->chargeRefundedTotal($charge->id));
    }

    private function countEvents(): \stdClass
    {
        return $this->eventCounters[] = $this->countOrderRefundedEvents();
    }

    private function secondCharge(OrderTransaction $charge): OrderTransaction
    {
        return (new OrderTransactionFactory())->create((int) $charge->order_id, [
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => 10000,
            'payment_method'   => $charge->payment_method,
            'vendor_charge_id' => 'ch_fake_2',
        ]);
    }

    private function freshCharge(OrderTransaction $charge): OrderTransaction
    {
        return OrderTransaction::query()->findOrFail($charge->id);
    }

    private function localRefundRow(OrderTransaction $charge, int $total, string $status = Status::TRANSACTION_REFUNDED): OrderTransaction
    {
        return OrderTransaction::query()->create([
            'order_id'         => $charge->order_id,
            'order_type'       => 'payment',
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => $status,
            'total'            => $total,
            'payment_method'   => $charge->payment_method,
            'payment_mode'     => 'test',
            'currency'         => 'USD',
            'meta'             => ['parent_id' => $charge->id, 'reason' => ''],
        ]);
    }
}

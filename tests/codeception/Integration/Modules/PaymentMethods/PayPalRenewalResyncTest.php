<?php

namespace Tests\Integration\Modules\PaymentMethods;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\IPN;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\PayPalSubscriptions;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Traits\BuildsSubscriptions;
use Tests\Support\Traits\MocksPayPalHttp;

/**
 * A PayPal sale webhook and a manual resync must agree on which remote
 * transaction is the first-cycle payment. The old webhook handler stamped ANY
 * incoming sale id onto the newest unstamped local transaction, so a renewal
 * arriving after a missed first-payment webhook claimed the parent slot; the
 * later resync then found the real first sale unmatched and duplicated it as a
 * renewal order dated a full cycle in the past.
 *
 * processRecurringPaymentReceived now delegates to reSyncSubscriptionFromRemote,
 * which sorts the remote list ascending and lets only the EARLIEST completed
 * transaction claim the unstamped first-cycle row.
 */
class PayPalRenewalResyncTest extends DatabaseTestCase
{
    use BuildsSubscriptions;
    use MocksPayPalHttp;

    /** Ids must be unique per test: the suite database persists across tests
     * and both the webhook dedup and recordRenewalPayment match on these
     * globally. */
    private const RESYNC_RETRY_HOOK = 'fluent_cart/paypal/subscription_resync_retry';

    private string $vendorSubId;
    private string $firstSaleId;
    private string $renewalSaleId;

    public function setUp(): void
    {
        parent::setUp();
        $this->installPayPalHttpMock();

        $uniq = strtoupper(substr(md5(uniqid('', true)), 0, 8));
        $this->vendorSubId   = 'I-RESYNC' . $uniq;
        $this->firstSaleId   = 'SALE-2025-FIRST-' . $uniq;
        $this->renewalSaleId = 'SALE-2026-RENEWAL-' . $uniq;
    }

    public function tearDown(): void
    {
        $this->removePayPalHttpMock();
        parent::tearDown();
    }

    /**
     * Build an active PayPal subscription whose parent order holds a succeeded
     * charge transaction that never received its vendor_charge_id (the
     * first-payment webhook was missed; activation confirmed the order without
     * stamping the sale id).
     *
     * @return array{0: Subscription, 1: Order, 2: OrderTransaction}
     */
    private function buildUnstampedPayPalSubscription(array $subscriptionOverrides = [], array $transactionOverrides = []): array
    {
        [, $product, $order, $subscription] = $this->buildSubscriptionChain(array_merge([
            'status'                 => Status::SUBSCRIPTION_ACTIVE,
            'current_payment_method' => 'paypal',
            'vendor_subscription_id' => $this->vendorSubId,
            'vendor_plan_id'         => 'P-TESTPLAN',
            'billing_interval'       => 'yearly',
            'recurring_amount'       => Helper::toCent(77.40),
            'recurring_total'        => Helper::toCent(77.40),
        ], $subscriptionOverrides), [], [
            'payment_method' => 'paypal',
            'status'         => Status::ORDER_COMPLETED,
            'payment_status' => Status::PAYMENT_PAID,
            'subtotal'       => Helper::toCent(77.40),
            'total_amount'   => Helper::toCent(77.40),
            'total_paid'     => Helper::toCent(77.40),
        ]);

        $variant = $product->variants->first();
        (new \Tests\Support\Factory\OrderItemFactory())->create($order->id, [
            'post_id'      => $product->ID,
            'object_id'    => $variant->id,
            'payment_type' => Status::ORDER_TYPE_SUBSCRIPTION,
            'unit_price'   => Helper::toCent(77.40),
            'subtotal'     => Helper::toCent(77.40),
            'line_total'   => Helper::toCent(77.40),
        ]);

        $parentTransaction = (new \Tests\Support\Factory\OrderTransactionFactory())->create($order->id, array_merge([
            'subscription_id'     => $subscription->id,
            'payment_method'      => 'paypal',
            'payment_method_type' => 'PayPal',
            'transaction_type'    => Status::TRANSACTION_TYPE_CHARGE,
            'status'              => Status::TRANSACTION_SUCCEEDED,
            'total'               => Helper::toCent(77.40),
            'vendor_charge_id'    => '',
        ], $transactionOverrides));

        return [$subscription, $order, $parentTransaction];
    }

    /**
     * Script the two remote endpoints the resync reads. The transactions route
     * must be registered first: route matching is first-fragment-wins and the
     * transactions URL also contains the subscription-detail fragment.
     */
    private function scriptRemoteSubscription(array $transactions, array $subscriptionOverrides = []): void
    {
        $this->scriptPayPal($this->vendorSubId . '/transactions', 200, [
            'transactions' => $transactions,
        ]);

        $this->scriptPayPal('billing/subscriptions/' . $this->vendorSubId, 200, array_merge([
            'id'           => $this->vendorSubId,
            'status'       => 'ACTIVE',
            'plan_id'      => 'P-TESTPLAN',
            'start_time'   => '2025-08-11T18:26:00Z',
            'subscriber'   => ['payer_id' => 'PAYER-TEST-1'],
            'billing_info' => ['next_billing_time' => '2027-08-11T10:00:00Z'],
        ], $subscriptionOverrides));
    }

    private function remoteSale(string $id, string $time, string $amount = '77.40', string $status = 'COMPLETED', string $currencyCode = 'USD'): array
    {
        return [
            'id'                    => $id,
            'status'                => $status,
            'time'                  => $time,
            'amount_with_breakdown' => [
                'gross_amount' => ['value' => $amount, 'currency_code' => $currencyCode],
            ],
        ];
    }

    /**
     * The customer bug: first-payment webhook missed, parent transaction left
     * unstamped, and the renewal sale webhook arrives a year later. The handler
     * must bind the EARLIEST remote sale to the parent transaction and record
     * the incoming sale as a renewal order dated by PayPal's own settle time —
     * never stamp the renewal id onto the parent row.
     */
    public function test_renewal_webhook_after_missed_first_payment_binds_earliest_sale_and_dates_renewal_by_paypal_time(): void
    {
        [$subscription, $order, $parentTransaction] = $this->buildUnstampedPayPalSubscription();

        // Deliberately newest-first: the resync must not trust API ordering.
        $this->scriptRemoteSubscription([
            $this->remoteSale($this->renewalSaleId, '2026-08-11T18:27:00Z'),
            $this->remoteSale($this->firstSaleId, '2025-08-11T18:26:00Z'),
        ]);

        $result = (new IPN())->processRecurringPaymentReceived([
            'vendor_subscription_id' => $this->vendorSubId,
            'charge'                 => [
                'id'     => $this->renewalSaleId,
                'custom' => $subscription->uuid,
                'amount' => ['total' => '77.40'],
            ],
        ]);

        $this->assertTrue($result, 'the sale webhook must be processed successfully');

        $parentTransaction = OrderTransaction::query()->find($parentTransaction->id);
        $this->assertSame(
            $this->firstSaleId,
            $parentTransaction->vendor_charge_id,
            'the earliest remote sale must claim the unstamped first-cycle transaction — never the incoming renewal'
        );

        $renewalOrders = Order::query()
            ->where('parent_id', $order->id)
            ->where('type', Status::ORDER_TYPE_RENEWAL)
            ->get();
        $this->assertCount(1, $renewalOrders, 'exactly one renewal order must be created');

        $renewalOrder = $renewalOrders->first();
        $this->assertSame(
            '2026-08-11',
            gmdate('Y-m-d', strtotime((string)$renewalOrder->created_at)),
            'the renewal order must be dated by the renewal sale\'s own settle time, not the first payment\'s'
        );
        $this->assertSame(Helper::toCent(77.40), (int)$renewalOrder->total_amount);
        $this->assertSame(Status::PAYMENT_PAID, $renewalOrder->payment_status);

        $renewalTransaction = OrderTransaction::query()
            ->where('vendor_charge_id', $this->renewalSaleId)
            ->first();
        $this->assertNotNull($renewalTransaction, 'the renewal sale must be recorded as its own transaction');
        $this->assertSame($renewalOrder->id, (int)$renewalTransaction->order_id);
        $this->assertSame(Status::TRANSACTION_SUCCEEDED, $renewalTransaction->status);

        $subscription = Subscription::query()->find($subscription->id);
        $this->assertSame(
            '2027-08-11',
            gmdate('Y-m-d', strtotime((string)$subscription->next_billing_date)),
            'next_billing_date must sync from PayPal\'s billing_info'
        );
    }

    /**
     * PayPal redelivers sale webhooks within seconds. Once the sale id is
     * stored, a redelivery must acknowledge without pulling the remote
     * transaction list again or creating a second renewal order.
     */
    public function test_redelivered_sale_webhook_is_acknowledged_without_a_second_remote_pull(): void
    {
        [$subscription, $order] = $this->buildUnstampedPayPalSubscription();

        $this->scriptRemoteSubscription([
            $this->remoteSale($this->renewalSaleId, '2026-08-11T18:27:00Z'),
            $this->remoteSale($this->firstSaleId, '2025-08-11T18:26:00Z'),
        ]);

        $webhookData = [
            'vendor_subscription_id' => $this->vendorSubId,
            'charge'                 => [
                'id'     => $this->renewalSaleId,
                'custom' => $subscription->uuid,
                'amount' => ['total' => '77.40'],
            ],
        ];

        $this->assertTrue((new IPN())->processRecurringPaymentReceived($webhookData));
        $pullsAfterFirst = $this->payPalRequestCount('/transactions');
        $this->assertSame(1, $pullsAfterFirst, 'the first delivery pulls the remote list once');

        $this->assertTrue((new IPN())->processRecurringPaymentReceived($webhookData), 'a redelivery must be acknowledged');

        $this->assertSame(
            $pullsAfterFirst,
            $this->payPalRequestCount('/transactions'),
            'a redelivery must not pull the remote transaction list again'
        );
        $this->assertSame(
            1,
            Order::query()->where('parent_id', $order->id)->where('type', Status::ORDER_TYPE_RENEWAL)->count(),
            'a redelivery must not create a second renewal order'
        );
    }

    /**
     * Async first payments (eCheck) leave the parent transaction pending with
     * no vendor_charge_id. When the resync finds the completed remote sale it
     * must run the full payment confirmation — order marked paid — not a bare
     * column update that strands the order pending forever.
     */
    public function test_resync_confirms_a_pending_first_payment_and_marks_the_order_paid(): void
    {
        [$subscription, $order, $parentTransaction] = $this->buildUnstampedPayPalSubscription([], [
            'status' => Status::TRANSACTION_PENDING,
        ]);
        $order->update([
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'total_paid'     => 0,
        ]);

        $this->scriptRemoteSubscription([
            $this->remoteSale($this->firstSaleId, '2026-06-01T09:00:00Z'),
        ]);

        $result = (new PayPalSubscriptions())->reSyncSubscriptionFromRemote(
            Subscription::query()->find($subscription->id)
        );
        $this->assertFalse(is_wp_error($result), 'resync must succeed');

        $parentTransaction = OrderTransaction::query()->find($parentTransaction->id);
        $this->assertSame($this->firstSaleId, $parentTransaction->vendor_charge_id);
        $this->assertSame(Status::TRANSACTION_SUCCEEDED, $parentTransaction->status);

        $order = Order::query()->find($order->id);
        $this->assertSame(Status::PAYMENT_PAID, $order->payment_status, 'confirming the cleared eCheck must mark the parent order paid');

        $this->assertSame(
            0,
            Order::query()->where('parent_id', $order->id)->where('type', Status::ORDER_TYPE_RENEWAL)->count(),
            'the first-cycle sale must never be duplicated as a renewal order'
        );
    }

    /**
     * A pending transaction that already carries its vendor_charge_id (a pending
     * sale stamped the id before the payment cleared) matches the remote sale by
     * id directly. Completing it must run the full payment confirmation — order
     * marked paid, side effects fired — exactly like the unstamped claim path,
     * not a bare transaction-row update that leaves the order pending forever.
     */
    public function test_resync_confirms_a_matched_pending_transaction_and_marks_the_order_paid(): void
    {
        [$subscription, $order, $parentTransaction] = $this->buildUnstampedPayPalSubscription([], [
            'status'           => Status::TRANSACTION_PENDING,
            'vendor_charge_id' => $this->firstSaleId,
        ]);
        $order->update([
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'total_paid'     => 0,
        ]);

        $this->scriptRemoteSubscription([
            $this->remoteSale($this->firstSaleId, '2026-06-01T09:00:00Z'),
        ]);

        $result = (new PayPalSubscriptions())->reSyncSubscriptionFromRemote(
            Subscription::query()->find($subscription->id)
        );
        $this->assertFalse(is_wp_error($result), 'resync must succeed');

        $parentTransaction = OrderTransaction::query()->find($parentTransaction->id);
        $this->assertSame(Status::TRANSACTION_SUCCEEDED, $parentTransaction->status);

        $order = Order::query()->find($order->id);
        $this->assertSame(
            Status::PAYMENT_PAID,
            $order->payment_status,
            'completing an id-matched pending transaction must mark its order paid'
        );
    }

    /**
     * Sites that ran the old blind fill-in handler can hold corrupted data: the
     * renewal sale's id stamped onto the first-cycle transaction. The earliest
     * remote sale then matches nothing and the empty-id fallback can't fire, so
     * a plain resync would duplicate the first sale as a past-dated renewal
     * order — the original customer bug, permanently reproducible. The
     * corruption is self-identifying: the row holds the id of a LATER remote
     * sale yet was created BEFORE that sale happened — a row legitimately
     * carrying a sale id is created by that sale's webhook and can never
     * predate it. The resync must reclaim that row for the earliest sale and
     * record the displaced sale as its own correctly-dated renewal.
     */
    public function test_resync_repairs_first_cycle_row_mis_stamped_with_a_later_sale_id(): void
    {
        [$subscription, $order, $parentTransaction] = $this->buildUnstampedPayPalSubscription([], [
            'vendor_charge_id' => $this->renewalSaleId,
        ]);

        // The mis-stamped row was created at activation, a year before the
        // renewal sale whose id it wrongly holds — the fingerprint's premise.
        OrderTransaction::query()->where('id', $parentTransaction->id)
            ->update(['created_at' => '2025-08-11 18:26:00']);

        $this->scriptRemoteSubscription([
            $this->remoteSale($this->renewalSaleId, '2026-08-11T18:27:00Z'),
            $this->remoteSale($this->firstSaleId, '2025-08-11T18:26:00Z'),
        ]);

        $result = (new PayPalSubscriptions())->reSyncSubscriptionFromRemote(
            Subscription::query()->find($subscription->id)
        );
        $this->assertFalse(is_wp_error($result), 'resync must succeed');

        $parentTransaction = OrderTransaction::query()->find($parentTransaction->id);
        $this->assertSame(
            $this->firstSaleId,
            $parentTransaction->vendor_charge_id,
            'the first-cycle row must be reclaimed for the earliest remote sale'
        );

        $renewalOrders = Order::query()
            ->where('parent_id', $order->id)
            ->where('type', Status::ORDER_TYPE_RENEWAL)
            ->get();
        $this->assertCount(1, $renewalOrders, 'exactly one renewal order — the first sale must not be duplicated');

        $renewalOrder = $renewalOrders->first();
        $this->assertSame(
            '2026-08-11',
            gmdate('Y-m-d', strtotime((string) $renewalOrder->created_at)),
            'the displaced renewal sale must be recorded with its own settle date, never the first payment\'s'
        );

        $renewalTransaction = OrderTransaction::query()
            ->where('vendor_charge_id', $this->renewalSaleId)
            ->orderBy('id', 'DESC')
            ->first();
        $this->assertSame((int) $renewalOrder->id, (int) $renewalTransaction->order_id, 'the displaced sale id must move to the renewal transaction');
    }

    /**
     * A mis-stamped row that has been refunded (even partially) must never be
     * restamped: the refund was executed at PayPal against the id the row
     * currently holds, so that binding has become real on the vendor side.
     * The missing earliest sale is recorded as its own renewal order instead.
     */
    public function test_resync_never_restamps_a_mis_stamped_row_that_was_refunded(): void
    {
        [$subscription, $order, $parentTransaction] = $this->buildUnstampedPayPalSubscription([], [
            'vendor_charge_id' => $this->renewalSaleId,
            'meta'             => ['refunded_total' => Helper::toCent(10)],
        ]);

        OrderTransaction::query()->where('id', $parentTransaction->id)
            ->update(['created_at' => '2025-08-11 18:26:00']);

        $this->scriptRemoteSubscription([
            $this->remoteSale($this->renewalSaleId, '2026-08-11T18:27:00Z'),
            $this->remoteSale($this->firstSaleId, '2025-08-11T18:26:00Z'),
        ]);

        $result = (new PayPalSubscriptions())->reSyncSubscriptionFromRemote(
            Subscription::query()->find($subscription->id)
        );
        $this->assertFalse(is_wp_error($result), 'resync must succeed');

        $parentTransaction = OrderTransaction::query()->find($parentTransaction->id);
        $this->assertSame(
            $this->renewalSaleId,
            $parentTransaction->vendor_charge_id,
            'a refunded row must keep the id its refund was executed against'
        );
        $this->assertSame(
            Helper::toCent(10),
            (int) Arr::get($parentTransaction->meta, 'refunded_total'),
            'the refund tracking must be untouched'
        );

        $firstSaleTransactions = OrderTransaction::query()
            ->where('vendor_charge_id', $this->firstSaleId)
            ->get();
        $this->assertCount(1, $firstSaleTransactions, 'the missing earliest sale must be recorded exactly once');

        $firstSaleOrder = Order::query()->find($firstSaleTransactions->first()->order_id);
        $this->assertSame(Status::ORDER_TYPE_RENEWAL, $firstSaleOrder->type, 'the earliest sale becomes its own renewal order');
        $this->assertSame(
            '2025-08-11',
            gmdate('Y-m-d', strtotime((string) $firstSaleOrder->created_at)),
            'the earliest sale must be dated by its own settle time'
        );
    }

    /**
     * The mis-stamp fingerprint must not fire on a legitimately recorded
     * renewal row. Shape: a subscription switched/reactivated onto a new PayPal
     * subscription — the parent row carries the old gateway's charge id (absent
     * from the new remote list), the new sub's first sale was never recorded,
     * and its second sale already has a correct renewal order created by its
     * own webhook (row created AFTER the sale). Reclaiming that row would
     * corrupt a correct renewal and duplicate its sale; the resync must leave
     * it alone and record the missing first sale as its own renewal order.
     */
    public function test_resync_never_reclaims_a_legit_renewal_row_when_the_earliest_sale_is_missing(): void
    {
        [$subscription, $order, $parentTransaction] = $this->buildUnstampedPayPalSubscription([], [
            'vendor_charge_id' => 'CH-OLDGATEWAY-1',
        ]);

        // Legit renewal for the second sale, created by its own webhook
        // (row born minutes after the sale it records).
        $recorded = SubscriptionService::recordRenewalPayment([
            'subscription_id'     => $subscription->id,
            'payment_method'      => 'paypal',
            'vendor_charge_id'    => $this->renewalSaleId,
            'payment_method_type' => 'PayPal',
            'total'               => Helper::toCent(77.40),
            'meta'                => ['settled_at' => '2026-08-11 18:27:00'],
            'created_at'          => '2026-08-11 18:29:00',
        ], Subscription::query()->find($subscription->id));
        $this->assertFalse(is_wp_error($recorded), 'fixture renewal must record cleanly');

        $legitRenewalTransaction = OrderTransaction::query()
            ->where('vendor_charge_id', $this->renewalSaleId)
            ->first();
        OrderTransaction::query()->where('id', $legitRenewalTransaction->id)
            ->update(['created_at' => '2026-08-11 18:29:00']);

        $this->scriptRemoteSubscription([
            $this->remoteSale($this->renewalSaleId, '2026-08-11T18:27:00Z'),
            $this->remoteSale($this->firstSaleId, '2026-07-11T18:26:00Z'),
        ]);

        $result = (new PayPalSubscriptions())->reSyncSubscriptionFromRemote(
            Subscription::query()->find($subscription->id)
        );
        $this->assertFalse(is_wp_error($result), 'resync must succeed');

        $legitRenewalTransaction = OrderTransaction::query()->find($legitRenewalTransaction->id);
        $this->assertSame(
            $this->renewalSaleId,
            $legitRenewalTransaction->vendor_charge_id,
            'the legit renewal row must keep its own sale id — never be reclaimed for the earlier missing sale'
        );

        $parentTransaction = OrderTransaction::query()->find($parentTransaction->id);
        $this->assertSame(
            'CH-OLDGATEWAY-1',
            $parentTransaction->vendor_charge_id,
            'the old-gateway parent row must be untouched'
        );

        $firstSaleTransactions = OrderTransaction::query()
            ->where('vendor_charge_id', $this->firstSaleId)
            ->get();
        $this->assertCount(1, $firstSaleTransactions, 'the missing first sale must be recorded exactly once');

        $firstSaleOrder = Order::query()->find($firstSaleTransactions->first()->order_id);
        $this->assertSame(Status::ORDER_TYPE_RENEWAL, $firstSaleOrder->type, 'the missing sale becomes its own renewal order');
        $this->assertSame(
            '2026-07-11',
            gmdate('Y-m-d', strtotime((string) $firstSaleOrder->created_at)),
            'the missing sale must be dated by its own settle time'
        );
    }

    /**
     * The repair must only fire on its exact fingerprint. A first-cycle row
     * holding an id that is NOT in the remote transaction list (imported data,
     * manually edited vendor ids) is not the old fill-in corruption — the
     * resync must leave it untouched.
     */
    public function test_resync_never_reclaims_a_first_cycle_row_whose_id_is_not_in_the_remote_list(): void
    {
        [$subscription, , $parentTransaction] = $this->buildUnstampedPayPalSubscription([], [
            'vendor_charge_id' => 'SALE-FOREIGN-' . strtoupper(substr(md5(uniqid('', true)), 0, 8)),
        ]);
        $foreignId = $parentTransaction->vendor_charge_id;

        $this->scriptRemoteSubscription([
            $this->remoteSale($this->renewalSaleId, '2026-08-11T18:27:00Z'),
            $this->remoteSale($this->firstSaleId, '2025-08-11T18:26:00Z'),
        ]);

        $result = (new PayPalSubscriptions())->reSyncSubscriptionFromRemote(
            Subscription::query()->find($subscription->id)
        );
        $this->assertFalse(is_wp_error($result), 'resync must succeed');

        $parentTransaction = OrderTransaction::query()->find($parentTransaction->id);
        $this->assertSame(
            $foreignId,
            $parentTransaction->vendor_charge_id,
            'a first-cycle id outside the remote list must never be overwritten'
        );
    }

    /**
     * A subscription can be matched by uuid before its vendor id was ever
     * stored (activation raced the first webhook). The handler must persist
     * the vendor id from the webhook so the resync can pull the remote list —
     * and the renewal must still be recorded.
     */
    public function test_uuid_matched_subscription_gets_vendor_id_backfilled_and_renewal_recorded(): void
    {
        [$subscription, $order] = $this->buildUnstampedPayPalSubscription([
            'vendor_subscription_id' => '',
        ]);

        $this->scriptRemoteSubscription([
            $this->remoteSale($this->firstSaleId, '2025-08-11T18:26:00Z'),
            $this->remoteSale($this->renewalSaleId, '2026-08-11T18:27:00Z'),
        ]);

        $result = (new IPN())->processRecurringPaymentReceived([
            'vendor_subscription_id' => $this->vendorSubId,
            'charge'                 => [
                'id'     => $this->renewalSaleId,
                'custom' => $subscription->uuid,
                'amount' => ['total' => '77.40'],
            ],
        ]);
        $this->assertTrue($result);

        $subscription = Subscription::query()->find($subscription->id);
        $this->assertSame(
            $this->vendorSubId,
            $subscription->vendor_subscription_id,
            'the vendor subscription id from the webhook must be persisted'
        );

        $this->assertSame(
            1,
            Order::query()->where('parent_id', $order->id)->where('type', Status::ORDER_TYPE_RENEWAL)->count(),
            'the renewal must still be recorded after the backfill'
        );
    }

    /**
     * A resync failure must surface as a non-2xx webhook response so PayPal
     * redelivers. The handler's return value is discarded by do_action, so the
     * failure has to travel to handleWebhookRequest through the IPN itself.
     */
    public function test_webhook_answers_500_when_the_remote_transaction_pull_fails(): void
    {
        [$subscription, $order] = $this->buildUnstampedPayPalSubscription();

        $this->scriptRemoteSubscription([]);
        // Overwrite the transactions route with a failure; the detail route stays 200.
        $this->scriptPayPal($this->vendorSubId . '/transactions', 500, [
            'name'    => 'INTERNAL_SERVICE_ERROR',
            'message' => 'transactions unavailable',
        ]);

        add_filter('fluent_cart/payments/paypal/verify_webhook', '__return_false');
        (new IPN())->init();

        try {
            $status = (new IPN())->handleWebhookRequest(json_encode([
                'event_type' => 'PAYMENT.SALE.COMPLETED',
                'resource'   => [
                    'id'                   => $this->renewalSaleId,
                    'billing_agreement_id' => $this->vendorSubId,
                    'custom'               => $subscription->uuid,
                    'amount'               => ['total' => '77.40'],
                ],
            ]));
        } finally {
            remove_filter('fluent_cart/payments/paypal/verify_webhook', '__return_false');
        }

        $this->assertSame(500, $status, 'a failed resync must answer non-2xx so PayPal retries the delivery');
        $this->assertSame(
            0,
            Order::query()->where('parent_id', $order->id)->where('type', Status::ORDER_TYPE_RENEWAL)->count(),
            'no renewal order exists yet — the retry is what will record it'
        );
    }

    /**
     * PayPal's transaction reporting can lag the sale webhook. When the
     * incoming sale is not on the remote list yet, neither the direct bind nor
     * the resync could record it — acknowledging would silently lose the
     * payment, so the webhook must answer 500 and leave every row untouched
     * for the redelivery.
     */
    public function test_webhook_answers_500_when_the_incoming_sale_is_not_on_the_remote_list_yet(): void
    {
        [$subscription, $order, $parentTransaction] = $this->buildUnstampedPayPalSubscription();

        // The list has not caught up with the just-completed first sale.
        $this->scriptRemoteSubscription([]);

        add_filter('fluent_cart/payments/paypal/verify_webhook', '__return_false');
        (new IPN())->init();

        try {
            $status = (new IPN())->handleWebhookRequest(json_encode([
                'event_type' => 'PAYMENT.SALE.COMPLETED',
                'resource'   => [
                    'id'                   => $this->firstSaleId,
                    'billing_agreement_id' => $this->vendorSubId,
                    'custom'               => $subscription->uuid,
                    'amount'               => ['total' => '77.40'],
                ],
            ]));
        } finally {
            remove_filter('fluent_cart/payments/paypal/verify_webhook', '__return_false');
        }

        $this->assertSame(500, $status, 'a lagging remote list must answer non-2xx so PayPal redelivers');

        $parentTransaction = OrderTransaction::query()->find($parentTransaction->id);
        $this->assertSame('', $parentTransaction->vendor_charge_id, 'the row must stay unstamped until the list confirms the sale');
        $this->assertSame(
            0,
            Order::query()->where('parent_id', $order->id)->where('type', Status::ORDER_TYPE_RENEWAL)->count(),
            'nothing may be recorded from a sale the remote list cannot confirm yet'
        );
    }

    /**
     * The 500 answer only helps while PayPal keeps redelivering. If reporting
     * lag outlasts that window (or the endpoint gets auto-disabled) the sale
     * would be lost, so a retryable failure must also queue our own resync.
     */
    public function test_lagging_webhook_schedules_a_resync_retry_backstop(): void
    {

        [$subscription] = $this->buildUnstampedPayPalSubscription();

        $this->scriptRemoteSubscription([]);

        $this->assertFalse((new IPN())->processRecurringPaymentReceived([
            'vendor_subscription_id' => $this->vendorSubId,
            'charge'                 => [
                'id'     => $this->firstSaleId,
                'custom' => $subscription->uuid,
                'amount' => ['total' => '77.40'],
            ],
        ]));

        $this->assertNotFalse(
            as_next_scheduled_action('fluent_cart/paypal/subscription_resync_retry', [$subscription->id, 1], 'fluent-cart'),
            'a lagging remote list must arm the first backstop retry'
        );

        // A redelivery of the same failure must not stack a second retry.
        (new IPN())->processRecurringPaymentReceived([
            'vendor_subscription_id' => $this->vendorSubId,
            'charge'                 => [
                'id'     => $this->firstSaleId,
                'custom' => $subscription->uuid,
                'amount' => ['total' => '77.40'],
            ],
        ]);

        $this->assertCount(
            1,
            as_get_scheduled_actions([
                'hook'   => 'fluent_cart/paypal/subscription_resync_retry',
                'args'   => [$subscription->id, 1],
                'group'  => 'fluent-cart',
                'status' => \ActionScheduler_Store::STATUS_PENDING,
            ], 'ids'),
            'redeliveries must dedupe against the already-pending retry'
        );
    }

    public function test_scheduled_resync_retry_records_the_missed_renewal(): void
    {
        [$subscription, $order, $parentTransaction] = $this->buildUnstampedPayPalSubscription();

        $this->scriptRemoteSubscription([
            $this->remoteSale($this->firstSaleId, '2025-08-11T18:26:00Z'),
            $this->remoteSale($this->renewalSaleId, '2026-08-11T18:27:00Z'),
        ]);

        (new IPN())->handleResyncRetry($subscription->id, 1);

        $parentTransaction = OrderTransaction::query()->find($parentTransaction->id);
        $this->assertSame($this->firstSaleId, $parentTransaction->vendor_charge_id, 'the retry must bind the earliest sale to the first-cycle row');
        $this->assertSame(
            1,
            Order::query()->where('parent_id', $order->id)->where('type', Status::ORDER_TYPE_RENEWAL)->count(),
            'the retry must record the missed renewal'
        );
    }

    public function test_failed_resync_retry_schedules_the_next_ladder_step(): void
    {

        [$subscription] = $this->buildUnstampedPayPalSubscription();

        $this->scriptRemoteSubscription([]);
        $this->scriptPayPal($this->vendorSubId . '/transactions', 500, [
            'name'    => 'INTERNAL_SERVICE_ERROR',
            'message' => 'transactions unavailable',
        ]);

        (new IPN())->handleResyncRetry($subscription->id, 1);

        $this->assertNotFalse(
            as_next_scheduled_action('fluent_cart/paypal/subscription_resync_retry', [$subscription->id, 2], 'fluent-cart'),
            'a failed retry must arm the next ladder step'
        );

        // Past the last ladder step nothing further is scheduled.
        (new IPN())->handleResyncRetry($subscription->id, 3);

        $this->assertFalse(
            as_next_scheduled_action('fluent_cart/paypal/subscription_resync_retry', [$subscription->id, 4], 'fluent-cart'),
            'the ladder must stop after the final attempt'
        );
    }

    public function test_non_retryable_remote_error_does_not_queue_a_backstop(): void
    {

        [$subscription] = $this->buildUnstampedPayPalSubscription();

        // The vendor subscription no longer exists at PayPal — a retry can never fix this.
        $this->scriptPayPal('billing/subscriptions/' . $this->vendorSubId, 404, [
            'name'    => 'RESOURCE_NOT_FOUND',
            'message' => 'The specified resource does not exist.',
        ]);

        $this->assertFalse((new IPN())->processRecurringPaymentReceived([
            'vendor_subscription_id' => $this->vendorSubId,
            'charge'                 => [
                'id'     => $this->firstSaleId,
                'custom' => $subscription->uuid,
                'amount' => ['total' => '77.40'],
            ],
        ]));

        $this->assertFalse(
            as_next_scheduled_action('fluent_cart/paypal/subscription_resync_retry', [$subscription->id, 1], 'fluent-cart'),
            'a remote not-found must not arm a doomed retry'
        );
    }

    /**
     * The backstop must also cover the ordinary renewal path: when the first
     * cycle is properly stamped and recordRenewalPayment() itself fails
     * transiently (rolled back), the 500 alone is not enough — the retry
     * ladder has to pick the sale up if PayPal stops redelivering.
     */
    public function test_failed_renewal_recording_schedules_a_resync_retry_backstop(): void
    {

        [$subscription, $order] = $this->buildUnstampedPayPalSubscription(
            [],
            ['vendor_charge_id' => $this->firstSaleId]
        );

        $this->scriptRemoteSubscription([
            $this->remoteSale($this->firstSaleId, '2025-08-11T18:26:00Z'),
            $this->remoteSale($this->renewalSaleId, '2026-08-11T18:27:00Z'),
        ]);

        // Simulate a transient local failure inside recordRenewalPayment's DB
        // transaction: this action fires from Order::created for the paid
        // renewal child order, so throwing here rolls the renewal back.
        $failRenewalOrder = function ($payload) {
            if (Arr::get($payload, 'order.type') === Status::ORDER_TYPE_RENEWAL) {
                throw new \RuntimeException('simulated transient failure');
            }
        };
        add_action('fluent_cart/order/invoice_number_added', $failRenewalOrder);

        try {
            $this->assertFalse((new IPN())->processRecurringPaymentReceived([
                'vendor_subscription_id' => $this->vendorSubId,
                'charge'                 => [
                    'id'     => $this->renewalSaleId,
                    'custom' => $subscription->uuid,
                    'amount' => ['total' => '77.40'],
                ],
            ]), 'a failed renewal recording must answer 500');
        } finally {
            remove_action('fluent_cart/order/invoice_number_added', $failRenewalOrder);
        }

        $this->assertSame(
            0,
            Order::query()->where('parent_id', $order->id)->where('type', Status::ORDER_TYPE_RENEWAL)->count(),
            'the failed renewal must be rolled back'
        );

        $this->assertNotFalse(
            as_next_scheduled_action('fluent_cart/paypal/subscription_resync_retry', [$subscription->id, 1], 'fluent-cart'),
            'a transient renewal-recording failure must arm the first backstop retry'
        );
    }

    /**
     * A deleted parent order is terminal — no redelivery or retry can ever
     * record the payment. The webhook must be acknowledged with a loud log,
     * not answered 500 (days of redelivery) with a doomed retry ladder armed.
     */
    public function test_deleted_parent_order_is_acknowledged_as_terminal_not_retried(): void
    {

        [$subscription, $order] = $this->buildUnstampedPayPalSubscription();

        $this->scriptRemoteSubscription([
            $this->remoteSale($this->firstSaleId, '2025-08-11T18:26:00Z'),
        ]);

        Order::query()->where('id', $order->id)->delete();

        $this->assertTrue((new IPN())->processRecurringPaymentReceived([
            'vendor_subscription_id' => $this->vendorSubId,
            'charge'                 => [
                'id'     => $this->firstSaleId,
                'custom' => $subscription->uuid,
                'amount' => ['total' => '77.40'],
            ],
        ]), 'a missing parent order is terminal and must be acknowledged, not redelivered forever');

        $this->assertFalse(
            as_next_scheduled_action('fluent_cart/paypal/subscription_resync_retry', [$subscription->id, 1], 'fluent-cart'),
            'a terminal error must not arm a doomed retry'
        );

        $this->assertGreaterThan(
            0,
            \FluentCart\App\Models\Activity::query()
                ->where('module_id', $subscription->id)
                ->where('module_name', 'subscription')
                ->where('status', 'error')
                ->count(),
            'the terminal miss must be logged loudly for manual review'
        );
    }

    /**
     * The remote list can also lag on STATUS: the sale id appears but is not
     * completed yet. Nothing downstream binds a non-completed sale (the resync
     * loop only consumes completed entries), so acknowledging would silently
     * lose the payment — it must take the same lag path as an absent sale.
     */
    public function test_sale_listed_but_not_completed_takes_the_lag_path(): void
    {

        [$subscription, $order, $parentTransaction] = $this->buildUnstampedPayPalSubscription();

        $this->scriptRemoteSubscription([
            $this->remoteSale($this->firstSaleId, '2025-08-11T18:26:00Z', '77.40', 'PENDING'),
        ]);

        $this->assertFalse((new IPN())->processRecurringPaymentReceived([
            'vendor_subscription_id' => $this->vendorSubId,
            'charge'                 => [
                'id'     => $this->firstSaleId,
                'custom' => $subscription->uuid,
                'amount' => ['total' => '77.40'],
            ],
        ]), 'a listed-but-pending sale must answer 500 so PayPal redelivers');

        $this->assertNotFalse(
            as_next_scheduled_action('fluent_cart/paypal/subscription_resync_retry', [$subscription->id, 1], 'fluent-cart'),
            'the status lag must arm the backstop retry like a list lag'
        );

        $parentTransaction = OrderTransaction::query()->find($parentTransaction->id);
        $this->assertSame('', $parentTransaction->vendor_charge_id, 'the row must stay unstamped until the sale completes on the list');
        $this->assertSame(
            0,
            Order::query()->where('parent_id', $order->id)->where('type', Status::ORDER_TYPE_RENEWAL)->count(),
            'nothing may be recorded from a sale the list has not completed yet'
        );
    }

    /**
     * The webhook already fetches the PayPal subscription for plan
     * verification; the resync must reuse that payload instead of fetching
     * the same resource again.
     */
    public function test_renewal_webhook_fetches_the_paypal_subscription_only_once(): void
    {
        [$subscription] = $this->buildUnstampedPayPalSubscription();

        $this->scriptRemoteSubscription([
            $this->remoteSale($this->firstSaleId, '2025-08-11T18:26:00Z'),
            $this->remoteSale($this->renewalSaleId, '2026-08-11T18:27:00Z'),
        ]);

        $this->assertTrue((new IPN())->processRecurringPaymentReceived([
            'vendor_subscription_id' => $this->vendorSubId,
            'charge'                 => [
                'id'     => $this->renewalSaleId,
                'custom' => $subscription->uuid,
                'amount' => ['total' => '77.40'],
            ],
        ]));

        // The transactions URL contains the detail fragment, so subtract it out.
        $detailFetches = $this->payPalRequestCount('billing/subscriptions/' . $this->vendorSubId)
            - $this->payPalRequestCount($this->vendorSubId . '/transactions');
        $this->assertSame(1, $detailFetches, 'the subscription detail must be fetched once, not re-fetched by the resync');
    }

    /**
     * Even when the incoming charge's amount unambiguously matches the parent's
     * unstamped total and differs from the recurring amount (a signup fee was
     * included) — the shape a local amount-match heuristic would call "clearly
     * the first payment" — the handler must still confirm against PayPal's own
     * transaction list rather than trust that appearance. An unstamped row is
     * only known to belong to the *current* vendor subscription's activation;
     * it is not proof by itself that the incoming sale is that row's payment
     * (a reactivated subscription can carry prior renewal orders under an
     * earlier vendor subscription, so no local signal alone settles this).
     */
    public function test_unstamped_row_with_matching_amount_still_confirms_via_remote_transaction_list(): void
    {
        [$subscription, $order, $parentTransaction] = $this->buildUnstampedPayPalSubscription(
            ['recurring_amount' => Helper::toCent(77.40), 'recurring_total' => Helper::toCent(77.40)],
            ['total' => Helper::toCent(97.40)]
        );

        $this->scriptRemoteSubscription([
            $this->remoteSale($this->firstSaleId, '2025-08-11T18:26:00Z', '97.40'),
        ]);

        $result = (new IPN())->processRecurringPaymentReceived([
            'vendor_subscription_id' => $this->vendorSubId,
            'charge'                 => [
                'id'     => $this->firstSaleId,
                'custom' => $subscription->uuid,
                'amount' => ['total' => '97.40'],
            ],
        ]);

        $this->assertTrue($result);

        $parentTransaction = OrderTransaction::query()->find($parentTransaction->id);
        $this->assertSame(
            $this->firstSaleId,
            $parentTransaction->vendor_charge_id,
            'the sale must still be bound via the remote transaction list, not a local amount guess'
        );

        $this->assertSame(
            1,
            $this->payPalRequestCount($this->vendorSubId . '/transactions'),
            'even an unambiguous-looking match must be confirmed against the remote transaction list — '
            . 'and, once confirmed, bound directly in a single pull rather than falling through to a second resync fetch'
        );
        $this->assertSame(
            0,
            Order::query()->where('parent_id', $order->id)->where('type', Status::ORDER_TYPE_RENEWAL)->count(),
            'the first payment must never be recorded as a renewal'
        );
    }

    /**
     * activateSubscription() only marks the parent transaction SUCCEEDED when the
     * activation payload's last_payment verified (or total is 0) — an async first
     * payment (eCheck) leaves the row PENDING and the order unpaid. When the sale
     * webhook then confirms it as the first payment, the direct bind must run the
     * full payment confirmation — order marked paid, fulfilment side effects — not
     * a bare column update that stamps SUCCEEDED while stranding the order pending.
     */
    public function test_pending_first_payment_webhook_runs_full_payment_confirmation(): void
    {
        [$subscription, $order, $parentTransaction] = $this->buildUnstampedPayPalSubscription([], [
            'status' => Status::TRANSACTION_PENDING,
        ]);
        $order->update([
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'total_paid'     => 0,
        ]);

        $this->scriptRemoteSubscription([
            $this->remoteSale($this->firstSaleId, '2026-06-01T09:00:00Z'),
        ]);

        $result = (new IPN())->processRecurringPaymentReceived([
            'vendor_subscription_id' => $this->vendorSubId,
            'charge'                 => [
                'id'     => $this->firstSaleId,
                'custom' => $subscription->uuid,
                'amount' => ['total' => '77.40'],
            ],
        ]);

        $this->assertTrue($result);

        $parentTransaction = OrderTransaction::query()->find($parentTransaction->id);
        $this->assertSame($this->firstSaleId, $parentTransaction->vendor_charge_id);
        $this->assertSame(Status::TRANSACTION_SUCCEEDED, $parentTransaction->status);

        $order = Order::query()->find($order->id);
        $this->assertSame(
            Status::PAYMENT_PAID,
            $order->payment_status,
            'confirming the cleared eCheck via the webhook fast path must mark the parent order paid'
        );

        $this->assertSame(
            1,
            $this->payPalRequestCount($this->vendorSubId . '/transactions'),
            'the fast path must confirm and bind in a single remote pull'
        );
        $this->assertSame(
            0,
            Order::query()->where('parent_id', $order->id)->where('type', Status::ORDER_TYPE_RENEWAL)->count(),
            'the first payment must never be recorded as a renewal'
        );
    }

    /**
     * When the incoming charge amount does NOT match the parent's unstamped
     * total, this cannot be the missed first payment — it's a later renewal
     * reusing the empty slot, the shape of the original bug. It must route
     * through the resync rather than blindly stamping the incoming id.
     */
    public function test_amount_mismatch_against_initial_total_delegates_to_resync_instead_of_blind_fill_in(): void
    {
        [$subscription, $order, $parentTransaction] = $this->buildUnstampedPayPalSubscription(
            ['recurring_amount' => Helper::toCent(77.40), 'recurring_total' => Helper::toCent(77.40)],
            ['total' => Helper::toCent(97.40)]
        );

        $this->scriptRemoteSubscription([
            $this->remoteSale($this->renewalSaleId, '2026-08-11T18:27:00Z', '77.40'),
            $this->remoteSale($this->firstSaleId, '2025-08-11T18:26:00Z', '97.40'),
        ]);

        $result = (new IPN())->processRecurringPaymentReceived([
            'vendor_subscription_id' => $this->vendorSubId,
            'charge'                 => [
                'id'     => $this->renewalSaleId,
                'custom' => $subscription->uuid,
                'amount' => ['total' => '77.40'],
            ],
        ]);

        $this->assertTrue($result);

        $parentTransaction = OrderTransaction::query()->find($parentTransaction->id);
        $this->assertSame(
            $this->firstSaleId,
            $parentTransaction->vendor_charge_id,
            'the earliest remote sale — not the mismatched incoming charge — must claim the first-cycle row'
        );

        $this->assertSame(
            1,
            Order::query()->where('parent_id', $order->id)->where('type', Status::ORDER_TYPE_RENEWAL)->count(),
            'the renewal must be recorded via the resync'
        );

        $this->assertSame(
            1,
            $this->payPalRequestCount($this->vendorSubId . '/transactions'),
            'the first-payment check and the resync it delegates to must share one remote pull, not fetch twice'
        );
    }

    /**
     * auto_bill_outstanding: a missed cycle's amount rides the next cycle's sale
     * — one $154.80 sale for a $77.40 subscription pays TWO cycles. The webhook
     * must record the combined renewal AND credit the extra cycle, or the local
     * bill count stays one short forever.
     */
    public function test_outstanding_collection_webhook_credits_the_missed_cycle(): void
    {
        [$subscription, $order] = $this->buildUnstampedPayPalSubscription(
            [],
            ['vendor_charge_id' => $this->firstSaleId]
        );

        $this->scriptRemoteSubscription([]);

        $this->assertTrue((new IPN())->processRecurringPaymentReceived([
            'vendor_subscription_id' => $this->vendorSubId,
            'charge'                 => [
                'id'     => $this->renewalSaleId,
                'custom' => $subscription->uuid,
                'amount' => ['total' => '154.80'],
            ],
        ]));

        $renewalOrders = Order::query()
            ->where('parent_id', $order->id)
            ->where('type', Status::ORDER_TYPE_RENEWAL)
            ->get();
        $this->assertCount(1, $renewalOrders, 'the combined collection is still ONE renewal order');
        $this->assertSame(Helper::toCent(154.80), (int)$renewalOrders->first()->total_amount);

        $subscription = Subscription::query()->find($subscription->id);
        $history = (array) $subscription->getMeta('early_payment_history', []);
        $this->assertCount(1, $history, 'exactly one credit entry for the collection sale');

        $entry = $history[0];
        $this->assertSame('outstanding_collection', Arr::get($entry, 'type'));
        $this->assertSame(2, (int) Arr::get($entry, 'count'), 'a 2x-gross sale covers two cycles');
        $this->assertSame($this->renewalSaleId, Arr::get($entry, 'vendor_charge_id'));
        $this->assertSame(Helper::toCent(154.80), (int) Arr::get($entry, 'amount'));

        $this->assertSame(
            3,
            $subscription->calculateBillCount(),
            'first cycle + the two cycles the collection sale paid for'
        );
    }

    /**
     * PayPal redelivers sale webhooks. The credit is keyed on the sale id, so a
     * redelivery must not append a second history entry (which would inflate the
     * bill count by another cycle).
     */
    public function test_outstanding_collection_credit_is_idempotent_across_redelivery(): void
    {
        [$subscription, $order] = $this->buildUnstampedPayPalSubscription(
            [],
            ['vendor_charge_id' => $this->firstSaleId]
        );

        $this->scriptRemoteSubscription([]);

        $webhookData = [
            'vendor_subscription_id' => $this->vendorSubId,
            'charge'                 => [
                'id'     => $this->renewalSaleId,
                'custom' => $subscription->uuid,
                'amount' => ['total' => '154.80'],
            ],
        ];

        $this->assertTrue((new IPN())->processRecurringPaymentReceived($webhookData));
        $this->assertTrue((new IPN())->processRecurringPaymentReceived($webhookData), 'a redelivery must be acknowledged');

        $subscription = Subscription::query()->find($subscription->id);
        $this->assertCount(
            1,
            (array) $subscription->getMeta('early_payment_history', []),
            'a redelivered sale must not credit its cycles twice'
        );
        $this->assertSame(3, $subscription->calculateBillCount());
        $this->assertSame(
            1,
            Order::query()->where('parent_id', $order->id)->where('type', Status::ORDER_TYPE_RENEWAL)->count()
        );
    }

    /**
     * Installments are where the skew bites: PayPal's plan finishes its
     * total_cycles and stops charging, but the undercounted local subscription
     * waits forever for a payment that never comes. A final collection sale
     * covering the last two cycles must complete the subscription in the same
     * webhook — the credit has to be on the books before recordRenewalPayment
     * recomputes the end-of-term.
     */
    public function test_outstanding_collection_completes_an_installment_at_the_true_cycle_count(): void
    {
        [$subscription] = $this->buildUnstampedPayPalSubscription(
            ['bill_times' => 3, 'bill_count' => 1],
            ['vendor_charge_id' => $this->firstSaleId]
        );

        $this->scriptRemoteSubscription([]);

        $this->assertTrue((new IPN())->processRecurringPaymentReceived([
            'vendor_subscription_id' => $this->vendorSubId,
            'charge'                 => [
                'id'     => $this->renewalSaleId,
                'custom' => $subscription->uuid,
                'amount' => ['total' => '154.80'],
            ],
        ]));

        $subscription = Subscription::query()->find($subscription->id);
        $this->assertSame(3, (int) $subscription->bill_count, 'all three installment cycles are paid');
        $this->assertSame(
            'completed',
            $subscription->status,
            'the installment must complete in the webhook that collects its final cycles'
        );
    }

    /**
     * The resync shares the same credit: an unknown 2x-gross completed sale on
     * the remote list is recorded as one renewal and credited one extra cycle.
     */
    public function test_resync_credits_an_outstanding_collection_sale(): void
    {
        [$subscription, $order] = $this->buildUnstampedPayPalSubscription(
            [],
            ['vendor_charge_id' => $this->firstSaleId]
        );

        $this->scriptRemoteSubscription([
            $this->remoteSale($this->renewalSaleId, '2026-08-11T18:27:00Z', '154.80'),
            $this->remoteSale($this->firstSaleId, '2025-08-11T18:26:00Z'),
        ]);

        $result = (new PayPalSubscriptions())->reSyncSubscriptionFromRemote(
            Subscription::query()->find($subscription->id)
        );
        $this->assertFalse(is_wp_error($result), 'the resync must succeed');

        $subscription = Subscription::query()->find($subscription->id);
        $history = (array) $subscription->getMeta('early_payment_history', []);
        $this->assertCount(1, $history);
        $this->assertSame($this->renewalSaleId, Arr::get($history[0], 'vendor_charge_id'));
        $this->assertSame(2, (int) Arr::get($history[0], 'count'));
        $this->assertSame(3, $subscription->calculateBillCount());
        $this->assertSame(
            1,
            Order::query()->where('parent_id', $order->id)->where('type', Status::ORDER_TYPE_RENEWAL)->count()
        );
    }

    /**
     * A 2x-gross sale recorded BEFORE the outstanding-collection credit shipped
     * carries no early_payment_history entry, so the local bill count stays one
     * cycle low and an extra cycle gets collected at the end of the term. The
     * resync must backfill the credit for such an already-recorded sale — and
     * stay idempotent across repeated resyncs.
     */
    public function test_resync_backfills_the_credit_for_an_already_recorded_outstanding_sale(): void
    {
        [$subscription, $order] = $this->buildUnstampedPayPalSubscription(
            [],
            ['vendor_charge_id' => $this->firstSaleId]
        );

        // Renewal recorded historically at 2x gross, no credit written (the
        // recording path predates creditOutstandingCollection).
        $recorded = SubscriptionService::recordRenewalPayment([
            'subscription_id'     => $subscription->id,
            'payment_method'      => 'paypal',
            'vendor_charge_id'    => $this->renewalSaleId,
            'payment_method_type' => 'PayPal',
            'total'               => Helper::toCent(154.80),
            'meta'                => ['settled_at' => '2026-08-11 18:27:00'],
            'created_at'          => '2026-08-11 18:29:00',
        ], Subscription::query()->find($subscription->id));
        $this->assertFalse(is_wp_error($recorded), 'fixture renewal must record cleanly');

        $this->scriptRemoteSubscription([
            $this->remoteSale($this->renewalSaleId, '2026-08-11T18:27:00Z', '154.80'),
            $this->remoteSale($this->firstSaleId, '2025-08-11T18:26:00Z'),
        ]);

        $result = (new PayPalSubscriptions())->reSyncSubscriptionFromRemote(
            Subscription::query()->find($subscription->id)
        );
        $this->assertFalse(is_wp_error($result), 'the resync must succeed');

        $subscription = Subscription::query()->find($subscription->id);
        $history = (array) $subscription->getMeta('early_payment_history', []);
        $this->assertCount(1, $history, 'the recorded 2x sale must be credited retroactively');
        $this->assertSame('outstanding_collection', Arr::get($history[0], 'type'));
        $this->assertSame($this->renewalSaleId, Arr::get($history[0], 'vendor_charge_id'));
        $this->assertSame(2, (int) Arr::get($history[0], 'count'));
        $this->assertSame(3, $subscription->calculateBillCount());
        $this->assertSame(
            1,
            Order::query()->where('parent_id', $order->id)->where('type', Status::ORDER_TYPE_RENEWAL)->count(),
            'the backfill must not duplicate the already-recorded renewal'
        );
        $this->assertSame(
            1,
            \FluentCart\App\Models\Activity::query()
                ->where('module_id', $subscription->id)
                ->where('module_name', 'subscription')
                ->where('title', 'PayPal outstanding credit backfilled')
                ->count(),
            'a retroactive credit must be distinguishable from a live collection in the activity log'
        );

        // Second resync over the same list: the credit is deduped by sale id.
        $this->scriptRemoteSubscription([
            $this->remoteSale($this->renewalSaleId, '2026-08-11T18:27:00Z', '154.80'),
            $this->remoteSale($this->firstSaleId, '2025-08-11T18:26:00Z'),
        ]);
        $result = (new PayPalSubscriptions())->reSyncSubscriptionFromRemote(
            Subscription::query()->find($subscription->id)
        );
        $this->assertFalse(is_wp_error($result));

        $subscription = Subscription::query()->find($subscription->id);
        $this->assertCount(
            1,
            (array) $subscription->getMeta('early_payment_history', []),
            'a repeated resync must not credit the same sale twice'
        );
        $this->assertSame(3, $subscription->calculateBillCount());
        $this->assertSame(
            1,
            \FluentCart\App\Models\Activity::query()
                ->where('module_id', $subscription->id)
                ->where('module_name', 'subscription')
                ->where('title', 'PayPal outstanding credit backfilled')
                ->count(),
            'a deduped repeat resync must not log the backfill again'
        );
    }

    /**
     * When the same resync pass records a NEW sale (Step 3) and then backfills
     * a credit on a LATER already-recorded sale, the end-of-loop sync is
     * skipped ($newPayment refreshes the model without recomputing), so the
     * credit must sync the persisted bill_count itself — otherwise the column
     * stays one cycle behind calculateBillCount() until the next sync.
     */
    public function test_backfill_after_a_recorded_sale_still_syncs_the_persisted_bill_count(): void
    {
        [$subscription] = $this->buildUnstampedPayPalSubscription(
            [],
            ['vendor_charge_id' => $this->firstSaleId]
        );

        // The 2x sale was recorded historically without a credit; the middle
        // sale's webhook was missed entirely and is unknown locally.
        $recorded = SubscriptionService::recordRenewalPayment([
            'subscription_id'     => $subscription->id,
            'payment_method'      => 'paypal',
            'vendor_charge_id'    => $this->renewalSaleId,
            'payment_method_type' => 'PayPal',
            'total'               => Helper::toCent(154.80),
            'meta'                => ['settled_at' => '2027-08-11 18:27:00'],
            'created_at'          => '2027-08-11 18:29:00',
        ], Subscription::query()->find($subscription->id));
        $this->assertFalse(is_wp_error($recorded), 'fixture renewal must record cleanly');

        $missedSaleId = 'SALE-2026-MISSED-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
        $this->scriptRemoteSubscription([
            $this->remoteSale($this->renewalSaleId, '2027-08-11T18:27:00Z', '154.80'),
            $this->remoteSale($missedSaleId, '2026-08-11T18:27:00Z'),
            $this->remoteSale($this->firstSaleId, '2025-08-11T18:26:00Z'),
        ]);

        $result = (new PayPalSubscriptions())->reSyncSubscriptionFromRemote(
            Subscription::query()->find($subscription->id)
        );
        $this->assertFalse(is_wp_error($result), 'the resync must succeed');

        $subscription = Subscription::query()->find($subscription->id);
        $this->assertCount(1, (array) $subscription->getMeta('early_payment_history', []));
        $this->assertSame(4, $subscription->calculateBillCount());
        $this->assertSame(
            4,
            (int) $subscription->bill_count,
            'the persisted bill_count must include the backfilled credit even when a new sale was recorded first'
        );
    }

    /**
     * The earliest completed sale can carry a signup fee, so its gross
     * exceeding recurring_total is normal — the backfill must never credit it,
     * even when the gross happens to be an exact cycle multiple.
     */
    public function test_backfill_never_credits_the_earliest_recorded_sale(): void
    {
        [$subscription] = $this->buildUnstampedPayPalSubscription(
            [],
            [
                'vendor_charge_id' => $this->firstSaleId,
                'total'            => Helper::toCent(154.80),
            ]
        );

        $recorded = SubscriptionService::recordRenewalPayment([
            'subscription_id'     => $subscription->id,
            'payment_method'      => 'paypal',
            'vendor_charge_id'    => $this->renewalSaleId,
            'payment_method_type' => 'PayPal',
            'total'               => Helper::toCent(77.40),
            'meta'                => ['settled_at' => '2026-08-11 18:27:00'],
            'created_at'          => '2026-08-11 18:29:00',
        ], Subscription::query()->find($subscription->id));
        $this->assertFalse(is_wp_error($recorded), 'fixture renewal must record cleanly');

        // First sale = signup fee shape: 2x the cycle price, already stamped.
        $this->scriptRemoteSubscription([
            $this->remoteSale($this->renewalSaleId, '2026-08-11T18:27:00Z'),
            $this->remoteSale($this->firstSaleId, '2025-08-11T18:26:00Z', '154.80'),
        ]);

        $result = (new PayPalSubscriptions())->reSyncSubscriptionFromRemote(
            Subscription::query()->find($subscription->id)
        );
        $this->assertFalse(is_wp_error($result), 'the resync must succeed');

        $subscription = Subscription::query()->find($subscription->id);
        $this->assertCount(
            0,
            (array) $subscription->getMeta('early_payment_history', []),
            'a fee-inflated first sale must never be read as an outstanding collection'
        );
        $this->assertSame(2, $subscription->calculateBillCount());
    }

    /**
     * A retroactive credit compares a historical gross against the CURRENT
     * recurring_total. Unless at least one other completed sale on the same
     * remote list charged exactly that amount — proving it is this vendor
     * subscription's wire cycle price — the backfill must skip: a false credit
     * silently under-bills, an uncredited historical sale only under-counts.
     */
    public function test_backfill_skips_without_a_regular_price_corroborator_on_the_remote_list(): void
    {
        [$subscription] = $this->buildUnstampedPayPalSubscription(
            [],
            [
                'vendor_charge_id' => $this->firstSaleId,
                'total'            => Helper::toCent(87.40),
            ]
        );

        $recorded = SubscriptionService::recordRenewalPayment([
            'subscription_id'     => $subscription->id,
            'payment_method'      => 'paypal',
            'vendor_charge_id'    => $this->renewalSaleId,
            'payment_method_type' => 'PayPal',
            'total'               => Helper::toCent(154.80),
            'meta'                => ['settled_at' => '2026-08-11 18:27:00'],
            'created_at'          => '2026-08-11 18:29:00',
        ], Subscription::query()->find($subscription->id));
        $this->assertFalse(is_wp_error($recorded), 'fixture renewal must record cleanly');

        // No sale on the list charges exactly recurring_total (77.40): the
        // first sale carries a fee, the second is the 2x candidate itself.
        $this->scriptRemoteSubscription([
            $this->remoteSale($this->renewalSaleId, '2026-08-11T18:27:00Z', '154.80'),
            $this->remoteSale($this->firstSaleId, '2025-08-11T18:26:00Z', '87.40'),
        ]);

        $result = (new PayPalSubscriptions())->reSyncSubscriptionFromRemote(
            Subscription::query()->find($subscription->id)
        );
        $this->assertFalse(is_wp_error($result), 'the resync must succeed');

        $subscription = Subscription::query()->find($subscription->id);
        $this->assertCount(
            0,
            (array) $subscription->getMeta('early_payment_history', []),
            'an uncorroborated cycle price must not back a retroactive credit'
        );
        $this->assertSame(2, $subscription->calculateBillCount());
    }

    /**
     * A gross that exceeds the cycle price without being an exact multiple has
     * no deterministic reading — credit nothing, log loudly for manual review.
     */
    public function test_non_exact_overcharge_credits_nothing_and_logs_for_review(): void
    {
        [$subscription] = $this->buildUnstampedPayPalSubscription(
            [],
            ['vendor_charge_id' => $this->firstSaleId]
        );

        $this->scriptRemoteSubscription([]);

        $this->assertTrue((new IPN())->processRecurringPaymentReceived([
            'vendor_subscription_id' => $this->vendorSubId,
            'charge'                 => [
                'id'     => $this->renewalSaleId,
                'custom' => $subscription->uuid,
                'amount' => ['total' => '116.10'],
            ],
        ]), 'the renewal itself is still real money and must be recorded');

        $subscription = Subscription::query()->find($subscription->id);
        $this->assertCount(
            0,
            (array) $subscription->getMeta('early_payment_history', []),
            'a 1.5x gross must not be guessed into a cycle credit'
        );
        $this->assertSame(2, $subscription->calculateBillCount());

        $this->assertGreaterThan(
            0,
            \FluentCart\App\Models\Activity::query()
                ->where('module_id', $subscription->id)
                ->where('module_name', 'subscription')
                ->where('status', 'error')
                ->count(),
            'the unexplained overcharge must be logged for manual review'
        );
    }

    /**
     * payment_failure_threshold is 3 — more than 4 cycles in one sale cannot
     * come from auto_bill_outstanding (the subscription would have suspended).
     * A 5x gross is treated like any other unexplained overcharge.
     */
    public function test_gross_beyond_the_failure_threshold_credits_nothing_and_logs(): void
    {
        [$subscription] = $this->buildUnstampedPayPalSubscription(
            [],
            ['vendor_charge_id' => $this->firstSaleId]
        );

        $this->scriptRemoteSubscription([]);

        $this->assertTrue((new IPN())->processRecurringPaymentReceived([
            'vendor_subscription_id' => $this->vendorSubId,
            'charge'                 => [
                'id'     => $this->renewalSaleId,
                'custom' => $subscription->uuid,
                'amount' => ['total' => '387.00'],
            ],
        ]));

        $subscription = Subscription::query()->find($subscription->id);
        $this->assertCount(0, (array) $subscription->getMeta('early_payment_history', []));
        $this->assertSame(2, $subscription->calculateBillCount());

        $this->assertGreaterThan(
            0,
            \FluentCart\App\Models\Activity::query()
                ->where('module_id', $subscription->id)
                ->where('module_name', 'subscription')
                ->where('status', 'error')
                ->count()
        );
    }

    /**
     * The credit meta commits outside recordRenewalPayment's DB transaction.
     * When the renewal recording rolls back, the credit written for it must be
     * revoked — otherwise an exhausted retry ladder leaves the bill count
     * inflated by a credit with no backing transaction. The redelivery that
     * finally records the sale credits it again.
     */
    public function test_failed_renewal_recording_revokes_the_outstanding_credit(): void
    {
        [$subscription, $order] = $this->buildUnstampedPayPalSubscription(
            [],
            ['vendor_charge_id' => $this->firstSaleId]
        );

        $this->scriptRemoteSubscription([]);

        $webhookData = [
            'vendor_subscription_id' => $this->vendorSubId,
            'charge'                 => [
                'id'     => $this->renewalSaleId,
                'custom' => $subscription->uuid,
                'amount' => ['total' => '154.80'],
            ],
        ];

        $failRenewalOrder = function ($payload) {
            if (Arr::get($payload, 'order.type') === Status::ORDER_TYPE_RENEWAL) {
                throw new \RuntimeException('simulated transient failure');
            }
        };
        add_action('fluent_cart/order/invoice_number_added', $failRenewalOrder);

        try {
            $this->assertFalse(
                (new IPN())->processRecurringPaymentReceived($webhookData),
                'the rolled-back renewal must answer 500'
            );
        } finally {
            remove_action('fluent_cart/order/invoice_number_added', $failRenewalOrder);
        }

        $subscription = Subscription::query()->find($subscription->id);
        $this->assertCount(
            0,
            (array) $subscription->getMeta('early_payment_history', []),
            'a credit whose renewal recording rolled back must be revoked'
        );
        $this->assertSame(1, $subscription->calculateBillCount(), 'only the first cycle is on the books');
        $this->assertSame(
            1,
            \FluentCart\App\Models\Activity::query()
                ->where('module_id', $subscription->id)
                ->where('module_name', 'subscription')
                ->where('title', 'PayPal outstanding credit revoked')
                ->count(),
            'the revoked credit must leave a trail in the activity log'
        );

        // The redelivery (no failure this time) records the renewal AND
        // re-credits the collection.
        $this->assertTrue((new IPN())->processRecurringPaymentReceived($webhookData));

        $subscription = Subscription::query()->find($subscription->id);
        $this->assertCount(1, (array) $subscription->getMeta('early_payment_history', []));
        $this->assertSame(3, $subscription->calculateBillCount());
        $this->assertSame(
            1,
            Order::query()->where('parent_id', $order->id)->where('type', Status::ORDER_TYPE_RENEWAL)->count()
        );
    }

    /**
     * A resync that answers HTTP 200 has not necessarily recovered anything —
     * PayPal's transaction list lags, so the sale that armed the ladder can
     * still be absent. The worker must keep the ladder alive on local evidence.
     */
    public function test_resync_retry_rearms_while_the_remote_list_still_lags(): void
    {
        [$subscription] = $this->buildUnstampedPayPalSubscription();
        $this->scriptRemoteSubscription([]);

        $this->assertFalse((new IPN())->processRecurringPaymentReceived([
            'vendor_subscription_id' => $this->vendorSubId,
            'charge'                 => [
                'id'     => $this->renewalSaleId,
                'custom' => $subscription->uuid,
                'amount' => ['total' => '77.40']
            ],
        ]), 'the lagging list leaves the sale unconfirmed');

        $this->assertNotFalse(
            as_next_scheduled_action(self::RESYNC_RETRY_HOOK, [$subscription->id, 1], 'fluent-cart'),
            'the first ladder attempt is armed'
        );
        $this->assertSame(
            [$this->renewalSaleId],
            (array) Subscription::query()->find($subscription->id)->getMeta('paypal_pending_sales', []),
            'the ladder remembers which sale it is chasing'
        );

        // model Action Scheduler consuming the attempt, then run the worker
        as_unschedule_all_actions(self::RESYNC_RETRY_HOOK, [$subscription->id, 1], 'fluent-cart');
        (new IPN())->handleResyncRetry($subscription->id, 1);

        $this->assertFalse(
            OrderTransaction::query()->where('vendor_charge_id', $this->renewalSaleId)->exists(),
            'the list still lags, so the sale is still unrecorded'
        );
        $this->assertNotFalse(
            as_next_scheduled_action(self::RESYNC_RETRY_HOOK, [$subscription->id, 2], 'fluent-cart'),
            'an HTTP-200 but still-lagging list must rearm recovery'
        );
    }

    /**
     * Once the list catches up the worker records the sale, clears the pending
     * entry and stops — the ladder must not outlive the work it was chasing.
     */
    public function test_resync_retry_stops_once_the_sale_is_recorded(): void
    {
        [$subscription] = $this->buildUnstampedPayPalSubscription();
        $this->scriptRemoteSubscription([]);

        (new IPN())->processRecurringPaymentReceived([
            'vendor_subscription_id' => $this->vendorSubId,
            'charge'                 => [
                'id'     => $this->renewalSaleId,
                'custom' => $subscription->uuid,
                'amount' => ['total' => '77.40']
            ],
        ]);

        as_unschedule_all_actions(self::RESYNC_RETRY_HOOK, [$subscription->id, 1], 'fluent-cart');

        // the list has caught up by the time the worker runs
        $this->scriptRemoteSubscription([
            $this->remoteSale($this->firstSaleId, '2025-08-11T18:26:00Z'),
            $this->remoteSale($this->renewalSaleId, '2026-08-11T18:27:00Z'),
        ]);

        (new IPN())->handleResyncRetry($subscription->id, 1);

        $this->assertTrue(
            OrderTransaction::query()->where('vendor_charge_id', $this->renewalSaleId)->exists(),
            'the recovered sale is recorded locally'
        );
        $this->assertFalse(
            as_next_scheduled_action(self::RESYNC_RETRY_HOOK, [$subscription->id, 2], 'fluent-cart'),
            'nothing is left to chase, so the ladder stops'
        );
        $this->assertSame(
            [],
            (array) Subscription::query()->find($subscription->id)->getMeta('paypal_pending_sales', []),
            'the resolved sale is pruned from the pending set'
        );
    }

    /**
     * Two unbound charge rows on one subscription can share a wire amount — a
     * missed first-payment webhook plus a later renewal whose own webhook was
     * also missed, both at the regular price. The unbound lookup returns
     * whichever row the storage engine happens to hand back first, so the
     * earliest sale could bind to the LATER row and strand the first-cycle
     * transaction unbound forever, with the first sale then duplicated as a
     * renewal. The oldest row is the first cycle by construction, so the claim
     * must be ordered, not incidental.
     */
    public function test_earliest_sale_claims_the_oldest_of_two_unbound_rows_sharing_an_amount(): void
    {
        [$subscription, $order, $parentTransaction] = $this->buildUnstampedPayPalSubscription();

        $laterUnbound = (new \Tests\Support\Factory\OrderTransactionFactory())->create($order->id, [
            'subscription_id'     => $subscription->id,
            'payment_method'      => 'paypal',
            'payment_method_type' => 'PayPal',
            'transaction_type'    => Status::TRANSACTION_TYPE_CHARGE,
            'status'              => Status::TRANSACTION_SUCCEEDED,
            'total'               => Helper::toCent(77.40),
            'vendor_charge_id'    => '',
        ]);

        $this->assertGreaterThan(
            (int) $parentTransaction->id,
            (int) $laterUnbound->id,
            'the rival row must be the newer of the two'
        );

        $this->scriptRemoteSubscription([
            $this->remoteSale($this->firstSaleId, '2025-08-11T18:26:00Z'),
        ]);

        $result = (new PayPalSubscriptions())->reSyncSubscriptionFromRemote(
            Subscription::query()->find($subscription->id)
        );
        $this->assertFalse(is_wp_error($result), 'resync must succeed');

        $this->assertSame(
            $this->firstSaleId,
            OrderTransaction::query()->find($parentTransaction->id)->vendor_charge_id,
            'the earliest sale must claim the oldest unbound row'
        );
        $this->assertSame(
            '',
            (string) OrderTransaction::query()->find($laterUnbound->id)->vendor_charge_id,
            'the newer unbound row must be left for its own sale'
        );
    }

    /**
     * Build the same chain on a zero-decimal currency whose cycle price sits
     * OFF the currency grid: JPY 7740 is stored x100 like every other currency
     * (¥77.40), but PayPal's plan can only hold ¥77, so every sale comes back
     * on the 7700 grid. Outstanding-cycle math must therefore compare wire to
     * wire — the raw 7740 never divides a real collection.
     *
     * @return array{0: Subscription, 1: Order, 2: OrderTransaction}
     */
    private function buildZeroDecimalPayPalSubscription(array $transactionOverrides = []): array
    {
        return $this->buildUnstampedPayPalSubscription([
            'config'           => ['currency' => 'JPY'],
            'recurring_amount' => 7740,
            'recurring_total'  => 7740,
        ], $transactionOverrides);
    }

    /**
     * auto_bill_outstanding collects the missed cycles in one sale, and PayPal
     * charges its own plan price: two cycles of ¥77 arrive as ¥154 (15400),
     * not as 2 x 7740. Crediting against the raw stored price rejects a
     * perfectly valid collection, so the missed cycle is never credited and
     * calculateBillCount() stays one short — the subscription bills again a
     * cycle too early.
     */
    public function test_outstanding_credit_divides_by_the_wire_cycle_price_on_a_zero_decimal_currency(): void
    {
        [$subscription] = $this->buildZeroDecimalPayPalSubscription(
            ['vendor_charge_id' => $this->firstSaleId]
        );

        $credited = (new PayPalSubscriptions())->creditOutstandingCollection(
            $subscription,
            $this->renewalSaleId,
            15400
        );

        $this->assertTrue($credited, 'a two-cycle collection at the wire price must be credited');

        $subscription = Subscription::query()->find($subscription->id);
        $history = (array) $subscription->getMeta('early_payment_history', []);
        $this->assertCount(1, $history, 'the extra cycle must reach early_payment_history');
        $this->assertSame('outstanding_collection', Arr::get($history[0], 'type'));
        $this->assertSame(2, (int) Arr::get($history[0], 'count'), 'the sale covered two cycles');
        $this->assertSame($this->renewalSaleId, Arr::get($history[0], 'vendor_charge_id'));

        // The sale itself is not recorded here (IPN records it after crediting),
        // so the count is the one succeeded parent charge plus the credited
        // extra cycle.
        $this->assertSame(2, $subscription->calculateBillCount());
    }

    /**
     * The same wire/raw mismatch on the retroactive path: the corroborating
     * sale is a single cycle charged at ¥77 (7700), which never equals the
     * stored 7740, so no completed sale can corroborate and the backfill is
     * dead for every off-grid zero-decimal subscription.
     */
    public function test_resync_backfill_corroborates_against_the_wire_cycle_price_on_a_zero_decimal_currency(): void
    {
        [$subscription, $order] = $this->buildZeroDecimalPayPalSubscription(
            ['vendor_charge_id' => $this->firstSaleId, 'total' => 7740]
        );

        $recorded = SubscriptionService::recordRenewalPayment([
            'subscription_id'     => $subscription->id,
            'payment_method'      => 'paypal',
            'vendor_charge_id'    => $this->renewalSaleId,
            'payment_method_type' => 'PayPal',
            'total'               => 15400,
            'meta'                => ['settled_at' => '2026-08-11 18:27:00'],
            'created_at'          => '2026-08-11 18:29:00',
        ], Subscription::query()->find($subscription->id));
        $this->assertFalse(is_wp_error($recorded), 'fixture renewal must record cleanly');

        $this->scriptRemoteSubscription([
            $this->remoteSale($this->renewalSaleId, '2026-08-11T18:27:00Z', '154', 'COMPLETED', 'JPY'),
            $this->remoteSale($this->firstSaleId, '2025-08-11T18:26:00Z', '77', 'COMPLETED', 'JPY'),
        ]);

        $result = (new PayPalSubscriptions())->reSyncSubscriptionFromRemote(
            Subscription::query()->find($subscription->id)
        );
        $this->assertFalse(is_wp_error($result), 'the resync must succeed');

        $subscription = Subscription::query()->find($subscription->id);
        $history = (array) $subscription->getMeta('early_payment_history', []);
        $this->assertCount(1, $history, 'the single-cycle sale at ¥77 must corroborate the ¥154 collection');
        $this->assertSame(2, (int) Arr::get($history[0], 'count'));
        $this->assertSame(3, $subscription->calculateBillCount());
        $this->assertSame(
            1,
            Order::query()->where('parent_id', $order->id)->where('type', Status::ORDER_TYPE_RENEWAL)->count(),
            'the backfill must not duplicate the already-recorded renewal'
        );
    }

    /**
     * every early_payment_history mutation — credit or revoke, any sale id —
     * runs under the subscription's fc_sub_history_ GET_LOCK: the meta is one
     * blob per subscription, so a per-sale lock would let a revoke of sale A
     * race a credit of sale B and lose the update. A rival holding that lock
     * (second DB connection — GET_LOCK is per-connection) must make the credit
     * write nothing and report the timeout as retryable: because the lock is
     * per-subscription the holder may be working on an unrelated sale, so
     * losing it says nothing about whether THIS sale is credited.
     */
    public function test_outstanding_credit_writes_nothing_while_a_concurrent_delivery_holds_the_history_lock(): void
    {
        [$subscription] = $this->buildUnstampedPayPalSubscription(
            [],
            ['vendor_charge_id' => $this->firstSaleId]
        );

        $rival = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        // A second connection is part of this fixture; without it the lock contention is untested.
        $this->assertNotFalse($rival, 'could not open a second DB connection to simulate the rival delivery');

        $lockName = 'fc_sub_history_' . $subscription->id;

        try {
            $row = mysqli_fetch_row(mysqli_query($rival, "SELECT GET_LOCK('" . mysqli_real_escape_string($rival, $lockName) . "', 5)"));
            $this->assertSame('1', (string) $row[0], 'the rival connection must hold the history lock');

            $credited = (new PayPalSubscriptions())->creditOutstandingCollection(
                $subscription,
                $this->renewalSaleId,
                Helper::toCent(154.80)
            );

            $this->assertTrue(is_wp_error($credited), 'a lock timeout is retryable, not a no-credit verdict');
            $this->assertSame('paypal_history_lock_timeout', $credited->get_error_code());
            $this->assertCount(
                0,
                (array) Subscription::query()->find($subscription->id)->getMeta('early_payment_history', []),
                'no history entry may be written while a concurrent delivery holds the lock'
            );
        } finally {
            mysqli_query($rival, "SELECT RELEASE_LOCK('" . mysqli_real_escape_string($rival, $lockName) . "')");
            mysqli_close($rival);
        }

        // Lock free again: the same call now credits normally.
        $this->assertTrue(
            (new PayPalSubscriptions())->creditOutstandingCollection($subscription, $this->renewalSaleId, Helper::toCent(154.80))
        );
        $this->assertCount(1, (array) Subscription::query()->find($subscription->id)->getMeta('early_payment_history', []));
    }

    /**
     * fc_sub_history_ is per-subscription, so an unrelated sale's credit or
     * revoke can hold it. Recording the payment anyway would hide the missing
     * cycles behind processRecurringPaymentReceived()'s existing-transaction
     * early return, where no redelivery can ever reach them again.
     */
    public function test_history_lock_timeout_defers_the_renewal_until_redelivery(): void
    {
        [$subscription] = $this->buildUnstampedPayPalSubscription(
            ['bill_times' => 3, 'bill_count' => 1],
            ['vendor_charge_id' => $this->firstSaleId]
        );
        $this->scriptRemoteSubscription([]);

        $webhookData = [
            'vendor_subscription_id' => $this->vendorSubId,
            'charge'                 => [
                'id'     => $this->renewalSaleId,
                'custom' => $subscription->uuid,
                'amount' => ['total' => '154.80']
            ],
        ];

        $rival = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        // A second connection is part of this fixture; without it the lock contention is untested.
        $this->assertNotFalse($rival, 'could not open a second DB connection to simulate the rival delivery');

        $lockName = 'fc_sub_history_' . $subscription->id;

        try {
            $row = mysqli_fetch_row(mysqli_query($rival, "SELECT GET_LOCK('" . mysqli_real_escape_string($rival, $lockName) . "', 5)"));
            $this->assertSame('1', (string) $row[0], 'a different sale on this subscription owns the history lock');

            $this->assertFalse(
                (new IPN())->processRecurringPaymentReceived($webhookData),
                'an undecided credit must not be acknowledged'
            );
            $this->assertFalse(
                OrderTransaction::query()->where('vendor_charge_id', $this->renewalSaleId)->exists(),
                'nothing is recorded, so redelivery can still repair it'
            );
        } finally {
            mysqli_query($rival, "SELECT RELEASE_LOCK('" . mysqli_real_escape_string($rival, $lockName) . "')");
            mysqli_close($rival);
        }

        $this->assertTrue(
            (new IPN())->processRecurringPaymentReceived($webhookData),
            'the redelivery credits and records together'
        );

        $subscription = Subscription::query()->find($subscription->id);
        $this->assertSame(3, (int) $subscription->bill_count, 'three paid cycles survive contention and redelivery');
    }
}

<?php

namespace Tests\Integration\Services;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\Payments\Refund;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Doubles\FailingQueries;
use Tests\Support\Doubles\FakeRefundGateway;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Traits\BuildsRefundFixtures;

class RefundResyncTest extends DatabaseTestCase
{
    use BuildsRefundFixtures;

    public function setUp(): void
    {
        parent::setUp();
        FakeRefundGateway::register();
    }

    public function tearDown(): void
    {
        FakeRefundGateway::remove();
        parent::tearDown();
    }

    public function test_row_not_awaiting_resync_has_nothing_to_resync(): void
    {
        $txn = $this->paidCharge();
        list($item, $intent) = $this->oneUnitRefundIntent($txn);
        $result = (new Refund())->processRefund($txn, 3000, [], $intent);
        $this->assertFalse($result['needs_resync']);
        $refund = $this->refundRows($txn->order_id)->first();

        $result = Refund::resync($this->order($txn), $refund);

        $this->assertWpError('nothing_to_resync', 400, $result);
        $this->assertSame(1, $this->refundedQuantity($item));
        $this->assertSame(3000, (int) $this->order($txn)->total_refund);
    }

    public function test_line_refunded_since_the_fallback_is_order_changed(): void
    {
        list($txn, $item, $refund) = $this->rowAwaitingResync();
        $lineMeta = $item->line_meta;
        $lineMeta['refunded_quantity'] = 1;
        $item->line_meta = $lineMeta;
        $item->save();

        $result = Refund::resync($this->order($txn), $refund);

        $this->assertWpError('refund_order_changed', 409, $result);
        $this->assertStillAwaitingResync($refund);
        $this->assertSame(1, $this->refundedQuantity($item));
        $this->assertSame(0, (int) $this->order($txn)->total_refund);
    }

    public function test_line_deleted_since_the_fallback_is_order_changed(): void
    {
        list($txn, $item, $refund) = $this->rowAwaitingResync();
        $item->delete();

        $result = Refund::resync($this->order($txn), $refund);

        $this->assertWpError('refund_order_changed', 409, $result);
        $this->assertStillAwaitingResync($refund);
        $this->assertSame(0, (int) $this->order($txn)->total_refund);
    }

    public function test_lock_held_by_another_request_is_409(): void
    {
        list($txn, $item, $refund) = $this->rowAwaitingResync();
        // GET_LOCK is re-entrant on one connection, so the holder needs its own.
        $other = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $lockName = 'fct_refund_' . $txn->order_id;
        $this->assertSame(1, (int) $other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $lockName)));

        try {
            $result = Refund::resync($this->order($txn), $refund);
        } finally {
            $other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
            $other->close();
        }

        $this->assertWpError('refund_lock_timeout', 409, $result);
        $this->assertStillAwaitingResync($refund);
        $this->assertSame(0, $this->refundedQuantity($item));
        $this->assertSame(0, (int) $this->order($txn)->total_refund);
    }

    public function test_row_not_refunded_has_nothing_to_resync(): void
    {
        list($txn, $item, $refund) = $this->rowAwaitingResync();
        $row = $this->freshRefund($refund);
        $row->status = Status::TRANSACTION_PENDING;
        $row->save();

        $result = Refund::resync($this->order($txn), $refund);

        $this->assertWpError('nothing_to_resync', 400, $result);
        $this->assertSame(0, $this->refundedQuantity($item));
    }

    public function test_failed_row_save_rolls_back_the_line_write(): void
    {
        list($txn, $item, $refund) = $this->rowAwaitingResync();
        $filter = FailingQueries::matching('/^update `[^`]*fct_order_transactions`/i');

        try {
            $result = Refund::resync($this->order($txn), $refund);
        } finally {
            $filter->remove();
        }

        $this->assertWpError('resync_failed', 500, $result);
        $this->assertStillAwaitingResync($refund);
        $this->assertSame(1, (int) ($this->freshRefund($refund)->meta['refund_intent']['items'][0]['refund_qty'] ?? 0));
        $this->assertSame(0, $this->refundedQuantity($item));
        $this->assertSame(0, (int) $this->order($txn)->total_refund);
    }

    public function test_failed_line_write_stays_pending(): void
    {
        list($txn, $item, $refund) = $this->rowAwaitingResync();
        $filter = FailingQueries::matching('/^update `[^`]*fct_order_items`/i');

        try {
            $result = Refund::resync($this->order($txn), $refund);
        } finally {
            $filter->remove();
        }

        $this->assertWpError('resync_failed', 500, $result);
        $this->assertStillAwaitingResync($refund);
        $this->assertSame(1, (int) ($this->freshRefund($refund)->meta['refund_intent']['items'][0]['refund_qty'] ?? 0));
        $this->assertSame(0, $this->refundedQuantity($item));
        $this->assertSame(0, (int) $this->order($txn)->total_refund);
    }

    public function test_failed_count_stays_pending_and_a_retry_counts_once(): void
    {
        list($txn, $item, $refund) = $this->rowAwaitingResync();
        $filter = FailingQueries::matching('/^update `[^`]*fct_orders` set/i');

        try {
            $result = Refund::resync($this->order($txn), $refund);
        } finally {
            $filter->remove();
        }

        $this->assertWpError('resync_failed', 500, $result);
        $this->assertStillAwaitingResync($refund);
        $this->assertSame([], $this->freshRefund($refund)->meta['refund_intent']['items'] ?? null);
        $this->assertSame(1, $this->refundedQuantity($item));
        $this->assertSame(0, (int) $this->order($txn)->total_refund);
        $this->assertSame(0, (int) OrderItem::query()->findOrFail($item->id)->refund_total);

        $this->assertTrue(Refund::resync($this->order($txn), $this->freshRefund($refund)));
        $this->assertSame('done', $this->freshRefund($refund)->meta['local_sync'] ?? null);
        $this->assertSame(1, $this->refundedQuantity($item));
        $this->assertSame(3000, (int) $this->order($txn)->total_refund);
        $this->assertSame(3000, (int) OrderItem::query()->findOrFail($item->id)->refund_total);
    }

    /**
     * A gateway-accepted refund whose line write failed every attempt: row saved bare, awaiting resync.
     */
    private function rowAwaitingResync(): array
    {
        $txn = $this->paidCharge();
        list($item, $intent) = $this->oneUnitRefundIntent($txn);
        $filter = FailingQueries::matching('/^update `[^`]*fct_order_items`/i');

        try {
            $result = (new Refund())->processRefund($txn, 3000, [], $intent);
        } finally {
            $filter->remove();
        }

        $this->assertTrue($result['needs_resync']);
        $refund = $this->refundRows($txn->order_id)->first();
        $this->assertTrue($refund->isAwaitingResync());
        $this->assertSame(0, $this->refundedQuantity($item));

        return [$txn, $item, $refund];
    }

    private function oneUnitRefundIntent(OrderTransaction $txn): array
    {
        $item = (new OrderItemFactory())->create((int) $txn->order_id, [
            'quantity'   => 2,
            'unit_price' => 5000,
            'subtotal'   => 10000,
            'line_total' => 10000,
        ]);

        return [$item, [
            'items'           => [[
                'id'               => (int) $item->id,
                'variation_id'     => (int) $item->object_id,
                'refund_qty'       => 1,
                'restore_quantity' => 1,
                'restock_type'     => 'cancel',
                'pre_refund_qty'   => 2,
                'refund_amount'    => 3000,
            ]],
            'shipping_amount' => 0,
            'manage_stock'    => false,
        ]];
    }

    private function assertWpError(string $code, int $status, $result): void
    {
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame($code, $result->get_error_code());
        $this->assertSame($status, $result->get_error_data()['status'] ?? null);
    }

    private function assertStillAwaitingResync(OrderTransaction $refund): void
    {
        $this->assertTrue($this->freshRefund($refund)->isAwaitingResync());
    }

    private function freshRefund(OrderTransaction $refund): OrderTransaction
    {
        return OrderTransaction::query()->findOrFail($refund->id);
    }

    private function order(OrderTransaction $txn): Order
    {
        return Order::query()->findOrFail($txn->order_id);
    }

    private function refundedQuantity(OrderItem $item): int
    {
        return (int) (OrderItem::query()->findOrFail($item->id)->line_meta['refunded_quantity'] ?? 0);
    }
}

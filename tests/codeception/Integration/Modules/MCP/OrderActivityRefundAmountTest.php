<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\MCP\Tools\OrderTools;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\ActivityFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;

/**
 * Priority-6 regression: get-order-activity must carry the amount on activity-source
 * refund rows, not just the transaction rows. The Activity model stores no amount,
 * so the row is backfilled from the closest refund transaction on the same order.
 *
 * Modeled on order 7637892: a $799 full refund produces one refund transaction row
 * (amount populated already) and an "Order Refund" activity row (amount was null).
 */
class OrderActivityRefundAmountTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private OrderTransactionFactory $txnFactory;
    private ActivityFactory $activityFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory    = new OrderFactory();
        $this->txnFactory      = new OrderTransactionFactory();
        $this->activityFactory = new ActivityFactory();
    }

    private function timeline(int $orderId): array
    {
        $res = OrderTools::getOrderActivity(['order_id' => $orderId]);
        return $res['data']['timeline'];
    }

    private function firstRow(array $timeline, callable $match): ?array
    {
        foreach ($timeline as $row) {
            if ($match($row)) {
                return $row;
            }
        }
        return null;
    }

    public function test_activity_refund_row_gets_amount_from_transaction(): void
    {
        $cid   = $this->customerFactory->create()->id;
        $order = $this->orderFactory->create([
            'customer_id'    => $cid,
            'payment_status' => Status::PAYMENT_REFUNDED,
            'status'         => Status::ORDER_CANCELED,
            'currency'       => 'USD',
            'total_amount'   => Helper::toCent(799),
            'total_paid'     => Helper::toCent(799),
            'total_refund'   => Helper::toCent(799),
            'created_at'     => '2026-07-07 02:45:51',
        ]);

        // The charge (hours earlier) and the refund.
        $this->txnFactory->create($order->id, [
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => Helper::toCent(799),
            'created_at'       => '2026-07-07 02:45:52',
        ]);
        $this->txnFactory->create($order->id, [
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'total'            => Helper::toCent(799),
            'created_at'       => '2026-07-07 05:36:24',
        ]);

        // The activity log written seconds after the refund — no amount of its own.
        $this->activityFactory->createForOrder($order->id, [
            'title'      => 'Order Refund',
            'content'    => 'Order Refund successfully!',
            'created_at' => '2026-07-07 05:36:26',
        ]);
        // A plain note that must NOT get a money amount.
        $this->activityFactory->createForOrder($order->id, [
            'title'      => 'Note added',
            'content'    => 'Customer emailed about the order.',
            'created_at' => '2026-07-07 06:00:00',
        ]);

        $timeline = $this->timeline($order->id);

        $activityRefund = $this->firstRow($timeline, function ($r) {
            return $r['source'] === 'activity' && $r['title'] === 'Order Refund';
        });
        $this->assertNotNull($activityRefund, 'The activity refund row is present.');
        $this->assertNotNull($activityRefund['amount'], 'Activity refund row now carries an amount.');
        $this->assertSame(Helper::toCent(799), $activityRefund['amount']['amount_cents'], 'It is the refunded $799 from the transaction.');

        // The transaction refund row still carries its amount (unchanged).
        $txnRefund = $this->firstRow($timeline, function ($r) {
            return $r['source'] === 'transaction' && $r['event'] === 'refund';
        });
        $this->assertSame(Helper::toCent(799), $txnRefund['amount']['amount_cents']);

        // A non-money note is untouched.
        $note = $this->firstRow($timeline, function ($r) {
            return $r['source'] === 'activity' && $r['title'] === 'Note added';
        });
        $this->assertNull($note['amount'], 'A plain note gets no amount.');
    }
}

<?php

namespace Tests\Integration\Database;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Database\DataBackfills;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTransactionFactory;

/**
 * Old amount refunds stopped each line at its pre-tax total and never recorded shipping.
 * The repair writes only the part of the split that is certain.
 */
class RefundTotalsRepairBackfillTest extends DatabaseTestCase
{
    private const SLUG = 'refund_totals_repair';

    protected function setUp(): void
    {
        parent::setUp();

        $option = (array) fluent_cart_get_option('_db_migrations', [], false);
        unset($option['backfills'][self::SLUG]);
        fluent_cart_update_option('_db_migrations', $option);
        fluent_cart_update_option('_fluent_cart_refund_totals_repair_report', []);
    }

    public function test_a_fully_refunded_order_gets_each_lines_tax_and_the_shipping_back(): void
    {
        [$order, $items] = $this->oldRefundedOrder(23000, [10000, 10000]);

        DataBackfills::processPending();

        $this->assertSame([11000, 11000], $this->refundTotals($items));
        $this->assertSame([1000, '1'], $this->shippingRefunded($order));
        $this->assertSame(1, (int) $this->report('repaired_count'));
    }

    public function test_a_single_line_gets_what_shipping_could_not_have_taken(): void
    {
        // 115.00 of 120.00: at least 105.00 was the line, at least 5.00 the shipping
        [$order, $items] = $this->oldRefundedOrder(11500, [10000]);

        DataBackfills::processPending();

        $this->assertSame([10500], $this->refundTotals($items));
        $this->assertSame([500, ''], $this->shippingRefunded($order));
    }

    public function test_a_partly_refunded_multi_line_order_gets_only_the_forced_part(): void
    {
        // 225.00 of 230.00: each line must hold 225.00 - 10.00 shipping - 110.00 other line
        [$order, $items] = $this->oldRefundedOrder(22500, [10000, 10000]);

        DataBackfills::processPending();

        $this->assertSame([10500, 10500], $this->refundTotals($items));
        $this->assertSame([500, ''], $this->shippingRefunded($order));
    }

    public function test_a_split_that_could_go_either_way_is_left_alone(): void
    {
        [$order, $items] = $this->oldRefundedOrder(21500, [10000, 10000]);

        DataBackfills::processPending();

        $this->assertSame([10000, 10000], $this->refundTotals($items));
        $this->assertSame([0, ''], $this->shippingRefunded($order));
        $this->assertSame(0, (int) $this->report('skipped_count'));
    }

    public function test_a_line_that_held_shipping_is_lowered_to_its_charge(): void
    {
        // the old one-item path put line A's 110.00 and the 10.00 shipping on line A
        [$order, $items] = $this->oldRefundedOrder(23000, [12000, 10000]);

        DataBackfills::processPending();

        $this->assertSame([11000, 11000], $this->refundTotals($items));
        $this->assertSame([1000, '1'], $this->shippingRefunded($order));
    }

    public function test_an_order_with_money_outside_lines_and_shipping_is_skipped(): void
    {
        [$order, $items] = $this->oldRefundedOrder(23000, [10000, 10000]);
        $this->setOrderColumns($order->id, ['total_amount' => 23500]);
        $this->setChargeTotal($order->id, 23500);

        DataBackfills::processPending();

        $this->assertSame([10000, 10000], $this->refundTotals($items));
        $this->assertSame([0, ''], $this->shippingRefunded($order));
        $this->assertContains($order->id, (array) $this->report('skipped_order_ids'));
    }

    public function test_an_order_edited_after_payment_is_skipped(): void
    {
        // line B refunded, then deleted and the total lowered: the lines still add up, the charge does not
        [$order, $items] = $this->oldRefundedOrder(11000, [0, 11000]);
        OrderItem::query()->where('id', $items[1]->id)->delete();
        $this->setOrderColumns($order->id, ['total_amount' => 12000, 'tax_total' => 1000]);

        DataBackfills::processPending();

        $this->assertSame([0], $this->refundTotals([$items[0]]));
        $this->assertContains($order->id, (array) $this->report('skipped_order_ids'));
    }

    public function test_a_charge_marked_refunded_is_not_taken_as_a_refund(): void
    {
        [$order, $items] = $this->oldRefundedOrder(23000, [10000, 10000], 1, false);
        $this->addTransaction($order->id, Status::TRANSACTION_TYPE_CHARGE, Status::TRANSACTION_REFUNDED, 23000);

        DataBackfills::processPending();

        $this->assertSame([10000, 10000], $this->refundTotals($items));
        $this->assertContains($order->id, (array) $this->report('skipped_order_ids'));
    }

    public function test_a_charge_row_marked_refunded_beside_the_real_charge_is_not_counted_as_a_refund(): void
    {
        [$order, $items] = $this->oldRefundedOrder(23000, [10000, 10000]);
        $this->addTransaction($order->id, Status::TRANSACTION_TYPE_CHARGE, Status::TRANSACTION_REFUNDED, 5000);

        DataBackfills::processPending();

        $this->assertSame([11000, 11000], $this->refundTotals($items));
    }

    public function test_a_duplicate_refund_row_is_skipped(): void
    {
        [$order, $items] = $this->oldRefundedOrder(23000, [10000, 10000]);
        $this->addTransaction($order->id, Status::TRANSACTION_TYPE_REFUND, Status::TRANSACTION_REFUNDED, 23000);

        DataBackfills::processPending();

        $this->assertSame([10000, 10000], $this->refundTotals($items));
        $this->assertContains($order->id, (array) $this->report('skipped_order_ids'));
    }

    public function test_a_refund_above_the_order_total_is_skipped(): void
    {
        [$order, $items] = $this->oldRefundedOrder(24000, [10000, 10000]);

        DataBackfills::processPending();

        $this->assertSame([10000, 10000], $this->refundTotals($items));
        $this->assertContains($order->id, (array) $this->report('skipped_order_ids'));
    }

    public function test_a_refund_still_waiting_on_the_gateway_is_not_counted(): void
    {
        [$order, $items] = $this->oldRefundedOrder(23000, [10000, 10000]);
        OrderTransaction::query()
            ->where('order_id', $order->id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->first()
            ->update(['meta' => ['local_sync' => 'pending']]);

        DataBackfills::processPending();

        $this->assertSame([10000, 10000], $this->refundTotals($items));
        $this->assertContains($order->id, (array) $this->report('skipped_order_ids'));
    }

    public function test_an_order_refunded_in_the_last_minutes_is_skipped(): void
    {
        [$order, $items] = $this->oldRefundedOrder(23000, [10000, 10000]);
        OrderTransaction::query()
            ->where('order_id', $order->id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->update(['created_at' => gmdate('Y-m-d H:i:s')]);

        DataBackfills::processPending();

        $this->assertSame([10000, 10000], $this->refundTotals($items));
        $this->assertContains($order->id, (array) $this->report('skipped_order_ids'));
    }

    public function test_a_line_above_its_charge_on_a_partial_refund_is_skipped(): void
    {
        // 120.00 on line A could be 110.00 line + 10.00 shipping or 120.00 split any other way
        [$order, $items] = $this->oldRefundedOrder(12000, [12000, 0]);

        DataBackfills::processPending();

        $this->assertSame([12000, 0], $this->refundTotals($items));
        $this->assertSame([0, ''], $this->shippingRefunded($order));
        $this->assertContains($order->id, (array) $this->report('skipped_order_ids'));
    }

    public function test_recorded_shipping_is_only_ever_raised(): void
    {
        [$higher] = $this->oldRefundedOrder(22500, [10500, 10500]);
        $higher->updateMeta('shipping_refunded_amount', 800);
        [$lower] = $this->oldRefundedOrder(23000, [11000, 11000]);
        $lower->updateMeta('shipping_refunded_amount', 300);

        DataBackfills::processPending();

        $this->assertSame([800, ''], $this->shippingRefunded($higher));
        $this->assertSame([1000, '1'], $this->shippingRefunded($lower));
    }

    public function test_an_order_being_refunded_right_now_is_retried_later(): void
    {
        [$order, $items] = $this->oldRefundedOrder(23000, [10000, 10000]);
        $other = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $other->get_var($other->prepare('SELECT GET_LOCK(%s, %d)', 'fct_refund_' . $order->id, 0));

        DataBackfills::processPending();

        $this->assertSame([10000, 10000], $this->refundTotals($items));
        $this->assertContains(self::SLUG, DataBackfills::getPending());

        $other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)', 'fct_refund_' . $order->id));
        $other->close();
        DataBackfills::processPending();

        $this->assertSame([11000, 11000], $this->refundTotals($items));
        $this->assertNotContains(self::SLUG, DataBackfills::getPending());
    }

    public function test_a_replayed_chunk_does_not_count_a_skipped_order_twice(): void
    {
        [$skipped] = $this->oldRefundedOrder(24000, [10000, 10000]);
        [$busy] = $this->oldRefundedOrder(23000, [10000, 10000]);
        $other = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $other->get_var($other->prepare('SELECT GET_LOCK(%s, %d)', 'fct_refund_' . $busy->id, 0));

        DataBackfills::processPending();
        $other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)', 'fct_refund_' . $busy->id));
        $other->close();
        DataBackfills::processPending();

        $this->assertSame(1, (int) $this->report('skipped_count'));
        $this->assertSame([$skipped->id], (array) $this->report('skipped_order_ids'));
        $this->assertSame(1, (int) $this->report('repaired_count'));
    }

    public function test_tax_inclusive_lines_are_left_alone(): void
    {
        [$order, $items] = $this->oldRefundedOrder(23000, [11000, 11000], 2);

        DataBackfills::processPending();

        $this->assertSame([11000, 11000], $this->refundTotals($items));
        $this->assertSame([1000, '1'], $this->shippingRefunded($order));
    }

    public function test_an_order_without_tax_or_shipping_is_never_read(): void
    {
        $order = (new OrderFactory())->create([
            'customer_id'    => (new CustomerFactory())->create()->id,
            'tax_behavior'   => 1,
            'tax_total'      => 0,
            'shipping_total' => 0,
            'total_amount'   => 10000,
            'total_paid'     => 10000,
            'total_refund'   => 10000,
            'payment_status' => Status::PAYMENT_REFUNDED,
        ]);
        $item = (new OrderItemFactory())->create($order->id, [
            'unit_price'   => 10000,
            'subtotal'     => 10000,
            'line_total'   => 10000,
            'tax_amount'   => 0,
            'refund_total' => 9000,
            'other_info'   => ['payment_type' => 'onetime'],
        ]);
        $this->addTransactions($order->id, 10000, 10000);

        DataBackfills::processPending();

        $this->assertSame([9000], $this->refundTotals([$item]));
        $this->assertSame(0, (int) $this->report('skipped_count'));
        $this->assertNotContains(self::SLUG, DataBackfills::getPending());
    }

    public function test_a_fully_refunded_renewal_line_gets_nothing_added(): void
    {
        $order = (new OrderFactory())->create([
            'customer_id'    => (new CustomerFactory())->create()->id,
            'type'           => Status::ORDER_TYPE_RENEWAL,
            'tax_behavior'   => 1,
            'subtotal'       => 123000,
            'tax_total'      => 12300,
            'shipping_total' => 1000,
            'total_amount'   => 136300,
            'total_paid'     => 136300,
            'total_refund'   => 136300,
            'payment_status' => Status::PAYMENT_REFUNDED,
        ]);
        $item = (new OrderItemFactory())->create($order->id, [
            'unit_price'   => 123000,
            'subtotal'     => 123000,
            'line_total'   => 135300,
            'tax_amount'   => 12300,
            'refund_total' => 135300,
            'line_meta'    => [],
            'other_info'   => [],
        ]);
        $this->addTransactions($order->id, 136300, 136300);

        DataBackfills::processPending();

        $this->assertSame([135300], $this->refundTotals([$item]));
        $this->assertSame([1000, '1'], $this->shippingRefunded($order));
    }

    public function test_the_repair_resumes_across_budgeted_runs_and_never_applies_twice(): void
    {
        [, $first] = $this->oldRefundedOrder(23000, [10000, 10000]);
        [, $second] = $this->oldRefundedOrder(23000, [10000, 10000]);

        $budget = function ($budget, $context) {
            return $context['slug'] === self::SLUG ? ['chunk_size' => 1, 'max_chunks_per_run' => 1] : $budget;
        };
        add_filter('fluent_cart/data_backfills/chunk_budget', $budget, 10, 2);
        for ($i = 0; $i < 10 && in_array(self::SLUG, DataBackfills::getPending(), true); $i++) {
            DataBackfills::processPending();
        }
        remove_filter('fluent_cart/data_backfills/chunk_budget', $budget, 10);

        $this->assertNotContains(self::SLUG, DataBackfills::getPending());

        $option = (array) fluent_cart_get_option('_db_migrations', [], false);
        unset($option['backfills'][self::SLUG]);
        fluent_cart_update_option('_db_migrations', $option);
        DataBackfills::processPending();

        $this->assertSame([11000, 11000], $this->refundTotals($first));
        $this->assertSame([11000, 11000], $this->refundTotals($second));
        $this->assertSame(2, (int) $this->report('repaired_count'));
    }

    /**
     * Lines of 100.00 + 10.00 tax, 10.00 shipping, paid in one charge and refunded in one refund.
     *
     * @param int $refunded order total_refund
     * @param int[] $lineRefunds refund_total per line, as the old spread left it
     * @param int $taxBehavior 1 exclusive, 2 inclusive
     */
    private function oldRefundedOrder(int $refunded, array $lineRefunds, int $taxBehavior = 1, bool $charge = true): array
    {
        $lineTotal = $taxBehavior === 2 ? 11000 : 10000;
        $lines = count($lineRefunds);
        $total = 11000 * $lines + 1000;
        $order = (new OrderFactory())->create([
            'customer_id'    => (new CustomerFactory())->create()->id,
            'tax_behavior'   => $taxBehavior,
            'shipping_total' => 1000,
            'tax_total'      => 1000 * $lines,
            'total_amount'   => $total,
            'total_paid'     => $total,
            'total_refund'   => $refunded,
            'payment_status' => Status::PAYMENT_PARTIALLY_REFUNDED,
        ]);

        $items = [];
        foreach ($lineRefunds as $lineRefund) {
            $items[] = (new OrderItemFactory())->create($order->id, [
                'unit_price'   => $lineTotal,
                'subtotal'     => $lineTotal,
                'line_total'   => $lineTotal,
                'tax_amount'   => 1000,
                'refund_total' => $lineRefund,
                'other_info'   => ['payment_type' => 'onetime', 'tax_inclusion' => $taxBehavior === 2 ? 'included' : 'excluded'],
            ]);
        }

        $this->addTransactions($order->id, $charge ? $total : 0, $refunded);

        return [$order, $items];
    }

    private function addTransactions(int $orderId, int $charged, int $refunded): void
    {
        if ($charged) {
            $this->addTransaction($orderId, Status::TRANSACTION_TYPE_CHARGE, Status::TRANSACTION_SUCCEEDED, $charged);
        }

        $this->addTransaction($orderId, Status::TRANSACTION_TYPE_REFUND, Status::TRANSACTION_REFUNDED, $refunded);
    }

    private function addTransaction(int $orderId, string $type, string $status, int $total): void
    {
        (new OrderTransactionFactory())->create($orderId, [
            'transaction_type' => $type,
            'status'           => $status,
            'total'            => $total,
            'created_at'       => gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS),
        ]);
    }

    private function setOrderColumns(int $orderId, array $columns): void
    {
        global $wpdb;
        $wpdb->update($this->fullTableName('fct_orders'), $columns, ['id' => $orderId]);
    }

    private function setChargeTotal(int $orderId, int $total): void
    {
        global $wpdb;
        $wpdb->update(
            $this->fullTableName('fct_order_transactions'),
            ['total' => $total],
            ['order_id' => $orderId, 'transaction_type' => Status::TRANSACTION_TYPE_CHARGE]
        );
    }

    private function refundTotals(array $items): array
    {
        return array_map(function ($item) {
            return (int) OrderItem::query()->find($item->id)->refund_total;
        }, $items);
    }

    private function shippingRefunded($order): array
    {
        $order = Order::query()->find($order->id);

        return [(int) $order->getMeta('shipping_refunded_amount', 0), (string) $order->getMeta('shipping_refunded', '')];
    }

    private function report(string $key)
    {
        $report = (array) fluent_cart_get_option('_fluent_cart_refund_totals_repair_report', [], false);

        return $report[$key] ?? null;
    }
}

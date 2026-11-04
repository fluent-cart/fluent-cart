<?php

namespace Tests\Functional\Rest\Orders;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use PHPUnit\Framework\Assert;
use Tests\Support\Doubles\FakeRefundGateway;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\BuildsRefundFixtures;

/**
 * An item refund may post a lower amount than the selected items and shipping add up to.
 * Shipping keeps what was entered for it; the selected items share the rest by their refund amounts.
 */
class ItemRefundCustomAmountCest
{
    use BuildsRefundFixtures;

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        FakeRefundGateway::register();
    }

    public function _after(FunctionalTester $I): void
    {
        FakeRefundGateway::remove();
    }

    public function itemRowsAddUpToThePostedAmount(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, [$itemA], $transaction] = $this->order([[1, 6000]]);

        [$status, $body] = $this->refund($I, $order, $transaction, 5000, [[(int) $itemA->id, 1]]);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(5000, (int) $this->refundRows((int) $order->id)[0]->total);
        Assert::assertSame(5000, (int) $this->column('fct_order_items', 'refund_total', (int) $itemA->id));
        Assert::assertSame(5000, (int) $this->column('fct_orders', 'total_refund', (int) $order->id));
        Assert::assertSame(Status::PAYMENT_PARTIALLY_REFUNDED, $this->column('fct_orders', 'payment_status', (int) $order->id));
    }

    public function shippingKeepsItsAmountAndItemsTakeTheWholeCut(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, [$itemA], $transaction] = $this->order([[1, 6000]], 1000);

        [$status, $body] = $this->refund($I, $order, $transaction, 6500, [[(int) $itemA->id, 1]], 1000);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(6500, (int) $this->refundRows((int) $order->id)[0]->total);
        Assert::assertSame(5500, (int) $this->column('fct_order_items', 'refund_total', (int) $itemA->id));
        Assert::assertSame(1000, (int) Order::query()->findOrFail((int) $order->id)->getMeta('shipping_refunded_amount'));
    }

    public function cutAcrossTwoItemsIsProportionalToTheCent(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, [$itemA, $itemB], $transaction] = $this->order([[1, 6000], [2, 4000]]);

        // Cut 999 at 60/40: 599.4 and 399.6, the leftover cent goes to the larger remainder.
        [$status, $body] = $this->refund($I, $order, $transaction, 9001, [[(int) $itemA->id, 1], [(int) $itemB->id, 2]]);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(9001, (int) $this->refundRows((int) $order->id)[0]->total);
        Assert::assertSame(5401, (int) $this->column('fct_order_items', 'refund_total', (int) $itemA->id));
        Assert::assertSame(3600, (int) $this->column('fct_order_items', 'refund_total', (int) $itemB->id));
    }

    public function leftoverCentGoesToTheLargerRemainderNotTheLastRow(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, [$itemA, $itemB], $transaction] = $this->order([[2, 4000], [1, 6000]]);

        // Cut 999 at 40/60: 399.6 and 599.4, the leftover cent goes to the first row.
        [$status, $body] = $this->refund($I, $order, $transaction, 9001, [[(int) $itemA->id, 2], [(int) $itemB->id, 1]]);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(3600, (int) $this->column('fct_order_items', 'refund_total', (int) $itemA->id));
        Assert::assertSame(5401, (int) $this->column('fct_order_items', 'refund_total', (int) $itemB->id));
    }

    public function postedAmountIsCheckedAgainstThePaidCap(FunctionalTester $I): void
    {
        global $wpdb;
        $I->loginAsSuperAdmin();
        [$order, [$itemA, $itemB], $transaction] = $this->order([[1, 6000], [1, 4000]]);
        // An order-level credit leaves the lines above what was paid.
        $wpdb->update($wpdb->prefix . 'fct_orders', ['total_paid' => 8000], ['id' => (int) $order->id]);
        $wpdb->update($wpdb->prefix . 'fct_order_transactions', ['total' => 8000], ['id' => (int) $transaction->id]);
        $selection = [[(int) $itemA->id, 1], [(int) $itemB->id, 1]];

        [$status, $body] = $this->refund($I, $order, $transaction, 9000, $selection);
        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertCount(0, $this->refundRows((int) $order->id));

        // Capped rows are 6000 and 2000; a 1000 cut splits 750 and 250.
        [$status, $body] = $this->refund($I, $order, $transaction, 7000, $selection);
        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(5250, (int) $this->column('fct_order_items', 'refund_total', (int) $itemA->id));
        Assert::assertSame(1750, (int) $this->column('fct_order_items', 'refund_total', (int) $itemB->id));
    }

    public function largeDongLinesSplitWithoutOverflow(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, [$itemA, $itemB], $transaction] = $this->order([[1, 10000000000], [1, 5000000000]], 0, 'VND');

        [$status, $body] = $this->refund($I, $order, $transaction, 13500000000, [[(int) $itemA->id, 1], [(int) $itemB->id, 1]]);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(9000000000, (int) $this->column('fct_order_items', 'refund_total', (int) $itemA->id));
        Assert::assertSame(4500000000, (int) $this->column('fct_order_items', 'refund_total', (int) $itemB->id));
    }

    public function amountAboveTheSelectionIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, [$itemA], $transaction] = $this->order([[1, 6000], [1, 4000]]);

        [$status, $body] = $this->refund($I, $order, $transaction, 6001, [[(int) $itemA->id, 1]]);

        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame('Refund amount cannot be more than the selected items and shipping.', $body['message'] ?? null);
        Assert::assertCount(0, $this->refundRows((int) $order->id));
        Assert::assertSame(0, FakeRefundGateway::$refundCalls);
    }

    public function amountAtOrBelowTheShippingIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, [$itemA], $transaction] = $this->order([[1, 6000]], 1000);

        foreach ([1000, 400] as $posted) {
            [$status, $body] = $this->refund($I, $order, $transaction, $posted, [[(int) $itemA->id, 1]], 1000);

            Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
            Assert::assertSame('Refund amount must be more than the shipping refund.', $body['message'] ?? null);
        }
        Assert::assertCount(0, $this->refundRows((int) $order->id));
    }

    public function nonNumericOrNegativeAmountIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, [$itemA], $transaction] = $this->order([[1, 6000]]);

        foreach (['abc', -100] as $posted) {
            [$status, $body] = $this->refund($I, $order, $transaction, $posted, [[(int) $itemA->id, 1]]);

            Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
            Assert::assertSame('Invalid refund amount.', $body['message'] ?? null);
        }
        Assert::assertCount(0, $this->refundRows((int) $order->id));
    }

    public function fractionOfAYenIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, [$itemA], $transaction] = $this->order([[1, 20000]], 0, 'JPY');

        [$status, $body] = $this->refund($I, $order, $transaction, 15050, [[(int) $itemA->id, 1]]);

        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString('smallest unit', (string) ($body['message'] ?? ''));
        Assert::assertCount(0, $this->refundRows((int) $order->id));
    }

    public function yenCutIsSplitInWholeYen(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, [$itemA, $itemB], $transaction] = $this->order([[1, 30000], [1, 20000]], 0, 'JPY');

        // Cut ¥99 at 60/40: ¥59.4 and ¥39.6, the leftover yen goes to the larger remainder.
        [$status, $body] = $this->refund($I, $order, $transaction, 40100, [[(int) $itemA->id, 1], [(int) $itemB->id, 1]]);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(24100, (int) $this->column('fct_order_items', 'refund_total', (int) $itemA->id));
        Assert::assertSame(16000, (int) $this->column('fct_order_items', 'refund_total', (int) $itemB->id));
    }

    public function gatewayIsAskedForThePostedAmount(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, [$itemA], $transaction] = $this->order([[1, 6000]]);
        $sent = null;
        FakeRefundGateway::$beforeReturn = function ($charge, $amount) use (&$sent) {
            $sent = $amount;
        };

        [$status, $body] = $this->refund($I, $order, $transaction, 5000, [[(int) $itemA->id, 1]]);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(1, FakeRefundGateway::$refundCalls);
        Assert::assertSame(5000, (int) $sent);
    }

    public function forcedLocalRefundRecordsThePostedAmount(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, [$itemA], $transaction] = $this->order([[1, 6000]]);
        FakeRefundGateway::$nextResult = new \WP_Error('charge_disputed', 'Charge is disputed.');

        [$status, $body] = $this->refund($I, $order, $transaction, 5000, [[(int) $itemA->id, 1]]);
        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(5000, (int) $body['impact']['amount']);

        [$status, $body] = $this->refund($I, $order, $transaction, 5000, [[(int) $itemA->id, 1]], 0, ['force_local' => 'true']);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame('forced_local', $this->refundRows((int) $order->id)[0]->meta['refund_route']);
        Assert::assertSame(5000, (int) $this->refundRows((int) $order->id)[0]->total);
        Assert::assertSame(5000, (int) $this->column('fct_order_items', 'refund_total', (int) $itemA->id));
    }

    public function lowerAmountRefundsTheSameUnits(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, [$itemB], $transaction] = $this->order([[2, 4000]]);

        [$status, $body] = $this->refund($I, $order, $transaction, 1500, [[(int) $itemB->id, 1]]);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        $lineMeta = json_decode($this->column('fct_order_items', 'line_meta', (int) $itemB->id), true);
        Assert::assertSame(1, (int) ($lineMeta['refunded_quantity'] ?? 0));
        Assert::assertSame(2, (int) $this->column('fct_order_items', 'quantity', (int) $itemB->id));

        // The last unit is priced from what is left on the line, so it includes the 500 kept.
        FakeRefundGateway::$nextResult = 're_fake_2';
        [$status, $body] = $this->refund($I, $order, $transaction, 0, [[(int) $itemB->id, 1]]);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(2500, (int) $this->refundRows((int) $order->id)[1]->total);
        Assert::assertSame(Status::PAYMENT_REFUNDED, $this->column('fct_orders', 'payment_status', (int) $order->id));
    }

    public function moneyLeftOnALineWithNoUnitsLeftIsRefundable(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, [$itemA], $transaction] = $this->order([[1, 800]], 2000);

        // 800 item + 200 shipping lowered to 500: the item gets 300 and its only unit counts as refunded.
        [$status, $body] = $this->refund($I, $order, $transaction, 500, [[(int) $itemA->id, 1]], 200);
        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));

        FakeRefundGateway::$nextResult = 're_fake_2';
        [$status, $body] = $this->refund($I, $order, $transaction, 0, [[(int) $itemA->id, 0]]);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(500, (int) $this->refundRows((int) $order->id)[1]->total);
        Assert::assertSame(800, (int) $this->column('fct_order_items', 'refund_total', (int) $itemA->id));
        $lineMeta = json_decode($this->column('fct_order_items', 'line_meta', (int) $itemA->id), true);
        Assert::assertSame(1, (int) ($lineMeta['refunded_quantity'] ?? 0));
        Assert::assertSame(1000, (int) $this->column('fct_orders', 'total_refund', (int) $order->id));
    }

    public function negativeQtyOnASpentLineKeepsItsRefundedUnits(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, [$itemA], $transaction] = $this->order([[1, 800]]);
        $this->refund($I, $order, $transaction, 500, [[(int) $itemA->id, 1]]);

        FakeRefundGateway::$nextResult = 're_fake_2';
        [$status, $body] = $this->refund($I, $order, $transaction, 0, [[(int) $itemA->id, -1]]);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(800, (int) $this->column('fct_order_items', 'refund_total', (int) $itemA->id));
        $lineMeta = json_decode($this->column('fct_order_items', 'line_meta', (int) $itemA->id), true);
        Assert::assertSame(1, (int) ($lineMeta['refunded_quantity'] ?? 0));
    }

    public function unitsPostedOnASpentLineAreNotRefundedAgain(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, [$itemA], $transaction] = $this->order([[1, 800]]);
        $this->refund($I, $order, $transaction, 500, [[(int) $itemA->id, 1]]);

        // A modal opened before the first refund still posts the unit.
        FakeRefundGateway::$nextResult = 're_fake_2';
        [$status, $body] = $this->refund($I, $order, $transaction, 0, [[(int) $itemA->id, 1]]);

        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(500, (int) $this->column('fct_order_items', 'refund_total', (int) $itemA->id));
        Assert::assertSame(500, (int) $this->column('fct_orders', 'total_refund', (int) $order->id));
    }

    public function signupFeeWithMoneyLeftRefundsTheRestWhenAnOldModalPostsOne(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, [$fee], $transaction] = $this->order([[1, 800]]);
        OrderItem::query()->where('id', $fee->id)->update(['payment_type' => 'signup_fee']);
        [$firstStatus] = $this->refund($I, $order, $transaction, 500, [[(int) $fee->id, 1]]);
        Assert::assertSame(200, $firstStatus);

        FakeRefundGateway::$nextResult = 're_fake_2';
        [$status, $body] = $this->refund($I, $order, $transaction, 0, [[(int) $fee->id, 1]]);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame(800, (int) $this->column('fct_order_items', 'refund_total', (int) $fee->id));
        $lineMeta = json_decode($this->column('fct_order_items', 'line_meta', (int) $fee->id), true);
        Assert::assertSame(1, (int) ($lineMeta['refunded_quantity'] ?? 0));
    }

    /**
     * @param array $lines [[quantity, line_total], ...]
     */
    private function order(array $lines, int $shipping = 0, string $currency = 'USD'): array
    {
        $subtotal = array_sum(array_column($lines, 1));
        $total = $subtotal + $shipping;
        $order = (new OrderFactory())->create([
            'customer_id'    => (new CustomerFactory())->create()->id,
            'currency'       => $currency,
            'status'         => Status::ORDER_PROCESSING,
            'payment_status' => Status::PAYMENT_PAID,
            'payment_method' => FakeRefundGateway::SLUG,
            'subtotal'       => $subtotal,
            'shipping_total' => $shipping,
            'total_amount'   => $total,
            'total_paid'     => $total,
        ]);

        $items = [];
        foreach ($lines as [$quantity, $lineTotal]) {
            $items[] = (new OrderItemFactory())->create((int) $order->id, [
                'quantity'   => $quantity,
                'unit_price' => intdiv($lineTotal, $quantity),
                'subtotal'   => $lineTotal,
                'line_total' => $lineTotal,
            ]);
        }

        $transaction = (new OrderTransactionFactory())->create((int) $order->id, [
            'currency'         => $currency,
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => $total,
            'payment_method'   => FakeRefundGateway::SLUG,
            'vendor_charge_id' => 'pi_fake_1',
        ]);

        return [$order, $items, $transaction];
    }

    private function refund(FunctionalTester $I, $order, $transaction, $amount, array $items, int $shipping = 0, array $extra = []): array
    {
        return $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/refund', [
            'refund_info'    => array_merge([
                'transaction_id'     => (int) $transaction->id,
                'amount'             => $amount,
                'shipping_amount'    => $shipping,
                'manageStock'        => 'false',
                'cancelSubscription' => 'false',
            ], $extra),
            'refunded_items' => array_map(function ($item) {
                return ['id' => $item[0], 'refund_qty' => $item[1]];
            }, $items),
        ]);
    }

    private function column(string $table, string $column, int $id): string
    {
        global $wpdb;
        return (string) $wpdb->get_var(
            $wpdb->prepare("SELECT {$column} FROM {$wpdb->prefix}{$table} WHERE id = %d", $id)
        );
    }
}

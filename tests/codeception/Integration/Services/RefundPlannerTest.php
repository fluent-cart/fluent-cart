<?php

namespace Tests\Integration\Services;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Services\Payments\RefundPlanner;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;

class RefundPlannerTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private OrderItemFactory $itemFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
        $this->itemFactory = new OrderItemFactory();
    }

    public function test_item_refund_of_a_trial_order_prices_the_trial_line_at_zero(): void
    {
        [$order, $trial, $signupFee] = $this->trialOrderWithSignupFee();

        $plan = RefundPlanner::plan($order, ['transaction_id' => 1], [
            ['id' => $trial->id, 'refund_qty' => 1],
            ['id' => $signupFee->id, 'refund_qty' => 1],
        ]);

        $this->assertSame(2750, $plan['refund_amount']);
        $this->assertSame([$trial->id => 0, $signupFee->id => 2750], array_column($plan['item_refunds'], 'refund_amount', 'id'));
    }

    public function test_item_refund_of_only_the_trial_line_refunds_nothing(): void
    {
        [$order, $trial] = $this->trialOrderWithSignupFee();

        $plan = RefundPlanner::plan($order, ['transaction_id' => 1], [
            ['id' => $trial->id, 'refund_qty' => 1],
        ]);

        $this->assertSame(0, $plan['refund_amount']);
    }

    public function test_amount_refund_rejects_a_fractional_cent(): void
    {
        [$order] = $this->trialOrderWithSignupFee();

        $plan = RefundPlanner::plan($order, ['transaction_id' => 1, 'amount' => '100.5'], []);

        $this->assertWPError($plan);
        $this->assertSame('invalid_refund_precision', $plan->get_error_code());
        $this->assertSame(['status' => 422], $plan->get_error_data());
    }

    public function test_amount_refund_accepts_whole_cents(): void
    {
        [$order] = $this->trialOrderWithSignupFee();

        $plan = RefundPlanner::plan($order, ['transaction_id' => 1, 'amount' => '100'], []);

        $this->assertSame(100, $plan['refund_amount']);
    }

    public function test_amount_refund_of_a_zero_decimal_currency_needs_whole_units(): void
    {
        $order = $this->orderFactory->create([
            'customer_id'    => $this->customerFactory->create()->id,
            'currency'       => 'JPY',
            'total_amount'   => 50000,
            'total_paid'     => 50000,
            'payment_status' => Status::PAYMENT_PAID,
        ]);

        $rejected = RefundPlanner::plan($order, ['transaction_id' => 1, 'amount' => '150'], []);
        $this->assertWPError($rejected);
        $this->assertSame('invalid_refund_precision', $rejected->get_error_code());

        $plan = RefundPlanner::plan($order, ['transaction_id' => 1, 'amount' => '200'], []);
        $this->assertSame(200, $plan['refund_amount']);
    }

    public function test_shipped_qty_outside_the_shipped_and_unshipped_units_is_rejected(): void
    {
        [$order, $item] = $this->paidOrderWithPhysicalItem(3, 2);

        foreach ([0, 3] as $shippedQty) {
            $plan = RefundPlanner::plan($order, ['transaction_id' => 1], [
                ['id' => $item->id, 'refund_qty' => 2, 'shipped_qty' => $shippedQty],
            ]);

            $this->assertWPError($plan, "shipped_qty {$shippedQty}");
            $this->assertSame('invalid_shipped_qty', $plan->get_error_code());
            $this->assertSame(['status' => 422], $plan->get_error_data());
        }
    }

    public function test_shipped_qty_defaults_to_the_fewest_shipped_units(): void
    {
        [$order, $item] = $this->paidOrderWithPhysicalItem(3, 2);

        $one = RefundPlanner::plan($order, ['transaction_id' => 1], [['id' => $item->id, 'refund_qty' => 1]]);
        $two = RefundPlanner::plan($order, ['transaction_id' => 1], [['id' => $item->id, 'refund_qty' => 2]]);

        $this->assertSame(0, $one['item_refunds'][0]['shipped_qty']);
        $this->assertSame(1, $two['item_refunds'][0]['shipped_qty']);
        $this->assertSame($two['item_refunds'], $two['refund_intent']['items']);
    }

    public function test_shipped_qty_of_a_repeated_item_is_summed(): void
    {
        [$order, $item] = $this->paidOrderWithPhysicalItem(3, 2);

        $plan = RefundPlanner::plan($order, ['transaction_id' => 1], [
            ['id' => $item->id, 'refund_qty' => 1, 'shipped_qty' => 1],
            ['id' => $item->id, 'refund_qty' => 1, 'shipped_qty' => 1],
        ]);

        $this->assertSame(2, $plan['item_refunds'][0]['shipped_qty']);
    }

    public function test_shipped_qty_is_ignored_on_digital_and_child_lines(): void
    {
        $order = $this->paidOrder();
        $digital = $this->itemFactory->create($order->id, ['quantity' => 1, 'fulfilled_quantity' => 1]);
        $child = $this->itemFactory->create($order->id, [
            'fulfillment_type' => 'physical',
            'line_meta'        => ['parent_item_id' => $digital->id],
        ]);

        $plan = RefundPlanner::plan($order, ['transaction_id' => 1], [
            ['id' => $digital->id, 'refund_qty' => 1, 'shipped_qty' => 5],
            ['id' => $child->id, 'refund_qty' => 1, 'shipped_qty' => 5],
        ]);

        $this->assertIsArray($plan);
        $this->assertArrayNotHasKey('shipped_qty', $plan['item_refunds'][0]);
        $this->assertArrayNotHasKey('shipped_qty', $plan['item_refunds'][1]);
    }

    public function test_line_shipped_beyond_its_units_left_still_refunds(): void
    {
        [$order, $item] = $this->paidOrderWithPhysicalItem(2, 2, ['refunded_quantity' => 1]);

        $plan = RefundPlanner::plan($order, ['transaction_id' => 1], [['id' => $item->id, 'refund_qty' => 1]]);

        $this->assertIsArray($plan);
        $this->assertSame(1, $plan['item_refunds'][0]['shipped_qty']);
    }

    private function paidOrder(): Order
    {
        return $this->orderFactory->create([
            'customer_id'    => $this->customerFactory->create()->id,
            'total_amount'   => 30000,
            'total_paid'     => 30000,
            'payment_status' => Status::PAYMENT_PAID,
        ]);
    }

    private function paidOrderWithPhysicalItem(int $qty, int $fulfilled, array $lineMeta = []): array
    {
        $order = $this->paidOrder();
        $item = $this->itemFactory->create($order->id, [
            'fulfillment_type'   => 'physical',
            'quantity'           => $qty,
            'fulfilled_quantity' => $fulfilled,
            'line_total'         => 10000 * $qty,
            'line_meta'          => $lineMeta,
        ]);

        return [Order::query()->find($order->id), OrderItem::query()->find($item->id)];
    }

    private function trialOrderWithSignupFee(): array
    {
        $order = $this->orderFactory->create([
            'customer_id'    => $this->customerFactory->create()->id,
            'type'           => Status::ORDER_TYPE_SUBSCRIPTION,
            'tax_behavior'   => 1,
            'total_amount'   => 2750,
            'total_paid'     => 2750,
            'payment_status' => Status::PAYMENT_PAID,
        ]);
        $trial = $this->itemFactory->create($order->id, [
            'payment_type' => 'subscription',
            'line_total'   => 4000,
            'other_info'   => ['payment_type' => 'subscription', 'trial_days' => 7],
        ]);
        $signupFee = $this->itemFactory->create($order->id, [
            'payment_type' => 'signup_fee',
            'line_total'   => 2500,
            'tax_amount'   => 250,
            'line_meta'    => ['parent_item_id' => $trial->id, 'tax_config' => ['inclusive' => false]],
            'other_info'   => ['payment_type' => 'signup_fee'],
        ]);

        return [Order::query()->find($order->id), OrderItem::query()->find($trial->id), OrderItem::query()->find($signupFee->id)];
    }
}

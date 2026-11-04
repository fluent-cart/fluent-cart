<?php

namespace Tests\Integration\Services;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\PlanUpgradeService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * The order an upgrade's prorate credit (and its currency) is computed
 * from: a subscription's latest PAID or partially refunded renewal, else
 * the order itself. Core's portal and Pro's upgrade link both use it.
 */
class PlanUpgradeSourceOrderTest extends DatabaseTestCase
{
    public function test_the_latest_paid_renewal_wins_over_a_later_unpaid_one(): void
    {
        $parent = $this->order(Status::ORDER_TYPE_SUBSCRIPTION, Status::PAYMENT_PAID);
        $this->order(Status::ORDER_TYPE_RENEWAL, Status::PAYMENT_PAID, $parent->id);
        $refunded = $this->order(Status::ORDER_TYPE_RENEWAL, Status::PAYMENT_PARTIALLY_REFUNDED, $parent->id);
        $this->order(Status::ORDER_TYPE_RENEWAL, Status::PAYMENT_PENDING, $parent->id);
        $this->order(Status::ORDER_TYPE_RENEWAL, Status::PAYMENT_FAILED, $parent->id);

        $this->assertSame((int) $refunded->id, (int) PlanUpgradeService::upgradeSourceOrder($parent)->id);
    }

    public function test_without_a_paid_renewal_the_parent_order_is_used(): void
    {
        $parent = $this->order(Status::ORDER_TYPE_SUBSCRIPTION, Status::PAYMENT_PAID);
        $this->order(Status::ORDER_TYPE_RENEWAL, Status::PAYMENT_PENDING, $parent->id);

        $this->assertSame((int) $parent->id, (int) PlanUpgradeService::upgradeSourceOrder($parent)->id);
    }

    public function test_a_one_time_order_is_its_own_source(): void
    {
        $order = $this->order(Status::ORDER_TYPE_PAYMENT, Status::PAYMENT_PAID);

        $this->assertSame((int) $order->id, (int) PlanUpgradeService::upgradeSourceOrder($order)->id);
    }

    private function order(string $type, string $paymentStatus, ?int $parentId = null): Order
    {
        $order = (new OrderFactory())->create([
            'customer_id'    => (new CustomerFactory())->create()->id,
            'type'           => $type,
            'parent_id'      => $parentId,
            'payment_status' => $paymentStatus,
        ]);

        return Order::query()->find($order->id);
    }
}

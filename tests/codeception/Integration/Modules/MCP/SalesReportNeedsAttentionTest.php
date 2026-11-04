<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\MCP\Tools\ReportTools;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\SubscriptionFactory;

/**
 * get-sales-report needs_attention lists what the admin Overview's Needs Attention card shows.
 */
class SalesReportNeedsAttentionTest extends DatabaseTestCase
{
    public function test_nothing_needs_attention_on_a_quiet_store(): void
    {
        Order::query()->delete();
        Subscription::query()->delete();

        $none = ReportTools::getSalesReport(['currency' => 'USD', 'needs_attention' => true])['data'];
        $this->assertSame([], $none['needs_attention']);
        $this->assertArrayNotHasKey('needs_attention', ReportTools::getSalesReport(['currency' => 'USD'])['data'], 'Only when asked: it reads four reports');
    }

    // A separate test: the health card is cached for a few minutes, as on the Overview
    public function test_failing_subscription_needs_attention(): void
    {
        Order::query()->delete();
        Subscription::query()->delete();

        $cid = (new CustomerFactory())->create()->id;
        $order = (new OrderFactory())->create([
            'customer_id' => $cid, 'type' => Status::ORDER_TYPE_SUBSCRIPTION, 'currency' => 'USD',
            'payment_status' => Status::PAYMENT_PAID, 'total_amount' => 2999, 'total_paid' => 2999,
        ]);
        (new SubscriptionFactory())->create([
            'customer_id' => $cid, 'parent_order_id' => $order->id, 'currency' => 'USD',
            'product_id' => 9201, 'variation_id' => 92010, 'status' => Status::SUBSCRIPTION_FAILING,
        ]);

        $items = ReportTools::getSalesReport(['currency' => 'USD', 'needs_attention' => true])['data']['needs_attention'];
        $this->assertSame(['failing'], array_column($items, 'key'));
        $this->assertSame(1, $items[0]['count']);
    }
}

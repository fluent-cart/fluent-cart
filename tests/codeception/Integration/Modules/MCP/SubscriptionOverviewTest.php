<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\MCP\Tools\ReportTools;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\SubscriptionReportService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\SubscriptionFactory;

/**
 * query-subscriptions view=overview gives the admin Subscriptions page: health now, and the period's movement.
 */
class SubscriptionOverviewTest extends DatabaseTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        Order::query()->delete();
        Subscription::query()->delete();
        $cid = (new CustomerFactory())->create()->id;
        foreach ([[Status::SUBSCRIPTION_ACTIVE, null], [Status::SUBSCRIPTION_CANCELED, '2025-09-20 10:00:00']] as $i => $sub) {
            $order = (new OrderFactory())->create([
                'customer_id' => $cid, 'type' => Status::ORDER_TYPE_SUBSCRIPTION, 'currency' => 'USD',
                'payment_status' => Status::PAYMENT_PAID, 'total_amount' => 2999, 'total_paid' => 2999,
                'created_at' => '2025-09-0' . ($i + 1) . ' 10:00:00',
            ]);
            (new SubscriptionFactory())->create([
                'customer_id' => $cid, 'parent_order_id' => $order->id, 'currency' => 'USD',
                'product_id' => 9101, 'variation_id' => 91010,
                'status' => $sub[0], 'canceled_at' => $sub[1],
                'created_at' => '2025-09-0' . ($i + 1) . ' 10:00:00',
            ]);
        }
    }

    public function tearDown(): void
    {
        remove_all_filters('fluent_cart/report/mrr_breakdown');
        parent::tearDown();
    }

    public function test_overview_equals_the_subscriptions_page(): void
    {
        $params = [
            'startDate' => new DateTime('2025-09-01 00:00:00', new \DateTimeZone('UTC')),
            'endDate'   => new DateTime('2025-09-30 23:59:59', new \DateTimeZone('UTC')),
            'currency'  => 'USD',
            'groupKey'  => 'monthly',
        ];
        $service = SubscriptionReportService::make();
        $period = $service->getOverview($params, false)['period'];
        $health = $service->getHealth($params);

        $res = ReportTools::querySubscriptions(['view' => 'overview', 'currency' => 'USD', 'start_date' => '2025-09-01', 'end_date' => '2025-09-30'])['data'];

        $this->assertSame($period, $res['period']);
        $this->assertSame($health['mrr'], $res['health']['mrr']);
        $this->assertSame($health['subscriptions'], $res['health']['subscriptions']);
        $this->assertArrayNotHasKey('mrr_by_plan', $res, 'MRR by plan is a Pro report');

        add_filter('fluent_cart/report/mrr_breakdown', '__return_true');
        $pro = ReportTools::querySubscriptions(['view' => 'overview', 'currency' => 'USD', 'start_date' => '2025-09-01', 'end_date' => '2025-09-30'])['data'];
        $this->assertArrayHasKey('mrr_by_plan', $pro);
    }
}

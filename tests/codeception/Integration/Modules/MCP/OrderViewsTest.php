<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Modules\MCP\Tools\ReportTools;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\CouponReportService;
use FluentCart\App\Services\Report\PaymentMethodReportService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * query-orders view=payment_methods and view=coupons give the admin Payment Methods and Coupons reports.
 */
class OrderViewsTest extends DatabaseTestCase
{
    private function params(): array
    {
        return [
            'startDate'     => new DateTime('2025-10-01 00:00:00', new \DateTimeZone('UTC')),
            'endDate'       => new DateTime('2025-10-31 23:59:59', new \DateTimeZone('UTC')),
            'currency'      => 'USD',
            'paymentStatus' => Status::getReportStatuses(),
            'groupKey'      => 'monthly',
        ];
    }

    private function args(string $view): array
    {
        return ['view' => $view, 'currency' => 'USD', 'start_date' => '2025-10-01', 'end_date' => '2025-10-31'];
    }

    public function setUp(): void
    {
        parent::setUp();
        Order::query()->delete();
        $cid = (new CustomerFactory())->create()->id;
        foreach (['stripe', 'paypal', 'stripe'] as $i => $method) {
            (new OrderFactory())->create([
                'customer_id' => $cid, 'type' => Status::ORDER_TYPE_PAYMENT, 'currency' => 'USD', 'payment_method' => $method,
                'payment_status' => Status::PAYMENT_PAID, 'total_amount' => 4000, 'total_paid' => 4000,
                'created_at' => '2025-10-0' . ($i + 1) . ' 10:00:00',
            ]);
        }
    }

    public function tearDown(): void
    {
        remove_all_filters('fluent_cart/report/coupon_quality');
        parent::tearDown();
    }

    public function test_payment_methods_equal_the_admin_report(): void
    {
        $admin = PaymentMethodReportService::make()->getReport($this->params());
        $res = ReportTools::queryOrders($this->args('payment_methods'))['data'];

        $this->assertSame($admin['rows'], $res['rows']);
        $this->assertSame($admin['totals'], $res['totals']);
    }

    public function test_coupons_equal_the_admin_report_without_pro_fields(): void
    {
        $admin = CouponReportService::make()->getReport(['withQuality' => false] + $this->params());
        $res = ReportTools::queryOrders($this->args('coupons'))['data'];

        $this->assertSame(count($admin['rows']), count($res['rows']));
        $this->assertArrayNotHasKey('won_ltv', $res['totals'], 'Coupon quality is a Pro report');
    }

    public function test_payment_methods_say_they_count_every_mode(): void
    {
        // Its declines and failing subscriptions have no order mode, so the whole report stays on both modes
        $res = ReportTools::queryOrders($this->args('payment_methods') + ['mode' => 'test']);
        $this->assertSame(3, (int) array_sum(array_column($res['data']['rows'], 'orders')));
        $this->assertSame('all', $res['meta']['mode']);
    }

    public function test_coupons_follow_the_mode(): void
    {
        $this->assertSame('test', ReportTools::queryOrders($this->args('coupons') + ['mode' => 'test'])['meta']['mode']);
    }
}

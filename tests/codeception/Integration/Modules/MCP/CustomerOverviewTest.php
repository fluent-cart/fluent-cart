<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Modules\MCP\Tools\ReportTools;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\CustomerReportService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * query-customers view=overview gives the admin Customers overview for a date range.
 */
class CustomerOverviewTest extends DatabaseTestCase
{
    public function test_overview_equals_the_customers_page(): void
    {
        Order::query()->delete();
        $returning = (new CustomerFactory())->create()->id;
        $new = (new CustomerFactory())->create()->id;
        $paid = ['type' => Status::ORDER_TYPE_PAYMENT, 'currency' => 'USD', 'payment_status' => Status::PAYMENT_PAID, 'total_amount' => 5000, 'total_paid' => 5000];
        (new OrderFactory())->create($paid + ['customer_id' => $returning, 'created_at' => '2025-06-01 10:00:00']);
        (new OrderFactory())->create($paid + ['customer_id' => $returning, 'created_at' => '2025-10-05 10:00:00']);
        (new OrderFactory())->create($paid + ['customer_id' => $new, 'created_at' => '2025-10-06 10:00:00']);

        $admin = CustomerReportService::make()->getOverview([
            'startDate'     => new DateTime('2025-10-01 00:00:00', new \DateTimeZone('UTC')),
            'endDate'       => new DateTime('2025-10-31 23:59:59', new \DateTimeZone('UTC')),
            'currency'      => 'USD',
            'paymentStatus' => Status::getReportStatuses(),
            'groupKey'      => 'monthly',
        ])['summary'];

        $res = ReportTools::queryCustomers(['view' => 'overview', 'currency' => 'USD', 'start_date' => '2025-10-01', 'end_date' => '2025-10-31'])['data'];

        // Same figures as the page, money in currency units instead of the page's cents
        foreach (['new_net', 'returning_net', 'renewal_net', 'net', 'average_ltv'] as $key) {
            $admin[$key] = Helper::toDecimalWithoutComma($admin[$key]);
        }
        $this->assertEquals($admin, $res['summary']);
        $this->assertSame(1, $res['summary']['new_customers']);
        $this->assertSame(1, $res['summary']['returning_customers']);
    }
}

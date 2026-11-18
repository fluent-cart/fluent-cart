<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Models\Order;
use FluentCart\App\Modules\MCP\Tools\OrderTools;
use FluentCart\App\Modules\MCP\Tools\PaymentScheduleTools;
use FluentCart\App\Modules\MCP\Tools\ReportTools;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Traits\SeedsDatedPaidOrders;

/**
 * A date with no offset in a list filter is a store day, as in the report windows:
 * "orders on 2026-11-10" must be the same orders in list-orders and in a report.
 * A value with an offset or Z stays the exact moment.
 */
class ListFilterStoreDayTest extends DatabaseTestCase
{
    use SeedsDatedPaidOrders;

    private $originalTimezone;

    public function setUp(): void
    {
        parent::setUp();
        $this->originalTimezone = get_option('timezone_string');
        update_option('timezone_string', 'Asia/Dhaka'); // UTC+6

        Order::query()->delete();
        $cid = (new CustomerFactory())->create()->id;
        $this->createPaidOrderAt($cid, '2026-11-09 17:00:00'); // Nov 9, 23:00 in the store
        $this->createPaidOrderAt($cid, '2026-11-09 19:00:00'); // Nov 10, 01:00 in the store
        $this->createPaidOrderAt($cid, '2026-11-10 17:30:00'); // Nov 10, 23:30 in the store
        $this->createPaidOrderAt($cid, '2026-11-10 19:00:00'); // Nov 11, 01:00 in the store
    }

    public function tearDown(): void
    {
        update_option('timezone_string', $this->originalTimezone);
        parent::tearDown();
    }

    public function test_a_bare_day_is_the_store_day_and_matches_the_report(): void
    {
        $listed = OrderTools::listOrders(['created_after' => '2026-11-10', 'created_before' => '2026-11-10']);
        $report = ReportTools::queryOrders(['start_date' => '2026-11-10', 'end_date' => '2026-11-10']);

        $this->assertSame(2, $listed['meta']['page']['total'], 'The two orders placed on the store\'s Nov 10');
        $this->assertSame(2, (int) $report['data']['rows'][0]['order_count']);
    }

    public function test_a_bare_time_is_store_time(): void
    {
        // 00:30 in the store on Nov 10 is 18:30 UTC on Nov 9
        $listed = OrderTools::listOrders(['created_after' => '2026-11-10 00:30:00', 'created_before' => '2026-11-10 23:00:00']);

        $this->assertSame(1, $listed['meta']['page']['total']);
    }

    public function test_a_value_with_an_offset_is_the_exact_moment(): void
    {
        $listed = OrderTools::listOrders(['created_after' => '2026-11-09T18:00:00Z', 'created_before' => '2026-11-10T18:00:00+00:00']);

        $this->assertSame(2, $listed['meta']['page']['total']);
    }

    public function test_a_word_is_not_a_date(): void
    {
        $res = OrderTools::listOrders(['created_after' => 'yesterday']);

        $this->assertInstanceOf(\WP_Error::class, $res);
        $this->assertSame('invalid_param', $res->get_error_code());
        $this->assertSame(['created_after'], $res->get_error_data()['fields']);
    }

    public function test_upcoming_payments_reads_a_bare_day_as_a_store_day(): void
    {
        $res = PaymentScheduleTools::getUpcomingPayments(['date_from' => '2026-11-10', 'date_to' => '2026-11-10']);

        $this->assertSame('2026-11-09T18:00:00+00:00', $res['data']['window']['from']);
        $this->assertSame('2026-11-10T17:59:59+00:00', $res['data']['window']['to']);
    }
}

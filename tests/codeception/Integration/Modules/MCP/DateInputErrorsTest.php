<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Models\Coupon;
use FluentCart\App\Modules\MCP\Tools\CouponTools;
use FluentCart\App\Modules\MCP\Tools\CustomerTools;
use FluentCart\App\Modules\MCP\Tools\OrderTools;
use FluentCart\App\Modules\MCP\Tools\ProductFinancialsTools;
use FluentCart\App\Modules\MCP\Tools\ReportTools;
use FluentCart\App\Modules\MCP\Tools\SubscriptionTools;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * A date the tool cannot read, or a pair of bounds in the wrong order, is an error.
 * An agent reads a default window or an empty list as a real answer.
 */
class DateInputErrorsTest extends DatabaseTestCase
{
    /**
     * @dataProvider unreadableWindows
     */
    public function test_an_unreadable_report_date_is_an_error_not_the_default_window(array $params, string $field): void
    {
        $res = ReportTools::getSalesReport($params);

        $this->assertInstanceOf(\WP_Error::class, $res);
        $this->assertSame('invalid_param', $res->get_error_code());
        $this->assertSame([$field], $res->get_error_data()['fields']);
    }

    public function unreadableWindows(): array
    {
        return [
            'start_date'            => [['start_date' => 'x', 'end_date' => '2026-05-10'], 'start_date'],
            'end_date'              => [['start_date' => '2026-05-01', 'end_date' => 'y'], 'end_date'],
            'date_from'             => [['date_from' => 'garbage'], 'date_from'],
            'date_to'               => [['date_from' => '2026-05-01', 'date_to' => 'garbage'], 'date_to'],
            'since'                 => [['since' => 'garbage'], 'since'],
            'a day that never was'  => [['start_date' => '2026-02-31', 'end_date' => '2026-03-10'], 'start_date'],
        ];
    }

    public function test_readable_report_dates_still_resolve(): void
    {
        $res = ReportTools::getSalesReport(['date_from' => '2026-05-01T10:00:00+06:00', 'date_to' => '2026-05-10']);
        $this->assertIsArray($res);
        $this->assertSame('2026-05-01T04:00:00+00:00', $res['data']['range']['start']);

        $res = ReportTools::getSalesReport(['start_date' => '2026-05-01', 'end_date' => '2026-05-10']);
        $this->assertIsArray($res);
    }

    public function test_an_unreadable_installments_as_of_is_an_error_not_today(): void
    {
        $res = ReportTools::getReport(['report' => 'installments', 'as_of' => 'garbage']);

        $this->assertInstanceOf(\WP_Error::class, $res);
        $this->assertSame('invalid_param', $res->get_error_code());
        $this->assertSame(['as_of'], $res->get_error_data()['fields']);
    }

    /**
     * @dataProvider reversedBounds
     */
    public function test_reversed_after_and_before_bounds_are_an_error_not_an_empty_list(string $tool, string $after, string $before): void
    {
        $tools = [
            'orders'        => [OrderTools::class, 'listOrders'],
            'subscriptions' => [SubscriptionTools::class, 'listSubscriptions'],
            'customers'     => [CustomerTools::class, 'listCustomers'],
        ];

        $res = call_user_func($tools[$tool], [$after => '2026-11-10', $before => '2026-11-01']);

        $this->assertInstanceOf(\WP_Error::class, $res);
        $this->assertSame('invalid_param', $res->get_error_code());
        $this->assertSame([$after, $before], $res->get_error_data()['fields']);
    }

    public function reversedBounds(): array
    {
        return [
            ['orders', 'created_after', 'created_before'],
            ['subscriptions', 'next_billing_after', 'next_billing_before'],
            ['subscriptions', 'created_after', 'created_before'],
            ['subscriptions', 'canceled_after', 'canceled_before'],
            ['customers', 'last_purchase_after', 'last_purchase_before'],
        ];
    }

    public function test_a_time_and_a_bare_day_on_the_same_day_are_in_order(): void
    {
        // A bare end day runs to 23:59:59, so noon to "that day" is a real window
        $this->assertIsArray(OrderTools::listOrders(['created_after' => '2026-03-05T12:00:00', 'created_before' => '2026-03-05']));
        $this->assertIsArray(OrderTools::listOrders(['created_after' => '2026-03-05T20:00:00-08:00', 'created_before' => '2026-03-06']));

        $res = OrderTools::listOrders(['created_after' => '2026-03-06T00:30:00', 'created_before' => '2026-03-05']);
        $this->assertInstanceOf(\WP_Error::class, $res, 'After the end of the bare day is still reversed');
    }

    /**
     * @dataProvider unreadableFinancialDates
     */
    public function test_an_unreadable_product_financials_date_is_an_error(array $params, string $field): void
    {
        $product = (new ProductFactory())->create();

        $res = ProductFinancialsTools::getProductFinancials(['product_id' => $product->ID] + $params);

        $this->assertInstanceOf(\WP_Error::class, $res);
        $this->assertSame('invalid_param', $res->get_error_code());
        $this->assertSame([$field], $res->get_error_data()['fields']);
    }

    public function unreadableFinancialDates(): array
    {
        return [
            'as_of'                => [['as_of' => 'garbage'], 'as_of'],
            'a day that never was' => [['date_from' => '2026-02-31'], 'date_from'],
            'date_to'              => [['date_to' => 'garbage'], 'date_to'],
        ];
    }

    public function test_coupon_dates_are_compared_in_utc_as_they_are_stored(): void
    {
        $original = get_option('timezone_string');
        update_option('timezone_string', 'America/New_York');

        // 12:00 UTC to 14:00 UTC is a two-hour window; read in New York time the start is 17:00 UTC
        $res = CouponTools::manageCoupon([
            'action'     => 'create',
            'code'       => 'UTCWINDOW',
            'title'      => 'UTC window',
            'type'       => 'fixed',
            'amount'     => 5,
            'start_date' => '2027-01-01T12:00:00',
            'end_date'   => '2027-01-01T14:00:00Z',
        ]);
        $reversed = CouponTools::manageCoupon([
            'action'     => 'create',
            'code'       => 'UTCREVERSED',
            'title'      => 'Reversed',
            'type'       => 'fixed',
            'amount'     => 5,
            'start_date' => '2027-01-02',
            'end_date'   => '2027-01-01',
        ]);

        update_option('timezone_string', $original);

        $this->assertIsArray($res);
        $this->assertSame('2027-01-01 12:00:00', Coupon::query()->where('code', 'UTCWINDOW')->first()->start_date);
        $this->assertInstanceOf(\WP_Error::class, $reversed);
        $this->assertSame(['start_date', 'end_date'], $reversed->get_error_data()['fields']);
    }

    public function test_bounds_in_order_or_on_the_same_day_still_list(): void
    {
        $this->assertIsArray(OrderTools::listOrders(['created_after' => '2026-11-01', 'created_before' => '2026-11-10']));
        $this->assertIsArray(OrderTools::listOrders(['created_after' => '2026-11-01', 'created_before' => '2026-11-01']));
    }
}

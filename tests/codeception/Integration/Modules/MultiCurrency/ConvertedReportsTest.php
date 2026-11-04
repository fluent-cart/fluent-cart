<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Http\Controllers\Reports\RevenueReportController;
use FluentCart\App\Http\Controllers\Reports\SubscriptionReportController;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySnapshot;
use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;
use FluentCart\App\Services\Report\DefaultReportService;
use FluentCart\App\Services\Report\ReportHelper;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\SubscriptionFactory;
use FluentCart\Framework\Http\Request\Request as FluentRequest;

/**
 * Tier B: every order converted to the base currency at its own frozen rate;
 * orders without a trustworthy rate are left out and counted.
 * dev-docs/multi-currency/plan.md §2.4, §2.5.5, §13. Base USD.
 */
class ConvertedReportsTest extends DatabaseTestCase
{
    /** A window no other test writes into */
    const START = '2002-06-01 00:00:00';
    const END = '2002-06-30 23:59:59';

    public function test_the_all_mode_is_only_accepted_where_a_report_supports_it(): void
    {
        $this->assertSame(ReportCurrency::ALL, ReportCurrency::resolve(ReportCurrency::ALL, true));
        $this->assertSame('USD', ReportCurrency::resolve(ReportCurrency::ALL), 'reports without a converted mode fall back to the base currency');
        // Opt-in: only a report that converts passes true
        $this->assertSame(ReportCurrency::ALL, ReportHelper::processParams(['currency' => ReportCurrency::ALL], [], true)['currency']);
        $this->assertSame('USD', ReportHelper::processParams(['currency' => ReportCurrency::ALL], [], false)['currency'], 'a report without the converted mode reads the base currency');
        $this->assertSame('USD', ReportHelper::processParams(['currency' => ReportCurrency::ALL])['currency'], 'a caller passing nothing gets the base currency');
    }

    public function test_totals_convert_each_order_at_its_own_frozen_rate(): void
    {
        $customer = (new CustomerFactory())->create();
        $this->paidOrder($customer->id, 'USD', 1000, null);
        // £7.90 at 0.79 -> $10.00
        $this->paidOrder($customer->id, 'GBP', 790, CurrencySnapshot::make('GBP', '0.79', ['source' => CurrencySnapshot::SOURCE_MANUAL]));
        // ¥1,495 at 149.5 -> $10.00 (x100 storage for a zero-decimal currency)
        $this->paidOrder($customer->id, 'JPY', 149500, CurrencySnapshot::make('JPY', '149.5', ['source' => CurrencySnapshot::SOURCE_MANUAL]));
        // A EUR order with no trustworthy rate: never counted as 1 EUR = 1 USD
        $this->paidOrder($customer->id, 'EUR', 5000, null);

        $summary = $this->summary(ReportCurrency::ALL);

        $this->assertEquals(30.0, $summary['gross_sale'], '$10 + £7.90 + ¥1,495, each at its own rate');
        $this->assertSame(3, $summary['order_count'], 'the rateless EUR order is left out');
        $this->assertSame(1, ReportCurrency::excludedOrderCount(self::START, self::END));

        $this->assertEquals(10.0, $this->summary('USD')['gross_sale'], 'the scoped mode is unchanged');
    }

    public function test_line_items_convert_at_their_order_rate(): void
    {
        $customer = (new CustomerFactory())->create();
        $this->paidOrder($customer->id, 'GBP', 790, CurrencySnapshot::make('GBP', '0.79', ['source' => CurrencySnapshot::SOURCE_MANUAL]), 9001);

        $top = DefaultReportService::make([])->fetchTopSoldProducts($this->params(ReportCurrency::ALL));
        $rows = array_values(array_filter($top['topSoldProducts']->all(), function ($product) {
            return (int) $product['product_id'] === 9001;
        }));
        $row = $rows[0] ?? null;

        $this->assertNotNull($row);
        $this->assertEquals(10.0, $row['total_amount']);
    }

    /**
     * A report that supports the mode opts in on its controller: the request's
     * "All currencies" reaches the service and comes back converted.
     */
    public function test_a_supported_report_returns_converted_totals_through_its_controller(): void
    {
        $sales = function ($currency) {
            $result = (new RevenueReportController())->getRevenue($this->reportRequest($currency));

            return (array) $result['summary'];
        };
        $allBefore = $sales(ReportCurrency::ALL);
        $usdBefore = $sales('USD');

        $customer = (new CustomerFactory())->create();
        foreach ([['USD', 1000, null], ['GBP', 790, CurrencySnapshot::make('GBP', '0.79', ['source' => CurrencySnapshot::SOURCE_MANUAL])], ['EUR', 5000, null]] as [$currency, $total, $snapshot]) {
            $attributes = [
                'customer_id'    => $customer->id,
                'currency'       => $currency,
                'status'         => Status::ORDER_COMPLETED,
                'payment_status' => Status::PAYMENT_PAID,
                'subtotal'       => $total,
                'total_amount'   => $total,
                'total_paid'     => $total,
            ];
            if ($snapshot) {
                $attributes['config'] = ['fx' => $snapshot];
            }
            $order = (new OrderFactory())->create($attributes);
            (new OrderItemFactory())->create($order->id, ['unit_price' => $total, 'subtotal' => $total, 'line_total' => $total]);
        }

        $all = $sales(ReportCurrency::ALL);
        $usd = $sales('USD');

        $this->assertEqualsWithDelta(20.0, $all['gross_sale'] - $allBefore['gross_sale'], 0.001, '$10 + £7.90 at 0.79; the rateless EUR order left out');
        $this->assertSame(2, $all['order_count'] - $allBefore['order_count']);
        $this->assertEqualsWithDelta(10.0, $usd['gross_sale'] - $usdBefore['gross_sale'], 0.001, 'the scoped mode is unchanged');
    }

    /**
     * The Subscriptions overview converts every subscription at its own frozen
     * rate (plan §13, mc-12): "All currencies" counts each new subscription
     * with a trustworthy rate and leaves the rateless one out.
     */
    public function test_subscription_signups_convert_in_the_all_mode(): void
    {
        $signups = function ($currency) {
            return (int) (new SubscriptionReportController())->getOverview($this->reportRequest($currency))['period']['new'];
        };
        $signupsBefore = ['all' => $signups(ReportCurrency::ALL), 'usd' => $signups('USD')];

        $customer = (new CustomerFactory())->create();
        $this->subscriptionOrder($customer->id, 'USD', null);
        $this->subscriptionOrder($customer->id, 'GBP', CurrencySnapshot::make('GBP', '0.79', ['source' => CurrencySnapshot::SOURCE_MANUAL]));
        $this->subscriptionOrder($customer->id, 'EUR', null);

        $this->assertSame(2, $signups(ReportCurrency::ALL) - $signupsBefore['all'], 'USD + GBP signups; the rateless EUR one left out');
        $this->assertSame(1, $signups('USD') - $signupsBefore['usd'], 'the scoped mode is unchanged');
    }

    private function reportRequest(string $currency, array $extra = []): FluentRequest
    {
        return new FluentRequest(App::getInstance(), [
            'params' => array_merge([
                'startDate' => gmdate('Y-m-d', strtotime('-7 days')),
                'endDate'   => gmdate('Y-m-d', strtotime('+1 day')),
                'groupKey'  => 'daily',
                'currency'  => $currency,
            ], $extra),
        ], []);
    }

    private function subscriptionOrder(int $customerId, string $currency, ?array $snapshot): void
    {
        $attributes = [
            'customer_id'    => $customerId,
            'currency'       => $currency,
            'type'           => Status::ORDER_TYPE_SUBSCRIPTION,
            'status'         => Status::ORDER_COMPLETED,
            'payment_status' => Status::PAYMENT_PAID,
            'subtotal'       => 2999,
            'total_amount'   => 2999,
            'total_paid'     => 2999,
        ];
        if ($snapshot) {
            $attributes['config'] = ['fx' => $snapshot];
        }
        $order = (new OrderFactory())->create($attributes);

        (new SubscriptionFactory())->create([
            'customer_id'     => $customerId,
            'parent_order_id' => $order->id,
            'product_id'      => 9101,
            'variation_id'    => 9102,
            'currency'        => $currency,
            'bill_times'      => 0,
            'bill_count'      => 1,
        ]);
    }

    private function summary(string $currency): array
    {
        $result = DefaultReportService::make([])->getAllGraphMetricsSeparate($this->params($currency));

        return (array) $result['summary'];
    }

    private function params(string $currency): array
    {
        return [
            'startDate'     => self::START,
            'endDate'       => self::END,
            'groupKey'      => 'daily',
            'currency'      => $currency,
            'variationIds'  => [],
            'paymentStatus' => Status::getReportStatuses(),
        ];
    }

    private function paidOrder(int $customerId, string $currency, int $total, ?array $snapshot, int $productId = 0)
    {
        $attributes = [
            'customer_id'    => $customerId,
            'currency'       => $currency,
            'status'         => Status::ORDER_COMPLETED,
            'payment_status' => Status::PAYMENT_PAID,
            'subtotal'       => $total,
            'total_amount'   => $total,
            'total_paid'     => $total,
        ];
        if ($snapshot) {
            $attributes['config'] = ['fx' => $snapshot];
        }

        $order = (new OrderFactory())->create($attributes);
        (new OrderItemFactory())->create($order->id, array_filter([
            'unit_price' => $total,
            'subtotal'   => $total,
            'line_total' => $total,
            'post_id'    => $productId ?: null,
        ]));

        global $wpdb;
        $wpdb->update($wpdb->prefix . 'fct_orders', ['created_at' => '2002-06-10 10:00:00'], ['id' => $order->id]);
        $wpdb->update($wpdb->prefix . 'fct_order_items', ['created_at' => '2002-06-10 10:00:00'], ['order_id' => $order->id]);

        return $order;
    }
}

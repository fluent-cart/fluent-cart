<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Customer;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySnapshot;
use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;
use FluentCart\App\Services\Report\DefaultReportService;
use FluentCart\App\Services\Report\ReportHelper;
use FluentCart\App\Services\Report\RetentionSnapshotService;
use FluentCart\App\Services\Report\SubscriptionReportService;
use FluentCart\Framework\Support\DateTime;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\SubscriptionFactory;

/**
 * One-currency reports: every report is in exactly one currency.
 * dev-docs/multi-currency/07-reports.md. The test store's base is USD.
 */
class CurrencyScopedReportsTest extends DatabaseTestCase
{
    public function test_currency_list_has_every_currency_with_orders_most_orders_first(): void
    {
        // A date window no other test writes into, so the suite's other orders
        // cannot leak into the list
        $window = ['2001-03-01 00:00:00', '2001-03-31 23:59:59'];
        $customer = (new CustomerFactory())->create();
        $this->paidOrder($customer->id, 'EUR', 5000, null, '2001-03-05 10:00:00');
        $this->paidOrder($customer->id, 'EUR', 5000, null, '2001-03-06 10:00:00');
        $this->paidOrder($customer->id, 'usd', 1000, null, '2001-03-07 10:00:00'); // mixed case is the same currency
        $this->paidOrder($customer->id, 'USD', 1000, null, '2001-03-08 10:00:00');
        $this->paidOrder($customer->id, 'USD', 1000, null, '2001-03-09 10:00:00');

        $currencies = ReportCurrency::orderCurrencies($window[0], $window[1]);

        $this->assertSame(['USD', 'EUR'], array_column($currencies, 'currency'));
        $this->assertSame([3, 2], array_column($currencies, 'order_count'));
    }

    public function test_a_report_without_a_currency_is_scoped_to_the_base_currency_not_summed(): void
    {
        $params = ReportHelper::processParams(['startDate' => gmdate('Y-m-d', strtotime('-1 day')), 'endDate' => gmdate('Y-m-d')]);
        $this->assertSame('USD', $params['currency']);

        $params = ReportHelper::processParams(['currency' => 'eur']);
        $this->assertSame('EUR', $params['currency']);

        $this->assertSame('USD', ReportCurrency::resolve('<script>'), 'garbage never reaches SQL');
    }

    public function test_sales_totals_only_add_up_orders_in_the_selected_currency(): void
    {
        $customer = (new CustomerFactory())->create();
        $this->paidOrder($customer->id, 'USD', 1000);
        $this->paidOrder($customer->id, 'EUR', 5000);

        $usd = $this->salesSummary('USD');
        $eur = $this->salesSummary('EUR');

        $this->assertEquals(10.0, $usd['gross_sale']);
        $this->assertSame(1, $usd['order_count']);
        $this->assertEquals(50.0, $eur['gross_sale']);
        $this->assertSame(1, $eur['order_count']);
    }

    public function test_mrr_is_scoped_to_the_selected_currency(): void
    {
        $customer = (new CustomerFactory())->create();
        $this->activeMonthlySubscription($customer->id, 'USD', 1000);
        $this->activeMonthlySubscription($customer->id, 'EUR', 5000);

        $service = SubscriptionReportService::make();
        $range = [
            'startDate' => new \FluentCart\App\Services\DateTime\DateTime(gmdate('Y-m-01 00:00:00')),
            'endDate'   => new \FluentCart\App\Services\DateTime\DateTime(gmdate('Y-m-t 23:59:59')),
        ];

        $usd = $service->getRetentionData($range + ['currency' => 'USD', 'variationIds' => []]);
        $eur = $service->getRetentionData($range + ['currency' => 'EUR', 'variationIds' => []]);

        $this->assertEquals(10.0, end($usd)['mrr']);
        $this->assertEquals(50.0, end($eur)['mrr']);
    }

    public function test_customer_ltv_is_base_currency_and_purchase_value_is_per_currency(): void
    {
        $customer = (new CustomerFactory())->create();
        $this->paidOrder($customer->id, 'USD', 1000);
        $this->paidOrder($customer->id, 'EUR', 900, CurrencySnapshot::make('EUR', '0.9', ['source' => CurrencySnapshot::SOURCE_MANUAL]));
        // A EUR order with no trustworthy rate: counted per currency, left out of LTV
        $this->paidOrder($customer->id, 'EUR', 2000);

        $fresh = Customer::query()->find($customer->id)->recountStat();

        $this->assertSame(['USD' => 1000, 'EUR' => 2900], $fresh->purchase_value);
        $this->assertSame(3, (int) $fresh->purchase_count);
        $this->assertSame(2000, (int) $fresh->ltv, '$10.00 + (EUR 9.00 / 0.9 = $10.00); the rateless order is not guessed');
        $this->assertEquals(1000, $fresh->aov);
    }

    public function test_retention_snapshots_are_built_per_currency(): void
    {
        // Cohort month no other test writes into, so the suite's other
        // subscriptions cannot leak into these rows
        $cohortMonth = '2003-05';
        $customer = (new CustomerFactory())->create();
        $usd = $this->activeMonthlySubscription($customer->id, 'USD', 1000);
        $eur = $this->activeMonthlySubscription($customer->id, 'EUR', 5000);
        global $wpdb;
        foreach ([$usd->id, $eur->id] as $id) {
            $wpdb->update($wpdb->prefix . 'fct_subscriptions', ['created_at' => $cohortMonth . '-10 10:00:00'], ['id' => $id]);
        }

        (new RetentionSnapshotService())->generate();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT currency, cohort_mrr FROM {$wpdb->prefix}fct_retention_snapshots
             WHERE cohort = %s AND product_id IS NULL AND period_offset = 0 ORDER BY currency",
            $cohortMonth
        ));

        $this->assertSame(['EUR' => 5000, 'USD' => 1000], array_combine(
            array_column($rows, 'currency'),
            array_map('intval', array_column($rows, 'cohort_mrr'))
        ), 'one row per currency; never EUR + USD added together');
    }

    private function paidOrder(int $customerId, string $currency, int $total, ?array $snapshot = null, ?string $createdAt = null)
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
        (new OrderItemFactory())->create($order->id, ['unit_price' => $total, 'subtotal' => $total, 'line_total' => $total]);

        if ($createdAt) {
            global $wpdb;
            $wpdb->update($wpdb->prefix . 'fct_orders', ['created_at' => $createdAt], ['id' => $order->id]);
        }

        return $order;
    }

    private function activeMonthlySubscription(int $customerId, string $currency, int $amount)
    {
        $order = $this->paidOrder($customerId, $currency, $amount);

        return (new SubscriptionFactory())->create([
            'customer_id'      => $customerId,
            'parent_order_id'  => $order->id,
            'product_id'       => 1,
            'variation_id'     => 1,
            'billing_interval' => 'monthly',
            'recurring_amount' => $amount,
            'recurring_total'  => $amount,
            'status'           => Status::SUBSCRIPTION_ACTIVE,
        ]);
    }

    private function salesSummary(string $currency): array
    {
        $result = DefaultReportService::make([])->getAllGraphMetricsSeparate([
            'startDate'     => gmdate('Y-m-d 00:00:00', strtotime('-1 day')),
            'endDate'       => gmdate('Y-m-d 23:59:59', strtotime('+1 day')),
            'groupKey'      => 'daily',
            'currency'      => $currency,
            'variationIds'  => [],
            'paymentStatus' => Status::getReportStatuses(),
        ]);

        return (array) $result['summary'];
    }
}

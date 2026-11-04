<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Http\Controllers\Reports\SubscriptionReportController;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySnapshot;
use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;
use FluentCart\App\Services\Report\SubscriptionReportService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\SubscriptionFactory;
use FluentCart\Framework\Http\Request\Request as FluentRequest;

/**
 * MRR in the "All currencies" mode: each subscription converted at its own
 * frozen rate; a subscription without a trustworthy rate is left out.
 * dev-docs/multi-currency/plan.md §13. Base USD.
 */
class ConvertedSubscriptionReportsTest extends DatabaseTestCase
{
    public function test_mrr_converts_each_subscription_at_its_own_rate(): void
    {
        $customer = (new CustomerFactory())->create();
        $this->activeMonthly($customer->id, 'USD', 1000, null);
        // £7.90 at 0.79 -> $10.00
        $this->activeMonthly($customer->id, 'GBP', 790, CurrencySnapshot::make('GBP', '0.79', ['source' => CurrencySnapshot::SOURCE_MANUAL]));
        // A EUR subscription with no trustworthy rate
        $this->activeMonthly($customer->id, 'EUR', 5000, null);

        $service = SubscriptionReportService::make();
        $range = [
            'startDate'    => new \FluentCart\App\Services\DateTime\DateTime(gmdate('Y-m-01 00:00:00')),
            'endDate'      => new \FluentCart\App\Services\DateTime\DateTime(gmdate('Y-m-t 23:59:59')),
            // A variation no other test uses, so the suite's other
            // subscriptions cannot leak into the totals
            'variationIds' => [987654],
        ];

        $all = $service->getRetentionData($range + ['currency' => ReportCurrency::ALL]);
        $usd = $service->getRetentionData($range + ['currency' => 'USD']);

        $this->assertEquals(20.0, end($all)['mrr'], '$10 + £7.90 at 0.79; the rateless EUR one is left out');
        $this->assertEquals(10.0, end($usd)['mrr'], 'the scoped mode is unchanged');
    }

    /**
     * The endpoints opt in: "All currencies" in the request reaches the
     * service and the MRR comes back converted, not read as the base currency.
     */
    public function test_the_retention_endpoint_returns_converted_mrr(): void
    {
        $customer = (new CustomerFactory())->create();
        $this->activeMonthly($customer->id, 'USD', 1000, null);
        $this->activeMonthly($customer->id, 'GBP', 790, CurrencySnapshot::make('GBP', '0.79', ['source' => CurrencySnapshot::SOURCE_MANUAL]));
        $this->activeMonthly($customer->id, 'EUR', 5000, null);

        $mrr = function ($currency) {
            $rows = (new SubscriptionReportController())->getRetentionData($this->request($currency, ['variation_ids' => [987654]]))['retention_data'];

            return (float) end($rows)['mrr'];
        };

        $this->assertEquals(20.0, $mrr(ReportCurrency::ALL), '$10 + £7.90 at 0.79; the rateless EUR one is left out');
        $this->assertEquals(10.0, $mrr('USD'), 'the scoped mode is unchanged');
    }

    /**
     * Projected renewal revenue: each subscription's recurring total at its
     * own rate. The endpoint has no product filter, so this reads deltas.
     */
    public function test_the_future_renewals_endpoint_projects_converted_revenue(): void
    {
        $renewals = function ($currency) {
            return (new SubscriptionReportController())->getFutureRenewals($this->request($currency));
        };
        $allBefore = $renewals(ReportCurrency::ALL);
        $usdBefore = $renewals('USD');

        $customer = (new CustomerFactory())->create();
        // FutureRenewals keeps open-ended subscriptions only (bill_times <= 0)
        // and projects a quarter (89-92 days) ahead: a monthly renewal due in
        // 80 days lands once in that window whatever the month lengths.
        $due = ['next_billing_date' => gmdate('Y-m-d H:i:s', strtotime('+80 days')), 'bill_times' => 0, 'bill_count' => 1];
        $this->activeMonthly($customer->id, 'USD', 1000, null, $due);
        $this->activeMonthly($customer->id, 'GBP', 790, CurrencySnapshot::make('GBP', '0.79', ['source' => CurrencySnapshot::SOURCE_MANUAL]), $due);
        $this->activeMonthly($customer->id, 'EUR', 5000, null, $due);

        $all = $renewals(ReportCurrency::ALL);
        $usd = $renewals('USD');

        $this->assertEquals(2000, $all['totalProjected'] - $allBefore['totalProjected'], '$10 + £7.90 at 0.79 in cents; the rateless EUR one is left out');
        $this->assertSame(2, $all['totalRenewals'] - $allBefore['totalRenewals']);
        $this->assertEquals(1000, $usd['totalProjected'] - $usdBefore['totalProjected'], 'the scoped mode is unchanged');
    }

    private function request(string $currency, array $extra = []): FluentRequest
    {
        return new FluentRequest(App::getInstance(), [
            'params' => array_merge([
                'startDate' => gmdate('Y-m-01', strtotime('-1 month')),
                'endDate'   => gmdate('Y-m-d'),
                'currency'  => $currency,
            ], $extra),
        ], []);
    }

    private function activeMonthly(int $customerId, string $currency, int $amount, ?array $snapshot, array $extra = [])
    {
        $attributes = ['customer_id' => $customerId, 'type' => 'subscription', 'currency' => $currency, 'total_amount' => $amount];
        if ($snapshot) {
            $attributes['config'] = ['fx' => $snapshot];
        }
        $order = (new OrderFactory())->create($attributes);

        return (new SubscriptionFactory())->create([
            'customer_id'      => $customerId,
            'parent_order_id'  => $order->id,
            'product_id'       => 1,
            'variation_id'     => 987654,
            'billing_interval' => 'monthly',
            'recurring_amount' => $amount,
            'recurring_total'  => $amount,
            'status'           => Status::SUBSCRIPTION_ACTIVE,
        ] + $extra);
    }
}

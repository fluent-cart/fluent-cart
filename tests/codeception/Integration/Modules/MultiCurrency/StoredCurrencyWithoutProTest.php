<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Customer;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencyContext;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySnapshot;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\RestTestCase;
use Tests\Support\RateFixture;

/**
 * Turning multi-currency off for the storefront (no Pro) must not change how
 * stored orders read: reports, the dashboard, the order list and detail and
 * customer lifetime value use each order's own currency and frozen rate.
 * Base USD; one £7.90 order at 0.79 and one $10.00 order.
 */
class StoredCurrencyWithoutProTest extends RestTestCase
{
    const AVAILABLE = 'fluent_cart/currency/multi_currency_available';

    public function setUp(): void
    {
        parent::setUp();
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP']]]);
        RateFixture::set('GBP', '0.79');
        $this->reset();
    }

    public function tearDown(): void
    {
        remove_all_filters(self::AVAILABLE);
        $this->reset();
        parent::tearDown();
    }

    public function test_reports_and_the_order_list_read_stored_orders_the_same_without_pro(): void
    {
        // Without Pro reports read a fixed recent window, so figures are deltas
        $before = ['all' => $this->sales(ReportCurrency::ALL), 'gbp' => $this->sales('GBP')];

        $customer = (new CustomerFactory())->create();
        $gbp = $this->paidOrder($customer->id, 'GBP', 790, CurrencySnapshot::make('GBP', '0.79', ['source' => CurrencySnapshot::SOURCE_MANUAL]));
        $this->paidOrder($customer->id, 'USD', 1000, null);

        $this->activatePro();
        $withPro = $this->readEverything($gbp->id);

        $this->deactivatePro();
        $withoutPro = $this->readEverything($gbp->id);

        // A recount may save the customer, whose updated_at the order detail shows
        $this->activatePro();
        $withPro['ltv'] = $this->recountLtv($customer->id);
        $this->deactivatePro();
        $withoutPro['ltv'] = $this->recountLtv($customer->id);

        foreach ($withPro as $key => $value) {
            $this->assertSame($value, $withoutPro[$key], $key . ' reads the same without Pro');
        }

        // And the stored values are the GBP order's own
        $this->assertSame('GBP', $withoutPro['list_row']['currency']);
        $this->assertEquals(790, $withoutPro['list_row']['total_amount']);
        $this->assertSame('GBP', $withoutPro['detail']['currency']);
        $this->assertArrayHasKey('GBP', $withoutPro['report_meta']['currencies'], 'the report picker still offers GBP');
        $this->assertEqualsWithDelta(20.0, $withoutPro['sales_all']['gross_sale'] - $before['all']['gross_sale'], 0.001, '£7.90 at 0.79 + $10.00');
        $this->assertEqualsWithDelta(7.9, $withoutPro['sales_gbp']['gross_sale'] - $before['gbp']['gross_sale'], 0.001);
        $this->assertSame(2000, $withoutPro['ltv'], 'lifetime value converts the GBP order at its own rate');
    }

    private function readEverything(int $orderId): array
    {
        $list = $this->get('/fluent-cart/v2/orders', ['per_page' => 50, 'sort_by' => 'id', 'sort_type' => 'DESC']);
        $rows = $list['orders']['data'] ?? [];
        $row = null;
        foreach ($rows as $candidate) {
            if ((int) $candidate['id'] === $orderId) {
                $row = $candidate;
            }
        }
        $this->assertNotNull($row, 'the GBP order is in the list');

        $detail = $this->get('/fluent-cart/v2/orders/' . $orderId);

        return [
            'list_row'    => $row,
            'detail'      => $detail['order'] ?? $detail,
            'report_meta' => $this->get('/fluent-cart/v2/reports/fetch-report-meta', $this->window()),
            'sales_all'   => $this->sales(ReportCurrency::ALL),
            'sales_gbp'   => $this->sales('GBP'),
            'dashboard'   => $this->get('/fluent-cart/v2/reports/dashboard-stats', $this->window('GBP')),
        ];
    }

    private function activatePro(): void
    {
        add_filter(self::AVAILABLE, '__return_true');
        $this->reset();
        $this->assertTrue(MultiCurrencySettings::isEnabled(), 'precondition: Pro active');
    }

    private function deactivatePro(): void
    {
        remove_all_filters(self::AVAILABLE);
        $this->reset();
        $this->assertFalse(MultiCurrencySettings::isEnabled(), 'precondition: Pro inactive');
    }

    private function recountLtv(int $customerId): int
    {
        Customer::query()->find($customerId)->recountStat();

        return (int) Customer::query()->find($customerId)->ltv;
    }

    private function get(string $route, array $params = []): array
    {
        $request = new \WP_REST_Request('GET', $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        $this->resetFluentRequest();
        $response = $this->dispatchRequest($request);
        $this->assertSame(200, $response->get_status(), $route . ': ' . wp_json_encode($response->get_data()));

        return json_decode(wp_json_encode($response->get_data()), true);
    }

    private function window(string $currency = ''): array
    {
        return ['params' => array_filter([
            'startDate' => gmdate('Y-m-d', strtotime('-7 days')),
            'endDate'   => gmdate('Y-m-d', strtotime('+1 day')),
            'groupKey'  => 'daily',
            'currency'  => $currency,
        ])];
    }

    private function sales(string $currency): array
    {
        return (array) $this->get('/fluent-cart/v2/reports/revenue', $this->window($currency))['summary'];
    }

    private function paidOrder(int $customerId, string $currency, int $total, ?array $snapshot)
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

        return $order;
    }

    private function reset(): void
    {
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        CurrencyContext::reset();
    }
}

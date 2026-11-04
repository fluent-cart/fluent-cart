<?php

namespace Tests\Integration\Services\Report;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;
use FluentCart\App\Services\Report\PaymentMethodReportService;
use FluentCart\Framework\Support\DateTime;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Traits\BuildsTaxFixtures;

/**
 * In the All currencies mode the Payment Methods report leaves out orders with no trusted exchange rate. Their failed
 * charges must stay out of the risk figures too, not count as base-currency money at a rate of 1.
 */
class PaymentMethodsConvertedRiskTest extends DatabaseTestCase
{
    use BuildsTaxFixtures;

    public function setUp(): void
    {
        parent::setUp();
        $this->setStoreCurrency('USD');
        // The report reads the whole table in this mode
        App::db()->table('fct_orders')->delete();
    }

    public function tearDown(): void
    {
        $this->restoreStoreCurrency();
        parent::tearDown();
    }

    private function failedCharge(string $currency, array $config, int $cents): void
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id' => $customer->id, 'currency' => $currency, 'rate' => '1', 'config' => $config,
            'status' => Status::ORDER_FAILED, 'payment_status' => Status::PAYMENT_FAILED, 'payment_method' => 'stripe',
            'total_amount' => $cents, 'total_paid' => 0,
        ]);
        (new OrderTransactionFactory())->create($order->id, [
            'payment_method' => 'stripe', 'currency' => $currency, 'rate' => '1',
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE, 'status' => Status::TRANSACTION_FAILED, 'total' => $cents,
        ]);
    }

    public function test_failed_charges_of_orders_without_a_trusted_rate_stay_out(): void
    {
        $this->failedCharge('USD', ['fx' => ['currency' => 'USD', 'rate' => '1', 'source' => 'manual']], 1000);
        // An old euro order with no rate snapshot: no trusted rate, so the report cannot convert it
        $this->failedCharge('EUR', [], 5000);

        $report = PaymentMethodReportService::make()->getReport([
            'startDate' => new DateTime(gmdate('Y-m-d 00:00:00', strtotime('-1 day'))),
            'endDate'   => new DateTime(gmdate('Y-m-d 23:59:59', strtotime('+1 day'))),
            'currency'  => ReportCurrency::ALL, 'paymentStatus' => Status::getReportStatuses(), 'variationIds' => [], 'groupKey' => 'daily',
        ]);

        $this->assertSame([1, 10.0], [(int) $report['totals']['failed_charges'], (float) $report['totals']['failed_charge_amount']]);
    }
}

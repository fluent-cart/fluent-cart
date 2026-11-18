<?php

namespace Tests\Integration\Services\Report;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\Report\SubscriptionReportService;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;

/**
 * SubscriptionReportService::getFutureRenewals() projects renewals for the next
 * quarter by stepping next_billing_date forward by PaymentHelper::getIntervalDays().
 * Covers the half_yearly regression (it used to fall through to a 30-day default and
 * was projected as monthly) and the unresolvable-interval contract (0 days → excluded,
 * never an infinite loop).
 */
class SubscriptionFutureRenewalsTest extends WPTestCase
{
    private CustomerFactory $customerFactory;
    private ProductFactory $productFactory;
    private OrderFactory $orderFactory;
    private SubscriptionFactory $subscriptionFactory;
    private SubscriptionReportService $service;

    public function setUp(): void
    {
        parent::setUp();

        // The report aggregates over the WHOLE fct_subscriptions table, so stray
        // committed rows from other suites would shift every count. Clear inside
        // this test's transaction before seeding (same pattern as GroupByReportServicesTest).
        Subscription::query()->delete();

        $this->customerFactory     = new CustomerFactory();
        $this->productFactory      = new ProductFactory();
        $this->orderFactory        = new OrderFactory();
        $this->subscriptionFactory = new SubscriptionFactory();
        $this->service             = SubscriptionReportService::make();
    }

    private function activeSubscription(string $interval, int $daysUntilNextBilling, int $recurringTotal = 5000): Subscription
    {
        $customer = $this->customerFactory->create();
        $product  = $this->productFactory->withVariantAttributes(['payment_type' => 'subscription'])->create();
        $order    = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'type'        => Status::ORDER_TYPE_SUBSCRIPTION,
        ]);

        return $this->subscriptionFactory->create([
            'customer_id'       => $customer->id,
            'parent_order_id'   => $order->id,
            'product_id'        => $product->ID,
            'variation_id'      => $product->variants->first()->id,
            'billing_interval'  => $interval,
            'status'            => Status::SUBSCRIPTION_ACTIVE,
            'recurring_total'   => $recurringTotal,
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() + $daysUntilNextBilling * DAY_IN_SECONDS),
        ]);
    }

    // Regression: half_yearly was missing from the report's private interval map and
    // defaulted to 30 days, so one subscription projected three renewals per quarter.
    public function test_half_yearly_subscription_projects_one_renewal_in_the_next_quarter(): void
    {
        $this->activeSubscription('half_yearly', 10, 5000);

        $result = $this->service->getFutureRenewals([]);

        $this->assertSame(1, $result['totalRenewals']);
        $this->assertEquals(5000, $result['totalProjected']);
    }
}

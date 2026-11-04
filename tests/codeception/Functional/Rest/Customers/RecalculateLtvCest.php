<?php

namespace Tests\Functional\Rest\Customers;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/customers.md → "Recalculating lifetime value" section, items 58-59.
 */
class RecalculateLtvCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/customers.md [58]
     *   "Admin can recalculate a customer's lifetime value from their successful orders."
     */
    public function adminCanRecalculateCustomerLifetimeValueFromSuccessfulOrders(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create([
            'email' => 'ltv-recalc-' . uniqid('', true) . '@example.test',
            'ltv'   => 0,
            'purchase_count' => 0,
        ]);
        $orderFactory = new OrderFactory();
        // Two paid orders contribute to LTV; one pending order must be excluded.
        $paidA = $orderFactory->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PAID,
            'total_paid'     => Helper::toCent(120),
            'total_refund'   => Helper::toCent(20),
            'invoice_no'     => 'LTV-PAID-A',
        ]);
        $paidB = $orderFactory->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PAID,
            'total_paid'     => Helper::toCent(50),
            'total_refund'   => 0,
            'invoice_no'     => 'LTV-PAID-B',
        ]);
        $pending = $orderFactory->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PENDING,
            'total_paid'     => Helper::toCent(999),
            'total_refund'   => 0,
            'invoice_no'     => 'LTV-PENDING-IGNORED',
        ]);
        // Pre-write a stale ltv so the test verifies the recalculation does work, not just that ltv stayed at zero.
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'fct_customers', ['ltv' => 1, 'purchase_count' => 99], ['id' => $customer->id]);

        [, $body] = $I->post('/fluent-cart/v2/customers/' . (int) $customer->id . '/recalculate-ltv');

        $I->seeResponseCodeIs(200);
        Assert::assertNotEmpty($body['message'] ?? '', 'success response must include a confirmation message');
        Assert::assertArrayHasKey('customer', $body, 'success response must include the recalculated customer payload');
        Assert::assertSame(
            (int) $customer->id,
            (int) ($body['customer']['id'] ?? 0),
            'returned customer payload must match the recalculation target id'
        );

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT ltv, purchase_count FROM {$wpdb->prefix}fct_customers WHERE id = %d",
            $customer->id
        ), ARRAY_A);
        $expectedLtv = Helper::toCent(120) - Helper::toCent(20) + Helper::toCent(50);
        Assert::assertSame(
            $expectedLtv,
            (int) $row['ltv'],
            'ltv must be sum of (total_paid - total_refund) across paid orders only, in cents'
        );
        Assert::assertSame(
            2,
            (int) $row['purchase_count'],
            'purchase_count must be the number of paid orders (the pending order must be excluded)'
        );
    }

    /**
     * Spec: Cases/Functional/customers.md [59]
     *   "Admin receives a clear \"not found\" error when recalculating lifetime value for a customer that does not exist."
     */
    public function recalculatingLtvForMissingCustomerReturnsNotFound(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/customers/9999999/recalculate-ltv');

        $I->seeResponseCodeIs(422);
        Assert::assertNotEmpty($body['message'] ?? '', 'rejection response must include a non-empty message field. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsStringIgnoringCase('not found', (string) ($body['message'] ?? ''), 'rejection message must indicate the customer is not found');
    }
}

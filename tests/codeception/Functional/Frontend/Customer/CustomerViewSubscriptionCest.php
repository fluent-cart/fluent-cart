<?php

namespace Tests\Functional\Frontend\Customer;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Customer;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\BuildsSubscriptions;

/**
 * Spec: Cases/Functional/subscriptions.md → "Viewing a single subscription (customer portal)"
 *       section, items 39-43.
 *
 * Route: GET /fluent-cart/v2/customer-profile/subscriptions/{subscription_uuid}
 * Controller: CustomerSubscriptionController::getSubscription
 *   (app/Http/Controllers/FrontendControllers/CustomerSubscriptionController.php:78-166)
 * Policy: CustomerFrontendPolicy::verifyRequest — `is_user_logged_in()`
 *
 * The controller scopes by customer_id AND uuid AND status NOT IN (pending, intended) so
 * customers only see their own complete subscriptions. The single-view response carries a
 * richer shape than the list view (transactions, can_early_pay, remaining_installments).
 *
 * Test ordering note: CustomerResource::getCurrentCustomer caches statically across
 * tests within the same PHP process (known limitation flagged at
 * tests/Integration/Resources/CustomerResourceGetCurrentTest.php). Every method here
 * always re-seeds the logged-in customer FIRST (auto-increment id=1 after truncate) so
 * the stale cache's id matches whatever row gets created next.
 */
class CustomerViewSubscriptionCest
{
    use BuildsSubscriptions;

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [39]
     *   "Customer can open one of their own subscriptions by its public identifier and see
     *    the subscription with its billing info, transactions, and payment-method options."
     */
    public function customerCanOpenOwnSubscriptionByPublicIdentifierWithBillingInfoTransactionsAndPaymentOptions(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = $this->seedCurrentCustomer($userId);
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $productPair = $this->seedProductWithVariation();

        $subscription = (new SubscriptionFactory())->create([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => $productPair['product']->ID,
            'variation_id'    => $productPair['variation']->id,
            'item_name'       => 'View Probe 39',
            'status'          => Status::SUBSCRIPTION_ACTIVE,
            'bill_times'      => 12,
            'bill_count'      => 4,
        ]);

        // Seed a transaction attached to this subscription's order so the response's
        // transactions array isn't empty — proves the controller fetches them. The model
        // auto-generates a uuid on create (app/Models/OrderTransaction.php:100-105).
        $transaction = (new OrderTransactionFactory())->create((int) $order->id, [
            'subscription_id' => (int) $subscription->id,
        ]);

        [$status, $body] = $I->get(
            '/fluent-cart/v2/customer-profile/subscriptions/' . (string) $subscription->uuid
        );

        Assert::assertSame(200, $status, 'customer single-view must return 200 for own subscription. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('subscription', (array) $body, 'response must carry a subscription envelope');
        $payload = (array) $body['subscription'];

        Assert::assertSame(
            (string) $subscription->uuid,
            (string) ($payload['uuid'] ?? ''),
            'subscription payload uuid must match the requested public identifier'
        );
        Assert::assertSame(
            Status::SUBSCRIPTION_ACTIVE,
            (string) ($payload['status'] ?? ''),
            'subscription payload status must echo the seeded active status'
        );

        // "Billing info" is the billing_info / billing_addresses / current_payment_method
        // bundle the controller builds at lines 113-114 + 132 of CustomerSubscriptionController.
        Assert::assertArrayHasKey('billing_info', $payload, 'payload must expose billing_info (active payment method details)');
        Assert::assertArrayHasKey('billing_addresses', $payload, 'payload must expose billing_addresses (customer addresses keyed by type=billing)');
        Assert::assertArrayHasKey('current_payment_method', $payload, 'payload must expose current_payment_method so the portal can render the gateway label');

        // "Transactions" — controller line 153 attaches an array of transformed transactions.
        Assert::assertArrayHasKey('transactions', $payload, 'payload must expose transactions');
        $txnRows = (array) $payload['transactions'];
        $txnUuids = array_column($txnRows, 'uuid');
        Assert::assertContains(
            (string) $transaction->uuid,
            $txnUuids,
            'transactions array must include the transaction seeded on this subscription\'s order (OrderService::transformTransaction at app/Services/OrderService.php:620 surfaces the model uuid auto-generated at app/Models/OrderTransaction.php:100-105)'
        );

        // "Payment-method options" — controller exposes can_switch_payment_method,
        // switchable_payment_methods, and can_update_payment_method (lines 126-128).
        Assert::assertArrayHasKey('can_switch_payment_method', $payload, 'payload must expose can_switch_payment_method so the portal can render the Switch button');
        Assert::assertArrayHasKey('switchable_payment_methods', $payload, 'payload must expose switchable_payment_methods list');
        Assert::assertArrayHasKey('can_update_payment_method', $payload, 'payload must expose can_update_payment_method so the portal can render the Update card button');
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [40]
     *   "Customer cannot open another customer's subscription by guessing its identifier."
     */
    public function customerCannotOpenAnotherCustomersSubscriptionByGuessingIdentifier(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');

        // Seed logged-in customer FIRST (id=1) so the static cache stays aligned, then the
        // "other" customer (id=2).
        $this->seedCurrentCustomer($userId);

        $other = (new CustomerFactory())->create([
            'first_name' => 'Outside',
            'last_name'  => 'Customer',
            'email'      => 'view-40-other@example.test',
        ]);
        $otherOrder = (new OrderFactory())->create(['customer_id' => $other->id]);
        $productPair = $this->seedProductWithVariation();

        $otherSub = (new SubscriptionFactory())->create([
            'customer_id'     => $other->id,
            'parent_order_id' => $otherOrder->id,
            'product_id'      => $productPair['product']->ID,
            'variation_id'    => $productPair['variation']->id,
            'item_name'       => 'Foreign Sub 40',
            'status'          => Status::SUBSCRIPTION_ACTIVE,
        ]);

        [$status, $body] = $I->get(
            '/fluent-cart/v2/customer-profile/subscriptions/' . (string) $otherSub->uuid
        );

        Assert::assertSame(
            422,
            $status,
            'cross-customer view must surface controller sendError 422 (default code, since the controller cannot resolve a sub scoped to the logged-in customer at app/Http/Controllers/FrontendControllers/CustomerSubscriptionController.php:95-105). Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            'Subscription not found',
            (string) ($body['message'] ?? ''),
            'cross-customer view rejection must carry the "Subscription not found" message — admin must not be able to differentiate "exists, wrong owner" from "doesn\'t exist" via the response'
        );
        Assert::assertArrayNotHasKey('subscription', (array) $body, 'cross-customer view denial must NOT leak the foreign subscription payload');
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [41]
     *   "Opening an unpaid first-purchase subscription in pending or intended status returns a 'not found' error."
     */
    public function openingPendingOrIntendedSubscriptionIsRejectedAsNotFound(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = $this->seedCurrentCustomer($userId);
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $productPair = $this->seedProductWithVariation();

        // Probe both pending AND intended states because the spec explicitly enumerates
        // both — a regression where only one is filtered would slip through if only one
        // is exercised. Controller filter at line 101.
        $cases = [
            ['status' => Status::SUBSCRIPTION_PENDING,  'label' => 'pending'],
            ['status' => Status::SUBSCRIPTION_INTENDED, 'label' => 'intended'],
        ];

        foreach ($cases as $case) {
            $sub = (new SubscriptionFactory())->create([
                'customer_id'     => $customer->id,
                'parent_order_id' => $order->id,
                'product_id'      => $productPair['product']->ID,
                'variation_id'    => $productPair['variation']->id,
                'item_name'       => 'Incomplete Sub 41 ' . $case['label'],
                'status'          => $case['status'],
            ]);

            [$status, $body] = $I->get(
                '/fluent-cart/v2/customer-profile/subscriptions/' . (string) $sub->uuid
            );

            Assert::assertSame(
                422,
                $status,
                $case['label'] . ' subscription single-view must surface controller sendError 422. Body: ' . wp_json_encode($body)
            );
            Assert::assertStringContainsString(
                'Subscription not found',
                (string) ($body['message'] ?? ''),
                $case['label'] . ' single-view rejection must carry the "Subscription not found" message per app/Http/Controllers/FrontendControllers/CustomerSubscriptionController.php:101-105'
            );
            Assert::assertArrayNotHasKey(
                'subscription',
                (array) $body,
                $case['label'] . ' single-view rejection must NOT leak the incomplete subscription payload'
            );
        }
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [41a]
     *   "Customer can reopen an established installment subscription after a failed reactivation."
     */
    public function customerCanOpenEstablishedInstallmentAfterFailedReactivation(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = $this->seedCurrentCustomer($userId);
        $subscription = $this->buildSubscriptionForCustomer((int) $customer->id, [
            'status' => Status::SUBSCRIPTION_INTENDED,
            'bill_count' => 6,
            'bill_times' => 8,
        ], ['payment_status' => Status::PAYMENT_PAID]);

        [$status, $body] = $I->get('/fluent-cart/v2/customer-profile/subscriptions/' . $subscription->uuid);
        Assert::assertSame(200, $status, 'An established subscription remains accessible by its existing profile URL.');
        Assert::assertSame($subscription->uuid, $body['subscription']['uuid'] ?? null, 'The detail response belongs to the same subscription.');
        Assert::assertSame(6, (int) ($body['subscription']['bill_count'] ?? 0), 'The six installments remain recorded.');
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [42]
     *   "The single-subscription view exposes whether the customer is currently allowed to
     *    make an early payment."
     */
    public function singleSubscriptionViewExposesCanEarlyPayFlag(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = $this->seedCurrentCustomer($userId);
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $productPair = $this->seedProductWithVariation();

        // Eligible installment subscription so EarlyPaymentFeature::canPay returns true
        // (Pro is faked active by the functional bootstrap; status active; bill_times > 0;
        // bill_count < bill_times). See app/Modules/Subscriptions/Services/EarlyPaymentFeature.php:18-28.
        $eligible = (new SubscriptionFactory())->create([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => $productPair['product']->ID,
            'variation_id'    => $productPair['variation']->id,
            'item_name'       => 'Eligible Early-Pay Sub 42',
            'status'          => Status::SUBSCRIPTION_ACTIVE,
            'bill_times'      => 12,
            'bill_count'      => 4,
        ]);

        // Ineligible: open-ended (bill_times=0). canPay must return false.
        $ineligible = (new SubscriptionFactory())->create([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => $productPair['product']->ID,
            'variation_id'    => $productPair['variation']->id,
            'item_name'       => 'Open-Ended Sub 42',
            'status'          => Status::SUBSCRIPTION_ACTIVE,
            'bill_times'      => 0,
            'bill_count'      => 0,
        ]);

        // Eligible single-view → can_early_pay must be true.
        [, $bodyEligible] = $I->get(
            '/fluent-cart/v2/customer-profile/subscriptions/' . (string) $eligible->uuid
        );
        $payloadEligible = (array) ($bodyEligible['subscription'] ?? []);
        Assert::assertArrayHasKey(
            'can_early_pay',
            $payloadEligible,
            'single-view payload must expose can_early_pay so the portal can show or hide the "Pay early" action (controller line 134)'
        );
        Assert::assertTrue(
            (bool) ($payloadEligible['can_early_pay'] ?? false),
            'can_early_pay must be true for an installment subscription with bill_times>0, bill_count<bill_times, status=active (EarlyPaymentFeature::canPay)'
        );

        // Ineligible single-view → can_early_pay must be false.
        [, $bodyIneligible] = $I->get(
            '/fluent-cart/v2/customer-profile/subscriptions/' . (string) $ineligible->uuid
        );
        $payloadIneligible = (array) ($bodyIneligible['subscription'] ?? []);
        Assert::assertSame(
            false,
            $payloadIneligible['can_early_pay'] ?? null,
            'can_early_pay must be false for an open-ended (bill_times=0) subscription so the portal hides the "Pay early" action'
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [43]
     *   "The single-subscription view exposes the number of installments that remain for
     *    an installment subscription."
     */
    public function singleSubscriptionViewExposesRemainingInstallments(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = $this->seedCurrentCustomer($userId);
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $productPair = $this->seedProductWithVariation();

        $billTimes = 12;
        $billCount = 4;
        $expectedRemaining = $billTimes - $billCount;

        $subscription = (new SubscriptionFactory())->create([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => $productPair['product']->ID,
            'variation_id'    => $productPair['variation']->id,
            'item_name'       => 'Installment Sub 43',
            'status'          => Status::SUBSCRIPTION_ACTIVE,
            'bill_times'      => $billTimes,
            'bill_count'      => $billCount,
        ]);

        [, $body] = $I->get(
            '/fluent-cart/v2/customer-profile/subscriptions/' . (string) $subscription->uuid
        );
        $payload = (array) ($body['subscription'] ?? []);

        Assert::assertArrayHasKey(
            'remaining_installments',
            $payload,
            'single-view payload must expose remaining_installments so the portal can display "N payments left" (controller line 135)'
        );
        Assert::assertSame(
            $expectedRemaining,
            (int) $payload['remaining_installments'],
            'remaining_installments must equal max(0, bill_times - bill_count) per app/Http/Controllers/FrontendControllers/CustomerSubscriptionController.php:135'
        );
    }

    /**
     * Seed a Customer row linked to the supplied WP user via CustomerFactory. Overrides
     * email + user_id so CustomerResource::getCurrentCustomer (which queries by user_id
     * OR email) resolves against the just-logged-in WP user.
     */
    private function seedCurrentCustomer(int $userId): Customer
    {
        $wpUser = get_userdata($userId);
        return (new CustomerFactory())->create([
            'email'   => $wpUser->user_email,
            'user_id' => $userId,
        ]);
    }

    /** @return array{product:\FluentCart\App\Models\Product,variation:object} */
    private function seedProductWithVariation(): array
    {
        $product = (new ProductFactory())->create();
        return [
            'product'   => $product,
            'variation' => $product->variants->first(),
        ];
    }
}

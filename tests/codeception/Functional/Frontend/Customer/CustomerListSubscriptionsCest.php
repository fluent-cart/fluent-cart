<?php

namespace Tests\Functional\Frontend\Customer;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Customer;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\BuildsSubscriptions;

/**
 * Spec: Cases/Functional/subscriptions.md → "Listing my subscriptions (customer portal)"
 *       section, items 34-38.
 *
 * Route: GET /fluent-cart/v2/customer-profile/subscriptions
 * Controller: CustomerSubscriptionController::getSubscriptions
 *   (app/Http/Controllers/FrontendControllers/CustomerSubscriptionController.php:19-76)
 * Policy: CustomerFrontendPolicy::verifyRequest — `is_user_logged_in()`
 *   (app/Http/Policies/CustomerFrontendPolicy.php). The controller additionally checks
 *   CustomerResource::getCurrentCustomer() and degrades to an empty payload when missing,
 *   so the policy is permissive — even guests can hit the route.
 *
 * Test ordering note: `CustomerResource::getCurrentCustomer()` caches the resolved customer
 * in a function-static (app/Resource/CustomerResource.php:385-417, no public reset seam —
 * known limitation flagged by tests/Integration/Resources/CustomerResourceGetCurrentTest.php).
 * To stay deterministic across the suite, the guest test is declared first and the
 * logged-in tests always re-seed the "current customer" row FIRST so that its
 * auto-increment id (1, after fct_customers TRUNCATE) matches whatever id the stale cache
 * may hold from earlier runs of this Cest.
 */
class CustomerListSubscriptionsCest
{
    use BuildsSubscriptions;

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [35]
     *   "Customer cannot see another customer's subscriptions in their list."
     */
    public function customerCannotSeeAnotherCustomersSubscriptionsInList(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');

        // Seed the logged-in customer FIRST so it gets id=1 (matches the stale-cache id);
        // the "other" customer takes id=2.
        $mine = $this->seedCurrentCustomer($userId);
        $myOrder = (new OrderFactory())->create(["customer_id" => $mine->id]);
        $myProduct = $this->seedProductWithVariation();

        $other = (new CustomerFactory())->create([
            'first_name' => 'Outside',
            'last_name'  => 'Customer',
            'email'      => 'other-customer-35@example.test',
        ]);
        $otherOrder = (new OrderFactory())->create(["customer_id" => $other->id]);
        $otherProduct = $this->seedProductWithVariation();

        $mySub = (new SubscriptionFactory())->create([
            'customer_id'     => $mine->id,
            'parent_order_id' => $myOrder->id,
            'product_id'      => $myProduct['product']->ID,
            'variation_id'    => $myProduct['variation']->id,
            'item_name'       => 'My Subscription 35',
            'status'          => Status::SUBSCRIPTION_ACTIVE,
        ]);
        $otherSub = (new SubscriptionFactory())->create([
            'customer_id'     => $other->id,
            'parent_order_id' => $otherOrder->id,
            'product_id'      => $otherProduct['product']->ID,
            'variation_id'    => $otherProduct['variation']->id,
            'item_name'       => 'Other Subscription 35',
            'status'          => Status::SUBSCRIPTION_ACTIVE,
        ]);

        [$status, $body] = $I->get('/fluent-cart/v2/customer-profile/subscriptions');

        Assert::assertSame(200, $status, 'customer list must return 200. Body: ' . wp_json_encode($body));
        $rows = (array) ($body['subscriptions']['data'] ?? []);
        // OrderService::transformSubscription omits id; identify rows by uuid.
        $uuids = array_column($rows, 'uuid');

        Assert::assertContains(
            (string) $mySub->uuid,
            $uuids,
            'customer list must include the logged-in customer\'s own subscription (matched by uuid)'
        );
        Assert::assertNotContains(
            (string) $otherSub->uuid,
            $uuids,
            'customer list must NOT include the other customer\'s subscription (controller scopes by customer_id at app/Http/Controllers/FrontendControllers/CustomerSubscriptionController.php:56)'
        );
        Assert::assertSame(
            1,
            (int) ($body['subscriptions']['total'] ?? -1),
            'subscriptions.total must reflect only the logged-in customer\'s rows'
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [36]
     *   "An unpaid first-purchase subscription in pending or intended status is hidden from the customer's list."
     */
    public function subscriptionsInPendingOrIntendedStatusAreHiddenFromCustomerList(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');

        $customer = $this->seedCurrentCustomer($userId);
        $order = (new OrderFactory())->create(["customer_id" => $customer->id]);
        $productPair = $this->seedProductWithVariation();

        $pendingSub = (new SubscriptionFactory())->create([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => $productPair['product']->ID,
            'variation_id'    => $productPair['variation']->id,
            'item_name'       => 'Pending Sub 36',
            'status'          => Status::SUBSCRIPTION_PENDING,
        ]);
        $intendedSub = (new SubscriptionFactory())->create([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => $productPair['product']->ID,
            'variation_id'    => $productPair['variation']->id,
            'item_name'       => 'Intended Sub 36',
            'status'          => Status::SUBSCRIPTION_INTENDED,
        ]);
        $activeSub = (new SubscriptionFactory())->create([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => $productPair['product']->ID,
            'variation_id'    => $productPair['variation']->id,
            'item_name'       => 'Active Sub 36',
            'status'          => Status::SUBSCRIPTION_ACTIVE,
        ]);

        [$status, $body] = $I->get('/fluent-cart/v2/customer-profile/subscriptions');

        Assert::assertSame(200, $status, 'customer list must return 200. Body: ' . wp_json_encode($body));
        $rows = (array) ($body['subscriptions']['data'] ?? []);
        $uuids = array_column($rows, 'uuid');

        Assert::assertContains(
            (string) $activeSub->uuid,
            $uuids,
            'customer list must include the active subscription (matched by uuid)'
        );
        Assert::assertNotContains(
            (string) $pendingSub->uuid,
            $uuids,
            'customer list must hide pending subscriptions (controller whereNotIn at app/Http/Controllers/FrontendControllers/CustomerSubscriptionController.php:57)'
        );
        Assert::assertNotContains(
            (string) $intendedSub->uuid,
            $uuids,
            'customer list must hide intended subscriptions (same whereNotIn filter)'
        );
        Assert::assertSame(
            1,
            (int) ($body['subscriptions']['total'] ?? -1),
            'subscriptions.total must reflect only the visible (active) row'
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [36a]
     *   "A billed subscription stays on the customer's list through repeated failed reactivation attempts; an unbilled first purchase awaiting activation stays hidden."
     */
    public function establishedInstallmentRemainsListedAfterRepeatedRetryFailures(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = $this->seedCurrentCustomer($userId);
        $established = $this->buildSubscriptionForCustomer((int) $customer->id, [
            'status' => Status::SUBSCRIPTION_ACTIVE,
            'bill_count' => 6,
            'bill_times' => 8,
        ], ['payment_status' => Status::PAYMENT_PAID]);
        $newPurchase = $this->buildSubscriptionForCustomer((int) $customer->id, [
            'status' => Status::SUBSCRIPTION_INTENDED,
        ], ['payment_status' => Status::PAYMENT_PAID]);

        foreach ([Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_INTENDED, Status::SUBSCRIPTION_PENDING, Status::SUBSCRIPTION_INTENDED] as $status) {
            $established->update(['status' => $status]);
            [$responseStatus, $body] = $I->get('/fluent-cart/v2/customer-profile/subscriptions');
            Assert::assertSame(200, $responseStatus, 'The customer subscription list responds after each retry state.');
            Assert::assertSame([(string) $established->uuid], array_column((array) ($body['subscriptions']['data'] ?? []), 'uuid'), 'The billed installment stays visible and the unbilled first purchase awaiting activation stays hidden.');
            Assert::assertSame(1, (int) ($body['subscriptions']['total'] ?? 0), 'Pagination counts the established subscription after each retry state.');
        }
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [37]
     *   "Customer can paginate their subscription list and request a specific page size."
     */
    public function customerCanPaginateSubscriptionListAndRequestSpecificPageSize(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');

        $customer = $this->seedCurrentCustomer($userId);
        $order = (new OrderFactory())->create(["customer_id" => $customer->id]);
        $productPair = $this->seedProductWithVariation();

        $uuidsInInsertOrder = [];
        for ($i = 1; $i <= 5; $i++) {
            $sub = (new SubscriptionFactory())->create([
                'customer_id'     => $customer->id,
                'parent_order_id' => $order->id,
                'product_id'      => $productPair['product']->ID,
                'variation_id'    => $productPair['variation']->id,
                'item_name'       => 'Paginated Sub ' . $i,
                'status'          => Status::SUBSCRIPTION_ACTIVE,
            ]);
            $uuidsInInsertOrder[] = (string) $sub->uuid;
        }
        // Newest-first (id-desc) order is the reverse of insertion order. Identify by uuid
        // since OrderService::transformSubscription omits the primary key from the response
        // payload.
        $expectedDescUuids = array_reverse($uuidsInInsertOrder);

        // Page 1, per_page=2 → first two newest uuids.
        [$status1, $body1] = $I->get('/fluent-cart/v2/customer-profile/subscriptions', [
            'per_page' => 2,
            'page'     => 1,
        ]);
        Assert::assertSame(200, $status1, 'paginated list page 1 must return 200. Body: ' . wp_json_encode($body1));
        $env1 = (array) ($body1['subscriptions'] ?? []);
        Assert::assertCount(2, (array) ($env1['data'] ?? []), 'page 1 must contain per_page=2 rows');
        Assert::assertSame(
            array_slice($expectedDescUuids, 0, 2),
            array_column((array) $env1['data'], 'uuid'),
            'page 1 must contain the two newest subscriptions in id-desc order (matched by uuid)'
        );
        Assert::assertSame(5, (int) ($env1['total'] ?? -1), 'paginated total must reflect all customer subscriptions');
        Assert::assertSame(2, (int) ($env1['per_page'] ?? -1), 'per_page must echo the requested value');
        Assert::assertSame(1, (int) ($env1['current_page'] ?? -1), 'current_page must echo the requested page');
        Assert::assertSame(3, (int) ($env1['last_page'] ?? -1), 'last_page must compute ceil(total/per_page)');

        // Every returned row must carry the OrderService::transformSubscription shape
        // (app/Services/OrderService.php:597-618) so the customer portal can render —
        // not just the first row. Catches a regression where a single malformed row
        // sneaks in among the rest.
        foreach ((array) $env1['data'] as $rowIdx => $row) {
            foreach (['uuid', 'status', 'current_payment_method', 'bill_times', 'bill_count', 'item_name', 'can_upgrade'] as $expectedKey) {
                Assert::assertArrayHasKey(
                    $expectedKey,
                    (array) $row,
                    'paginated row #' . $rowIdx . ' must expose ' . $expectedKey . ' per OrderService::transformSubscription'
                );
            }
            Assert::assertSame(
                Status::SUBSCRIPTION_ACTIVE,
                (string) ($row['status'] ?? ''),
                'paginated row #' . $rowIdx . ' status must echo the seeded "active" status'
            );
        }

        // Page 2 with the same per_page → next two newest uuids.
        [$status2, $body2] = $I->get('/fluent-cart/v2/customer-profile/subscriptions', [
            'per_page' => 2,
            'page'     => 2,
        ]);
        Assert::assertSame(200, $status2, 'paginated list page 2 must return 200. Body: ' . wp_json_encode($body2));
        $env2 = (array) ($body2['subscriptions'] ?? []);
        Assert::assertSame(
            array_slice($expectedDescUuids, 2, 2),
            array_column((array) $env2['data'], 'uuid'),
            'page 2 must contain the next two newest subscriptions in id-desc order (matched by uuid)'
        );
        Assert::assertSame(2, (int) ($env2['current_page'] ?? -1), 'current_page must echo page=2');

        // Page 3 — only one row remains.
        [$status3, $body3] = $I->get('/fluent-cart/v2/customer-profile/subscriptions', [
            'per_page' => 2,
            'page'     => 3,
        ]);
        Assert::assertSame(200, $status3, 'paginated list page 3 must return 200. Body: ' . wp_json_encode($body3));
        $env3 = (array) ($body3['subscriptions'] ?? []);
        Assert::assertCount(1, (array) ($env3['data'] ?? []), 'page 3 must contain the final remaining subscription');
        Assert::assertSame(
            array_slice($expectedDescUuids, 4, 1),
            array_column((array) $env3['data'], 'uuid'),
            'page 3 must contain the oldest subscription (matched by uuid)'
        );
    }

    /**
     * Seed a Customer row linked to the supplied WP user via CustomerFactory. The factory's
     * defaults supply first/last name + status; we override email + user_id so
     * CustomerResource::getCurrentCustomer (which queries by user_id OR email) resolves
     * deterministically against the just-logged-in WP user.
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

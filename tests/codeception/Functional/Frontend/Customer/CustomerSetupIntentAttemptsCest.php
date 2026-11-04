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
 * Spec: Cases/Functional/subscriptions.md → "Setup-intent remaining attempts
 *       (customer portal)" section, items 59-61.
 *
 * Route: GET /fluent-cart/v2/customer-profile/subscriptions/{uuid}/setup-intent-attempts
 * Controller: CustomerSubscriptionController::getSetupIntentRemainingAttempts
 *   (app/Http/Controllers/FrontendControllers/CustomerSubscriptionController.php:412-451)
 *
 * Unlike the switch-payment-method group, this controller DOES scope the subscription
 * lookup by `customer_id`, so the cross-customer denial path is observable end-to-end.
 * The rate-limit computation uses WordPress transients (no real Stripe API call) at
 * app/Modules/PaymentMethods/StripeGateway/SubscriptionsManager.php:103-109, so the
 * happy-path test exercises the controller deterministically without external HTTP.
 */
class CustomerSetupIntentAttemptsCest
{
    use BuildsSubscriptions;

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [59]
     *   "Customer using Stripe can see how many setup-intent attempts remain before the
     *    gateway rate-limits them."
     */
    public function customerUsingStripeCanSeeRemainingSetupIntentAttempts(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = $this->seedCurrentCustomer($userId);

        $vendorCustomerId = 'cus_test_setup_' . uniqid('', true);
        $subscription = $this->seedSubscriptionFor($customer->id, [
            'current_payment_method' => 'stripe',
            'vendor_customer_id'     => $vendorCustomerId,
        ]);

        // Ensure the rate-limit transient is clear before dispatching so the response is
        // deterministic; SubscriptionsManager::getRemainingRateLimit treats a missing
        // transient as zero used attempts (line 107 returns `get_transient($key) ?: 0`).
        $rateLimitKey = 'fct_stripe_setup_intent_rate_daily_' . md5($vendorCustomerId);
        delete_transient($rateLimitKey);

        [$status, $body] = $I->get(
            '/fluent-cart/v2/customer-profile/subscriptions/' . (string) $subscription->uuid . '/setup-intent-attempts'
        );

        Assert::assertSame(200, $status, 'stripe customer setup-intent-attempts must return 200. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey(
            'remaining',
            (array) $body,
            'response must expose a `remaining` field so the customer portal can render the count'
        );
        // Default daily limit is 5 (filter `fluent_cart/stripe/setup_intent_rate_limit_customer_daily`
        // default in SubscriptionsManager::getRemainingRateLimit, lowered from 7 by
        // d9793c3bf #2483); zero attempts used → 5 remaining.
        Assert::assertSame(
            5,
            (int) $body['remaining'],
            'fresh stripe customer with no prior attempts must have 5 remaining (default daily limit minus zero used attempts; see SubscriptionsManager::getRemainingRateLimit)'
        );

        // Simulate one used attempt and re-dispatch to prove the response reflects the
        // current transient state, not a hardcoded value.
        set_transient($rateLimitKey, 1, HOUR_IN_SECONDS);
        [$status2, $body2] = $I->get(
            '/fluent-cart/v2/customer-profile/subscriptions/' . (string) $subscription->uuid . '/setup-intent-attempts'
        );
        Assert::assertSame(200, $status2, 'second dispatch must also return 200. Body: ' . wp_json_encode($body2));
        Assert::assertSame(
            4,
            (int) ($body2['remaining'] ?? -1),
            'remaining must decrement to 4 once the transient records 1 used attempt (proves the controller reads live state, not a constant)'
        );

        delete_transient($rateLimitKey);
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [60]
     *   "Customer using a non-Stripe gateway asking for setup-intent attempts is rejected
     *    with a clear 'payment method not supported' message."
     */
    public function customerUsingNonStripeGatewayAskingForSetupIntentAttemptsIsRejected(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = $this->seedCurrentCustomer($userId);

        $subscription = $this->seedSubscriptionFor($customer->id, [
            'current_payment_method' => 'offline_payment',
            'vendor_customer_id'     => 'cus_offline_irrelevant',
        ]);

        [$status, $body] = $I->get(
            '/fluent-cart/v2/customer-profile/subscriptions/' . (string) $subscription->uuid . '/setup-intent-attempts'
        );

        Assert::assertSame(
            422,
            $status,
            'non-stripe setup-intent-attempts request must surface controller sendError 422 (app/Http/Controllers/FrontendControllers/CustomerSubscriptionController.php:439-443). Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            'Payment method not supported',
            (string) ($body['message'] ?? ''),
            'non-stripe rejection message must name the unsupported-gateway cause per app/Http/Controllers/FrontendControllers/CustomerSubscriptionController.php:441'
        );
        Assert::assertArrayNotHasKey('remaining', (array) $body, 'rejection must NOT leak a remaining count');
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [61]
     *   "Customer cannot inspect setup-intent attempts on another customer's subscription."
     */
    public function customerCannotInspectSetupIntentAttemptsOnAnotherCustomersSubscription(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $this->seedCurrentCustomer($userId);

        $other = (new CustomerFactory())->create([
            'first_name' => 'Outside',
            'last_name'  => 'Customer',
            'email'      => 'setup-intent-61-other@example.test',
        ]);
        $foreignSub = $this->seedSubscriptionFor($other->id, [
            'current_payment_method' => 'stripe',
            'vendor_customer_id'     => 'cus_foreign_61',
        ]);

        [$status, $body] = $I->get(
            '/fluent-cart/v2/customer-profile/subscriptions/' . (string) $foreignSub->uuid . '/setup-intent-attempts'
        );

        Assert::assertSame(
            422,
            $status,
            'cross-customer setup-intent-attempts must surface controller sendError 422 (controller line 432-436 scopes by customer_id). Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            'Subscription not found',
            (string) ($body['message'] ?? ''),
            'cross-customer rejection must carry the "Subscription not found" message — must not leak whether the foreign subscription exists or what gateway it uses'
        );
        Assert::assertArrayNotHasKey('remaining', (array) $body, 'cross-customer rejection must NOT leak a remaining count');
    }

    private function seedCurrentCustomer(int $userId): Customer
    {
        $wpUser = get_userdata($userId);
        return (new CustomerFactory())->create([
            'email'   => $wpUser->user_email,
            'user_id' => $userId,
        ]);
    }

    private function seedSubscriptionFor(int $customerId, array $overrides = []): \FluentCart\App\Models\Subscription
    {
        $order = (new OrderFactory())->create(['customer_id' => $customerId]);
        $product = (new ProductFactory())->create();
        return (new SubscriptionFactory())->create(array_merge([
            'customer_id'     => $customerId,
            'parent_order_id' => $order->id,
            'product_id'      => $product->ID,
            'variation_id'    => $product->variants->first()->id,
            'item_name'       => 'SetupIntent Probe',
            'status'          => Status::SUBSCRIPTION_ACTIVE,
        ], $overrides));
    }
}

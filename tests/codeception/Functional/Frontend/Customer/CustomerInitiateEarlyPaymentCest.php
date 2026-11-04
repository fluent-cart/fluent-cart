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
 * Spec: Cases/Functional/subscriptions.md → "Initiating an early payment
 *       (customer portal)" section, items 62-64.
 *
 * Route: POST /fluent-cart/v2/customer-profile/subscriptions/{uuid}/initiate-early-payment
 * Controller: CustomerSubscriptionController::initiateEarlyPayment
 *   (app/Http/Controllers/FrontendControllers/CustomerSubscriptionController.php:362-402)
 *
 * Eligibility gate: EarlyPaymentFeature::canPay (app/Modules/Subscriptions/Services/
 * EarlyPaymentFeature.php:18-28) requires the feature enabled (Pro active + setting), an
 * installment subscription with remaining bill_times, and status in {ACTIVE, TRIALING}.
 * The functional bootstrap defines FLUENTCART_PRO_PLUGIN_VERSION so App::isProActive()
 * is true; the default store setting is enabled.
 *
 * Unlike the switch-payment-method group, this controller already scopes the lookup by
 * `customer_id`, so the cross-customer denial path is observable.
 */
class CustomerInitiateEarlyPaymentCest
{
    use BuildsSubscriptions;

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [62]
     *   "Customer can initiate an early installment payment on their own subscription
     *    when the early-payment feature is enabled and the subscription is eligible."
     */
    public function customerCanInitiateEarlyInstallmentPaymentOnOwnEligibleSubscription(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = $this->seedCurrentCustomer($userId);

        // Probe both ACTIVE and TRIALING — EarlyPaymentFeature::canPay at line 24 of
        // app/Modules/Subscriptions/Services/EarlyPaymentFeature.php explicitly accepts
        // both statuses, so a regression that narrowed it to ACTIVE-only must fail this
        // test. Each case posts independently with its own subscription.
        $eligibleCases = [
            'active'   => Status::SUBSCRIPTION_ACTIVE,
            'trialing' => Status::SUBSCRIPTION_TRIALING,
        ];

        foreach ($eligibleCases as $label => $statusValue) {
            $subscription = $this->seedSubscriptionFor($customer->id, [
                'item_name'  => 'Eligible ' . $label,
                'status'     => $statusValue,
                'bill_times' => 12,
                'bill_count' => 3,
            ]);

            [$status, $body] = $I->post(
                '/fluent-cart/v2/customer-profile/subscriptions/' . (string) $subscription->uuid . '/initiate-early-payment',
                []
            );

            Assert::assertSame(
                200,
                $status,
                'eligible ' . $label . ' subscription initiate-early-payment must return 200. Body: ' . wp_json_encode($body)
            );
            Assert::assertArrayHasKey(
                'checkout_url',
                (array) $body,
                $label . ' response must expose checkout_url so the portal can redirect the customer'
            );
            Assert::assertStringContainsString(
                'fluent-cart=early-installment-payment',
                (string) ($body['checkout_url'] ?? ''),
                $label . ' checkout_url must carry the fluent-cart=early-installment-payment query parameter so the front controller routes the request to the early-payment flow'
            );
            Assert::assertStringContainsString(
                (string) $subscription->uuid,
                (string) ($body['checkout_url'] ?? ''),
                $label . ' checkout_url must embed the subscription uuid as subscription_hash so the checkout binds to the right subscription (app/Http/Controllers/FrontendControllers/CustomerSubscriptionController.php:393-396)'
            );
            Assert::assertStringContainsString(
                'Early payment URL generated',
                (string) ($body['message'] ?? ''),
                $label . ' response message must echo the controller success text'
            );
        }
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [63]
     *   "Customer cannot initiate an early payment on a subscription that is not eligible
     *    (open-ended, canceled, expired, or already fully paid)."
     */
    public function customerCannotInitiateEarlyPaymentOnIneligibleSubscription(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = $this->seedCurrentCustomer($userId);

        // The spec enumerates four ineligible categories. Probe each one separately so a
        // regression that whitelists only one branch is observable.
        $cases = [
            'open-ended'  => ['status' => Status::SUBSCRIPTION_ACTIVE,    'bill_times' => 0,  'bill_count' => 0],
            'canceled'    => ['status' => Status::SUBSCRIPTION_CANCELED,  'bill_times' => 12, 'bill_count' => 3],
            'expired'     => ['status' => Status::SUBSCRIPTION_EXPIRED,   'bill_times' => 12, 'bill_count' => 3],
            'fully-paid'  => ['status' => Status::SUBSCRIPTION_ACTIVE,    'bill_times' => 12, 'bill_count' => 12],
        ];

        foreach ($cases as $label => $overrides) {
            $sub = $this->seedSubscriptionFor($customer->id, array_merge(
                ['item_name' => 'Ineligible ' . $label],
                $overrides
            ));

            [$status, $body] = $I->post(
                '/fluent-cart/v2/customer-profile/subscriptions/' . (string) $sub->uuid . '/initiate-early-payment',
                []
            );

            Assert::assertSame(
                422,
                $status,
                $label . ' subscription must be rejected with sendError 422. Body: ' . wp_json_encode($body)
            );
            Assert::assertStringContainsString(
                'Early payment is not available',
                (string) ($body['message'] ?? ''),
                $label . ' rejection message must name the ineligibility cause per app/Http/Controllers/FrontendControllers/CustomerSubscriptionController.php:388-390'
            );
            Assert::assertArrayNotHasKey(
                'checkout_url',
                (array) $body,
                $label . ' rejection must NOT leak a checkout_url — that would let the customer bypass the eligibility gate by URL-only access'
            );
        }
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [64]
     *   "Customer cannot initiate an early payment on another customer's subscription."
     */
    public function customerCannotInitiateEarlyPaymentOnAnotherCustomersSubscription(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $this->seedCurrentCustomer($userId);

        $other = (new CustomerFactory())->create([
            'first_name' => 'Outside',
            'last_name'  => 'Customer',
            'email'      => 'init-early-64-other@example.test',
        ]);
        $foreignSub = $this->seedSubscriptionFor($other->id, [
            'status'     => Status::SUBSCRIPTION_ACTIVE,
            'bill_times' => 12,
            'bill_count' => 3,
        ]);

        [$status, $body] = $I->post(
            '/fluent-cart/v2/customer-profile/subscriptions/' . (string) $foreignSub->uuid . '/initiate-early-payment',
            []
        );

        Assert::assertSame(
            422,
            $status,
            'cross-customer initiate-early-payment must surface controller sendError 422 (controller line 376-385 scopes by customer_id). Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            'Subscription not found',
            (string) ($body['message'] ?? ''),
            'cross-customer rejection must carry the "Subscription not found" message — must not differentiate "exists, wrong owner" from "does not exist", and must NOT be the eligibility-gate message (that would imply the controller resolved the foreign subscription)'
        );
        Assert::assertStringNotContainsString(
            'Early payment is not available',
            (string) ($body['message'] ?? ''),
            'cross-customer rejection message must NOT use the eligibility-gate wording — if the response said "Early payment is not available" the customer could infer the foreign subscription exists but is ineligible, which is an information leak'
        );
        Assert::assertArrayNotHasKey('checkout_url', (array) $body, 'cross-customer rejection must NOT leak a checkout_url');
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
            'item_name'       => 'InitEarlyPay Probe',
            'status'          => Status::SUBSCRIPTION_ACTIVE,
        ], $overrides));
    }
}

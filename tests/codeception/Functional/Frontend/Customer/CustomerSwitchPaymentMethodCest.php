<?php

namespace Tests\Functional\Frontend\Customer;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Customer;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\BuildsSubscriptions;

/**
 * Spec: Cases/Functional/subscriptions.md → "Switching or updating payment method
 *       (customer portal)" section, items 47-58.
 *
 * Routes (all POST, app/Http/Routes/frontend_routes.php:90-93):
 *   - /customer-profile/subscriptions/{uuid}/update-payment-method
 *   - /customer-profile/subscriptions/{uuid}/get-or-create-plan
 *   - /customer-profile/subscriptions/{uuid}/switch-payment-method
 *   - /customer-profile/subscriptions/{uuid}/confirm-subscription-switch
 *
 * Controller methods at app/Http/Controllers/FrontendControllers/CustomerSubscriptionController.php:
 *   updatePaymentMethod          (lines 168-201)
 *   getOrCreatePlan              (lines 203-235)
 *   switchPaymentMethod          (lines 237-276)
 *   confirmSubscriptionSwitch    (lines 279-318)
 *
 * Two systemic production bugs touch nearly every spec sentence in this section:
 *   (1) Fall-through to sendError on success — each controller invokes the gateway method
 *       inside a try/catch, but after the catch unconditionally returns
 *       `sendError('Could not <action>')`, so even a successful gateway call surfaces an
 *       error response to the customer. This breaks the happy-path items 47, 49, 51, 53.
 *   (2) No customer ownership scope on lookup — each controller queries
 *       `Subscription::query()->where('uuid', $uuid)->first()` without
 *       `->where('customer_id', $customer->id)`, so a logged-in customer can hit any
 *       other customer's subscription uuid. This breaks the cross-customer items 55-58.
 *
 * The negative input items (48, 50, 52, 54) are green because the rejection short-circuits
 * before the buggy fall-through logic runs.
 */
class CustomerSwitchPaymentMethodCest
{
    use BuildsSubscriptions;

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [50]
     *   "A prepare-plan request without naming a payment method is rejected with a clear error."
     */
    public function preparePlanRequestWithoutMethodIsRejected(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = $this->seedCurrentCustomer($userId);
        $subscription = $this->seedOwnedSubscription($customer);

        [$status, $body] = $I->post(
            '/fluent-cart/v2/customer-profile/subscriptions/' . (string) $subscription->uuid . '/get-or-create-plan',
            ['data' => []]
        );

        Assert::assertSame(
            422,
            $status,
            'missing-method get-or-create-plan must surface controller sendError 422 (app/Http/Controllers/FrontendControllers/CustomerSubscriptionController.php:209-213). Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            'Missing required parameters',
            (string) ($body['message'] ?? ''),
            'missing-method rejection message must name the missing parameters per app/Http/Controllers/FrontendControllers/CustomerSubscriptionController.php:211'
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [52]
     *   "A switch request that is missing either the current or the new payment method is
     *    rejected with a clear error."
     */
    public function switchRequestMissingCurrentOrNewPaymentMethodIsRejected(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = $this->seedCurrentCustomer($userId);
        $subscription = $this->seedOwnedSubscription($customer);

        // Probe BOTH "missing new" and "missing current" cases — the spec sentence is
        // "missing either the current or the new payment method", so a regression that
        // checked only one would slip through if only one is exercised.
        $cases = [
            'missing-new'     => ['data' => ['currentPaymentMethod' => 'offline_payment']],
            'missing-current' => ['data' => ['newPaymentMethod'     => 'offline_payment']],
            'missing-both'    => ['data' => []],
        ];

        foreach ($cases as $label => $payload) {
            [$status, $body] = $I->post(
                '/fluent-cart/v2/customer-profile/subscriptions/' . (string) $subscription->uuid . '/switch-payment-method',
                $payload
            );

            Assert::assertSame(
                422,
                $status,
                $label . ' switch-payment-method must surface controller sendError 422 (app/Http/Controllers/FrontendControllers/CustomerSubscriptionController.php:249-253). Body: ' . wp_json_encode($body)
            );
            Assert::assertStringContainsString(
                'Missing required parameters',
                (string) ($body['message'] ?? ''),
                $label . ' rejection message must name the missing parameters'
            );
        }
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [54]
     *   "A confirmation request that is missing the new vendor subscription identifier is
     *    rejected with a clear error."
     */
    public function confirmationRequestMissingNewVendorSubscriptionIdIsRejected(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = $this->seedCurrentCustomer($userId);
        $subscription = $this->seedOwnedSubscription($customer);

        [$status, $body] = $I->post(
            '/fluent-cart/v2/customer-profile/subscriptions/' . (string) $subscription->uuid . '/confirm-subscription-switch',
            ['data' => ['method' => 'offline_payment']]
        );

        Assert::assertSame(
            422,
            $status,
            'missing-newVendorSubscriptionId confirm must surface controller sendError 422 (app/Http/Controllers/FrontendControllers/CustomerSubscriptionController.php:291-295). Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            'Missing required parameters',
            (string) ($body['message'] ?? ''),
            'missing-newVendorSubscriptionId rejection message must name the missing parameters'
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [55]
     *   "Customer cannot request a payment-method update on another customer's subscription."
     */
    public function customerCannotRequestPaymentMethodUpdateOnAnotherCustomersSubscription(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $this->seedCurrentCustomer($userId);

        $other = (new CustomerFactory())->create([
            'first_name' => 'Outside',
            'last_name'  => 'Customer',
            'email'      => 'switch-55-other@example.test',
        ]);
        $otherSub = $this->seedOwnedSubscription($other);

        // Stub the gateway so we can prove no gateway dispatch happened — the spec sentence
        // "cannot request a payment-method update on another customer's subscription" must
        // be evidenced both by the rejection response AND by the gateway never being hit
        // with the foreign subscription's id.
        $calls = ['count' => 0];
        $gateway = App::gateway('offline_payment');
        $originalSubs = $gateway->subscriptions;
        $gateway->subscriptions = new class($calls) extends AbstractSubscriptionModule {
            /** @var array */
            private $calls;
            public function __construct(array &$calls)
            {
                $this->calls = &$calls;
            }
            public function cardUpdate($data, $subscriptionId)
            {
                $this->calls['count']++;
            }
        };

        try {
            [$status, $body] = $I->post(
                '/fluent-cart/v2/customer-profile/subscriptions/' . (string) $otherSub->uuid . '/update-payment-method',
                ['data' => ['method' => 'offline_payment', 'token' => 'fake-card-token']]
            );

            Assert::assertSame(
                422,
                $status,
                'cross-customer update-payment-method must surface a sendError 422 rejection. Body: ' . wp_json_encode($body)
            );
            Assert::assertStringContainsString(
                'Subscription not found',
                (string) ($body['message'] ?? ''),
                'cross-customer rejection message must explicitly name the not-found cause (must NOT leak whether the foreign subscription exists, AND must not be the generic fall-through "Could not update payment method" — that response is reused by every dispatch and is therefore not evidence of access denial)'
            );
            Assert::assertSame(
                0,
                $calls['count'],
                'cross-customer update-payment-method must NOT dispatch to offline_payment->subscriptions->cardUpdate — the controller must short-circuit at the customer-scope lookup before the gateway is touched'
            );
        } finally {
            $gateway->subscriptions = $originalSubs;
        }
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [56]
     *   "Customer cannot ask the gateway to prepare a plan on another customer's subscription."
     */
    public function customerCannotAskGatewayToPreparePlanOnAnotherCustomersSubscription(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $this->seedCurrentCustomer($userId);

        $other = (new CustomerFactory())->create([
            'first_name' => 'Outside',
            'last_name'  => 'Customer',
            'email'      => 'switch-56-other@example.test',
        ]);
        $otherSub = $this->seedOwnedSubscription($other);

        $calls = ['count' => 0];
        $gateway = App::gateway('offline_payment');
        $originalSubs = $gateway->subscriptions;
        $gateway->subscriptions = new class($calls) extends AbstractSubscriptionModule {
            /** @var array */
            private $calls;
            public function __construct(array &$calls)
            {
                $this->calls = &$calls;
            }
            public function getOrCreateNewPlan($subscriptionId, $reason)
            {
                $this->calls['count']++;
            }
        };

        try {
            [$status, $body] = $I->post(
                '/fluent-cart/v2/customer-profile/subscriptions/' . (string) $otherSub->uuid . '/get-or-create-plan',
                ['data' => ['method' => 'offline_payment', 'reason' => 'upgrade']]
            );

            Assert::assertSame(422, $status, 'cross-customer get-or-create-plan must surface a sendError 422. Body: ' . wp_json_encode($body));
            Assert::assertStringContainsString(
                'Subscription not found',
                (string) ($body['message'] ?? ''),
                'cross-customer rejection message must name the not-found cause, not the generic "Could not get or create plan" fall-through'
            );
            Assert::assertSame(
                0,
                $calls['count'],
                'cross-customer get-or-create-plan must NOT dispatch to offline_payment->subscriptions->getOrCreateNewPlan — the controller must short-circuit before the gateway is touched'
            );
        } finally {
            $gateway->subscriptions = $originalSubs;
        }
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [57]
     *   "Customer cannot switch the payment method on another customer's subscription."
     */
    public function customerCannotSwitchPaymentMethodOnAnotherCustomersSubscription(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $this->seedCurrentCustomer($userId);

        $other = (new CustomerFactory())->create([
            'first_name' => 'Outside',
            'last_name'  => 'Customer',
            'email'      => 'switch-57-other@example.test',
        ]);
        $otherSub = $this->seedOwnedSubscription($other);

        $calls = ['count' => 0];
        $gateway = App::gateway('offline_payment');
        $originalSubs = $gateway->subscriptions;
        $gateway->subscriptions = new class($calls) extends AbstractSubscriptionModule {
            /** @var array */
            private $calls;
            public function __construct(array &$calls)
            {
                $this->calls = &$calls;
            }
            public function switchPaymentMethod($data, $subscriptionId)
            {
                $this->calls['count']++;
            }
        };

        try {
            [$status, $body] = $I->post(
                '/fluent-cart/v2/customer-profile/subscriptions/' . (string) $otherSub->uuid . '/switch-payment-method',
                ['data' => [
                    'currentPaymentMethod' => 'stripe',
                    'newPaymentMethod'     => 'offline_payment',
                ]]
            );

            Assert::assertSame(422, $status, 'cross-customer switch-payment-method must surface a sendError 422. Body: ' . wp_json_encode($body));
            Assert::assertStringContainsString(
                'Subscription not found',
                (string) ($body['message'] ?? ''),
                'cross-customer rejection message must name the not-found cause, not the generic "Could not switch payment method" fall-through'
            );
            Assert::assertSame(
                0,
                $calls['count'],
                'cross-customer switch-payment-method must NOT dispatch to offline_payment->subscriptions->switchPaymentMethod — the controller must short-circuit before the gateway is touched'
            );
        } finally {
            $gateway->subscriptions = $originalSubs;
        }
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [58]
     *   "Customer cannot confirm a payment-method switch on another customer's subscription."
     */
    public function customerCannotConfirmPaymentMethodSwitchOnAnotherCustomersSubscription(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $this->seedCurrentCustomer($userId);

        $other = (new CustomerFactory())->create([
            'first_name' => 'Outside',
            'last_name'  => 'Customer',
            'email'      => 'switch-58-other@example.test',
        ]);
        $otherSub = $this->seedOwnedSubscription($other);

        $calls = ['count' => 0];
        $gateway = App::gateway('offline_payment');
        $originalSubs = $gateway->subscriptions;
        $gateway->subscriptions = new class($calls) extends AbstractSubscriptionModule {
            /** @var array */
            private $calls;
            public function __construct(array &$calls)
            {
                $this->calls = &$calls;
            }
            public function confirmSubscriptionSwitch($data, $subscriptionId)
            {
                $this->calls['count']++;
            }
        };

        try {
            [$status, $body] = $I->post(
                '/fluent-cart/v2/customer-profile/subscriptions/' . (string) $otherSub->uuid . '/confirm-subscription-switch',
                ['data' => [
                    'method'                  => 'offline_payment',
                    'newVendorSubscriptionId' => 'vendor_sub_FOREIGN',
                ]]
            );

            Assert::assertSame(422, $status, 'cross-customer confirm-subscription-switch must surface a sendError 422. Body: ' . wp_json_encode($body));
            Assert::assertStringContainsString(
                'Subscription not found',
                (string) ($body['message'] ?? ''),
                'cross-customer rejection message must name the not-found cause, not the generic "Could not confirm subscription switch" fall-through'
            );
            Assert::assertSame(
                0,
                $calls['count'],
                'cross-customer confirm-subscription-switch must NOT dispatch to offline_payment->subscriptions->confirmSubscriptionSwitch — the controller must short-circuit before the gateway is touched'
            );
        } finally {
            $gateway->subscriptions = $originalSubs;
        }
    }

    private function seedCurrentCustomer(int $userId): Customer
    {
        $wpUser = get_userdata($userId);
        return (new CustomerFactory())->create([
            'email'   => $wpUser->user_email,
            'user_id' => $userId,
        ]);
    }

    private function seedOwnedSubscription(Customer $customer): \FluentCart\App\Models\Subscription
    {
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $product = (new ProductFactory())->create();
        return (new SubscriptionFactory())->create([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => $product->ID,
            'variation_id'    => $product->variants->first()->id,
            'item_name'       => 'Switch-Cest Owned Sub',
            'status'          => Status::SUBSCRIPTION_ACTIVE,
        ]);
    }
}

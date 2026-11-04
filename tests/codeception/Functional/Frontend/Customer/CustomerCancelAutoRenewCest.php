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
 * Spec: Cases/Functional/subscriptions.md → "Cancelling auto-renew (customer portal)"
 *       section, items 44-46.
 *
 * Route: POST /fluent-cart/v2/customer-profile/subscriptions/{uuid}/cancel-auto-renew
 * Controller: CustomerSubscriptionController::cancelAutoRenew
 *   (app/Http/Controllers/FrontendControllers/CustomerSubscriptionController.php:320-360)
 *
 * The customer-portal cancel path is a thin wrapper around Subscription::cancelRemoteSubscription
 * (the same model method exercised by admin in CancelSubscriptionCest). It supplies
 * 'reason' => 'cancelled_by_customer' so the same config-accessor bug at
 * Subscription.php:140 + :561-563 still fires on the happy path. Item 46 bypasses that
 * branch by stubbing the gateway's cancel() to return WP_Error early, which causes
 * cancelRemoteSubscription to return at line 538-540 before any config access.
 */
class CustomerCancelAutoRenewCest
{
    use BuildsSubscriptions;

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [44]
     *   "Customer can cancel auto-renew on their own active subscription, and the
     *    subscription is marked canceled at the payment gateway."
     */
    public function customerCanCancelAutoRenewOnOwnActiveSubscription(FunctionalTester $I): void
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
            'item_name'       => 'Customer Cancel 44',
            'status'          => Status::SUBSCRIPTION_ACTIVE,
            // The spec sentence promises cancellation AT THE PAYMENT GATEWAY —
            // without a vendor id, cancelRemoteSubscription takes the local-only
            // branch and a deleted gateway call would go unnoticed (review-gate
            // round 7). Vendor id + capability + spy make the remote leg real.
            'vendor_subscription_id' => 'sub_vendor_customer_cancel_44',
        ]);

        $gateway = App::gateway('offline_payment');
        $originalSubs = $gateway->subscriptions;
        $originalFeatures = $gateway->supportedFeatures;
        $gateway->supportedFeatures[] = 'subscriptions';
        $cancelCalls = [];
        $gateway->subscriptions = new class($cancelCalls) extends AbstractSubscriptionModule {
            public array $calls = [];
            public function __construct(array $seed)
            {
                $this->calls = $seed;
            }
            public function cancel($vendorSubscriptionId, $args = [])
            {
                $this->calls[] = ['vendor_id' => $vendorSubscriptionId, 'args' => $args];
                return ['canceled_at' => gmdate('Y-m-d H:i:s', time())];
            }
        };

        try {
            [$status, $body] = $I->post(
                '/fluent-cart/v2/customer-profile/subscriptions/' . (string) $subscription->uuid . '/cancel-auto-renew',
                []
            );
            $spyCalls = $gateway->subscriptions->calls;
        } finally {
            $gateway->subscriptions = $originalSubs;
            $gateway->supportedFeatures = $originalFeatures;
        }

        Assert::assertSame(200, $status, 'customer cancel-auto-renew must return 200 on success. Body: ' . wp_json_encode($body));
        Assert::assertCount(1, $spyCalls, 'the gateway cancel() must be called exactly once — the spec promises cancellation AT the gateway');
        Assert::assertSame(
            'sub_vendor_customer_cancel_44',
            $spyCalls[0]['vendor_id'],
            'the gateway must be asked to cancel the subscription\'s own vendor id'
        );
        Assert::assertSame(
            (int) $subscription->id,
            (int) ($spyCalls[0]['args']['subscription_id'] ?? 0),
            'the cancel args must carry the local subscription id (Subscription::cancelRemoteSubscription contract)'
        );
        Assert::assertSame(
            (int) $order->id,
            (int) ($spyCalls[0]['args']['parent_order_id'] ?? 0),
            'the cancel args must carry the parent order id'
        );
        Assert::assertStringContainsString(
            'successfully cancelled',
            (string) ($body['message'] ?? ''),
            'customer cancel-auto-renew success body must carry the controller success message per app/Http/Controllers/FrontendControllers/CustomerSubscriptionController.php:356-358'
        );

        global $wpdb;
        Assert::assertSame(
            Status::SUBSCRIPTION_CANCELED,
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
                (int) $subscription->id
            )),
            'cancel-auto-renew success must persist fct_subscriptions.status=canceled via Subscription::cancelRemoteSubscription local-update branch'
        );
        Assert::assertNotEmpty(
            $wpdb->get_var($wpdb->prepare(
                "SELECT canceled_at FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
                (int) $subscription->id
            )),
            'cancel-auto-renew success must stamp fct_subscriptions.canceled_at as a UTC timestamp'
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [45]
     *   "Customer cannot cancel auto-renew on another customer's subscription."
     */
    public function customerCannotCancelAutoRenewOnAnotherCustomersSubscription(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');

        // Seed logged-in customer FIRST (id=1) to align with the static-cache invariant
        // (see class-level note in CustomerListSubscriptionsCest), then the foreign one.
        $this->seedCurrentCustomer($userId);

        $other = (new CustomerFactory())->create([
            'first_name' => 'Outside',
            'last_name'  => 'Customer',
            'email'      => 'cancel-45-other@example.test',
        ]);
        $otherOrder = (new OrderFactory())->create(['customer_id' => $other->id]);
        $productPair = $this->seedProductWithVariation();

        $otherSub = (new SubscriptionFactory())->create([
            'customer_id'     => $other->id,
            'parent_order_id' => $otherOrder->id,
            'product_id'      => $productPair['product']->ID,
            'variation_id'    => $productPair['variation']->id,
            'item_name'       => 'Foreign Cancel 45',
            'status'          => Status::SUBSCRIPTION_ACTIVE,
        ]);

        [$status, $body] = $I->post(
            '/fluent-cart/v2/customer-profile/subscriptions/' . (string) $otherSub->uuid . '/cancel-auto-renew',
            []
        );

        Assert::assertSame(
            422,
            $status,
            'cross-customer cancel must surface controller sendError 422 (controller cannot resolve a sub scoped to the logged-in customer at app/Http/Controllers/FrontendControllers/CustomerSubscriptionController.php:334-343). Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            'Subscription not found',
            (string) ($body['message'] ?? ''),
            'cross-customer cancel rejection must carry the "Subscription not found" message — must not leak whether the foreign sub exists'
        );

        global $wpdb;
        Assert::assertSame(
            Status::SUBSCRIPTION_ACTIVE,
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
                (int) $otherSub->id
            )),
            'cross-customer cancel denial must NOT mutate the foreign subscription status'
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [46]
     *   "When the payment gateway rejects auto-renew cancellation, the customer receives
     *    a generic friendly message rather than the raw gateway error."
     */
    public function whenGatewayRejectsAutoRenewCancellationCustomerReceivesGenericFriendlyMessage(FunctionalTester $I): void
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
            'item_name'              => 'Customer Cancel 46',
            'status'                 => Status::SUBSCRIPTION_ACTIVE,
            // cancelRemoteSubscription only calls the gateway when a vendor
            // subscription exists — without it the renewal engine cancels locally.
            'vendor_subscription_id' => 'sub_vendor_customer_cancel_46',
        ]);

        // Stub the offline_payment gateway's subscriptions->cancel() to return a WP_Error
        // with a leak-y, gateway-specific message. The controller must wrap that with a
        // generic user-facing message rather than echoing the gateway's text.
        $rawGatewayMessage = 'Card processor returned error code GATEWAY_INTERNAL_42: subscription token expired';
        $gateway = App::gateway('offline_payment');
        $originalSubs = $gateway->subscriptions;
        // The renewal engine dropped 'subscriptions' from offline_payment's
        // supportedFeatures; cancelRemoteSubscription gates the gateway call on it.
        $originalFeatures = $gateway->supportedFeatures;
        $gateway->supportedFeatures[] = 'subscriptions';
        $gateway->subscriptions = new class($rawGatewayMessage) extends AbstractSubscriptionModule {
            private string $rawGatewayMessage;
            public function __construct(string $rawGatewayMessage)
            {
                $this->rawGatewayMessage = $rawGatewayMessage;
            }
            public function cancel($vendorSubscriptionId, $args = [])
            {
                return new \WP_Error('vendor_declined', $this->rawGatewayMessage);
            }
        };

        try {
            [$status, $body] = $I->post(
                '/fluent-cart/v2/customer-profile/subscriptions/' . (string) $subscription->uuid . '/cancel-auto-renew',
                []
            );

            Assert::assertSame(
                422,
                $status,
                'gateway-rejected customer cancel must surface controller sendError 422 (controller at app/Http/Controllers/FrontendControllers/CustomerSubscriptionController.php:350-354). Body: ' . wp_json_encode($body)
            );
            $message = (string) ($body['message'] ?? '');
            Assert::assertStringContainsString(
                'Unable to cancel subscription',
                $message,
                'customer-facing message must carry the generic "Unable to cancel subscription" wording from the controller, hiding gateway details from end users'
            );
            Assert::assertStringNotContainsString(
                $rawGatewayMessage,
                $message,
                'customer-facing message must NOT leak the raw gateway error — that exposes internals to end users (controller intentionally wraps the WP_Error with a generic message at line 351-353)'
            );
            Assert::assertStringNotContainsString(
                'GATEWAY_INTERNAL_42',
                $message,
                'customer-facing message must NOT leak gateway-internal error codes'
            );

            global $wpdb;
            Assert::assertSame(
                Status::SUBSCRIPTION_ACTIVE,
                (string) $wpdb->get_var($wpdb->prepare(
                    "SELECT status FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
                    (int) $subscription->id
                )),
                'gateway-rejected cancel must NOT mutate fct_subscriptions.status — cancelRemoteSubscription returns the WP_Error before the local-update branch runs (app/Models/Subscription.php:538-540)'
            );
        } finally {
            $gateway->subscriptions = $originalSubs;
            $gateway->supportedFeatures = $originalFeatures;
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

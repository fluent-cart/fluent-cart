<?php

namespace Tests\Functional\Rest\Subscriptions;

use FluentCart\App\Helpers\Status;
use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Traits\BuildsSubscriptions;

/**
 * Route:      PUT /fluent-cart/v2/orders/{order}/subscriptions/{subscription}/cancel
 * Controller: SubscriptionController::cancelSubscription
 * Guard:      SubscriptionController::validateOrderBinding
 *
 * validateOrderBinding is shared by cancel / fetch / pause / resume / reactivate /
 * update / charge-now / create-renewal / skip-renewal, so pinning it once on the
 * cancel route pins the contract for all of them.
 *
 * The guard resolves the URL order to its root — a renewal child order is created
 * with parent_id = subscription->parent_order_id (SubscriptionService::createRenewalOrder),
 * so the tree is flat and $order->parent_id IS the root. Accepting the child lets the
 * renewal-order page drive subscription actions. Every other order must still be
 * refused, which is what the 403 cases below hold in place.
 */
class OrderBindingCest
{
    use BuildsSubscriptions;

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    public function renewalChildOrderBindsToItsParentSubscription(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$customer, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Binding Probe Child',
            'status'    => Status::SUBSCRIPTION_ACTIVE,
        ]);

        $renewalOrder = (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'parent_id'   => $order->id,
            'type'        => Status::ORDER_TYPE_RENEWAL,
        ]);

        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $renewalOrder->id . '/subscriptions/' . (int) $subscription->id . '/cancel',
            ['cancel_reason' => 'Cancelled from the renewal order page']
        );

        Assert::assertSame(
            200,
            $status,
            'a renewal child order (parent_id = subscription->parent_order_id) must bind to its subscription — validateOrderBinding resolves the URL order to its root before comparing. Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        Assert::assertSame(
            Status::SUBSCRIPTION_CANCELED,
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
                (int) $subscription->id
            )),
            'binding through the child order must reach the real cancel path, not just return 200. Body: ' . wp_json_encode($body)
        );
    }

    public function parentOrderStillBindsToItsSubscription(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Binding Probe Parent',
            'status'    => Status::SUBSCRIPTION_ACTIVE,
        ]);

        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/cancel',
            ['cancel_reason' => 'Cancelled from the parent order']
        );

        Assert::assertSame(
            200,
            $status,
            'the original parent order must keep binding — widening the guard to child orders must not cost the root case. Body: ' . wp_json_encode($body)
        );
    }

    public function unrelatedOrderIsRefused(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [, , , $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Binding Probe Foreign',
            'status'    => Status::SUBSCRIPTION_ACTIVE,
        ]);

        [$otherCustomer, , $otherOrder] = $this->buildSubscriptionChain([
            'item_name' => 'Binding Probe Other Chain',
            'status'    => Status::SUBSCRIPTION_ACTIVE,
        ]);

        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $otherOrder->id . '/subscriptions/' . (int) $subscription->id . '/cancel',
            ['cancel_reason' => 'Should never land']
        );

        Assert::assertSame(
            403,
            $status,
            'an order belonging to a different subscription chain must be refused with 403. Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            'does not belong to this order',
            (string) ($body['message'] ?? ''),
            'the refusal must carry the validateOrderBinding message. Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        Assert::assertSame(
            Status::SUBSCRIPTION_ACTIVE,
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
                (int) $subscription->id
            )),
            'a refused binding must not mutate the subscription. Body: ' . wp_json_encode($body)
        );
        Assert::assertNotEmpty($otherCustomer->id, 'the foreign chain must have been built for this assertion to mean anything');
    }

    public function childOfAnUnrelatedOrderIsRefused(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [, , , $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Binding Probe Foreign Child',
            'status'    => Status::SUBSCRIPTION_ACTIVE,
        ]);

        [$otherCustomer, , $otherOrder] = $this->buildSubscriptionChain([
            'item_name' => 'Binding Probe Other Chain Child',
            'status'    => Status::SUBSCRIPTION_ACTIVE,
        ]);

        $foreignRenewal = (new OrderFactory())->create([
            'customer_id' => $otherCustomer->id,
            'parent_id'   => $otherOrder->id,
            'type'        => Status::ORDER_TYPE_RENEWAL,
        ]);

        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $foreignRenewal->id . '/subscriptions/' . (int) $subscription->id . '/cancel',
            ['cancel_reason' => 'Should never land']
        );

        Assert::assertSame(
            403,
            $status,
            'resolving a child order to its root must not let another chain through — the root still has to match parent_order_id. Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        Assert::assertSame(
            Status::SUBSCRIPTION_ACTIVE,
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
                (int) $subscription->id
            )),
            'a refused foreign-child binding must not mutate the subscription. Body: ' . wp_json_encode($body)
        );
    }
}

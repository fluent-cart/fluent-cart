<?php

namespace Tests\Functional\Rest\Subscriptions;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Status;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ActivityFactory;
use Tests\Support\Factory\OrderAddressFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\BuildsSubscriptions;

/**
 * Spec: Cases/Functional/subscriptions.md → "Viewing a single subscription" section, items 12-17.
 */
class ViewSubscriptionCest
{
    use BuildsSubscriptions;

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [12]
     *   "Admin can open a subscription by its identifier and see the subscription along with
     *    its customer, billing and shipping addresses, transactions, related orders, and
     *    activity log."
     */
    public function adminCanOpenSubscriptionWithCustomerAddressesTransactionsRelatedOrdersAndActivities(FunctionalTester $I): void
    {

        $I->loginAsSuperAdmin();

        [$customer, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'View Probe With Relations',
        ]);

        $billing = (new OrderAddressFactory())->create((int) $order->id, [
            'type'      => 'billing',
            'name'      => 'Sub Bill Receiver',
            'address_1' => '301 Subscription Lane',
            'city'      => 'Subtown',
            'state'     => 'SU',
            'postcode'  => '33333',
            'country'   => 'US',
        ]);
        $shipping = (new OrderAddressFactory())->create((int) $order->id, [
            'type'      => 'shipping',
            'name'      => 'Sub Ship Receiver',
            'address_1' => '402 Subscription Way',
            'city'      => 'Subport',
            'state'     => 'SP',
            'postcode'  => '44444',
            'country'   => 'US',
        ]);

        $transaction = (new OrderTransactionFactory())->create((int) $order->id, [
            'subscription_id'  => (int) $subscription->id,
            'transaction_hash' => 'view-sub-txn-' . uniqid('', true),
        ]);

        $activity = (new ActivityFactory())->create([
            'module_type' => \FluentCart\App\Models\Subscription::class,
            'module_id'   => (int) $subscription->id,
            'module_name' => 'subscription',
            'title'       => 'View Probe Activity',
            'content'     => 'Subscription was inspected for the View Probe',
        ]);

        [, $body] = $I->get('/fluent-cart/v2/subscriptions/' . (int) $subscription->id);

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('subscription', (array) $body, 'view response must contain top-level subscription key');
        $payload = (array) $body['subscription'];

        Assert::assertSame((int) $subscription->id, (int) ($payload['id'] ?? 0), 'view payload id must match the requested subscription id');
        Assert::assertSame((int) $customer->id, (int) ($payload['customer_id'] ?? 0), 'view payload customer_id must match the seeded customer');

        Assert::assertArrayHasKey('customer', $payload, 'view payload must include the customer relation');
        Assert::assertSame((int) $customer->id, (int) ($payload['customer']['id'] ?? 0), 'embedded customer.id must match seeded customer');

        Assert::assertArrayHasKey('related_orders', $payload, 'view payload must include the related_orders relation');
        $relatedOrderIds = array_map('intval', array_column((array) $payload['related_orders'], 'id'));
        Assert::assertContains((int) $order->id, $relatedOrderIds, 'related_orders must include the parent order that originated the subscription');

        Assert::assertArrayHasKey('activities', $payload, 'view payload must include the activities relation (activity log)');
        $activityIds = array_map('intval', array_column((array) $payload['activities'], 'id'));
        Assert::assertContains((int) $activity->id, $activityIds, 'activities must include the activity row seeded on this subscription');

        Assert::assertArrayHasKey('billing_address', $payload, 'view payload must include billing_address (attached from parent order)');
        $billingRow = (array) $payload['billing_address'];
        Assert::assertSame((int) $billing->id, (int) ($billingRow['id'] ?? 0), 'view billing_address.id must match the seeded billing row');
        Assert::assertSame('billing', (string) ($billingRow['type'] ?? ''), 'view billing_address.type must be billing');
        Assert::assertSame('301 Subscription Lane', (string) ($billingRow['address_1'] ?? ''), 'view billing_address.address_1 must echo seeded value');

        Assert::assertArrayHasKey('shipping_address', $payload, 'view payload must include shipping_address (attached from parent order)');
        $shippingRow = (array) $payload['shipping_address'];
        Assert::assertSame((int) $shipping->id, (int) ($shippingRow['id'] ?? 0), 'view shipping_address.id must match the seeded shipping row');
        Assert::assertSame('shipping', (string) ($shippingRow['type'] ?? ''), 'view shipping_address.type must be shipping');
        Assert::assertSame('402 Subscription Way', (string) ($shippingRow['address_1'] ?? ''), 'view shipping_address.address_1 must echo seeded value');

        Assert::assertArrayHasKey('transactions', $payload, 'view payload must include the transactions relation');
        $txnRows = (array) $payload['transactions'];
        $txnIds  = array_map('intval', array_column($txnRows, 'id'));
        Assert::assertContains((int) $transaction->id, $txnIds, 'view payload transactions must include the transaction seeded on this subscription');
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [13]
     *   "Opening a subscription that does not exist returns a clear 'not found' error."
     */
    public function openingMissingSubscriptionReturnsNotFoundError(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        // Seed an unrelated subscription so the table is not empty — proves the 404 is about
        // the requested id, not about an empty table.
        $other = $this->buildSubscription(['item_name' => 'Untouched Sibling']);
        $missingId = ((int) $other->id) + 9999;

        [$status, $body] = $I->get('/fluent-cart/v2/subscriptions/' . $missingId);

        $I->seeResponseCodeIs(404);
        Assert::assertSame(
            'fluent_cart_entity_not_found',
            (string) ($body['code'] ?? ''),
            'view-missing response must carry the entity-not-found error code so the UI can route appropriately'
        );
        Assert::assertArrayHasKey('data', (array) $body, 'view-missing response must carry a data envelope with message + buttonText + route');
        Assert::assertNotEmpty(
            $body['data']['message'] ?? null,
            'view-missing response must include a human-readable message'
        );
        Assert::assertSame(
            '/subscriptions',
            (string) ($body['data']['route'] ?? ''),
            'view-missing response must route the UI back to the subscriptions list'
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [14]
     *   "Viewing an installment subscription reveals how many billing cycles remain."
     */
    public function viewingInstallmentSubscriptionRevealsRemainingBillingCycles(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $expectedTotal     = 12;
        $expectedCompleted = 4;
        $expectedRemaining = $expectedTotal - $expectedCompleted;

        $subscription = $this->buildSubscription([
            'item_name'  => 'Installment Probe',
            'bill_times' => $expectedTotal,
            'bill_count' => $expectedCompleted,
            'status'     => Status::SUBSCRIPTION_ACTIVE,
        ]);

        [, $body] = $I->get('/fluent-cart/v2/subscriptions/' . (int) $subscription->id);

        $I->seeResponseCodeIs(200);
        $payload = (array) ($body['subscription'] ?? []);

        Assert::assertArrayHasKey('bill_times', $payload, 'view payload must expose bill_times so the UI can determine total billing cycles');
        Assert::assertArrayHasKey('bill_count', $payload, 'view payload must expose bill_count so the UI can determine completed billing cycles');
        Assert::assertSame($expectedTotal, (int) $payload['bill_times'], 'view payload bill_times must echo seeded installment total');
        Assert::assertSame($expectedCompleted, (int) $payload['bill_count'], 'view payload bill_count must echo seeded installments completed');
        Assert::assertSame(
            $expectedRemaining,
            (int) $payload['bill_times'] - (int) $payload['bill_count'],
            'remaining billing cycles (bill_times - bill_count) must equal expected remaining count'
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [15]
     *   "The single-subscription view exposes whether the subscription can currently be
     *    upgraded to another plan."
     */
    public function singleSubscriptionViewExposesCanUpgradeFlag(FunctionalTester $I): void
    {

        $I->loginAsSuperAdmin();

        $subscription = $this->buildSubscription([
            'item_name' => 'Upgrade Flag Probe',
            'status'    => Status::SUBSCRIPTION_ACTIVE,
        ]);

        [, $body] = $I->get('/fluent-cart/v2/subscriptions/' . (int) $subscription->id);

        $I->seeResponseCodeIs(200);
        $payload = (array) ($body['subscription'] ?? []);

        Assert::assertArrayHasKey(
            'can_upgrade',
            $payload,
            'view payload must expose can_upgrade so the UI can show or hide the "Upgrade plan" action'
        );
        // Both ends of Subscription::canUpgrade() — a boolean-type assert alone
        // accepts an inverted or hard-coded value (review-gate round 6). No
        // variant_upgrade_path meta exists for this fixture, so the flag must
        // be exactly false…
        Assert::assertFalse(
            $payload['can_upgrade'],
            'can_upgrade must be false while the variation has no variant_upgrade_path meta'
        );

        // …and configuring the path on an ACTIVE subscription must flip it to
        // true (canUpgrade requires the meta AND active/trialing status).
        \FluentCart\App\Models\Meta::query()->create([
            'meta_key'    => 'variant_upgrade_path',
            'object_id'   => (int) $subscription->variation_id,
            'object_type' => 'product_variation',
            'meta_value'  => ['paths' => [['variants' => []]]],
        ]);
        [, $bodyWithPath] = $I->get('/fluent-cart/v2/subscriptions/' . (int) $subscription->id);
        Assert::assertTrue(
            (bool) (((array) ($bodyWithPath['subscription'] ?? []))['can_upgrade'] ?? false),
            'can_upgrade must be true once variant_upgrade_path meta exists for an active subscription'
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [17]
     *   "The single-subscription view exposes the reminder permissions that admins are
     *    allowed to manage for this subscription."
     */
    public function singleSubscriptionViewExposesReminderPermissions(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        // ReminderService::getSubscriptionReminderPermissions reads three sources to compute
        // the two flags:
        //   reminders_enabled store-setting + subscription.status + subscription.next_billing_date
        //   + per-event "active" flag in fluent_cart_store_settings.notification_config
        //
        // Seed three subscriptions that cover the three observable outcomes of the production
        // computation, then assert the exact (true/false) value of each flag on each row.
        // A hardcoded "always false" implementation would fail the renewal-on-active row;
        // a hardcoded "always true" implementation would fail the renewal-on-no-next-billing
        // row. Both flags exercised in true AND false states.
        $previousStoreSettings = get_option('fluent_cart_store_settings', []);
        update_option('fluent_cart_store_settings', array_merge(
            (array) $previousStoreSettings,
            ['reminders_enabled' => 'yes']
        ));
        StoreSettings::clearCache();

        try {
            // Active subscription with a next_billing_date → renewal allowed, trial-end not allowed.
            $activeWithBilling = $this->buildSubscription([
                'item_name'         => 'Reminder Probe — Active With Billing',
                'status'            => Status::SUBSCRIPTION_ACTIVE,
                'next_billing_date' => gmdate('Y-m-d H:i:s', strtotime('+7 days')),
            ]);

            // True trial (not simulated) with a next_billing_date → trial-end allowed, renewal not allowed.
            $realTrial = $this->buildSubscription([
                'item_name'         => 'Reminder Probe — Real Trial',
                'status'            => Status::SUBSCRIPTION_TRIALING,
                'next_billing_date' => gmdate('Y-m-d H:i:s', strtotime('+3 days')),
                'config'            => [],
            ]);

            // Canceled subscription — status is neither ACTIVE nor TRIALING, so both
            // permission flags must be false regardless of next_billing_date.
            $canceled = $this->buildSubscription([
                'item_name'         => 'Reminder Probe — Canceled',
                'status'            => Status::SUBSCRIPTION_CANCELED,
                'next_billing_date' => gmdate('Y-m-d H:i:s', strtotime('+7 days')),
            ]);

            // Row 1 — active + next_billing_date → canSendRenewal=true, canSendTrialEnd=false.
            [, $bodyActive] = $I->get('/fluent-cart/v2/subscriptions/' . (int) $activeWithBilling->id);
            $I->seeResponseCodeIs(200);
            Assert::assertArrayHasKey(
                'reminder_permissions',
                (array) $bodyActive,
                'active-with-billing view response must include reminder_permissions envelope'
            );
            $activePerm = (array) $bodyActive['reminder_permissions'];
            Assert::assertSame(
                true,
                $activePerm['canSendRenewal'] ?? null,
                'canSendRenewal must be true for an ACTIVE subscription with next_billing_date and reminders enabled'
            );
            Assert::assertSame(
                false,
                $activePerm['canSendTrialEnd'] ?? null,
                'canSendTrialEnd must be false for an ACTIVE (non-trialing) subscription'
            );

            // Row 2 — trialing + next_billing_date, not simulated → canSendRenewal=false, canSendTrialEnd=true.
            [, $bodyTrial] = $I->get('/fluent-cart/v2/subscriptions/' . (int) $realTrial->id);
            $I->seeResponseCodeIs(200);
            $trialPerm = (array) $bodyTrial['reminder_permissions'];
            Assert::assertSame(
                false,
                $trialPerm['canSendRenewal'] ?? null,
                'canSendRenewal must be false for a real (non-simulated) trialing subscription — only ACTIVE or simulated-trial qualify'
            );
            Assert::assertSame(
                true,
                $trialPerm['canSendTrialEnd'] ?? null,
                'canSendTrialEnd must be true for a real TRIALING subscription with next_billing_date and reminders enabled'
            );

            // Row 3 — canceled subscription → both flags must be false because the status
            // is neither ACTIVE (renewal gate) nor TRIALING (trial-end gate).
            [, $bodyCanceled] = $I->get('/fluent-cart/v2/subscriptions/' . (int) $canceled->id);
            $I->seeResponseCodeIs(200);
            $canceledPerm = (array) $bodyCanceled['reminder_permissions'];
            Assert::assertSame(
                false,
                $canceledPerm['canSendRenewal'] ?? null,
                'canSendRenewal must be false for a CANCELED subscription — only ACTIVE (or simulated trial) qualifies'
            );
            Assert::assertSame(
                false,
                $canceledPerm['canSendTrialEnd'] ?? null,
                'canSendTrialEnd must be false for a CANCELED subscription — only TRIALING (non-simulated) qualifies'
            );
        } finally {
            update_option('fluent_cart_store_settings', $previousStoreSettings);
            StoreSettings::clearCache();
        }
    }
}

<?php

namespace Tests\Integration\Modules\PaymentMethods;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\IPN;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\SubscriptionManager;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Traits\BuildsSubscriptions;
use Tests\Support\Traits\MocksPayPalHttp;
use Tests\Support\WpDieCapture;

/**
 * SCAN-01: a switch to PayPal accepts only a PayPal subscription on the plan the
 * store issued for that switch. Before, any subscription on the merchant account
 * was accepted and the original agreement was cancelled.
 */
class PayPalSubscriptionSwitchTest extends DatabaseTestCase
{
    use BuildsSubscriptions;
    use MocksPayPalHttp;

    private string $oldVendorId;
    private string $newVendorId;

    public function setUp(): void
    {
        parent::setUp();
        $this->installPayPalHttpMock();

        $uniq = strtoupper(substr(md5(uniqid('', true)), 0, 8));
        $this->oldVendorId = 'I-OLD' . $uniq;
        $this->newVendorId = 'I-NEW' . $uniq;
    }

    public function tearDown(): void
    {
        $this->removePayPalHttpMock();
        parent::tearDown();
    }

    private function buildSwitchableSubscription(): Subscription
    {
        [, , , $subscription] = $this->buildSubscriptionChain([
            'status'                 => Status::SUBSCRIPTION_ACTIVE,
            'current_payment_method' => 'paypal',
            'vendor_subscription_id' => $this->oldVendorId,
            'vendor_plan_id'         => 'P-ORIGINAL',
            'billing_interval'       => 'monthly',
            'recurring_amount'       => Helper::toCent(100),
            'recurring_total'        => Helper::toCent(100),
            'next_billing_date'      => gmdate('Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS),
        ], [], [
            'payment_method' => 'paypal',
            'status'         => Status::ORDER_COMPLETED,
            'payment_status' => Status::PAYMENT_PAID,
        ]);

        // Cancel looks the old agreement up first; it must be live so a wrongly
        // reached cancel actually sends the cancel request the tests count.
        $this->scriptPayPal('billing/subscriptions/' . $this->oldVendorId, 200, [
            'id'     => $this->oldVendorId,
            'status' => 'ACTIVE',
        ]);

        return $subscription;
    }

    private function scriptNewPayPalSubscription(string $planId, string $status = 'ACTIVE', ?string $customId = null, ?int $nextBillingTime = null): void
    {
        $this->scriptPayPal('billing/subscriptions/' . $this->newVendorId, 200, [
            'id'           => $this->newVendorId,
            'plan_id'      => $planId,
            'status'       => $status,
            'custom_id'    => $customId,
            'subscriber'   => ['payer_id' => 'PAYER1', 'email_address' => 'buyer@example.com'],
            'billing_info' => ['next_billing_time' => gmdate('Y-m-d\TH:i:s\Z', $nextBillingTime ?: strtotime('+1 month'))],
        ]);
    }

    private function buildIssuedSwitch(string $status = 'ACTIVE', ?int $nextBillingTime = null): Subscription
    {
        $subscription = $this->buildSwitchableSubscription();
        $subscription->updateMeta('paypal_switch_plan_id', 'P-ISSUED');
        $this->scriptNewPayPalSubscription('P-ISSUED', $status, $subscription->uuid, $nextBillingTime);

        return $subscription;
    }

    private function newSubscriptionCancelCount(): int
    {
        return $this->payPalRequestCount('billing/subscriptions/' . $this->newVendorId . '/cancel');
    }

    private function oldSubscriptions(Subscription $subscription): array
    {
        $old = Subscription::query()->find($subscription->id)->getMeta('old_subscriptions');

        return is_string($old) ? (array)json_decode($old, true) : (array)$old;
    }

    private function confirm(Subscription $subscription): array
    {
        return WpDieCapture::capture(function () use ($subscription) {
            (new SubscriptionManager())->confirmSubscriptionSwitch([
                'newVendorSubscriptionId' => $this->newVendorId,
                'vendorOrderId'           => 'PPORDER1',
            ], $subscription->id);
        });
    }

    private function assertSwitchRefused(array $result, Subscription $subscription, string $why, bool $newCancelled = false): void
    {
        $this->assertGreaterThanOrEqual(400, $result['status'], $why . ' Response: ' . $result['body']);

        $fresh = Subscription::query()->find($subscription->id);
        $this->assertSame($this->oldVendorId, $fresh->vendor_subscription_id, 'a refused switch must leave the local subscription on its original agreement');
        $this->assertSame(0, $this->payPalRequestCount('billing/subscriptions/' . $this->oldVendorId . '/cancel'), 'a refused switch must not cancel the original agreement');
        $this->assertSame(
            $newCancelled ? 1 : 0,
            $this->newSubscriptionCancelCount(),
            $newCancelled ? 'our unusable new agreement must be cancelled so it never bills' : 'a PayPal subscription not proven ours must never be cancelled'
        );
    }

    public function test_rejects_paypal_subscription_on_a_different_plan(): void
    {
        $subscription = $this->buildSwitchableSubscription();
        $subscription->updateMeta('paypal_switch_plan_id', 'P-ISSUED');
        $this->scriptNewPayPalSubscription('P-SOME-OTHER-PLAN');

        $this->assertSwitchRefused($this->confirm($subscription), $subscription, 'a PayPal subscription on another plan must be refused.');
    }

    public function test_rejects_switch_when_no_plan_was_issued(): void
    {
        $subscription = $this->buildSwitchableSubscription();
        $this->scriptNewPayPalSubscription('P-ISSUED');

        $this->assertSwitchRefused($this->confirm($subscription), $subscription, 'a confirm without a store-issued switch plan must be refused.');
    }

    public function test_rejects_paypal_subscription_already_linked_to_another_subscription(): void
    {
        $subscription = $this->buildSwitchableSubscription();
        $subscription->updateMeta('paypal_switch_plan_id', 'P-ISSUED');
        $this->scriptNewPayPalSubscription('P-ISSUED');

        $this->buildSubscription(['vendor_subscription_id' => $this->newVendorId, 'current_payment_method' => 'paypal']);

        $this->assertSwitchRefused($this->confirm($subscription), $subscription, 'a PayPal subscription already used by another subscription must be refused.');
    }

    public function test_rejects_paypal_subscription_that_is_not_active(): void
    {
        $subscription = $this->buildSwitchableSubscription();
        $subscription->updateMeta('paypal_switch_plan_id', 'P-ISSUED');
        $this->scriptNewPayPalSubscription('P-ISSUED', 'APPROVAL_PENDING', $subscription->uuid);

        $this->assertSwitchRefused($this->confirm($subscription), $subscription, 'a PayPal subscription the buyer has not approved must be refused.', true);
    }

    public function test_accepts_paypal_subscription_on_the_issued_plan_once(): void
    {
        $subscription = $this->buildSwitchableSubscription();
        $subscription->updateMeta('paypal_switch_plan_id', 'P-ISSUED');
        $this->scriptNewPayPalSubscription('P-ISSUED', 'ACTIVE', $subscription->uuid);

        $result = $this->confirm($subscription);

        $this->assertSame(200, $result['status'], 'the PayPal subscription on the issued plan must be accepted. Response: ' . $result['body']);
        $fresh = Subscription::query()->find($subscription->id);
        $this->assertSame($this->newVendorId, $fresh->vendor_subscription_id, 'the local subscription must move to the new PayPal agreement');
        $this->assertSame(1, $this->payPalRequestCount('billing/subscriptions/' . $this->oldVendorId . '/cancel'), 'the original agreement must be cancelled once');
        $this->assertEmpty($fresh->getMeta('paypal_switch_plan_id'), 'the issued switch plan must be single-use');

        $old = $this->oldSubscriptions($subscription);
        $this->assertCount(1, $old);
        $this->assertSame($this->oldVendorId, $old[0]['vendor_subscription_id'] ?? null);
    }

    public function test_approved_subscription_is_accepted_as_active(): void
    {
        $subscription = $this->buildIssuedSwitch('APPROVED');

        $result = $this->confirm($subscription);

        $this->assertSame(200, $result['status'], $result['body']);
        $fresh = Subscription::query()->find($subscription->id);
        $this->assertSame($this->newVendorId, $fresh->vendor_subscription_id);
        $this->assertSame(Status::SUBSCRIPTION_ACTIVE, $fresh->status, 'PayPal APPROVED is not a local status');
    }

    public function test_rejects_paypal_subscription_made_for_another_store_subscription(): void
    {
        $subscription = $this->buildSwitchableSubscription();
        $subscription->updateMeta('paypal_switch_plan_id', 'P-ISSUED');
        $this->scriptNewPayPalSubscription('P-ISSUED', 'ACTIVE', 'some-other-uuid');

        $this->assertSwitchRefused($this->confirm($subscription), $subscription, 'a PayPal subscription carrying another custom_id must be refused.');
    }

    public function test_suspended_new_subscription_is_cancelled_and_refused(): void
    {
        $subscription = $this->buildIssuedSwitch('SUSPENDED');

        $this->assertSwitchRefused($this->confirm($subscription), $subscription, 'a suspended new agreement must be refused.', true);
    }

    public function test_new_subscription_billing_before_the_paid_period_ends_is_cancelled_and_refused(): void
    {
        $subscription = $this->buildIssuedSwitch('ACTIVE', time() + 2 * DAY_IN_SECONDS);

        $this->assertSwitchRefused($this->confirm($subscription), $subscription, 'a new agreement that bills before next_billing_date must be refused.', true);
    }

    public function test_second_confirm_succeeds_without_cancelling_again(): void
    {
        $subscription = $this->buildIssuedSwitch();
        $this->assertSame(200, $this->confirm($subscription)['status']);

        $result = $this->confirm($subscription);

        $this->assertSame(200, $result['status'], 'a repeated confirm of the same agreement is a success. Response: ' . $result['body']);
        $this->assertSame(1, $this->payPalRequestCount('billing/subscriptions/' . $this->oldVendorId . '/cancel'));
        $this->assertSame(0, $this->newSubscriptionCancelCount());
        $this->assertCount(1, $this->oldSubscriptions($subscription));
    }

    public function test_cancel_webhook_of_an_unlinked_agreement_leaves_the_subscription_alone(): void
    {
        $subscription = $this->buildSwitchableSubscription();

        (new IPN())->handleWebhookRecurringProfileCancelled(['paypal_subscription' => [
            'id'                 => $this->newVendorId,
            'custom_id'          => $subscription->uuid,
            'status'             => 'CANCELLED',
            'status_update_time' => gmdate('Y-m-d\TH:i:s\Z'),
        ]]);

        $this->assertSame(Status::SUBSCRIPTION_ACTIVE, Subscription::query()->find($subscription->id)->status, 'a CANCELLED event for an agreement the row is not linked to must not cancel the row');
    }

    public function test_suspend_and_expire_webhooks_of_an_unlinked_agreement_leave_a_stripe_subscription_alone(): void
    {
        [, , , $subscription] = $this->buildSubscriptionChain([
            'status'                 => Status::SUBSCRIPTION_ACTIVE,
            'current_payment_method' => 'stripe',
            'vendor_subscription_id' => 'sub_STRIPE',
        ]);
        $event = ['paypal_subscription' => ['id' => $this->oldVendorId, 'custom_id' => $subscription->uuid]];

        (new IPN())->handleWebhookRecurringProfileSuspended($event);
        $this->assertSame(Status::SUBSCRIPTION_ACTIVE, Subscription::query()->find($subscription->id)->status);

        (new IPN())->handleWebhookRecurringProfileExpired($event);
        $this->assertSame(Status::SUBSCRIPTION_ACTIVE, Subscription::query()->find($subscription->id)->status);
    }

    public function test_issuing_a_switch_plan_records_it_on_the_subscription(): void
    {
        $subscription = $this->buildSwitchableSubscription();
        $this->scriptPayPal('catalogs/products', 200, ['id' => 'PROD-SWITCH']);
        $this->scriptPayPal('billing/plans', 201, ['id' => 'P-ISSUED-NOW', 'status' => 'ACTIVE']);

        $result = WpDieCapture::capture(function () use ($subscription) {
            (new SubscriptionManager())->getOrCreateNewPlan($subscription->id, 'switch');
        });

        $this->assertSame(200, $result['status'], 'issuing the switch plan must succeed. Response: ' . $result['body']);
        $this->assertSame('P-ISSUED-NOW', Subscription::query()->find($subscription->id)->getMeta('paypal_switch_plan_id'), 'the store must remember which plan it issued for this switch');
    }
}

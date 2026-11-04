<?php

namespace Tests\Integration\Modules\PaymentMethods;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Subscription;
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

    private function scriptNewPayPalSubscription(string $planId, string $status = 'ACTIVE'): void
    {
        $this->scriptPayPal('billing/subscriptions/' . $this->newVendorId, 200, [
            'id'           => $this->newVendorId,
            'plan_id'      => $planId,
            'status'       => $status,
            'subscriber'   => ['payer_id' => 'PAYER1', 'email_address' => 'buyer@example.com'],
            'billing_info' => ['next_billing_time' => gmdate('Y-m-d\TH:i:s\Z', strtotime('+1 month'))],
        ]);
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

    private function assertSwitchRefused(array $result, Subscription $subscription, string $why): void
    {
        $this->assertGreaterThanOrEqual(400, $result['status'], $why . ' Response: ' . $result['body']);

        $fresh = Subscription::query()->find($subscription->id);
        $this->assertSame($this->oldVendorId, $fresh->vendor_subscription_id, 'a refused switch must leave the local subscription on its original agreement');
        $this->assertSame(0, $this->payPalRequestCount('/cancel'), 'a refused switch must not cancel the original agreement');
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
        $this->scriptNewPayPalSubscription('P-ISSUED', 'APPROVAL_PENDING');

        $this->assertSwitchRefused($this->confirm($subscription), $subscription, 'a PayPal subscription the buyer has not approved must be refused.');
    }

    public function test_accepts_paypal_subscription_on_the_issued_plan_once(): void
    {
        $subscription = $this->buildSwitchableSubscription();
        $subscription->updateMeta('paypal_switch_plan_id', 'P-ISSUED');
        $this->scriptNewPayPalSubscription('P-ISSUED');

        $result = $this->confirm($subscription);

        $this->assertSame(200, $result['status'], 'the PayPal subscription on the issued plan must be accepted. Response: ' . $result['body']);
        $fresh = Subscription::query()->find($subscription->id);
        $this->assertSame($this->newVendorId, $fresh->vendor_subscription_id, 'the local subscription must move to the new PayPal agreement');
        $this->assertSame(1, $this->payPalRequestCount('billing/subscriptions/' . $this->oldVendorId . '/cancel'), 'the original agreement must be cancelled once');
        $this->assertEmpty($fresh->getMeta('paypal_switch_plan_id'), 'the issued switch plan must be single-use');
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

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
 * A customer who approves the PayPal switch and closes the tab before the
 * browser confirms leaves PayPal billing the new subscription while the old
 * one keeps billing. The ACTIVATED webhook finishes that switch.
 */
class PayPalSwitchWebhookFinishTest extends DatabaseTestCase
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

    private function buildIssuedSwitch(): Subscription
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

        $subscription->updateMeta('paypal_switch_plan_id', 'P-ISSUED');

        $this->scriptPayPal('billing/subscriptions/' . $this->oldVendorId, 200, [
            'id'     => $this->oldVendorId,
            'status' => 'ACTIVE',
        ]);

        return $subscription;
    }

    private function newPayPalSubscription(Subscription $subscription, string $planId = 'P-ISSUED', ?int $nextBillingTime = null): array
    {
        return [
            'id'           => $this->newVendorId,
            'plan_id'      => $planId,
            'status'       => 'ACTIVE',
            'custom_id'    => $subscription->uuid,
            'subscriber'   => ['payer_id' => 'PAYER1', 'email_address' => 'buyer@example.com'],
            'billing_info' => ['next_billing_time' => gmdate('Y-m-d\TH:i:s\Z', $nextBillingTime ?: strtotime('+1 month'))],
        ];
    }

    private function activatedWebhook(array $paypalSubscription, ?array $livePayPalSubscription = null): void
    {
        $this->scriptPayPal('billing/subscriptions/' . $this->newVendorId, 200, $livePayPalSubscription ?: $paypalSubscription);
        (new IPN())->processSubscriptionActivated(['paypal_subscription' => $paypalSubscription]);
    }

    private function browserConfirm(Subscription $subscription): array
    {
        return WpDieCapture::capture(function () use ($subscription) {
            (new SubscriptionManager())->confirmSubscriptionSwitch([
                'newVendorSubscriptionId' => $this->newVendorId,
                'vendorOrderId'           => 'PPORDER1',
            ], $subscription->id);
        });
    }

    private function oldCancelCount(): int
    {
        return $this->payPalRequestCount('billing/subscriptions/' . $this->oldVendorId . '/cancel');
    }

    private function newCancelCount(): int
    {
        return $this->payPalRequestCount('billing/subscriptions/' . $this->newVendorId . '/cancel');
    }

    public function test_activated_webhook_finishes_a_switch_the_browser_never_confirmed(): void
    {
        $subscription = $this->buildIssuedSwitch();

        $this->activatedWebhook($this->newPayPalSubscription($subscription));

        $fresh = Subscription::query()->find($subscription->id);
        $this->assertSame($this->newVendorId, $fresh->vendor_subscription_id);
        $this->assertSame(Status::SUBSCRIPTION_ACTIVE, $fresh->status);
        $this->assertSame(1, $this->oldCancelCount(), 'the replaced agreement must be cancelled so it stops billing');
        $this->assertSame(0, $this->newCancelCount());
        $this->assertEmpty($fresh->getMeta('paypal_switch_plan_id'));
    }

    public function test_activated_webhook_on_another_plan_leaves_the_subscription_alone(): void
    {
        $subscription = $this->buildIssuedSwitch();

        $this->activatedWebhook($this->newPayPalSubscription($subscription, 'P-SOME-OTHER-PLAN'));

        $fresh = Subscription::query()->find($subscription->id);
        $this->assertSame($this->oldVendorId, $fresh->vendor_subscription_id);
        $this->assertSame(0, $this->oldCancelCount());
        $this->assertSame(0, $this->newCancelCount(), 'a PayPal subscription not proven ours must never be cancelled');
        $this->assertSame('P-ISSUED', $fresh->getMeta('paypal_switch_plan_id'));
    }

    public function test_activated_webhook_billing_before_the_paid_period_ends_cancels_the_new_subscription(): void
    {
        $subscription = $this->buildIssuedSwitch();

        $this->activatedWebhook($this->newPayPalSubscription($subscription, 'P-ISSUED', time() + DAY_IN_SECONDS));

        $fresh = Subscription::query()->find($subscription->id);
        $this->assertSame($this->oldVendorId, $fresh->vendor_subscription_id);
        $this->assertSame(0, $this->oldCancelCount());
        $this->assertSame(1, $this->newCancelCount(), 'our unusable new agreement must be cancelled so it never bills');
    }

    public function test_activated_webhook_after_the_browser_confirmed_does_not_cancel_again(): void
    {
        $subscription = $this->buildIssuedSwitch();
        $paypalSubscription = $this->newPayPalSubscription($subscription);
        $this->scriptPayPal('billing/subscriptions/' . $this->newVendorId, 200, $paypalSubscription);

        $result = WpDieCapture::capture(function () use ($subscription) {
            (new SubscriptionManager())->confirmSubscriptionSwitch([
                'newVendorSubscriptionId' => $this->newVendorId,
                'vendorOrderId'           => 'PPORDER1',
            ], $subscription->id);
        });
        $this->assertSame(200, $result['status'], $result['body']);

        $this->activatedWebhook($paypalSubscription);

        $fresh = Subscription::query()->find($subscription->id);
        $this->assertSame($this->newVendorId, $fresh->vendor_subscription_id);
        $this->assertSame(1, $this->oldCancelCount());
        $this->assertSame(0, $this->newCancelCount());
    }

    public function test_activated_webhook_answers_500_while_the_browser_holds_the_switch_lock(): void
    {
        $subscription = $this->buildIssuedSwitch();
        $body = json_encode([
            'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
            'resource'   => $this->newPayPalSubscription($subscription),
        ]);

        $this->scriptPayPal('billing/subscriptions/' . $this->newVendorId, 200, $this->newPayPalSubscription($subscription));
        add_filter('fluent_cart/payments/paypal/verify_webhook', '__return_false');
        (new IPN())->init();

        $rival = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        $lockName = 'fc_sub_switch_' . $subscription->id;
        try {
            $row = mysqli_fetch_row(mysqli_query($rival, "SELECT GET_LOCK('" . mysqli_real_escape_string($rival, $lockName) . "', 0)"));
            $this->assertSame('1', (string) $row[0], 'the rival must hold the switch lock');

            $status = (new IPN())->handleWebhookRequest($body);

            $this->assertSame(500, $status, 'a busy switch must answer non-2xx so PayPal redelivers');
            $fresh = Subscription::query()->find($subscription->id);
            $this->assertSame($this->oldVendorId, $fresh->vendor_subscription_id);
            $this->assertSame(0, $this->oldCancelCount());

            mysqli_query($rival, "SELECT RELEASE_LOCK('" . mysqli_real_escape_string($rival, $lockName) . "')");

            $this->assertSame(200, (new IPN())->handleWebhookRequest($body), 'the redelivery finishes the switch');
        } finally {
            mysqli_close($rival);
            remove_filter('fluent_cart/payments/paypal/verify_webhook', '__return_false');
        }

        $fresh = Subscription::query()->find($subscription->id);
        $this->assertSame($this->newVendorId, $fresh->vendor_subscription_id);
        $this->assertSame(1, $this->oldCancelCount());
    }

    private function deliverActivated(Subscription $subscription, int $liveStatus, array $liveBody): int
    {
        $body = json_encode([
            'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
            'resource'   => $this->newPayPalSubscription($subscription),
        ]);
        $this->scriptPayPal('billing/subscriptions/' . $this->newVendorId, $liveStatus, $liveBody);
        add_filter('fluent_cart/payments/paypal/verify_webhook', '__return_false');
        try {
            return (new IPN())->handleWebhookRequest($body);
        } finally {
            remove_filter('fluent_cart/payments/paypal/verify_webhook', '__return_false');
        }
    }

    public function test_activated_webhook_answers_500_when_the_live_read_fails(): void
    {
        $subscription = $this->buildIssuedSwitch();

        $status = $this->deliverActivated($subscription, 500, ['name' => 'INTERNAL_SERVICE_ERROR', 'message' => 'down']);

        $this->assertSame(500, $status, 'an unconfirmed switch must be redelivered');
        $this->assertSame($this->oldVendorId, Subscription::query()->find($subscription->id)->vendor_subscription_id);
        $this->assertSame(0, $this->oldCancelCount());
        $this->assertSame(0, $this->newCancelCount());
    }

    public function test_activated_webhook_acks_a_new_subscription_paypal_no_longer_has(): void
    {
        $subscription = $this->buildIssuedSwitch();

        $status = $this->deliverActivated($subscription, 404, ['name' => 'RESOURCE_NOT_FOUND', 'message' => 'gone']);

        $this->assertSame(200, $status, 'a permanent miss must not be redelivered');
        $this->assertSame($this->oldVendorId, Subscription::query()->find($subscription->id)->vendor_subscription_id);
        $this->assertSame(0, $this->oldCancelCount());
        $this->assertSame(0, $this->newCancelCount());
    }

    public function test_browser_confirm_of_an_already_cancelled_new_subscription_does_not_cancel_it_again(): void
    {
        $subscription = $this->buildIssuedSwitch();
        $paypalSubscription = $this->newPayPalSubscription($subscription);
        $paypalSubscription['status'] = 'CANCELLED';
        $this->scriptPayPal('billing/subscriptions/' . $this->newVendorId, 200, $paypalSubscription);

        $result = WpDieCapture::capture(function () use ($subscription) {
            (new SubscriptionManager())->confirmSubscriptionSwitch([
                'newVendorSubscriptionId' => $this->newVendorId,
                'vendorOrderId'           => 'PPORDER1',
            ], $subscription->id);
        });

        $this->assertNotSame(200, $result['status'], $result['body']);
        $this->assertSame(0, $this->newCancelCount(), 'a cancelled PayPal subscription never bills, so it is not cancelled again');
        $fresh = Subscription::query()->find($subscription->id);
        $this->assertSame($this->oldVendorId, $fresh->vendor_subscription_id);
        $this->assertSame(0, $this->oldCancelCount());
    }

    public function test_activated_webhook_after_a_browser_refusal_reads_the_live_subscription(): void
    {
        $subscription = $this->buildIssuedSwitch();
        $snapshot = $this->newPayPalSubscription($subscription);
        $suspended = $snapshot;
        $suspended['status'] = 'SUSPENDED';
        $this->scriptPayPal('billing/subscriptions/' . $this->newVendorId, 200, $suspended);

        $result = $this->browserConfirm($subscription);
        $this->assertNotSame(200, $result['status'], $result['body']);
        $this->assertSame(1, $this->newCancelCount());

        $cancelled = $snapshot;
        $cancelled['status'] = 'CANCELLED';
        $this->activatedWebhook($snapshot, $cancelled);

        $fresh = Subscription::query()->find($subscription->id);
        $this->assertSame($this->oldVendorId, $fresh->vendor_subscription_id, 'a switch the browser refused must not be finished from the event snapshot');
        $this->assertSame(0, $this->oldCancelCount());
        $this->assertSame(1, $this->newCancelCount(), 'the cancelled subscription is not cancelled again');
    }

    public function test_activated_webhook_after_an_early_billing_refusal_does_not_cancel_again(): void
    {
        $subscription = $this->buildIssuedSwitch();
        $early = $this->newPayPalSubscription($subscription, 'P-ISSUED', time() + DAY_IN_SECONDS);
        $this->scriptPayPal('billing/subscriptions/' . $this->newVendorId, 200, $early);

        $result = $this->browserConfirm($subscription);
        $this->assertNotSame(200, $result['status'], $result['body']);
        $this->assertSame(1, $this->newCancelCount());

        $cancelled = $early;
        $cancelled['status'] = 'CANCELLED';
        $this->activatedWebhook($early, $cancelled);

        $this->assertSame(1, $this->newCancelCount());
        $this->assertSame($this->oldVendorId, Subscription::query()->find($subscription->id)->vendor_subscription_id);
    }
}

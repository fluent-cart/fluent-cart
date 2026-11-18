<?php

namespace Tests\Integration\Models;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\SubscriptionManager;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Stripe;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\SwitchCustomerMethod;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Traits\BuildsSubscriptions;

/**
 * Issue #3480: a switch, or a PayPal update (which recreates the PayPal
 * subscription), is free only while paid days are left. With none left the new
 * subscription would start with no trial and charge at once, so it is blocked
 * up front. The Stripe card update creates no subscription and stays open.
 * Each gateway decides through its own can* overrides; the base says no.
 */
class SubscriptionPaymentMethodChangeGateTest extends DatabaseTestCase
{
    use BuildsSubscriptions;

    private function subscription(string $method, array $overrides = []): Subscription
    {
        $sub = $this->buildSubscription(array_merge([
            'status'                 => Status::SUBSCRIPTION_ACTIVE,
            'current_payment_method' => $method,
            'collection_method'      => 'automatic',
            'billing_interval'       => 'monthly',
            'bill_count'             => 1,
            'next_billing_date'      => gmdate('Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS),
        ], $overrides), [], ['payment_method' => $method]);

        return Subscription::query()->find($sub->id);
    }

    private function past(): string
    {
        return gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
    }

    public function test_paypal_with_no_paid_days_left_cannot_switch_or_update(): void
    {
        $sub = $this->subscription('paypal', ['next_billing_date' => $this->past()]);

        $this->assertFalse($sub->canSwitchPaymentMethod());
        $this->assertTrue($sub->canSwitchPaymentMethod(false));
        $this->assertFalse($sub->canUpdatePaymentMethod());
        $this->assertSame([], $sub->switchablePaymentMethods());
    }

    public function test_paypal_with_no_paid_days_left_without_pro_gets_no_renew_link(): void
    {
        $sub = $this->subscription('paypal', ['next_billing_date' => $this->past()]);

        $this->assertFalse($sub->canReactivate());
        $this->assertSame('', $sub->getReactivateUrl());
    }

    public function test_stripe_with_no_paid_days_left_cannot_switch_but_keeps_card_update(): void
    {
        $sub = $this->subscription('stripe', ['next_billing_date' => $this->past()]);

        $this->assertFalse($sub->canSwitchPaymentMethod());
        $this->assertTrue($sub->canUpdatePaymentMethod());
    }

    public function test_failing_subscription_in_grace(): void
    {
        $stripe = $this->subscription('stripe', ['status' => Status::SUBSCRIPTION_FAILING, 'next_billing_date' => $this->past()]);
        $paypal = $this->subscription('paypal', ['status' => Status::SUBSCRIPTION_FAILING, 'next_billing_date' => $this->past()]);

        $this->assertTrue($stripe->canUpdatePaymentMethod());
        $this->assertFalse($stripe->canSwitchPaymentMethod());
        $this->assertFalse($paypal->canUpdatePaymentMethod());
        $this->assertFalse($paypal->canSwitchPaymentMethod());
    }

    public function test_loophole_zeroed_trial_is_blocked(): void
    {
        // 90 days old, one bill: three cycles expected, so the loophole check zeroes the trial.
        $sub = $this->subscription('paypal');
        Subscription::query()->where('id', $sub->id)->update([
            'created_at' => gmdate('Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS),
        ]);
        $sub = Subscription::query()->find($sub->id);

        $this->assertSame(0, $sub->paymentMethodChangeTrialDays());
        $this->assertFalse($sub->canSwitchPaymentMethod());
        $this->assertFalse($sub->canUpdatePaymentMethod());
    }

    public function test_paid_days_left_allows_switch_and_update(): void
    {
        $sub = $this->subscription('paypal');

        $this->assertSame(10, $sub->paymentMethodChangeTrialDays());
        $this->assertTrue($sub->canSwitchPaymentMethod());
        $this->assertTrue($sub->canUpdatePaymentMethod());
        $this->assertSame(['stripe'], $sub->switchablePaymentMethods());
    }

    public function test_store_billed_subscription_cannot_switch(): void
    {
        foreach (['stripe', 'paypal'] as $method) {
            foreach (['system', 'manual'] as $collection) {
                $sub = $this->subscription($method, ['collection_method' => $collection]);

                $this->assertFalse($sub->canSwitchPaymentMethod(false), "$method $collection switch");
                $this->assertSame([], $sub->switchablePaymentMethods(), "$method $collection switchable");
            }
        }
    }

    public function test_ended_subscription_cannot_update_or_switch(): void
    {
        foreach (['stripe', 'paypal'] as $method) {
            foreach ([Status::SUBSCRIPTION_CANCELED, Status::SUBSCRIPTION_EXPIRED, Status::SUBSCRIPTION_COMPLETED] as $status) {
                $sub = $this->subscription($method, ['status' => $status]);

                $this->assertFalse($sub->canUpdatePaymentMethod(), "$method $status update");
                $this->assertFalse($sub->canSwitchPaymentMethod(false), "$method $status switch");
            }
        }
    }

    public function test_stripe_with_paid_days_left_switches_to_paypal_only(): void
    {
        $sub = $this->subscription('stripe');

        $this->assertTrue($sub->canSwitchPaymentMethod());
        $this->assertSame(['paypal'], $sub->switchablePaymentMethods());
    }

    public function test_gateway_override_decides_switch(): void
    {
        $manager = GatewayManager::getInstance();
        $ref = new \ReflectionProperty(GatewayManager::class, 'gateways');
        $ref->setAccessible(true);
        $before = $ref->getValue($manager);

        $stub = new class extends Stripe {
            public $allowSwitch = true;

            public function canSwitchSubscriptionPaymentMethod(Subscription $subscription, bool $requirePaidDays = true): bool
            {
                return $this->allowSwitch || !$requirePaidDays;
            }
        };

        try {
            $manager->register('stripe', $stub);

            $stub->allowSwitch = false;
            $this->assertFalse($this->subscription('stripe')->canSwitchPaymentMethod());
            $this->assertTrue($this->subscription('stripe')->canSwitchPaymentMethod(false));

            $stub->allowSwitch = true;
            $this->assertTrue($this->subscription('stripe', ['next_billing_date' => $this->past()])->canSwitchPaymentMethod());
        } finally {
            $ref->setValue($manager, $before);
        }
    }

    public function test_unknown_interval_does_not_divide_by_zero(): void
    {
        $sub = $this->subscription('paypal', ['billing_interval' => 'every_tenth_day']);

        $this->assertSame(10, $sub->paymentMethodChangeTrialDays());
    }

    public function test_stripe_switch_builder_throws_with_no_paid_days_left(): void
    {
        $sub = $this->subscription('stripe', ['next_billing_date' => $this->past()]);

        $this->expectException(\Exception::class);
        (new SwitchCustomerMethod())->getSubscriptionItem($sub, ProductVariation::query()->find($sub->variation_id));
    }

    public function test_stripe_switch_builder_sets_trial_end_to_next_billing_date(): void
    {
        $sub = $this->subscription('stripe');

        $item = (new SwitchCustomerMethod())->getSubscriptionItem($sub, ProductVariation::query()->find($sub->variation_id));

        $this->assertSame(10, (int) $item['trial_days']);
        $this->assertSame(strtotime($sub->next_billing_date . ' UTC'), $item['trial_end']);
    }

    public function test_paypal_update_builder_throws_with_no_paid_days_left(): void
    {
        $sub = $this->subscription('paypal', ['next_billing_date' => $this->past()]);

        $this->expectException(\Exception::class);
        (new SubscriptionManager())->getSubscriptionItemForUpdate($sub, ProductVariation::query()->find($sub->variation_id));
    }

    public function test_paypal_update_builder_caps_trial_at_365_days(): void
    {
        $sub = $this->subscription('paypal', [
            'billing_interval'  => 'yearly',
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() + 400 * DAY_IN_SECONDS),
        ]);

        $item = (new SubscriptionManager())->getSubscriptionItemForUpdate($sub, ProductVariation::query()->find($sub->variation_id));

        $this->assertSame(365, (int) $item['trial_days']);
    }

    /**
     * @dataProvider paymentMethodUpdates
     */
    public function test_payment_method_update_respects_billing_ownership(string $method, string $collectionMethod, bool $withPaidDays, bool $withoutPaidDays): void
    {
        foreach ([10 => $withPaidDays, -1 => $withoutPaidDays] as $paidDays => $expected) {
            $subscription = new Subscription([
                'status'                 => Status::SUBSCRIPTION_ACTIVE,
                'current_payment_method' => $method,
                'collection_method'      => $collectionMethod,
                'next_billing_date'      => gmdate('Y-m-d H:i:s', time() + $paidDays * DAY_IN_SECONDS),
            ]);

            $this->assertSame($expected, $subscription->canUpdatePaymentMethod(), $method . ' / ' . $collectionMethod . ' / paid days: ' . $paidDays);
        }
    }

    public function paymentMethodUpdates(): array
    {
        return [
            ['stripe', 'system', true, true],
            ['paypal', 'system', false, false],
            ['stripe', 'manual', false, false],
            ['paypal', 'manual', false, false],
            ['stripe', 'automatic', true, true],
            ['paypal', 'automatic', true, false],
        ];
    }
}

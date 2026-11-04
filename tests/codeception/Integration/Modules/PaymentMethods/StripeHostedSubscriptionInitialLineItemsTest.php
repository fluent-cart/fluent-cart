<?php

namespace Tests\Integration\Modules\PaymentMethods;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Processor;
use FluentCart\App\Services\Payments\PaymentInstance;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\SubscriptionFactory;

/**
 * The one-time part of a hosted subscription session bundles up to four unrelated
 * amounts (setup fee, one-time items, order fees, first-cycle delta) that Stripe
 * used to render under the subscription product's own name. These tests pin both
 * halves of the fix: the labels are the order's own, and the split still sums
 * EXACTLY to the initial amount — Stripe derives the charge from line_items, so a
 * breakdown that is one unit off mischarges silently.
 */
class StripeHostedSubscriptionInitialLineItemsTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private OrderItemFactory $itemFactory;
    private SubscriptionFactory $subscriptionFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
        $this->itemFactory = new OrderItemFactory();
        $this->subscriptionFactory = new SubscriptionFactory();
    }

    /**
     * @return array|null
     */
    private function build(PaymentInstance $instance, int $initialAmount, int $existingCount = 1)
    {
        $method = new \ReflectionMethod(Processor::class, 'buildHostedSubscriptionInitialLineItems');
        $method->setAccessible(true);

        return $method->invoke(new Processor(), $instance, 'usd', false, $initialAmount, $existingCount);
    }

    private function fallbackName(Order $order, ?Subscription $subscription): string
    {
        $method = new \ReflectionMethod(Processor::class, 'hostedSubscriptionInitialName');
        $method->setAccessible(true);

        return $method->invoke(new Processor(), $order, $subscription);
    }

    private function sumOf(array $lineItems): int
    {
        $sum = 0;
        foreach ($lineItems as $item) {
            $sum += (int) $item['price_data']['unit_amount'] * (int) $item['quantity'];
        }

        return $sum;
    }

    private function namesOf(array $lineItems): array
    {
        return array_map(function ($item) {
            return $item['price_data']['product_data']['name'];
        }, $lineItems);
    }

    /**
     * @param array $items    Order item overrides.
     * @param array $subAttrs Subscription overrides.
     */
    private function makeInstance(array $items, array $subAttrs = []): PaymentInstance
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'type'        => 'subscription',
            'currency'    => 'USD',
        ]);

        foreach ($items as $item) {
            $this->itemFactory->create($order->id, $item);
        }

        $this->subscriptionFactory->create(array_merge([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => 1,
            'variation_id'    => 1,
        ], $subAttrs));

        return new PaymentInstance(Order::query()->find($order->id));
    }

    public function test_setup_fee_line_uses_its_own_label_not_the_subscription_name(): void
    {
        $instance = $this->makeInstance([
            [
                'payment_type' => 'subscription',
                'post_title'   => 'Pro Plan',
                'title'        => 'Monthly',
                'line_total'   => 2999,
            ],
            [
                'payment_type' => 'signup_fee',
                'post_title'   => 'Pro Plan',
                'title'        => 'Onboarding & setup',
                'line_total'   => 5000,
            ],
        ]);

        $lineItems = $this->build($instance, 5000);

        $this->assertNotNull($lineItems);
        $this->assertCount(1, $lineItems);
        $this->assertSame(['Pro Plan Onboarding & setup'], $this->namesOf($lineItems));
        $this->assertSame(5000, $this->sumOf($lineItems));
    }

    public function test_subscription_line_is_never_duplicated_into_the_one_time_part(): void
    {
        $instance = $this->makeInstance([
            [
                'payment_type' => 'subscription',
                'post_title'   => 'Pro Plan',
                'title'        => 'Monthly',
                'line_total'   => 2999,
            ],
            [
                'payment_type' => 'signup_fee',
                'post_title'   => 'Pro Plan',
                'title'        => 'Setup Fee',
                'line_total'   => 1500,
            ],
        ]);

        $lineItems = $this->build($instance, 1500);

        $this->assertNotNull($lineItems);
        $this->assertSame(1500, $this->sumOf($lineItems));
        $this->assertNotContains('Pro Plan Monthly', $this->namesOf($lineItems));
    }

    public function test_addons_and_fees_each_get_their_own_line(): void
    {
        $instance = $this->makeInstance([
            [
                'payment_type' => 'subscription',
                'post_title'   => 'Pro Plan',
                'title'        => 'Monthly',
                'line_total'   => 2999,
            ],
            [
                'payment_type' => 'signup_fee',
                'post_title'   => 'Pro Plan',
                'title'        => 'Setup Fee',
                'line_total'   => 1000,
            ],
            [
                'payment_type' => 'onetime',
                'post_title'   => 'Training Session',
                'title'        => 'One hour',
                'line_total'   => 2500,
            ],
            [
                'payment_type' => 'fee',
                'post_title'   => '',
                'title'        => 'Handling fee',
                'line_total'   => 300,
            ],
        ]);

        $lineItems = $this->build($instance, 3800);

        $this->assertNotNull($lineItems);
        $this->assertSame([
            'Pro Plan Setup Fee',
            'Training Session One hour',
            'Handling fee',
        ], $this->namesOf($lineItems));
        $this->assertSame(3800, $this->sumOf($lineItems));
    }

    public function test_quantity_is_preserved_and_reconciles_exactly(): void
    {
        $instance = $this->makeInstance([
            [
                'payment_type' => 'subscription',
                'post_title'   => 'Pro Plan',
                'title'        => 'Monthly',
                'line_total'   => 2999,
            ],
            [
                'payment_type' => 'onetime',
                'post_title'   => 'Seat Licence',
                'title'        => 'Extra seat',
                'quantity'     => 3,
                'line_total'   => 4500,
            ],
        ]);

        $lineItems = $this->build($instance, 4500);

        $this->assertNotNull($lineItems);
        $this->assertSame(3, (int) $lineItems[0]['quantity']);
        $this->assertSame(1500, (int) $lineItems[0]['price_data']['unit_amount']);
        $this->assertSame(4500, $this->sumOf($lineItems));
    }

    public function test_leftover_tax_becomes_one_reconciliation_line(): void
    {
        $instance = $this->makeInstance([
            [
                'payment_type' => 'subscription',
                'post_title'   => 'Pro Plan',
                'title'        => 'Monthly',
                'line_total'   => 2999,
            ],
            [
                'payment_type' => 'signup_fee',
                'post_title'   => 'Pro Plan',
                'title'        => 'Setup Fee',
                'line_total'   => 1000,
            ],
        ]);

        // Signup fee 1000 + 200 additive tax folded into the subscription's signup_fee.
        $lineItems = $this->build($instance, 1200);

        $this->assertNotNull($lineItems);
        $this->assertCount(2, $lineItems);
        $this->assertSame('Taxes & adjustments', $this->namesOf($lineItems)[1]);
        $this->assertSame(200, (int) $lineItems[1]['price_data']['unit_amount']);
        $this->assertSame(1200, $this->sumOf($lineItems));
    }

    public function test_pure_first_cycle_excess_is_labelled_as_an_adjustment(): void
    {
        // No setup-fee item exists: the amount is the computed excess over recurring.
        $instance = $this->makeInstance([
            [
                'payment_type' => 'subscription',
                'post_title'   => 'Pro Plan',
                'title'        => 'Monthly',
                'line_total'   => 2999,
            ],
        ]);

        $lineItems = $this->build($instance, 500);

        $this->assertNotNull($lineItems);
        $this->assertCount(1, $lineItems);
        $this->assertSame(['First payment adjustment'], $this->namesOf($lineItems));
        $this->assertSame(500, $this->sumOf($lineItems));
    }

    public function test_simulated_trial_labels_the_line_as_the_whole_first_payment(): void
    {
        $instance = $this->makeInstance(
            [
                [
                    'payment_type' => 'subscription',
                    'post_title'   => 'Pro Plan',
                    'title'        => 'Monthly',
                    'line_total'   => 2999,
                ],
            ],
            ['config' => ['is_trial_days_simulated' => 'yes']]
        );

        $lineItems = $this->build($instance, 1499);

        $this->assertNotNull($lineItems);
        $this->assertSame(['First payment'], $this->namesOf($lineItems));
        $this->assertSame(1499, $this->sumOf($lineItems));
    }

    public function test_a_configured_setup_fee_owns_the_fallback_label(): void
    {
        $instance = $this->makeInstance(
            [
                [
                    'payment_type' => 'subscription',
                    'post_title'   => 'Pro Plan',
                    'title'        => 'Monthly',
                    'line_total'   => 2999,
                ],
                [
                    'payment_type' => 'signup_fee',
                    'post_title'   => 'Pro Plan',
                    'title'        => 'Onboarding & setup',
                    'line_total'   => 1000,
                ],
            ],
            ['config' => ['is_trial_days_simulated' => 'yes']]
        );

        $this->assertSame(
            'Pro Plan Onboarding & setup',
            $this->fallbackName($instance->order, $instance->subscription)
        );
    }

    public function test_over_sum_declines_so_the_caller_falls_back_to_one_line(): void
    {
        // Discount recomputation can leave the items worth more than the amount
        // actually charged up front; there is no subtractive line in Checkout.
        $instance = $this->makeInstance([
            [
                'payment_type' => 'subscription',
                'post_title'   => 'Pro Plan',
                'title'        => 'Monthly',
                'line_total'   => 2999,
            ],
            [
                'payment_type' => 'signup_fee',
                'post_title'   => 'Pro Plan',
                'title'        => 'Setup Fee',
                'line_total'   => 5000,
            ],
        ]);

        $this->assertNull($this->build($instance, 1000));
    }

    public function test_declines_when_the_session_is_already_at_the_line_cap(): void
    {
        $instance = $this->makeInstance([
            [
                'payment_type' => 'subscription',
                'post_title'   => 'Pro Plan',
                'title'        => 'Monthly',
                'line_total'   => 2999,
            ],
            [
                'payment_type' => 'signup_fee',
                'post_title'   => 'Pro Plan',
                'title'        => 'Setup Fee',
                'line_total'   => 1000,
            ],
        ]);

        $this->assertNull($this->build($instance, 1000, Processor::MAX_HOSTED_LINE_ITEMS));
    }

    public function test_zero_and_negative_lines_are_skipped(): void
    {
        $instance = $this->makeInstance([
            [
                'payment_type' => 'subscription',
                'post_title'   => 'Pro Plan',
                'title'        => 'Monthly',
                'line_total'   => 2999,
            ],
            [
                'payment_type' => 'onetime',
                'post_title'   => 'Free Gift',
                'title'        => 'Included',
                'line_total'   => 0,
            ],
            [
                'payment_type' => 'signup_fee',
                'post_title'   => 'Pro Plan',
                'title'        => 'Setup Fee',
                'line_total'   => 1000,
            ],
        ]);

        $lineItems = $this->build($instance, 1000);

        $this->assertNotNull($lineItems);
        $this->assertSame(['Pro Plan Setup Fee'], $this->namesOf($lineItems));
        $this->assertSame(1000, $this->sumOf($lineItems));
    }
}

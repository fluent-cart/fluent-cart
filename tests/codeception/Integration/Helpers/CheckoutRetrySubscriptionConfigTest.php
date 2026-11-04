<?php

namespace Tests\Integration\Helpers;

use FluentCart\App\Helpers\CheckoutProcessor;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySnapshot;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionManagementMode;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;

/**
 * Paying an unpaid order again re-uses its pending subscription. The retry
 * refreshes what checkout owns in the subscription's config but keeps what a
 * gateway wrote there between attempts (Stripe's replaced-subscription marker
 * rolls its idempotency key).
 */
class CheckoutRetrySubscriptionConfigTest extends DatabaseTestCase
{
    /** @var callable|null */
    private $storeManaged;

    public function tearDown(): void
    {
        if ($this->storeManaged) {
            remove_filter('fluent_cart/subscription/management_mode', $this->storeManaged);
        }
        parent::tearDown();
    }

    public function test_a_retry_keeps_what_a_gateway_wrote_to_the_subscription_config(): void
    {
        $customer = (new CustomerFactory())->create();
        $order = $this->checkout($customer->id);
        Subscription::query()->where('parent_order_id', $order->id)->first()
            ->mergeConfig(['stripe_replaced_vendor_sub_id' => 'sub_first_attempt']);

        $this->checkout($customer->id, $order, CurrencySnapshot::make('GBP', '0.79', ['source' => CurrencySnapshot::SOURCE_MANUAL]));

        $config = Subscription::query()->where('parent_order_id', $order->id)->first()->config;
        $this->assertSame('sub_first_attempt', $config['stripe_replaced_vendor_sub_id'] ?? null, 'the gateway\'s marker survives the retry');
        $this->assertSame('GBP', $config['fx']['currency'], 'checkout-owned keys follow the retry');
        $this->assertSame('0.790000000000', $config['fx']['rate']);
        $this->assertSame('no', $config['is_trial_days_simulated']);
    }

    public function test_a_retry_drops_a_store_managed_stamp_it_does_not_set_again(): void
    {
        $this->storeManaged = function () {
            return SubscriptionManagementMode::STORE_MANAGED;
        };
        add_filter('fluent_cart/subscription/management_mode', $this->storeManaged);

        $customer = (new CustomerFactory())->create();
        $order = $this->checkout($customer->id);
        $config = Subscription::query()->where('parent_order_id', $order->id)->first()->config;
        $this->assertSame(SubscriptionManagementMode::STORE_MANAGED, $config[SubscriptionManagementMode::CONFIG_KEY]);

        // The store is back to gateway-managed when the shopper pays again
        remove_filter('fluent_cart/subscription/management_mode', $this->storeManaged);
        $this->storeManaged = null;
        $this->checkout($customer->id, $order);

        $config = Subscription::query()->where('parent_order_id', $order->id)->first()->config;
        $this->assertArrayNotHasKey(SubscriptionManagementMode::CONFIG_KEY, $config);
    }

    private function checkout(int $customerId, ?Order $previous = null, ?array $snapshot = null): Order
    {
        $orderData = [
            'customer_id'    => $customerId,
            'payment_method' => 'offline_payment',
        ];
        if ($snapshot) {
            $orderData['currency_snapshot'] = $snapshot;
        }

        $processor = new CheckoutProcessor([$this->subscriptionItem()], $orderData);
        $order = $previous ? $processor->createDraftOrder(Order::query()->find($previous->id)) : $processor->createDraftOrder();

        return Order::query()->find($order->id);
    }

    private function subscriptionItem(): array
    {
        return [
            'object_id'        => 1,
            'post_id'          => 1,
            'quantity'         => 1,
            'unit_price'       => 4980,
            'price'            => 4980,
            'subtotal'         => 4980,
            'line_total'       => 4980,
            'discount_total'   => 0,
            'coupon_discount'  => 0,
            'manual_discount'  => 0,
            'tax_amount'       => 0,
            'shipping_charge'  => 0,
            'cost'             => 0,
            'post_title'       => 'Plan',
            'product_title'    => 'Plan',
            'title'            => 'Monthly',
            'variation_title'  => 'Monthly',
            'fulfillment_type' => 'digital',
            'other_info'       => [
                'payment_type'    => 'subscription',
                'repeat_interval' => 'monthly',
                'times'           => 0,
                'trial_days'      => 0,
                'signup_fee'      => 0,
                'recurring_tax'   => 0,
            ],
        ];
    }
}

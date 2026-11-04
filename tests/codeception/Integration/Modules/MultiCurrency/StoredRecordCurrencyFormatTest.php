<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\Api\Resource\CouponResource;
use FluentCart\App\Models\Activity;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencyContext;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Services\Renderer\Receipt\TaxSummaryHelper;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CouponFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTaxRateFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;

/**
 * A stored record shows its amounts in its own currency, read from any
 * screen: an order and its items in the order's, a subscription in the
 * subscription's. The store currency (USD here) is never the fallback.
 */
class StoredRecordCurrencyFormatTest extends DatabaseTestCase
{
    public function test_a_subscription_items_billing_line_is_in_the_orders_currency(): void
    {
        $item = $this->subscriptionItem('EUR', 900, ['manage_setup_fee' => 'yes', 'signup_fee' => 500]);

        $this->assertSame('USD', CurrencyContext::current());
        $this->assertStringContainsString('€9.00', html_entity_decode($item->payment_info));
        $this->assertStringNotContainsString('$', html_entity_decode($item->payment_info));
        $this->assertStringContainsString('€5.00', html_entity_decode($item->setup_info));
    }

    public function test_a_zero_decimal_items_amounts_have_no_decimals(): void
    {
        $item = $this->subscriptionItem('JPY', 298000);

        $this->assertStringContainsString('¥2,980 ', html_entity_decode($item->payment_info));
        $this->assertSame('¥2,980', html_entity_decode($item->formatted_total));
    }

    public function test_an_items_formatted_total_is_in_the_orders_currency(): void
    {
        $item = $this->subscriptionItem('EUR', 900);

        $this->assertSame('€9.00', html_entity_decode($item->formatted_total));
    }

    public function test_the_tax_summary_line_is_in_the_orders_currency(): void
    {
        $order = (new OrderFactory())->create([
            'customer_id'  => (new CustomerFactory())->create()->id,
            'currency'     => 'GBP',
            'tax_behavior' => 1,
            'subtotal'     => 3934,
            'tax_total'    => 787,
            'total_amount' => 4721,
        ]);
        (new OrderTaxRateFactory())->create([
            'order_id'    => $order->id,
            'tax_rate_id' => 1,
            'order_tax'   => 787,
            'total_tax'   => 787,
            'meta'        => ['label' => 'VAT', 'rate_percent' => 20, 'taxable_amount' => 3934, 'inclusive' => false],
        ]);

        $summary = TaxSummaryHelper::computeTaxSummary(Order::query()->find($order->id));

        $this->assertSame('£7.87', $summary['simpleLine']['value']);
    }

    public function test_a_subscription_price_change_is_logged_in_the_subscriptions_currency(): void
    {
        $product = (new ProductFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id' => (new CustomerFactory())->create()->id,
            'currency'    => 'JPY',
        ]);
        $subscription = (new SubscriptionFactory())->create([
            'customer_id'       => $order->customer_id,
            'parent_order_id'   => $order->id,
            'product_id'        => $product->ID,
            'variation_id'      => $product->variants->first()->id,
            'currency'          => 'JPY',
            'collection_method' => 'manual',
            'recurring_total'   => 298000,
        ]);

        $hookChanges = null;
        $listener = function ($payload) use (&$hookChanges) {
            $hookChanges = $payload['changes'];
        };
        add_action('fluent_cart/subscription_updated', $listener);

        $result = SubscriptionService::updateSubscription(Subscription::query()->find($subscription->id), ['recurring_total' => '3000']);
        remove_action('fluent_cart/subscription_updated', $listener);

        $this->assertNotInstanceOf(\WP_Error::class, $result);
        $log = Activity::query()
            ->where('module_name', 'subscription')
            ->where('module_id', $subscription->id)
            ->where('title', 'Subscription updated')
            ->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('recurring_total: ¥2,980 → ¥3,000', html_entity_decode($log->content));
        $this->assertSame(['recurring_total: 2,980.00 → 3,000.00'], $hookChanges, 'the public hook payload keeps its plain-number format');
    }

    public function test_an_admin_applied_coupon_is_formatted_in_the_orders_currency(): void
    {
        $order = (new OrderFactory())->create([
            'customer_id' => (new CustomerFactory())->create()->id,
            'currency'    => 'GBP',
            'rate'        => '0.79',
        ]);
        $coupon = (new CouponFactory())->create(['code' => 'FMT-' . wp_generate_password(6, false), 'amount' => 1000]);
        $product = (new ProductFactory())->withVariantAttributes(['item_price' => 4500])->create();
        $variant = $product->variants->first();

        $result = CouponResource::applyCoupon([
            'coupon_code' => $coupon->code,
            'order_uuid'  => $order->uuid,
            'order_items' => [[
                'id'             => $variant->id,
                'post_id'        => $product->ID,
                'variation_id'   => $variant->id,
                'object_id'      => $variant->id,
                'type'           => 'product',
                'quantity'       => 1,
                'unit_price'     => 4500,
                'subtotal'       => 4500,
                'line_total'     => 4500,
                'discount_total' => 0,
                'other_info'     => [],
            ]],
        ]);

        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertSame('£7.90', html_entity_decode($result['applied_coupons'][$coupon->code]['formatted_discount']));
    }

    private function subscriptionItem(string $currency, int $unitPrice, array $otherInfo = []): OrderItem
    {
        $order = (new OrderFactory())->create([
            'customer_id'  => (new CustomerFactory())->create()->id,
            'currency'     => $currency,
            'subtotal'     => $unitPrice,
            'total_amount' => $unitPrice,
        ]);
        $item = (new OrderItemFactory())->create($order->id, [
            'payment_type' => 'subscription',
            'unit_price'   => $unitPrice,
            'subtotal'     => $unitPrice,
            'line_total'   => $unitPrice,
            'other_info'   => array_merge(['payment_type' => 'subscription', 'repeat_interval' => 'daily'], $otherInfo),
        ]);

        return OrderItem::query()->find($item->id);
    }
}

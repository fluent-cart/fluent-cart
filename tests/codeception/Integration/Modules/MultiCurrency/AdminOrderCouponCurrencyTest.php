<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\Api\Resource\CouponResource;
use FluentCart\Api\Resource\OrderResource;
use FluentCart\App\Models\AppliedCoupon;
use FluentCart\App\Models\Order;
use FluentCart\Framework\Support\Arr;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CouponFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductFactory;

/**
 * A coupon applied while editing an order in the admin. Coupons are authored
 * in the base currency (USD); on a GBP order their fixed amounts and spend
 * thresholds are converted at the order's frozen rate, the way the cart
 * converts them at checkout — never applied at face value.
 */
class AdminOrderCouponCurrencyTest extends DatabaseTestCase
{
    public function test_a_fixed_coupon_is_converted_at_the_orders_rate(): void
    {
        $order = $this->order('GBP', '0.79');
        $coupon = (new CouponFactory())->create(['code' => 'TENOFF-' . wp_generate_password(6, false), 'amount' => 1000]);

        $result = $this->apply($order, $coupon->code, 4500);

        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertEquals(790, $result['applied_coupons'][$coupon->code]['discount'], '$10.00 off is £7.90 at 0.79, not £10.00');
    }

    public function test_a_spend_threshold_is_converted_at_the_orders_rate(): void
    {
        $order = $this->order('GBP', '0.79');
        $coupon = (new CouponFactory())->create([
            'code'       => 'MIN50-' . wp_generate_password(6, false),
            'amount'     => 500,
            'conditions' => ['min_purchase_amount' => 5000],
        ]);

        // £45.00 clears a $50.00 minimum (£39.50 at 0.79)
        $result = $this->apply($order, $coupon->code, 4500);

        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertArrayHasKey($coupon->code, $result['applied_coupons']);
    }

    public function test_a_base_currency_order_is_unchanged(): void
    {
        $order = $this->order('USD', '1');
        $coupon = (new CouponFactory())->create(['code' => 'BASE-' . wp_generate_password(6, false), 'amount' => 1000]);

        $result = $this->apply($order, $coupon->code, 4500);

        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertEquals(1000, $result['applied_coupons'][$coupon->code]['discount']);
    }

    public function test_an_edited_order_saves_a_coupon_applied_at_the_orders_rate(): void
    {
        // The edit screen applies the coupon at the order's rate (£7.90) and
        // saves that; the save's re-check must use the same rate, not $10.00
        $order = $this->order('GBP', '0.79');
        $product = (new ProductFactory())->withVariantAttributes(['item_price' => 4500])->create();
        $variant = $product->variants->first();
        $line = (new OrderItemFactory())->create($order->id, [
            'post_id'    => $product->ID,
            'object_id'  => $variant->id,
            'unit_price' => 4500,
            'subtotal'   => 4500,
            'line_total' => 4500,
        ]);
        $coupon = (new CouponFactory())->create(['code' => 'EDIT-' . wp_generate_password(6, false), 'amount' => 1000]);

        $orderLine = [
            'id'             => $line->id,
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
        ];
        $applied = CouponResource::applyCoupon([
            'coupon_code' => $coupon->code,
            'order_uuid'  => $order->uuid,
            'order_items' => [$orderLine],
        ]);
        $this->assertIsArray($applied, is_wp_error($applied) ? $applied->get_error_message() : '');
        $this->assertEquals(790, $applied['applied_coupons'][$coupon->code]['discount']);

        $calculated = Arr::get($applied['calculated_items'], (string) $line->id);
        $result = OrderResource::update([
            'orderData'    => [
                'order_items'    => [array_merge($orderLine, ['discount_total' => $calculated['discount_total']])],
                'applied_coupon' => $applied['applied_coupons'],
            ],
            'deletedItems' => [],
            'discount'     => '',
            'shipping'     => '',
        ], $order->id);

        $this->assertNotInstanceOf(\WP_Error::class, $result, is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertSame(790, (int) AppliedCoupon::query()->where('order_id', $order->id)->value('amount'), 'saved in GBP at the order rate');
    }

    private function order(string $currency, string $rate): Order
    {
        $order = (new OrderFactory())->create([
            'customer_id' => (new CustomerFactory())->create()->id,
            'currency'    => $currency,
            'rate'        => $rate,
        ]);

        return Order::query()->find($order->id);
    }

    /**
     * @return array|\WP_Error
     */
    private function apply(Order $order, string $code, int $subtotal)
    {
        $product = (new ProductFactory())->withVariantAttributes(['item_price' => $subtotal])->create();
        $variant = $product->variants->first();

        return CouponResource::applyCoupon([
            'coupon_code' => $code,
            'order_uuid'  => $order->uuid,
            'order_items' => [[
                'id'             => $variant->id,
                'post_id'        => $product->ID,
                'variation_id'   => $variant->id,
                'object_id'      => $variant->id,
                'type'           => 'product',
                'quantity'       => 1,
                'unit_price'     => $subtotal,
                'subtotal'       => $subtotal,
                'line_total'     => $subtotal,
                'discount_total' => 0,
                'other_info'     => [],
            ]],
        ]);
    }
}

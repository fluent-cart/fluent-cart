<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\Api\Resource\CouponResource;
use FluentCart\Api\Resource\OrderResource;
use FluentCart\App\Models\AppliedCoupon;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencyAdmin;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use FluentCart\App\Services\AdminOrderPricing;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CouponFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Traits\MakesMultiCurrencyAvailable;
use Tests\Support\RateFixture;

/**
 * Admin-created orders in another currency: catalog lines and coupons are
 * priced at the currency's current rate, frozen on the order; amounts the
 * admin types (shipping, custom prices) are already in the order currency.
 * Base USD, GBP 0.79.
 */
class AdminOrderCurrencyTest extends DatabaseTestCase
{
    use MakesMultiCurrencyAvailable;

    public function setUp(): void
    {
        parent::setUp();
        $this->makeMultiCurrencyAvailable();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP'], ['code' => 'EUR']]]);
        RateFixture::set('GBP', '0.79');
    }

    public function tearDown(): void
    {
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        parent::tearDown();
    }

    public function test_an_admin_order_in_gbp_prices_catalog_lines_at_the_rate_and_freezes_it(): void
    {
        $order = $this->placeAsScreen($this->payload('GBP', 4980, 2, 1000));

        $this->assertInstanceOf(Order::class, $order);
        $order = Order::query()->find($order->id);

        $this->assertSame('GBP', $order->currency);
        $this->assertSame('0.790000000000', (string) $order->rate);
        $this->assertSame(7868, (int) $order->subtotal, '2 x (4980 x 0.79 = 3934)');
        $this->assertSame(1000, (int) $order->shipping_total, '£10.00 shipping is typed in GBP and stored as is');
        $this->assertSame('manual', $order->config['fx']['source']);

        $transaction = OrderTransaction::query()->where('order_id', $order->id)->first();
        $this->assertSame('0.790000000000', (string) $transaction->rate);
    }

    public function test_a_coupon_applied_on_the_create_screen_is_recorded_in_the_orders_currency(): void
    {
        // The Create Order screen has no order yet, so the coupon is applied
        // to the base-currency prices the admin entered ($50.00, $10.00 off)
        $coupon = (new CouponFactory())->create(['code' => 'CREATE-' . wp_generate_password(6, false), 'amount' => 1000]);
        $payload = $this->payload('GBP', 5000, 1, 0);
        $item = $payload['order_items'][0];

        $applied = CouponResource::applyCoupon([
            'coupon_code' => $coupon->code,
            'order_items' => [array_merge($item, [
                'id'             => $item['object_id'],
                'variation_id'   => $item['object_id'],
                'type'           => 'product',
                'subtotal'       => 5000,
                'line_total'     => 5000,
                'discount_total' => 0,
            ])],
        ]);
        $this->assertIsArray($applied, is_wp_error($applied) ? $applied->get_error_message() : '');

        $payload['order_items'] = array_values($applied['calculated_items']);
        $payload['applied_coupon'] = $applied['applied_coupons'];

        $order = $this->placeAsScreen($payload);
        $this->assertInstanceOf(Order::class, $order);
        $order = Order::query()->find($order->id);

        $this->assertSame('GBP', $order->currency);
        $this->assertSame(790, (int) $order->coupon_discount_total, '$10.00 off is £7.90 at 0.79');

        $appliedCoupon = AppliedCoupon::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($appliedCoupon);
        $this->assertSame(790, (int) $appliedCoupon->amount, 'the applied coupon is recorded in GBP like the order, not the base $10.00');
    }

    public function test_create_screen_coupon_records_sum_to_the_orders_coupon_total(): void
    {
        // Two fixed coupons, $1.50 and $2.50, on one $50.00 line. Each is
        // priced in GBP on its own (£1.19 + £1.98); the posted line
        // discount is ignored and the order's coupon total is their sum.
        $small = (new CouponFactory())->create(['code' => 'SMALL-' . wp_generate_password(6, false), 'amount' => 150]);
        $large = (new CouponFactory())->create(['code' => 'LARGE-' . wp_generate_password(6, false), 'amount' => 250]);

        $payload = $this->payload('GBP', 5000, 1, 0);
        $payload['order_items'][0]['discount_total'] = 400;
        $payload['order_items'][0]['coupon_discount'] = 400;
        $payload['applied_coupon'] = [
            $small->code => ['id' => $small->id, 'discount' => 150],
            $large->code => ['id' => $large->id, 'discount' => 250],
        ];

        $order = $this->placeAsScreen($payload);
        $this->assertInstanceOf(Order::class, $order);
        $order = Order::query()->find($order->id);

        $this->assertSame(317, (int) $order->coupon_discount_total, '£1.19 + £1.98');

        $amounts = AppliedCoupon::query()->where('order_id', $order->id)->pluck('amount', 'code')->toArray();
        $this->assertSame(317, (int) array_sum($amounts), 'the coupon records sum to the order coupon total');
        $this->assertSame(119, (int) $amounts[$small->code]);
        $this->assertSame(198, (int) $amounts[$large->code]);
    }

    public function test_create_screen_coupons_without_line_discounts_keep_their_own_converted_amounts(): void
    {
        // Coupons sent with no line discounts: the 317 vs 0 gap is not
        // rounding, so nothing is moved — each coupon keeps its conversion
        $small = (new CouponFactory())->create(['code' => 'SMALL-' . wp_generate_password(6, false), 'amount' => 150]);
        $large = (new CouponFactory())->create(['code' => 'LARGE-' . wp_generate_password(6, false), 'amount' => 250]);

        $payload = $this->payload('GBP', 5000, 1, 0);
        $payload['applied_coupon'] = [
            $small->code => ['id' => $small->id, 'discount' => 150],
            $large->code => ['id' => $large->id, 'discount' => 250],
        ];

        $order = $this->placeAsScreen($payload);
        $this->assertInstanceOf(Order::class, $order);

        $amounts = AppliedCoupon::query()->where('order_id', $order->id)->pluck('amount', 'code')->toArray();
        $this->assertSame(119, (int) $amounts[$small->code], '$1.50 -> £1.19');
        $this->assertSame(198, (int) $amounts[$large->code], '$2.50 -> £1.98, never lowered toward 0');
    }

    public function test_one_coupon_spread_over_several_lines_records_the_orders_coupon_total(): void
    {
        // One $7.50 coupon (£5.93 at 0.79) spread over five lines. The lines
        // share the £5.93 in whole pence, so line discounts and the coupon
        // record agree; the posted $1.50 per line is ignored.
        $coupon = (new CouponFactory())->create(['code' => 'SPREAD-' . wp_generate_password(6, false), 'amount' => 750]);

        $payload = $this->payload('GBP', 5000, 1, 0);
        $lines = [];
        for ($index = 0; $index < 5; $index++) {
            $product = (new ProductFactory())->withVariantAttributes(['item_price' => 5000])->create();
            $variant = $product->variants->first();
            $lines[] = array_merge($payload['order_items'][0], [
                'id'              => $variant->id,
                'post_id'         => $product->ID,
                'object_id'       => $variant->id,
                'discount_total'  => 150,
                'coupon_discount' => 150,
            ]);
        }
        $payload['order_items'] = $lines;
        $payload['applied_coupon'] = [$coupon->code => ['id' => $coupon->id, 'discount' => 750]];

        $order = $this->placeAsScreen($payload);
        $this->assertInstanceOf(Order::class, $order);
        $order = Order::query()->find($order->id);

        $this->assertSame(593, (int) $order->coupon_discount_total, '750 x 0.79 = 592.5 -> 593');

        $appliedCoupon = AppliedCoupon::query()->where('order_id', $order->id)->first();
        $this->assertSame(593, (int) $appliedCoupon->amount, 'the coupon record equals the order coupon total');
    }

    public function test_tiny_coupons_each_keep_their_smallest_unit(): void
    {
        // EUR at 0.6: five 1-cent coupons each price to €0.01 (0.6 rounds
        // up). The posted 5-cent line discount is ignored; the order's
        // coupon total is the five records' sum.
        RateFixture::set('EUR', '0.6');
        ExchangeRateService::flushCache();

        $payload = $this->payload('EUR', 5000, 1, 0);
        $payload['order_items'][0]['discount_total'] = 5;
        $payload['order_items'][0]['coupon_discount'] = 5;
        $payload['applied_coupon'] = [];
        for ($index = 0; $index < 5; $index++) {
            $coupon = (new CouponFactory())->create(['code' => 'TINY' . $index . '-' . wp_generate_password(6, false), 'amount' => 1]);
            $payload['applied_coupon'][$coupon->code] = ['id' => $coupon->id, 'discount' => 1];
        }

        $order = $this->placeAsScreen($payload);
        $this->assertInstanceOf(Order::class, $order, is_wp_error($order) ? $order->get_error_message() : '');
        $order = Order::query()->find($order->id);

        $this->assertSame(5, (int) $order->coupon_discount_total, '5 x €0.01');

        $amounts = array_map('intval', AppliedCoupon::query()->where('order_id', $order->id)->pluck('amount', 'code')->toArray());
        $this->assertCount(5, $amounts);
        $this->assertSame([1, 1, 1, 1, 1], array_values($amounts));
    }

    public function test_a_base_currency_admin_order_is_unchanged(): void
    {
        $order = Order::query()->find(OrderResource::updatedPlaceOrder($this->payload('', 4980, 2, 1000))->id);

        $this->assertSame('USD', $order->currency);
        $this->assertSame(9960, (int) $order->subtotal);
        $this->assertSame(1000, (int) $order->shipping_total);
    }

    public function test_a_currency_without_a_rate_is_refused(): void
    {
        $payload = $this->payload('EUR', 4980, 1, 0);
        $ordersBefore = Order::query()->count();

        $result = OrderResource::updatedPlaceOrder($payload);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame($ordersBefore, Order::query()->count(), 'no order is created');
    }

    public function test_the_admin_app_lists_only_currencies_with_a_rate(): void
    {
        $codes = array_column(MultiCurrencyAdmin::orderCurrencies(), 'code');

        $this->assertSame(['USD', 'GBP'], $codes, 'EUR has no rate');
    }

    private function payload(string $currency, int $unitPrice, int $quantity, int $shipping): array
    {
        $customer = (new CustomerFactory())->create();
        $product = (new ProductFactory())->withVariantAttributes(['item_price' => $unitPrice])->create();
        $variant = $product->variants->first();

        return array_filter([
            'customer_id'    => $customer->id,
            'currency'       => $currency,
            'order_items'    => [[
                'id'           => $variant->id,
                'post_id'      => $product->ID,
                'object_id'    => $variant->id,
                'title'        => 'Default',
                'post_title'   => 'Product',
                'unit_price'   => $unitPrice,
                'quantity'     => $quantity,
                'payment_type' => 'onetime',
                'other_info'   => ['payment_type' => 'onetime'],
            ]],
            'shipping_total' => $shipping,
            'discount'       => [],
            'shipping'       => [],
            'labels'         => [],
            'user_tz'        => 'UTC',
        ], function ($value) {
            return $value !== '';
        });
    }

    /**
     * Save as the Create Order screen does: preview, then send the revision
     * and total it showed.
     *
     * @return Order|\WP_Error
     */
    private function placeAsScreen(array $payload)
    {
        $preview = AdminOrderPricing::price($payload);
        if (is_wp_error($preview)) {
            return $preview;
        }

        return OrderResource::updatedPlaceOrder(array_merge($payload, [
            'quote_revision' => $preview['quote_revision'],
            'total_amount'   => $preview['total'],
        ]));
    }
}

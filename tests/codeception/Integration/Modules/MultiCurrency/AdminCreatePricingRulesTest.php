<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\Api\Resource\CouponResource;
use FluentCart\Api\Resource\OrderResource;
use FluentCart\App\Models\AppliedCoupon;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Services\AdminOrderPricing;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CouponFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Traits\MakesMultiCurrencyAvailable;
use Tests\Support\RateFixture;

/**
 * Coupons applied on the Create Order screen when the order's currency has
 * pricing rules (dev-docs/multi-currency/06-orders-subscriptions.md). The screen
 * applies coupons to the base prices the admin entered; the order's line
 * prices follow the currency's rules. A percentage coupon is a percentage of
 * the line the order charges; a fixed coupon converts plainly.
 *
 * Base USD, GBP at 0.79, rounded up to 1 and ending in .99: $50.00 sells at
 * £39.99 (39.50 → 40.00 → 39.99), not the plain £39.50.
 */
class AdminCreatePricingRulesTest extends DatabaseTestCase
{
    use MakesMultiCurrencyAvailable;


    public function setUp(): void
    {
        parent::setUp();
        $this->makeMultiCurrencyAvailable();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [
            ['code' => 'GBP', 'rounding' => 'up_1', 'charm' => 'minus_0_01', 'adjustment' => 0],
        ]]);
        RateFixture::set('GBP', '0.79');
    }

    public function tearDown(): void
    {
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        parent::tearDown();
    }

    public function test_a_percentage_coupon_is_a_percentage_of_the_rounded_line_price(): void
    {
        $coupon = (new CouponFactory())->createPercentage(['code' => 'PCT-' . wp_generate_password(6, false), 'amount' => 10]);

        $order = $this->placeWithCoupons(5000, 1, [$coupon->code]);
        $order = Order::query()->find($order->id);

        $this->assertSame('GBP', $order->currency);
        $this->assertSame(3999, (int) $order->subtotal, '$50.00 sells at £39.99 under the rules');
        $this->assertSame(400, (int) $order->coupon_discount_total, '10% of £39.99 is £4.00, not $5.00 converted (£3.95)');

        $amounts = $this->couponAmounts($order);
        $this->assertSame(400, $amounts[$coupon->code], 'the coupon record matches the order coupon total');
    }

    public function test_two_percentage_coupons_each_keep_their_share(): void
    {
        // 10% then 20% on two $50.00 lines. Base: 1000 then 1800 (on the
        // 9000 left). In GBP the lines are 2 x 3999 = 7998: 10% = 799.8,
        // then 20% of the 7198.2 left = 1439.64 — each coupon keeps its own
        // share of the order's coupon total.
        $ten = (new CouponFactory())->createPercentage(['code' => 'TEN-' . wp_generate_password(6, false), 'amount' => 10, 'priority' => 1]);
        $twenty = (new CouponFactory())->createPercentage(['code' => 'TWENTY-' . wp_generate_password(6, false), 'amount' => 20, 'priority' => 2]);

        $order = $this->placeWithCoupons(5000, 2, [$ten->code, $twenty->code]);
        $order = Order::query()->find($order->id);

        $this->assertSame(7998, (int) $order->subtotal);
        $total = (int) $order->coupon_discount_total;
        $this->assertEqualsWithDelta(2239, $total, 1, '799.8 + 1439.64 of £79.98');

        $amounts = $this->couponAmounts($order);
        $this->assertSame($total, array_sum($amounts), 'the coupon records sum to the order coupon total');
        $this->assertEqualsWithDelta(800, $amounts[$ten->code], 1);
        $this->assertEqualsWithDelta(1440, $amounts[$twenty->code], 1);
    }

    public function test_a_fixed_coupon_still_converts_plainly(): void
    {
        // Rules apply to prices, never to coupon amounts: $10.00 off is £7.90
        $coupon = (new CouponFactory())->create(['code' => 'FIX-' . wp_generate_password(6, false), 'amount' => 1000]);

        $order = $this->placeWithCoupons(5000, 1, [$coupon->code]);
        $order = Order::query()->find($order->id);

        $this->assertSame(3999, (int) $order->subtotal);
        $this->assertSame(790, (int) $order->coupon_discount_total);
        $this->assertSame(790, $this->couponAmounts($order)[$coupon->code]);
    }

    public function test_a_capped_percentage_coupon_converts_its_cap_plainly(): void
    {
        // 10% capped at $3.00: the cap is a fixed amount, so $3.00 off is
        // £2.37 — the cap is never scaled up with the rounded price
        $coupon = (new CouponFactory())->createPercentage([
            'code'       => 'CAP-' . wp_generate_password(6, false),
            'amount'     => 10,
            'conditions' => ['max_discount_amount' => 300],
        ]);

        $order = $this->placeWithCoupons(5000, 1, [$coupon->code]);
        $order = Order::query()->find($order->id);

        $this->assertSame(237, (int) $order->coupon_discount_total);
        $this->assertSame(237, $this->couponAmounts($order)[$coupon->code]);
    }

    public function test_a_fixed_coupon_never_takes_more_than_the_reduced_line(): void
    {
        // GBP prices 50% below the plain conversion: $50.00 sells at £19.75.
        // A $40.00 coupon converts plainly to £31.60 — more than the line —
        // so it takes the whole line and no more, as in the cart.
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [
            ['code' => 'GBP', 'rounding' => 'none', 'charm' => 'none', 'adjustment' => -50],
        ]]);
        MultiCurrencySettings::clearCache();
        $coupon = (new CouponFactory())->create(['code' => 'BIG-' . wp_generate_password(6, false), 'amount' => 4000]);

        $order = $this->placeWithCoupons(5000, 1, [$coupon->code]);
        $order = Order::query()->find($order->id);

        $this->assertSame(1975, (int) $order->subtotal);
        $this->assertSame(1975, (int) $order->coupon_discount_total, 'capped at the line');
        $this->assertSame(0, (int) $order->total_amount, 'never below zero');
        $this->assertSame(1975, $this->couponAmounts($order)[$coupon->code]);
    }

    public function test_a_percentage_coupon_in_a_zero_decimal_currency_is_whole_yen(): void
    {
        // JPY at 150, no rules: $33.32 sells at ¥4,998 (499800). 10% of it
        // is ¥499.8 — the order records ¥500, never a fraction of a yen
        $this->useYen();
        $coupon = (new CouponFactory())->createPercentage(['code' => 'YEN-' . wp_generate_password(6, false), 'amount' => 10]);

        $order = $this->placeWithCoupons(3332, 1, [$coupon->code], 'JPY');
        $order = Order::query()->find($order->id);

        $this->assertSame('JPY', $order->currency);
        $this->assertSame(499800, (int) $order->subtotal);
        $this->assertSame(50000, (int) $order->coupon_discount_total, '10% of ¥4,998 is ¥500 in whole yen');
        $this->assertSame(449800, (int) $order->total_amount);
        $this->assertSame([50000], $this->lineDiscounts($order));
        $this->assertSame(50000, $this->couponAmounts($order)[$coupon->code]);
    }

    public function test_two_percentage_coupons_in_a_zero_decimal_currency_reconcile_in_whole_yen(): void
    {
        // 10% then 20% on two $33.32 lines (¥4,998 each). Every line
        // discount, every coupon record and the order totals are whole yen,
        // and the records sum to the order's coupon total
        $this->useYen();
        $ten = (new CouponFactory())->createPercentage(['code' => 'YTEN-' . wp_generate_password(6, false), 'amount' => 10, 'priority' => 1]);
        $twenty = (new CouponFactory())->createPercentage(['code' => 'YTWENTY-' . wp_generate_password(6, false), 'amount' => 20, 'priority' => 2]);

        $order = $this->placeWithCoupons(3332, 2, [$ten->code, $twenty->code], 'JPY');
        $order = Order::query()->find($order->id);

        $this->assertSame(999600, (int) $order->subtotal);
        $this->assertWholeYen($order);
        $amounts = $this->couponAmounts($order);
        $this->assertSame((int) $order->coupon_discount_total, array_sum($amounts), 'the coupon records sum to the order coupon total');
        $this->assertSame((int) $order->coupon_discount_total, array_sum($this->lineDiscounts($order)));
    }

    public function test_a_fixed_coupon_in_a_zero_decimal_currency_is_whole_yen(): void
    {
        // $10.01 off spread over a $33.32 and a $50.00 line: each line's
        // share converts to whole yen, and the record reconciles to them
        $this->useYen();
        $coupon = (new CouponFactory())->create(['code' => 'YFIX-' . wp_generate_password(6, false), 'amount' => 1001]);

        $order = $this->placeOrder([3332, 5000], [$coupon->code], 'JPY');
        $order = Order::query()->find($order->id);

        $this->assertWholeYen($order);
        $this->assertSame((int) $order->coupon_discount_total, $this->couponAmounts($order)[$coupon->code]);
    }

    public function test_a_manual_discount_in_a_zero_decimal_currency_is_spread_in_whole_yen(): void
    {
        // ¥1,502 off, spread over a ¥4,998 and a ¥7,500 line by their
        // share — in whole yen per line
        $this->useYen();

        $order = $this->placeOrder([3332, 5000], [], 'JPY', ['type' => 'amount', 'value' => 1502]);
        $order = Order::query()->find($order->id);

        $this->assertSame(1249800, (int) $order->subtotal);
        $this->assertSame(150200, (int) $order->manual_discount_total);
        $this->assertWholeYen($order);
        $this->assertSame(150200, array_sum($this->lineDiscounts($order)));
    }

    public function test_a_manual_discount_on_low_value_yen_lines_never_overflows_a_line(): void
    {
        // JPY at 100: three 1-cent lines sell at ¥1 each (100 stored), ¥2
        // off. Every proportional share floors to ¥0, so the ¥2 must go out
        // a yen at a time — never both onto one ¥1 line
        $this->useYen('100');

        $order = $this->placeOrder([1, 1, 1], [], 'JPY', ['type' => 'amount', 'value' => 2]);
        $order = Order::query()->find($order->id);

        $this->assertSame(300, (int) $order->subtotal);
        $this->assertSame(200, (int) $order->manual_discount_total);
        $this->assertSame(100, (int) $order->total_amount);
        $this->assertWholeYen($order);
        $this->assertSame([100, 100, 0], $this->lineDiscounts($order), 'one yen each to the first two lines');
        $this->assertLinesNeverNegative($order);
    }

    public function test_a_manual_discount_on_one_cent_lines_never_overflows_a_line(): void
    {
        // The same shape in the base currency: 2 cents off three 1-cent lines
        $order = $this->placeOrder([1, 1, 1], [], '', ['type' => 'amount', 'value' => 0.02]);
        $order = Order::query()->find($order->id);

        $this->assertSame(3, (int) $order->subtotal);
        $this->assertSame(2, (int) $order->manual_discount_total);
        $this->assertSame(1, (int) $order->total_amount);
        $this->assertSame([1, 1, 0], $this->lineDiscounts($order));
        $this->assertLinesNeverNegative($order);
    }

    public function test_a_manual_discount_larger_than_the_order_is_rejected(): void
    {
        $this->useYen('100');

        $items = $this->makeLines([1, 1, 1]);
        $this->assertRejectedWithoutOrder(function () use ($items) {
            return $this->placeLinesResult($items, [], 'JPY', ['type' => 'amount', 'value' => 5]);
        });
    }

    public function test_a_manual_discount_equal_to_the_order_takes_every_line(): void
    {
        $this->useYen('100');

        $order = $this->placeOrder([1, 1, 1], [], 'JPY', ['type' => 'amount', 'value' => 3]);
        $order = Order::query()->find($order->id);

        $this->assertSame(300, (int) $order->manual_discount_total);
        $this->assertSame(0, (int) $order->total_amount);
        $this->assertSame([100, 100, 100], $this->lineDiscounts($order));
        $this->assertLinesNeverNegative($order);
    }

    public function test_a_manual_discount_never_takes_what_a_coupon_already_took(): void
    {
        // A $1.00 coupon on a $2.00 line leaves $1.00 of capacity: $2.00
        // off is rejected, $1.00 off takes exactly what the line has left
        $coupon = (new CouponFactory())->create(['code' => 'CAPA-' . wp_generate_password(6, false), 'amount' => 100]);

        $items = $this->makeLines([200]);
        $this->assertRejectedWithoutOrder(function () use ($items, $coupon) {
            return $this->placeLinesResult($items, [$coupon->code], '', ['type' => 'amount', 'value' => 2]);
        });

        $order = $this->placeOrder([200], [$coupon->code], '', ['type' => 'amount', 'value' => 1]);
        $order = Order::query()->find($order->id);

        $this->assertSame(200, (int) $order->subtotal);
        $this->assertSame(100, (int) $order->coupon_discount_total);
        $this->assertSame(100, (int) $order->manual_discount_total, 'only the capacity the coupon left');
        $this->assertSame(0, (int) $order->total_amount);
        $this->assertLinesNeverNegative($order);
    }

    public function test_a_manual_discount_capped_on_one_line_moves_the_shortfall_to_the_others_in_bulk(): void
    {
        // Two $50,000.00 lines; a $49,999.00 coupon on the first leaves it
        // $1.00 of capacity. $40,000.00 off splits $20,000.00 each by share;
        // the first takes only its $1.00 and the $19,999.00 shortfall goes
        // to the second line - in bulk, not one cent per pass
        $order = $this->placeOrderWithFirstLineCoupon([5000000, 5000000], 4999900, '', ['type' => 'amount', 'value' => 40000]);
        $order = Order::query()->find($order->id);

        $this->assertSame(10000000, (int) $order->subtotal);
        $this->assertSame(4999900, (int) $order->coupon_discount_total);
        $this->assertSame(4000000, (int) $order->manual_discount_total);
        $this->assertSame(1000100, (int) $order->total_amount);
        $this->assertSame([4999900 + 100, 3999900], $this->lineDiscounts($order), 'first line $1.00 manual, second $39,999.00');
        $this->assertLinesNeverNegative($order);
    }

    public function test_a_manual_discount_capped_on_one_yen_line_moves_the_shortfall_in_whole_yen(): void
    {
        // The same shape in JPY at 150: lines of ¥7,500,000, a coupon of
        // ¥7,499,850 on the first (¥150 left), ¥6,000,000 off. The first
        // line takes ¥150, the second ¥5,999,850 - whole yen throughout
        $this->useYen();

        $order = $this->placeOrderWithFirstLineCoupon([5000000, 5000000], 4999900, 'JPY', ['type' => 'amount', 'value' => 6000000]);
        $order = Order::query()->find($order->id);

        $this->assertSame(1500000000, (int) $order->subtotal);
        $this->assertSame(749985000, (int) $order->coupon_discount_total);
        $this->assertSame(600000000, (int) $order->manual_discount_total);
        $this->assertSame([749985000 + 15000, 599985000], $this->lineDiscounts($order));
        $this->assertWholeYen($order);
        $this->assertLinesNeverNegative($order);
    }

    public function test_the_manual_discount_allocator_work_does_not_grow_with_the_amount(): void
    {
        // Three yen lines with uneven capacity after coupons (¥1,
        // ¥80,000,000 and ¥40,000,000 left) and ¥100,000,000 off. The
        // proportional shares are ¥20M / ¥60M / ¥20M; the first is capped
        // at ¥1, and the ¥19,999,999 shortfall goes to the other two (the
        // tie at equal spare capacity to the earlier line). A per-yen
        // allocator runs ~20 million passes for it (seconds); a bounded one
        // does a handful of rounds whatever the amount
        $items = [
            ['unit_price' => 4000000000, 'quantity' => 1, 'discount_total' => 3999999900],
            ['unit_price' => 12000000000, 'quantity' => 1, 'discount_total' => 4000000000],
            ['unit_price' => 4000000000, 'quantity' => 1, 'discount_total' => 0],
        ];

        $start = microtime(true);
        AdminOrderPricing::distributeManualDiscount($items, 10000000000, 100);
        $elapsed = microtime(true) - $start;

        $shares = array_column($items, 'manual_discount');
        $this->assertSame(10000000000, array_sum($shares), 'the whole discount is spread');
        $this->assertSame([100, 7000000000, 2999999900], $shares, 'the first line takes only its ¥1');
        foreach ($items as $index => $item) {
            $this->assertSame(0, $shares[$index] % 100, 'line ' . $index . ' is whole yen');
            $this->assertLessThanOrEqual($item['unit_price'] - $item['discount_total'], $shares[$index], 'line ' . $index . ' within capacity');
        }
        $this->assertLessThan(0.5, $elapsed, 'allocation is bounded by the line count, not the amount (took ' . round($elapsed, 3) . 's)');
    }

    /**
     * No line is discounted below zero: discount_total never exceeds the
     * line's subtotal and line_total is never negative.
     */
    private function assertLinesNeverNegative(Order $order): void
    {
        $lines = OrderItem::query()->where('order_id', $order->id)->orderBy('id')->get();
        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual((int) $line->subtotal, (int) $line->discount_total, 'line ' . $line->id . ' discount exceeds its subtotal');
            $this->assertGreaterThanOrEqual(0, (int) $line->line_total, 'line ' . $line->id . ' total is negative');
        }
    }

    /**
     * Base USD, JPY at $rate (150 by default) with no pricing rules.
     */
    private function useYen(string $rate = '150'): void
    {
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [
            ['code' => 'JPY', 'rounding' => 'none', 'charm' => 'none', 'adjustment' => 0],
        ]]);
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        RateFixture::set('JPY', $rate);
    }

    /**
     * One yen is 100 stored units: every persisted amount is a multiple of 100.
     */
    private function assertWholeYen(Order $order): void
    {
        foreach (['subtotal', 'coupon_discount_total', 'manual_discount_total', 'total_amount'] as $column) {
            $this->assertSame(0, (int) $order->{$column} % 100, $column . ' is whole yen: ' . $order->{$column});
        }
        foreach ($this->lineDiscounts($order) as $lineDiscount) {
            $this->assertSame(0, $lineDiscount % 100, 'line discount is whole yen: ' . $lineDiscount);
        }
        foreach ($this->couponAmounts($order) as $code => $amount) {
            $this->assertSame(0, $amount % 100, $code . ' record is whole yen: ' . $amount);
        }
    }

    /**
     * @return int[] each order line's discount_total, in line order
     */
    private function lineDiscounts(Order $order): array
    {
        return array_map('intval', OrderItem::query()->where('order_id', $order->id)->orderBy('id')->pluck('discount_total')->toArray());
    }

    /**
     * Apply the coupons as the Create Order screen does (no order yet, base
     * prices), then place the order in $currency.
     */
    private function placeWithCoupons(int $unitPrice, int $lines, array $codes, string $currency = 'GBP'): Order
    {
        return $this->placeOrder(array_fill(0, $lines, $unitPrice), $codes, $currency);
    }

    /**
     * @param int[] $unitPrices one line per base price
     */
    private function placeOrder(array $unitPrices, array $codes, string $currency, array $discount = []): Order
    {
        return $this->placeLines($this->makeLines($unitPrices), $codes, $currency, $discount);
    }

    /**
     * Place an order whose first line alone carries a fixed coupon of
     * $couponAmount base cents (restricted to that line's variant).
     *
     * @param int[] $unitPrices one line per base price
     */
    private function placeOrderWithFirstLineCoupon(array $unitPrices, int $couponAmount, string $currency, array $discount): Order
    {
        $items = $this->makeLines($unitPrices);
        $coupon = (new CouponFactory())->create([
            'code'       => 'LINE-' . wp_generate_password(6, false),
            'amount'     => $couponAmount,
            'conditions' => ['included_products' => [$items[0]['object_id']]],
        ]);

        return $this->placeLines($items, [$coupon->code], $currency, $discount);
    }

    /**
     * @param int[] $unitPrices one line per base price
     * @return array[] order lines as the Create Order screen sends them
     */
    private function makeLines(array $unitPrices): array
    {
        $items = [];
        foreach ($unitPrices as $unitPrice) {
            $product = (new ProductFactory())->withVariantAttributes(['item_price' => $unitPrice])->create();
            $variant = $product->variants->first();
            $items[] = [
                'id'             => $variant->id,
                'variation_id'   => $variant->id,
                'type'           => 'product',
                'post_id'        => $product->ID,
                'object_id'      => $variant->id,
                'title'          => 'Default',
                'post_title'     => 'Product',
                'unit_price'     => $unitPrice,
                'quantity'       => 1,
                'subtotal'       => $unitPrice,
                'line_total'     => $unitPrice,
                'discount_total' => 0,
                'payment_type'   => 'onetime',
                'other_info'     => ['payment_type' => 'onetime'],
            ];
        }

        return $items;
    }

    /**
     * Apply the coupons to the lines as the Create Order screen does, then
     * place the order in $currency.
     */
    private function placeLines(array $items, array $codes, string $currency, array $discount = []): Order
    {
        $order = $this->placeLinesResult($items, $codes, $currency, $discount);
        $this->assertInstanceOf(Order::class, $order, is_wp_error($order) ? $order->get_error_message() : '');

        return $order;
    }

    /**
     * @return Order|\WP_Error
     */
    private function placeLinesResult(array $items, array $codes, string $currency, array $discount = [])
    {
        $customer = (new CustomerFactory())->create();
        $appliedIds = [];
        $applied = null;
        foreach ($codes as $code) {
            $applied = CouponResource::applyCoupon([
                'coupon_code'     => $code,
                'order_items'     => $applied ? array_values($applied['calculated_items']) : $items,
                'applied_coupons' => $appliedIds,
                'customer_email'  => $customer->email,
            ]);
            $this->assertIsArray($applied, is_wp_error($applied) ? $applied->get_error_message() : '');
            foreach ($applied['applied_coupons'] as $record) {
                $appliedIds[] = $record['id'];
            }
            $appliedIds = array_values(array_unique($appliedIds));
        }

        $payload = [
            'customer_id'    => $customer->id,
            'currency'       => $currency,
            'order_items'    => $applied ? array_values($applied['calculated_items']) : $items,
            'applied_coupon' => $applied ? $applied['applied_coupons'] : [],
            'shipping_total' => 0,
            'discount'       => $discount,
            'shipping'       => [],
            'labels'         => [],
            'user_tz'        => 'UTC',
        ];

        // as the Create Order screen does: preview, then save what it showed
        $preview = AdminOrderPricing::price($payload, $customer->email);

        return OrderResource::updatedPlaceOrder(array_merge($payload, [
            'quote_revision' => is_wp_error($preview) ? 'not-previewed' : $preview['quote_revision'],
            'total_amount'   => is_wp_error($preview) ? 0 : $preview['total'],
        ]));
    }

    private function assertRejectedWithoutOrder(callable $place): void
    {
        $ordersBefore = Order::query()->count();
        $result = $place();
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_amount', $result->get_error_code());
        $this->assertSame($ordersBefore, Order::query()->count());
    }

    /**
     * @return array<string, int> code => amount
     */
    private function couponAmounts(Order $order): array
    {
        return array_map('intval', AppliedCoupon::query()->where('order_id', $order->id)->pluck('amount', 'code')->toArray());
    }
}

<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\Models\AppliedCoupon;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Coupon;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CouponFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\PlacesCheckoutOrders;

/**
 * Spec: Cases/checkout-functional.md → "Placing an order — coupons" section, items 19-25.
 *
 * TC-103 (REST half). The coupon is put on the cart through `Cart::applyCoupon()`,
 * exactly as the web ingress does, and `place-order` is what is under test: the
 * cents it persists to fct_orders, fct_order_items, fct_applied_coupons and the
 * coupon use_count, and what it drops when the coupon stopped being valid.
 */
class CheckoutCouponCest
{
    use PlacesCheckoutOrders;

    public function _before(FunctionalTester $I): void
    {
        $I->logout();
        $this->registerStubCheckoutGateway();
    }

    public function _after(FunctionalTester $I): void
    {
        $this->removeStubCheckoutGateway();
    }

    /**
     * Spec: Cases/checkout-functional.md [19]
     *   "A percentage coupon is rounded to whole cents once, and the same cents appear on the
     *    order total, the order item, and the recorded coupon usage."
     */
    public function percentageCouponIsRoundedOnceAndPersistedEverywhere(FunctionalTester $I): void
    {
        $coupon = (new CouponFactory())->createPercentage(['code' => 'TEN10', 'amount' => 10]);
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1995])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 3]]);
        $cart->applyCoupon([$coupon->code]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'coupon checkout must succeed. Body: ' . wp_json_encode($body));

        // 10% of the 5985-cent line = 598.5 → 599 when rounded ONCE at the line.
        // A per-unit implementation would give round(199.5) × 3 = 600, so 599 is the discriminator.
        Assert::assertSame(1, Order::query()->count(), 'one submission must leave exactly one order to inspect');
        $order = Order::query()->first();

        // Money is read from the raw columns, not the models: fct_applied_coupons.amount is a
        // DOUBLE and the order/item cent columns cast to double on the model, so an (int) cast
        // would silently accept a fractional-cent regression such as 599.5.
        global $wpdb;
        $orderRow = $wpdb->get_row($wpdb->prepare("SELECT subtotal, coupon_discount_total, total_amount FROM {$wpdb->prefix}fct_orders WHERE id = %d", $order->id), ARRAY_A);
        Assert::assertNotNull($orderRow, 'the created order must be readable as a raw row');
        foreach ($orderRow as $column => $value) {
            Assert::assertMatchesRegularExpression('/^-?\d+$/', (string) $value, "fct_orders.{$column} must be stored as integer cents, got: {$value}");
        }
        Assert::assertSame(5985, (int) $orderRow['subtotal'], 'subtotal must stay the undiscounted 3 × 1995 cents');
        Assert::assertSame(599, (int) $orderRow['coupon_discount_total'], 'a 10% coupon on a 5985 cent line must round once to 599 cents, not per unit to 600');
        Assert::assertSame(5386, (int) $orderRow['total_amount'], 'order total must be subtotal minus the once-rounded coupon cents');

        $itemRow = $wpdb->get_row($wpdb->prepare("SELECT discount_total, line_total, line_meta FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d", $order->id), ARRAY_A);
        Assert::assertNotNull($itemRow, 'the order item must be readable as a raw row');
        foreach (['discount_total', 'line_total'] as $column) {
            Assert::assertMatchesRegularExpression('/^-?\d+$/', (string) $itemRow[$column], "fct_order_items.{$column} must be stored as integer cents, got: {$itemRow[$column]}");
        }
        Assert::assertSame(599, (int) $itemRow['discount_total'], 'the order item must carry the same 599 discount cents');
        Assert::assertSame(5386, (int) $itemRow['line_total'], 'the order item line total must be its subtotal minus the discount');
        $meta = json_decode((string) $itemRow['line_meta'], true);
        Assert::assertIsArray($meta, 'the order item line_meta must be stored as decodable JSON');
        Assert::assertArrayHasKey('coupon_discount', $meta, 'the order item meta must attribute the discount to the coupon');
        Assert::assertMatchesRegularExpression('/^-?\d+$/', (string) $meta['coupon_discount'], 'line_meta.coupon_discount must be whole cents, got: ' . var_export($meta['coupon_discount'], true));
        Assert::assertSame(599, (int) $meta['coupon_discount'], 'the order item meta must attribute the 599 cents to the coupon');

        $appliedRow = $wpdb->get_row($wpdb->prepare("SELECT coupon_id, amount FROM {$wpdb->prefix}fct_applied_coupons WHERE order_id = %d", $order->id), ARRAY_A);
        Assert::assertNotNull($appliedRow, 'checkout must record the coupon usage on the order');
        Assert::assertSame((int) $coupon->id, (int) $appliedRow['coupon_id'], 'the usage row must reference the coupon');
        Assert::assertMatchesRegularExpression('/^-?\d+$/', (string) $appliedRow['amount'], "fct_applied_coupons.amount must be stored as integer cents, got: {$appliedRow['amount']}");
        Assert::assertSame(599, (int) $appliedRow['amount'], 'the usage row must record the same 599 discount cents');
    }

    /**
     * Spec: Cases/checkout-functional.md [20]
     *   "A fixed coupon spread over several lines is split in proportion to each line and the
     *    line shares add up to the coupon amount exactly."
     */
    public function fixedCouponIsSplitAcrossLinesAndAddsUpExactly(FunctionalTester $I): void
    {
        $coupon = (new CouponFactory())->create(['code' => 'TEN1000', 'type' => 'fixed', 'amount' => 1000]);
        $small  = (new ProductFactory())->withVariantAttributes(['item_price' => 500])->create()->variants->first();
        $medium = (new ProductFactory())->withVariantAttributes(['item_price' => 1250])->create()->variants->first();
        $large  = (new ProductFactory())->withVariantAttributes(['item_price' => 2000])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$small, 1], [$medium, 1], [$large, 1]]);
        $cart->applyCoupon([$coupon->code]);

        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'coupon checkout must succeed. Body: ' . wp_json_encode($body));

        Assert::assertSame(1, Order::query()->count(), 'one submission must leave exactly one order to inspect');
        $order = Order::query()->first();

        // Raw columns, not model attributes — an (int) cast would accept a fractional cent.
        global $wpdb;
        $orderRow = $wpdb->get_row($wpdb->prepare("SELECT coupon_discount_total, total_amount FROM {$wpdb->prefix}fct_orders WHERE id = %d", $order->id), ARRAY_A);
        Assert::assertNotNull($orderRow, 'the created order must be readable as a raw row');
        foreach ($orderRow as $column => $value) {
            Assert::assertMatchesRegularExpression('/^-?\d+$/', (string) $value, "fct_orders.{$column} must be stored as integer cents, got: {$value}");
        }
        Assert::assertSame(1000, (int) $orderRow['coupon_discount_total'], 'the order must carry the whole 1000 cent coupon');
        Assert::assertSame(3750 - 1000, (int) $orderRow['total_amount'], 'order total must be the three lines minus the coupon');

        // Unequal lines prove proportional weighting AND the remainder correction at once:
        // 1000 / 3750 = 26.67% → 500 × 0.2667 = 133, 1250 × 0.2667 = 333, 2000 × 0.2667 = 533 = 999,
        // then the lost cent is handed back to the first cart line → 134 / 333 / 533.
        // An equal split (334/333/333) or a missing correction (133/333/533) both fail here.
        $itemRows = $wpdb->get_results($wpdb->prepare("SELECT object_id, discount_total, line_total, line_meta FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d", $order->id), ARRAY_A);
        Assert::assertCount(3, $itemRows, 'three cart lines must produce exactly three order item rows — counted before keying, so a duplicated line cannot hide');
        $rawDiscountSum = 0;
        foreach ($itemRows as $row) {
            foreach (['discount_total', 'line_total'] as $column) {
                Assert::assertMatchesRegularExpression('/^-?\d+$/', (string) $row[$column], "fct_order_items.{$column} must be stored as integer cents, got: {$row[$column]}");
            }
            $rawDiscountSum += (int) $row['discount_total'];
        }
        Assert::assertSame(1000, $rawDiscountSum, 'the raw item rows must carry exactly the coupon amount in total');
        $byVariation = [];
        foreach ($itemRows as $row) {
            $byVariation[(int) $row['object_id']] = $row;
        }
        Assert::assertCount(3, $byVariation, 'each of the three rows must belong to a distinct variation');
        $expected = [
            (int) $small->id  => ['discount' => 134, 'line_total' => 500 - 134],
            (int) $medium->id => ['discount' => 333, 'line_total' => 1250 - 333],
            (int) $large->id  => ['discount' => 533, 'line_total' => 2000 - 533],
        ];
        foreach ($expected as $variationId => $line) {
            $row = $byVariation[$variationId] ?? null;
            Assert::assertNotNull($row, "an order item must exist for variation {$variationId}");
            Assert::assertSame($line['discount'], (int) $row['discount_total'], "variation {$variationId} must take its proportional share (plus the restored cent on the first line)");
            Assert::assertSame($line['line_total'], (int) $row['line_total'], "variation {$variationId} line total must be its subtotal minus its exact share");
            $meta = json_decode((string) $row['line_meta'], true);
            Assert::assertIsArray($meta, "variation {$variationId} line_meta must be stored as decodable JSON");
            Assert::assertArrayHasKey('coupon_discount', $meta, "variation {$variationId} item meta must attribute its share to the coupon");
            Assert::assertMatchesRegularExpression('/^-?\d+$/', (string) $meta['coupon_discount'], "variation {$variationId} line_meta.coupon_discount must be whole cents, got: " . var_export($meta['coupon_discount'], true));
            Assert::assertSame($line['discount'], (int) $meta['coupon_discount'], "variation {$variationId} item meta must attribute its exact share to the coupon");
        }
        Assert::assertSame(1000, array_sum(array_column($expected, 'discount')), 'the three shares must add up to the coupon amount exactly');

        $appliedRow = $wpdb->get_row($wpdb->prepare("SELECT amount FROM {$wpdb->prefix}fct_applied_coupons WHERE order_id = %d AND coupon_id = %d", $order->id, $coupon->id), ARRAY_A);
        Assert::assertNotNull($appliedRow, 'checkout must record the coupon usage on the order');
        Assert::assertMatchesRegularExpression('/^-?\d+$/', (string) $appliedRow['amount'], "fct_applied_coupons.amount must be stored as integer cents, got: {$appliedRow['amount']}");
        Assert::assertSame(1000, (int) $appliedRow['amount'], 'the usage row must record the full 1000 cents, not the 999 left by uncorrected rounding');
    }

    /**
     * Spec: Cases/checkout-functional.md [21]
     *   "Two stackable coupons on one cart are both recorded on the order, each counted as used
     *    once, and their discounts add up on the order total."
     */
    public function twoStackableCouponsAreBothRecordedAndCounted(FunctionalTester $I): void
    {
        $first  = (new CouponFactory())->create(['code' => 'STACK300', 'type' => 'fixed', 'amount' => 300, 'stackable' => 'yes', 'priority' => 1]);
        $second = (new CouponFactory())->create(['code' => 'STACK200', 'type' => 'fixed', 'amount' => 200, 'stackable' => 'yes', 'priority' => 2]);
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 2500])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);

        // Applied one code per call, the way a shopper reaches the cart twice: the second call
        // only sees the first coupon because DiscountService::applyCouponCodes() merges the
        // codes already stored on the cart. A batch call in one go would not exercise that.
        $cart->applyCoupon([$first->code]);
        $afterFirst = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        Assert::assertSame([$first->code], array_values((array) $afterFirst->coupons), 'the first coupon must be stored on the cart before the second is applied');

        $cart->applyCoupon([$second->code]);
        $afterSecond = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        $storedCodes = array_values((array) $afterSecond->coupons);
        sort($storedCodes);
        Assert::assertSame([$second->code, $first->code], $storedCodes, 'applying the second stackable coupon must keep the first, not replace it');

        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'stacked coupon checkout must succeed. Body: ' . wp_json_encode($body));

        Assert::assertSame(1, Order::query()->count(), 'one submission must leave exactly one order to inspect');
        $order = Order::query()->first();

        // Raw columns, not model attributes — an (int) cast would accept a fractional cent.
        global $wpdb;
        $orderRow = $wpdb->get_row($wpdb->prepare("SELECT coupon_discount_total, total_amount FROM {$wpdb->prefix}fct_orders WHERE id = %d", $order->id), ARRAY_A);
        Assert::assertNotNull($orderRow, 'the created order must be readable as a raw row');
        foreach ($orderRow as $column => $value) {
            Assert::assertMatchesRegularExpression('/^-?\d+$/', (string) $value, "fct_orders.{$column} must be stored as integer cents, got: {$value}");
        }
        Assert::assertSame(500, (int) $orderRow['coupon_discount_total'], 'both coupon amounts must add up on the order');
        Assert::assertSame(2000, (int) $orderRow['total_amount'], 'order total must be the price minus both coupons');

        $appliedRows = $wpdb->get_results($wpdb->prepare("SELECT code, amount FROM {$wpdb->prefix}fct_applied_coupons WHERE order_id = %d", $order->id), ARRAY_A);
        Assert::assertCount(2, $appliedRows, 'exactly two coupon usage rows must be recorded — counted before keying, so a duplicated row cannot hide');
        $rawAmountSum = 0;
        $applied = [];
        foreach ($appliedRows as $row) {
            Assert::assertMatchesRegularExpression('/^-?\d+$/', (string) $row['amount'], "fct_applied_coupons.amount must be stored as integer cents, got: {$row['amount']}");
            $rawAmountSum += (int) $row['amount'];
            $applied[(string) $row['code']] = $row;
        }
        Assert::assertSame(500, $rawAmountSum, 'the raw usage rows must add up to exactly the two coupon amounts');
        Assert::assertCount(2, $applied, 'the two usage rows must be for two distinct codes');
        Assert::assertSame(300, (int) $applied['STACK300']['amount'], 'the first coupon usage must record its 300 cents');
        Assert::assertSame(200, (int) $applied['STACK200']['amount'], 'the second coupon usage must record its 200 cents');
        Assert::assertSame(1, (int) $first->fresh()->use_count, 'the first coupon must be counted as used once');
        Assert::assertSame(1, (int) $second->fresh()->use_count, 'the second coupon must be counted as used once');
    }

    /**
     * Spec: Cases/checkout-functional.md [22]
     *   "When a non-stackable coupon is on the cart, applying a second coupon is refused on the
     *    cart and checkout records only the first."
     */
    public function secondCouponIsRefusedNextToNonStackableOne(FunctionalTester $I): void
    {
        $solo  = (new CouponFactory())->create(['code' => 'SOLO400', 'type' => 'fixed', 'amount' => 400, 'stackable' => 'no', 'priority' => 1]);
        $extra = (new CouponFactory())->create(['code' => 'EXTRA100', 'type' => 'fixed', 'amount' => 100, 'stackable' => 'yes', 'priority' => 2]);
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 2500])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);

        // Sequential, because spec item 22 is about a coupon ALREADY on the cart: the refusal
        // depends on applyCouponCodes() merging the stored code into the second call. Passing
        // both codes at once decides stackability inside a single batch and would stay green
        // even if that merge were deleted.
        $cart->applyCoupon([$solo->code]);
        $afterSolo = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        Assert::assertSame([$solo->code], array_values((array) $afterSolo->coupons), 'the non-stackable coupon must be stored on the cart before the second is attempted');
        Assert::assertSame(400, array_sum(array_column((array) $afterSolo->cart_data, 'coupon_discount')), 'the stored cart must carry the 400 cent discount before the second coupon is attempted');

        $result = $cart->applyCoupon([$extra->code]);
        Assert::assertNotEmpty($result['coupon_results']['EXTRA100']['error'] ?? null, 'the cart must refuse a second coupon next to one already-stored non-stackable coupon');

        $afterExtra = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        Assert::assertSame([$solo->code], array_values((array) $afterExtra->coupons), 'the refused coupon must leave the stored cart carrying only the non-stackable one');
        Assert::assertSame(400, array_sum(array_column((array) $afterExtra->cart_data, 'coupon_discount')), 'the refusal must leave the first coupon\'s discount untouched on the cart');

        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'checkout with the surviving coupon must succeed. Body: ' . wp_json_encode($body));

        Assert::assertSame(1, Order::query()->count(), 'one submission must leave exactly one order to inspect');
        $order = Order::query()->first();
        // Raw columns, not model attributes — an (int) cast would accept a fractional cent.
        global $wpdb;
        $orderRow = $wpdb->get_row($wpdb->prepare("SELECT coupon_discount_total, total_amount FROM {$wpdb->prefix}fct_orders WHERE id = %d", $order->id), ARRAY_A);
        Assert::assertNotNull($orderRow, 'the created order must be readable as a raw row');
        foreach ($orderRow as $column => $value) {
            Assert::assertMatchesRegularExpression('/^-?\\d+$/', (string) $value, "fct_orders.{$column} must be stored as integer cents, got: {$value}");
        }
        Assert::assertSame(400, (int) $orderRow['coupon_discount_total'], 'only the non-stackable coupon may discount the order');
        Assert::assertSame(2100, (int) $orderRow['total_amount'], 'order total must reflect only the non-stackable coupon');
        Assert::assertSame(1, AppliedCoupon::query()->where('order_id', $order->id)->count(), 'only one coupon usage may be recorded');
        Assert::assertSame('SOLO400', (string) AppliedCoupon::query()->where('order_id', $order->id)->first()->code, 'the recorded usage must be the non-stackable coupon');
        Assert::assertSame(0, (int) $extra->fresh()->use_count, 'the refused coupon must not be counted as used');
    }

    /**
     * Spec: Cases/checkout-functional.md [23]
     *   "A coupon that expires between carting and checkout is dropped at checkout: the order is
     *    charged full price and the coupon is not recorded or counted."
     */
    public function couponExpiredSinceCartingIsDroppedAtCheckout(FunctionalTester $I): void
    {
        $coupon = (new CouponFactory())->create(['code' => 'LATE500', 'type' => 'fixed', 'amount' => 500]);
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 2500])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);
        $applied = $cart->applyCoupon([$coupon->code]);
        Assert::assertFalse(is_wp_error($applied), 'the coupon must apply cleanly while still valid');
        $carted = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        Assert::assertSame(['LATE500'], array_values((array) $carted->coupons), 'the coupon must sit on the stored cart before it expires');
        Assert::assertSame(500, array_sum(array_column((array) $carted->cart_data, 'coupon_discount')), 'the stored cart must already carry the 500 cent discount before the coupon expires');

        Coupon::query()->where('id', $coupon->id)->update(['end_date' => gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)]);

        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'checkout must still succeed without the expired coupon. Body: ' . wp_json_encode($body));

        Assert::assertSame(1, Order::query()->count(), 'one submission must leave exactly one order to inspect');
        $order = Order::query()->first();
        // Raw columns, not model attributes — an (int) cast would accept a fractional cent.
        global $wpdb;
        $orderRow = $wpdb->get_row($wpdb->prepare("SELECT coupon_discount_total, total_amount FROM {$wpdb->prefix}fct_orders WHERE id = %d", $order->id), ARRAY_A);
        Assert::assertNotNull($orderRow, 'the created order must be readable as a raw row');
        foreach ($orderRow as $column => $value) {
            Assert::assertMatchesRegularExpression('/^-?\\d+$/', (string) $value, "fct_orders.{$column} must be stored as integer cents, got: {$value}");
        }
        Assert::assertSame(0, (int) $orderRow['coupon_discount_total'], 'an expired coupon must not discount the order');
        Assert::assertSame(2500, (int) $orderRow['total_amount'], 'the order must be charged full price');
        Assert::assertSame(0, AppliedCoupon::query()->where('order_id', $order->id)->count(), 'an expired coupon must not be recorded on the order');
        $afterCheckout = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        Assert::assertSame([], array_values((array) $afterCheckout->coupons), 'an expired coupon must be removed from the stored cart at checkout');
        Assert::assertSame(0, array_sum(array_column((array) $afterCheckout->cart_data, 'coupon_discount')), 'the stored cart lines must carry no coupon discount once an expired coupon is dropped');
        Assert::assertSame(0, (int) $coupon->fresh()->use_count, 'an expired coupon must not be counted as used');
    }

    /**
     * Spec: Cases/checkout-functional.md [24]
     *   "A coupon that is deactivated between carting and checkout is dropped at checkout the same way."
     */
    public function couponDeactivatedSinceCartingIsDroppedAtCheckout(FunctionalTester $I): void
    {
        $coupon = (new CouponFactory())->create(['code' => 'OFF500', 'type' => 'fixed', 'amount' => 500]);
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 2500])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);
        $applied = $cart->applyCoupon([$coupon->code]);
        Assert::assertFalse(is_wp_error($applied), 'the coupon must apply cleanly while still active');
        $carted = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        Assert::assertSame(['OFF500'], array_values((array) $carted->coupons), 'the coupon must sit on the stored cart before it is deactivated');
        Assert::assertSame(500, array_sum(array_column((array) $carted->cart_data, 'coupon_discount')), 'the stored cart must already carry the 500 cent discount before the coupon is deactivated');

        Coupon::query()->where('id', $coupon->id)->update(['status' => 'inactive']);

        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'checkout must still succeed without the deactivated coupon. Body: ' . wp_json_encode($body));

        Assert::assertSame(1, Order::query()->count(), 'one submission must leave exactly one order to inspect');
        $order = Order::query()->first();
        // Raw columns, not model attributes — an (int) cast would accept a fractional cent.
        global $wpdb;
        $orderRow = $wpdb->get_row($wpdb->prepare("SELECT coupon_discount_total, total_amount FROM {$wpdb->prefix}fct_orders WHERE id = %d", $order->id), ARRAY_A);
        Assert::assertNotNull($orderRow, 'the created order must be readable as a raw row');
        foreach ($orderRow as $column => $value) {
            Assert::assertMatchesRegularExpression('/^-?\\d+$/', (string) $value, "fct_orders.{$column} must be stored as integer cents, got: {$value}");
        }
        Assert::assertSame(0, (int) $orderRow['coupon_discount_total'], 'a deactivated coupon must not discount the order');
        Assert::assertSame(2500, (int) $orderRow['total_amount'], 'the order must be charged full price');
        Assert::assertSame(0, AppliedCoupon::query()->where('order_id', $order->id)->count(), 'a deactivated coupon must not be recorded on the order');
        $afterCheckout = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        Assert::assertSame([], array_values((array) $afterCheckout->coupons), 'a deactivated coupon must be removed from the stored cart at checkout');
        Assert::assertSame(0, array_sum(array_column((array) $afterCheckout->cart_data, 'coupon_discount')), 'the stored cart lines must carry no coupon discount once a deactivated coupon is dropped');
        Assert::assertSame(0, (int) $coupon->fresh()->use_count, 'a deactivated coupon must not be counted as used');
    }

    /**
     * Spec: Cases/checkout-functional.md [25]
     *   "A coupon whose usage limit is reached between carting and checkout is dropped at checkout the same way."
     */
    public function couponAtUsageLimitSinceCartingIsDroppedAtCheckout(FunctionalTester $I): void
    {
        $coupon = (new CouponFactory())->create(['code' => 'LIMIT500', 'type' => 'fixed', 'amount' => 500, 'conditions' => ['max_uses' => 1]]);
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 2500])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);
        $applied = $cart->applyCoupon([$coupon->code]);
        Assert::assertFalse(is_wp_error($applied), 'a max_uses coupon with zero uses must apply cleanly');
        $carted = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        Assert::assertSame(['LIMIT500'], array_values((array) $carted->coupons), 'the coupon must sit on the stored cart before its limit is reached');
        Assert::assertSame(500, array_sum(array_column((array) $carted->cart_data, 'coupon_discount')), 'the stored cart must already carry the 500 cent discount before the limit is reached');

        // Another shopper used the last allowed redemption while this cart sat open.
        Coupon::query()->where('id', $coupon->id)->update(['use_count' => 1]);

        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'checkout must still succeed without the exhausted coupon. Body: ' . wp_json_encode($body));

        Assert::assertSame(1, Order::query()->count(), 'one submission must leave exactly one order to inspect');
        $order = Order::query()->first();
        // Raw columns, not model attributes — an (int) cast would accept a fractional cent.
        global $wpdb;
        $orderRow = $wpdb->get_row($wpdb->prepare("SELECT coupon_discount_total, total_amount FROM {$wpdb->prefix}fct_orders WHERE id = %d", $order->id), ARRAY_A);
        Assert::assertNotNull($orderRow, 'the created order must be readable as a raw row');
        foreach ($orderRow as $column => $value) {
            Assert::assertMatchesRegularExpression('/^-?\\d+$/', (string) $value, "fct_orders.{$column} must be stored as integer cents, got: {$value}");
        }
        Assert::assertSame(0, (int) $orderRow['coupon_discount_total'], 'an exhausted coupon must not discount the order');
        Assert::assertSame(2500, (int) $orderRow['total_amount'], 'the order must be charged full price');
        Assert::assertSame(0, AppliedCoupon::query()->where('order_id', $order->id)->count(), 'an exhausted coupon must not be recorded on the order');
        $afterCheckout = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        Assert::assertSame([], array_values((array) $afterCheckout->coupons), 'an exhausted coupon must be removed from the stored cart at checkout');
        Assert::assertSame(0, array_sum(array_column((array) $afterCheckout->cart_data, 'coupon_discount')), 'the stored cart lines must carry no coupon discount once an exhausted coupon is dropped');
        Assert::assertSame(1, (int) $coupon->fresh()->use_count, 'an exhausted coupon must not be counted again');
    }
}

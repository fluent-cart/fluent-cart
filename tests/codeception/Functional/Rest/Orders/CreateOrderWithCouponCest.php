<?php

namespace Tests\Functional\Rest\Orders;

use FluentCart\App\Helpers\Helper;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CouponFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/orders.md → "Creating an order" section, items 26d-26g.
 *
 * The admin Create Order screen applies a coupon by POSTing coupons/apply and then
 * feeding that endpoint's `applied_coupons` map straight back into the order payload
 * as `applied_coupon`. These cases pin that hand-off end to end: whatever
 * coupons/apply produces must be accepted by orders/create and must persist.
 */
class CreateOrderWithCouponCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/orders.md [26d]
     *   "Admin can create a manual order with a coupon applied, using the exact applied-coupon
     *    payload the apply-coupon endpoint returns, and the discount is recorded against the order."
     */
    public function adminCanCreateOrderWithTheAppliedCouponPayloadTheApplyEndpointReturns(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $customer = (new CustomerFactory())->create(['email' => 'create-order-with-coupon@example.test']);
        $coupon   = (new CouponFactory())->create(['code' => 'MINMAX', 'type' => 'fixed', 'amount' => 1000]);
        $product  = (new ProductFactory())
            ->withVariantAttributes(['item_price' => Helper::toCent(50)])
            ->create();
        $variant  = $product->variants->first();

        $orderItems = [$this->lineItem((int) $variant->id, (int) $product->ID)];

        // Step 1 — exactly what the Apply button does.
        [$applyStatus, $applyBody] = $I->post('/fluent-cart/v2/coupons/apply', [
            'coupon_code'     => 'MINMAX',
            'order_items'     => $orderItems,
            'applied_coupons' => [],
        ]);

        Assert::assertSame(200, $applyStatus, 'applying a valid coupon to a draft order must succeed');
        $appliedCoupons = (array) ($applyBody['applied_coupons'] ?? []);
        Assert::assertArrayHasKey(
            'MINMAX',
            $appliedCoupons,
            'apply-coupon must return the discount data keyed by coupon code'
        );

        // Step 2 — Save, with the apply response handed through untouched, as the admin UI does.
        [$createStatus, $createBody] = $I->createOrder([
            'customer_id'    => $customer->id,
            'order_items'    => $orderItems,
            'applied_coupon' => $appliedCoupons,
        ]);

        Assert::assertSame(
            200,
            $createStatus,
            'creating an order must accept the applied-coupon payload produced by coupons/apply, got: '
            . wp_json_encode($createBody)
        );

        $orderId = (int) ($createBody['order_id'] ?? 0);
        Assert::assertGreaterThan(0, $orderId, 'create response must include a positive order id');

        global $wpdb;
        $appliedRow = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fct_applied_coupons WHERE order_id = %d",
                $orderId
            ),
            ARRAY_A
        );

        Assert::assertNotEmpty($appliedRow, 'the applied coupon must be persisted against the created order');
        Assert::assertSame(
            (int) $coupon->id,
            (int) $appliedRow['coupon_id'],
            'the persisted applied-coupon row must point at the coupon that was applied'
        );
        Assert::assertSame(
            'MINMAX',
            (string) $appliedRow['code'],
            'the persisted applied-coupon row must carry the coupon code'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [26e]
     *   "Admin is rejected when creating an order whose applied-coupon entry omits the coupon id
     *    the order processor needs to link the discount."
     */
    public function adminIsRejectedWhenAppliedCouponEntryOmitsTheCouponId(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $customer = (new CustomerFactory())->create(['email' => 'coupon-without-id@example.test']);
        (new CouponFactory())->create(['code' => 'NOID', 'type' => 'fixed', 'amount' => 1000]);
        $product = (new ProductFactory())
            ->withVariantAttributes(['item_price' => Helper::toCent(50)])
            ->create();
        $variant = $product->variants->first();

        [$status, $body] = $I->createOrder([
            'customer_id'    => $customer->id,
            'order_items'    => [$this->lineItem((int) $variant->id, (int) $product->ID)],
            'applied_coupon' => [
                'NOID' => [
                    // No `id` — the key AdminOrderProcessor reads to write coupon_id.
                    'discount' => 1000,
                    'type'     => 'fixed',
                ],
            ],
        ]);

        Assert::assertSame(422, $status, 'an applied-coupon entry without a coupon id must be rejected');
        Assert::assertArrayHasKey(
            'applied_coupon',
            (array) $body,
            'validation payload must key the failed rule by field name (applied_coupon). Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            'NOID',
            wp_json_encode($body['applied_coupon'] ?? []),
            'the validation message must name the offending coupon code. Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        $appliedCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_applied_coupons");
        Assert::assertSame(0, $appliedCount, 'a rejected applied-coupon payload must not persist an applied-coupon row');
    }

    public function pricingAcceptsANewCouponCodeWithoutIdAndReturnsItsDiscountData(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $coupon  = (new CouponFactory())->create(['code' => 'PRICEME', 'type' => 'fixed', 'amount' => 1000]);
        $product = (new ProductFactory())
            ->withVariantAttributes(['item_price' => Helper::toCent(50)])
            ->create();
        $variant = $product->variants->first();

        [$status, $body] = $I->post('/fluent-cart/v2/orders/price', [
            'order_items'    => [$this->lineItem((int) $variant->id, (int) $product->ID)],
            'applied_coupon' => ['PRICEME' => []],
        ]);

        Assert::assertSame(200, $status, 'pricing must accept a coupon code the admin is applying. Body: ' . wp_json_encode($body));
        Assert::assertSame((int) $coupon->id, (int) ($body['applied_coupons']['PRICEME']['id'] ?? 0));
        Assert::assertSame(1000, (int) ($body['applied_coupons']['PRICEME']['discount'] ?? 0));

        [$status, $body] = $I->post('/fluent-cart/v2/orders/price', [
            'order_items'    => [$this->lineItem((int) $variant->id, (int) $product->ID)],
            'applied_coupon' => ['NOSUCHCODE' => []],
        ]);

        Assert::assertSame(422, $status, 'pricing must reject an unknown coupon code. Body: ' . wp_json_encode($body));
    }

    /**
     * Spec: Cases/Functional/orders.md [26f]
     *   "Admin is rejected when creating an order that combines a non-stackable coupon with
     *    another coupon, and no order is created and no coupon usage is recorded."
     */
    public function adminIsRejectedWhenCreatingAnOrderThatStacksANonStackableCoupon(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $customer = (new CustomerFactory())->create(['email' => 'stacked-coupons@example.test']);
        $solo     = (new CouponFactory())->createNonStackable(['code' => 'SOLO', 'type' => 'fixed', 'amount' => 1000]);
        $extra    = (new CouponFactory())->create(['code' => 'EXTRA', 'type' => 'fixed', 'amount' => 500]);
        $product  = (new ProductFactory())
            ->withVariantAttributes(['item_price' => Helper::toCent(50)])
            ->create();
        $variant  = $product->variants->first();

        $orderItems = [$this->lineItem((int) $variant->id, (int) $product->ID)];
        $orderItems[0]['discount_total'] = 1500;

        [$status, $body] = $I->createOrder([
            'customer_id'    => $customer->id,
            'order_items'    => $orderItems,
            'applied_coupon' => [
                'SOLO'  => ['id' => (int) $solo->id, 'discount' => 1000, 'type' => 'fixed'],
                'EXTRA' => ['id' => (int) $extra->id, 'discount' => 500, 'type' => 'fixed'],
            ],
        ]);

        Assert::assertSame(
            422,
            $status,
            'an order stacking a non-stackable coupon must be rejected. Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        $orderCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders");
        Assert::assertSame(0, $orderCount, 'a rejected coupon combination must not create an order');

        $appliedCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_applied_coupons");
        Assert::assertSame(0, $appliedCount, 'a rejected coupon combination must not persist applied-coupon rows');

        $useCounts = $wpdb->get_col("SELECT use_count FROM {$wpdb->prefix}fct_coupons ORDER BY id");
        Assert::assertSame(['0', '0'], $useCounts, 'a rejected coupon combination must not increment either coupon use count');
    }

    /**
     * Spec: Cases/Functional/orders.md [26g]
     *   "The discount recorded for a coupon on a created order is the amount the store calculates,
     *    not an amount supplied in the request."
     */
    public function createdOrderRecordsTheCalculatedCouponDiscountNotTheSubmittedOne(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $customer = (new CustomerFactory())->create(['email' => 'inflated-coupon@example.test']);
        $coupon   = (new CouponFactory())->create(['code' => 'TENOFF', 'type' => 'fixed', 'amount' => 1000]);
        $product  = (new ProductFactory())
            ->withVariantAttributes(['item_price' => Helper::toCent(50)])
            ->create();
        $variant  = $product->variants->first();

        $orderItems = [$this->lineItem((int) $variant->id, (int) $product->ID)];
        $orderItems[0]['discount_total'] = 4900;

        [$status, $body] = $I->createOrder([
            'customer_id'    => $customer->id,
            'order_items'    => $orderItems,
            'applied_coupon' => [
                'TENOFF' => ['id' => (int) $coupon->id, 'discount' => 4900, 'type' => 'fixed'],
            ],
        ]);

        Assert::assertSame(200, $status, 'a valid coupon order must be created. Body: ' . wp_json_encode($body));
        $orderId = (int) ($body['order_id'] ?? 0);

        global $wpdb;
        $order = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT coupon_discount_total, total_amount FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                $orderId
            ),
            ARRAY_A
        );
        Assert::assertSame(1000, (int) $order['coupon_discount_total'], 'the order coupon discount must be the calculated $10.00, not the submitted $49.00');
        Assert::assertSame(4000, (int) $order['total_amount'], 'the order total must be $50.00 less the calculated $10.00 coupon');

        $amount = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT amount FROM {$wpdb->prefix}fct_applied_coupons WHERE order_id = %d",
                $orderId
            )
        );
        Assert::assertEquals(1000, (float) $amount, 'the applied-coupon row must store the calculated discount, not the submitted one');
    }

    private function lineItem(int $variantId, int $productId): array
    {
        $unitPrice = Helper::toCent(50);
        $quantity  = 1;

        return [
            'id'             => $variantId,
            'post_id'        => $productId,
            'object_id'      => $variantId,
            'variation_id'   => $variantId,
            'type'           => 'product',
            'quantity'       => $quantity,
            'title'          => 'Line',
            'price'          => $unitPrice,
            'unit_price'     => $unitPrice,
            'item_cost'      => 0,
            'item_total'     => $unitPrice * $quantity,
            'tax_amount'     => 0,
            'discount_total' => 0,
            'total'          => $unitPrice * $quantity,
            'line_total'     => $unitPrice * $quantity,
            'subtotal'       => $unitPrice * $quantity,
            'fulfillment_type' => 'digital',
            'payment_type'   => 'onetime',
            'other_info'     => ['payment_type' => 'onetime'],
        ];
    }
}

<?php

namespace Tests\Functional\Rest\Coupons;

use FluentCart\App\Models\AppliedCoupon;
use FluentCart\App\Models\Coupon;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\AppliedCouponFactory;
use Tests\Support\Factory\CouponFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/coupons.md → "Canceling an applied coupon" section, items 46-47.
 *
 * Route: POST /fluent-cart/v2/coupons/cancel (meta: orders/create OR orders/manage)
 * Controller: CouponsController::cancelCoupon — validates via FrontendRequests\CouponRequest,
 * delegates to CouponResource::cancelCoupon which (when both order_uuid + applied-coupon `id`
 * are supplied) deletes the matching fct_applied_coupons row, decrements fct_coupons.use_count
 * by 1, and re-runs CouponServiceAdmin so the response surfaces calculated_items with the
 * canceled coupon's discount stripped from applied_coupons.
 */
class CancelCouponCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    /**
     * Build a single-item line-items array compatible with the FrontendRequest
     * coupon-cancel validator. Mirrors the shape used by ApplyCouponCest so the
     * service receives identical inputs across the apply ↔ cancel round-trip.
     */
    private function lineItem(int $variantId, int $productId, array $overrides = []): array
    {
        $unitPrice = 5000; // cents
        $quantity  = 2;
        return array_merge([
            'id'             => $variantId,
            'order_id'       => 1,
            'post_id'        => $productId,
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
            'rate'           => 0,
            'cart_index'     => 0,
            'other_info'     => [],
        ], $overrides);
    }

    /**
     * Spec: Cases/Functional/coupons.md [46]
     *   "Admin can cancel a previously applied coupon on an order, and the discount is removed from the calculated totals."
     */
    public function adminCanCancelPreviouslyAppliedCouponAndDiscountIsRemoved(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $customer = (new CustomerFactory())->create(['email' => 'cancel-discount@example.test']);
        $coupon = (new CouponFactory())->create([
            'code'      => 'CANCEL-ME',
            'type'      => 'fixed',
            'amount'    => 500,
            'use_count' => 1,
        ]);
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $appliedRow = (new AppliedCouponFactory())->create([
            'order_id'  => (int) $order->id,
            'coupon_id' => (int) $coupon->id,
            'code'      => 'CANCEL-ME',
            'amount'    => 500,
        ]);
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        // Precondition: the applied row exists before cancel; the response must show it gone.
        Assert::assertSame(
            1,
            (int) AppliedCoupon::query()->where('id', (int) $appliedRow->id)->count(),
            'precondition: fct_applied_coupons must hold the to-be-canceled row before dispatch'
        );

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/cancel', [
            'id'              => (int) $appliedRow->id,
            'coupon_code'     => 'CANCEL-ME',
            'order_uuid'      => (string) $order->uuid,
            'applied_coupons' => [(int) $coupon->id],
            'order_items'     => [
                $this->lineItem((int) $variant->id, (int) $product->ID),
            ],
        ]);

        Assert::assertSame(200, $status, 'cancel must return 200. Body: ' . wp_json_encode($body));

        Assert::assertArrayHasKey(
            'applied_coupons',
            (array) $body,
            'response must contain applied_coupons key (proves the cancel handler ran the discount-data branch). Body: ' . wp_json_encode($body)
        );
        $applied = (array) $body['applied_coupons'];
        Assert::assertArrayNotHasKey(
            'CANCEL-ME',
            $applied,
            'response.applied_coupons must NOT contain the canceled coupon code. Body: ' . wp_json_encode($body)
        );
        Assert::assertCount(
            0,
            $applied,
            'response.applied_coupons must be empty after canceling the only applied coupon. Body: ' . wp_json_encode($body)
        );

        $items = (array) ($body['calculated_items'] ?? []);
        Assert::assertNotEmpty($items, 'response.calculated_items must echo back the calculated line items after cancel');
        $lineByVariant = null;
        foreach ($items as $item) {
            if ((int) ($item['id'] ?? 0) === (int) $variant->id) {
                $lineByVariant = $item;
                break;
            }
        }
        Assert::assertNotNull($lineByVariant, 'calculated_items must contain the line keyed by variant id after cancel');
        Assert::assertSame(
            0.0,
            (float) ($lineByVariant['discount_total'] ?? -1),
            'calculated_items[].discount_total must be 0 after the only applied coupon is canceled. Body: ' . wp_json_encode($body)
        );

        Assert::assertSame(
            0,
            (int) AppliedCoupon::query()->where('id', (int) $appliedRow->id)->count(),
            'cancel must delete the fct_applied_coupons row keyed by the supplied id'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [47]
     *   "Canceling a coupon decrements how many times that coupon has been used by one."
     */
    public function cancelingCouponDecrementsUseCountByOne(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $customer = (new CustomerFactory())->create(['email' => 'cancel-decrement@example.test']);
        $coupon = (new CouponFactory())->create([
            'code'      => 'DEC-ME',
            'type'      => 'fixed',
            'amount'    => 500,
            'use_count' => 5,
        ]);
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $appliedRow = (new AppliedCouponFactory())->create([
            'order_id'  => (int) $order->id,
            'coupon_id' => (int) $coupon->id,
            'code'      => 'DEC-ME',
            'amount'    => 500,
        ]);
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        // Precondition: persisted use_count must be 5 before the cancel dispatch — so the
        // post-cancel value is unambiguously a one-step decrement, not a stale read.
        Assert::assertSame(
            5,
            (int) Coupon::query()->where('id', (int) $coupon->id)->value('use_count'),
            'precondition: fct_coupons.use_count must be 5 before dispatch'
        );

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/cancel', [
            'id'              => (int) $appliedRow->id,
            'coupon_code'     => 'DEC-ME',
            'order_uuid'      => (string) $order->uuid,
            'applied_coupons' => [(int) $coupon->id],
            'order_items'     => [
                $this->lineItem((int) $variant->id, (int) $product->ID),
            ],
        ]);

        Assert::assertSame(200, $status, 'cancel must return 200. Body: ' . wp_json_encode($body));

        Assert::assertSame(
            4,
            (int) Coupon::query()->where('id', (int) $coupon->id)->value('use_count'),
            'cancel must decrement fct_coupons.use_count by exactly 1 (5 → 4). Body: ' . wp_json_encode($body)
        );
    }
}

<?php

namespace Tests\Functional\Rest\Coupons;

use FluentCart\App\Models\AppliedCoupon;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\AppliedCouponFactory;
use Tests\Support\Factory\CouponFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/coupons.md → "Re-applying coupons" section, items 48-49.
 *
 * Route: POST /fluent-cart/v2/coupons/re-apply (meta: orders/create OR orders/manage)
 * Controller: CouponsController::reapplyCoupon — uses the framework Request (no validator
 * rules), delegates to CouponResource::reapplyCoupon. The resource pulls the order's
 * fct_applied_coupons rows as previously-applied state, runs CouponServiceAdmin to
 * recompute discounts, and (only when order_items is empty) hard-deletes every
 * fct_applied_coupons row for the order before returning the recomputed shape.
 */
class ReapplyCouponsCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    /**
     * Build a single-item line-items array compatible with the reapply controller —
     * the controller calls $request->getSafe with order_items.* sanitised as text.
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
     * Spec: Cases/Functional/coupons.md [49]
     *   "Re-applying with no items removes any previously saved coupons from the order."
     */
    public function reapplyingWithNoItemsRemovesPreviouslySavedCoupons(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $customer = (new CustomerFactory())->create(['email' => 'reapply-empty@example.test']);
        $couponA = (new CouponFactory())->create([
            'code'   => 'EMPTY-A',
            'type'   => 'fixed',
            'amount' => 500,
        ]);
        $couponB = (new CouponFactory())->create([
            'code'   => 'EMPTY-B',
            'type'   => 'fixed',
            'amount' => 300,
        ]);
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        (new AppliedCouponFactory())->create([
            'order_id'  => (int) $order->id,
            'coupon_id' => (int) $couponA->id,
            'code'      => 'EMPTY-A',
            'amount'    => 500,
        ]);
        (new AppliedCouponFactory())->create([
            'order_id'  => (int) $order->id,
            'coupon_id' => (int) $couponB->id,
            'code'      => 'EMPTY-B',
            'amount'    => 300,
        ]);

        // Precondition: there must be saved rows to remove.
        Assert::assertSame(
            2,
            (int) AppliedCoupon::query()->where('order_id', (int) $order->id)->count(),
            'precondition: order must have 2 fct_applied_coupons rows before re-apply with empty items'
        );

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/re-apply', [
            'order_uuid'  => (string) $order->uuid,
            // order_items deliberately omitted — the resource branch keys off Arr::get(...,'order_items',[])
        ]);

        Assert::assertSame(200, $status, 're-apply with no items must return 200. Body: ' . wp_json_encode($body));

        Assert::assertSame(
            0,
            (int) AppliedCoupon::query()->where('order_id', (int) $order->id)->count(),
            're-apply with empty order_items must hard-delete every fct_applied_coupons row for the order'
        );
    }
}

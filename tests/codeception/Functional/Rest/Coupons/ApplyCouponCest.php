<?php

namespace Tests\Functional\Rest\Coupons;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Coupon;
use FluentCart\App\Models\Customer;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\AppliedCouponFactory;
use Tests\Support\Factory\CouponFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/coupons.md → "Applying a coupon to an admin-created order" + appendix
 * apply-related items, cases 36-45, 100-101, 111-112.
 *
 * Route: POST /fluent-cart/v2/coupons/apply (meta: orders/create OR orders/manage)
 * Controller: CouponsController::applyCoupon — validates via FrontendRequests\CouponRequest,
 * delegates to CouponResource::applyCoupon → CouponServiceAdmin which validates the code
 * (existence, status, dates, use limit, stackability, min purchase, product applicability)
 * and returns calculated discount per code keyed by `applied_coupons`. The admin /apply
 * route is purely calculation — it does NOT persist fct_applied_coupons rows; the order
 * save flow is what persists. So tests assert the JSON response shape, not DB rows.
 */
class ApplyCouponCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    /**
     * Build a single-item line-items array compatible with the FrontendRequest
     * coupon-apply validator: numeric `id`, `post_id`, `variation_id`, `quantity` ≥ 1,
     * plus the cents-denominated `unit_price`, `subtotal`, `discount_total` that the
     * CouponServiceAdmin path actually consumes for total + applicability calculations.
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
     * Spec: Cases/Functional/coupons.md [36]
     *   "Admin can apply a valid active coupon to an order's items and the discount is reflected in the calculated totals."
     */
    public function adminCanApplyValidActiveCouponToOrderItemsAndDiscountIsReflected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $coupon = (new CouponFactory())->create([
            'code'   => 'APPLY-OK',
            'type'   => 'fixed',
            'amount' => 1000, // cents — Helper::toCent(10.00)=1000
        ]);
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/apply', [
            'coupon_code' => 'APPLY-OK',
            'order_items' => [
                $this->lineItem((int) $variant->id, (int) $product->ID),
            ],
        ]);

        Assert::assertSame(200, $status, 'apply with valid active coupon must return 200. Body: ' . wp_json_encode($body));

        $applied = (array) ($body['applied_coupons'] ?? []);
        Assert::assertArrayHasKey('APPLY-OK', $applied, 'response.applied_coupons must include the applied code');
        $entry = (array) $applied['APPLY-OK'];
        Assert::assertSame((int) $coupon->id, (int) ($entry['id'] ?? 0), 'applied_coupons[code].id must reference the applied coupon row');
        Assert::assertSame(1000.0, (float) ($entry['discount'] ?? 0), 'fixed-amount 1000-cent coupon must produce 1000-cent discount across the line items');

        $items = (array) ($body['calculated_items'] ?? []);
        Assert::assertNotEmpty($items, 'response.calculated_items must echo back the calculated line items');
        $lineByVariant = null;
        foreach ($items as $item) {
            if ((int) ($item['id'] ?? 0) === (int) $variant->id) {
                $lineByVariant = $item;
                break;
            }
        }
        Assert::assertNotNull($lineByVariant, 'calculated_items must contain the line keyed by variant id');
        Assert::assertSame(
            1000.0,
            (float) ($lineByVariant['discount_total'] ?? 0),
            'calculated_items[].discount_total must reflect the distributed coupon discount in cents'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [37]
     *   "Admin can apply more than one stackable coupon at a time and each discount is reflected in the calculated totals."
     */
    public function adminCanApplyMultipleStackableCouponsAndEachDiscountIsReflected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $couponA = (new CouponFactory())->create([
            'code'      => 'STACK-A',
            'type'      => 'fixed',
            'amount'    => 500,
            'stackable' => 'yes',
            'priority'  => 1,
        ]);
        $couponB = (new CouponFactory())->create([
            'code'      => 'STACK-B',
            'type'      => 'fixed',
            'amount'    => 300,
            'stackable' => 'yes',
            'priority'  => 2,
        ]);
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/apply', [
            'coupon_code'     => 'STACK-B',
            'applied_coupons' => [(int) $couponA->id],
            'order_items'     => [
                $this->lineItem((int) $variant->id, (int) $product->ID),
            ],
        ]);

        Assert::assertSame(200, $status, 'apply two stackable coupons must return 200. Body: ' . wp_json_encode($body));

        $applied = (array) ($body['applied_coupons'] ?? []);
        Assert::assertArrayHasKey('STACK-A', $applied, 'response.applied_coupons must retain the previously-applied stackable coupon A');
        Assert::assertArrayHasKey('STACK-B', $applied, 'response.applied_coupons must include the newly-applied stackable coupon B');
        Assert::assertSame(
            500.0,
            (float) ($applied['STACK-A']['discount'] ?? 0),
            'coupon A discount must remain 500 cents after stacking with B'
        );
        Assert::assertSame(
            300.0,
            (float) ($applied['STACK-B']['discount'] ?? 0),
            'coupon B discount must be 300 cents after applying on top of A'
        );

        $items = (array) ($body['calculated_items'] ?? []);
        $lineByVariant = null;
        foreach ($items as $item) {
            if ((int) ($item['id'] ?? 0) === (int) $variant->id) {
                $lineByVariant = $item;
                break;
            }
        }
        Assert::assertNotNull($lineByVariant, 'calculated_items must contain the line keyed by variant id');
        Assert::assertSame(
            800.0,
            (float) ($lineByVariant['discount_total'] ?? 0),
            'calculated_items[].discount_total must accumulate both stackable discounts (500 + 300 = 800)'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [38]
     *   "Applying a coupon code that does not exist is rejected with a clear error."
     */
    public function applyingCouponCodeThatDoesNotExistIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/apply', [
            'coupon_code' => 'DOES-NOT-EXIST',
            'order_items' => [
                $this->lineItem((int) $variant->id, (int) $product->ID),
            ],
        ]);

        Assert::assertNotSame(
            200,
            $status,
            'apply with a non-existent coupon code must not return 200. Body: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey('code', (array) $body, 'rejection body must surface a code field. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('message', (array) $body, 'rejection body must surface a message field. Body: ' . wp_json_encode($body));
        Assert::assertSame(
            '400',
            (string) ($body['code'] ?? ''),
            'rejection body.code must be the resource-supplied "400" sentinel produced by CouponResource::makeErrorResponse. Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            'coupon not found',
            strtolower((string) ($body['message'] ?? '')),
            'rejection body.message must surface the coupon-not-found wording (CanValidateCoupon makeError "Coupon Not Found"). Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [39]
     *   "Applying a coupon whose start date has not yet arrived is rejected with a clear error."
     */
    public function applyingCouponWhoseStartDateHasNotYetArrivedIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $future = gmdate('Y-m-d H:i:s', time() + (7 * DAY_IN_SECONDS));
        (new CouponFactory())->create([
            'code'       => 'NOT-STARTED',
            'type'       => 'fixed',
            'amount'     => 500,
            'status'     => 'scheduled',
            'start_date' => $future,
        ]);
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/apply', [
            'coupon_code' => 'NOT-STARTED',
            'order_items' => [
                $this->lineItem((int) $variant->id, (int) $product->ID),
            ],
        ]);

        Assert::assertNotSame(
            200,
            $status,
            'apply with not-yet-started coupon must not return 200. Body: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey('code', (array) $body, 'rejection body must surface a code field. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('message', (array) $body, 'rejection body must surface a message field. Body: ' . wp_json_encode($body));
        Assert::assertSame(
            '400',
            (string) ($body['code'] ?? ''),
            'rejection body.code must be the resource-supplied "400" sentinel produced by CouponResource::makeErrorResponse. Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            'expired or is not valid',
            strtolower((string) ($body['message'] ?? '')),
            'rejection body.message must surface the expired-or-not-valid wording. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [40]
     *   "Applying a coupon that has already expired is rejected with a clear error."
     */
    public function applyingCouponThatHasAlreadyExpiredIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $past = gmdate('Y-m-d H:i:s', time() - (7 * DAY_IN_SECONDS));
        (new CouponFactory())->create([
            'code'     => 'EXPIRED',
            'type'     => 'fixed',
            'amount'   => 500,
            'status'   => 'active',
            'end_date' => $past,
        ]);
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/apply', [
            'coupon_code' => 'EXPIRED',
            'order_items' => [
                $this->lineItem((int) $variant->id, (int) $product->ID),
            ],
        ]);

        Assert::assertNotSame(
            200,
            $status,
            'apply with expired coupon must not return 200. Body: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey('code', (array) $body, 'rejection body must surface a code field. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('message', (array) $body, 'rejection body must surface a message field. Body: ' . wp_json_encode($body));
        Assert::assertSame(
            '400',
            (string) ($body['code'] ?? ''),
            'rejection body.code must be the resource-supplied "400" sentinel produced by CouponResource::makeErrorResponse. Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            'expired or is not valid',
            strtolower((string) ($body['message'] ?? '')),
            'rejection body.message must surface the expired-or-not-valid wording. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [41]
     *   "Applying a coupon that has already reached its maximum total uses is rejected with a clear error."
     */
    public function applyingCouponThatReachedMaxUsesIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        (new CouponFactory())->create([
            'code'       => 'MAXED-OUT',
            'type'       => 'fixed',
            'amount'     => 500,
            'use_count'  => 5,
            'conditions' => [
                'max_uses' => 5,
            ],
        ]);
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/apply', [
            'coupon_code' => 'MAXED-OUT',
            'order_items' => [
                $this->lineItem((int) $variant->id, (int) $product->ID),
            ],
        ]);

        Assert::assertNotSame(
            200,
            $status,
            'apply with maxed-out coupon must not return 200. Body: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey('code', (array) $body, 'rejection body must surface a code field. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('message', (array) $body, 'rejection body must surface a message field. Body: ' . wp_json_encode($body));
        Assert::assertSame(
            '400',
            (string) ($body['code'] ?? ''),
            'rejection body.code must be the resource-supplied "400" sentinel produced by CouponResource::makeErrorResponse. Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            'uses limit exceeded',
            strtolower((string) ($body['message'] ?? '')),
            'rejection body.message must surface the uses-limit-exceeded wording. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [42]
     *   "Applying a coupon to an order whose subtotal is below the coupon's minimum purchase amount is rejected with a clear error."
     */
    public function applyingCouponToOrderBelowMinimumPurchaseAmountIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        (new CouponFactory())->create([
            'code'       => 'MIN-PURCHASE',
            'type'       => 'fixed',
            'amount'     => 500,
            'conditions' => [
                'min_purchase_amount' => 50000, // cents — 500.00
            ],
        ]);
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/apply', [
            'coupon_code' => 'MIN-PURCHASE',
            'order_items' => [
                // line: unit_price 5000 * qty 2 = 10000-cent subtotal — below 50000-cent minimum
                $this->lineItem((int) $variant->id, (int) $product->ID),
            ],
        ]);

        Assert::assertNotSame(
            200,
            $status,
            'apply below min-purchase-amount must not return 200. Body: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey('code', (array) $body, 'rejection body must surface a code field. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('message', (array) $body, 'rejection body must surface a message field. Body: ' . wp_json_encode($body));
        Assert::assertSame(
            '400',
            (string) ($body['code'] ?? ''),
            'rejection body.code must be the resource-supplied "400" sentinel produced by CouponResource::makeErrorResponse. Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            'smaller than required amount',
            strtolower((string) ($body['message'] ?? '')),
            'rejection body.message must surface the smaller-than-required wording. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [43]
     *   "Applying a non-stackable coupon when another coupon is already applied is rejected with a clear error."
     */
    public function applyingNonStackableCouponWhenAnotherIsAppliedIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $couponA = (new CouponFactory())->create([
            'code'      => 'STACK-FIRST',
            'type'      => 'fixed',
            'amount'    => 500,
            'stackable' => 'yes',
        ]);
        (new CouponFactory())->create([
            'code'      => 'NO-STACK',
            'type'      => 'fixed',
            'amount'    => 300,
            'stackable' => 'no',
        ]);
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/apply', [
            'coupon_code'     => 'NO-STACK',
            'applied_coupons' => [(int) $couponA->id],
            'order_items'     => [
                $this->lineItem((int) $variant->id, (int) $product->ID),
            ],
        ]);

        Assert::assertNotSame(
            200,
            $status,
            'apply non-stackable on top of an already-applied coupon must not return 200. Body: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey('code', (array) $body, 'rejection body must surface a code field. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('message', (array) $body, 'rejection body must surface a message field. Body: ' . wp_json_encode($body));
        Assert::assertSame(
            '400',
            (string) ($body['code'] ?? ''),
            'rejection body.code must be the resource-supplied "400" sentinel produced by CouponResource::makeErrorResponse. Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            'can not apply the coupons together',
            strtolower((string) ($body['message'] ?? '')),
            'rejection body.message must surface the cannot-apply-together wording. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [44]
     *   "Applying a coupon without a coupon code is rejected with a clear error."
     */
    public function applyingCouponWithoutCouponCodeIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/apply', [
            'order_items' => [
                $this->lineItem((int) $variant->id, (int) $product->ID),
            ],
        ]);

        Assert::assertNotSame(
            200,
            $status,
            'apply without coupon_code must not return 200. Body: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey(
            'coupon_code',
            (array) $body,
            'validation payload must surface a coupon_code field error. Body: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey(
            'required',
            (array) ($body['coupon_code'] ?? []),
            'validation entry for coupon_code must surface the "required" rule key'
        );
        Assert::assertNotEmpty(
            (string) ($body['coupon_code']['required'] ?? ''),
            'required-rule message for coupon_code must be non-empty'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [45]
     *   "Applying a coupon without any order items is rejected with a clear error."
     */
    public function applyingCouponWithoutOrderItemsIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        (new CouponFactory())->create(['code' => 'NEEDS-ITEMS', 'type' => 'fixed', 'amount' => 500]);

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/apply', [
            'coupon_code' => 'NEEDS-ITEMS',
        ]);

        Assert::assertNotSame(
            200,
            $status,
            'apply without order_items must not return 200. Body: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey(
            'order_items',
            (array) $body,
            'validation payload must surface an order_items field error. Body: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey(
            'required',
            (array) ($body['order_items'] ?? []),
            'validation entry for order_items must surface the "required" rule key'
        );
        Assert::assertNotEmpty(
            (string) ($body['order_items']['required'] ?? ''),
            'required-rule message for order_items must be non-empty'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [100]
     *   "Applying a coupon whose status is disabled is rejected with a clear error."
     */
    public function applyingCouponWhoseStatusIsDisabledIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        (new CouponFactory())->create([
            'code'   => 'DISABLED',
            'type'   => 'fixed',
            'amount' => 500,
            'status' => 'disabled',
        ]);
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/apply', [
            'coupon_code' => 'DISABLED',
            'order_items' => [
                $this->lineItem((int) $variant->id, (int) $product->ID),
            ],
        ]);

        Assert::assertNotSame(
            200,
            $status,
            'apply with disabled coupon must not return 200. Body: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey('code', (array) $body, 'rejection body must surface a code field. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('message', (array) $body, 'rejection body must surface a message field. Body: ' . wp_json_encode($body));
        Assert::assertSame(
            '400',
            (string) ($body['code'] ?? ''),
            'rejection body.code must be the resource-supplied "400" sentinel produced by CouponResource::makeErrorResponse. Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            'expired or is not valid',
            strtolower((string) ($body['message'] ?? '')),
            'rejection body.message must surface the expired-or-not-valid wording for non-active status. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [101]
     *   "Applying a coupon to a customer who has already used it up to its per-customer limit is rejected with a clear error."
     */
    public function applyingCouponToCustomerAtPerCustomerLimitIsRejected(FunctionalTester $I): void
    {
        $userId = $I->loginAsSuperAdmin();

        $coupon = (new CouponFactory())->create([
            'code'       => 'PER-CUSTOMER',
            'type'       => 'fixed',
            'amount'     => 500,
            'conditions' => [
                'max_per_customer' => 1,
            ],
        ]);

        $customer = (new CustomerFactory())->create(['user_id' => $userId, 'email' => 'percust@example.test']);
        $priorOrder = (new OrderFactory())->create(['customer_id' => $customer->id]);
        (new AppliedCouponFactory())->create([
            'order_id'  => (int) $priorOrder->id,
            'coupon_id' => (int) $coupon->id,
            'code'      => 'PER-CUSTOMER',
        ]);

        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        // Sanity precondition: customer is associated with the logged-in user, and exactly
        // one prior AppliedCoupon row exists — so the next apply hits the per-customer cap.
        $loadedCustomer = Customer::query()->where('user_id', $userId)->first();
        Assert::assertNotNull($loadedCustomer, 'precondition: customer must be linked to the logged-in admin user_id');
        Assert::assertSame((int) $customer->id, (int) $loadedCustomer->id, 'precondition: linked customer id must match the seeded customer');

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/apply', [
            'coupon_code' => 'PER-CUSTOMER',
            'order_items' => [
                $this->lineItem((int) $variant->id, (int) $product->ID),
            ],
        ]);

        Assert::assertNotSame(
            200,
            $status,
            'apply by a customer at the per-customer limit must not return 200. Body: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey('code', (array) $body, 'rejection body must surface a code field. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('message', (array) $body, 'rejection body must surface a message field. Body: ' . wp_json_encode($body));
        Assert::assertSame(
            '400',
            (string) ($body['code'] ?? ''),
            'rejection body.code must be the resource-supplied "400" sentinel produced by CouponResource::makeErrorResponse. Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            'uses limit exceeded',
            strtolower((string) ($body['message'] ?? '')),
            'rejection body.message must surface the uses-limit-exceeded wording for the per-customer cap. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [126]
     *   "A legacy applied-coupon row recorded without a coupon id still counts toward the per-customer limit."
     */
    public function applyingCouponAtPerCustomerLimitCountsLegacyRowsWithoutCouponId(FunctionalTester $I): void
    {
        $userId = $I->loginAsSuperAdmin();

        (new CouponFactory())->create([
            'code'       => 'PER-CUST-LEGACY',
            'type'       => 'fixed',
            'amount'     => 500,
            'conditions' => [
                'max_per_customer' => 1,
            ],
        ]);

        $customer = (new CustomerFactory())->create(['user_id' => $userId, 'email' => 'percust-legacy@example.test']);
        $priorOrder = (new OrderFactory())->create(['customer_id' => $customer->id]);
        // Legacy usage row: the nullable coupon_id was never backfilled — only the code links it.
        (new AppliedCouponFactory())->create([
            'order_id'  => (int) $priorOrder->id,
            'coupon_id' => null,
            'code'      => 'PER-CUST-LEGACY',
        ]);

        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/apply', [
            'coupon_code' => 'PER-CUST-LEGACY',
            'order_items' => [
                $this->lineItem((int) $variant->id, (int) $product->ID),
            ],
        ]);

        Assert::assertNotSame(
            200,
            $status,
            'a legacy NULL-coupon_id usage row must still count toward the per-customer cap. Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            'uses limit exceeded',
            strtolower((string) ($body['message'] ?? '')),
            'rejection body.message must surface the uses-limit-exceeded wording for the legacy-row cap. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [111]
     *   "Applying a coupon to an order whose subtotal exceeds the coupon's maximum purchase amount is rejected with a clear error."
     */
    public function applyingCouponWhenSubtotalExceedsMaxPurchaseAmountIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        (new CouponFactory())->create([
            'code'       => 'MAX-PURCHASE',
            'type'       => 'fixed',
            'amount'     => 500,
            'conditions' => [
                // stored as the decimal amount the admin typed (CouponRequest::sanitize
                // floatval — no cent conversion, unlike min_purchase_amount) — 50.00
                'max_purchase_amount' => 50,
            ],
        ]);
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/apply', [
            'coupon_code' => 'MAX-PURCHASE',
            'order_items' => [
                // unit_price 5000 * qty 2 = 10000-cent (100.00) subtotal — exceeds the 50.00 cap
                $this->lineItem((int) $variant->id, (int) $product->ID),
            ],
        ]);

        Assert::assertNotSame(
            200,
            $status,
            'apply on a subtotal above max-purchase-amount must not return 200. Body: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey('code', (array) $body, 'rejection body must surface a code field. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('message', (array) $body, 'rejection body must surface a message field. Body: ' . wp_json_encode($body));
        Assert::assertSame(
            '400',
            (string) ($body['code'] ?? ''),
            'rejection body.code must be the resource-supplied "400" sentinel produced by CouponResource::makeErrorResponse. Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            'exceeds the maximum amount',
            strtolower((string) ($body['message'] ?? '')),
            'rejection body.message must surface the exceeds-maximum-amount wording shared with DiscountService::isCouponValid. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [112]
     *   "Applying a coupon to a customer whose email is not on the coupon's email allowlist is rejected with a clear error."
     */
    public function applyingCouponWhenCustomerEmailNotOnAllowlistIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        (new CouponFactory())->create([
            'code'       => 'EMAIL-ALLOW',
            'type'       => 'fixed',
            'amount'     => 500,
            'conditions' => [
                // the real condition key — a comma-separated string sanitized by
                // CouponRequest::sanitize, matched with * wildcards (DiscountService parity)
                'email_restrictions' => 'allowed@example.test',
            ],
        ]);
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/apply', [
            'coupon_code'    => 'EMAIL-ALLOW',
            'customer_email' => 'not-allowed@example.test',
            'order_items'    => [
                $this->lineItem((int) $variant->id, (int) $product->ID),
            ],
        ]);

        Assert::assertNotSame(
            200,
            $status,
            'apply with disallowed customer email must not return 200. Body: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey('code', (array) $body, 'rejection body must surface a code field. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('message', (array) $body, 'rejection body must surface a message field. Body: ' . wp_json_encode($body));
        Assert::assertSame(
            '400',
            (string) ($body['code'] ?? ''),
            'rejection body.code must be the resource-supplied "400" sentinel produced by CouponResource::makeErrorResponse. Body: ' . wp_json_encode($body)
        );
        Assert::assertMatchesRegularExpression(
            '/email|allowed|allowlist/i',
            (string) ($body['message'] ?? ''),
            'rejection body.message must surface email/allowlist wording so the rejection is distinguishable from generic-validation failures. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [125]
     *   "Applying an email-restricted coupon with a customer email on the allowlist succeeds."
     */
    public function applyingCouponWhenCustomerEmailOnAllowlistSucceeds(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        (new CouponFactory())->create([
            'code'       => 'EMAIL-ALLOW-OK',
            'type'       => 'fixed',
            'amount'     => 500,
            'conditions' => [
                'email_restrictions' => 'allowed@example.test',
            ],
        ]);
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/apply', [
            'coupon_code'    => 'EMAIL-ALLOW-OK',
            'customer_email' => 'allowed@example.test',
            'order_items'    => [
                $this->lineItem((int) $variant->id, (int) $product->ID),
            ],
        ]);

        Assert::assertSame(200, $status, 'apply with an allowlisted customer email must return 200. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey(
            'EMAIL-ALLOW-OK',
            (array) ($body['applied_coupons'] ?? []),
            'response.applied_coupons must include the email-restricted coupon when the email matches the allowlist'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [132]
     *   "Admin can apply a coupon to a line item that submits a null subtotal (variation-shaped payload) and the discount is calculated from the item's real unit price and quantity."
     *
     * The admin UI computes per-item subtotal as `unit_price * quantity - item.cost`
     * (cartService.js), but productService.js never sets `cost` on variation rows, so
     * `number - undefined = NaN` serializes as JSON null for every variation line item.
     */
    public function adminCanApplyCouponWhenVariationItemSendsNullSubtotal(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $coupon = (new CouponFactory())->create([
            'code'   => 'NULL-SUBTOTAL-OK',
            'type'   => 'fixed',
            'amount' => 1000, // cents
        ]);
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/apply', [
            'coupon_code' => 'NULL-SUBTOTAL-OK',
            'order_items' => [
                $this->lineItem((int) $variant->id, (int) $product->ID, ['subtotal' => null]),
            ],
        ]);

        Assert::assertSame(
            200,
            $status,
            'apply must tolerate the null subtotal the admin UI sends for variation items. Body: ' . wp_json_encode($body)
        );
        $applied = (array) ($body['applied_coupons'] ?? []);
        Assert::assertArrayHasKey('NULL-SUBTOTAL-OK', $applied, 'response.applied_coupons must include the applied code');
        Assert::assertSame(
            1000.0,
            (float) (((array) $applied['NULL-SUBTOTAL-OK'])['discount'] ?? 0),
            'fixed 1000-cent coupon must still produce its full discount on a null-subtotal line'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [133]
     *   "A coupon's maximum purchase amount is still enforced against the real line totals when the line items submit null subtotals."
     *
     * Guards the enforcement PR #2492 added: with a bare `nullable` rule fix the null
     * subtotal would sanitize to 0, the items total would compute 0, and the 50.00 cap
     * would wrongly admit this 100.00 order.
     */
    public function maxPurchaseAmountStillEnforcedWhenVariationItemSendsNullSubtotal(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        (new CouponFactory())->create([
            'code'       => 'NULL-SUB-MAX',
            'type'       => 'fixed',
            'amount'     => 500,
            'conditions' => [
                // decimal amount, no cent conversion (CouponRequest::sanitize floatval) — 50.00
                'max_purchase_amount' => 50,
            ],
        ]);
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/apply', [
            'coupon_code' => 'NULL-SUB-MAX',
            'order_items' => [
                // real total: unit_price 5000 * qty 2 = 10000 cents (100.00) — over the 50.00 cap
                $this->lineItem((int) $variant->id, (int) $product->ID, ['subtotal' => null]),
            ],
        ]);

        Assert::assertNotSame(
            200,
            $status,
            'a null subtotal must not defeat the max-purchase-amount cap — the real 100.00 total exceeds the 50.00 cap. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            '400',
            (string) ($body['code'] ?? ''),
            'rejection body.code must be the resource-supplied "400" sentinel, not a validation 422. Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            'exceeds the maximum amount',
            strtolower((string) ($body['message'] ?? '')),
            'rejection body.message must surface the exceeds-maximum-amount wording. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [134]
     *   "A coupon's minimum purchase amount is satisfied by the real line totals when the line items submit null subtotals."
     *
     * The inverse direction of case 133: with null sanitized to 0 the items total would
     * compute 0 and this qualifying 100.00 order would be wrongly rejected as below minimum.
     */
    public function minPurchaseAmountSatisfiedWhenVariationItemSendsNullSubtotal(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        (new CouponFactory())->create([
            'code'       => 'NULL-SUB-MIN',
            'type'       => 'fixed',
            'amount'     => 500,
            'conditions' => [
                'min_purchase_amount' => 6000, // cents — 60.00, below the real 100.00 total
            ],
        ]);
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/apply', [
            'coupon_code' => 'NULL-SUB-MIN',
            'order_items' => [
                $this->lineItem((int) $variant->id, (int) $product->ID, ['subtotal' => null]),
            ],
        ]);

        Assert::assertSame(
            200,
            $status,
            'a qualifying order must not be rejected as below-minimum just because the subtotal key is null. Body: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey(
            'NULL-SUB-MIN',
            (array) ($body['applied_coupons'] ?? []),
            'response.applied_coupons must include the coupon once the real total satisfies the minimum'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [141]
     *   "Admin coupon-apply rejections carry a 4xx validation status, not a 500 server error."
     *
     * CouponResource::applyCoupon built its rejection envelope via
     * makeErrorResponse WITHOUT the status argument, so the WP_Error carried no
     * data.status and WP_REST_Server defaulted every coupon rejection on this
     * route — stacking, expiry, limits, spend gates — to HTTP 500. The sibling
     * update/delete paths already pass the status (the #2471/#2472 family fix);
     * this route was the straggler. The stacking case is the audit's measured
     * reproduction (finding C9).
     */
    public function applyingNonStackableCouponRejectionCarriesValidationStatusNotServerError(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $applied = (new CouponFactory())->create([
            'code'      => 'STATUS-FIRST',
            'type'      => 'fixed',
            'amount'    => 500,
            'stackable' => 'yes',
        ]);
        (new CouponFactory())->create([
            'code'      => 'STATUS-NOSTACK',
            'type'      => 'fixed',
            'amount'    => 300,
            'stackable' => 'no',
        ]);
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/apply', [
            'coupon_code'     => 'STATUS-NOSTACK',
            'applied_coupons' => [(int) $applied->id],
            'order_items'     => [
                $this->lineItem((int) $variant->id, (int) $product->ID),
            ],
        ]);

        Assert::assertSame(
            422,
            $status,
            'a stacking rejection is a validation outcome and must answer 422 — not a 500 server error. Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            'can not apply the coupons together',
            strtolower((string) ($body['message'] ?? '')),
            'the 422 must still carry the stacking rejection message. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [148]
     *   "Admin can apply a second stackable coupon to a saved order while the first coupon is applied
     *    but not yet saved, and both discounts are reflected."
     */
    public function adminCanApplySecondCouponToSavedOrderWhileFirstIsUnsaved(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $customer = (new CustomerFactory())->create(['email' => 'unsaved-coupon@example.test']);
        $first    = (new CouponFactory())->create(['code' => 'UNSAVED-FIRST', 'type' => 'fixed', 'amount' => 500]);
        (new CouponFactory())->create(['code' => 'SECOND', 'type' => 'fixed', 'amount' => 300]);
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();
        $order   = (new OrderFactory())->create(['customer_id' => $customer->id]);
        Assert::assertNotEmpty($order->uuid, 'the saved order must carry a uuid for the apply request');

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/apply', [
            'coupon_code'     => 'SECOND',
            'order_uuid'      => $order->uuid,
            'applied_coupons' => [(int) $first->id],
            'order_items'     => [$this->lineItem((int) $variant->id, (int) $product->ID)],
        ]);

        Assert::assertSame(200, $status, 'applying a second coupon to a saved order must not error. Body: ' . wp_json_encode($body));
        $applied = (array) ($body['applied_coupons'] ?? []);
        Assert::assertSame(500, (int) ($applied['UNSAVED-FIRST']['discount'] ?? 0), 'the unsaved first coupon must keep its $5.00 discount');
        Assert::assertSame(300, (int) ($applied['SECOND']['discount'] ?? 0), 'the second coupon must add its $3.00 discount');
    }

    /**
     * Spec: Cases/Functional/coupons.md [149]
     *   "Applying a non-stackable coupon to a saved order while another coupon is applied but not yet
     *    saved is rejected with the stacking error, not a server error."
     */
    public function applyingNonStackableCouponToSavedOrderWhileAnotherIsUnsavedIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $customer = (new CustomerFactory())->create(['email' => 'unsaved-stacking@example.test']);
        $first    = (new CouponFactory())->create(['code' => 'UNSAVED-FIRST', 'type' => 'fixed', 'amount' => 500]);
        (new CouponFactory())->createNonStackable(['code' => 'NO-STACK', 'type' => 'fixed', 'amount' => 300]);
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();
        $order   = (new OrderFactory())->create(['customer_id' => $customer->id]);

        [$status, $body] = $I->post('/fluent-cart/v2/coupons/apply', [
            'coupon_code'     => 'NO-STACK',
            'order_uuid'      => $order->uuid,
            'applied_coupons' => [(int) $first->id],
            'order_items'     => [$this->lineItem((int) $variant->id, (int) $product->ID)],
        ]);

        Assert::assertSame(422, $status, 'stacking a non-stackable coupon must be a validation error, not a crash. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString(
            'can not apply the coupons together',
            strtolower((string) ($body['message'] ?? '')),
            'the 422 must carry the stacking rejection message. Body: ' . wp_json_encode($body)
        );
    }
}

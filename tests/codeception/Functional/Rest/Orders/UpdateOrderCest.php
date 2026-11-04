<?php

namespace Tests\Functional\Rest\Orders;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CouponFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/orders.md → "Updating an order" section, items 27-31c.
 */
class UpdateOrderCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/orders.md [27]
     *   "Admin can change non-status fields of an existing order, such as the customer note or shipping total."
     */
    public function adminCanChangeNonStatusFieldsLikeNoteOrShippingTotal(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'update-note-and-shipping@example.test']);
        $product  = (new ProductFactory())->withVariantAttributes(['item_price' => Helper::toCent(50)])->create();
        $variant  = $product->variants->first();

        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'subtotal'       => Helper::toCent(50),
            'total_amount'   => Helper::toCent(50),
            'shipping_total' => 0,
            'note'           => '',
            'invoice_no'     => 'INV-UPDATE-NOTE',
        ]);
        (new OrderItemFactory())->create((int) $order->id, [
            'post_id'    => (int) $product->ID,
            'object_id'  => (int) $variant->id,
            'unit_price' => Helper::toCent(50),
            'subtotal'   => Helper::toCent(50),
            'line_total' => Helper::toCent(50),
        ]);

        [, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id, [
            'customer_id'    => (int) $customer->id,
            'note'           => 'Customer wants gift wrapping.',
            'shipping_total' => Helper::toCent(10),
            'subtotal'       => Helper::toCent(50),
            'total_amount'   => Helper::toCent(50),
            'order_items'    => [
                [
                    'post_id'    => (int) $product->ID,
                    'object_id'  => (int) $variant->id,
                    'quantity'   => 1,
                    'unit_price' => Helper::toCent(50),
                    'subtotal'   => Helper::toCent(50),
                    'line_total' => Helper::toCent(50),
                ],
            ],
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT note, shipping_total FROM {$wpdb->prefix}fct_orders WHERE id = %d", $order->id),
            ARRAY_A
        );
        Assert::assertNotEmpty($row, 'order row must still exist after update');
        Assert::assertSame('Customer wants gift wrapping.', (string) $row['note'], 'note must persist the new value supplied in the update payload');
        Assert::assertSame(Helper::toCent(10), (int) $row['shipping_total'], 'shipping_total must persist the new cents value supplied in the update payload');
    }

    /**
     * Spec: Cases/Functional/orders.md [28]
     *   "Admin is rejected when trying to update a subscription order through the standard order update flow."
     */
    public function adminIsRejectedWhenUpdatingSubscriptionOrderThroughStandardFlow(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'subscription-edit-rejected@example.test']);
        $product  = (new ProductFactory())->create();
        $variant  = $product->variants->first();

        $order = (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'type'        => Status::ORDER_TYPE_SUBSCRIPTION,
            'invoice_no'  => 'INV-SUB-EDIT',
        ]);
        // Order::isSubscription() reads from order_items.payment_type, not order.type,
        // so the seeded line item must carry payment_type='subscription' too.
        $existingItem = (new OrderItemFactory())->create((int) $order->id, [
            'post_id'      => (int) $product->ID,
            'object_id'    => (int) $variant->id,
            'payment_type' => 'subscription',
            'other_info'   => ['payment_type' => 'subscription'],
            'quantity'     => 1,
            'unit_price'   => Helper::toCent(99),
            'subtotal'     => Helper::toCent(99),
            'line_total'   => Helper::toCent(99),
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id, [
            'customer_id' => (int) $customer->id,
            'note'        => 'should not save',
            'order_items' => [
                [
                    'post_id'    => (int) $product->ID,
                    'object_id'  => (int) $variant->id,
                    'quantity'   => 7,
                    'unit_price' => Helper::toCent(10),
                ],
            ],
        ]);

        Assert::assertSame(400, $status, 'subscription order update through the standard endpoint must return 400. Body: ' . wp_json_encode($body));
        $message = (string) ($body['message'] ?? '');
        Assert::assertStringContainsString(
            'Subscription Order',
            $message,
            'rejection message must explain that subscription orders cannot be edited via this endpoint. Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        $persistedNote = (string) $wpdb->get_var(
            $wpdb->prepare("SELECT note FROM {$wpdb->prefix}fct_orders WHERE id = %d", (int) $order->id)
        );
        Assert::assertSame('', $persistedNote, 'subscription order note must remain unchanged when the update was rejected');

        $itemRow = $wpdb->get_row(
            $wpdb->prepare("SELECT quantity, unit_price, line_total FROM {$wpdb->prefix}fct_order_items WHERE id = %d", (int) $existingItem->id),
            ARRAY_A
        );
        Assert::assertNotEmpty($itemRow, 'existing order item must still exist after a rejected subscription update');
        Assert::assertSame(1, (int) $itemRow['quantity'], 'order item quantity must NOT be mutated when the subscription update is rejected');
        Assert::assertSame(Helper::toCent(99), (int) $itemRow['unit_price'], 'order item unit_price must NOT be mutated when the subscription update is rejected');
        Assert::assertSame(Helper::toCent(99), (int) $itemRow['line_total'], 'order item line_total must NOT be mutated when the subscription update is rejected');

        $itemCount = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d", (int) $order->id)
        );
        Assert::assertSame(
            1,
            $itemCount,
            'rejected subscription update must NOT create an additional order_items row from the payload — exactly one row should remain for this order'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [29]
     *   "Admin is rejected when trying to set an order's status to \"completed\" through the standard order update flow."
     */
    public function adminIsRejectedWhenSettingStatusToCompletedThroughStandardFlow(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'forbid-completed-via-update@example.test']);
        $product  = (new ProductFactory())->create();
        $variant  = $product->variants->first();

        $order = (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'status'      => Status::ORDER_ON_HOLD,
            'invoice_no'  => 'INV-FORBID-COMPLETED',
        ]);
        $existingItem = (new OrderItemFactory())->create((int) $order->id, [
            'post_id'    => (int) $product->ID,
            'object_id'  => (int) $variant->id,
            'quantity'   => 1,
            'unit_price' => Helper::toCent(77),
            'subtotal'   => Helper::toCent(77),
            'line_total' => Helper::toCent(77),
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id, [
            'customer_id' => (int) $customer->id,
            'status'      => Status::ORDER_COMPLETED,
            'order_items' => [
                [
                    'post_id'    => (int) $product->ID,
                    'object_id'  => (int) $variant->id,
                    'quantity'   => 5,
                    'unit_price' => Helper::toCent(10),
                ],
            ],
        ]);

        Assert::assertSame(400, $status, 'standard update flow must reject status=completed with 400. Body: ' . wp_json_encode($body));
        $message = (string) ($body['message'] ?? '');
        Assert::assertStringContainsString(
            'Completed',
            $message,
            'rejection message must explain that completed status cannot be set through this endpoint. Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        $persistedStatus = (string) $wpdb->get_var(
            $wpdb->prepare("SELECT status FROM {$wpdb->prefix}fct_orders WHERE id = %d", (int) $order->id)
        );
        Assert::assertSame(
            Status::ORDER_ON_HOLD,
            $persistedStatus,
            'order status must remain on-hold when the request to mark completed is rejected'
        );

        $itemRow = $wpdb->get_row(
            $wpdb->prepare("SELECT quantity, unit_price, line_total FROM {$wpdb->prefix}fct_order_items WHERE id = %d", (int) $existingItem->id),
            ARRAY_A
        );
        Assert::assertNotEmpty($itemRow, 'existing order item must still exist after a rejected completed-status update');
        Assert::assertSame(1, (int) $itemRow['quantity'], 'order item quantity must NOT be mutated when the completed-status update is rejected');
        Assert::assertSame(Helper::toCent(77), (int) $itemRow['unit_price'], 'order item unit_price must NOT be mutated when the completed-status update is rejected');
        Assert::assertSame(Helper::toCent(77), (int) $itemRow['line_total'], 'order item line_total must NOT be mutated when the completed-status update is rejected');

        $itemCount = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d", (int) $order->id)
        );
        Assert::assertSame(
            1,
            $itemCount,
            'rejected completed-status update must NOT create an additional order_items row from the payload — exactly one row should remain for this order'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [30]
     *   "Admin sees an order's payment status automatically marked partially paid when the new total exceeds the amount already paid."
     */
    public function adminSeesPaymentStatusAutoMarkedPartiallyPaidWhenNewTotalExceedsPaid(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'auto-partially-paid@example.test']);
        $product  = (new ProductFactory())->withVariantAttributes(['item_price' => Helper::toCent(50)])->create();
        $variant  = $product->variants->first();

        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'subtotal'       => Helper::toCent(50),
            'total_amount'   => Helper::toCent(50),
            'total_paid'     => Helper::toCent(50),
            'payment_status' => Status::PAYMENT_PAID,
            'invoice_no'     => 'INV-AUTO-PARTIAL',
        ]);
        (new OrderItemFactory())->create((int) $order->id, [
            'post_id'    => (int) $product->ID,
            'object_id'  => (int) $variant->id,
            'unit_price' => Helper::toCent(50),
            'subtotal'   => Helper::toCent(50),
            'line_total' => Helper::toCent(50),
        ]);

        // The new total exceeds total_paid (100 > 50). Pass payment_status=paid in the
        // payload so the auto-adjust branch in OrderController::updateOrder fires; the
        // controller then must override it with partially_paid.
        [, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id, [
            'customer_id'    => (int) $customer->id,
            'subtotal'       => Helper::toCent(100),
            'total_amount'   => Helper::toCent(100),
            'total_paid'     => Helper::toCent(50),
            'payment_status' => Status::PAYMENT_PAID,
            'order_items'    => [
                [
                    'post_id'    => (int) $product->ID,
                    'object_id'  => (int) $variant->id,
                    'quantity'   => 2,
                    'unit_price' => Helper::toCent(50),
                    'subtotal'   => Helper::toCent(100),
                    'line_total' => Helper::toCent(100),
                ],
            ],
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT payment_status, total_amount, total_paid FROM {$wpdb->prefix}fct_orders WHERE id = %d", (int) $order->id),
            ARRAY_A
        );
        Assert::assertNotEmpty($row, 'order row must still exist after update');
        Assert::assertSame(
            Helper::toCent(100),
            (int) $row['total_amount'],
            'precondition: persisted total_amount must reflect the new 10000 cents so the auto-adjust branch saw new total > total_paid'
        );
        Assert::assertSame(
            Helper::toCent(50),
            (int) $row['total_paid'],
            'precondition: persisted total_paid must remain at 5000 cents so the diff is real'
        );
        Assert::assertSame(
            Status::PAYMENT_PARTIALLY_PAID,
            (string) $row['payment_status'],
            'payment_status must auto-flip to partially_paid when the new total exceeds total_paid; payload sent paid'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [31]
     *   "Admin sees the order's overall total automatically adjusted when the shipping total changes during an update."
     */
    public function adminSeesOverallTotalAutoAdjustedWhenShippingTotalChanges(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'auto-total-on-shipping-change@example.test']);
        $product  = (new ProductFactory())->withVariantAttributes(['item_price' => Helper::toCent(40)])->create();
        $variant  = $product->variants->first();

        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'subtotal'       => Helper::toCent(40),
            'total_amount'   => Helper::toCent(40),
            'shipping_total' => 0,
            'invoice_no'     => 'INV-AUTO-SHIP-TOTAL',
        ]);
        (new OrderItemFactory())->create((int) $order->id, [
            'post_id'    => (int) $product->ID,
            'object_id'  => (int) $variant->id,
            'unit_price' => Helper::toCent(40),
            'subtotal'   => Helper::toCent(40),
            'line_total' => Helper::toCent(40),
        ]);

        // Send payload total_amount=4000 (matches old) but shipping_total=1500 (was 0).
        // OrderController::updateOrder must add the shipping diff (1500) to total_amount.
        [, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id, [
            'customer_id'    => (int) $customer->id,
            'subtotal'       => Helper::toCent(40),
            'total_amount'   => Helper::toCent(40),
            'shipping_total' => Helper::toCent(15),
            'order_items'    => [
                [
                    'post_id'    => (int) $product->ID,
                    'object_id'  => (int) $variant->id,
                    'quantity'   => 1,
                    'unit_price' => Helper::toCent(40),
                    'subtotal'   => Helper::toCent(40),
                    'line_total' => Helper::toCent(40),
                ],
            ],
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT total_amount, shipping_total FROM {$wpdb->prefix}fct_orders WHERE id = %d", $order->id),
            ARRAY_A
        );
        Assert::assertNotEmpty($row, 'order row must still exist after update');
        Assert::assertSame(
            Helper::toCent(15),
            (int) $row['shipping_total'],
            'shipping_total must persist the new value (1500 cents)'
        );
        Assert::assertSame(
            Helper::toCent(55),
            (int) $row['total_amount'],
            'total_amount must auto-adjust by the shipping_total diff: old total 4000 + diff 1500 = 5500 cents'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [31a]
     *   "Admin is rejected when updating an order to combine a non-stackable coupon with another
     *    coupon, and no coupon is attached to the order."
     */
    public function adminIsRejectedWhenUpdatingAnOrderToStackANonStackableCoupon(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'update-stacked-coupons@example.test']);
        $solo     = (new CouponFactory())->createNonStackable(['code' => 'SOLO', 'type' => 'fixed', 'amount' => 1000]);
        $extra    = (new CouponFactory())->create(['code' => 'EXTRA', 'type' => 'fixed', 'amount' => 500]);
        $product  = (new ProductFactory())->withVariantAttributes(['item_price' => Helper::toCent(50)])->create();
        $variant  = $product->variants->first();

        $order = (new OrderFactory())->create([
            'customer_id'  => $customer->id,
            'subtotal'     => Helper::toCent(50),
            'total_amount' => Helper::toCent(50),
            'invoice_no'   => 'INV-UPDATE-STACKED',
        ]);
        $item = (new OrderItemFactory())->create((int) $order->id, [
            'post_id'    => (int) $product->ID,
            'object_id'  => (int) $variant->id,
            'unit_price' => Helper::toCent(50),
            'subtotal'   => Helper::toCent(50),
            'line_total' => Helper::toCent(50),
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id, [
            'customer_id'    => (int) $customer->id,
            'subtotal'       => Helper::toCent(50),
            'total_amount'   => Helper::toCent(35),
            'order_items'    => [
                [
                    'id'             => (int) $item->id,
                    'post_id'        => (int) $product->ID,
                    'object_id'      => (int) $variant->id,
                    'quantity'       => 1,
                    'unit_price'     => Helper::toCent(50),
                    'subtotal'       => Helper::toCent(50),
                    'discount_total' => 1500,
                    'line_total'     => Helper::toCent(35),
                ],
            ],
            'applied_coupon' => [
                'SOLO'  => ['id' => (int) $solo->id, 'discount' => 1000, 'type' => 'fixed'],
                'EXTRA' => ['id' => (int) $extra->id, 'discount' => 500, 'type' => 'fixed'],
            ],
        ]);

        Assert::assertSame(
            422,
            $status,
            'an update stacking a non-stackable coupon must be rejected. Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        $appliedCount = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}fct_applied_coupons WHERE order_id = %d", $order->id)
        );
        Assert::assertSame(0, $appliedCount, 'a rejected coupon combination must not attach coupons to the order');

        $useCounts = $wpdb->get_col("SELECT use_count FROM {$wpdb->prefix}fct_coupons ORDER BY id");
        Assert::assertSame(['0', '0'], $useCounts, 'a rejected coupon combination must not increment either coupon use count');

        $total = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT total_amount FROM {$wpdb->prefix}fct_orders WHERE id = %d", $order->id)
        );
        Assert::assertSame(Helper::toCent(50), $total, 'a rejected update must leave the order total unchanged');
    }

    /**
     * Spec: Cases/Functional/orders.md [31b]
     *   "Admin is rejected when updating an order whose submitted coupon discount differs from the
     *    discount the store calculates, and the order is left unchanged."
     */
    public function adminIsRejectedWhenUpdatedCouponDiscountDiffersFromTheCalculatedOne(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'update-mismatched-discount@example.test']);
        $coupon   = (new CouponFactory())->create(['code' => 'TENOFF', 'type' => 'fixed', 'amount' => 1000]);
        $product  = (new ProductFactory())->withVariantAttributes(['item_price' => Helper::toCent(50)])->create();
        $variant  = $product->variants->first();

        $order = (new OrderFactory())->create([
            'customer_id'  => $customer->id,
            'subtotal'     => Helper::toCent(50),
            'total_amount' => Helper::toCent(50),
            'invoice_no'   => 'INV-UPDATE-MISMATCH',
        ]);
        $item = (new OrderItemFactory())->create((int) $order->id, [
            'post_id'    => (int) $product->ID,
            'object_id'  => (int) $variant->id,
            'unit_price' => Helper::toCent(50),
            'subtotal'   => Helper::toCent(50),
            'line_total' => Helper::toCent(50),
        ]);

        // The coupon row would say $10 while the item and total claim $49 off.
        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id, [
            'customer_id'    => (int) $customer->id,
            'subtotal'       => Helper::toCent(50),
            'total_amount'   => Helper::toCent(1),
            'order_items'    => [
                [
                    'id'             => (int) $item->id,
                    'post_id'        => (int) $product->ID,
                    'object_id'      => (int) $variant->id,
                    'quantity'       => 1,
                    'unit_price'     => Helper::toCent(50),
                    'subtotal'       => Helper::toCent(50),
                    'discount_total' => Helper::toCent(49),
                    'line_total'     => Helper::toCent(1),
                ],
            ],
            'applied_coupon' => [
                'TENOFF' => ['id' => (int) $coupon->id, 'discount' => Helper::toCent(10), 'type' => 'fixed'],
            ],
        ]);

        Assert::assertSame(
            422,
            $status,
            'an update whose item discount disagrees with the calculated coupon discount must be rejected. Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        $appliedCount = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}fct_applied_coupons WHERE order_id = %d", $order->id)
        );
        Assert::assertSame(0, $appliedCount, 'a rejected update must not attach the coupon');

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT total_amount FROM {$wpdb->prefix}fct_orders WHERE id = %d", $order->id),
            ARRAY_A
        );
        Assert::assertSame(Helper::toCent(50), (int) $row['total_amount'], 'a rejected update must leave the order total unchanged');

        $itemDiscount = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT discount_total FROM {$wpdb->prefix}fct_order_items WHERE id = %d", $item->id)
        );
        Assert::assertSame(0, $itemDiscount, 'a rejected update must leave the item discount unchanged');
    }

    /**
     * Spec: Cases/Functional/orders.md [31c]
     *   "Admin can update an order with a coupon using the discounts the apply-coupon endpoint returned,
     *    and the coupon is attached with that discount."
     */
    public function adminCanUpdateOrderWithTheDiscountsTheApplyEndpointReturned(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'update-applied-coupon@example.test']);
        $coupon   = (new CouponFactory())->create(['code' => 'TENOFF', 'type' => 'fixed', 'amount' => 1000]);
        $product  = (new ProductFactory())->withVariantAttributes(['item_price' => Helper::toCent(50)])->create();
        $variant  = $product->variants->first();

        $order = (new OrderFactory())->create([
            'customer_id'  => $customer->id,
            'subtotal'     => Helper::toCent(50),
            'total_amount' => Helper::toCent(50),
            'invoice_no'   => 'INV-UPDATE-APPLIED',
        ]);
        $item = (new OrderItemFactory())->create((int) $order->id, [
            'post_id'    => (int) $product->ID,
            'object_id'  => (int) $variant->id,
            'unit_price' => Helper::toCent(50),
            'subtotal'   => Helper::toCent(50),
            'line_total' => Helper::toCent(50),
        ]);

        $orderItem = [
            'id'             => (int) $item->id,
            'post_id'        => (int) $product->ID,
            'object_id'      => (int) $variant->id,
            'quantity'       => 1,
            'unit_price'     => Helper::toCent(50),
            'subtotal'       => Helper::toCent(50),
            'discount_total' => 0,
            'line_total'     => Helper::toCent(50),
        ];

        // Step 1: what the edit screen does when Apply is clicked.
        [$applyStatus, $applyBody] = $I->post('/fluent-cart/v2/coupons/apply', [
            'coupon_code'     => 'TENOFF',
            'order_uuid'      => $order->uuid,
            'applied_coupons' => [],
            'order_items'     => [$orderItem],
        ]);
        Assert::assertSame(200, $applyStatus, 'applying a valid coupon must succeed. Body: ' . wp_json_encode($applyBody));

        $calculated = (array) ($applyBody['calculated_items'] ?? []);
        $orderItem['discount_total'] = $calculated[(int) $item->id]['discount_total'] ?? null;
        Assert::assertNotNull($orderItem['discount_total'], 'apply must return the calculated item keyed by the line item id');
        $orderItem['line_total'] = $orderItem['subtotal'] - $orderItem['discount_total'];

        // Step 2: Save, handing the apply response through as the edit screen does.
        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id, [
            'customer_id'    => (int) $customer->id,
            'subtotal'       => Helper::toCent(50),
            'total_amount'   => Helper::toCent(40),
            'order_items'    => [$orderItem],
            'applied_coupon' => (array) ($applyBody['applied_coupons'] ?? []),
        ]);

        Assert::assertSame(200, $status, 'an update carrying the apply endpoint discounts must succeed. Body: ' . wp_json_encode($body));

        global $wpdb;
        $amount = $wpdb->get_var(
            $wpdb->prepare("SELECT amount FROM {$wpdb->prefix}fct_applied_coupons WHERE order_id = %d AND coupon_id = %d", $order->id, $coupon->id)
        );
        Assert::assertEquals(1000, (float) $amount, 'the coupon must be attached with its calculated $10.00 discount');
    }
}

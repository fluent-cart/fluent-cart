<?php

namespace Tests\Functional\Rest\Orders;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\LabelRelationship;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderDownloadPermission;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\OrderOperation;
use FluentCart\App\Models\Subscription;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\AppliedCouponFactory;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\CouponFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTaxRateFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/orders.md → "Deleting an order" section, items 62-67.
 *
 * Routes under test:
 *   DELETE /fluent-cart/v2/orders/{order_id}     orders/delete
 *
 * deleteOrder() (OrderController:459) flow:
 *   1. Order::find or 404
 *   2. canBeDeleted() (Order:1008) — WP_Error if status not on_hold/canceled, paid+non-free,
 *      or active subscription attached
 *   3. transaction: child subs deleted (subscription type), OrderDeleting dispatched,
 *      related rows removed (transactions, items, addresses, meta, taxes, ops, coupons,
 *      cart, downloads, labels), orders deleted
 *   4. RenewalOrderDeleted (renewal type) or OrderDeleted (regular) dispatched
 */
class DeleteOrderCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        // ProductFactory inserts a fluent_products CPT row in wp_posts that resetFluentCartTables
        // (truncates fct_* only) leaves behind. Purge to keep methods independent across runs.
        $I->purgeFluentProductPosts();
    }

    /**
     * Spec: Cases/Functional/orders.md [62]
     *   "Admin can delete an order, and the order's related records are removed alongside it."
     */
    public function adminCanDeleteOrderAndRelatedRecordsAreRemovedAlongsideIt(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'delete-related@example.test']);

        $order = (new OrderFactory())
            ->withTransaction()
            ->withAddresses()
            ->create([
                'customer_id'    => $customer->id,
                'status'         => Status::ORDER_ON_HOLD,
                'payment_status' => Status::PAYMENT_PENDING,
                'subtotal'       => 0,
                'total_amount'   => 0,
                'invoice_no'     => 'INV-DEL-RELATED',
            ]);
        $orderId = (int) $order->id;

        // Seed every relation OrderController::deleteOrderRelatedData prunes (lines 556-575)
        // so the post-delete assertions below prove all of them are cleaned, not just a subset.
        (new OrderItemFactory())->create($orderId);
        (new OrderTaxRateFactory())->create([
            'order_id'  => $orderId,
            'total_tax' => 100,
        ]);
        $coupon = (new CouponFactory())->create();
        (new AppliedCouponFactory())->create([
            'order_id'  => $orderId,
            'coupon_id' => $coupon->id,
            'code'      => $coupon->code,
            'amount'    => 100,
        ]);
        (new CartFactory())->create([
            'order_id' => $orderId,
            'email'    => 'cart-attached-to-' . $orderId . '@example.test',
        ]);
        OrderMeta::query()->create([
            'order_id'   => $orderId,
            'meta_key'   => 'delete_relations_test',
            'meta_value' => 'sentinel',
        ]);
        OrderOperation::query()->create([
            'order_id'    => $orderId,
            'created_via' => 'phpunit-delete-test',
        ]);
        OrderDownloadPermission::query()->create([
            'order_id'       => $orderId,
            'variation_id'   => 1,
            'customer_id'    => (int) $customer->id,
            'download_id'    => 1,
            'download_count' => 0,
            'download_limit' => 1,
        ]);
        LabelRelationship::query()->create([
            'label_id'       => 1,
            'labelable_id'   => $orderId,
            'labelable_type' => Order::class,
        ]);

        global $wpdb;
        $relations = [
            'fct_order_items'                => 'order_id',
            'fct_order_transactions'         => 'order_id',
            'fct_order_addresses'            => 'order_id',
            'fct_order_meta'                 => 'order_id',
            'fct_order_tax_rate'             => 'order_id',
            'fct_order_operations'           => 'order_id',
            'fct_applied_coupons'            => 'order_id',
            'fct_carts'                      => 'order_id',
            'fct_order_download_permissions' => 'order_id',
        ];
        foreach ($relations as $table => $column) {
            Assert::assertGreaterThan(
                0,
                (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE {$column} = %d",
                    $orderId
                )),
                'fixture must seed at least one ' . $table . ' row before delete so the cleanup assertion is meaningful'
            );
        }
        Assert::assertGreaterThan(
            0,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fct_label_relationships WHERE labelable_id = %d AND labelable_type = %s",
                $orderId,
                Order::class
            )),
            'fixture must seed at least one fct_label_relationships row before delete so the cleanup assertion is meaningful'
        );

        [, $body] = $I->delete('/fluent-cart/v2/orders/' . $orderId);

        $I->seeResponseCodeIs(200);
        Assert::assertSame(
            'success',
            (string) ($body['data']['status'] ?? ''),
            'delete success payload must surface status=success so the admin UI can flag the row removed. Body: ' . wp_json_encode($body)
        );

        Assert::assertSame(
            0,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                $orderId
            )),
            'fct_orders row must be removed after delete'
        );
        foreach ($relations as $table => $column) {
            Assert::assertSame(
                0,
                (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE {$column} = %d",
                    $orderId
                )),
                $table . ' rows must be removed alongside the order'
            );
        }
        Assert::assertSame(
            0,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fct_label_relationships WHERE labelable_id = %d AND labelable_type = %s",
                $orderId,
                Order::class
            )),
            'fct_label_relationships rows scoped to the deleted order must be removed alongside it'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [63]
     *   "Admin sees subscriptions linked to a deleted subscription-type order
     *    removed alongside the order."
     */
    public function adminSeesSubscriptionsLinkedToDeletedSubscriptionTypeOrderRemovedAlongsideOrder(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'delete-subscription@example.test']);
        $product  = (new ProductFactory())->create();
        $variant  = $product->variants->first();

        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'type'           => Status::ORDER_TYPE_SUBSCRIPTION,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'subtotal'       => 0,
            'total_amount'   => 0,
            'invoice_no'     => 'INV-DEL-SUB',
        ]);
        $subscription = (new SubscriptionFactory())->create([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => $product->ID,
            'variation_id'    => $variant->id,
            'status'          => Status::SUBSCRIPTION_CANCELED,
        ]);

        global $wpdb;
        $orderId = (int) $order->id;
        $subId   = (int) $subscription->id;
        Assert::assertSame(1, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d", $subId
        )), 'fixture must seed the subscription row before delete');

        [, $body] = $I->delete('/fluent-cart/v2/orders/' . $orderId);

        $I->seeResponseCodeIs(200);
        Assert::assertSame(
            'success',
            (string) ($body['data']['status'] ?? ''),
            'subscription-order delete must succeed. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            0,
            Subscription::query()->where('parent_order_id', $orderId)->count(),
            'subscriptions whose parent_order_id matches the deleted subscription-type order must be removed'
        );
        Assert::assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders WHERE id = %d", $orderId
        )), 'fct_orders row must be removed after delete');
    }

    /**
     * Spec: Cases/Functional/orders.md [64]
     *   "Admin sees an \"order deleted\" notification triggered when a regular order is removed."
     */
    public function adminSeesOrderDeletedNotificationTriggeredWhenRegularOrderIsRemoved(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'delete-notification@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'subtotal'       => 0,
            'total_amount'   => 0,
            'invoice_no'     => 'INV-DEL-NOTIFY',
            'type'           => Status::ORDER_TYPE_PAYMENT,
        ]);

        $deletedCaptured = [];
        $renewalCaptured = [];
        $deletedListener = function ($payload) use (&$deletedCaptured) {
            $deletedCaptured[] = $payload;
        };
        $renewalListener = function ($payload) use (&$renewalCaptured) {
            $renewalCaptured[] = $payload;
        };
        add_action('fluent_cart/order_deleted', $deletedListener, 10, 1);
        add_action('fluent_cart/renewal_order_deleted', $renewalListener, 10, 1);

        try {
            $orderId = (int) $order->id;
            [, $body] = $I->delete('/fluent-cart/v2/orders/' . $orderId);
        } finally {
            remove_action('fluent_cart/order_deleted', $deletedListener, 10);
            remove_action('fluent_cart/renewal_order_deleted', $renewalListener, 10);
        }

        $I->seeResponseCodeIs(200);
        Assert::assertCount(
            1,
            $deletedCaptured,
            'fluent_cart/order_deleted action must fire exactly once for a regular order delete. Captured: ' . count($deletedCaptured) . ' Body: ' . wp_json_encode($body)
        );
        Assert::assertCount(
            0,
            $renewalCaptured,
            'fluent_cart/renewal_order_deleted must NOT fire for a regular (payment-type) order'
        );
        Assert::assertArrayHasKey('order', (array) $deletedCaptured[0], 'order_deleted event payload must include the order key');
        Assert::assertSame(
            (int) $order->id,
            (int) ($deletedCaptured[0]['order']->id ?? 0),
            'order_deleted payload order id must match the just-deleted order id'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [65]
     *   "Admin sees a \"renewal order deleted\" notification triggered when a renewal order is removed."
     */
    public function adminSeesRenewalOrderDeletedNotificationTriggeredWhenRenewalOrderIsRemoved(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'delete-renewal-notification@example.test']);

        $parent = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'type'           => Status::ORDER_TYPE_SUBSCRIPTION,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'subtotal'       => 0,
            'total_amount'   => 0,
            'invoice_no'     => 'INV-DEL-RENEWAL-PARENT',
        ]);
        $renewal = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'parent_id'      => $parent->id,
            'type'           => Status::ORDER_TYPE_RENEWAL,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'subtotal'       => 0,
            'total_amount'   => 0,
            'invoice_no'     => 'INV-DEL-RENEWAL',
        ]);

        $deletedCaptured = [];
        $renewalCaptured = [];
        $deletedListener = function ($payload) use (&$deletedCaptured) {
            $deletedCaptured[] = $payload;
        };
        $renewalListener = function ($payload) use (&$renewalCaptured) {
            $renewalCaptured[] = $payload;
        };
        add_action('fluent_cart/order_deleted', $deletedListener, 10, 1);
        add_action('fluent_cart/renewal_order_deleted', $renewalListener, 10, 1);

        try {
            [, $body] = $I->delete('/fluent-cart/v2/orders/' . (int) $renewal->id);
        } finally {
            remove_action('fluent_cart/order_deleted', $deletedListener, 10);
            remove_action('fluent_cart/renewal_order_deleted', $renewalListener, 10);
        }

        $I->seeResponseCodeIs(200);
        Assert::assertCount(
            1,
            $renewalCaptured,
            'fluent_cart/renewal_order_deleted action must fire exactly once for a renewal-type order delete. Captured: ' . count($renewalCaptured) . ' Body: ' . wp_json_encode($body)
        );
        Assert::assertCount(
            0,
            $deletedCaptured,
            'fluent_cart/order_deleted must NOT fire when the order being removed is a renewal'
        );
        Assert::assertArrayHasKey('order', (array) $renewalCaptured[0], 'renewal_order_deleted event payload must include the order key');
        Assert::assertSame(
            (int) $renewal->id,
            (int) ($renewalCaptured[0]['order']->id ?? 0),
            'renewal_order_deleted payload order id must match the just-deleted renewal order id'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [66]
     *   "Admin receives a clear \"not found\" error when deleting an order that does not exist."
     */
    public function adminReceivesNotFoundErrorWhenDeletingOrderThatDoesNotExist(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->delete('/fluent-cart/v2/orders/999999');

        $I->seeResponseCodeIs(404);
        Assert::assertStringContainsStringIgnoringCase(
            'not found',
            (string) ($body['message'] ?? ''),
            'missing-order delete must surface the literal "not found" phrase so the spec sentence "clear not-found error" is honored. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            'error',
            (string) ($body['data']['status'] ?? ''),
            'missing-order delete payload must mark data.status=error so the admin UI can branch on the failure path. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [67]
     *   "Admin is rejected with a clear error when deleting an order that the system considers undeletable."
     */
    public function adminIsRejectedWithClearErrorWhenDeletingOrderThatSystemConsidersUndeletable(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'undeletable@example.test']);

        // Order::canBeDeleted() rejects live-mode orders whose status is neither
        // on_hold nor canceled — Processing is undeletable by definition.
        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_PROCESSING,
            'payment_status' => Status::PAYMENT_PENDING,
            'subtotal'       => 0,
            'total_amount'   => 0,
            'invoice_no'     => 'INV-UNDELETABLE',
        ]);

        [, $body] = $I->delete('/fluent-cart/v2/orders/' . (int) $order->id);

        $I->seeResponseCodeIs(400);
        Assert::assertNotEmpty(
            (string) ($body['message'] ?? ''),
            'undeletable-order rejection must surface a human-readable message explaining why. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            'error',
            (string) ($body['data']['status'] ?? ''),
            'undeletable-order rejection must mark data.status=error so the admin UI can branch on the failure path. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            'order_cannot_be_deleted',
            (string) ($body['data']['reason'] ?? ''),
            'undeletable-order rejection must surface the order_cannot_be_deleted reason code so callers can branch programmatically. Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        Assert::assertSame(
            1,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders WHERE id = %d", (int) $order->id
            )),
            'rejected delete must NOT remove the underlying fct_orders row'
        );
    }
}

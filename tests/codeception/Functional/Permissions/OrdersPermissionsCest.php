<?php

namespace Tests\Functional\Permissions;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\ShippingMethodFactory;
use Tests\Support\Factory\ShippingZoneFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/orders.md → "Working with orders as a Store manager",
 * "Working with orders as a Worker", "Working with orders as an Accountant"
 * sections, items 80-114.
 *
 * Order routes (under /orders, OrderPolicy) and their meta permissions:
 *   GET    /orders                                           orders/view
 *   POST   /orders                                           orders/create
 *   POST   /orders/calculate-shipping                        orders/create + orders/manage
 *   POST   /orders/calculate-tax                             orders/create + orders/manage (any)
 *   GET    /orders/shipping_methods                          orders/manage
 *   POST   /orders/do-bulk-action                            orders/delete
 *   POST   /orders/{order}/mark-as-paid                      orders/manage
 *   POST   /orders/{order}/generate-missing-licenses         orders/manage
 *   GET    /orders/{order_id}                                orders/view
 *   POST   /orders/{order_id}                                orders/manage
 *   POST   /orders/{order_id}/refund                         orders/can_refund
 *   POST   /orders/{order_id}/change-customer                orders/manage
 *   POST   /orders/{order_id}/create-and-change-customer     orders/manage
 *   DELETE /orders/{order_id}                                orders/delete
 *   PUT    /orders/{order}/statuses                          orders/manage_statuses
 *   GET    /orders/{order}/transactions                      orders/view
 *   POST   /orders/{order}/transactions/{tx}/accept-dispute/ orders/manage
 *   PUT    /orders/{order}/transactions/{tx}/status          orders/manage
 *   PUT    /orders/{order}/sync-statuses                     orders/manage
 *   POST   /orders/{order}/create-custom                     orders/create
 *
 * PermissionManager role grants:
 *   manager     — orders/view, manage_statuses, can_refund, manage, export, delete
 *   worker      — orders/view, manage_statuses
 *   accountant  — orders/view, export
 *
 * "with" tests rely on each role's default permission grant (no strip).
 * "without" tests cover permissions the role lacks by default (no strip).
 */
class OrdersPermissionsCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    private function makeDeletableOrder(int $customerId, string $invoiceSuffix): int
    {
        return (int) (new OrderFactory())->create([
            'customer_id'    => $customerId,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'subtotal'       => 0,
            'total_amount'   => 0,
            'invoice_no'     => 'INV-PERM-' . $invoiceSuffix,
        ])->id;
    }

    // -------------------------------------------------------------------------
    // Items 80-87 — Store manager
    // -------------------------------------------------------------------------

    /**
     * Spec: Cases/Functional/orders.md [80]
     *   "Store manager with the \"view orders\" permission can list and view orders."
     */
    public function storeManagerWithViewOrdersPermissionCanListAndViewOrders(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-mgr-view@example.test']);
        $orderId  = $this->makeDeletableOrder((int) $customer->id, 'MGR-VIEW');

        $I->loginAsManager();

        [$listStatus, $listBody] = $I->get('/fluent-cart/v2/orders');
        Assert::assertSame(200, $listStatus, 'manager-with-view list-orders must return 200. Body: ' . wp_json_encode($listBody));
        $listIds = array_map('intval', array_column((array) ($listBody['orders']['data'] ?? []), 'id'));
        Assert::assertContains($orderId, $listIds, 'manager-with-view list response must include the seeded order id');

        [$viewStatus, $viewBody] = $I->get('/fluent-cart/v2/orders/' . $orderId);
        Assert::assertSame(200, $viewStatus, 'manager-with-view view-order must return 200. Body: ' . wp_json_encode($viewBody));
        Assert::assertSame($orderId, (int) ($viewBody['order']['id'] ?? 0), 'manager-with-view view-order must echo the requested order id');
    }

    /**
     * Spec: Cases/Functional/orders.md [81]
     *   "Store manager without the \"create orders\" permission cannot create an order."
     */
    public function storeManagerWithoutCreateOrdersPermissionCannotCreateOrder(FunctionalTester $I): void
    {
        $I->loginAsManager();

        [, $body] = $I->post('/fluent-cart/v2/orders', [
            'customer_id' => 0,
            'order_items' => [],
        ]);

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'manager-without-create create-order denial must surface rest_forbidden code. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty($body['message'] ?? '', 'manager-without-create denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/orders.md [82]
     *   "Store manager without the \"create orders\" permission cannot add a custom line item to an order."
     */
    public function storeManagerWithoutCreateOrdersPermissionCannotAddCustomLineItem(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-mgr-add-custom@example.test']);
        $orderId  = $this->makeDeletableOrder((int) $customer->id, 'MGR-ADDCUSTOM');

        $I->loginAsManager();
        [, $body] = $I->post('/fluent-cart/v2/orders/' . $orderId . '/create-custom', []);

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'manager-without-create add-custom-item denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'manager-without-create add-custom-item denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/orders.md [83]
     *   "Store manager with the \"manage orders\" permission can edit an order."
     */
    public function storeManagerWithManageOrdersPermissionCanEditOrder(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-mgr-edit@example.test']);
        $product  = (new ProductFactory())
            ->withVariantAttributes(['item_price' => Helper::toCent(50)])
            ->create();
        $variant  = $product->variants->first();

        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'subtotal'       => Helper::toCent(50),
            'total_amount'   => Helper::toCent(50),
            'invoice_no'     => 'INV-PERM-MGR-EDIT',
            'note'           => 'before',
        ]);
        (new OrderItemFactory())->create((int) $order->id, [
            'post_id'    => (int) $product->ID,
            'object_id'  => (int) $variant->id,
            'unit_price' => Helper::toCent(50),
            'subtotal'   => Helper::toCent(50),
            'line_total' => Helper::toCent(50),
        ]);

        $I->loginAsManager();
        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id, [
            'customer_id'    => (int) $customer->id,
            'note'           => 'updated by manager',
            'shipping_total' => 0,
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

        Assert::assertSame(200, $status, 'manager-with-manage edit-order must return 200. Body: ' . wp_json_encode($body));
        global $wpdb;
        $persistedNote = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT note FROM {$wpdb->prefix}fct_orders WHERE id = %d",
            (int) $order->id
        ));
        Assert::assertSame(
            'updated by manager',
            $persistedNote,
            'manager-with-manage edit must persist the note column. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [84]
     *   "Store manager with the \"delete orders\" permission can delete an order."
     */
    public function storeManagerWithDeleteOrdersPermissionCanDeleteOrder(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-mgr-delete@example.test']);
        $orderId  = $this->makeDeletableOrder((int) $customer->id, 'MGR-DELETE');

        $I->loginAsManager();
        [$status, $body] = $I->delete('/fluent-cart/v2/orders/' . $orderId);

        Assert::assertSame(200, $status, 'manager-with-delete must return 200. Body: ' . wp_json_encode($body));
        global $wpdb;
        Assert::assertSame(
            0,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                $orderId
            )),
            'manager-with-delete must remove the underlying fct_orders row. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [85]
     *   "Store manager with the \"refund orders\" permission can refund an order."
     */
    public function storeManagerWithRefundOrdersPermissionCanRefundOrder(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-mgr-refund@example.test']);
        $order    = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_PROCESSING,
            'payment_status' => Status::PAYMENT_PAID,
            'subtotal'       => Helper::toCent(200),
            'total_amount'   => Helper::toCent(200),
            'total_paid'     => Helper::toCent(200),
            'invoice_no'     => 'INV-PERM-MGR-REFUND',
        ]);
        $transaction = (new OrderTransactionFactory())->create((int) $order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => Helper::toCent(200),
        ]);

        $I->loginAsManager();
        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/refund', [
            'refund_info' => [
                'transaction_id' => (int) $transaction->id,
                // Cents since c623df9d2a (#2614): 7500 refunds $75.00.
                'amount'         => Helper::toCent(75),
                'note'           => 'manager refund',
            ],
        ]);

        Assert::assertNotSame(403, $status, 'manager-with-can_refund refund-order must NOT be rejected by the policy gate. Body: ' . wp_json_encode($body));
        Assert::assertNotSame(401, $status, 'manager-with-can_refund refund-order must NOT be rejected as unauthenticated. Body: ' . wp_json_encode($body));
        Assert::assertSame(200, $status, 'manager-with-can_refund refund-order must succeed. Body: ' . wp_json_encode($body));
        global $wpdb;
        Assert::assertSame(
            Helper::toCent(75),
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT total_refund FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                (int) $order->id
            )),
            'manager-with-can_refund must persist total_refund=Helper::toCent(75) (7500 cents), the amount sent in cents, so a dollars-vs-cents regression on the refund path is caught. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [86]
     *   "Store manager with the \"manage order statuses\" permission can change an order's status."
     */
    public function storeManagerWithManageOrderStatusesPermissionCanChangeOrderStatus(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-mgr-changestatus@example.test']);
        $order    = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'invoice_no'     => 'INV-PERM-MGR-STATUS',
        ]);

        $I->loginAsManager();
        [$status, $body] = $I->put('/fluent-cart/v2/orders/' . (int) $order->id . '/statuses', [
            'action'   => 'change_order_status',
            'statuses' => ['order_status' => Status::ORDER_PROCESSING],
        ]);

        Assert::assertSame(200, $status, 'manager-with-manage_statuses change-status must return 200. Body: ' . wp_json_encode($body));
        global $wpdb;
        $persistedStatus = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}fct_orders WHERE id = %d",
            (int) $order->id
        ));
        Assert::assertSame(
            Status::ORDER_PROCESSING,
            $persistedStatus,
            'manager-with-manage_statuses must persist the new order status. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [87]
     *   "Store manager with the \"manage orders\" permission can preview shipping and tax for an order."
     */
    public function storeManagerWithManageOrdersPermissionCanPreviewShippingAndTax(FunctionalTester $I): void
    {
        $product = (new ProductFactory())
            ->withVariantAttributes(['item_price' => Helper::toCent(50)])
            ->create();
        $variant = $product->variants->first();
        $zone    = (new ShippingZoneFactory())->create(['region' => 'US']);
        $method  = (new ShippingMethodFactory())->create([
            'zone_id'  => (int) $zone->id,
            'type'     => 'flat_rate',
            'amount'   => 5,
            'settings' => ['configure_rate' => 'per_order'],
        ]);

        $I->loginAsManager();

        [$shipStatus, $shipBody] = $I->post('/fluent-cart/v2/orders/calculate-shipping', [
            'shipping_id' => (int) $method->id,
            'order_items' => [
                ['id' => (int) $variant->id, 'quantity' => 1],
            ],
        ]);
        Assert::assertSame(200, $shipStatus, 'manager-with-manage calculate-shipping must succeed. Body: ' . wp_json_encode($shipBody));
        Assert::assertArrayHasKey('shipping_charge', (array) $shipBody, 'calculate-shipping success body must include the shipping_charge key. Body: ' . wp_json_encode($shipBody));
        Assert::assertArrayHasKey('order_items', (array) $shipBody, 'calculate-shipping success body must include the order_items key with computed per-item charges');

        [$taxStatus, $taxBody] = $I->post('/fluent-cart/v2/orders/calculate-tax', [
            'country'  => 'US',
            'state'    => 'NY',
            'city'     => 'New York',
            'postcode' => '10001',
            'items'    => [
                [
                    'post_id'        => (int) $product->ID,
                    'object_id'      => (int) $variant->id,
                    'subtotal'       => Helper::toCent(50),
                    'discount_total' => 0,
                    'quantity'       => 1,
                ],
            ],
        ]);
        Assert::assertSame(200, $taxStatus, 'manager-with-manage calculate-tax must succeed. Body: ' . wp_json_encode($taxBody));
        Assert::assertArrayHasKey('tax_total', (array) $taxBody, 'calculate-tax success body must include the tax_total key. Body: ' . wp_json_encode($taxBody));
        Assert::assertArrayHasKey('tax_lines', (array) $taxBody, 'calculate-tax success body must include the tax_lines key');
    }

    // -------------------------------------------------------------------------
    // Items 88-100 — Worker
    // -------------------------------------------------------------------------

    /**
     * Spec: Cases/Functional/orders.md [88]
     *   "Worker with the \"view orders\" permission can list and view orders."
     */
    public function workerWithViewOrdersPermissionCanListAndViewOrders(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-w-view@example.test']);
        $orderId  = $this->makeDeletableOrder((int) $customer->id, 'W-VIEW');

        $I->loginAsWorker();

        [$listStatus, $listBody] = $I->get('/fluent-cart/v2/orders');
        Assert::assertSame(200, $listStatus, 'worker-with-view list-orders must return 200. Body: ' . wp_json_encode($listBody));
        $listIds = array_map('intval', array_column((array) ($listBody['orders']['data'] ?? []), 'id'));
        Assert::assertContains($orderId, $listIds, 'worker-with-view list response must include the seeded order id');

        [$viewStatus, $viewBody] = $I->get('/fluent-cart/v2/orders/' . $orderId);
        Assert::assertSame(200, $viewStatus, 'worker-with-view view-order must return 200. Body: ' . wp_json_encode($viewBody));
        Assert::assertSame($orderId, (int) ($viewBody['order']['id'] ?? 0), 'worker-with-view view-order must echo the requested order id');
    }

    /**
     * Spec: Cases/Functional/orders.md [89]
     *   "Worker with the \"manage order statuses\" permission can change an order's status."
     */
    public function workerWithManageOrderStatusesPermissionCanChangeOrderStatus(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-w-changestatus@example.test']);
        $order    = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'invoice_no'     => 'INV-PERM-W-STATUS',
        ]);

        $I->loginAsWorker();
        [$status, $body] = $I->put('/fluent-cart/v2/orders/' . (int) $order->id . '/statuses', [
            'action'   => 'change_order_status',
            'statuses' => ['order_status' => Status::ORDER_PROCESSING],
        ]);

        Assert::assertSame(200, $status, 'worker-with-manage_statuses change-status must return 200. Body: ' . wp_json_encode($body));
        global $wpdb;
        Assert::assertSame(
            Status::ORDER_PROCESSING,
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                (int) $order->id
            )),
            'worker-with-manage_statuses must persist the new order status'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [90]
     *   "Worker without the \"create orders\" permission cannot create an order."
     */
    public function workerWithoutCreateOrdersPermissionCannotCreateOrder(FunctionalTester $I): void
    {
        $I->loginAsWorker();
        [, $body] = $I->post('/fluent-cart/v2/orders', [
            'customer_id' => 0,
            'order_items' => [],
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker-without-create create-order denial must surface rest_forbidden code. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty($body['message'] ?? '', 'worker-without-create denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/orders.md [91]
     *   "Worker without the \"manage orders\" permission cannot edit an order."
     */
    public function workerWithoutManageOrdersPermissionCannotEditOrder(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-w-edit@example.test']);
        $orderId  = $this->makeDeletableOrder((int) $customer->id, 'W-EDIT');

        $I->loginAsWorker();
        [, $body] = $I->post('/fluent-cart/v2/orders/' . $orderId, [
            'note' => 'forbidden',
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker-without-manage edit-order denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'worker-without-manage edit-order denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/orders.md [92]
     *   "Worker without the \"delete orders\" permission cannot delete an order."
     */
    public function workerWithoutDeleteOrdersPermissionCannotDeleteOrder(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-w-delete@example.test']);
        $orderId  = $this->makeDeletableOrder((int) $customer->id, 'W-DELETE');

        $I->loginAsWorker();
        [, $body] = $I->delete('/fluent-cart/v2/orders/' . $orderId);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker-without-delete delete-order denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'worker-without-delete delete-order denial must include a permission-denied message');

        global $wpdb;
        Assert::assertSame(
            1,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                $orderId
            )),
            'worker-without-delete denial must NOT remove the underlying fct_orders row'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [93]
     *   "Worker without the \"refund orders\" permission cannot refund an order."
     */
    public function workerWithoutRefundOrdersPermissionCannotRefundOrder(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-w-refund@example.test']);
        $orderId  = $this->makeDeletableOrder((int) $customer->id, 'W-REFUND');

        $I->loginAsWorker();
        [, $body] = $I->post('/fluent-cart/v2/orders/' . $orderId . '/refund', [
            'refund_info' => ['transaction_id' => 1, 'amount' => 1],
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker-without-can_refund refund denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'worker-without-can_refund refund denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/orders.md [94]
     *   "Worker without the \"delete orders\" permission cannot run a bulk action that deletes orders."
     *
     * Every action POST /orders/do-bulk-action accepts deletes orders, so the route
     * declares orders/delete (FC-SEC-01). The worker role holds neither orders/manage
     * nor orders/delete, so the denial stands either way.
     */
    public function workerWithoutDeleteOrdersPermissionCannotRunBulkAction(FunctionalTester $I): void
    {
        $I->loginAsWorker();
        [, $body] = $I->post('/fluent-cart/v2/orders/do-bulk-action', [
            'action'    => 'delete_orders',
            'order_ids' => [],
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker-without-delete bulk-action denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'worker-without-delete bulk-action denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/orders.md [95]
     *   "Worker without the \"manage orders\" permission cannot mark an order as paid."
     */
    public function workerWithoutManageOrdersPermissionCannotMarkOrderAsPaid(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-w-markpaid@example.test']);
        $orderId  = $this->makeDeletableOrder((int) $customer->id, 'W-MARKPAID');

        $I->loginAsWorker();
        [, $body] = $I->post('/fluent-cart/v2/orders/' . $orderId . '/mark-as-paid', []);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker-without-manage mark-as-paid denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'worker-without-manage mark-as-paid denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/orders.md [96]
     *   "Worker without the \"manage orders\" permission cannot preview shipping or tax for an order."
     */
    public function workerWithoutManageOrdersPermissionCannotPreviewShippingOrTax(FunctionalTester $I): void
    {
        $I->loginAsWorker();

        [, $shipBody] = $I->post('/fluent-cart/v2/orders/calculate-shipping', [
            'address' => ['country' => 'US', 'state' => 'NY'],
            'items'   => [],
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $shipBody['code'] ?? '', 'worker-without-manage calculate-shipping denial must surface rest_forbidden code');
        Assert::assertNotEmpty($shipBody['message'] ?? '', 'worker-without-manage calculate-shipping denial must include a permission-denied message');

        [, $taxBody] = $I->post('/fluent-cart/v2/orders/calculate-tax', [
            'address' => ['country' => 'US', 'state' => 'NY'],
            'items'   => [],
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $taxBody['code'] ?? '', 'worker-without-manage calculate-tax denial must surface rest_forbidden code');
        Assert::assertNotEmpty($taxBody['message'] ?? '', 'worker-without-manage calculate-tax denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/orders.md [97]
     *   "Worker without the \"manage orders\" permission cannot regenerate missing licenses on an order."
     */
    public function workerWithoutManageOrdersPermissionCannotRegenerateLicenses(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-w-licenses@example.test']);
        $orderId  = $this->makeDeletableOrder((int) $customer->id, 'W-LICENSES');

        $I->loginAsWorker();
        [, $body] = $I->post('/fluent-cart/v2/orders/' . $orderId . '/generate-missing-licenses', []);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker-without-manage regenerate-licenses denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'worker-without-manage regenerate-licenses denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/orders.md [98]
     *   "Worker without the \"manage orders\" permission cannot accept a transaction dispute."
     */
    public function workerWithoutManageOrdersPermissionCannotAcceptDispute(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-w-dispute@example.test']);
        $order    = (new OrderFactory())
            ->withTransaction()
            ->create([
                'customer_id'    => $customer->id,
                'status'         => Status::ORDER_ON_HOLD,
                'payment_status' => Status::PAYMENT_PENDING,
                'subtotal'       => 0,
                'total_amount'   => 0,
                'invoice_no'     => 'INV-PERM-W-DISPUTE',
            ]);
        global $wpdb;
        $txId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fct_order_transactions WHERE order_id = %d",
            (int) $order->id
        ));

        $I->loginAsWorker();
        [, $body] = $I->post(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/transactions/' . $txId . '/accept-dispute',
            []
        );
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker-without-manage accept-dispute denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'worker-without-manage accept-dispute denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/orders.md [99]
     *   "Worker without the \"manage orders\" permission cannot change a transaction's payment status."
     */
    public function workerWithoutManageOrdersPermissionCannotChangeTransactionStatus(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-w-txstatus@example.test']);
        $order    = (new OrderFactory())
            ->withTransaction()
            ->create([
                'customer_id'    => $customer->id,
                'status'         => Status::ORDER_ON_HOLD,
                'payment_status' => Status::PAYMENT_PENDING,
                'subtotal'       => 0,
                'total_amount'   => 0,
                'invoice_no'     => 'INV-PERM-W-TXSTATUS',
            ]);
        global $wpdb;
        $txId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fct_order_transactions WHERE order_id = %d",
            (int) $order->id
        ));

        $I->loginAsWorker();
        [, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/transactions/' . $txId . '/status',
            ['status' => Status::TRANSACTION_SUCCEEDED]
        );
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker-without-manage change-tx-status denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'worker-without-manage change-tx-status denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/orders.md [100]
     *   "Worker without the \"manage orders\" permission cannot resync an order's status from its latest payment."
     */
    public function workerWithoutManageOrdersPermissionCannotResyncOrderStatus(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-w-resync@example.test']);
        $orderId  = $this->makeDeletableOrder((int) $customer->id, 'W-RESYNC');

        $I->loginAsWorker();
        [, $body] = $I->put('/fluent-cart/v2/orders/' . $orderId . '/sync-statuses', []);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker-without-manage resync-status denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'worker-without-manage resync-status denial must include a permission-denied message');
    }

    // -------------------------------------------------------------------------
    // Items 101-114 — Accountant
    // -------------------------------------------------------------------------

    /**
     * Spec: Cases/Functional/orders.md [101]
     *   "Accountant with the \"view orders\" permission can list and view orders."
     */
    public function accountantWithViewOrdersPermissionCanListAndViewOrders(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-a-view@example.test']);
        $orderId  = $this->makeDeletableOrder((int) $customer->id, 'A-VIEW');

        $I->loginAsAccountant();

        [$listStatus, $listBody] = $I->get('/fluent-cart/v2/orders');
        Assert::assertSame(200, $listStatus, 'accountant-with-view list-orders must return 200. Body: ' . wp_json_encode($listBody));
        $listIds = array_map('intval', array_column((array) ($listBody['orders']['data'] ?? []), 'id'));
        Assert::assertContains($orderId, $listIds, 'accountant-with-view list response must include the seeded order id');

        [$viewStatus, $viewBody] = $I->get('/fluent-cart/v2/orders/' . $orderId);
        Assert::assertSame(200, $viewStatus, 'accountant-with-view view-order must return 200. Body: ' . wp_json_encode($viewBody));
        Assert::assertSame($orderId, (int) ($viewBody['order']['id'] ?? 0), 'accountant-with-view view-order must echo the requested order id');
    }

    /**
     * Spec: Cases/Functional/orders.md [102]
     *   "Accountant without the \"create orders\" permission cannot create an order."
     */
    public function accountantWithoutCreateOrdersPermissionCannotCreateOrder(FunctionalTester $I): void
    {
        $I->loginAsAccountant();
        [, $body] = $I->post('/fluent-cart/v2/orders', [
            'customer_id' => 0,
            'order_items' => [],
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant-without-create create-order denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'accountant-without-create denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/orders.md [103]
     *   "Accountant without the \"manage orders\" permission cannot edit an order."
     */
    public function accountantWithoutManageOrdersPermissionCannotEditOrder(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-a-edit@example.test']);
        $orderId  = $this->makeDeletableOrder((int) $customer->id, 'A-EDIT');

        $I->loginAsAccountant();
        [, $body] = $I->post('/fluent-cart/v2/orders/' . $orderId, ['note' => 'forbidden']);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant-without-manage edit-order denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'accountant-without-manage edit-order denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/orders.md [104]
     *   "Accountant without the \"delete orders\" permission cannot delete an order."
     */
    public function accountantWithoutDeleteOrdersPermissionCannotDeleteOrder(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-a-delete@example.test']);
        $orderId  = $this->makeDeletableOrder((int) $customer->id, 'A-DELETE');

        $I->loginAsAccountant();
        [, $body] = $I->delete('/fluent-cart/v2/orders/' . $orderId);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant-without-delete delete-order denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'accountant-without-delete delete-order denial must include a permission-denied message');

        global $wpdb;
        Assert::assertSame(
            1,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                $orderId
            )),
            'accountant-without-delete denial must NOT remove the underlying fct_orders row'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [105]
     *   "Accountant without the \"refund orders\" permission cannot refund an order."
     */
    public function accountantWithoutRefundOrdersPermissionCannotRefundOrder(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-a-refund@example.test']);
        $orderId  = $this->makeDeletableOrder((int) $customer->id, 'A-REFUND');

        $I->loginAsAccountant();
        [, $body] = $I->post('/fluent-cart/v2/orders/' . $orderId . '/refund', [
            'refund_info' => ['transaction_id' => 1, 'amount' => 1],
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant-without-can_refund refund denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'accountant-without-can_refund refund denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/orders.md [106]
     *   "Accountant without the \"manage order statuses\" permission cannot change an order's status."
     */
    public function accountantWithoutManageOrderStatusesPermissionCannotChangeOrderStatus(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-a-changestatus@example.test']);
        $orderId  = $this->makeDeletableOrder((int) $customer->id, 'A-STATUS');

        $I->loginAsAccountant();
        [, $body] = $I->put('/fluent-cart/v2/orders/' . $orderId . '/statuses', [
            'action'   => 'change_order_status',
            'order_id' => $orderId,
            'status'   => Status::ORDER_PROCESSING,
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant-without-manage_statuses change-status denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'accountant-without-manage_statuses change-status denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/orders.md [107]
     *   "Accountant without the \"manage orders\" permission cannot preview shipping or tax for an order."
     */
    public function accountantWithoutManageOrdersPermissionCannotPreviewShippingOrTax(FunctionalTester $I): void
    {
        $I->loginAsAccountant();

        [, $shipBody] = $I->post('/fluent-cart/v2/orders/calculate-shipping', [
            'address' => ['country' => 'US', 'state' => 'NY'],
            'items'   => [],
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $shipBody['code'] ?? '', 'accountant-without-manage calculate-shipping denial must surface rest_forbidden code');
        Assert::assertNotEmpty($shipBody['message'] ?? '', 'accountant-without-manage calculate-shipping denial must include a permission-denied message');

        [, $taxBody] = $I->post('/fluent-cart/v2/orders/calculate-tax', [
            'address' => ['country' => 'US', 'state' => 'NY'],
            'items'   => [],
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $taxBody['code'] ?? '', 'accountant-without-manage calculate-tax denial must surface rest_forbidden code');
        Assert::assertNotEmpty($taxBody['message'] ?? '', 'accountant-without-manage calculate-tax denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/orders.md [108]
     *   "Accountant without the \"manage orders\" permission cannot list shipping methods applicable to a country and state."
     */
    public function accountantWithoutManageOrdersPermissionCannotListShippingMethods(FunctionalTester $I): void
    {
        $I->loginAsAccountant();
        [, $body] = $I->get('/fluent-cart/v2/orders/shipping_methods', [
            'country' => 'US',
            'state'   => 'NY',
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant-without-manage list-shipping-methods denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'accountant-without-manage list-shipping-methods denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/orders.md [109]
     *   "Accountant without the \"delete orders\" permission cannot run a bulk action that deletes orders."
     *
     * See the worker case above: the route gate is orders/delete (FC-SEC-01) and the
     * accountant role holds neither that nor orders/manage.
     */
    public function accountantWithoutDeleteOrdersPermissionCannotRunBulkAction(FunctionalTester $I): void
    {
        $I->loginAsAccountant();
        [, $body] = $I->post('/fluent-cart/v2/orders/do-bulk-action', [
            'action'    => 'delete_orders',
            'order_ids' => [],
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant-without-delete bulk-action denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'accountant-without-delete bulk-action denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/orders.md [110]
     *   "Accountant without the \"manage orders\" permission cannot mark an order as paid."
     */
    public function accountantWithoutManageOrdersPermissionCannotMarkOrderAsPaid(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-a-markpaid@example.test']);
        $orderId  = $this->makeDeletableOrder((int) $customer->id, 'A-MARKPAID');

        $I->loginAsAccountant();
        [, $body] = $I->post('/fluent-cart/v2/orders/' . $orderId . '/mark-as-paid', []);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant-without-manage mark-as-paid denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'accountant-without-manage mark-as-paid denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/orders.md [111]
     *   "Accountant without the \"manage orders\" permission cannot regenerate missing licenses on an order."
     */
    public function accountantWithoutManageOrdersPermissionCannotRegenerateLicenses(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-a-licenses@example.test']);
        $orderId  = $this->makeDeletableOrder((int) $customer->id, 'A-LICENSES');

        $I->loginAsAccountant();
        [, $body] = $I->post('/fluent-cart/v2/orders/' . $orderId . '/generate-missing-licenses', []);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant-without-manage regenerate-licenses denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'accountant-without-manage regenerate-licenses denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/orders.md [112]
     *   "Accountant without the \"manage orders\" permission cannot accept a transaction dispute."
     */
    public function accountantWithoutManageOrdersPermissionCannotAcceptDispute(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-a-dispute@example.test']);
        $order    = (new OrderFactory())
            ->withTransaction()
            ->create([
                'customer_id'    => $customer->id,
                'status'         => Status::ORDER_ON_HOLD,
                'payment_status' => Status::PAYMENT_PENDING,
                'subtotal'       => 0,
                'total_amount'   => 0,
                'invoice_no'     => 'INV-PERM-A-DISPUTE',
            ]);
        global $wpdb;
        $txId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fct_order_transactions WHERE order_id = %d",
            (int) $order->id
        ));

        $I->loginAsAccountant();
        [, $body] = $I->post(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/transactions/' . $txId . '/accept-dispute',
            []
        );
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant-without-manage accept-dispute denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'accountant-without-manage accept-dispute denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/orders.md [113]
     *   "Accountant without the \"manage orders\" permission cannot change a transaction's payment status."
     */
    public function accountantWithoutManageOrdersPermissionCannotChangeTransactionStatus(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-a-txstatus@example.test']);
        $order    = (new OrderFactory())
            ->withTransaction()
            ->create([
                'customer_id'    => $customer->id,
                'status'         => Status::ORDER_ON_HOLD,
                'payment_status' => Status::PAYMENT_PENDING,
                'subtotal'       => 0,
                'total_amount'   => 0,
                'invoice_no'     => 'INV-PERM-A-TXSTATUS',
            ]);
        global $wpdb;
        $txId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fct_order_transactions WHERE order_id = %d",
            (int) $order->id
        ));

        $I->loginAsAccountant();
        [, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/transactions/' . $txId . '/status',
            ['status' => Status::TRANSACTION_SUCCEEDED]
        );
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant-without-manage change-tx-status denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'accountant-without-manage change-tx-status denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/orders.md [114]
     *   "Accountant without the \"manage orders\" permission cannot resync an order's status from its latest payment."
     */
    public function accountantWithoutManageOrdersPermissionCannotResyncOrderStatus(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-a-resync@example.test']);
        $orderId  = $this->makeDeletableOrder((int) $customer->id, 'A-RESYNC');

        $I->loginAsAccountant();
        [, $body] = $I->put('/fluent-cart/v2/orders/' . $orderId . '/sync-statuses', []);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant-without-manage resync-status denial must surface rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'accountant-without-manage resync-status denial must include a permission-denied message');
    }
}

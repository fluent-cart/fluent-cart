<?php

namespace Tests\Functional\Rest\Orders;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/orders.md → "Creating an order" section, items 20-26.
 */
class CreateOrderCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/orders.md [20]
     *   "Admin can create a manual order with a customer and at least one item, and the order receives a unique identifier automatically."
     */
    public function adminCanCreateManualOrderWithCustomerAndAtLeastOneItem(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'create-manual-order@example.test']);
        $product  = (new ProductFactory())
            ->withVariantAttributes(['item_price' => Helper::toCent(50)])
            ->create();
        $variant  = $product->variants->first();

        [, $body] = $I->createOrder([
            'customer_id' => $customer->id,
            'order_items' => [
                [
                    'post_id'          => (int) $product->ID,
                    'object_id'        => (int) $variant->id,
                    'quantity'         => 1,
                    'unit_price'       => Helper::toCent(50),
                    'fulfillment_type' => 'digital',
                    'payment_type'     => 'onetime',
                    'other_info'       => ['payment_type' => 'onetime'],
                ],
            ],
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('order_id', (array) $body, 'create response must include the new order id');
        Assert::assertArrayHasKey('uuid', (array) $body, 'create response must include the auto-generated uuid');
        Assert::assertNotEmpty((string) ($body['uuid'] ?? ''), 'auto-generated uuid must be a non-empty string');

        $orderId = (int) $body['order_id'];
        Assert::assertGreaterThan(0, $orderId, 'create response must include a positive order id');

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}fct_orders WHERE id = %d", $orderId),
            ARRAY_A
        );
        Assert::assertNotEmpty($row, 'a row in fct_orders must exist for the created order id');
        Assert::assertSame((int) $customer->id, (int) $row['customer_id'], 'created order must persist the requested customer_id');
        Assert::assertSame((string) $body['uuid'], (string) $row['uuid'], 'response uuid must match the persisted uuid');

        $itemRows = $wpdb->get_results(
            $wpdb->prepare("SELECT id FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d", $orderId),
            ARRAY_A
        );
        Assert::assertCount(1, (array) $itemRows, 'exactly one order item must be persisted for the single requested line');
    }

    /**
     * The payload documented for POST /orders carries no preview revision:
     * the order saves the posted prices, as before multi-currency.
     */
    public function documentedPayloadWithoutPreviewSavesThePostedPrice(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'documented-payload@example.test']);
        $product  = (new ProductFactory())
            ->withVariantAttributes(['item_price' => Helper::toCent(50)])
            ->create();
        $variant  = $product->variants->first();

        [, $body] = $I->post('/fluent-cart/v2/orders', [
            'customer_id'    => $customer->id,
            'order_items'    => [
                [
                    'post_id'      => (int) $product->ID,
                    'variation_id' => (int) $variant->id,
                    'object_id'    => (int) $variant->id,
                    'quantity'     => 1,
                    'unit_price'   => 2500,
                    'price'        => 2500,
                    'item_total'   => 2500,
                    'total'        => 2500,
                    'line_total'   => 2500,
                    'title'        => 'Default',
                ],
            ],
            'subtotal'       => 2500,
            'total_amount'   => 2500,
            'payment_method' => 'offline_payment',
        ]);

        $I->seeResponseCodeIs(200);
        $orderId = (int) ($body['order_id'] ?? 0);
        Assert::assertGreaterThan(0, $orderId, is_array($body) ? (string) ($body['message'] ?? '') : '');

        global $wpdb;
        $item = $wpdb->get_row(
            $wpdb->prepare("SELECT unit_price FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d", $orderId),
            ARRAY_A
        );
        Assert::assertSame(2500, (int) $item['unit_price'], 'the posted price is saved, not the catalog price');
    }

    /**
     * Spec: Cases/Functional/orders.md [21]
     *   "Admin is rejected with a clear validation error when creating an order without selecting a customer."
     */
    public function adminIsRejectedWhenCreatingOrderWithoutCustomer(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        [$status, $body] = $I->createOrder([
            'order_items' => [
                [
                    'post_id'          => (int) $product->ID,
                    'object_id'        => (int) $variant->id,
                    'quantity'         => 1,
                    'unit_price'       => Helper::toCent(10),
                    'fulfillment_type' => 'digital',
                    'payment_type'     => 'onetime',
                    'other_info'       => ['payment_type' => 'onetime'],
                ],
            ],
        ]);

        Assert::assertNotSame(200, $status, 'request without customer_id must not return success status. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('customer_id', (array) $body, 'validation payload must key the failed rule by field name (customer_id). Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('required', (array) ($body['customer_id'] ?? []), 'validation entry for customer_id must surface the "required" rule key. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty((string) ($body['customer_id']['required'] ?? ''), 'required-rule message for customer_id must be non-empty');

        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders");
        Assert::assertSame(0, $count, 'no order row may be created when customer_id validation fails');
    }

    /**
     * Spec: Cases/Functional/orders.md [22]
     *   "Admin is rejected with a clear validation error when creating an order without any items."
     */
    public function adminIsRejectedWhenCreatingOrderWithoutAnyItems(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'create-no-items@example.test']);

        [$status, $body] = $I->createOrder([
            'customer_id' => $customer->id,
        ]);

        Assert::assertNotSame(200, $status, 'request without order_items must not return success status. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('order_items', (array) $body, 'validation payload must key the failed rule by field name (order_items). Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('required', (array) ($body['order_items'] ?? []), 'validation entry for order_items must surface the "required" rule key. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty((string) ($body['order_items']['required'] ?? ''), 'required-rule message for order_items must be non-empty');

        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders");
        Assert::assertSame(0, $count, 'no order row may be created when order_items validation fails');
    }

    /**
     * Spec: Cases/Functional/orders.md [23]
     *   "Admin is rejected when creating a manual order whose items include a subscription product."
     */
    public function adminIsRejectedWhenCreatingManualOrderWithSubscriptionItem(FunctionalTester $I): void
    {

        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'create-with-subscription@example.test']);

        // Build a product whose variation is a subscription variant.
        $product = (new ProductFactory())
            ->withVariantAttributes([
                'payment_type' => 'subscription',
                'other_info'   => ['payment_type' => 'subscription'],
            ])
            ->create();
        $variant = $product->variants->first();
        Assert::assertSame(
            'subscription',
            (string) $variant->payment_type,
            'precondition: factory must build a variant whose payment_type is subscription so the request points at a real subscription product'
        );

        // Submit the variant in a payload labelled as a one-time line. The spec wording
        // "manual order whose items include a subscription product" should be enforced
        // by reading the variant's payment_type — not by trusting the caller's label.
        [$status, $body] = $I->createOrder([
            'customer_id' => $customer->id,
            'order_items' => [
                [
                    'post_id'          => (int) $product->ID,
                    'object_id'        => (int) $variant->id,
                    'quantity'         => 1,
                    'unit_price'       => Helper::toCent(20),
                    'fulfillment_type' => 'digital',
                    'payment_type'     => 'onetime',
                    'other_info'       => ['payment_type' => 'onetime'],
                ],
            ],
        ]);

        Assert::assertNotSame(200, $status, 'manual order pointing at a subscription product must be rejected even when the payload mislabels it as onetime. Body: ' . wp_json_encode($body));
        $message = (string) ($body['message'] ?? '');
        Assert::assertStringContainsString(
            'Subscription',
            $message,
            'rejection message must mention "Subscription". Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders");
        Assert::assertSame(0, $count, 'no order row may be created when a subscription product is included in a manual order');
    }

    /**
     * Spec: Cases/Functional/orders.md [23a]
     *   A subscription product declared honestly as a subscription line is still accepted,
     *   and the order is recorded as a subscription order.
     */
    public function adminCanCreateManualOrderWithHonestlySubscriptionLabelledItem(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'create-honest-subscription@example.test']);

        $product = (new ProductFactory())
            ->withVariantAttributes([
                'payment_type' => 'subscription',
                'other_info'   => ['payment_type' => 'subscription'],
            ])
            ->create();
        $variant = $product->variants->first();

        [$status, $body] = $I->createOrder([
            'customer_id' => $customer->id,
            'order_items' => [
                [
                    'post_id'          => (int) $product->ID,
                    'object_id'        => (int) $variant->id,
                    'quantity'         => 1,
                    'unit_price'       => Helper::toCent(20),
                    'fulfillment_type' => 'digital',
                    'payment_type'     => 'subscription',
                    'other_info'       => [
                        'payment_type'    => 'subscription',
                        'repeat_interval' => 'month',
                        'times'           => 0,
                    ],
                ],
            ],
        ]);

        Assert::assertSame(200, $status, 'a correctly labelled subscription line must still be accepted. Body: ' . wp_json_encode($body));

        $orderId = (int) ($body['order_id'] ?? 0);
        Assert::assertGreaterThan(0, $orderId, 'create response must include a positive order id');

        global $wpdb;
        $type = (string) $wpdb->get_var(
            $wpdb->prepare("SELECT type FROM {$wpdb->prefix}fct_orders WHERE id = %d", $orderId)
        );
        Assert::assertSame(Status::ORDER_TYPE_SUBSCRIPTION, $type, 'an order carrying a subscription line must be stored as a subscription order');
    }

    /**
     * Spec: Cases/Functional/orders.md [23b]
     *   A one-time product labelled as a subscription must not create a subscription order.
     */
    public function adminIsRejectedWhenOneTimeProductIsLabelledAsSubscription(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'onetime-labelled-subscription@example.test']);

        $product = (new ProductFactory())
            ->withVariantAttributes([
                'payment_type' => 'onetime',
                'other_info'   => ['payment_type' => 'onetime'],
            ])
            ->create();
        $variant = $product->variants->first();
        Assert::assertNotSame(
            'subscription',
            (string) $variant->payment_type,
            'precondition: factory must build a one-time variant'
        );

        [$status, $body] = $I->createOrder([
            'customer_id' => $customer->id,
            'order_items' => [
                [
                    'post_id'          => (int) $product->ID,
                    'object_id'        => (int) $variant->id,
                    'quantity'         => 1,
                    'unit_price'       => Helper::toCent(20),
                    'fulfillment_type' => 'digital',
                    'payment_type'     => 'subscription',
                    'other_info'       => ['payment_type' => 'subscription'],
                ],
            ],
        ]);

        Assert::assertNotSame(200, $status, 'a one-time variation labelled as a subscription must be rejected. Body: ' . wp_json_encode($body));

        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders");
        Assert::assertSame(0, $count, 'no order row may be created, and none may be stored as a subscription order, for a one-time variation');
    }

    /**
     * Spec: Cases/Functional/orders.md [23c]
     *   The two payment_type labels a line can carry must not disagree — the controller and
     *   AdminOrderProcessor read different copies.
     */
    public function adminIsRejectedWhenLineCarriesConflictingPaymentTypeLabels(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'conflicting-payment-type@example.test']);

        $product = (new ProductFactory())
            ->withVariantAttributes([
                'payment_type' => 'subscription',
                'other_info'   => ['payment_type' => 'subscription'],
            ])
            ->create();
        $variant = $product->variants->first();

        // Top-level says subscription, nested says onetime. The controller reads both,
        // AdminOrderProcessor reads only the nested copy.
        [$status, $body] = $I->createOrder([
            'customer_id' => $customer->id,
            'order_items' => [
                [
                    'post_id'          => (int) $product->ID,
                    'object_id'        => (int) $variant->id,
                    'quantity'         => 1,
                    'unit_price'       => Helper::toCent(20),
                    'fulfillment_type' => 'digital',
                    'payment_type'     => 'subscription',
                    'other_info'       => ['payment_type' => 'onetime'],
                ],
            ],
        ]);

        Assert::assertNotSame(200, $status, 'conflicting payment_type labels on one line must be rejected. Body: ' . wp_json_encode($body));

        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders");
        Assert::assertSame(0, $count, 'no order row may be created when the line labels disagree');
    }

    /**
     * Spec: Cases/Functional/orders.md [23d]
     *   A subscription line must carry its label in other_info — the copy that
     *   AdminOrderProcessor reads and that the recurring config travels with.
     */
    public function adminIsRejectedWhenSubscriptionLabelIsMissingFromOtherInfo(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'subscription-label-top-level@example.test']);

        $product = (new ProductFactory())
            ->withVariantAttributes([
                'payment_type' => 'subscription',
                'other_info'   => ['payment_type' => 'subscription'],
            ])
            ->create();
        $variant = $product->variants->first();

        [$status, $body] = $I->createOrder([
            'customer_id' => $customer->id,
            'order_items' => [
                [
                    'post_id'          => (int) $product->ID,
                    'object_id'        => (int) $variant->id,
                    'quantity'         => 1,
                    'unit_price'       => Helper::toCent(20),
                    'fulfillment_type' => 'digital',
                    'payment_type'     => 'subscription',
                ],
            ],
        ]);

        Assert::assertNotSame(200, $status, 'a subscription line without other_info.payment_type must be rejected. Body: ' . wp_json_encode($body));

        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders");
        Assert::assertSame(0, $count, 'no order row may be created when the subscription label never reaches AdminOrderProcessor');
    }

    /**
     * Spec: Cases/Functional/orders.md [24]
     *   "Admin sees money amounts on a created order stored consistently (e.g. $12.34 round-trips correctly)."
     */
    public function adminSeesMoneyAmountsOnCreatedOrderStoredConsistently(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'money-roundtrip@example.test']);
        $product  = (new ProductFactory())
            ->withVariantAttributes(['item_price' => Helper::toCent(12.34)])
            ->create();
        $variant = $product->variants->first();

        [, $body] = $I->createOrder([
            'customer_id' => $customer->id,
            'order_items' => [
                [
                    'post_id'          => (int) $product->ID,
                    'object_id'        => (int) $variant->id,
                    'quantity'         => 1,
                    'unit_price'       => Helper::toCent(12.34),
                    'fulfillment_type' => 'digital',
                    'payment_type'     => 'onetime',
                    'other_info'       => ['payment_type' => 'onetime'],
                ],
            ],
        ]);

        $I->seeResponseCodeIs(200);
        $orderId = (int) ($body['order_id'] ?? 0);
        Assert::assertGreaterThan(0, $orderId, 'order_id must be returned on a successful create');

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT subtotal, total_amount FROM {$wpdb->prefix}fct_orders WHERE id = %d", $orderId),
            ARRAY_A
        );
        Assert::assertNotEmpty($row, 'created order row must exist');
        Assert::assertSame(1234, (int) $row['subtotal'], 'subtotal must persist as 1234 cents for a $12.34 line');
        Assert::assertSame(1234, (int) $row['total_amount'], 'total_amount must persist as 1234 cents for a $12.34 single-line order with no discount/shipping/tax');

        $itemRow = $wpdb->get_row(
            $wpdb->prepare("SELECT unit_price, subtotal, line_total FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d", $orderId),
            ARRAY_A
        );
        Assert::assertNotEmpty($itemRow, 'created order_items row must exist');
        Assert::assertSame(1234, (int) $itemRow['unit_price'], 'item unit_price must persist as 1234 cents for $12.34');
        Assert::assertSame(1234, (int) $itemRow['subtotal'], 'item subtotal must persist as 1234 cents for one $12.34 line');
        Assert::assertSame(1234, (int) $itemRow['line_total'], 'item line_total must persist as 1234 cents for one $12.34 line with no discount');
    }

    /**
     * Spec: Cases/Functional/orders.md [26a]
     *   "Creating an order without a shipping total stores zero shipping rather than failing,
     *    since the field is optional."
     */
    public function adminCanCreateOrderWithoutSupplyingShippingTotal(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'no-shipping@example.test']);
        $product  = (new ProductFactory())
            ->withVariantAttributes(['item_price' => Helper::toCent(10.00)])
            ->create();
        $variant = $product->variants->first();

        // shipping_total deliberately absent: OrderRequest marks it optional, but
        // Request::getSafe() null-fills every omitted sanitize-map key, so the
        // resource must normalize the injected null before it reaches the
        // NOT NULL cents column.
        [, $body] = $I->createOrder([
            'customer_id' => $customer->id,
            'order_items' => [
                [
                    'post_id'          => (int) $product->ID,
                    'object_id'        => (int) $variant->id,
                    'quantity'         => 1,
                    'unit_price'       => Helper::toCent(10.00),
                    'fulfillment_type' => 'digital',
                    'payment_type'     => 'onetime',
                    'other_info'       => ['payment_type' => 'onetime'],
                ],
            ],
        ]);

        $I->seeResponseCodeIs(200);
        $orderId = (int) ($body['order_id'] ?? 0);
        Assert::assertGreaterThan(0, $orderId, 'omitting the optional shipping_total must not fail order creation. Body: ' . wp_json_encode($body));

        global $wpdb;
        $shippingTotal = $wpdb->get_var($wpdb->prepare(
            "SELECT shipping_total FROM {$wpdb->prefix}fct_orders WHERE id = %d",
            $orderId
        ));
        Assert::assertNotNull($shippingTotal, 'fct_orders.shipping_total must never persist as NULL — the column is NOT NULL and an explicit NULL bypasses its default');
        Assert::assertSame(0, (int) $shippingTotal, 'an omitted shipping_total must persist as 0 cents');
    }

    /**
     * Spec: Cases/Functional/orders.md [26b]
     *   "Creating an order with a shipping total stores that exact amount, and a fractional
     *    amount is rounded to the nearest cent rather than truncated down."
     */
    public function adminSeesSuppliedShippingTotalStoredToTheExactCent(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())
            ->withVariantAttributes(['item_price' => Helper::toCent(10.00)])
            ->create();
        $variant = $product->variants->first();

        $lineItem = [
            'post_id'          => (int) $product->ID,
            'object_id'        => (int) $variant->id,
            'quantity'         => 1,
            'unit_price'       => Helper::toCent(10.00),
            'fulfillment_type' => 'digital',
            'payment_type'     => 'onetime',
            'other_info'       => ['payment_type' => 'onetime'],
        ];

        // EXPLICIT zero is valid free shipping under the min:0 rule and must be
        // accepted — this pins the boundary between min:0 rejection of negatives
        // and acceptance of zero (a min:1-style off-by-one fails here).
        $zeroCustomer = (new CustomerFactory())->create(['email' => 'shipping-zero@example.test']);
        [, $zeroBody] = $I->createOrder([
            'customer_id'    => $zeroCustomer->id,
            'shipping_total' => 0,
            'order_items'    => [$lineItem],
        ]);
        $I->seeResponseCodeIs(200);
        $zeroOrderId = (int) ($zeroBody['order_id'] ?? 0);
        Assert::assertGreaterThan(0, $zeroOrderId, 'an explicit zero shipping_total must not fail order creation. Body: ' . wp_json_encode($zeroBody));

        global $wpdb;
        Assert::assertSame(
            0,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT shipping_total FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                $zeroOrderId
            )),
            'an explicit zero shipping_total must persist as 0'
        );

        // A whole-cent integer must survive untouched.
        $exactCustomer = (new CustomerFactory())->create(['email' => 'shipping-exact@example.test']);
        [, $exactBody] = $I->createOrder([
            'customer_id'    => $exactCustomer->id,
            'shipping_total' => 1999,
            'order_items'    => [$lineItem],
        ]);
        $I->seeResponseCodeIs(200);
        $exactOrderId = (int) ($exactBody['order_id'] ?? 0);
        Assert::assertGreaterThan(0, $exactOrderId, 'supplying shipping_total must not fail order creation. Body: ' . wp_json_encode($exactBody));

        Assert::assertSame(
            1999,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT shipping_total FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                $exactOrderId
            )),
            'a supplied shipping_total of 1999 cents must persist as exactly 1999 — a normalization that writes 0 loses the whole charge'
        );

        // OrderRequest sanitizes shipping_total through the Helper::roundCent()
        // closure and accepts any numeric, so a client computing
        // cents in JS sends artifacts like
        // 19.99 * 100 === 1998.9999999999998. Rounding to the nearest cent is
        // the contract; truncating undercharges by a cent.
        $fractionalCustomer = (new CustomerFactory())->create(['email' => 'shipping-fractional@example.test']);
        [, $fractionalBody] = $I->createOrder([
            'customer_id'    => $fractionalCustomer->id,
            'shipping_total' => 1998.9999999999998,
            'order_items'    => [$lineItem],
        ]);
        $I->seeResponseCodeIs(200);
        $fractionalOrderId = (int) ($fractionalBody['order_id'] ?? 0);
        Assert::assertGreaterThan(0, $fractionalOrderId, 'a fractional shipping_total must not fail order creation. Body: ' . wp_json_encode($fractionalBody));

        Assert::assertSame(
            1999,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT shipping_total FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                $fractionalOrderId
            )),
            'a floating-point cents artifact (1998.9999999999998) must round to 1999, not truncate to 1998 — truncation silently undercharges the customer by a cent'
        );

        // The case above sits BELOW an integer, where round() and ceil() agree, so on
        // its own it cannot tell a request-layer ceil apart from correct rounding.
        // 0.07 * 100 evaluates to 7.000000000000001 in JS — just as ordinary — and a
        // ceil would overcharge that 7-cent fee to 8. Neither the direct-resource
        // contract test nor the Helper unit tests can see a ceil applied only in
        // OrderRequest::sanitize(), so the REST boundary needs its own above-integer case.
        $aboveIntegerCustomer = (new CustomerFactory())->create(['email' => 'shipping-above-int@example.test']);
        [, $aboveBody] = $I->createOrder([
            'customer_id'    => $aboveIntegerCustomer->id,
            'shipping_total' => 7.000000000000001,
            'order_items'    => [$lineItem],
        ]);
        $I->seeResponseCodeIs(200);
        $aboveOrderId = (int) ($aboveBody['order_id'] ?? 0);
        Assert::assertGreaterThan(0, $aboveOrderId, 'an above-integer fractional shipping_total must not fail order creation. Body: ' . wp_json_encode($aboveBody));

        Assert::assertSame(
            7,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT shipping_total FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                $aboveOrderId
            )),
            'a floating-point cents artifact just ABOVE an integer (7.000000000000001, i.e. 0.07 * 100 in JS) must round DOWN to 7 — a ceiling at the request boundary would overcharge by a cent'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [26c]
     *   "Creating an order with an out-of-range (e.g. 1e19) or negative shipping
     *    total is rejected with a validation error rather than stored as a
     *    wrapped or negative cents value."
     */
    public function adminIsRejectedWhenShippingTotalIsOutOfRangeOrNegative(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())
            ->withVariantAttributes(['item_price' => Helper::toCent(10.00)])
            ->create();
        $variant = $product->variants->first();

        $lineItem = [
            'post_id'          => (int) $product->ID,
            'object_id'        => (int) $variant->id,
            'quantity'         => 1,
            'unit_price'       => Helper::toCent(10.00),
            'fulfillment_type' => 'digital',
            'payment_type'     => 'onetime',
            'other_info'       => ['payment_type' => 'onetime'],
        ];

        global $wpdb;
        $ordersBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders");

        // 1e19 passes the `numeric` rule and is_numeric(), but (int) casting the
        // float wraps it to -8446744073709551616 — a VALID signed BIGINT — so
        // without the max rule the order would be created with corrupt shipping.
        // Negative shipping is the same class: a charge column must not go below 0.
        $customer = (new CustomerFactory())->create(['email' => 'shipping-out-of-range@example.test']);
        foreach (['1e19' => 1e19, '-500' => -500] as $label => $bad) {
            [$status, $body] = $I->createOrder([
                'customer_id'    => $customer->id,
                'shipping_total' => $bad,
                'order_items'    => [$lineItem],
            ]);
            Assert::assertSame(
                422,
                $status,
                'shipping_total ' . $label . ' must be rejected by validation, not persisted. Body: ' . wp_json_encode($body)
            );
        }

        $ordersAfter = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders");
        Assert::assertSame(
            $ordersBefore,
            $ordersAfter,
            'a rejected out-of-range/negative shipping_total must not create an order row'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [25]
     *   "Admin sees a created order's creation timestamp recorded in UTC regardless of the site's timezone."
     */
    public function adminSeesCreatedOrderCreationTimestampRecordedInUtc(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'utc-timestamp@example.test']);
        $product  = (new ProductFactory())
            ->withVariantAttributes(['item_price' => Helper::toCent(10)])
            ->create();
        $variant = $product->variants->first();

        // Drive the WP site to a strongly non-UTC timezone so a local-time
        // implementation would diverge from gmdate() and the test would
        // catch it. America/Los_Angeles is UTC-7 or UTC-8 depending on DST.
        $previousTz       = get_option('timezone_string');
        $previousGmtOffset = get_option('gmt_offset');
        update_option('timezone_string', 'America/Los_Angeles');
        update_option('gmt_offset', '');

        try {
            $beforeUtc   = gmdate('Y-m-d H:i:s');
            $beforeLocal = wp_date('Y-m-d H:i:s');

            [, $body] = $I->createOrder([
                'customer_id' => $customer->id,
                'order_items' => [
                    [
                        'post_id'          => (int) $product->ID,
                        'object_id'        => (int) $variant->id,
                        'quantity'         => 1,
                        'unit_price'       => Helper::toCent(10),
                        'fulfillment_type' => 'digital',
                        'payment_type'     => 'onetime',
                        'other_info'       => ['payment_type' => 'onetime'],
                    ],
                ],
            ]);

            $afterUtc = gmdate('Y-m-d H:i:s', time() + 5);

            $I->seeResponseCodeIs(200);
            $orderId = (int) ($body['order_id'] ?? 0);
            Assert::assertGreaterThan(0, $orderId, 'order_id must be returned on a successful create');

            // Sanity: confirm the chosen WP timezone really diverges from UTC by at
            // least an hour, otherwise the rest of the assertions would be vacuous.
            Assert::assertNotSame(
                substr($beforeUtc, 0, 13),
                substr($beforeLocal, 0, 13),
                'precondition: site timezone must visibly differ from UTC at the hour boundary so a local-time bug would diverge from a GMT timestamp (utc=' . $beforeUtc . ', local=' . $beforeLocal . ')'
            );

            global $wpdb;
            $createdAt = (string) $wpdb->get_var(
                $wpdb->prepare("SELECT created_at FROM {$wpdb->prefix}fct_orders WHERE id = %d", $orderId)
            );
            Assert::assertNotEmpty($createdAt, 'created_at must be persisted on the new order');
            Assert::assertGreaterThanOrEqual(
                $beforeUtc,
                $createdAt,
                'created_at (' . $createdAt . ') must be at or after the GMT timestamp captured immediately before the request (' . $beforeUtc . '), proving GMT not local-time. local was: ' . $beforeLocal
            );
            Assert::assertLessThanOrEqual(
                $afterUtc,
                $createdAt,
                'created_at (' . $createdAt . ') must be at or before the GMT timestamp captured +5s after the request (' . $afterUtc . ')'
            );
        } finally {
            update_option('timezone_string', $previousTz);
            update_option('gmt_offset', $previousGmtOffset);
        }
    }

    /**
     * Spec: Cases/Functional/orders.md [26]
     *   "Admin sees an \"order created\" notification triggered when a new order is created."
     */
    public function adminSeesOrderCreatedNotificationTriggeredOnNewOrder(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'order-created-event@example.test']);
        $product  = (new ProductFactory())
            ->withVariantAttributes(['item_price' => Helper::toCent(15)])
            ->create();
        $variant = $product->variants->first();

        $captured = [];
        $listener = function ($payload) use (&$captured) {
            $captured[] = $payload;
        };
        add_action('fluent_cart/order_created', $listener, 10, 1);

        try {
            [, $body] = $I->createOrder([
                'customer_id' => $customer->id,
                'order_items' => [
                    [
                        'post_id'          => (int) $product->ID,
                        'object_id'        => (int) $variant->id,
                        'quantity'         => 1,
                        'unit_price'       => Helper::toCent(15),
                        'fulfillment_type' => 'digital',
                        'payment_type'     => 'onetime',
                        'other_info'       => ['payment_type' => 'onetime'],
                    ],
                ],
            ]);
        } finally {
            remove_action('fluent_cart/order_created', $listener, 10);
        }

        $I->seeResponseCodeIs(200);
        $orderId = (int) ($body['order_id'] ?? 0);
        Assert::assertGreaterThan(0, $orderId, 'order_id must be returned on a successful create');

        Assert::assertCount(
            1,
            $captured,
            'fluent_cart/order_created action must fire exactly once per create-order request. Captured: ' . count($captured)
        );

        $payload = $captured[0];
        Assert::assertIsArray($payload, 'event payload must be an array');
        Assert::assertArrayHasKey('order', $payload, 'event payload must include the order key');
        Assert::assertSame(
            $orderId,
            (int) ($payload['order']->id ?? 0),
            'event payload order id must match the just-created order id'
        );
    }
}

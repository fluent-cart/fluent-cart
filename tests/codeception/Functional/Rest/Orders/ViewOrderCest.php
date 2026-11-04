<?php

namespace Tests\Functional\Rest\Orders;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderAddressFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/orders.md → "Viewing a single order" section, items 14-17.
 */
class ViewOrderCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/orders.md [14]
     *   "Admin can open a specific order and see the order along with its items, customer, billing and shipping addresses, and transactions."
     */
    public function adminCanOpenOrderAndSeeItemsCustomerAddressesAndTransactions(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create([
            'email'      => 'view-order-with-relations@example.test',
            'first_name' => 'View',
            'last_name'  => 'Tester',
        ]);

        $order = (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'invoice_no'  => 'INV-VIEW-RELATIONS',
        ]);

        $item = (new OrderItemFactory())->create((int) $order->id, [
            'title'      => 'Viewable Widget',
            'post_title' => 'Viewable Widget',
        ]);

        $transaction = (new OrderTransactionFactory())->create((int) $order->id, [
            'transaction_hash' => 'view-relations-txn-' . uniqid('', true),
        ]);

        $billing = (new OrderAddressFactory())->create((int) $order->id, [
            'type'      => 'billing',
            'name'      => 'Bill Receiver',
            'address_1' => '101 Billing Lane',
            'city'      => 'Billtown',
            'state'     => 'BL',
            'postcode'  => '11111',
            'country'   => 'US',
        ]);
        $shipping = (new OrderAddressFactory())->create((int) $order->id, [
            'type'      => 'shipping',
            'name'      => 'Ship Receiver',
            'address_1' => '202 Shipping Way',
            'city'      => 'Shiptown',
            'state'     => 'SP',
            'postcode'  => '22222',
            'country'   => 'US',
        ]);

        [, $body] = $I->get('/fluent-cart/v2/orders/' . (int) $order->id);

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('order', (array) $body, 'view response must contain top-level order key');
        $payload = (array) $body['order'];

        Assert::assertSame((int) $order->id, (int) ($payload['id'] ?? 0), 'view payload id must match the requested order id');
        Assert::assertSame((int) $customer->id, (int) ($payload['customer_id'] ?? 0), 'view payload customer_id must match the seeded customer');

        Assert::assertArrayHasKey('customer', $payload, 'view payload must include the customer relation');
        Assert::assertSame((int) $customer->id, (int) ($payload['customer']['id'] ?? 0), 'embedded customer.id must match seeded customer');

        Assert::assertArrayHasKey('order_items', $payload, 'view payload must include the order_items relation');
        $itemIds = array_map('intval', array_column((array) $payload['order_items'], 'id'));
        Assert::assertContains((int) $item->id, $itemIds, 'view payload order_items must include the seeded item');

        Assert::assertArrayHasKey('transactions', $payload, 'view payload must include the transactions relation');
        $txnRows = (array) $payload['transactions'];
        $txnIds = array_map('intval', array_column($txnRows, 'id'));
        Assert::assertContains((int) $transaction->id, $txnIds, 'view payload transactions must include the seeded transaction id');
        $orderIdsOnTxns = array_unique(array_map('intval', array_column($txnRows, 'order_id')));
        Assert::assertSame([(int) $order->id], $orderIdsOnTxns, 'every transaction in the view payload must belong to the viewed order');

        Assert::assertArrayHasKey('billing_address', $payload, 'view payload must include billing_address');
        $billingRow = (array) $payload['billing_address'];
        Assert::assertSame((int) $billing->id, (int) ($billingRow['id'] ?? 0), 'view billing_address.id must match the seeded billing row');
        Assert::assertSame((int) $order->id, (int) ($billingRow['order_id'] ?? 0), 'view billing_address.order_id must match the viewed order');
        Assert::assertSame('billing', (string) ($billingRow['type'] ?? ''), 'view billing_address.type must be billing');
        Assert::assertSame('101 Billing Lane', (string) ($billingRow['address_1'] ?? ''), 'view billing_address.address_1 must echo seeded value');

        Assert::assertArrayHasKey('shipping_address', $payload, 'view payload must include shipping_address');
        $shippingRow = (array) $payload['shipping_address'];
        Assert::assertSame((int) $shipping->id, (int) ($shippingRow['id'] ?? 0), 'view shipping_address.id must match the seeded shipping row');
        Assert::assertSame((int) $order->id, (int) ($shippingRow['order_id'] ?? 0), 'view shipping_address.order_id must match the viewed order');
        Assert::assertSame('shipping', (string) ($shippingRow['type'] ?? ''), 'view shipping_address.type must be shipping');
        Assert::assertSame('202 Shipping Way', (string) ($shippingRow['address_1'] ?? ''), 'view shipping_address.address_1 must echo seeded value');
    }

    /**
     * Spec: Cases/Functional/orders.md [15]
     *   "Admin sees an order's receipt link included in the single-order view."
     */
    public function adminSeesOrderReceiptLinkInSingleOrderView(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'receipt-link@example.test']);
        $order = (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'uuid'        => 'receipt-uuid-' . uniqid('', true),
            'invoice_no'  => 'INV-RECEIPT-LINK',
        ]);

        [, $body] = $I->get('/fluent-cart/v2/orders/' . (int) $order->id);

        $I->seeResponseCodeIs(200);
        $payload = (array) ($body['order'] ?? []);
        Assert::assertArrayHasKey('receipt_url', $payload, 'view payload must include receipt_url for the order');
        Assert::assertNotEmpty($payload['receipt_url'], 'receipt_url must not be empty');
        Assert::assertStringContainsString(
            (string) $order->uuid,
            (string) $payload['receipt_url'],
            'receipt_url must reference the order uuid so the public receipt page can resolve the order'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [16]
     *   "Admin receives a clear \"not found\" error when opening an order that does not exist."
     */
    public function adminReceivesNotFoundErrorWhenOpeningNonexistentOrder(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->get('/fluent-cart/v2/orders/999999');

        $I->seeResponseCodeIs(404);
        Assert::assertSame(
            'fluent_cart_entity_not_found',
            (string) ($body['code'] ?? ''),
            'missing-order error must surface the dedicated fluent_cart_entity_not_found code'
        );
        Assert::assertArrayHasKey('data', (array) $body, 'not-found error body must include the data envelope with a human-readable message');
        Assert::assertNotEmpty(
            (string) ($body['data']['message'] ?? ''),
            'not-found error must include a human-readable message string'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [17]
     *   "Customer cannot view another customer's order through the admin order management area."
     */
    public function customerCannotViewAnotherCustomersOrderThroughAdminArea(FunctionalTester $I): void
    {
        $owner = (new CustomerFactory())->create(['email' => 'owner-of-order@example.test']);
        $order = (new OrderFactory())->create([
            'customer_id' => $owner->id,
            'invoice_no'  => 'INV-CROSS-CUSTOMER',
        ]);

        // Log in as a WP subscriber, then link that subscriber to a *different*
        // FluentCart customer record (not the order owner). This proves the admin
        // area refuses cross-customer access, not just unauthenticated access.
        $loggedInUserId = $I->loginAs('customer');
        $accessor = (new CustomerFactory())->create([
            'email'   => 'other-customer-attempting-cross-access@example.test',
            'user_id' => $loggedInUserId,
        ]);
        Assert::assertNotSame(
            (int) $owner->id,
            (int) $accessor->id,
            'precondition: the logged-in customer must be a different fct_customers row than the order owner'
        );

        [, $body] = $I->get('/fluent-cart/v2/orders/' . (int) $order->id);

        $I->seeResponseCodeIs(403);
        Assert::assertSame(
            'rest_forbidden',
            (string) ($body['code'] ?? ''),
            'a non-admin customer hitting the admin order endpoint for another customer must surface rest_forbidden, not the order payload'
        );
        Assert::assertArrayNotHasKey('order', (array) $body, 'admin-area view response for a non-admin customer must not leak the order payload');
    }
}

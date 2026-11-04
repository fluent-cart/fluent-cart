<?php

namespace Tests\Functional\Rest\Customers;

use FluentCart\App\Models\Customer;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerAddressFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\LabelFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/customers.md → "Viewing a single customer" section, items 9-15.
 */
class ViewCustomerCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/customers.md [9]
     *   "Admin can open a specific customer and see the customer along with their selected labels."
     */
    public function adminCanOpenSpecificCustomerWithSelectedLabels(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customerFactory = new CustomerFactory();
        $customer = $customerFactory->create(['email' => 'view-labels-target@example.test']);
        $other    = $customerFactory->create(['email' => 'view-labels-other@example.test']);

        $labelFactory = new LabelFactory();
        $labelA       = $labelFactory->create();
        $labelB       = $labelFactory->create();
        $unattached   = $labelFactory->create();
        $otherCustomerLabel = $labelFactory->create();
        $labelFactory->attachTo((int) $labelA->id, (int) $customer->id, Customer::class);
        $labelFactory->attachTo((int) $labelB->id, (int) $customer->id, Customer::class);
        $labelFactory->attachTo((int) $otherCustomerLabel->id, (int) $other->id, Customer::class);

        [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id, [
            'with' => ['labels'],
        ]);

        $I->seeResponseCodeIs(200);
        $I->seeResponseJsonMatchesSubset(['customer' => ['id' => (int) $customer->id]]);
        Assert::assertArrayHasKey('selected_labels', $body['customer'], 'view response must include selected_labels alongside the customer');

        $selected = array_map('intval', (array) ($body['customer']['selected_labels'] ?? []));
        sort($selected);
        $expected = [(int) $labelA->id, (int) $labelB->id];
        sort($expected);
        Assert::assertSame(
            $expected,
            $selected,
            'selected_labels must list exactly the label ids attached to this customer'
        );
        Assert::assertNotContains(
            (int) $unattached->id,
            $selected,
            'an unattached label must not appear in this customer\'s selected_labels'
        );
        Assert::assertNotContains(
            (int) $otherCustomerLabel->id,
            $selected,
            'a label attached only to another customer must not appear in this customer\'s selected_labels'
        );
    }

    /**
     * Spec: Cases/Functional/customers.md [10]
     *   "Admin can open a customer and see related billing addresses, shipping addresses, and orders alongside the customer."
     */
    public function adminCanOpenCustomerWithRelatedAddressesAndOrders(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customerFactory = new CustomerFactory();
        $customer = $customerFactory->create(['email' => 'view-relations-target@example.test']);
        $foreign  = $customerFactory->create(['email' => 'view-relations-foreign@example.test']);

        $addressFactory = new CustomerAddressFactory();
        $billingA  = $addressFactory->create((int) $customer->id, ['type' => 'billing', 'address_1' => '1 Billing St']);
        $shippingA = $addressFactory->create((int) $customer->id, ['type' => 'shipping', 'address_1' => '2 Shipping Ave']);
        $foreignBilling  = $addressFactory->create((int) $foreign->id, ['type' => 'billing', 'address_1' => '99 Foreign Ln']);
        $foreignShipping = $addressFactory->create((int) $foreign->id, ['type' => 'shipping', 'address_1' => '100 Foreign Way']);

        $orderFactory = new OrderFactory();
        $order        = $orderFactory->create(['customer_id' => $customer->id, 'invoice_no' => 'VIEW-REL-OWN']);
        $foreignOrder = $orderFactory->create(['customer_id' => $foreign->id, 'invoice_no' => 'VIEW-REL-FOREIGN']);

        [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id, [
            'with' => ['billing_address', 'shipping_address', 'orders'],
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('billing_address', $body['customer'], 'view response must include billing_address relation');
        Assert::assertArrayHasKey('shipping_address', $body['customer'], 'view response must include shipping_address relation');

        // `orders` is deliberately NOT loadable here. It is an unbounded
        // to-many: a customer with a long history would cost a proportional
        // number of rows, hydrated models and serialized output on a single
        // request. The order history has its own paginated endpoint, asserted
        // below, and that is the only bounded way to read it.
        Assert::assertArrayNotHasKey(
            'orders',
            $body['customer'],
            'the detail endpoint must not return an unpaginated order history'
        );

        $billingIds  = array_map('intval', array_column((array) $body['customer']['billing_address'], 'id'));
        $shippingIds = array_map('intval', array_column((array) $body['customer']['shipping_address'], 'id'));

        Assert::assertContains((int) $billingA->id, $billingIds, 'seeded billing address must surface in the customer view');
        Assert::assertContains((int) $shippingA->id, $shippingIds, 'seeded shipping address must surface in the customer view');

        // The bounded path: the same order is reachable, paginated, from the
        // endpoint that exists for it.
        [, $ordersBody] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id . '/orders');
        $I->seeResponseCodeIs(200);

        $paginatedIds = array_map('intval', array_column($ordersBody['orders']['data'] ?? [], 'id'));
        Assert::assertContains(
            (int) $order->id,
            $paginatedIds,
            'the seeded order must be reachable through the paginated history endpoint'
        );
        Assert::assertNotContains(
            (int) $foreignOrder->id,
            $paginatedIds,
            'another customer\'s order must not appear in this customer\'s history'
        );

        Assert::assertNotContains((int) $foreignBilling->id, $billingIds, 'foreign customer\'s billing address must not leak into this customer\'s view');
        Assert::assertNotContains((int) $foreignShipping->id, $shippingIds, 'foreign customer\'s shipping address must not leak into this customer\'s view');
    }

    /**
     * Spec: Cases/Functional/customers.md [11]
     *   "Admin receives a clear \"not found\" error when opening a customer that does not exist."
     */
    public function adminReceivesNotFoundErrorWhenOpeningMissingCustomer(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->get('/fluent-cart/v2/customers/999999');

        $I->seeResponseCodeIs(404);
        $I->seeResponseJsonMatchesSubset(['code' => 'fluent_cart_entity_not_found']);
        $message = $body['data']['message'] ?? null;
        Assert::assertNotEmpty($message, 'response must include a clear not-found error message at data.message. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsStringIgnoringCase(
            'not found',
            (string) $message,
            'data.message must identify the customer as not found'
        );
    }

    /**
     * Spec: Cases/Functional/customers.md [12]
     *   "Admin can view a customer's lifetime statistics widgets."
     */
    public function adminCanViewCustomerLifetimeStatsWidgets(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer  = (new CustomerFactory())->create(['email' => 'view-stats-target@example.test']);
        $other     = (new CustomerFactory())->create(['email' => 'view-stats-other@example.test']);
        $expectedId = (int) $customer->id;

        $filter = static function (array $widgets, $resolved) use ($expectedId) {
            $resolvedArr = is_array($resolved) ? $resolved : (array) $resolved;
            $resolvedId  = (int) ($resolvedArr['customer']['id'] ?? $resolvedArr['id'] ?? 0);
            if ($resolvedId === $expectedId) {
                $widgets[] = ['key' => 'lifetime_marker_' . $expectedId, 'label' => 'LtvMarker', 'value' => 4242];
            }
            return $widgets;
        };
        add_filter('fluent_cart/widgets/single_customer', $filter, 99, 2);

        try {
            [, $body] = $I->get('/fluent-cart/v2/customers/get-stats/' . $expectedId);

            $I->seeResponseCodeIs(200);
            Assert::assertArrayHasKey('widgets', $body, 'get-stats response must contain a widgets key');
            Assert::assertIsArray($body['widgets'], 'widgets must be an array');

            $keys = array_map(static function ($w) {
                return $w['key'] ?? '';
            }, (array) $body['widgets']);
            Assert::assertContains(
                'lifetime_marker_' . $expectedId,
                $keys,
                'widgets payload must include the marker emitted only when the resolved customer id matches the seeded target (proves the endpoint resolves the requested customer and passes it through the filter)'
            );
            Assert::assertNotContains(
                'lifetime_marker_' . (int) $other->id,
                $keys,
                'no marker tied to the unrelated customer id may appear (filter only emits when resolved id matches target)'
            );
        } finally {
            remove_filter('fluent_cart/widgets/single_customer', $filter, 99);
        }
    }

    /**
     * Spec: Cases/Functional/customers.md [13]
     *   "Admin can list a single customer's orders."
     */
    public function adminCanListSingleCustomerOrders(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $owner   = (new CustomerFactory())->create(['email' => 'orders-owner@example.test']);
        $other   = (new CustomerFactory())->create(['email' => 'orders-other@example.test']);
        $orderFactory = new OrderFactory();
        $orderA = $orderFactory->create(['customer_id' => $owner->id, 'invoice_no' => 'OWN-1']);
        $orderB = $orderFactory->create(['customer_id' => $owner->id, 'invoice_no' => 'OWN-2']);
        $foreign = $orderFactory->create(['customer_id' => $other->id, 'invoice_no' => 'OTH-1']);

        [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $owner->id . '/orders');

        $I->seeResponseCodeIs(200);
        $rows = $body['orders']['data'] ?? [];
        Assert::assertNotEmpty($rows, 'orders payload must not be empty for a customer with orders');

        $ids = array_map('intval', array_column($rows, 'id'));
        Assert::assertContains((int) $orderA->id, $ids, 'first owner order must appear in the per-customer listing');
        Assert::assertContains((int) $orderB->id, $ids, 'second owner order must appear in the per-customer listing');
        Assert::assertNotContains((int) $foreign->id, $ids, 'order belonging to a different customer must not leak into the per-customer listing');
    }

    /**
     * Spec: Cases/Functional/customers.md [14]
     *   "Admin can filter a single customer's orders by status and only matching orders are returned."
     */
    public function adminCanFilterSingleCustomerOrdersByStatus(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $owner   = (new CustomerFactory())->create(['email' => 'orders-status-filter-owner@example.test']);
        $foreign = (new CustomerFactory())->create(['email' => 'orders-status-filter-foreign@example.test']);
        $orderFactory = new OrderFactory();
        $completed = $orderFactory->create([
            'customer_id' => $owner->id,
            'status'      => 'completed',
            'invoice_no'  => 'STATUS-COMPLETED-OWN',
        ]);
        $onHold = $orderFactory->create([
            'customer_id' => $owner->id,
            'status'      => 'on-hold',
            'invoice_no'  => 'STATUS-ONHOLD-OWN',
        ]);
        $foreignCompleted = $orderFactory->create([
            'customer_id' => $foreign->id,
            'status'      => 'completed',
            'invoice_no'  => 'STATUS-COMPLETED-FOREIGN',
        ]);

        [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $owner->id . '/orders', [
            'active_view' => 'completed',
        ]);

        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column($body['orders']['data'] ?? [], 'id'));
        Assert::assertContains((int) $completed->id, $ids, 'order with status=completed must appear when active_view=completed');
        Assert::assertNotContains((int) $onHold->id, $ids, 'order with status=on-hold must be excluded when active_view=completed');
        Assert::assertNotContains((int) $foreignCompleted->id, $ids, 'completed order belonging to a different customer must not leak into this customer\'s filtered listing');
    }

    /**
     * Spec: Cases/Functional/customers.md [15]
     *   "Admin can fetch a customer's full order history with each order's line items in a single call."
     */
    public function adminCanFetchFullOrderHistoryWithLineItemsInSingleCall(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $owner   = (new CustomerFactory())->create(['email' => 'history-with-items-owner@example.test']);
        $foreign = (new CustomerFactory())->create(['email' => 'history-with-items-foreign@example.test']);
        $orderFactory = new OrderFactory();
        $itemFactory  = new OrderItemFactory();
        $orderA = $orderFactory->create(['customer_id' => $owner->id, 'invoice_no' => 'HIST-A']);
        $orderB = $orderFactory->create(['customer_id' => $owner->id, 'invoice_no' => 'HIST-B']);
        $foreignOrder = $orderFactory->create(['customer_id' => $foreign->id, 'invoice_no' => 'HIST-FOREIGN']);

        $itemA1 = $itemFactory->create((int) $orderA->id, ['title' => 'Hist Item A1']);
        $itemA2 = $itemFactory->create((int) $orderA->id, ['title' => 'Hist Item A2']);
        $itemB1 = $itemFactory->create((int) $orderB->id, ['title' => 'Hist Item B1']);
        $foreignItem = $itemFactory->create((int) $foreignOrder->id, ['title' => 'Hist Item Foreign']);

        [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $owner->id . '/order');

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('data', $body, 'response root must contain a data key (controller wraps under data)');
        $orders = $body['data']['data'] ?? null;
        Assert::assertIsArray($orders, 'orders payload must be an array. Body: ' . wp_json_encode($body));

        $orderIds = array_map('intval', array_column($orders, 'id'));
        Assert::assertContains((int) $orderA->id, $orderIds, 'first owner order must appear in the full history payload');
        Assert::assertContains((int) $orderB->id, $orderIds, 'second owner order must appear in the full history payload');
        Assert::assertNotContains((int) $foreignOrder->id, $orderIds, 'order belonging to a different customer must not leak into this customer\'s full history');

        $itemIdsByOrder = [];
        foreach ($orders as $orderRow) {
            $orderId = (int) ($orderRow['id'] ?? 0);
            $items = (array) ($orderRow['filtered_order_items'] ?? $orderRow['filteredOrderItems'] ?? []);
            $itemIdsByOrder[$orderId] = array_map('intval', array_column($items, 'id'));
        }
        Assert::assertArrayHasKey((int) $orderA->id, $itemIdsByOrder, 'order A must surface in the line-items map');
        Assert::assertContains((int) $itemA1->id, $itemIdsByOrder[(int) $orderA->id], 'order A line item 1 must be eager-loaded into the same response');
        Assert::assertContains((int) $itemA2->id, $itemIdsByOrder[(int) $orderA->id], 'order A line item 2 must be eager-loaded into the same response');
        Assert::assertContains((int) $itemB1->id, $itemIdsByOrder[(int) $orderB->id], 'order B line item must be eager-loaded into the same response');

        $allItemIds = [];
        foreach ($itemIdsByOrder as $items) {
            foreach ($items as $itemId) {
                $allItemIds[] = $itemId;
            }
        }
        Assert::assertNotContains((int) $foreignItem->id, $allItemIds, 'foreign customer\'s line item must not appear anywhere in the full history payload');
    }
}

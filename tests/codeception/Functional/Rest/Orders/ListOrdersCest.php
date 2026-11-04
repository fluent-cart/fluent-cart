<?php

namespace Tests\Functional\Rest\Orders;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/orders.md → "Listing and searching orders" section, items 1-13.
 */
class ListOrdersCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/orders.md [2]
     *   "Admin can paginate the orders list."
     */
    public function adminCanPaginateOrdersList(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'paginate-orders@example.test']);

        $createdIds = [];
        for ($i = 0; $i < 4; $i++) {
            $createdIds[] = (int) (new OrderFactory())->create([
                'customer_id' => $customer->id,
                'invoice_no'  => 'INV-PAGINATE-' . $i,
            ])->id;
        }
        rsort($createdIds);
        $expectedPage1 = array_slice($createdIds, 0, 2);
        $expectedPage2 = array_slice($createdIds, 2, 2);

        [, $page1] = $I->get('/fluent-cart/v2/orders', ['per_page' => 2, 'page' => 1]);
        $I->seeResponseCodeIs(200);
        Assert::assertSame(1, (int) ($page1['orders']['current_page'] ?? 0), 'page 1 response must report current_page=1');
        Assert::assertCount(2, $page1['orders']['data'] ?? [], 'page 1 must contain exactly per_page items');

        [, $page2] = $I->get('/fluent-cart/v2/orders', ['per_page' => 2, 'page' => 2]);
        Assert::assertSame(2, (int) ($page2['orders']['current_page'] ?? 0), 'page 2 response must report current_page=2');
        Assert::assertCount(2, $page2['orders']['data'] ?? [], 'page 2 must contain exactly per_page items');

        $page1Ids = array_map('intval', array_column($page1['orders']['data'] ?? [], 'id'));
        $page2Ids = array_map('intval', array_column($page2['orders']['data'] ?? [], 'id'));

        foreach ($expectedPage1 as $id) {
            Assert::assertContains($id, $page1Ids, "page 1 must contain seeded order id {$id}");
        }
        foreach ($expectedPage2 as $id) {
            Assert::assertContains($id, $page2Ids, "page 2 must contain seeded order id {$id}");
        }
        Assert::assertEmpty(
            array_intersect($page1Ids, $page2Ids),
            'page 1 and page 2 must contain disjoint orders'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [3]
     *   "Admin can request a specific page size when listing orders."
     */
    public function adminCanRequestSpecificPageSizeWhenListingOrders(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'page-size-orders@example.test']);

        for ($i = 0; $i < 5; $i++) {
            (new OrderFactory())->create([
                'customer_id' => $customer->id,
                'invoice_no'  => 'INV-PAGESIZE-' . $i,
            ]);
        }

        [, $body] = $I->get('/fluent-cart/v2/orders', ['per_page' => 3]);

        $I->seeResponseCodeIs(200);
        Assert::assertSame(3, (int) ($body['orders']['per_page'] ?? 0), 'response must echo back the requested per_page value');
        Assert::assertCount(3, $body['orders']['data'] ?? [], 'data array must hold exactly per_page items');
    }

    /**
     * Spec: Cases/Functional/orders.md [4]
     *   "Admin receives an empty list, not an error, when requesting a page beyond the available results."
     */
    public function adminReceivesEmptyListWhenRequestingPageBeyondResults(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'page-beyond@example.test']);
        (new OrderFactory())->create(['customer_id' => $customer->id]);

        [, $body] = $I->get('/fluent-cart/v2/orders', ['per_page' => 5, 'page' => 999]);

        $I->seeResponseCodeIs(200);
        Assert::assertSame([], $body['orders']['data'] ?? null, 'page beyond available results must return an empty data array, not an error');
    }

    /**
     * Spec: Cases/Functional/orders.md [5]
     *   "Admin can search orders by part of an invoice number and only matching orders are returned."
     */
    public function adminCanSearchOrdersByPartialInvoiceNumber(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'invoice-search@example.test']);

        $hit = (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'invoice_no'  => 'INV-NEEDLE-789',
        ]);
        $miss = (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'invoice_no'  => 'INV-HAYSTACK-001',
        ]);

        [, $body] = $I->get('/fluent-cart/v2/orders', ['search' => 'NEEDLE']);

        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column($body['orders']['data'] ?? [], 'id'));
        Assert::assertContains((int) $hit->id, $ids, 'order whose invoice_no contains the search needle must appear in results');
        Assert::assertNotContains((int) $miss->id, $ids, 'order whose invoice_no does not contain the search needle must be excluded');
    }

    /**
     * Spec: Cases/Functional/orders.md [6]
     *   "Admin can search orders by part of a customer's email and only orders belonging to matching customers are returned."
     */
    public function adminCanSearchOrdersByPartialCustomerEmail(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $hitCustomer = (new CustomerFactory())->create(['email' => 'rare-zenith-email@example.test']);
        $missCustomer = (new CustomerFactory())->create(['email' => 'unrelated-mailbox@example.test']);

        $hit = (new OrderFactory())->create(['customer_id' => $hitCustomer->id, 'invoice_no' => 'INV-CE-HIT']);
        $miss = (new OrderFactory())->create(['customer_id' => $missCustomer->id, 'invoice_no' => 'INV-CE-MISS']);

        [, $body] = $I->get('/fluent-cart/v2/orders', ['search' => 'rare-zenith']);

        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column($body['orders']['data'] ?? [], 'id'));
        Assert::assertContains((int) $hit->id, $ids, 'order belonging to a customer whose email matches must appear');
        Assert::assertNotContains((int) $miss->id, $ids, 'order belonging to a customer whose email does not match must be excluded');
    }

    /**
     * Spec: Cases/Functional/orders.md [7]
     *   "Admin can search orders by part of a customer's name and only orders belonging to matching customers are returned."
     */
    public function adminCanSearchOrdersByPartialCustomerName(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $hitCustomer = (new CustomerFactory())->create([
            'email'      => 'name-search-orders-hit@example.test',
            'first_name' => 'Caspian',
            'last_name'  => 'Quill',
        ]);
        $missCustomer = (new CustomerFactory())->create([
            'email'      => 'name-search-orders-miss@example.test',
            'first_name' => 'Plain',
            'last_name'  => 'Smith',
        ]);

        $hit = (new OrderFactory())->create(['customer_id' => $hitCustomer->id, 'invoice_no' => 'INV-CN-HIT']);
        $miss = (new OrderFactory())->create(['customer_id' => $missCustomer->id, 'invoice_no' => 'INV-CN-MISS']);

        [, $body] = $I->get('/fluent-cart/v2/orders', ['search' => 'Caspian']);

        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column($body['orders']['data'] ?? [], 'id'));
        Assert::assertContains((int) $hit->id, $ids, 'order belonging to a customer whose first name matches must appear');
        Assert::assertNotContains((int) $miss->id, $ids, 'order belonging to a customer whose name does not match must be excluded');
    }

    /**
     * Spec: Cases/Functional/orders.md [8]
     *   "Admin can search orders by part of an order item's title and only orders containing matching items are returned."
     */
    public function adminCanSearchOrdersByPartialOrderItemTitle(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'item-title-search@example.test']);

        $hit = (new OrderFactory())->create(['customer_id' => $customer->id, 'invoice_no' => 'INV-IT-HIT']);
        $miss = (new OrderFactory())->create(['customer_id' => $customer->id, 'invoice_no' => 'INV-IT-MISS']);

        (new OrderItemFactory())->create((int) $hit->id, [
            'title'      => 'Galactic Widget Premium',
            'post_title' => 'Galactic Widget Premium',
        ]);
        (new OrderItemFactory())->create((int) $miss->id, [
            'title'      => 'Mundane Sprocket',
            'post_title' => 'Mundane Sprocket',
        ]);

        [, $body] = $I->get('/fluent-cart/v2/orders', ['search' => 'Galactic']);

        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column($body['orders']['data'] ?? [], 'id'));
        Assert::assertContains((int) $hit->id, $ids, 'order containing an item whose title matches must appear');
        Assert::assertNotContains((int) $miss->id, $ids, 'order without a matching item title must be excluded');
    }

    /**
     * Spec: Cases/Functional/orders.md [12]
     *   "Admin can filter orders by date range and only orders inside the range are returned."
     */
    public function adminCanFilterOrdersByDateRange(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'date-range-filter@example.test']);

        $inside = (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'created_at'  => '2024-03-15 12:00:00',
            'invoice_no'  => 'INV-DATE-IN',
        ]);
        $outside = (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'created_at'  => '2020-01-01 00:00:00',
            'invoice_no'  => 'INV-DATE-OUT',
        ]);

        $advanced = wp_json_encode([
            [
                [
                    'source'      => ['orders', 'created_at'],
                    'property'    => 'created_at',
                    'filter_type' => 'date',
                    'operator'    => 'after',
                    'value'       => '2024-01-01 00:00:00',
                ],
                [
                    'source'      => ['orders', 'created_at'],
                    'property'    => 'created_at',
                    'filter_type' => 'date',
                    'operator'    => 'before',
                    'value'       => '2024-12-31 23:59:59',
                ],
            ],
        ]);

        [, $body] = $I->get('/fluent-cart/v2/orders', [
            'filter_type'      => 'advanced',
            'advanced_filters' => $advanced,
        ]);

        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column($body['orders']['data'] ?? [], 'id'));
        Assert::assertContains((int) $inside->id, $ids, 'order whose created_at falls inside the requested range must appear');
        Assert::assertNotContains((int) $outside->id, $ids, 'order whose created_at falls outside the requested range must be excluded');
    }

    /**
     * Spec: Cases/Functional/orders.md [13]
     *   "Admin sees order search input that resembles an SQL injection attempt treated as plain text without causing a server error."
     */
    public function adminOrderSearchSqlInjectionAttemptIsTreatedAsPlainTextWithoutServerError(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'sql-inject-orders@example.test']);

        $a = (new OrderFactory())->create(['customer_id' => $customer->id, 'invoice_no' => 'INV-SQLI-A']);
        $b = (new OrderFactory())->create(['customer_id' => $customer->id, 'invoice_no' => 'INV-SQLI-B']);
        $c = (new OrderFactory())->create(['customer_id' => $customer->id, 'invoice_no' => 'INV-SQLI-C']);
        $totalSeeded = 3;

        $payload = "INV-SQLI-X' OR '1'='1";
        [, $body] = $I->get('/fluent-cart/v2/orders', ['search' => $payload]);

        $I->seeResponseCodeIs(200);
        $rows = $body['orders']['data'] ?? null;
        Assert::assertIsArray($rows, 'injection-shaped search must still produce a paginator data array');

        $ids = array_map('intval', array_column((array) $rows, 'id'));
        Assert::assertLessThan(
            $totalSeeded,
            count($ids),
            'plain-text search of an injection-shaped payload must not OR-return every seeded order; got ' . count($ids) . ' rows of ' . $totalSeeded
        );
        Assert::assertNotContains((int) $a->id, $ids, 'order A must not match an injection-shaped payload that does not literally appear in any column');
        Assert::assertNotContains((int) $b->id, $ids, 'order B must not match an injection-shaped payload that does not literally appear in any column');
        Assert::assertNotContains((int) $c->id, $ids, 'order C must not match an injection-shaped payload that does not literally appear in any column');

        $literalPayload = 'INV-LITERAL-MARKER';
        $marked = (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'invoice_no'  => $literalPayload,
        ]);

        [, $literalBody] = $I->get('/fluent-cart/v2/orders', ['search' => $literalPayload]);
        $I->seeResponseCodeIs(200);
        $literalIds = array_map('intval', array_column((array) ($literalBody['orders']['data'] ?? []), 'id'));
        Assert::assertContains(
            (int) $marked->id,
            $literalIds,
            'plain substring search must still locate an order whose invoice_no literally contains it (proves earlier empty result was due to plain-text comparison, not blanket sanitization-rejection)'
        );

        global $wpdb;
        $usersCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");
        Assert::assertGreaterThan(0, $usersCount, 'wp_users must still exist after injection probe');
        $ordersCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders");
        Assert::assertSame(4, $ordersCount, 'fct_orders must still hold the 4 seeded rows after the injection probe');
    }
}

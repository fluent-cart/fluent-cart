<?php

namespace Tests\Functional\Rest\Customers;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/customers.md → "Listing and searching customers" section, items 1-8.
 */
class ListCustomersCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/customers.md [1]
     *   "Admin can list customers, with the most recently created first by default."
     */
    public function adminCanListCustomersWithNewestFirstByDefault(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new CustomerFactory();
        $older = $factory->create([
            'email'      => 'newest-first-alpha@example.test',
            'created_at' => '2020-01-01 00:00:00',
        ]);
        $newer = $factory->create([
            'email'      => 'newest-first-beta@example.test',
            'created_at' => '2024-06-15 12:00:00',
        ]);

        [, $body] = $I->get('/fluent-cart/v2/customers');

        $I->seeResponseCodeIs(200);
        $rows = $body['customers']['data'] ?? [];
        Assert::assertNotEmpty($rows, 'customer list payload must not be empty');

        $ids = array_map(static function ($c) {
            return (int) ($c['id'] ?? 0);
        }, $rows);

        Assert::assertContains((int) $older->id, $ids, 'older seeded customer must appear in default listing');
        Assert::assertContains((int) $newer->id, $ids, 'newer seeded customer must appear in default listing');

        $olderIdx = array_search((int) $older->id, $ids, true);
        $newerIdx = array_search((int) $newer->id, $ids, true);

        Assert::assertLessThan(
            $olderIdx,
            $newerIdx,
            'most recently created customer must precede the older one in the default listing'
        );
    }

    /**
     * Spec: Cases/Functional/customers.md [2]
     *   "Admin can paginate the customer list."
     */
    public function adminCanPaginateCustomerList(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new CustomerFactory();
        $createdIds = [];
        for ($i = 0; $i < 4; $i++) {
            $createdIds[] = (int) $factory->create([
                'email' => 'paginate-' . $i . '@example.test',
            ])->id;
        }
        rsort($createdIds);
        $expectedPage1 = array_slice($createdIds, 0, 2);
        $expectedPage2 = array_slice($createdIds, 2, 2);

        [, $page1] = $I->get('/fluent-cart/v2/customers', ['per_page' => 2, 'page' => 1]);
        $I->seeResponseCodeIs(200);
        Assert::assertSame(1, (int) ($page1['customers']['current_page'] ?? 0), 'page 1 response must report current_page=1');
        Assert::assertCount(2, $page1['customers']['data'] ?? [], 'page 1 must contain exactly per_page items');

        [, $page2] = $I->get('/fluent-cart/v2/customers', ['per_page' => 2, 'page' => 2]);
        Assert::assertSame(2, (int) ($page2['customers']['current_page'] ?? 0), 'page 2 response must report current_page=2');
        Assert::assertCount(2, $page2['customers']['data'] ?? [], 'page 2 must contain exactly per_page items');

        $page1Ids = array_map('intval', array_column($page1['customers']['data'] ?? [], 'id'));
        $page2Ids = array_map('intval', array_column($page2['customers']['data'] ?? [], 'id'));

        foreach ($expectedPage1 as $id) {
            Assert::assertContains($id, $page1Ids, "page 1 must contain seeded id {$id}");
        }
        foreach ($expectedPage2 as $id) {
            Assert::assertContains($id, $page2Ids, "page 2 must contain seeded id {$id}");
        }
        Assert::assertEmpty(
            array_intersect($page1Ids, $page2Ids),
            'page 1 and page 2 must contain disjoint customers'
        );
    }

    /**
     * Spec: Cases/Functional/customers.md [3]
     *   "Admin can request a specific page size when listing customers."
     */
    public function adminCanRequestSpecificPageSizeWhenListingCustomers(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new CustomerFactory();
        for ($i = 0; $i < 5; $i++) {
            $factory->create(['email' => 'page-size-' . $i . '@example.test']);
        }

        [, $body] = $I->get('/fluent-cart/v2/customers', ['per_page' => 3]);

        $I->seeResponseCodeIs(200);
        Assert::assertSame(3, (int) ($body['customers']['per_page'] ?? 0), 'response must echo back the requested per_page value');
        Assert::assertCount(3, $body['customers']['data'] ?? [], 'data array must hold exactly per_page items');
    }

    /**
     * Spec: Cases/Functional/customers.md [4]
     *   "Admin receives an empty list, not an error, when requesting a page beyond the available results."
     */
    public function adminReceivesEmptyListWhenRequestingPageBeyondResults(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        (new CustomerFactory())->create(['email' => 'only-one@example.test']);

        [, $body] = $I->get('/fluent-cart/v2/customers', ['per_page' => 5, 'page' => 999]);

        $I->seeResponseCodeIs(200);
        Assert::assertSame([], $body['customers']['data'] ?? null, 'page beyond available results must return an empty data array, not an error');
    }

    /**
     * Spec: Cases/Functional/customers.md [5]
     *   "Admin can search customers by part of an email address and only matching customers are returned."
     */
    public function adminCanSearchCustomersByPartialEmail(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new CustomerFactory();
        $hit  = $factory->create(['email' => 'unique-zebra-needle@example.test', 'first_name' => 'Searchable']);
        $miss = $factory->create(['email' => 'unrelated-haystack@example.test', 'first_name' => 'Excluded']);

        [, $body] = $I->get('/fluent-cart/v2/customers', ['search' => 'zebra-needle']);

        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column($body['customers']['data'] ?? [], 'id'));
        Assert::assertContains((int) $hit->id, $ids, 'customer whose email matches the search must appear in results');
        Assert::assertNotContains((int) $miss->id, $ids, 'customer whose email does not match must be excluded');
    }

    /**
     * Spec: Cases/Functional/customers.md [6]
     *   "Admin can search customers by part of a customer's name and only matching customers are returned."
     */
    public function adminCanSearchCustomersByPartialName(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new CustomerFactory();
        $hit  = $factory->create([
            'email'      => 'name-search-hit@example.test',
            'first_name' => 'Zephyr',
            'last_name'  => 'Quill',
        ]);
        $miss = $factory->create([
            'email'      => 'name-search-miss@example.test',
            'first_name' => 'Plain',
            'last_name'  => 'Smith',
        ]);

        [, $body] = $I->get('/fluent-cart/v2/customers', ['search' => 'Zephyr']);

        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column($body['customers']['data'] ?? [], 'id'));
        Assert::assertContains((int) $hit->id, $ids, 'customer whose first name matches the search must appear in results');
        Assert::assertNotContains((int) $miss->id, $ids, 'customer whose name does not match must be excluded');
    }

    /**
     * Spec: Cases/Functional/customers.md [7]
     *   "Admin can filter customers by status and only customers with that status are returned."
     */
    public function adminCanFilterCustomersByStatus(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new CustomerFactory();
        $active   = $factory->create(['email' => 'status-active@example.test', 'status' => 'active']);
        $inactive = $factory->create(['email' => 'status-inactive@example.test', 'status' => 'inactive']);

        $advanced = wp_json_encode([
            [
                [
                    'source'   => ['customer', 'status'],
                    'operator' => 'in',
                    'value'    => ['inactive'],
                ],
            ],
        ]);

        [, $body] = $I->get('/fluent-cart/v2/customers', [
            'filter_type'      => 'advanced',
            'advanced_filters' => $advanced,
        ]);

        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column($body['customers']['data'] ?? [], 'id'));
        Assert::assertContains((int) $inactive->id, $ids, 'inactive customer must appear when status filter is "inactive"');
        Assert::assertNotContains((int) $active->id, $ids, 'active customer must be excluded when status filter is "inactive"');
    }

    /**
     * Spec: Cases/Functional/customers.md [8]
     *   "Admin sees customer search input that resembles an SQL injection attempt treated as plain text without causing a server error."
     */
    public function customerSearchSqlInjectionAttemptIsTreatedAsPlainText(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new CustomerFactory();
        $a = $factory->create(['email' => 'inj-normal-a@example.test', 'first_name' => 'Alpha']);
        $b = $factory->create(['email' => 'inj-normal-b@example.test', 'first_name' => 'Bravo']);
        $c = $factory->create(['email' => 'inj-normal-c@example.test', 'first_name' => 'Charlie']);
        $totalSeeded = 3;

        $payload = "alphazzz' OR '1'='1";
        [, $body] = $I->get('/fluent-cart/v2/customers', ['search' => $payload]);

        $I->seeResponseCodeIs(200);
        $rows = $body['customers']['data'] ?? null;
        Assert::assertIsArray($rows, 'injection-shaped search must still produce a paginator data array');

        $ids = array_map('intval', array_column((array) $rows, 'id'));
        Assert::assertLessThan(
            $totalSeeded,
            count($ids),
            'plain-text search of an injection-shaped payload must not OR-return every seeded customer; got ' . count($ids) . ' rows of ' . $totalSeeded
        );
        Assert::assertNotContains((int) $a->id, $ids, 'customer A must not match an injection-shaped payload that does not literally appear in any column');
        Assert::assertNotContains((int) $b->id, $ids, 'customer B must not match an injection-shaped payload that does not literally appear in any column');
        Assert::assertNotContains((int) $c->id, $ids, 'customer C must not match an injection-shaped payload that does not literally appear in any column');

        $literalPayload = "alphazzz_literal_marker";
        $marked = $factory->create(['email' => 'inj-' . $literalPayload . '@example.test', 'first_name' => 'Delta']);

        [, $literalBody] = $I->get('/fluent-cart/v2/customers', ['search' => $literalPayload]);
        $I->seeResponseCodeIs(200);
        $literalIds = array_map('intval', array_column((array) ($literalBody['customers']['data'] ?? []), 'id'));
        Assert::assertContains(
            (int) $marked->id,
            $literalIds,
            'plain substring search must still locate a customer whose email literally contains it (proves earlier empty result was due to plain-text comparison, not blanket sanitization-rejection)'
        );

        global $wpdb;
        $usersCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");
        Assert::assertGreaterThan(0, $usersCount, 'wp_users must still exist after injection probe');
        $customersCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_customers");
        Assert::assertSame(4, $customersCount, 'fct_customers must still hold the 4 seeded rows after the injection probe');
    }
}

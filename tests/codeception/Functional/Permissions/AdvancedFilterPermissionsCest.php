<?php

namespace Tests\Functional\Permissions;

use FluentCart\App\Models\AttributeGroup;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\AdjustsRolePermissions;

/**
 * Spec: Cases/advanced-filter.md → "Loading options for a filter dropdown".
 *
 * Route (AdvanceFilterPolicy):
 *   GET /advance_filter/get-filter-options
 *
 * The route carries no `permissions` meta on purpose. One URL serves several
 * unrelated lists, so the permission belongs to the requested remote_data_key
 * and AdvanceFilterController::resolvePermission() decides it per key:
 *
 *   product_variations  products/view, orders/view
 *   labels              orders/view, customers/view, products/view, labels/view
 *   attr_groups         products/view
 *   attr_terms          products/view
 *
 * A key with no entry is denied. Denial is an empty options list with a 200,
 * not a 403 — the caller is a select input inside a filter panel.
 *
 * Every assertion here checks the returned options, never the status alone: an
 * empty list also answers 200, so status-only coverage would keep passing if
 * the permission map silently stopped matching.
 */
class AdvancedFilterPermissionsCest
{
    use AdjustsRolePermissions;

    /**
     * Spec: Cases/advanced-filter.md [1]
     *   "Store manager can load product options for a filter dropdown."
     */
    public function managerCanLoadProductOptionsForFilterDropdown(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'ManagerFilterOption1']);
        $I->loginAsManager();

        [$status, $body] = $I->get('/fluent-cart/v2/advance_filter/get-filter-options', [
            'remote_data_key' => 'product_variations',
            'limit'           => 20,
        ]);

        Assert::assertSame(200, $status, 'manager must reach the filter options endpoint. Body: ' . wp_json_encode($body));

        $options = (array) ($body['options'] ?? []);
        Assert::assertNotEmpty($options, 'manager holds products/view, so product_variations must return options rather than an empty list');

        $ids = array_map(static fn ($option) => (int) ($option['value'] ?? 0), $options);
        Assert::assertContains((int) $product->ID, $ids, 'the response must contain the seeded product, proving it carries real data and not a stub');
    }

    /**
     * Spec: Cases/advanced-filter.md [2]
     *   "Worker can load product options for a filter dropdown."
     */
    public function workerCanLoadProductOptionsForFilterDropdown(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'WorkerFilterOption2']);
        $I->loginAsWorker();

        [$status, $body] = $I->get('/fluent-cart/v2/advance_filter/get-filter-options', [
            'remote_data_key' => 'product_variations',
            'limit'           => 20,
        ]);

        Assert::assertSame(200, $status, 'worker must reach the filter options endpoint. Body: ' . wp_json_encode($body));

        $options = (array) ($body['options'] ?? []);
        Assert::assertNotEmpty($options, 'worker holds products/view, so product_variations must return options');

        $ids = array_map(static fn ($option) => (int) ($option['value'] ?? 0), $options);
        Assert::assertContains((int) $product->ID, $ids, 'worker response must include the seeded product');
    }

    /**
     * Spec: Cases/advanced-filter.md [3]
     *   "Worker can load attribute options for the cascading picker."
     *
     * attr_groups is served by the options filter rather than a branch of its
     * own in the controller, so it is the entry most easily forgotten in the
     * permission map. Without it the cascading picker returns an empty list
     * with no error.
     */
    public function workerCanLoadAttributeOptionsForCascadingPicker(FunctionalTester $I): void
    {
        $group = AttributeGroup::create([
            'title' => 'Filter Attribute Group 3',
            'slug'  => 'filter-attribute-group-3',
        ]);

        $I->loginAsWorker();

        [$status, $body] = $I->get('/fluent-cart/v2/advance_filter/get-filter-options', [
            'remote_data_key' => 'attr_groups',
            'limit'           => 20,
        ]);

        Assert::assertSame(200, $status, 'worker must reach the filter options endpoint for attr_groups. Body: ' . wp_json_encode($body));

        $options = (array) ($body['options'] ?? []);
        Assert::assertNotEmpty($options, 'attr_groups must be readable by a worker holding products/view');

        $ids = array_map(static fn ($option) => (int) ($option['id'] ?? 0), $options);
        Assert::assertContains((int) $group->id, $ids, 'the response must contain the seeded attribute group');
    }

    /**
     * Spec: Cases/advanced-filter.md [4]
     *   "A worker whose product access is removed gets no product options."
     */
    public function workerWithoutProductAccessGetsNoProductOptions(FunctionalTester $I): void
    {
        (new ProductFactory())->create(['post_title' => 'StrippedWorkerProduct4']);

        // product_variations also accepts orders/view, so both must go for the
        // key to be denied.
        $restore = $this->stripPermissionsFromRole('worker', ['products/view', 'orders/view']);

        try {
            $I->loginAsWorker();

            [$status, $body] = $I->get('/fluent-cart/v2/advance_filter/get-filter-options', [
                'remote_data_key' => 'product_variations',
                'limit'           => 20,
            ]);

            Assert::assertSame(200, $status, 'denial is an empty list, not an error status. Body: ' . wp_json_encode($body));
            Assert::assertSame([], (array) ($body['options'] ?? null), 'a worker without products/view or orders/view must receive no product options');
        } finally {
            $restore();
        }
    }

    /**
     * Spec: Cases/advanced-filter.md [5]
     *   "A worker whose product access is removed gets no attribute options."
     */
    public function workerWithoutProductAccessGetsNoAttributeOptions(FunctionalTester $I): void
    {
        AttributeGroup::create([
            'title' => 'Filter Attribute Group 5',
            'slug'  => 'filter-attribute-group-5',
        ]);

        $restore = $this->stripPermissionFromRole('worker', 'products/view');

        try {
            $I->loginAsWorker();

            [$status, $body] = $I->get('/fluent-cart/v2/advance_filter/get-filter-options', [
                'remote_data_key' => 'attr_groups',
                'limit'           => 20,
            ]);

            Assert::assertSame(200, $status, 'denial is an empty list, not an error status. Body: ' . wp_json_encode($body));
            Assert::assertSame([], (array) ($body['options'] ?? null), 'attr_groups requires products/view, so a stripped worker must receive no options');
        } finally {
            $restore();
        }
    }

    /**
     * Spec: Cases/advanced-filter.md [6]
     *   "Customer cannot load filter options."
     */
    public function customerCannotLoadFilterOptions(FunctionalTester $I): void
    {
        (new ProductFactory())->create(['post_title' => 'CustomerDeniedProduct6']);
        $I->loginAs('customer');

        [$status, $body] = $I->get('/fluent-cart/v2/advance_filter/get-filter-options', [
            'remote_data_key' => 'product_variations',
            'limit'           => 20,
        ]);

        Assert::assertSame(403, $status, 'a customer has no FluentCart role and must be refused by the policy. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'customer denial must surface the rest_forbidden code');
        Assert::assertArrayNotHasKey('options', (array) $body, 'a refused request must not return an options payload at all');
    }

    /**
     * Spec: Cases/advanced-filter.md [7]
     *   "Logged-out visitor cannot load filter options."
     */
    public function loggedOutVisitorCannotLoadFilterOptions(FunctionalTester $I): void
    {
        (new ProductFactory())->create(['post_title' => 'GuestDeniedProduct7']);
        $I->logout();

        [$status, $body] = $I->get('/fluent-cart/v2/advance_filter/get-filter-options', [
            'remote_data_key' => 'product_variations',
            'limit'           => 20,
        ]);

        Assert::assertSame(401, $status, 'a logged-out visitor must be refused by the policy. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'guest denial must surface the rest_forbidden code');
        Assert::assertArrayNotHasKey('options', (array) $body, 'a refused request must not return an options payload at all');
    }

    /**
     * Spec: Cases/advanced-filter.md [8]
     *   "An unrecognised kind of option returns an empty list rather than an error."
     */
    public function unrecognisedOptionKindReturnsEmptyList(FunctionalTester $I): void
    {
        $I->loginAsManager();

        [$status, $body] = $I->get('/fluent-cart/v2/advance_filter/get-filter-options', [
            'remote_data_key' => 'not_a_registered_key',
            'limit'           => 20,
        ]);

        Assert::assertSame(200, $status, 'an unknown key is answered, not rejected. Body: ' . wp_json_encode($body));
        Assert::assertSame([], (array) ($body['options'] ?? null), 'a key with no permission entry must return an empty list even for a manager');
    }
}

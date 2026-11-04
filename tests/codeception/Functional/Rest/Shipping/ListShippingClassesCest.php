<?php

namespace Tests\Functional\Rest\Shipping;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ShippingClassFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/shipping.md → "Listing and searching shipping classes" section, items 35-39.
 */
class ListShippingClassesCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/shipping.md [35]
     *   "Admin can list shipping classes, and each entry shows the class's name, type, and cost."
     */
    public function adminCanListShippingClassesWithNameTypeAndCost(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new ShippingClassFactory();
        $fixed   = $factory->create(['name' => 'Class-Fixed-35',   'type' => 'fixed',      'cost' => 12.34]);
        $percent = $factory->create(['name' => 'Class-Percent-35', 'type' => 'percentage', 'cost' => 7.5]);

        [, $body] = $I->get('/fluent-cart/v2/shipping/classes');

        $I->seeResponseCodeIs(200);
        $rows = (array) ($body['shipping_classes']['data'] ?? []);
        $byName = [];
        foreach ($rows as $row) {
            $byName[(string) ($row['name'] ?? '')] = $row;
        }

        Assert::assertArrayHasKey('Class-Fixed-35', $byName, 'list must include the seeded fixed class');
        Assert::assertArrayHasKey('Class-Percent-35', $byName, 'list must include the seeded percent class');

        $fixedRow = $byName['Class-Fixed-35'];
        Assert::assertSame('fixed', (string) ($fixedRow['type'] ?? ''), 'list entry must carry the class type');
        Assert::assertSame(12.34, (float) ($fixedRow['cost'] ?? 0), 'list entry must carry the class cost');

        $percentRow = $byName['Class-Percent-35'];
        Assert::assertSame('percentage', (string) ($percentRow['type'] ?? ''), 'list entry must carry the class type for percentage classes');
        Assert::assertSame(7.5, (float) ($percentRow['cost'] ?? 0), 'list entry must carry the class cost for percentage classes');
    }

    /**
     * Spec: Cases/Functional/shipping.md [36]
     *   "Admin can paginate the shipping class list and request a specific page size."
     */
    public function adminCanPaginateShippingClassListWithRequestedPageSize(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new ShippingClassFactory();
        for ($i = 0; $i < 5; $i++) {
            $factory->create(['name' => 'Page-Class-' . $i]);
        }

        [, $page1] = $I->get('/fluent-cart/v2/shipping/classes', ['per_page' => 2, 'page' => 1]);
        $I->seeResponseCodeIs(200);
        Assert::assertSame(1, (int) ($page1['shipping_classes']['current_page'] ?? 0), 'page 1 must report current_page=1');
        Assert::assertSame(2, (int) ($page1['shipping_classes']['per_page'] ?? 0), 'per_page must echo back the requested value');
        Assert::assertCount(2, $page1['shipping_classes']['data'] ?? [], 'page 1 data must contain exactly per_page items');

        [, $page2] = $I->get('/fluent-cart/v2/shipping/classes', ['per_page' => 2, 'page' => 2]);
        Assert::assertSame(2, (int) ($page2['shipping_classes']['current_page'] ?? 0), 'page 2 must report current_page=2');
        Assert::assertCount(2, $page2['shipping_classes']['data'] ?? [], 'page 2 data must contain exactly per_page items');

        $page1Ids = array_map('intval', array_column((array) ($page1['shipping_classes']['data'] ?? []), 'id'));
        $page2Ids = array_map('intval', array_column((array) ($page2['shipping_classes']['data'] ?? []), 'id'));
        Assert::assertEmpty(array_intersect($page1Ids, $page2Ids), 'page 1 and page 2 must be disjoint');
    }

    /**
     * Spec: Cases/Functional/shipping.md [37]
     *   "Asking for a page beyond the available shipping classes returns an empty list, not an error."
     */
    public function askingForPageBeyondAvailableClassesReturnsEmptyList(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        (new ShippingClassFactory())->create(['name' => 'OnlyClassBeyond-37']);

        [, $body] = $I->get('/fluent-cart/v2/shipping/classes', ['per_page' => 5, 'page' => 999]);

        $I->seeResponseCodeIs(200);
        Assert::assertSame([], $body['shipping_classes']['data'] ?? null, 'page beyond available classes must return empty data, not an error');
    }

    /**
     * Spec: Cases/Functional/shipping.md [38]
     *   "Admin can search shipping classes by part of their name and only classes with a matching name are returned."
     */
    public function adminCanSearchShippingClassesByPartialName(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new ShippingClassFactory();
        $hit  = $factory->create(['name' => 'Search-NeedleClass-38']);
        $miss = $factory->create(['name' => 'Search-HaystackClass-38']);

        [, $body] = $I->get('/fluent-cart/v2/shipping/classes', ['search' => 'NeedleClass']);

        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column((array) ($body['shipping_classes']['data'] ?? []), 'id'));
        Assert::assertContains((int) $hit->id, $ids, 'class whose name matches search must appear in results');
        Assert::assertNotContains((int) $miss->id, $ids, 'class whose name does not match must be excluded');
    }

    /**
     * Spec: Cases/Functional/shipping.md [39]
     *   "Admin can filter shipping classes by type so that only fixed or only percentage classes are returned."
     */
    public function adminCanFilterShippingClassesByType(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new ShippingClassFactory();
        $fixed   = $factory->create(['name' => 'TypeFilter-Fixed-39',      'type' => 'fixed']);
        $percent = $factory->create(['name' => 'TypeFilter-Percent-39',    'type' => 'percentage']);

        $advanced = wp_json_encode([
            [
                [
                    'source'   => ['shipping_class', 'type'],
                    'operator' => 'in',
                    'value'    => ['fixed'],
                ],
            ],
        ]);

        [, $body] = $I->get('/fluent-cart/v2/shipping/classes', [
            'filter_type'      => 'advanced',
            'advanced_filters' => $advanced,
        ]);

        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column((array) ($body['shipping_classes']['data'] ?? []), 'id'));
        Assert::assertContains((int) $fixed->id, $ids, 'filtering by type=fixed must include fixed classes');
        Assert::assertNotContains((int) $percent->id, $ids, 'filtering by type=fixed must exclude percentage classes');
    }
}

<?php

namespace Tests\Functional\Rest\Shipping;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ShippingClassFactory;
use Tests\Support\Factory\ShippingZoneFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/shipping.md → "Listing and searching shipping zones" section, items 1-7.
 */
class ListShippingZonesCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/shipping.md [2]
     *   "Admin can paginate the zone list and request a specific page size."
     */
    public function adminCanPaginateZoneListWithRequestedPageSize(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new ShippingZoneFactory();
        $created = [];
        for ($i = 0; $i < 5; $i++) {
            $created[] = (int) $factory->create([
                'name'  => 'Page-Zone-' . $i,
                'order' => $i,
            ])->id;
        }

        [, $page1] = $I->get('/fluent-cart/v2/shipping/zones', ['per_page' => 2, 'page' => 1]);
        $I->seeResponseCodeIs(200);
        Assert::assertSame(1, (int) ($page1['shipping_zones']['current_page'] ?? 0), 'page 1 must report current_page=1');
        Assert::assertSame(2, (int) ($page1['shipping_zones']['per_page'] ?? 0), 'per_page must echo back the requested value');
        Assert::assertCount(2, $page1['shipping_zones']['data'] ?? [], 'page 1 data must contain exactly per_page items');

        [, $page2] = $I->get('/fluent-cart/v2/shipping/zones', ['per_page' => 2, 'page' => 2]);
        $I->seeResponseCodeIs(200);
        Assert::assertSame(2, (int) ($page2['shipping_zones']['current_page'] ?? 0), 'page 2 must report current_page=2');
        Assert::assertCount(2, $page2['shipping_zones']['data'] ?? [], 'page 2 data must contain exactly per_page items');

        $page1Ids = array_map('intval', array_column((array) ($page1['shipping_zones']['data'] ?? []), 'id'));
        $page2Ids = array_map('intval', array_column((array) ($page2['shipping_zones']['data'] ?? []), 'id'));
        Assert::assertEmpty(array_intersect($page1Ids, $page2Ids), 'page 1 and page 2 must contain disjoint zones');
    }

    /**
     * Spec: Cases/Functional/shipping.md [3]
     *   "Asking for a page beyond the available zones returns an empty list, not an error."
     */
    public function askingForPageBeyondAvailableZonesReturnsEmptyList(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        (new ShippingZoneFactory())->create(['name' => 'OnlyZoneBeyond', 'order' => 0]);

        [, $body] = $I->get('/fluent-cart/v2/shipping/zones', ['per_page' => 5, 'page' => 999]);

        $I->seeResponseCodeIs(200);
        Assert::assertSame([], $body['shipping_zones']['data'] ?? null, 'page beyond available zones must return empty data array, not an error');
    }

    /**
     * Spec: Cases/Functional/shipping.md [4]
     *   "Admin can search zones by part of their name and only zones with a matching name are returned."
     */
    public function adminCanSearchZonesByPartialName(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new ShippingZoneFactory();
        $hit  = $factory->create(['name' => 'Unique-Zebra-Marker-Zone', 'order' => 0]);
        $miss = $factory->create(['name' => 'Unrelated-Plain-Zone',     'order' => 1]);

        [, $body] = $I->get('/fluent-cart/v2/shipping/zones', ['search' => 'Zebra-Marker']);

        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column((array) ($body['shipping_zones']['data'] ?? []), 'id'));
        Assert::assertContains((int) $hit->id, $ids, 'zone whose name matches search must appear in results');
        Assert::assertNotContains((int) $miss->id, $ids, 'zone whose name does not match must be excluded');
    }

    /**
     * Spec: Cases/Functional/shipping.md [5]
     *   "Admin can filter the zone list to only those tied to a specific shipping class."
     */
    public function adminCanFilterZoneListByShippingClass(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $class    = (new ShippingClassFactory())->create(['name' => 'Class-Filter-A']);
        $factory  = new ShippingZoneFactory();
        $scoped   = $factory->create(['name' => 'ScopedZone',   'shipping_class_id' => (int) $class->id, 'order' => 0]);
        $general  = $factory->create(['name' => 'GeneralZone',  'shipping_class_id' => null,             'order' => 1]);

        [, $body] = $I->get('/fluent-cart/v2/shipping/zones', ['shipping_class_id' => (int) $class->id]);

        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column((array) ($body['shipping_zones']['data'] ?? []), 'id'));
        Assert::assertContains((int) $scoped->id, $ids, 'class-scoped zone must appear when filtering by its class id');
        Assert::assertNotContains((int) $general->id, $ids, 'general (class-less) zone must NOT appear when filtering by a class id');
    }

    /**
     * Spec: Cases/Functional/shipping.md [6]
     *   "Admin can filter the zone list to only general zones (those not tied to any shipping class)."
     */
    public function adminCanFilterZoneListToOnlyGeneralZones(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $class   = (new ShippingClassFactory())->create(['name' => 'Class-Filter-B']);
        $factory = new ShippingZoneFactory();
        $scoped  = $factory->create(['name' => 'ScopedZone6',  'shipping_class_id' => (int) $class->id, 'order' => 0]);
        $general = $factory->create(['name' => 'GeneralZone6', 'shipping_class_id' => null,             'order' => 1]);

        [, $body] = $I->get('/fluent-cart/v2/shipping/zones', ['shipping_class_id' => '0']);

        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column((array) ($body['shipping_zones']['data'] ?? []), 'id'));
        Assert::assertContains((int) $general->id, $ids, 'general zone must appear when filter targets general-only');
        Assert::assertNotContains((int) $scoped->id, $ids, 'class-scoped zone must NOT appear when filter targets general-only');
    }

    /**
     * Spec: Cases/Functional/shipping.md [7]
     *   "Search input that looks like an SQL wildcard or injection attempt is treated as plain text and never causes a server error."
     */
    public function searchInputResemblingSqlInjectionIsTreatedAsPlainText(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new ShippingZoneFactory();
        $a = $factory->create(['name' => 'NormalAlpha',   'order' => 0]);
        $b = $factory->create(['name' => 'NormalBravo',   'order' => 1]);
        $c = $factory->create(['name' => 'NormalCharlie', 'order' => 2]);

        $literalMarker = 'literal_marker_zone_payload';
        $marked = $factory->create(['name' => 'has-' . $literalMarker . '-mark', 'order' => 99]);

        $payload = "anything' OR '1'='1";
        [, $body] = $I->get('/fluent-cart/v2/shipping/zones', ['search' => $payload]);

        $I->seeResponseCodeIs(200);
        $rows = $body['shipping_zones']['data'] ?? null;
        Assert::assertIsArray($rows, 'injection-shaped search must still produce a paginator data array');

        $ids = array_map('intval', array_column((array) $rows, 'id'));
        Assert::assertNotContains((int) $a->id, $ids, 'plain-text search of injection payload must not OR-return seeded zone A');
        Assert::assertNotContains((int) $b->id, $ids, 'plain-text search of injection payload must not OR-return seeded zone B');
        Assert::assertNotContains((int) $c->id, $ids, 'plain-text search of injection payload must not OR-return seeded zone C');
        Assert::assertNotContains(
            (int) $marked->id,
            $ids,
            'plain-text search of injection payload must not OR-return the marker zone either (proves payload is searched literally — marker name does not contain the payload string)'
        );

        // SQL LIKE wildcard probe: a raw '%' should be treated as a literal character,
        // not a wildcard. If the search reached the DB unescaped, '%' would match every row.
        $wildcardZone = $factory->create(['name' => 'no-percent-in-this-name', 'order' => 100]);
        [, $wildcardBody] = $I->get('/fluent-cart/v2/shipping/zones', ['search' => '%']);
        $I->seeResponseCodeIs(200);
        $wildcardIds = array_map('intval', array_column((array) ($wildcardBody['shipping_zones']['data'] ?? []), 'id'));
        Assert::assertNotContains(
            (int) $wildcardZone->id,
            $wildcardIds,
            'SQL LIKE wildcard "%" must be escaped to literal — a name without "%" must not match'
        );

        [, $literalBody] = $I->get('/fluent-cart/v2/shipping/zones', ['search' => $literalMarker]);
        $I->seeResponseCodeIs(200);
        $literalIds = array_map('intval', array_column((array) ($literalBody['shipping_zones']['data'] ?? []), 'id'));
        Assert::assertContains(
            (int) $marked->id,
            $literalIds,
            'plain substring search must still locate a zone whose name literally contains it (proves earlier empty result was due to plain-text comparison, not blanket rejection)'
        );

        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_shipping_zones");
        Assert::assertSame(5, $count, 'fct_shipping_zones must still hold the 5 seeded rows (3 normal + 1 marker + 1 wildcard probe) after injection probe');
    }
}

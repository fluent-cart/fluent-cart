<?php

namespace Tests\Functional\Rest\Shipping;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ShippingClassFactory;
use Tests\Support\Factory\ShippingMethodFactory;
use Tests\Support\Factory\ShippingZoneFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/shipping.md → "Viewing a single shipping zone" section, items 8-10.
 */
class ViewShippingZoneCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/shipping.md [8]
     *   "Admin can open a zone by id and see the zone together with the shipping methods that belong to it."
     */
    public function adminCanOpenZoneByIdAndSeeMethodsBelongingToIt(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone   = (new ShippingZoneFactory())->create(['name' => 'ZoneView8', 'order' => 0]);
        $method = (new ShippingMethodFactory())->create([
            'zone_id' => (int) $zone->id,
            'title'   => 'MethodAttachedTo8',
        ]);

        [, $body] = $I->get('/fluent-cart/v2/shipping/zones/' . (int) $zone->id);

        $I->seeResponseCodeIs(200);
        Assert::assertSame((int) $zone->id, (int) ($body['shipping_zone']['id'] ?? 0), 'view zone must echo the requested zone id');
        $methods = (array) ($body['shipping_zone']['methods'] ?? []);
        $methodIds = array_map('intval', array_column($methods, 'id'));
        Assert::assertContains((int) $method->id, $methodIds, 'view zone must include the shipping methods nested under "methods"');
    }

    /**
     * Spec: Cases/Functional/shipping.md [9]
     *   "Opening a zone that does not exist returns a clear \"not found\" error."
     */
    public function openingNonExistentZoneReturnsNotFoundError(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->get('/fluent-cart/v2/shipping/zones/999999');

        Assert::assertGreaterThanOrEqual(400, $status, 'opening missing zone must be a client/server error, not 2xx. Body: ' . wp_json_encode($body));
        Assert::assertLessThan(500, $status, 'opening missing zone must NOT 500 — it should be a clear not-found. Status: ' . $status);
        Assert::assertEmpty(
            $body['shipping_zone'] ?? null,
            'response must NOT echo a shipping_zone payload for a missing id'
        );
    }

    /**
     * Spec: Cases/Functional/shipping.md [10]
     *   "The shipping class profile route is gone: GET /shipping/classes/{id}/profile returns 404."
     */
    public function shippingClassProfileRouteIsGone(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $class = (new ShippingClassFactory())->create(['name' => 'ProfileClass10']);

        $I->get('/fluent-cart/v2/shipping/classes/' . (int) $class->id . '/profile');

        $I->seeResponseCodeIs(404);
    }
}

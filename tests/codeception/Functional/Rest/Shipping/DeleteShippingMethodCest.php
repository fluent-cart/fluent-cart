<?php

namespace Tests\Functional\Rest\Shipping;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ShippingMethodFactory;
use Tests\Support\Factory\ShippingZoneFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/shipping.md → "Deleting a shipping method" section, items 74-75.
 */
class DeleteShippingMethodCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/shipping.md [74]
     *   "Admin can delete a shipping method, and the deleted method no longer appears under its zone."
     */
    public function adminCanDeleteShippingMethodAndItNoLongerAppearsUnderZone(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone   = (new ShippingZoneFactory())->create(['name' => 'Zone74', 'region' => 'US']);
        $methodToDelete = (new ShippingMethodFactory())->create([
            'zone_id' => (int) $zone->id,
            'title'   => 'ToDelete74',
        ]);
        $survivor = (new ShippingMethodFactory())->create([
            'zone_id' => (int) $zone->id,
            'title'   => 'Survivor74',
        ]);

        [, $body] = $I->delete('/fluent-cart/v2/shipping/methods/' . (int) $methodToDelete->id);
        $I->seeResponseCodeIs(200);

        global $wpdb;
        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_shipping_methods WHERE id = %d",
            $methodToDelete->id
        ));
        Assert::assertSame(0, $remaining, 'deleted method row must be removed');

        [, $zoneBody] = $I->get('/fluent-cart/v2/shipping/zones/' . (int) $zone->id);
        $methods = (array) ($zoneBody['shipping_zone']['methods'] ?? []);
        $methodIds = array_map('intval', array_column($methods, 'id'));
        Assert::assertNotContains((int) $methodToDelete->id, $methodIds, 'deleted method must not appear in zone\'s nested methods');
        Assert::assertContains((int) $survivor->id, $methodIds, 'unrelated method in the same zone must still appear');
    }

    /**
     * Spec: Cases/Functional/shipping.md [75]
     *   "Deleting a shipping method that does not exist returns a clear \"not found\" error."
     */
    public function deletingNonExistentShippingMethodReturnsNotFoundError(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->delete('/fluent-cart/v2/shipping/methods/999999');

        Assert::assertGreaterThanOrEqual(400, $status, 'deleting a missing method must be a non-2xx status. Body: ' . wp_json_encode($body));
        Assert::assertLessThan(500, $status, 'deleting a missing method must NOT 500. Status: ' . $status);
    }
}

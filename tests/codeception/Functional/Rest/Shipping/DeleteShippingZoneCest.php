<?php

namespace Tests\Functional\Rest\Shipping;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ShippingMethodFactory;
use Tests\Support\Factory\ShippingZoneFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/shipping.md → "Deleting a shipping zone" section, items 30-31.
 */
class DeleteShippingZoneCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/shipping.md [30]
     *   "Admin can delete a zone; every shipping method that belongs to that zone is removed at the same time."
     */
    public function adminCanDeleteZoneAndAllMethodsBelongingToItAreRemoved(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone   = (new ShippingZoneFactory())->create(['name' => 'ZoneToDelete30', 'region' => 'US']);
        $methodA = (new ShippingMethodFactory())->create(['zone_id' => (int) $zone->id, 'title' => 'M-A-30']);
        $methodB = (new ShippingMethodFactory())->create(['zone_id' => (int) $zone->id, 'title' => 'M-B-30']);
        $unrelatedZone = (new ShippingZoneFactory())->create(['name' => 'UnrelatedZone30', 'region' => 'GB']);
        $unrelatedMethod = (new ShippingMethodFactory())->create(['zone_id' => (int) $unrelatedZone->id, 'title' => 'M-Unrelated-30']);

        [, $body] = $I->delete('/fluent-cart/v2/shipping/zones/' . (int) $zone->id);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $zoneCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_shipping_zones WHERE id = %d",
            $zone->id
        ));
        Assert::assertSame(0, $zoneCount, 'deleted zone row must be removed from fct_shipping_zones');

        $methodACount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_shipping_methods WHERE id = %d",
            $methodA->id
        ));
        $methodBCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_shipping_methods WHERE id = %d",
            $methodB->id
        ));
        Assert::assertSame(0, $methodACount, 'method A belonging to deleted zone must be cascade-removed');
        Assert::assertSame(0, $methodBCount, 'method B belonging to deleted zone must be cascade-removed');

        $unrelatedMethodCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_shipping_methods WHERE id = %d",
            $unrelatedMethod->id
        ));
        Assert::assertSame(1, $unrelatedMethodCount, 'unrelated zone\'s method must survive the cascade');
    }

    /**
     * Spec: Cases/Functional/shipping.md [31]
     *   "Deleting a zone that does not exist returns a clear \"not found\" error."
     */
    public function deletingNonExistentZoneReturnsNotFoundError(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->delete('/fluent-cart/v2/shipping/zones/999999');

        Assert::assertGreaterThanOrEqual(400, $status, 'deleting a missing zone must return a non-2xx status. Body: ' . wp_json_encode($body));
        Assert::assertLessThan(500, $status, 'deleting a missing zone must NOT 500 — it should be a clear not-found. Status: ' . $status);
    }
}

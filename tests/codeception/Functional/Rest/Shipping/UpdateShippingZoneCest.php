<?php

namespace Tests\Functional\Rest\Shipping;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ShippingClassFactory;
use Tests\Support\Factory\ShippingMethodFactory;
use Tests\Support\Factory\ShippingZoneFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/shipping.md → "Updating a shipping zone" section, items 22-26.
 */
class UpdateShippingZoneCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/shipping.md [22]
     *   "Admin can rename an existing zone."
     */
    public function adminCanRenameExistingZone(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone = (new ShippingZoneFactory())->create(['name' => 'OldName22', 'region' => 'US']);

        [, $body] = $I->put('/fluent-cart/v2/shipping/zones/' . (int) $zone->id, [
            'name'   => 'RenamedZone22',
            'region' => 'US',
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $name = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}fct_shipping_zones WHERE id = %d",
            $zone->id
        ));
        Assert::assertSame('RenamedZone22', $name, 'rename must persist the new name in fct_shipping_zones');
    }

    /**
     * Spec: Cases/Functional/shipping.md [23]
     *   "Changing a zone's region clears the state list on every shipping method that belongs to that zone."
     */
    public function changingZoneRegionClearsStateListsOnItsMethods(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone   = (new ShippingZoneFactory())->create(['name' => 'StateClearZone23', 'region' => 'US']);
        $method = (new ShippingMethodFactory())->create([
            'zone_id' => (int) $zone->id,
            'title'   => 'MethodWithStates',
            'states'  => ['CA', 'NY'],
        ]);
        Assert::assertNotEmpty($method->fresh()->states, 'precondition: method must start with non-empty state list');

        [, $body] = $I->put('/fluent-cart/v2/shipping/zones/' . (int) $zone->id, [
            'name'   => 'StateClearZone23',
            'region' => 'CA',
        ]);

        $I->seeResponseCodeIs(200);

        $refreshed = \FluentCart\App\Models\ShippingMethod::find($method->id);
        Assert::assertSame(
            [],
            (array) $refreshed->states,
            'changing the zone region must clear the state list on every method belonging to that zone'
        );
    }

    /**
     * Spec: Cases/Functional/shipping.md [24]
     *   "Updating a zone's region while keeping the same value preserves the methods' existing state lists."
     */
    public function updatingZoneRegionToSameValuePreservesMethodStateLists(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone   = (new ShippingZoneFactory())->create(['name' => 'StatePreserveZone24', 'region' => 'US']);
        $method = (new ShippingMethodFactory())->create([
            'zone_id' => (int) $zone->id,
            'title'   => 'MethodWithPreservedStates',
            'states'  => ['CA', 'TX'],
        ]);

        [, $body] = $I->put('/fluent-cart/v2/shipping/zones/' . (int) $zone->id, [
            'name'   => 'StatePreserveZone24',
            'region' => 'US',
        ]);

        $I->seeResponseCodeIs(200);

        $refreshed = \FluentCart\App\Models\ShippingMethod::find($method->id);
        Assert::assertSame(
            ['CA', 'TX'],
            (array) $refreshed->states,
            'no-op region change must preserve the method state list verbatim'
        );
    }

    /**
     * Spec: Cases/Functional/shipping.md [25]
     *   "Updating a zone that does not exist returns a clear \"not found\" error."
     */
    public function updatingNonExistentZoneReturnsNotFoundError(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->put('/fluent-cart/v2/shipping/zones/999999', [
            'name'   => 'Whatever',
            'region' => 'US',
        ]);

        Assert::assertGreaterThanOrEqual(400, $status, 'updating a missing zone must be a non-2xx response. Body: ' . wp_json_encode($body));
        Assert::assertLessThan(500, $status, 'updating a missing zone must NOT 500 — it should be a clear not-found. Status: ' . $status);
        Assert::assertEmpty(
            $body['shipping_zone'] ?? null,
            'response must NOT echo a shipping_zone payload for a missing id'
        );
    }

    /**
     * Spec: Cases/Functional/shipping.md [26]
     *   "Updating a zone without specifying a shipping class leaves the zone's existing class assignment unchanged."
     */
    public function updatingZoneWithoutShippingClassLeavesExistingClassAssignmentUnchanged(FunctionalTester $I): void
    {

        $I->loginAsSuperAdmin();
        $class = (new ShippingClassFactory())->create(['name' => 'PinnedClass26']);
        $zone  = (new ShippingZoneFactory())->create([
            'name'              => 'PinnedZone26',
            'region'            => 'US',
            'shipping_class_id' => (int) $class->id,
        ]);

        [, $body] = $I->put('/fluent-cart/v2/shipping/zones/' . (int) $zone->id, [
            'name'   => 'PinnedZoneRenamed26',
            'region' => 'US',
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $classIdAfter = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT shipping_class_id FROM {$wpdb->prefix}fct_shipping_zones WHERE id = %d",
            $zone->id
        ));
        Assert::assertSame(
            (int) $class->id,
            $classIdAfter,
            'omitting shipping_class_id on update must preserve the previous class assignment, not wipe it to null'
        );
    }

    /**
     * Spec: Cases/Functional/shipping.md [127]
     *   "A shipping_class_id sent when updating a zone is ignored: a general zone stays general."
     */
    public function shippingClassIdSentOnUpdateIsIgnored(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $class = (new ShippingClassFactory())->create(['name' => 'Class127']);
        $zone  = (new ShippingZoneFactory())->create(['name' => 'GeneralZone127', 'region' => 'US', 'shipping_class_id' => null]);

        $I->put('/fluent-cart/v2/shipping/zones/' . (int) $zone->id, [
            'name'              => 'GeneralZone127',
            'region'            => 'US',
            'shipping_class_id' => (int) $class->id,
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $classId = $wpdb->get_var($wpdb->prepare("SELECT shipping_class_id FROM {$wpdb->prefix}fct_shipping_zones WHERE id = %d", $zone->id));
        Assert::assertNull($classId, 'the zone must stay general');
    }

    /**
     * Spec: Cases/Functional/shipping.md [128]
     *   "Updating a stored class zone to cover the whole world is rejected when a general whole-world zone exists."
     */
    public function storedClassZoneGoingWholeWorldIsRejectedWhenGeneralOneExists(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        (new ShippingZoneFactory())->create(['name' => 'GeneralWholeWorld128', 'region' => 'all', 'shipping_class_id' => null]);
        $class = (new ShippingClassFactory())->create(['name' => 'Class128']);
        $zone  = (new ShippingZoneFactory())->create(['name' => 'ClassZone128', 'region' => 'US', 'shipping_class_id' => (int) $class->id]);

        [$status, $body] = $I->put('/fluent-cart/v2/shipping/zones/' . (int) $zone->id, [
            'name'   => 'ClassZoneWholeWorld128',
            'region' => 'all',
        ]);

        Assert::assertSame(422, $status, 'only one Whole World zone is allowed. Body: ' . wp_json_encode($body));

        global $wpdb;
        $regionAfter = (string) $wpdb->get_var($wpdb->prepare("SELECT region FROM {$wpdb->prefix}fct_shipping_zones WHERE id = %d", $zone->id));
        Assert::assertSame('US', $regionAfter, 'the rejected update must leave the zone region unchanged');
    }
}

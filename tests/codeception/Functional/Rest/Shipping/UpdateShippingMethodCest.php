<?php

namespace Tests\Functional\Rest\Shipping;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ShippingMethodFactory;
use Tests\Support\Factory\ShippingZoneFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/shipping.md → "Updating a shipping method" section, items 70-73, 129-133.
 */
class UpdateShippingMethodCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/shipping.md [70]
     *   "Admin can rename an existing shipping method."
     */
    public function adminCanRenameExistingShippingMethod(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone   = (new ShippingZoneFactory())->create(['name' => 'Zone70', 'region' => 'US']);
        $method = (new ShippingMethodFactory())->create([
            'zone_id' => (int) $zone->id,
            'title'   => 'OldTitle70',
            'type'    => 'flat_rate',
        ]);

        [, $body] = $I->put('/fluent-cart/v2/shipping/methods', [
            'method_id' => (int) $method->id,
            'zone_id'   => (int) $zone->id,
            'title'     => 'RenamedTitle70',
            'type'      => 'flat_rate',
            'states'    => [],
        ]);
        $I->seeResponseCodeIs(200);

        global $wpdb;
        $title = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT title FROM {$wpdb->prefix}fct_shipping_methods WHERE id = %d",
            $method->id
        ));
        Assert::assertSame('RenamedTitle70', $title, 'rename must persist the new title');
    }

    /**
     * Spec: Cases/Functional/shipping.md [71]
     *   "Admin can change a shipping method's enabled flag and the new value persists."
     */
    public function adminCanChangeShippingMethodEnabledFlag(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone   = (new ShippingZoneFactory())->create(['name' => 'Zone71', 'region' => 'US']);
        $method = (new ShippingMethodFactory())->create([
            'zone_id'    => (int) $zone->id,
            'title'      => 'EnabledFlip71',
            'is_enabled' => 1,
        ]);

        [, $body] = $I->put('/fluent-cart/v2/shipping/methods', [
            'method_id'  => (int) $method->id,
            'zone_id'    => (int) $zone->id,
            'title'      => 'EnabledFlip71',
            'type'       => 'flat_rate',
            'is_enabled' => 0,
            'states'     => [],
        ]);
        $I->seeResponseCodeIs(200);

        global $wpdb;
        $isEnabled = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT is_enabled FROM {$wpdb->prefix}fct_shipping_methods WHERE id = %d",
            $method->id
        ));
        Assert::assertSame(0, $isEnabled, 'enabled flag must persist as 0 after disable update');
    }

    /**
     * Spec: Cases/Functional/shipping.md [72]
     *   "Updating a shipping method's state list replaces the previous list rather than appending to it."
     */
    public function updatingShippingMethodStateListReplacesPreviousList(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone   = (new ShippingZoneFactory())->create(['name' => 'Zone72', 'region' => 'US']);
        $method = (new ShippingMethodFactory())->create([
            'zone_id' => (int) $zone->id,
            'title'   => 'StateReplace72',
            'states'  => ['CA', 'NY'],
        ]);

        [, $body] = $I->put('/fluent-cart/v2/shipping/methods', [
            'method_id' => (int) $method->id,
            'zone_id'   => (int) $zone->id,
            'title'     => 'StateReplace72',
            'type'      => 'flat_rate',
            'states'    => ['TX'],
        ]);
        $I->seeResponseCodeIs(200);

        $refreshed = \FluentCart\App\Models\ShippingMethod::find($method->id);
        Assert::assertSame(['TX'], (array) $refreshed->states, 'state list must be REPLACED with new array, not appended to');
        Assert::assertNotContains('CA', (array) $refreshed->states, 'previous CA state must not survive the replace');
        Assert::assertNotContains('NY', (array) $refreshed->states, 'previous NY state must not survive the replace');
    }

    /**
     * Spec: Cases/Functional/shipping.md [73]
     *   "Updating a shipping method that does not exist returns a clear \"not found\" error."
     */
    public function updatingNonExistentShippingMethodReturnsNotFoundError(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone = (new ShippingZoneFactory())->create(['name' => 'Zone73', 'region' => 'US']);

        [$status, $body] = $I->put('/fluent-cart/v2/shipping/methods', [
            'method_id' => 999999,
            'zone_id'   => (int) $zone->id,
            'title'     => 'Whatever73',
            'type'      => 'flat_rate',
            'states'    => [],
        ]);

        Assert::assertGreaterThanOrEqual(400, $status, 'updating a missing method must be a non-2xx status. Body: ' . wp_json_encode($body));
        Assert::assertLessThan(500, $status, 'updating a missing method must NOT 500. Status: ' . $status);
    }

    /**
     * Spec: Cases/Functional/shipping.md [129]
     *   "Admin can save a tracking URL pattern on a shipping method, and it persists in the method's meta."
     */
    public function adminCanSaveTrackingUrlPatternOnShippingMethod(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone   = (new ShippingZoneFactory())->create(['name' => 'Zone129', 'region' => 'US']);
        $method = (new ShippingMethodFactory())->create(['zone_id' => (int) $zone->id, 'title' => 'Courier129']);

        $I->put('/fluent-cart/v2/shipping/methods', [
            'method_id' => (int) $method->id,
            'zone_id'   => (int) $zone->id,
            'title'     => 'Courier129',
            'type'      => 'flat_rate',
            'states'    => [],
            'meta'      => ['tracking_url_pattern' => 'https://courier.example/t/{tracking_number}'],
        ]);
        $I->seeResponseCodeIs(200);

        $meta = (array) \FluentCart\App\Models\ShippingMethod::find($method->id)->meta;
        Assert::assertSame('https://courier.example/t/{tracking_number}', $meta['tracking_url_pattern'] ?? null);
    }

    /**
     * Spec: Cases/Functional/shipping.md [130]
     *   "A tracking URL pattern that is not an http(s) URL with the {tracking_number} placeholder is rejected."
     */
    public function invalidTrackingUrlPatternIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone   = (new ShippingZoneFactory())->create(['name' => 'Zone130', 'region' => 'US']);
        $method = (new ShippingMethodFactory())->create(['zone_id' => (int) $zone->id, 'title' => 'Courier130']);

        foreach (['https://courier.example/no-placeholder', 'javascript:alert(1)//{tracking_number}'] as $pattern) {
            [$status, $body] = $I->put('/fluent-cart/v2/shipping/methods', [
                'method_id' => (int) $method->id,
                'zone_id'   => (int) $zone->id,
                'title'     => 'Courier130',
                'type'      => 'flat_rate',
                'states'    => [],
                'meta'      => ['tracking_url_pattern' => $pattern],
            ]);
            Assert::assertSame(422, $status, $pattern . ' must be rejected. Body: ' . wp_json_encode($body));
        }

        $meta = (array) \FluentCart\App\Models\ShippingMethod::find($method->id)->meta;
        Assert::assertArrayNotHasKey('tracking_url_pattern', $meta, 'a rejected pattern must not be stored');
    }

    private function updateMeta(FunctionalTester $I, $method, array $meta): array
    {
        $I->put('/fluent-cart/v2/shipping/methods', [
            'method_id' => (int) $method->id,
            'zone_id'   => (int) $method->zone_id,
            'title'     => (string) $method->title,
            'type'      => 'flat_rate',
            'states'    => [],
            'meta'      => $meta,
        ]);
        $I->seeResponseCodeIs(200);

        return (array) \FluentCart\App\Models\ShippingMethod::find($method->id)->meta;
    }

    /**
     * Spec: Cases/Functional/shipping.md [131]
     *   "Updating a shipping method keeps meta keys the edit form does not send."
     */
    public function updateKeepsMetaKeysTheFormDoesNotSend(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone   = (new ShippingZoneFactory())->create(['name' => 'Zone131', 'region' => 'US']);
        $method = (new ShippingMethodFactory())->create([
            'zone_id' => (int) $zone->id,
            'title'   => 'Courier131',
            'meta'    => ['addon_ref' => 'keep-me', 'description' => 'Old'],
        ]);

        $meta = $this->updateMeta($I, $method, ['description' => 'New', 'tracking_url_pattern' => '']);

        Assert::assertSame('keep-me', $meta['addon_ref'] ?? null, 'a meta key the form does not send must survive');
        Assert::assertSame('New', $meta['description'] ?? null);
    }

    /**
     * Spec: Cases/Functional/shipping.md [132]
     *   "Saving an empty tracking URL pattern removes the pattern from the method's meta."
     */
    public function emptyTrackingUrlPatternRemovesTheKey(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone  = (new ShippingZoneFactory())->create(['name' => 'Zone132', 'region' => 'US']);
        $set   = (new ShippingMethodFactory())->create([
            'zone_id' => (int) $zone->id,
            'title'   => 'Courier132a',
            'meta'    => ['tracking_url_pattern' => 'https://courier.example/t/{tracking_number}'],
        ]);
        $unset = (new ShippingMethodFactory())->create(['zone_id' => (int) $zone->id, 'title' => 'Courier132b']);

        $meta = $this->updateMeta($I, $set, ['tracking_url_pattern' => '']);
        Assert::assertArrayNotHasKey('tracking_url_pattern', $meta, 'clearing a set pattern must remove it');

        $meta = $this->updateMeta($I, $unset, ['tracking_url_pattern' => '']);
        Assert::assertArrayNotHasKey('tracking_url_pattern', $meta, 'an empty pattern must not add an empty key');
    }

    /**
     * Spec: Cases/Functional/shipping.md [133]
     *   "Changing a saved tracking URL pattern replaces the old pattern."
     */
    public function changingTrackingUrlPatternReplacesTheOldOne(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone   = (new ShippingZoneFactory())->create(['name' => 'Zone133', 'region' => 'US']);
        $method = (new ShippingMethodFactory())->create([
            'zone_id' => (int) $zone->id,
            'title'   => 'Courier133',
            'meta'    => ['tracking_url_pattern' => 'https://old.example/t/{tracking_number}'],
        ]);

        $meta = $this->updateMeta($I, $method, ['tracking_url_pattern' => 'https://new.example/t/{tracking_number}']);

        Assert::assertSame('https://new.example/t/{tracking_number}', $meta['tracking_url_pattern'] ?? null);
    }
}

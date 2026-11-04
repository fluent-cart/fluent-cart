<?php

namespace Tests\Functional\Rest\Shipping;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ShippingClassFactory;
use Tests\Support\Factory\ShippingZoneFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/shipping.md → "Creating a shipping zone" section, items 11-21.
 */
class CreateShippingZoneCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/shipping.md [11]
     *   "Admin can create a country-specific zone for a single country."
     */
    public function adminCanCreateCountrySpecificZoneForSingleCountry(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/shipping/zones', [
            'name'   => 'US-Only Zone',
            'region' => 'US',
        ]);

        $I->seeResponseCodeIs(200);
        $newId = (int) ($body['shipping_zone']['id'] ?? 0);
        Assert::assertGreaterThan(0, $newId, 'create response must return a non-zero zone id');

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}fct_shipping_zones WHERE id = %d", $newId),
            ARRAY_A
        );
        Assert::assertNotEmpty($row, 'created zone row must exist in fct_shipping_zones');
        Assert::assertSame('US-Only Zone', (string) $row['name'], 'name must persist as supplied');
        Assert::assertSame('US', (string) $row['region'], 'region must persist as supplied country code');
    }

    /**
     * Spec: Cases/Functional/shipping.md [12]
     *   "Admin can create a \"Whole World\" zone that covers every country."
     */
    public function adminCanCreateWholeWorldZoneCoveringEveryCountry(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/shipping/zones', [
            'name'   => 'Whole World Zone',
            'region' => 'all',
        ]);

        $I->seeResponseCodeIs(200);
        $newId = (int) ($body['shipping_zone']['id'] ?? 0);
        Assert::assertGreaterThan(0, $newId, 'create response must return a non-zero zone id');

        global $wpdb;
        $region = $wpdb->get_var($wpdb->prepare(
            "SELECT region FROM {$wpdb->prefix}fct_shipping_zones WHERE id = %d",
            $newId
        ));
        Assert::assertSame('all', (string) $region, 'region must persist as "all" to cover every country');
    }

    /**
     * Spec: Cases/Functional/shipping.md [13]
     *   "Admin can create a country-selection zone whose country list is treated as \"include these countries\"."
     */
    public function adminCanCreateSelectionZoneTreatedAsIncludedCountries(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/shipping/zones', [
            'name'   => 'Include-List Zone',
            'region' => 'selection',
            'meta'   => [
                'countries'      => ['US', 'CA'],
                'selection_type' => 'included',
            ],
        ]);

        $I->seeResponseCodeIs(200);
        $newId = (int) ($body['shipping_zone']['id'] ?? 0);
        Assert::assertGreaterThan(0, $newId, 'create response must return a non-zero zone id');

        $zone = \FluentCart\App\Models\ShippingZone::find($newId);
        Assert::assertNotNull($zone, 'created selection zone must exist');
        Assert::assertSame('selection', (string) $zone->region, 'region must persist as "selection"');
        Assert::assertTrue($zone->appliesToCountry('US'), 'included-list selection zone must apply to a country on the list');
        Assert::assertFalse($zone->appliesToCountry('GB'), 'included-list selection zone must NOT apply to a country off the list');
    }

    /**
     * Spec: Cases/Functional/shipping.md [14]
     *   "Admin can create a country-selection zone whose country list is treated as \"exclude these countries\"."
     */
    public function adminCanCreateSelectionZoneTreatedAsExcludedCountries(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/shipping/zones', [
            'name'   => 'Exclude-List Zone',
            'region' => 'selection',
            'meta'   => [
                'countries'      => ['US', 'CA'],
                'selection_type' => 'excluded',
            ],
        ]);

        $I->seeResponseCodeIs(200);
        $newId = (int) ($body['shipping_zone']['id'] ?? 0);
        Assert::assertGreaterThan(0, $newId, 'create response must return a non-zero zone id');

        $zone = \FluentCart\App\Models\ShippingZone::find($newId);
        Assert::assertNotNull($zone, 'created exclusion zone must exist');
        Assert::assertFalse($zone->appliesToCountry('US'), 'excluded-list selection zone must NOT apply to a country on the list');
        Assert::assertTrue($zone->appliesToCountry('GB'), 'excluded-list selection zone must apply to a country NOT on the list');
    }

    /**
     * Spec: Cases/Functional/shipping.md [15]
     *   "Creating a zone without a name is rejected with a clear validation error."
     */
    public function creatingZoneWithoutNameIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/shipping/zones', [
            'region' => 'US',
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertArrayHasKey('name', (array) $body, 'validation payload must key the failed rule by field name (name). Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('required', (array) ($body['name'] ?? []), 'validation entry for name must surface the "required" rule key. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty($body['name']['required'] ?? null, 'required-rule message for name must be non-empty');

        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_shipping_zones");
        Assert::assertSame(0, $count, 'no zone row may be created when name validation fails');
    }

    /**
     * Spec: Cases/Functional/shipping.md [16]
     *   "Creating a second general \"Whole World\" zone is rejected with a clear error explaining that only one is allowed."
     */
    public function creatingSecondGeneralWholeWorldZoneIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        (new ShippingZoneFactory())->create(['name' => 'Existing-WW', 'region' => 'all', 'shipping_class_id' => null]);

        [, $body] = $I->post('/fluent-cart/v2/shipping/zones', [
            'name'   => 'Duplicate-WW',
            'region' => 'all',
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertArrayHasKey('region', (array) $body, 'validation payload must key the failure under region. Body: ' . wp_json_encode($body));
        $regionErrors = (array) ($body['region'] ?? []);
        $combined = implode(' ', array_map('strval', $regionErrors));
        Assert::assertStringContainsStringIgnoringCase('whole world', $combined, 'error must explain that only one Whole World zone is allowed. Body: ' . wp_json_encode($body));

        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_shipping_zones WHERE region = 'all'");
        Assert::assertSame(1, $count, 'still only one general Whole World zone exists after rejection');
    }

    /**
     * Spec: Cases/Functional/shipping.md [17]
     *   "A shipping_class_id sent when creating a zone is ignored: the zone is created as a general zone."
     */
    public function shippingClassIdSentOnCreateIsIgnored(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $class = (new ShippingClassFactory())->create(['name' => 'IgnoredClass17']);

        [, $body] = $I->post('/fluent-cart/v2/shipping/zones', [
            'name'              => 'Zone-17',
            'region'            => 'US',
            'shipping_class_id' => (int) $class->id,
        ]);

        $I->seeResponseCodeIs(200);
        $newId = (int) ($body['shipping_zone']['id'] ?? 0);
        Assert::assertGreaterThan(0, $newId, 'the zone must still be created');

        global $wpdb;
        $classId = $wpdb->get_var($wpdb->prepare("SELECT shipping_class_id FROM {$wpdb->prefix}fct_shipping_zones WHERE id = %d", $newId));
        Assert::assertNull($classId, 'the created zone must be general, not linked to the sent class');
    }

    /**
     * Spec: Cases/Functional/shipping.md [18]
     *   "Creating a Whole World zone with a shipping_class_id is rejected when a general Whole World zone exists, because the class is ignored."
     */
    public function wholeWorldZoneWithClassIdIsRejectedWhenGeneralOneExists(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $class = (new ShippingClassFactory())->create(['name' => 'ClassForWW-18']);
        (new ShippingZoneFactory())->create(['name' => 'General-WW-18', 'region' => 'all', 'shipping_class_id' => null]);

        [, $body] = $I->post('/fluent-cart/v2/shipping/zones', [
            'name'              => 'Class-WW-18',
            'region'            => 'all',
            'shipping_class_id' => (int) $class->id,
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertArrayHasKey('region', (array) $body, 'validation payload must surface the region failure. Body: ' . wp_json_encode($body));

        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_shipping_zones WHERE region = 'all'");
        Assert::assertSame(1, $count, 'still only one Whole World zone exists after rejection');
    }

    /**
     * Spec: Cases/Functional/shipping.md [19]
     *   "Creating a zone with a name longer than 192 characters is rejected with a clear error."
     */
    public function creatingZoneWithNameLongerThan192CharsIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $longName = str_repeat('a', 193);

        [, $body] = $I->post('/fluent-cart/v2/shipping/zones', [
            'name'   => $longName,
            'region' => 'US',
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertArrayHasKey('name', (array) $body, 'validation payload must key the failure under name. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('maxLength', (array) ($body['name'] ?? []), 'name length rejection must surface the maxLength rule key. Body: ' . wp_json_encode($body));

        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_shipping_zones");
        Assert::assertSame(0, $count, 'no zone row may be created when name length validation fails');
    }

    /**
     * Spec: Cases/Functional/shipping.md [20]
     *   "A zone name containing HTML or script tags is stored as sanitized plain text."
     */
    public function zoneNameContainingHtmlTagsIsStoredSanitized(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/shipping/zones', [
            'name'   => 'Hostile<script>alert(1)</script>Zone',
            'region' => 'US',
        ]);

        $I->seeResponseCodeIs(200);
        $newId = (int) ($body['shipping_zone']['id'] ?? 0);
        Assert::assertGreaterThan(0, $newId, 'sanitized create must still succeed');

        global $wpdb;
        $stored = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}fct_shipping_zones WHERE id = %d",
            $newId
        ));
        Assert::assertStringNotContainsString('<script>', $stored, 'stored zone name must not retain raw <script> tags');
        Assert::assertStringNotContainsString('</script>', $stored, 'stored zone name must not retain closing </script> tag');
        Assert::assertStringContainsString('Hostile', $stored, 'sanitized name must preserve the non-tag plain text');
    }

    /**
     * Spec: Cases/Functional/shipping.md [21]
     *   "Creating a country-selection zone with a country-selection mode value other than \"include these countries\" or \"exclude these countries\" is rejected with a clear error."
     */
    public function creatingSelectionZoneWithInvalidSelectionModeIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/shipping/zones', [
            'name'   => 'BadModeZone',
            'region' => 'selection',
            'meta'   => [
                'countries'      => ['US'],
                'selection_type' => 'invalid_mode_xxx',
            ],
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertArrayHasKey('meta.selection_type', (array) $body, 'validation payload must surface failure on meta.selection_type. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('in', (array) ($body['meta.selection_type'] ?? []), 'invalid selection_type rejection must surface the "in" rule key. Body: ' . wp_json_encode($body));

        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_shipping_zones");
        Assert::assertSame(0, $count, 'no zone row may be created when selection_type validation fails');
    }
}

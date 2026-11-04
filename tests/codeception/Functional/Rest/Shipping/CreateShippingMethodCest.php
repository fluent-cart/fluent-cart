<?php

namespace Tests\Functional\Rest\Shipping;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ShippingZoneFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/shipping.md → "Creating a shipping method" section, items 59-69, 134.
 */
class CreateShippingMethodCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    private function seedZone(string $name = 'MethodZone'): \FluentCart\App\Models\ShippingZone
    {
        return (new ShippingZoneFactory())->create([
            'name'   => $name . '-' . uniqid('', true),
            'region' => 'US',
        ]);
    }

    /**
     * Spec: Cases/Functional/shipping.md [59]
     *   "Admin can add a shipping method to a zone with a title and a method type."
     */
    public function adminCanAddShippingMethodToZoneWithTitleAndType(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone = $this->seedZone('Zone59');

        [, $body] = $I->post('/fluent-cart/v2/shipping/methods', [
            'zone_id' => (int) $zone->id,
            'title'   => 'FlatRate59',
            'type'    => 'flat_rate',
            'states'  => [],
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fct_shipping_methods WHERE zone_id = %d AND title = %s",
                $zone->id,
                'FlatRate59'
            ),
            ARRAY_A
        );
        Assert::assertNotEmpty($row, 'created method row must exist for the zone+title');
        Assert::assertSame('flat_rate', (string) $row['type'], 'method type must persist');
        Assert::assertSame((int) $zone->id, (int) $row['zone_id'], 'method zone_id must persist');
    }

    /**
     * Spec: Cases/Functional/shipping.md [60]
     *   "Creating a shipping method without a zone is rejected with a clear error."
     */
    public function creatingMethodWithoutZoneIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/shipping/methods', [
            'title' => 'NoZone60',
            'type'  => 'flat_rate',
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertArrayHasKey('zone_id', (array) $body, 'validation payload must key the failure under zone_id. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('required', (array) ($body['zone_id'] ?? []), 'zone_id rejection must surface required rule. Body: ' . wp_json_encode($body));

        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_shipping_methods WHERE title = %s",
            'NoZone60'
        ));
        Assert::assertSame(0, $count, 'no method row may be created when zone_id validation fails');
    }

    /**
     * Spec: Cases/Functional/shipping.md [61]
     *   "Creating a shipping method without a title is rejected with a clear error."
     */
    public function creatingMethodWithoutTitleIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone = $this->seedZone('Zone61');

        [, $body] = $I->post('/fluent-cart/v2/shipping/methods', [
            'zone_id' => (int) $zone->id,
            'type'    => 'flat_rate',
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertArrayHasKey('title', (array) $body, 'validation payload must key the failure under title. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('required', (array) ($body['title'] ?? []), 'title rejection must surface required rule. Body: ' . wp_json_encode($body));

        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_shipping_methods WHERE zone_id = %d",
            $zone->id
        ));
        Assert::assertSame(0, $count, 'no method row may be created when title validation fails');
    }

    /**
     * Spec: Cases/Functional/shipping.md [62]
     *   "Creating a shipping method without a type is rejected with a clear error."
     */
    public function creatingMethodWithoutTypeIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone = $this->seedZone('Zone62');

        [, $body] = $I->post('/fluent-cart/v2/shipping/methods', [
            'zone_id' => (int) $zone->id,
            'title'   => 'NoType62',
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertArrayHasKey('type', (array) $body, 'validation payload must key the failure under type. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('required', (array) ($body['type'] ?? []), 'type rejection must surface required rule. Body: ' . wp_json_encode($body));

        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_shipping_methods WHERE zone_id = %d AND title = %s",
            $zone->id,
            'NoType62'
        ));
        Assert::assertSame(0, $count, 'no method row may be created when type validation fails');
    }

    /**
     * Spec: Cases/Functional/shipping.md [63]
     *   "Creating a shipping method with a title longer than 192 characters is rejected with a clear error."
     */
    public function creatingMethodWithTitleLongerThan192CharsIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone = $this->seedZone('Zone63');
        $longTitle = str_repeat('t', 193);

        [, $body] = $I->post('/fluent-cart/v2/shipping/methods', [
            'zone_id' => (int) $zone->id,
            'title'   => $longTitle,
            'type'    => 'flat_rate',
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertArrayHasKey('title', (array) $body, 'validation payload must key the failure under title. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('maxLength', (array) ($body['title'] ?? []), 'long-title rejection must surface maxLength rule. Body: ' . wp_json_encode($body));

        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_shipping_methods WHERE zone_id = %d",
            $zone->id
        ));
        Assert::assertSame(0, $count, 'no method row may be created when title length validation fails');
    }

    /**
     * Spec: Cases/Functional/shipping.md [64]
     *   "Admin can create a shipping method that is initially turned off."
     */
    public function adminCanCreateShippingMethodInitiallyTurnedOff(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone = $this->seedZone('Zone64');

        [, $body] = $I->post('/fluent-cart/v2/shipping/methods', [
            'zone_id'    => (int) $zone->id,
            'title'      => 'InitiallyOff64',
            'type'       => 'flat_rate',
            'is_enabled' => 0,
            'states'     => [],
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $isEnabled = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT is_enabled FROM {$wpdb->prefix}fct_shipping_methods WHERE zone_id = %d AND title = %s",
            $zone->id,
            'InitiallyOff64'
        ));
        Assert::assertSame(0, $isEnabled, 'method must persist with is_enabled=0');
    }

    /**
     * Spec: Cases/Functional/shipping.md [65]
     *   "Admin can create a shipping method that is initially turned on."
     */
    public function adminCanCreateShippingMethodInitiallyTurnedOn(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone = $this->seedZone('Zone65');

        [, $body] = $I->post('/fluent-cart/v2/shipping/methods', [
            'zone_id'    => (int) $zone->id,
            'title'      => 'InitiallyOn65',
            'type'       => 'flat_rate',
            'is_enabled' => 1,
            'states'     => [],
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $isEnabled = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT is_enabled FROM {$wpdb->prefix}fct_shipping_methods WHERE zone_id = %d AND title = %s",
            $zone->id,
            'InitiallyOn65'
        ));
        Assert::assertSame(1, $isEnabled, 'method must persist with is_enabled=1');
    }

    /**
     * Spec: Cases/Functional/shipping.md [67]
     *   "Admin can create a shipping method that applies only to specific states inside the zone's country."
     */
    public function adminCanCreateShippingMethodScopedToSpecificStates(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone = $this->seedZone('Zone67');

        [, $body] = $I->post('/fluent-cart/v2/shipping/methods', [
            'zone_id' => (int) $zone->id,
            'title'   => 'StateScoped67',
            'type'    => 'flat_rate',
            'states'  => ['CA', 'NY'],
        ]);

        $I->seeResponseCodeIs(200);

        $method = \FluentCart\App\Models\ShippingMethod::query()
            ->where('zone_id', (int) $zone->id)
            ->where('title', 'StateScoped67')
            ->first();
        Assert::assertNotNull($method, 'created state-scoped method must exist');
        Assert::assertSame(['CA', 'NY'], (array) $method->states, 'method states must persist as supplied array');
    }

    /**
     * Spec: Cases/Functional/shipping.md [68]
     *   "A shipping method title containing HTML or script tags is stored as sanitized plain text."
     */
    public function shippingMethodTitleContainingHtmlIsStoredSanitized(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone = $this->seedZone('Zone68');

        [, $body] = $I->post('/fluent-cart/v2/shipping/methods', [
            'zone_id' => (int) $zone->id,
            'title'   => 'HostileTitle<script>x()</script>68',
            'type'    => 'flat_rate',
            'states'  => [],
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $stored = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT title FROM {$wpdb->prefix}fct_shipping_methods WHERE zone_id = %d ORDER BY id DESC LIMIT 1",
            $zone->id
        ));
        Assert::assertStringNotContainsString('<script>', $stored, 'stored title must not retain raw <script> tag');
        Assert::assertStringNotContainsString('</script>', $stored, 'stored title must not retain closing </script> tag');
        Assert::assertStringContainsString('HostileTitle', $stored, 'sanitized title must preserve the plain-text part');
    }

    /**
     * Spec: Cases/Functional/shipping.md [69]
     *   "Admin can create a shipping method with weight-based pricing tiers, and each tier is stored with numeric minimum, maximum, and cost values."
     */
    public function adminCanCreateShippingMethodWithWeightBasedPricingTiers(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone = $this->seedZone('Zone69');

        [, $body] = $I->post('/fluent-cart/v2/shipping/methods', [
            'zone_id'  => (int) $zone->id,
            'title'    => 'WeightTiers69',
            'type'     => 'flat_rate',
            'states'   => [],
            'settings' => [
                'weight_tiers' => [
                    ['min' => '0',  'max' => '5',  'cost' => '2.50'],
                    ['min' => '5',  'max' => '20', 'cost' => '7.00'],
                ],
            ],
        ]);

        $I->seeResponseCodeIs(200);

        $method = \FluentCart\App\Models\ShippingMethod::query()
            ->where('zone_id', (int) $zone->id)
            ->where('title', 'WeightTiers69')
            ->first();
        Assert::assertNotNull($method, 'created weight-tier method must exist');
        $tiers = (array) ($method->settings['weight_tiers'] ?? []);
        Assert::assertCount(2, $tiers, 'two weight tiers must persist');

        foreach ([0, 1] as $i) {
            Assert::assertIsNumeric($tiers[$i]['min'] ?? null,  "tier " . ($i + 1) . " min must persist as a numeric (not string) value; spec demands numeric tiers");
            Assert::assertIsNumeric($tiers[$i]['max'] ?? null,  "tier " . ($i + 1) . " max must persist as a numeric value");
            Assert::assertIsNumeric($tiers[$i]['cost'] ?? null, "tier " . ($i + 1) . " cost must persist as a numeric value");
        }

        Assert::assertSame(0.0, (float) $tiers[0]['min'],  'tier 1 min must persist as numeric 0.0');
        Assert::assertSame(5.0, (float) $tiers[0]['max'],  'tier 1 max must persist as numeric 5.0');
        Assert::assertSame(2.5, (float) $tiers[0]['cost'], 'tier 1 cost must persist as numeric 2.5');

        Assert::assertSame(5.0,  (float) $tiers[1]['min'],  'tier 2 min must persist as numeric 5.0');
        Assert::assertSame(20.0, (float) $tiers[1]['max'],  'tier 2 max must persist as numeric 20.0');
        Assert::assertSame(7.0,  (float) $tiers[1]['cost'], 'tier 2 cost must persist as numeric 7.0');
    }

    /**
     * Spec: Cases/Functional/shipping.md [134]
     *   "Creating a shipping method with an empty tracking URL pattern stores no pattern, and keeps the other meta."
     */
    public function creatingMethodWithEmptyTrackingUrlPatternStoresNoPattern(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone = $this->seedZone('Zone134');

        $I->post('/fluent-cart/v2/shipping/methods', [
            'zone_id' => (int) $zone->id,
            'title'   => 'NoPattern134',
            'type'    => 'flat_rate',
            'states'  => [],
            'meta'    => ['description' => 'Two days', 'tracking_url_pattern' => ''],
        ]);
        $I->seeResponseCodeIs(200);

        $method = \FluentCart\App\Models\ShippingMethod::query()->where('title', 'NoPattern134')->first();
        Assert::assertNotNull($method, 'created method must exist');
        Assert::assertSame(['description' => 'Two days'], (array) $method->meta);
    }
}

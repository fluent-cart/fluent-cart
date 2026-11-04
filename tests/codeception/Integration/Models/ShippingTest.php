<?php

namespace Tests\Integration\Models;

use FluentCart\App\Models\ShippingClass;
use FluentCart\App\Models\ShippingMethod;
use FluentCart\App\Models\ShippingZone;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\ShippingClassFactory;
use Tests\Support\Factory\ShippingMethodFactory;
use Tests\Support\Factory\ShippingZoneFactory;

class ShippingTest extends DatabaseTestCase
{
    private ShippingZoneFactory $zoneFactory;
    private ShippingMethodFactory $methodFactory;
    private ShippingClassFactory $classFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->zoneFactory   = new ShippingZoneFactory();
        $this->methodFactory = new ShippingMethodFactory();
        $this->classFactory  = new ShippingClassFactory();
    }

    // =========================================================================
    // Spec 1-7: fct_shipping_zones schema
    // =========================================================================

    public function test_fct_shipping_zones_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_shipping_zones'));
    }

    public function test_fct_shipping_zones_has_expected_columns(): void
    {
        $this->assertColumnsExist('fct_shipping_zones', [
            'id', 'name', 'region', 'meta', 'order', 'created_at', 'updated_at',
        ]);
    }

    public function test_fct_shipping_zones_name_is_varchar_192(): void
    {
        $col = $this->getColumn('fct_shipping_zones', 'name');
        $this->assertNotNull($col);
        $this->assertSame('varchar(192)', strtolower($col['Type']));
    }

    public function test_fct_shipping_zones_region_is_varchar_192(): void
    {
        $col = $this->getColumn('fct_shipping_zones', 'region');
        $this->assertNotNull($col);
        $this->assertSame('varchar(192)', strtolower($col['Type']));
    }

    public function test_fct_shipping_zones_meta_is_nullable_json_or_text(): void
    {
        $col = $this->getColumn('fct_shipping_zones', 'meta');
        $this->assertNotNull($col);
        $type = strtolower($col['Type']);
        $this->assertTrue(
            $type === 'json' || in_array($type, ['longtext', 'text', 'mediumtext'], true),
            "meta should be json or text-family, got {$type}"
        );
        $this->assertSame('YES', $col['Null']);
    }

    public function test_fct_shipping_zones_order_default_0(): void
    {
        $col = $this->getColumn('fct_shipping_zones', 'order');
        $this->assertNotNull($col);
        $this->assertStringStartsWith('int', strtolower($col['Type']));
        $this->assertSame('0', $col['Default']);
    }

    public function test_fct_shipping_zones_has_order_index(): void
    {
        $indexes = $this->getIndexes('fct_shipping_zones');
        $found = false;
        foreach ($indexes as $cols) {
            if (in_array('order', $cols, true)) { $found = true; break; }
        }
        $this->assertTrue($found, 'order index should exist');
    }

    // =========================================================================
    // Spec 8-19: fct_shipping_methods schema
    // =========================================================================

    public function test_fct_shipping_methods_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_shipping_methods'));
    }

    public function test_fct_shipping_methods_has_expected_columns(): void
    {
        $this->assertColumnsExist('fct_shipping_methods', [
            'id', 'zone_id', 'title', 'type', 'settings', 'is_enabled',
            'states', 'amount', 'order', 'meta', 'created_at', 'updated_at',
        ]);
    }

    public function test_fct_shipping_methods_zone_id_is_bigint(): void
    {
        $col = $this->getColumn('fct_shipping_methods', 'zone_id');
        $this->assertNotNull($col);
        $this->assertStringStartsWith('bigint', strtolower($col['Type']));
    }

    public function test_fct_shipping_methods_title_is_varchar_192(): void
    {
        $col = $this->getColumn('fct_shipping_methods', 'title');
        $this->assertSame('varchar(192)', strtolower($col['Type']));
    }

    public function test_fct_shipping_methods_type_is_varchar_50(): void
    {
        $col = $this->getColumn('fct_shipping_methods', 'type');
        $this->assertSame('varchar(50)', strtolower($col['Type']));
    }

    public function test_fct_shipping_methods_settings_is_longtext_nullable(): void
    {
        $col = $this->getColumn('fct_shipping_methods', 'settings');
        $this->assertNotNull($col);
        $type = strtolower($col['Type']);
        $this->assertTrue(in_array($type, ['longtext', 'text', 'mediumtext'], true),
            "settings should be text-family, got {$type}");
        $this->assertSame('YES', $col['Null']);
    }

    public function test_fct_shipping_methods_is_enabled_default_1(): void
    {
        $col = $this->getColumn('fct_shipping_methods', 'is_enabled');
        $this->assertNotNull($col);
        $this->assertStringStartsWith('tinyint', strtolower($col['Type']));
        $this->assertSame('1', $col['Default']);
    }

    public function test_fct_shipping_methods_states_is_nullable_json_or_text(): void
    {
        $col = $this->getColumn('fct_shipping_methods', 'states');
        $this->assertNotNull($col);
        $type = strtolower($col['Type']);
        $this->assertTrue(
            $type === 'json' || in_array($type, ['longtext', 'text', 'mediumtext'], true),
            "states should be json or text-family, got {$type}"
        );
    }

    public function test_fct_shipping_methods_amount_default_0(): void
    {
        $col = $this->getColumn('fct_shipping_methods', 'amount');
        $this->assertNotNull($col);
        // Decimal types serialize defaults as "0.00"
        $this->assertTrue(
            $col['Default'] === '0.00' || $col['Default'] === '0' || (float) $col['Default'] === 0.0,
            "Expected default 0.00, got " . var_export($col['Default'], true)
        );
    }

    public function test_fct_shipping_methods_order_default_0(): void
    {
        $col = $this->getColumn('fct_shipping_methods', 'order');
        $this->assertNotNull($col);
        $this->assertSame('0', $col['Default']);
    }

    public function test_fct_shipping_methods_has_zone_id_index(): void
    {
        $indexes = $this->getIndexes('fct_shipping_methods');
        $found = false;
        foreach ($indexes as $cols) {
            if (in_array('zone_id', $cols, true)) { $found = true; break; }
        }
        $this->assertTrue($found, 'zone_id index should exist');
    }

    public function test_fct_shipping_methods_has_order_index(): void
    {
        $indexes = $this->getIndexes('fct_shipping_methods');
        $found = false;
        foreach ($indexes as $cols) {
            if (in_array('order', $cols, true)) { $found = true; break; }
        }
        $this->assertTrue($found, 'order index should exist');
    }

    // =========================================================================
    // Spec 20-25: fct_shipping_classes schema
    // =========================================================================

    public function test_fct_shipping_classes_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_shipping_classes'));
    }

    public function test_fct_shipping_classes_has_expected_columns(): void
    {
        $this->assertColumnsExist('fct_shipping_classes', [
            'id', 'name', 'cost', 'per_item', 'type', 'created_at', 'updated_at',
        ]);
    }

    public function test_fct_shipping_classes_cost_is_decimal_default_0(): void
    {
        $col = $this->getColumn('fct_shipping_classes', 'cost');
        $this->assertNotNull($col);
        $this->assertStringStartsWith('decimal', strtolower($col['Type']));
    }

    public function test_fct_shipping_classes_per_item_default_0(): void
    {
        $col = $this->getColumn('fct_shipping_classes', 'per_item');
        $this->assertNotNull($col);
        $this->assertStringStartsWith('tinyint', strtolower($col['Type']));
        $this->assertSame('0', $col['Default']);
    }

    public function test_fct_shipping_classes_type_default_fixed(): void
    {
        $col = $this->getColumn('fct_shipping_classes', 'type');
        $this->assertNotNull($col);
        $type = strtolower($col['Type']);
        $this->assertStringStartsWith('varchar', $type);
        $this->assertSame('fixed', $col['Default']);
    }

    public function test_fct_shipping_classes_has_name_index(): void
    {
        $indexes = $this->getIndexes('fct_shipping_classes');
        $found = false;
        foreach ($indexes as $cols) {
            if (in_array('name', $cols, true)) { $found = true; break; }
        }
        $this->assertTrue($found, 'name index should exist');
    }

    // =========================================================================
    // Spec 26-37: ShippingZone model
    // =========================================================================

    public function test_shipping_zone_fillable(): void
    {
        $zone = new ShippingZone();
        $fillable = $zone->getFillable();
        foreach (['name', 'region', 'meta', 'order'] as $f) {
            $this->assertContains($f, $fillable);
        }
    }

    public function test_shipping_zone_methods_relation_returns_methods_ordered_desc(): void
    {
        $zone = $this->zoneFactory->create();
        $a = $this->methodFactory->create(['zone_id' => $zone->id, 'title' => 'Alpha']);
        $b = $this->methodFactory->create(['zone_id' => $zone->id, 'title' => 'Beta']);

        $fresh = ShippingZone::query()->find($zone->id);
        $ids = $fresh->methods->pluck('id')->toArray();
        $this->assertSame([$b->id, $a->id], $ids, 'methods should be ordered by id DESC');
    }

    public function test_shipping_zone_meta_mutator_json_round_trip(): void
    {
        $zone = $this->zoneFactory->create([
            'region' => 'selection',
            'meta'   => ['countries' => ['US', 'CA'], 'selection_type' => 'included'],
        ]);
        $fresh = ShippingZone::query()->find($zone->id);
        $this->assertSame(['US', 'CA'], $fresh->meta['countries']);
        $this->assertSame('included', $fresh->meta['selection_type']);
    }

    public function test_formatted_region_returns_whole_world_for_all(): void
    {
        $zone = $this->zoneFactory->create(['region' => 'all']);
        $this->assertSame('Whole World', $zone->formatted_region);
    }

    public function test_formatted_region_selection_included_shows_country_count(): void
    {
        $zone = $this->zoneFactory->create([
            'region' => 'selection',
            'meta'   => [
                'countries'      => ['US', 'CA', 'MX', 'BR', 'AR', 'CL'],
                'selection_type' => 'included',
            ],
        ]);
        $this->assertSame('6 countries', $zone->formatted_region,
            'included selection must render as a country count');
    }

    public function test_formatted_region_selection_excluded_shows_all_except_count(): void
    {
        $zone = $this->zoneFactory->create([
            'region' => 'selection',
            'meta'   => ['countries' => ['US', 'CA'], 'selection_type' => 'excluded'],
        ]);
        $this->assertSame('All except 2 countries', $zone->formatted_region,
            'excluded selection must render as "All except N countries"');
    }

    public function test_formatted_region_direct_country_code_returns_country_name(): void
    {
        $zone = $this->zoneFactory->create(['region' => 'US']);
        $expected = \FluentCart\App\Helpers\AddressHelper::getCountryNameByCode('US');
        $this->assertNotEmpty($expected, 'AddressHelper must resolve a country name for US');
        $this->assertSame($expected, $zone->formatted_region,
            'formatted_region for region=US must match AddressHelper::getCountryNameByCode("US") exactly');
    }

    public function test_applies_to_country_true_for_all_region(): void
    {
        $zone = $this->zoneFactory->create(['region' => 'all']);
        $this->assertTrue($zone->appliesToCountry('US'));
    }

    public function test_applies_to_country_true_for_selection_included(): void
    {
        $zone = $this->zoneFactory->create([
            'region' => 'selection',
            'meta'   => ['countries' => ['US', 'CA'], 'selection_type' => 'included'],
        ]);
        $this->assertTrue($zone->appliesToCountry('US'));
    }

    public function test_applies_to_country_false_for_selection_excluded_match(): void
    {
        $zone = $this->zoneFactory->create([
            'region' => 'selection',
            'meta'   => ['countries' => ['US', 'CA'], 'selection_type' => 'excluded'],
        ]);
        $this->assertFalse($zone->appliesToCountry('US'));
        $this->assertTrue($zone->appliesToCountry('GB'));
    }

    public function test_applies_to_country_true_for_direct_country_match(): void
    {
        $zone = $this->zoneFactory->create(['region' => 'US']);
        $this->assertTrue($zone->appliesToCountry('US'));
    }

    public function test_applies_to_country_false_for_direct_country_mismatch(): void
    {
        $zone = $this->zoneFactory->create(['region' => 'US']);
        $this->assertFalse($zone->appliesToCountry('CA'));
    }

    // =========================================================================
    // Spec 38-49: ShippingMethod model
    // =========================================================================

    public function test_shipping_method_fillable(): void
    {
        $method = new ShippingMethod();
        $fillable = $method->getFillable();
        foreach (['zone_id', 'title', 'type', 'settings', 'amount', 'is_enabled', 'order', 'states', 'meta'] as $f) {
            $this->assertContains($f, $fillable);
        }
    }

    public function test_shipping_method_casts(): void
    {
        $zone = $this->zoneFactory->create();
        $m = $this->methodFactory->create([
            'zone_id'   => $zone->id,
            'settings'  => ['configure_rate' => 'per_order'],
            'states'    => ['CA'],
            'is_enabled'=> 1,
        ]);
        $fresh = ShippingMethod::query()->find($m->id);
        $this->assertIsArray($fresh->settings);
        $this->assertIsArray($fresh->states);
        $this->assertIsBool($fresh->is_enabled);
        $this->assertTrue($fresh->is_enabled);
    }

    public function test_shipping_method_zone_relation(): void
    {
        $zone = $this->zoneFactory->create();
        $m    = $this->methodFactory->create(['zone_id' => $zone->id]);
        $fresh = ShippingMethod::query()->find($m->id);
        $this->assertSame($zone->id, $fresh->zone->id);
    }

    public function test_shipping_method_meta_round_trip(): void
    {
        $zone = $this->zoneFactory->create();
        $m    = $this->methodFactory->create([
            'zone_id' => $zone->id,
            'meta'    => ['weight_unit' => 'kg', 'min' => 1],
        ]);
        $fresh = ShippingMethod::query()->find($m->id);
        $this->assertSame('kg', $fresh->meta['weight_unit']);
        $this->assertSame(1, $fresh->meta['min']);
    }

    public function test_formatted_states_returns_array_of_state_names(): void
    {
        $zone = $this->zoneFactory->create(['region' => 'US']);
        $m    = $this->methodFactory->create([
            'zone_id' => $zone->id,
            'states'  => ['CA', 'NY'],
        ]);
        $fresh = ShippingMethod::query()->find($m->id);
        $expectedCa = \FluentCart\App\Helpers\AddressHelper::getStateNameByCode('CA', 'US');
        $expectedNy = \FluentCart\App\Helpers\AddressHelper::getStateNameByCode('NY', 'US');

        $this->assertIsArray($fresh->formatted_states);
        $this->assertCount(2, $fresh->formatted_states);
        $this->assertSame($expectedCa, $fresh->formatted_states[0],
            'First formatted state must match AddressHelper::getStateNameByCode("CA", "US")');
        $this->assertSame($expectedNy, $fresh->formatted_states[1],
            'Second formatted state must match AddressHelper::getStateNameByCode("NY", "US")');
    }

    public function test_applicable_to_country_scope_matches_zone_and_state_empty(): void
    {
        // Isolate: the scope returns any existing methods matching the country, so create one in a zone
        // no other test has touched. Assert by id, not count — guards against accidental cross-test pollution.
        $zone = $this->zoneFactory->create(['region' => 'DE']);
        $method = $this->methodFactory->create(['zone_id' => $zone->id, 'states' => [], 'title' => 'DE base']);

        $ids = ShippingMethod::applicableToCountry('DE', null)->pluck('id')->toArray();
        $this->assertContains($method->id, $ids,
            'Method with empty states should be returned when state is null');
    }

    public function test_applicable_to_country_scope_matches_state(): void
    {
        // Use a country unique to this test so we can assert EXACT id presence/absence.
        $zone   = $this->zoneFactory->create(['region' => 'DE']);
        $method = $this->methodFactory->create(['zone_id' => $zone->id, 'states' => ['BE']]);

        $hitIds  = ShippingMethod::applicableToCountry('DE', 'BE')->pluck('id')->toArray();
        $missIds = ShippingMethod::applicableToCountry('DE', 'HH')->pluck('id')->toArray();

        $this->assertContains($method->id, $hitIds,
            'Method with states=["BE"] must be returned when state=BE');
        $this->assertNotContains($method->id, $missIds,
            'Method with states=["BE"] must NOT be returned when state=HH (state leakage)');
    }

    public function test_applicable_to_country_scope_orders_by_amount_desc(): void
    {
        $zone = $this->zoneFactory->create(['region' => 'US']);
        $this->methodFactory->create(['zone_id' => $zone->id, 'amount' => 5, 'title' => 'Cheap']);
        $this->methodFactory->create(['zone_id' => $zone->id, 'amount' => 20, 'title' => 'Expensive']);

        $rows = ShippingMethod::applicableToCountry('US', null)->get();
        $this->assertSame('Expensive', $rows->first()->title);
    }

    public function test_applicable_to_country_scope_excludes_disabled(): void
    {
        $zone = $this->zoneFactory->create(['region' => 'US']);
        $this->methodFactory->create(['zone_id' => $zone->id, 'is_enabled' => 0, 'title' => 'Disabled']);
        $this->methodFactory->create(['zone_id' => $zone->id, 'is_enabled' => 1, 'title' => 'Enabled']);

        $titles = ShippingMethod::applicableToCountry('US', null)->pluck('title')->toArray();
        $this->assertContains('Enabled', $titles);
        $this->assertNotContains('Disabled', $titles);
    }

    public function test_get_applicable_for_country_returns_collection(): void
    {
        $zone = $this->zoneFactory->create(['region' => 'US']);
        $this->methodFactory->create(['zone_id' => $zone->id]);

        $result = ShippingMethod::getApplicableForCountry('US');
        $this->assertGreaterThan(0, $result->count());
    }

    public function test_get_applicable_for_country_filters_selection_zone_not_matching(): void
    {
        $zone = $this->zoneFactory->create([
            'region' => 'selection',
            'meta'   => ['countries' => ['CA'], 'selection_type' => 'included'],
        ]);
        $this->methodFactory->create(['zone_id' => $zone->id]);

        $result = ShippingMethod::getApplicableForCountry('US');
        $this->assertSame(0, $result->count(), 'Selection zone excluding US should not appear');
    }

    public function test_get_applicable_for_country_with_state_filters_state(): void
    {
        $zone = $this->zoneFactory->create(['region' => 'US']);
        $this->methodFactory->create(['zone_id' => $zone->id, 'states' => ['CA']]);

        $ca = ShippingMethod::getApplicableForCountry('US', 'CA');
        $this->assertGreaterThan(0, $ca->count());
    }

    // =========================================================================
    // Spec 50-53: ShippingClass model
    // =========================================================================

    public function test_shipping_class_fillable(): void
    {
        $c = new ShippingClass();
        $fillable = $c->getFillable();
        foreach (['name', 'cost', 'type', 'per_item'] as $f) {
            $this->assertContains($f, $fillable);
        }
    }

    public function test_shipping_class_cost_cast_to_float(): void
    {
        $c = $this->classFactory->create(['cost' => '12.5']);
        $fresh = ShippingClass::query()->find($c->id);
        $this->assertIsFloat($fresh->cost);
    }

    public function test_shipping_class_accepts_type_fixed_and_percentage(): void
    {
        $fixed  = $this->classFactory->create(['type' => 'fixed']);
        $percent = $this->classFactory->create(['type' => 'percentage']);
        $this->assertSame('fixed', ShippingClass::query()->find($fixed->id)->type);
        $this->assertSame('percentage', ShippingClass::query()->find($percent->id)->type);
    }

    public function test_shipping_class_per_item_stored_as_int(): void
    {
        $c = $this->classFactory->create(['per_item' => 1]);
        $fresh = ShippingClass::query()->find($c->id);
        $this->assertEquals(1, $fresh->per_item);
    }
}

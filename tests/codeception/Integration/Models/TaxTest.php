<?php

namespace Tests\Integration\Models;

use FluentCart\App\Models\OrderTaxRate;
use FluentCart\App\Models\TaxClass;
use FluentCart\App\Models\TaxRate;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTaxRateFactory;
use Tests\Support\Factory\TaxClassFactory;
use Tests\Support\Factory\TaxRateFactory;

class TaxTest extends DatabaseTestCase
{
    private TaxClassFactory $classFactory;
    private TaxRateFactory $rateFactory;
    private OrderTaxRateFactory $orderTaxFactory;
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->classFactory    = new TaxClassFactory();
        $this->rateFactory     = new TaxRateFactory();
        $this->orderTaxFactory = new OrderTaxRateFactory();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory    = new OrderFactory();
    }

    // =========================================================================
    // Spec 1-11: fct_tax_rates schema
    // =========================================================================

    public function test_fct_tax_rates_class_id_is_bigint_unsigned(): void
    {
        $col = $this->getColumn('fct_tax_rates', 'class_id');
        $this->assertNotNull($col);
        $type = strtolower($col['Type']);
        $this->assertStringStartsWith('bigint', $type);
        $this->assertStringContainsString('unsigned', $type);
    }

    public function test_fct_tax_rates_varchar_columns_are_exactly_45(): void
    {
        foreach (['country', 'state', 'city', 'name', 'rate', 'group'] as $column) {
            $col = $this->getColumn('fct_tax_rates', $column);
            $this->assertNotNull($col, "Column {$column} missing");
            $this->assertSame('varchar(45)', strtolower($col['Type']),
                "Column {$column} should be varchar(45), got {$col['Type']}");
        }
    }

    public function test_fct_tax_rates_postcode_is_exactly_text_nullable(): void
    {
        $col = $this->getColumn('fct_tax_rates', 'postcode');
        $this->assertNotNull($col);
        $this->assertSame('text', strtolower($col['Type']));
        $this->assertSame('YES', $col['Null']);
    }

    public function test_fct_tax_rates_priority_is_int_default_1(): void
    {
        $col = $this->getColumn('fct_tax_rates', 'priority');
        $this->assertNotNull($col);
        $this->assertStringStartsWith('int', strtolower($col['Type']));
        $this->assertSame('1', $col['Default']);
    }

    public function test_fct_tax_rates_is_compound_is_tinyint(): void
    {
        $col = $this->getColumn('fct_tax_rates', 'is_compound');
        $this->assertNotNull($col);
        $this->assertStringStartsWith('tinyint', strtolower($col['Type']));
    }

    public function test_fct_tax_rates_for_shipping_is_nullable_decimal(): void
    {
        // Tax redesign (#1521): for_shipping is a DECIMAL(10,2) override RATE
        // (NULL = inherit base rate), no longer a tinyint flag —
        // TaxRatesMigrator::modifyColumnIfExists('for_shipping', 'DECIMAL(10, 2) NULL DEFAULT NULL').
        $col = $this->getColumn('fct_tax_rates', 'for_shipping');
        $this->assertNotNull($col);
        $this->assertSame('decimal(10,2)', strtolower($col['Type']),
            'for_shipping is a nullable decimal override rate');
        $this->assertSame('YES', $col['Null']);
        $this->assertNull($col['Default']);
    }

    public function test_fct_tax_rates_for_order_is_tinyint_default_0(): void
    {
        $col = $this->getColumn('fct_tax_rates', 'for_order');
        $this->assertNotNull($col);
        $this->assertStringStartsWith('tinyint', strtolower($col['Type']));
        $this->assertSame('0', $col['Default']);
    }

    public function test_fct_tax_rates_has_class_id_index(): void
    {
        $indexes = $this->getIndexes('fct_tax_rates');
        $found = false;
        foreach ($indexes as $name => $cols) {
            if (in_array('class_id', $cols, true)) { $found = true; break; }
        }
        $this->assertTrue($found, 'class_id index should exist');
    }

    public function test_fct_tax_rates_has_priority_index(): void
    {
        $indexes = $this->getIndexes('fct_tax_rates');
        $found = false;
        foreach ($indexes as $name => $cols) {
            if (in_array('priority', $cols, true)) { $found = true; break; }
        }
        $this->assertTrue($found, 'priority index should exist');
    }

    // =========================================================================
    // Spec 12-17: fct_tax_classes schema
    // =========================================================================

    public function test_fct_tax_classes_title_is_exactly_varchar_192(): void
    {
        $col = $this->getColumn('fct_tax_classes', 'title');
        $this->assertNotNull($col);
        $this->assertSame('varchar(192)', strtolower($col['Type']));
    }

    public function test_fct_tax_classes_slug_is_varchar_100(): void
    {
        $col = $this->getColumn('fct_tax_classes', 'slug');
        $this->assertNotNull($col);
        $this->assertSame('varchar(100)', strtolower($col['Type']));
    }

    public function test_fct_tax_classes_slug_has_db_unique_index(): void
    {
        // Since 476191525 (2026-05-06): slug uniqueness is DB-enforced —
        // TaxClassesMigrator::addSlugUniqueIndex() adds a UNIQUE index after
        // deduplicateSlugs(); TaxClass::generateUniqueSlug() still pre-resolves
        // collisions at the application layer. A composite unique index
        // containing slug would NOT enforce slug uniqueness — require a
        // single-column UNIQUE index on exactly slug.
        global $wpdb;
        $fullTable = $this->fullTableName('fct_tax_classes');
        $rows = $wpdb->get_results(
            "SHOW INDEX FROM `{$fullTable}` WHERE Non_unique = 0 AND Key_name != 'PRIMARY'",
            ARRAY_A
        );
        $uniqueIndexColumns = [];
        foreach ($rows as $row) {
            $uniqueIndexColumns[$row['Key_name']][] = $row['Column_name'];
        }
        $this->assertContains(['slug'], array_values($uniqueIndexColumns),
            'fct_tax_classes carries a single-column UNIQUE index on slug (TaxClassesMigrator::addSlugUniqueIndex)');
    }

    public function test_fct_tax_classes_description_is_nullable_text_family(): void
    {
        $col = $this->getColumn('fct_tax_classes', 'description');
        $this->assertNotNull($col);
        $type = strtolower($col['Type']);
        $this->assertTrue(
            in_array($type, ['longtext', 'text', 'mediumtext'], true),
            "description should be a text-family column, got {$type}"
        );
        $this->assertSame('YES', $col['Null']);
    }

    public function test_fct_tax_classes_meta_is_nullable_json_or_text(): void
    {
        $col = $this->getColumn('fct_tax_classes', 'meta');
        $this->assertNotNull($col);
        $type = strtolower($col['Type']);
        $this->assertTrue(
            $type === 'json' || in_array($type, ['longtext', 'text', 'mediumtext'], true),
            "meta column type should be json or a text-family type, got {$type}"
        );
        $this->assertSame('YES', $col['Null']);
    }

    // =========================================================================
    // Spec 18-24: fct_order_tax_rate schema
    // =========================================================================

    public function test_fct_order_tax_rate_order_id_is_bigint_not_null(): void
    {
        $col = $this->getColumn('fct_order_tax_rate', 'order_id');
        $this->assertNotNull($col);
        $this->assertStringStartsWith('bigint', strtolower($col['Type']));
        $this->assertSame('NO', $col['Null']);
    }

    public function test_fct_order_tax_rate_tax_rate_id_allows_zero(): void
    {
        $class = $this->classFactory->create();
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $otr = $this->orderTaxFactory->create([
            'order_id'     => $order->id,
            'tax_rate_id'  => 0,
        ]);
        $this->assertEquals(0, $otr->tax_rate_id);
    }

    public function test_fct_order_tax_rate_tax_columns_nullable_bigint(): void
    {
        foreach (['shipping_tax', 'order_tax', 'total_tax'] as $col) {
            $column = $this->getColumn('fct_order_tax_rate', $col);
            $this->assertNotNull($column, "Column {$col} not found");
            $this->assertStringContainsString('bigint', strtolower($column['Type']));
            $this->assertSame('YES', $column['Null']);
        }
    }

    public function test_fct_order_tax_rate_meta_is_nullable_json_or_text(): void
    {
        $col = $this->getColumn('fct_order_tax_rate', 'meta');
        $this->assertNotNull($col);
        $type = strtolower($col['Type']);
        $this->assertTrue(
            $type === 'json' || in_array($type, ['longtext', 'text', 'mediumtext'], true),
            "meta column should be json or text-family, got {$type}"
        );
        $this->assertSame('YES', $col['Null']);
    }

    public function test_fct_order_tax_rate_filed_at_is_exactly_datetime_nullable(): void
    {
        $col = $this->getColumn('fct_order_tax_rate', 'filed_at');
        $this->assertNotNull($col);
        $this->assertSame('datetime', strtolower($col['Type']));
        $this->assertSame('YES', $col['Null']);
    }

    // =========================================================================
    // Spec 25-27: TaxRate model
    // =========================================================================

    public function test_tax_rate_fillable_includes_core_fields(): void
    {
        $rate = new TaxRate();
        $fillable = $rate->getFillable();
        foreach ([
            'class_id', 'country', 'state', 'postcode', 'city', 'rate',
            'name', 'group', 'priority', 'is_compound', 'for_shipping', 'for_order',
        ] as $field) {
            $this->assertContains($field, $fillable, "fillable missing {$field}");
        }
    }

    public function test_tax_rate_belongs_to_tax_class(): void
    {
        $class = $this->classFactory->create(['title' => 'Reduced Tax']);
        $rate  = $this->rateFactory->create(['class_id' => $class->id]);
        $fresh = TaxRate::query()->find($rate->id);
        $this->assertInstanceOf(TaxClass::class, $fresh->tax_class);
        $this->assertSame($class->id, $fresh->tax_class->id);
    }

    public function test_tax_rate_formatted_state_fallback_empty_string_when_no_state(): void
    {
        $class = $this->classFactory->create();
        $rate  = $this->rateFactory->create([
            'class_id' => $class->id,
            'country'  => 'US',
            'state'    => '',
        ]);
        $fresh = TaxRate::query()->find($rate->id);
        $this->assertSame('', $fresh->formatted_state);
    }

    // =========================================================================
    // Spec 28-32: TaxClass model
    // =========================================================================

    public function test_tax_class_fillable_includes_core_fields(): void
    {
        $class = new TaxClass();
        $fillable = $class->getFillable();
        foreach (['title', 'meta', 'slug'] as $field) {
            $this->assertContains($field, $fillable, "fillable missing {$field}");
        }
        // Tax redesign (#1521) dropped description from fillable — the
        // controller persists only title + meta; the column remains.
        $this->assertNotContains('description', $fillable,
            'description is intentionally not mass-assignable since the tax redesign');
    }

    public function test_tax_class_auto_generates_slug_from_title(): void
    {
        $class = TaxClass::query()->create([
            'title'       => 'Reduced VAT Zone',
            'description' => 'EU reduced VAT',
            'meta'        => [],
        ]);
        $this->assertSame('reduced-vat-zone', $class->slug);
    }

    public function test_tax_class_slug_collision_appends_numeric_suffix(): void
    {
        TaxClass::query()->create(['title' => 'Collision Class', 'description' => 'A', 'meta' => []]);
        $second = TaxClass::query()->create(['title' => 'Collision Class', 'description' => 'B', 'meta' => []]);
        $third  = TaxClass::query()->create(['title' => 'Collision Class', 'description' => 'C', 'meta' => []]);
        $this->assertSame('collision-class-2', $second->slug);
        $this->assertSame('collision-class-3', $third->slug);
    }

    public function test_tax_class_meta_round_trips_via_json(): void
    {
        $meta = ['priority' => 10, 'categories' => [1, 2, 3]];
        $class = TaxClass::query()->create([
            'title' => 'Meta Test',
            'description' => '',
            'meta'  => $meta,
        ]);
        $fresh = TaxClass::query()->find($class->id);
        $this->assertSame(10, $fresh->meta['priority']);
        $this->assertSame([1, 2, 3], $fresh->meta['categories']);
    }

    public function test_tax_class_update_title_regenerates_slug(): void
    {
        $class = TaxClass::query()->create(['title' => 'Original Title', 'description' => '', 'meta' => []]);
        $class->title = 'Renamed Title';
        $class->save();
        $fresh = TaxClass::query()->find($class->id);
        $this->assertSame('renamed-title', $fresh->slug);
    }

    // =========================================================================
    // Spec 33-37: OrderTaxRate model
    // =========================================================================

    public function test_order_tax_rate_fillable_includes_core_fields(): void
    {
        $m = new OrderTaxRate();
        $fillable = $m->getFillable();
        foreach (['order_id', 'tax_rate_id', 'shipping_tax', 'order_tax', 'total_tax', 'meta', 'filed_at'] as $field) {
            $this->assertContains($field, $fillable);
        }
    }

    public function test_order_tax_rate_belongs_to_order(): void
    {
        $customer = $this->customerFactory->create();
        $order    = $this->orderFactory->create(['customer_id' => $customer->id]);
        $otr      = $this->orderTaxFactory->create(['order_id' => $order->id]);
        $fresh    = OrderTaxRate::query()->find($otr->id);
        $this->assertNotNull($fresh->order);
        $this->assertSame($order->id, $fresh->order->id);
    }

    public function test_order_tax_rate_belongs_to_tax_rate(): void
    {
        $class    = $this->classFactory->create();
        $rate     = $this->rateFactory->create(['class_id' => $class->id]);
        $customer = $this->customerFactory->create();
        $order    = $this->orderFactory->create(['customer_id' => $customer->id]);
        $otr      = $this->orderTaxFactory->create([
            'order_id'    => $order->id,
            'tax_rate_id' => $rate->id,
        ]);
        $fresh = OrderTaxRate::query()->find($otr->id);
        $this->assertNotNull($fresh->tax_rate);
        $this->assertSame($rate->id, $fresh->tax_rate->id);
    }

    public function test_order_tax_rate_meta_round_trips(): void
    {
        $customer = $this->customerFactory->create();
        $order    = $this->orderFactory->create(['customer_id' => $customer->id]);
        $otr      = $this->orderTaxFactory->create([
            'order_id' => $order->id,
            'meta'     => ['tax_config' => ['inclusive' => true], 'tax_country' => 'DE'],
        ]);
        $fresh = OrderTaxRate::query()->find($otr->id);
        $this->assertTrue($fresh->meta['tax_config']['inclusive']);
        $this->assertSame('DE', $fresh->meta['tax_country']);
    }

    public function test_order_tax_rate_scope_valid_order_filters_paid_orders(): void
    {
        // Since 5215e9dad (#1993): scopeValidOrder() filters on
        // payment_status IN (paid, partially_paid), not order status.
        $customer = $this->customerFactory->create();
        $paid     = $this->orderFactory->create(['customer_id' => $customer->id, 'payment_status' => 'paid']);
        $partial  = $this->orderFactory->create(['customer_id' => $customer->id, 'payment_status' => 'partially_paid']);
        $pending  = $this->orderFactory->create(['customer_id' => $customer->id, 'payment_status' => 'pending']);
        $paidRow    = $this->orderTaxFactory->create(['order_id' => $paid->id]);
        $partialRow = $this->orderTaxFactory->create(['order_id' => $partial->id]);
        $this->orderTaxFactory->create(['order_id' => $pending->id]);

        $rows = OrderTaxRate::query()->validOrder()->get();
        $ids  = $rows->pluck('id')->all();
        $this->assertContains($paidRow->id, $ids,
            'a paid order\'s tax row is included by validOrder()');
        $this->assertContains($partialRow->id, $ids,
            'a partially_paid order\'s tax row is included by validOrder()');
        foreach ($rows as $r) {
            $this->assertContains($r->order->payment_status, ['paid', 'partially_paid'],
                'validOrder() only yields paid / partially_paid orders');
        }
    }
}

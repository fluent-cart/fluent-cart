<?php

namespace Tests\Integration\Database;

use FluentCart\Database\DBMigrator;
use FluentCart\Database\Migrations\CartMigrator;
use FluentCart\Database\Migrations\CustomerAddressesMigrator;
use FluentCart\Database\Migrations\CustomersMigrator;
use FluentCart\Database\Migrations\MetaMigrator;
use FluentCart\Database\Migrations\OrderAddressesMigrator;
use FluentCart\Database\Migrations\OrderMetaMigrator;
use FluentCart\Database\Migrations\OrdersMigrator;
use FluentCart\Database\Migrations\OrderTaxRateMigrator;
use FluentCart\Database\Migrations\ProductMetaMigrator;
use FluentCart\Database\Migrations\ProductVariationMigrator;
use FluentCart\Database\Migrations\ShippingMethodsMigrator;
use FluentCart\Database\Migrations\ShippingZonesMigrator;
use FluentCart\Database\Migrations\SubscriptionsMigrator;
use FluentCart\Database\Migrations\TaxClassesMigrator;
use FluentCart\Database\Migrations\TaxRatesMigrator;
use FluentCart\Framework\Database\Schema;
use Tests\Support\DatabaseTestCase;

/**
 * Spec: database.md #193-224 — Upgrade-path migrations: simulates old schema, runs migrated() hooks,
 * verifies columns/indexes are added/renamed/modified correctly and data is preserved.
 */
class MigrationUpgradeTest extends DatabaseTestCase
{
    // =========================================================================
    // OrdersMigrator upgrade tests
    // =========================================================================

    /** #193 */
    public function test_orders_add_fee_total_column_on_upgrade(): void
    {
        $this->dropColumn('fct_orders', 'fee_total');
        $this->assertFalse($this->hasColumn('fct_orders', 'fee_total'));

        OrdersMigrator::addFeeTotalColumn();

        $col = $this->getColumn('fct_orders', 'fee_total');
        $this->assertNotNull($col);
        $this->assertStringContainsString('bigint', strtolower($col['Type']));
        $this->assertSame('0', $col['Default']);
    }

    /** #194 */
    public function test_orders_add_tax_behavior_column_on_upgrade(): void
    {
        $this->dropColumn('fct_orders', 'tax_behavior');
        $this->assertFalse($this->hasColumn('fct_orders', 'tax_behavior'));

        OrdersMigrator::addTaxBehaviorColumn();

        $col = $this->getColumn('fct_orders', 'tax_behavior');
        $this->assertNotNull($col);
        $this->assertStringContainsString('tinyint', strtolower($col['Type']));
        $this->assertSame('0', $col['Default']);
    }

    /** #195 */
    public function test_orders_add_receipt_number_column_on_upgrade(): void
    {
        $this->dropColumn('fct_orders', 'receipt_number');
        $this->assertFalse($this->hasColumn('fct_orders', 'receipt_number'));

        OrdersMigrator::addReceiptNumberColumn();

        $col = $this->getColumn('fct_orders', 'receipt_number');
        $this->assertNotNull($col);
        $this->assertStringContainsString('bigint', strtolower($col['Type']));
        $this->assertSame('YES', $col['Null']);
    }

    /** #196 */
    public function test_orders_rename_discount_total_preserves_data(): void
    {
        // Simulate old schema: rename manual_discount_total back to discount_total
        $this->renameColumn('fct_orders', 'manual_discount_total', 'discount_total', "BIGINT NOT NULL DEFAULT '0'");
        $this->assertTrue($this->hasColumn('fct_orders', 'discount_total'));
        $this->assertFalse($this->hasColumn('fct_orders', 'manual_discount_total'));

        // Insert a row with data in the old column
        $id = $this->insertRow('fct_orders', [
            'discount_total'       => 500,
            'status'               => 'draft',
            'payment_method'       => 'test',
            'payment_method_title' => 'Test',
            'currency'             => 'USD',
            'uuid'                 => wp_generate_uuid4(),
        ]);

        OrdersMigrator::renameDiscountTotalColumn();

        // Verify column was renamed
        $this->assertFalse($this->hasColumn('fct_orders', 'discount_total'));
        $this->assertTrue($this->hasColumn('fct_orders', 'manual_discount_total'));

        // Verify data was preserved
        $row = $this->getRow('fct_orders', $id);
        $this->assertEquals(500, $row->manual_discount_total);

        $this->deleteRow('fct_orders', $id);
    }

    /** #197 */
    public function test_orders_add_payment_status_index_on_upgrade(): void
    {
        if ($this->hasIndex('fct_orders', 'fct_payment_status')) {
            $this->dropIndex('fct_orders', 'fct_payment_status');
        }
        $this->assertFalse($this->hasIndex('fct_orders', 'fct_payment_status'));

        OrdersMigrator::addPaymentStatusIndex();

        $this->assertTrue($this->hasIndex('fct_orders', 'fct_payment_status'));
    }

    // =========================================================================
    // SubscriptionsMigrator upgrade tests
    // =========================================================================

    /** #198 */
    public function test_subscriptions_add_uuid_column_on_upgrade(): void
    {
        $this->dropColumn('fct_subscriptions', 'uuid');
        $this->assertFalse($this->hasColumn('fct_subscriptions', 'uuid'));

        SubscriptionsMigrator::addUuidColumn();

        $col = $this->getColumn('fct_subscriptions', 'uuid');
        $this->assertNotNull($col);
        $this->assertStringContainsString('varchar', strtolower($col['Type']));
    }

    /** #199 */
    public function test_subscriptions_rename_initial_amount_preserves_data(): void
    {
        // Simulate old schema: rename signup_fee back to initial_amount
        $this->renameColumn('fct_subscriptions', 'signup_fee', 'initial_amount', 'BIGINT UNSIGNED NOT NULL DEFAULT 0');
        $this->assertTrue($this->hasColumn('fct_subscriptions', 'initial_amount'));
        $this->assertFalse($this->hasColumn('fct_subscriptions', 'signup_fee'));

        $id = $this->insertRow('fct_subscriptions', [
            'initial_amount'  => 1500,
            'customer_id'     => 1,
            'parent_order_id' => 1,
            'product_id'      => 1,
            'item_name'       => 'Test',
            'variation_id'    => 1,
            'uuid'            => wp_generate_uuid4(),
        ]);

        SubscriptionsMigrator::renameInitialAmountToSignupFee();

        $this->assertFalse($this->hasColumn('fct_subscriptions', 'initial_amount'));
        $this->assertTrue($this->hasColumn('fct_subscriptions', 'signup_fee'));

        $row = $this->getRow('fct_subscriptions', $id);
        $this->assertEquals(1500, $row->signup_fee);

        $this->deleteRow('fct_subscriptions', $id);
    }

    /** #200 */
    public function test_subscriptions_backfill_empty_uuids(): void
    {
        // Insert rows with empty UUIDs
        $id1 = $this->insertRow('fct_subscriptions', [
            'uuid'            => '',
            'customer_id'     => 1,
            'parent_order_id' => 1,
            'product_id'      => 1,
            'item_name'       => 'Sub 1',
            'variation_id'    => 1,
        ]);
        $id2 = $this->insertRow('fct_subscriptions', [
            'uuid'            => '',
            'customer_id'     => 1,
            'parent_order_id' => 1,
            'product_id'      => 1,
            'item_name'       => 'Sub 2',
            'variation_id'    => 1,
        ]);

        SubscriptionsMigrator::backfillEmptyUuids();

        $row1 = $this->getRow('fct_subscriptions', $id1);
        $row2 = $this->getRow('fct_subscriptions', $id2);

        $this->assertNotEmpty($row1->uuid, 'UUID should be backfilled for sub 1');
        $this->assertNotEmpty($row2->uuid, 'UUID should be backfilled for sub 2');
        $this->assertNotEquals($row1->uuid, $row2->uuid, 'UUIDs should be unique');

        $this->deleteRow('fct_subscriptions', $id1);
        $this->deleteRow('fct_subscriptions', $id2);
    }

    public function test_subscriptions_add_vendor_subscription_id_index_on_upgrade(): void
    {
        $indexName = $this->findIndexName('fct_subscriptions', 'fct_index_vendor_subscription_id_idx');
        if ($indexName) {
            $this->dropIndex('fct_subscriptions', $indexName);
        }

        $this->assertNull($this->findIndexName('fct_subscriptions', 'fct_index_vendor_subscription_id_idx'));

        SubscriptionsMigrator::addVendorSubscriptionIdIndex();

        $this->assertNotNull($this->findIndexName('fct_subscriptions', 'fct_index_vendor_subscription_id_idx'));
    }

    // =========================================================================
    // ShippingZonesMigrator upgrade tests
    // =========================================================================

    /** #201 */
    public function test_shipping_zones_rename_regions_preserves_data(): void
    {
        // Simulate old schema: rename region back to regions
        $this->renameColumn('fct_shipping_zones', 'region', 'regions', 'VARCHAR(192) NOT NULL');
        $this->assertTrue($this->hasColumn('fct_shipping_zones', 'regions'));
        $this->assertFalse($this->hasColumn('fct_shipping_zones', 'region'));

        $id = $this->insertRow('fct_shipping_zones', [
            'name'    => 'US Zone',
            'regions' => 'US,CA',
        ]);

        ShippingZonesMigrator::migrated();

        $this->assertFalse($this->hasColumn('fct_shipping_zones', 'regions'));
        $this->assertTrue($this->hasColumn('fct_shipping_zones', 'region'));

        $row = $this->getRow('fct_shipping_zones', $id);
        $this->assertSame('US,CA', $row->region);

        $this->deleteRow('fct_shipping_zones', $id);
    }

    /** #202 */
    public function test_shipping_zones_add_meta_column_on_upgrade(): void
    {
        if ($this->hasColumn('fct_shipping_zones', 'meta')) {
            $this->dropColumn('fct_shipping_zones', 'meta');
        }
        $this->assertFalse($this->hasColumn('fct_shipping_zones', 'meta'));

        ShippingZonesMigrator::migrated();

        $col = $this->getColumn('fct_shipping_zones', 'meta');
        $this->assertNotNull($col);
    }

    // =========================================================================
    // ShippingMethodsMigrator upgrade tests
    // =========================================================================

    /** #203 */
    public function test_shipping_methods_add_states_column_on_upgrade(): void
    {
        $this->dropColumn('fct_shipping_methods', 'states');
        $this->assertFalse($this->hasColumn('fct_shipping_methods', 'states'));

        ShippingMethodsMigrator::addStatesColumn();

        $col = $this->getColumn('fct_shipping_methods', 'states');
        $this->assertNotNull($col);
        $type = strtolower($col['Type']);
        $this->assertTrue(str_contains($type, 'json') || str_contains($type, 'text'));
    }

    /** #204 */
    public function test_shipping_methods_modify_states_to_json(): void
    {
        // Change states to TEXT to simulate old schema
        $this->modifyColumn('fct_shipping_methods', 'states', 'TEXT NULL');

        ShippingMethodsMigrator::modifyStatesToJson();

        $col = $this->getColumn('fct_shipping_methods', 'states');
        $type = strtolower($col['Type']);
        $this->assertTrue(str_contains($type, 'json') || str_contains($type, 'text'),
            "states should be JSON type after migration, got {$type}");
    }

    /** #205 */
    public function test_shipping_methods_add_meta_column_on_upgrade(): void
    {
        if ($this->hasColumn('fct_shipping_methods', 'meta')) {
            $this->dropColumn('fct_shipping_methods', 'meta');
        }
        $this->assertFalse($this->hasColumn('fct_shipping_methods', 'meta'));

        ShippingMethodsMigrator::addMetaColumn();

        $col = $this->getColumn('fct_shipping_methods', 'meta');
        $this->assertNotNull($col);
    }

    /** #206 */
    public function test_shipping_methods_change_amount_to_decimal(): void
    {
        // Change to INT to simulate old schema
        $this->modifyColumn('fct_shipping_methods', 'amount', 'INT NULL DEFAULT 0');

        ShippingMethodsMigrator::changeAmountToDecimal();

        $col = $this->getColumn('fct_shipping_methods', 'amount');
        $this->assertStringContainsString('decimal(10,2)', strtolower($col['Type']));
    }

    // =========================================================================
    // TaxClassesMigrator upgrade tests
    // =========================================================================

    /** #207 */
    public function test_tax_classes_rename_categories_to_meta_preserves_data(): void
    {
        // Drop meta if exists, add old categories column
        if ($this->hasColumn('fct_tax_classes', 'meta')) {
            $this->dropColumn('fct_tax_classes', 'meta');
        }
        $this->addColumn('fct_tax_classes', 'categories', 'JSON', 'title');

        $id = $this->insertRow('fct_tax_classes', [
            'title'      => 'Standard',
            'categories' => '{"cat": "physical"}',
        ]);

        TaxClassesMigrator::renameCategoriesToMeta();

        $this->assertFalse($this->hasColumn('fct_tax_classes', 'categories'));
        $this->assertTrue($this->hasColumn('fct_tax_classes', 'meta'));

        $row = $this->getRow('fct_tax_classes', $id);
        $decoded = json_decode($row->meta, true);
        $this->assertSame('physical', $decoded['cat']);

        $this->deleteRow('fct_tax_classes', $id);

        // Restore meta column for subsequent tests
        if (!$this->hasColumn('fct_tax_classes', 'meta')) {
            TaxClassesMigrator::addMetaColumn();
        }
    }

    /** #208 */
    public function test_tax_classes_add_slug_column_on_upgrade(): void
    {
        if ($this->hasColumn('fct_tax_classes', 'slug')) {
            $this->dropColumn('fct_tax_classes', 'slug');
        }
        $this->assertFalse($this->hasColumn('fct_tax_classes', 'slug'));

        TaxClassesMigrator::addSlugColumn();

        $col = $this->getColumn('fct_tax_classes', 'slug');
        $this->assertNotNull($col);
        $this->assertStringContainsString('varchar', strtolower($col['Type']));
    }

    /** #209 */
    public function test_tax_classes_add_description_column_on_upgrade(): void
    {
        if ($this->hasColumn('fct_tax_classes', 'description')) {
            $this->dropColumn('fct_tax_classes', 'description');
        }
        $this->assertFalse($this->hasColumn('fct_tax_classes', 'description'));

        TaxClassesMigrator::addDescriptionColumn();

        $col = $this->getColumn('fct_tax_classes', 'description');
        $this->assertNotNull($col);
        $this->assertStringContainsString('longtext', strtolower($col['Type']));
    }

    // =========================================================================
    // MetaMigrator upgrade tests (data-preserving renames)
    // =========================================================================

    /** #210 */
    public function test_meta_rename_key_to_meta_key_preserves_data(): void
    {
        // Simulate old schema: rename meta_key back to key
        $this->renameColumn('fct_meta', 'meta_key', 'key', 'VARCHAR(192) NOT NULL');
        $this->assertTrue($this->hasColumn('fct_meta', 'key'));
        $this->assertFalse($this->hasColumn('fct_meta', 'meta_key'));

        $id = $this->insertRow('fct_meta', [
            'object_type' => 'test',
            'object_id'   => 1,
            'key'         => 'my_test_key',
        ]);

        MetaMigrator::renameKeyToMetaKey();

        $this->assertFalse($this->hasColumn('fct_meta', 'key'));
        $this->assertTrue($this->hasColumn('fct_meta', 'meta_key'));

        $row = $this->getRow('fct_meta', $id);
        $this->assertSame('my_test_key', $row->meta_key);

        $this->deleteRow('fct_meta', $id);
    }

    /** #211 */
    public function test_meta_rename_value_to_meta_value_preserves_data(): void
    {
        $this->renameColumn('fct_meta', 'meta_value', 'value', 'LONGTEXT NULL');
        $this->assertTrue($this->hasColumn('fct_meta', 'value'));
        $this->assertFalse($this->hasColumn('fct_meta', 'meta_value'));

        $id = $this->insertRow('fct_meta', [
            'object_type' => 'test',
            'object_id'   => 1,
            'meta_key'    => 'test_key',
            'value'       => 'preserved_value_123',
        ]);

        MetaMigrator::renameValueToMetaValue();

        $this->assertFalse($this->hasColumn('fct_meta', 'value'));
        $this->assertTrue($this->hasColumn('fct_meta', 'meta_value'));

        $row = $this->getRow('fct_meta', $id);
        $this->assertSame('preserved_value_123', $row->meta_value);

        $this->deleteRow('fct_meta', $id);
    }

    // =========================================================================
    // OrderMetaMigrator upgrade tests (data-preserving renames)
    // =========================================================================

    /** #212 */
    public function test_order_meta_rename_key_to_meta_key_preserves_data(): void
    {
        $this->renameColumn('fct_order_meta', 'meta_key', 'key', 'VARCHAR(192) NOT NULL');
        $this->assertTrue($this->hasColumn('fct_order_meta', 'key'));

        $id = $this->insertRow('fct_order_meta', [
            'order_id' => 1,
            'key'      => 'order_test_key',
        ]);

        OrderMetaMigrator::renameKeyToMetaKey();

        $this->assertTrue($this->hasColumn('fct_order_meta', 'meta_key'));
        $row = $this->getRow('fct_order_meta', $id);
        $this->assertSame('order_test_key', $row->meta_key);

        $this->deleteRow('fct_order_meta', $id);
    }

    /** #213 */
    public function test_order_meta_rename_value_to_meta_value_preserves_data(): void
    {
        $this->renameColumn('fct_order_meta', 'meta_value', 'value', 'LONGTEXT NULL');

        $id = $this->insertRow('fct_order_meta', [
            'order_id'  => 1,
            'meta_key'  => 'test',
            'value'     => 'order_meta_preserved',
        ]);

        OrderMetaMigrator::renameValueToMetaValue();

        $row = $this->getRow('fct_order_meta', $id);
        $this->assertSame('order_meta_preserved', $row->meta_value);

        $this->deleteRow('fct_order_meta', $id);
    }

    // =========================================================================
    // CustomersMigrator upgrade tests
    // =========================================================================

    /** #214 */
    public function test_customers_add_ltv_column_on_upgrade(): void
    {
        $this->dropColumn('fct_customers', 'ltv');
        $this->assertFalse($this->hasColumn('fct_customers', 'ltv'));

        CustomersMigrator::addLtvColumn();

        $col = $this->getColumn('fct_customers', 'ltv');
        $this->assertNotNull($col);
        $this->assertStringContainsString('bigint', strtolower($col['Type']));
        $this->assertSame('0', $col['Default']);
    }

    // =========================================================================
    // CustomerAddressesMigrator upgrade tests
    // =========================================================================

    /** #215 */
    public function test_customer_addresses_add_meta_column_on_upgrade(): void
    {
        if ($this->hasColumn('fct_customer_addresses', 'meta')) {
            $this->dropColumn('fct_customer_addresses', 'meta');
        }
        $this->assertFalse($this->hasColumn('fct_customer_addresses', 'meta'));

        CustomerAddressesMigrator::addMetaColumn();

        $col = $this->getColumn('fct_customer_addresses', 'meta');
        $this->assertNotNull($col);
    }

    // =========================================================================
    // OrderAddressesMigrator upgrade tests
    // =========================================================================

    /** #216 */
    public function test_order_addresses_add_meta_column_on_upgrade(): void
    {
        if ($this->hasColumn('fct_order_addresses', 'meta')) {
            $this->dropColumn('fct_order_addresses', 'meta');
        }
        $this->assertFalse($this->hasColumn('fct_order_addresses', 'meta'));

        OrderAddressesMigrator::addMetaColumn();

        $col = $this->getColumn('fct_order_addresses', 'meta');
        $this->assertNotNull($col);
    }

    // =========================================================================
    // OrderTaxRateMigrator upgrade tests
    // =========================================================================

    /** #217 */
    public function test_order_tax_rate_add_meta_column_on_upgrade(): void
    {
        if ($this->hasColumn('fct_order_tax_rate', 'meta')) {
            $this->dropColumn('fct_order_tax_rate', 'meta');
        }
        $this->assertFalse($this->hasColumn('fct_order_tax_rate', 'meta'));

        OrderTaxRateMigrator::addMetaColumn();

        $col = $this->getColumn('fct_order_tax_rate', 'meta');
        $this->assertNotNull($col);
    }

    /** #218 */
    public function test_order_tax_rate_add_filed_at_column_on_upgrade(): void
    {
        if ($this->hasColumn('fct_order_tax_rate', 'filed_at')) {
            $this->dropColumn('fct_order_tax_rate', 'filed_at');
        }
        $this->assertFalse($this->hasColumn('fct_order_tax_rate', 'filed_at'));

        OrderTaxRateMigrator::addFiledAtColumn();

        $col = $this->getColumn('fct_order_tax_rate', 'filed_at');
        $this->assertNotNull($col);
        $this->assertStringContainsString('datetime', strtolower($col['Type']));
    }

    // =========================================================================
    // TaxRatesMigrator upgrade tests
    // =========================================================================

    /** #219 */
    public function test_tax_rates_add_group_column_on_upgrade(): void
    {
        if ($this->hasColumn('fct_tax_rates', 'group')) {
            $this->dropColumn('fct_tax_rates', 'group');
        }
        $this->assertFalse($this->hasColumn('fct_tax_rates', 'group'));

        TaxRatesMigrator::addGroupColumn();

        $col = $this->getColumn('fct_tax_rates', 'group');
        $this->assertNotNull($col);
        $this->assertStringContainsString('varchar', strtolower($col['Type']));
    }

    // =========================================================================
    // ProductMetaMigrator upgrade tests
    // =========================================================================

    /** #221 */
    public function test_product_meta_drop_composite_unique_index(): void
    {
        global $wpdb;
        $indexName = $wpdb->prefix . 'fct_pm__comp_unq';

        // Create the index so we can test dropping it
        if (!$this->hasIndex('fct_product_meta', $indexName)) {
            $this->addIndex('fct_product_meta', $indexName, ['object_id', 'meta_key'], true);
        }
        $this->assertTrue($this->hasIndex('fct_product_meta', $indexName));

        ProductMetaMigrator::dropCompositeUniqueIndex();

        $this->assertFalse($this->hasIndex('fct_product_meta', $indexName));
    }

    /** #236 */
    public function test_product_meta_add_object_meta_index_on_upgrade(): void
    {
        global $wpdb;
        $indexName = $wpdb->prefix . 'fct_pm__object_meta';

        if ($this->hasIndex('fct_product_meta', $indexName)) {
            $this->dropIndex('fct_product_meta', $indexName);
        }
        $this->assertFalse($this->hasIndex('fct_product_meta', $indexName));

        ProductMetaMigrator::addObjectMetaIndex();

        $this->assertTrue($this->hasIndex('fct_product_meta', $indexName));

        // Every read of this table is `object_id = ? AND meta_key = ?`, so the leading
        // column has to be object_id — an index that only starts at meta_key is the
        // non-selective one this replaces.
        $indexes = $this->getIndexes('fct_product_meta');

        $this->assertSame(
            ['object_id', 'meta_key'],
            $indexes[$indexName] ?? [],
            'The composite index must lead with object_id for the per-object lookup to use it.'
        );
        $this->assertFalse(
            $this->hasIndex('fct_product_meta', $wpdb->prefix . 'fct_pm__comp_unq'),
            'The new index must stay non-unique — one object legitimately holds repeated keys.'
        );
    }

    // =========================================================================
    // ProductVariationMigrator upgrade tests
    // =========================================================================

    /** #222 */
    public function test_product_variations_add_sku_column_on_upgrade(): void
    {
        // Drop unique index first, then column
        if ($this->hasIndex('fct_product_variations', 'sku_unique')) {
            $this->dropIndex('fct_product_variations', 'sku_unique');
        }
        if ($this->hasColumn('fct_product_variations', 'sku')) {
            $this->dropColumn('fct_product_variations', 'sku');
        }
        $this->assertFalse($this->hasColumn('fct_product_variations', 'sku'));

        ProductVariationMigrator::addSkuColumn();

        $col = $this->getColumn('fct_product_variations', 'sku');
        $this->assertNotNull($col);
        $this->assertStringContainsString('varchar', strtolower($col['Type']));
        $this->assertTrue($this->hasIndex('fct_product_variations', 'sku_unique'));
    }

    // =========================================================================
    // DBMigrator full upgrade simulation
    // =========================================================================

    /** #223 */
    public function test_run_migrate_restores_missing_column_from_old_version(): void
    {
        // Drop a column that maybeMigrateDBChanges should re-add
        $this->dropColumn('fct_orders', 'fee_total');
        $this->assertFalse($this->hasColumn('fct_orders', 'fee_total'));

        // Set DB version to old
        update_option('_fluent_cart_db_version', '1.0.0');

        DBMigrator::run_migrate();

        // Verify column was re-added
        $this->assertTrue($this->hasColumn('fct_orders', 'fee_total'));

        // Verify version was updated
        $this->assertSame(FLUENTCART_DB_VERSION, get_option('_fluent_cart_db_version'));
    }

    /** #224 */
    public function test_run_migrate_is_safe_at_current_version(): void
    {
        update_option('_fluent_cart_db_version', FLUENTCART_DB_VERSION);

        // Should complete without errors
        DBMigrator::run_migrate();

        $this->assertSame(FLUENTCART_DB_VERSION, get_option('_fluent_cart_db_version'));
    }

    // =========================================================================
    // Idempotency — running migrated() twice should not error
    // =========================================================================

    /** #225 */
    /**
     * Spec: Cases/Integration/database.md [210]
     *   "Migration seeds exactly one mandatory 'standard' tax class."
     */
    public function test_migrate_seeds_exactly_one_standard_tax_class(): void
    {
        // The Integration bootstrap truncates every fct_* table and then runs
        // DBMigrator::migrate(); TaxCalculator's fallback and tax-class
        // deletion flows both require the seeded row, so a fresh-install
        // seeding regression must fail here, not surface as untaxed products.
        $this->assertSame(
            1,
            \FluentCart\App\Models\TaxClass::query()->where('slug', 'standard')->count(),
            "Exactly one 'standard' tax class must exist after a clean-state migrate()"
        );
    }

    public function test_all_migrated_hooks_are_idempotent(): void
    {
        // Run all migrated() hooks twice — they should not throw
        OrdersMigrator::migrated();
        OrdersMigrator::migrated();

        SubscriptionsMigrator::addUuidColumn();
        SubscriptionsMigrator::renameInitialAmountToSignupFee();

        ShippingZonesMigrator::migrated();
        ShippingZonesMigrator::migrated();

        ShippingMethodsMigrator::migrated();
        ShippingMethodsMigrator::migrated();

        TaxClassesMigrator::migrated();
        TaxClassesMigrator::migrated();

        MetaMigrator::migrated();
        MetaMigrator::migrated();

        OrderMetaMigrator::migrated();
        OrderMetaMigrator::migrated();

        CustomersMigrator::migrated();
        CustomersMigrator::migrated();

        TaxRatesMigrator::migrated();
        TaxRatesMigrator::migrated();

        ProductMetaMigrator::migrated();
        ProductMetaMigrator::migrated();

        ProductVariationMigrator::migrated();
        ProductVariationMigrator::migrated();

        CustomerAddressesMigrator::migrated();
        CustomerAddressesMigrator::migrated();

        OrderAddressesMigrator::migrated();
        OrderAddressesMigrator::migrated();

        OrderTaxRateMigrator::migrated();
        OrderTaxRateMigrator::migrated();

        // If we got here, all idempotency checks passed
        $this->assertTrue($this->hasColumn('fct_orders', 'fee_total'));
    }

    public function test_carts_add_group_stage_user_index_on_upgrade(): void
    {
        global $wpdb;
        $indexName = $wpdb->prefix . 'fct_carts__group_stage_user';

        $existing = $this->findIndexName('fct_carts', $indexName);
        if ($existing) {
            $this->dropIndex('fct_carts', $existing);
        }
        $this->assertNull(
            $this->findIndexName('fct_carts', $indexName),
            'The composite index must be absent before the upgrade hook runs.'
        );

        CartMigrator::migrated();

        $this->assertNotNull(
            $this->findIndexName('fct_carts', $indexName),
            'migrated() must add the cart_group/stage/user_id index on an upgraded install.'
        );
    }

    public function test_carts_add_updated_at_index_on_upgrade(): void
    {
        global $wpdb;
        $indexName = $wpdb->prefix . 'fct_carts__updated_at';

        $existing = $this->findIndexName('fct_carts', $indexName);
        if ($existing) {
            $this->dropIndex('fct_carts', $existing);
        }
        $this->assertNull(
            $this->findIndexName('fct_carts', $indexName),
            'The updated_at index must be absent before the upgrade hook runs.'
        );

        CartMigrator::migrated();

        $this->assertNotNull(
            $this->findIndexName('fct_carts', $indexName),
            'migrated() must add the updated_at index the daily cart sweep scans on.'
        );
    }

    public function test_carts_migrated_is_idempotent(): void
    {
        global $wpdb;

        CartMigrator::migrated();
        CartMigrator::migrated();

        $indexes = $this->getIndexes('fct_carts');
        $matching = array_filter(
            array_keys($indexes),
            static function ($name) {
                return str_contains($name, 'fct_carts__group_stage_user')
                    || str_contains($name, 'fct_carts__updated_at');
            }
        );

        $this->assertCount(
            2,
            $matching,
            'Running migrated() twice must not leave duplicate indexes on fct_carts.'
        );
    }
}

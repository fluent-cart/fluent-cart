<?php

namespace Tests\Integration\Database;

use Tests\Support\DatabaseTestCase;

/**
 * Spec: database.md #36-178 — Column structure, types, defaults, and indexes for all 35 tables.
 */
class TableSchemaTest extends DatabaseTestCase
{
    // =========================================================================
    // fct_orders (#36-40)
    // =========================================================================

    /** #36 */
    public function test_orders_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_orders', [
            'id', 'status', 'parent_id', 'receipt_number', 'invoice_no',
            'fulfillment_type', 'type', 'mode', 'shipping_status', 'customer_id',
            'payment_method', 'payment_status', 'payment_method_title', 'currency',
            'uuid', 'config', 'note', 'ip_address', 'completed_at', 'refunded_at',
            'created_at', 'updated_at',
        ]);
    }

    /** #37 */
    public function test_orders_monetary_columns_are_bigint(): void
    {
        $monetaryColumns = [
            'subtotal', 'discount_tax', 'manual_discount_total', 'coupon_discount_total',
            'shipping_tax', 'shipping_total', 'fee_total', 'tax_total',
            'total_amount', 'total_paid', 'total_refund',
        ];
        foreach ($monetaryColumns as $col) {
            $this->assertColumnType('fct_orders', $col, 'bigint');
        }
    }

    /** #38 */
    public function test_orders_has_key_defaults(): void
    {
        $this->assertColumnDefault('fct_orders', 'status', 'draft');
        $this->assertColumnDefault('fct_orders', 'type', 'payment');
        $this->assertColumnDefault('fct_orders', 'fulfillment_type', 'physical');
        $this->assertColumnType('fct_orders', 'mode', 'enum');
        $this->assertColumnDefault('fct_orders', 'mode', 'live');
    }

    /** #39 */
    public function test_orders_has_special_type_columns(): void
    {
        // Widened from decimal(12,4) to hold a real exchange rate
        // (dev-docs/multi-currency/02-database-schema.md)
        $this->assertColumnType('fct_orders', 'rate', 'decimal(24,12)');
        $this->assertColumnType('fct_orders', 'tax_behavior', 'tinyint');
        $this->assertColumnDefault('fct_orders', 'tax_behavior', '0');
        $this->assertColumnType('fct_orders', 'config', 'json');
    }

    /** #40 */
    public function test_orders_has_indexes(): void
    {
        $this->assertIndexExists('fct_orders', 'fct_ord_customer_id');
        $this->assertIndexExists('fct_orders', 'fct_ord_status_type');
        $this->assertIndexExists('fct_orders', 'fct_ord_date_created_completed');
        $this->assertIndexExists('fct_orders', 'fct_payment_status');
    }

    // =========================================================================
    // fct_order_items (#41-45)
    // =========================================================================

    /** #41 */
    public function test_order_items_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_order_items', [
            'id', 'order_id', 'post_id', 'fulfillment_type', 'payment_type',
            'post_title', 'title', 'object_id', 'cart_index', 'quantity',
        ]);
    }

    /** #42 */
    public function test_order_items_monetary_columns_are_bigint(): void
    {
        foreach (['unit_price', 'cost', 'subtotal', 'tax_amount', 'shipping_charge', 'discount_total', 'line_total', 'refund_total', 'rate'] as $col) {
            $this->assertColumnType('fct_order_items', $col, 'bigint');
        }
    }

    /** #43 */
    public function test_order_items_has_json_columns(): void
    {
        $this->assertColumnType('fct_order_items', 'other_info', 'json');
        $this->assertColumnType('fct_order_items', 'line_meta', 'json');
    }

    /** #44 */
    public function test_order_items_has_composite_index(): void
    {
        $this->assertIndexExists('fct_order_items', 'fct_oi_ord_id_var_id_idx');
    }

    /** #45 */
    public function test_order_items_has_post_id_index(): void
    {
        $this->assertIndexExists('fct_order_items', 'fct_oi_post_id_idx');
    }

    // =========================================================================
    // fct_customers (#46-52)
    // =========================================================================

    /** #46 */
    public function test_customers_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_customers', [
            'id', 'user_id', 'contact_id', 'email', 'first_name', 'last_name',
            'status', 'purchase_value', 'purchase_count', 'ltv', 'notes', 'uuid',
            'country', 'city', 'state', 'postcode',
        ]);
    }

    /** #47 */
    public function test_customers_purchase_value_is_json(): void
    {
        $this->assertColumnType('fct_customers', 'purchase_value', 'json');
    }

    /** #48 */
    public function test_customers_ltv_is_bigint(): void
    {
        $this->assertColumnType('fct_customers', 'ltv', 'bigint');
    }

    /** #49 */
    public function test_customers_aov_is_decimal(): void
    {
        $this->assertColumnType('fct_customers', 'aov', 'decimal(18,2)');
    }

    /** #50 */
    public function test_customers_has_purchase_date_columns(): void
    {
        $col1 = $this->getColumn('fct_customers', 'first_purchase_date');
        $col2 = $this->getColumn('fct_customers', 'last_purchase_date');
        $this->assertNotNull($col1);
        $this->assertNotNull($col2);
    }

    /** #51 */
    public function test_customers_has_email_index(): void
    {
        $this->assertIndexExists('fct_customers', 'fct_cus_email');
    }

    /** #52 */
    public function test_customers_has_user_id_index(): void
    {
        $this->assertIndexExists('fct_customers', 'fct_cus_user_id');
    }

    // =========================================================================
    // fct_product_details (#53-58)
    // =========================================================================

    /** #53 */
    public function test_product_details_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_product_details', [
            'id', 'post_id', 'fulfillment_type', 'min_price', 'max_price',
            'default_variation_id', 'manage_stock', 'stock_availability',
            'variation_type', 'manage_downloadable',
        ]);
    }

    /** #54 */
    public function test_product_details_default_media_is_json(): void
    {
        $this->assertColumnType('fct_product_details', 'default_media', 'json');
    }

    /** #55 */
    public function test_product_details_other_info_is_json(): void
    {
        $this->assertColumnType('fct_product_details', 'other_info', 'json');
    }

    /** #56 */
    public function test_product_details_prices_are_double(): void
    {
        $this->assertColumnType('fct_product_details', 'min_price', 'double');
        $this->assertColumnType('fct_product_details', 'max_price', 'double');
    }

    /** #57 */
    public function test_product_details_has_post_id_index(): void
    {
        $this->assertIndexExists('fct_product_details', 'fct_pd_product_id_idx');
    }

    /** #58 */
    public function test_product_details_has_stock_availability_index(): void
    {
        $this->assertIndexExists('fct_product_details', 'fct_pd_product_stock_stockx');
    }

    // =========================================================================
    // fct_product_variations (#59-64)
    // =========================================================================

    /** #59 */
    public function test_product_variations_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_product_variations', [
            'id', 'post_id', 'media_id', 'serial_index', 'sold_individually',
            'variation_title', 'variation_identifier', 'sku', 'manage_stock',
            'payment_type', 'stock_status', 'backorders', 'total_stock',
            'on_hold', 'committed', 'available', 'fulfillment_type',
            'item_status', 'manage_cost',
        ]);
    }

    /** #60 */
    public function test_product_variations_prices_are_double(): void
    {
        $this->assertColumnType('fct_product_variations', 'item_price', 'double');
        $this->assertColumnType('fct_product_variations', 'item_cost', 'double');
        $this->assertColumnType('fct_product_variations', 'compare_price', 'double');
    }

    /** #61 */
    public function test_product_variations_other_info_is_longtext(): void
    {
        $this->assertColumnType('fct_product_variations', 'other_info', 'longtext');
    }

    /** #62 */
    public function test_product_variations_downloadable_default(): void
    {
        $this->assertColumnType('fct_product_variations', 'downloadable', 'varchar(30)');
        $this->assertColumnDefault('fct_product_variations', 'downloadable', 'false');
    }

    /** #63 */
    public function test_product_variations_sku_unique_index(): void
    {
        $this->assertUniqueIndex('fct_product_variations', 'sku_unique');
    }

    /** #64 */
    public function test_product_variations_has_post_id_and_stock_indexes(): void
    {
        $this->assertIndexExists('fct_product_variations', 'fct_pd_var_post_id_idx');
        $this->assertIndexExists('fct_product_variations', 'fct_pd_var_stock_status_idx');
    }

    // =========================================================================
    // fct_subscriptions (#65-72)
    // =========================================================================

    /** #65 */
    public function test_subscriptions_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_subscriptions', [
            'id', 'uuid', 'customer_id', 'parent_order_id', 'product_id',
            'item_name', 'quantity', 'variation_id', 'billing_interval',
        ]);
    }

    /** #66 */
    public function test_subscriptions_monetary_columns_are_bigint(): void
    {
        foreach (['signup_fee', 'initial_tax_total', 'recurring_amount', 'recurring_tax_total', 'recurring_total'] as $col) {
            $this->assertColumnType('fct_subscriptions', $col, 'bigint');
        }
    }

    /** #67 */
    public function test_subscriptions_bill_times_is_bigint(): void
    {
        $this->assertColumnType('fct_subscriptions', 'bill_times', 'bigint');
    }

    /** #68 */
    public function test_subscriptions_bill_count_is_int(): void
    {
        $this->assertColumnType('fct_subscriptions', 'bill_count', 'int');
    }

    /** #69 */
    public function test_subscriptions_collection_method_is_enum(): void
    {
        $this->assertColumnType('fct_subscriptions', 'collection_method', 'enum');
        $this->assertColumnDefault('fct_subscriptions', 'collection_method', 'automatic');
    }

    /** #70 */
    public function test_subscriptions_has_date_columns(): void
    {
        $this->assertColumnsExist('fct_subscriptions', [
            'expire_at', 'trial_ends_at', 'canceled_at', 'restored_at', 'next_billing_date',
        ]);
    }

    /** #71 */
    public function test_subscriptions_has_vendor_columns(): void
    {
        $this->assertColumnsExist('fct_subscriptions', [
            'vendor_customer_id', 'vendor_plan_id', 'vendor_subscription_id',
        ]);
    }

    /** #72 */
    public function test_subscriptions_has_parent_order_id_index(): void
    {
        $this->assertIndexExists('fct_subscriptions', 'fct_index_order_subscription_idx');
    }

    public function test_subscriptions_has_vendor_subscription_id_index(): void
    {
        $this->assertIndexExists('fct_subscriptions', 'fct_index_vendor_subscription_id_idx');
    }

    // =========================================================================
    // fct_order_transactions (#73-76)
    // =========================================================================

    /** #73 */
    public function test_order_transactions_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_order_transactions', [
            'id', 'order_id', 'order_type', 'transaction_type', 'subscription_id',
            'card_last_4', 'card_brand', 'vendor_charge_id', 'payment_method',
            'payment_mode', 'payment_method_type', 'status', 'currency',
            'total', 'rate', 'uuid', 'meta',
        ]);
    }

    /** #74 */
    public function test_order_transactions_type_default(): void
    {
        $this->assertColumnDefault('fct_order_transactions', 'transaction_type', 'charge');
    }

    /** #75 */
    public function test_order_transactions_total_is_bigint(): void
    {
        $this->assertColumnType('fct_order_transactions', 'total', 'bigint');
    }

    /** #76 */
    public function test_order_transactions_has_indexes(): void
    {
        $this->assertIndexExists('fct_order_transactions', 'fct_ot_ven_charge_id');
        $this->assertIndexExists('fct_order_transactions', 'fct_ot_payment_method_idx');
        $this->assertIndexExists('fct_order_transactions', 'fct_ot_status_idx');
        $this->assertIndexExists('fct_order_transactions', 'fct_ot_order_id_idx');
    }

    // =========================================================================
    // fct_coupons (#77-81)
    // =========================================================================

    /** #77 */
    public function test_coupons_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_coupons', [
            'id', 'title', 'code', 'priority', 'type', 'conditions', 'amount',
            'use_count', 'status', 'notes', 'stackable', 'show_on_checkout',
            'start_date', 'end_date',
        ]);
    }

    /** #78 */
    public function test_coupons_code_is_unique(): void
    {
        $this->assertUniqueIndex('fct_coupons', 'code');
    }

    /** #79 */
    public function test_coupons_conditions_is_json(): void
    {
        $this->assertColumnType('fct_coupons', 'conditions', 'json');
    }

    /** #80 */
    public function test_coupons_stackable_and_show_on_checkout_defaults(): void
    {
        $this->assertColumnDefault('fct_coupons', 'stackable', 'no');
        $this->assertColumnDefault('fct_coupons', 'show_on_checkout', 'yes');
    }

    /** #81 */
    public function test_coupons_has_indexes(): void
    {
        $this->assertIndexExists('fct_coupons', 'fct_cpn_code_idx');
        $this->assertIndexExists('fct_coupons', 'fct_cpn_status_idx');
    }

    // =========================================================================
    // fct_carts (#82-86)
    // =========================================================================

    /** #82 */
    public function test_carts_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_carts', [
            'customer_id', 'user_id', 'order_id', 'cart_hash', 'checkout_data',
            'cart_data', 'utm_data', 'coupons', 'first_name', 'last_name',
            'email', 'stage', 'cart_group', 'user_agent', 'ip_address',
        ]);
    }

    /** #83 */
    public function test_carts_cart_hash_is_unique(): void
    {
        $this->assertUniqueIndex('fct_carts', 'cart_hash');
    }

    /** #84 */
    public function test_carts_stage_and_cart_group_defaults(): void
    {
        $this->assertColumnDefault('fct_carts', 'stage', 'draft');
        $this->assertColumnDefault('fct_carts', 'cart_group', 'global');
    }

    /** #85 */
    public function test_carts_has_deleted_at_column(): void
    {
        $col = $this->getColumn('fct_carts', 'deleted_at');
        $this->assertNotNull($col);
        $this->assertSame('YES', $col['Null']);
    }

    /** #86 */
    public function test_carts_has_no_auto_increment_id(): void
    {
        $col = $this->getColumn('fct_carts', 'id');
        if ($col) {
            $this->assertNotSame('auto_increment', $col['Extra'], 'fct_carts should not have auto_increment id');
        } else {
            $this->assertNull($col, 'fct_carts should not have an id column');
        }
    }

    // =========================================================================
    // fct_tax_rates (#87-89)
    // =========================================================================

    /** #87 */
    public function test_tax_rates_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_tax_rates', [
            'id', 'class_id', 'country', 'state', 'postcode', 'city',
            'rate', 'name', 'group', 'priority', 'is_compound', 'for_shipping', 'for_order',
        ]);
    }

    /** #88 */
    public function test_tax_rates_has_no_timestamps(): void
    {
        $columns = $this->getColumnNames('fct_tax_rates');
        $this->assertNotContains('created_at', $columns);
        $this->assertNotContains('updated_at', $columns);
    }

    /** #89 */
    public function test_tax_rates_has_indexes(): void
    {
        $this->assertIndexExists('fct_tax_rates', 'fct_txr_txr_class_idx');
        $this->assertIndexExists('fct_tax_rates', 'fct_txr_priority_idx');
    }

    // =========================================================================
    // fct_shipping_zones (#90-91)
    // =========================================================================

    /** #90 */
    public function test_shipping_zones_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_shipping_zones', [
            'id', 'name', 'region', 'order', 'meta', 'created_at', 'updated_at',
        ]);
    }

    /** #91 */
    public function test_shipping_zones_meta_column_exists(): void
    {
        $col = $this->getColumn('fct_shipping_zones', 'meta');
        $this->assertNotNull($col, 'meta column should exist (added via migrated() hook)');
    }

    // =========================================================================
    // fct_shipping_methods (#92-95)
    // =========================================================================

    /** #92 */
    public function test_shipping_methods_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_shipping_methods', [
            'id', 'zone_id', 'title', 'type', 'settings', 'is_enabled',
            'states', 'amount', 'order', 'meta',
        ]);
    }

    /** #93 */
    public function test_shipping_methods_amount_is_decimal(): void
    {
        $this->assertColumnType('fct_shipping_methods', 'amount', 'decimal(10,2)');
    }

    /** #94 */
    public function test_shipping_methods_json_columns(): void
    {
        $this->assertColumnType('fct_shipping_methods', 'states', 'json');
        $this->assertColumnType('fct_shipping_methods', 'meta', 'json');
    }

    /** #95 */
    public function test_shipping_methods_has_indexes(): void
    {
        $this->assertIndexExists('fct_shipping_methods', 'fct_sm_zone_id_idx');
        $this->assertIndexExists('fct_shipping_methods', 'fct_sm_order_idx');
    }

    // =========================================================================
    // fct_activity (#96-99)
    // =========================================================================

    /** #96 */
    public function test_activity_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_activity', [
            'id', 'status', 'log_type', 'module_type', 'module_id',
            'module_name', 'user_id', 'title', 'content', 'read_status', 'created_by',
        ]);
    }

    /** #97 */
    public function test_activity_read_status_default(): void
    {
        $this->assertColumnDefault('fct_activity', 'read_status', 'unread');
    }

    /** #98 */
    public function test_activity_created_by_default(): void
    {
        $this->assertColumnDefault('fct_activity', 'created_by', 'FCT-BOT');
    }

    /** #99 */
    public function test_activity_has_module_id_index(): void
    {
        $this->assertIndexExists('fct_activity', 'fct_act_module_id_idx');
    }

    // =========================================================================
    // fct_meta (#100-104)
    // =========================================================================

    /** #100 */
    public function test_meta_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_meta', [
            'id', 'object_type', 'object_id', 'meta_key', 'meta_value', 'created_at', 'updated_at',
        ]);
    }

    /** #101 */
    public function test_meta_object_type_is_varchar_not_null(): void
    {
        $col = $this->getColumn('fct_meta', 'object_type');
        $this->assertColumnType('fct_meta', 'object_type', 'varchar(50)');
        $this->assertSame('NO', $col['Null']);
    }

    /** #102 */
    public function test_meta_value_is_longtext(): void
    {
        $this->assertColumnType('fct_meta', 'meta_value', 'longtext');
    }

    /** #103 */
    public function test_meta_has_object_type_index(): void
    {
        $this->assertIndexExists('fct_meta', 'fct_mt_mt_idx');
    }

    /** #104 */
    public function test_meta_has_object_id_index(): void
    {
        $this->assertIndexExists('fct_meta', 'fct_mt_mto_id_idx');
    }

    // =========================================================================
    // fct_atts_groups (#105-108)
    // =========================================================================

    /** #105 */
    public function test_atts_groups_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_atts_groups', [
            'id', 'title', 'slug', 'description', 'settings', 'created_at', 'updated_at',
        ]);
    }

    /** #106 */
    public function test_atts_groups_title_is_not_unique(): void
    {
        $this->assertNull($this->findIndexName('fct_atts_groups', 'title'));
    }

    /** #107 */
    public function test_atts_groups_slug_is_unique(): void
    {
        $this->assertUniqueIndex('fct_atts_groups', 'slug');
    }

    /** #108 */
    public function test_atts_groups_settings_is_longtext(): void
    {
        $this->assertColumnType('fct_atts_groups', 'settings', 'longtext');
    }

    // =========================================================================
    // fct_atts_relations (#109-112)
    // =========================================================================

    /** #109 */
    public function test_atts_relations_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_atts_relations', [
            'id', 'group_id', 'term_id', 'object_id', 'created_at', 'updated_at',
        ]);
    }

    /** #110 */
    public function test_atts_relations_has_group_id_index(): void
    {
        $this->assertIndexExists('fct_atts_relations', 'fct_at_rel_group_id_idx');
    }

    /** #111 */
    public function test_atts_relations_has_term_id_index(): void
    {
        $this->assertIndexExists('fct_atts_relations', 'fct_at_rel_term_id_idx');
    }

    /** #112 */
    public function test_atts_relations_has_object_id_index(): void
    {
        $this->assertIndexExists('fct_atts_relations', 'fct_at_rel_obj_id_idx');
    }

    // =========================================================================
    // fct_atts_terms (#113-114)
    // =========================================================================

    /** #113 */
    public function test_atts_terms_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_atts_terms', [
            'id', 'group_id', 'serial', 'title', 'slug', 'description', 'settings', 'created_at', 'updated_at',
        ]);
    }

    /** #114 */
    public function test_atts_terms_has_group_id_index(): void
    {
        $this->assertIndexExists('fct_atts_terms', 'fct_attt_group_id_idx');
    }

    // =========================================================================
    // fct_applied_coupons (#115-118)
    // =========================================================================

    /** #115 */
    public function test_applied_coupons_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_applied_coupons', [
            'id', 'order_id', 'coupon_id', 'code', 'amount', 'created_at', 'updated_at',
        ]);
    }

    /** #116 */
    public function test_applied_coupons_order_id_is_bigint_not_null(): void
    {
        $col = $this->getColumn('fct_applied_coupons', 'order_id');
        $this->assertStringContainsString('bigint', strtolower($col['Type']));
        $this->assertSame('NO', $col['Null']);
    }

    /** #117 */
    public function test_applied_coupons_amount_is_double(): void
    {
        $this->assertColumnType('fct_applied_coupons', 'amount', 'double');
    }

    /** #118 */
    public function test_applied_coupons_has_code_index(): void
    {
        $this->assertIndexExists('fct_applied_coupons', 'fct_acoup_code_idx');
    }

    // =========================================================================
    // fct_customer_addresses (#119-125)
    // =========================================================================

    /** #119 */
    public function test_customer_addresses_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_customer_addresses', [
            'id', 'customer_id', 'is_primary', 'type', 'status', 'label',
            'name', 'address_1', 'address_2', 'city', 'state', 'phone',
            'email', 'postcode', 'country', 'meta',
        ]);
    }

    /** #120 */
    public function test_customer_addresses_is_primary_default(): void
    {
        $this->assertColumnDefault('fct_customer_addresses', 'is_primary', '0');
    }

    /** #121 */
    public function test_customer_addresses_type_default(): void
    {
        $this->assertColumnDefault('fct_customer_addresses', 'type', 'billing');
    }

    /** #122 */
    public function test_customer_addresses_status_default(): void
    {
        $this->assertColumnDefault('fct_customer_addresses', 'status', 'active');
    }

    /** #123 */
    public function test_customer_addresses_meta_is_json(): void
    {
        $this->assertColumnType('fct_customer_addresses', 'meta', 'json');
    }

    /** #124 */
    public function test_customer_addresses_has_composite_index(): void
    {
        $this->assertIndexExists('fct_customer_addresses', 'fct_cus_ad_customer_is_primary');
    }

    /** #125 */
    public function test_customer_addresses_has_type_and_status_indexes(): void
    {
        $this->assertIndexExists('fct_customer_addresses', 'fct_cus_ad_type');
        $this->assertIndexExists('fct_customer_addresses', 'fct_cus_ad_status');
    }

    // =========================================================================
    // fct_customer_meta (#126-128)
    // =========================================================================

    /** #126 */
    public function test_customer_meta_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_customer_meta', [
            'id', 'customer_id', 'meta_key', 'meta_value', 'created_at', 'updated_at',
        ]);
    }

    /** #127 */
    public function test_customer_meta_has_meta_key_index(): void
    {
        $this->assertIndexExists('fct_customer_meta', 'fct_cm_meta_key');
    }

    /** #128 */
    public function test_customer_meta_has_customer_id_index(): void
    {
        $this->assertIndexExists('fct_customer_meta', 'fct_cm_customer_id');
    }

    // =========================================================================
    // fct_order_addresses (#129-131)
    // =========================================================================

    /** #129 */
    public function test_order_addresses_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_order_addresses', [
            'id', 'order_id', 'type', 'name', 'address_1', 'address_2',
            'city', 'state', 'postcode', 'country', 'meta', 'created_at', 'updated_at',
        ]);
    }

    /** #130 */
    public function test_order_addresses_type_default(): void
    {
        $this->assertColumnDefault('fct_order_addresses', 'type', 'billing');
    }

    /** #131 */
    public function test_order_addresses_meta_is_json(): void
    {
        $this->assertColumnType('fct_order_addresses', 'meta', 'json');
    }

    // =========================================================================
    // fct_order_download_permissions (#132-135)
    // =========================================================================

    /** #132 */
    public function test_order_download_permissions_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_order_download_permissions', [
            'id', 'order_id', 'variation_id', 'download_id', 'download_count',
            'download_limit', 'access_expires', 'customer_id', 'created_at', 'updated_at',
        ]);
    }

    /** #133 */
    public function test_order_download_permissions_has_order_id_index(): void
    {
        $this->assertIndexExists('fct_order_download_permissions', 'fct_odp_order_id_idx');
    }

    /** #134 */
    public function test_order_download_permissions_has_download_id_index(): void
    {
        $this->assertIndexExists('fct_order_download_permissions', 'fct_odp_download_id_idx');
    }

    /** #135 */
    public function test_order_download_permissions_has_variation_id_index(): void
    {
        $this->assertIndexExists('fct_order_download_permissions', 'fct_odp_variation_id_idx');
    }

    // =========================================================================
    // fct_order_meta (#136-137)
    // =========================================================================

    /** #136 */
    public function test_order_meta_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_order_meta', [
            'id', 'order_id', 'meta_key', 'meta_value', 'created_at', 'updated_at',
        ]);
    }

    /** #137 */
    public function test_order_meta_has_order_id_index(): void
    {
        $this->assertIndexExists('fct_order_meta', 'fct_om_ord_id_idx');
    }

    // =========================================================================
    // fct_order_operations (#138-141)
    // =========================================================================

    /** #138 */
    public function test_order_operations_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_order_operations', [
            'id', 'order_id', 'created_via', 'emails_sent', 'sales_recorded',
            'utm_campaign', 'utm_term', 'utm_source', 'utm_medium', 'utm_content',
            'utm_id', 'cart_hash', 'refer_url', 'meta', 'created_at', 'updated_at',
        ]);
    }

    /** #139 */
    public function test_order_operations_boolean_defaults(): void
    {
        $this->assertColumnDefault('fct_order_operations', 'emails_sent', '0');
        $this->assertColumnDefault('fct_order_operations', 'sales_recorded', '0');
    }

    /** #140 */
    public function test_order_operations_meta_is_json(): void
    {
        $this->assertColumnType('fct_order_operations', 'meta', 'json');
    }

    /** #141 */
    public function test_order_operations_has_order_id_index(): void
    {
        $this->assertIndexExists('fct_order_operations', 'fct_oo_order_operations_idx');
    }

    // =========================================================================
    // fct_order_tax_rate (#142-144)
    // =========================================================================

    /** #142 */
    public function test_order_tax_rate_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_order_tax_rate', [
            'id', 'order_id', 'tax_rate_id', 'shipping_tax', 'order_tax',
            'total_tax', 'meta', 'filed_at', 'created_at', 'updated_at',
        ]);
    }

    /** #143 */
    public function test_order_tax_rate_monetary_columns_are_bigint(): void
    {
        foreach (['shipping_tax', 'order_tax', 'total_tax'] as $col) {
            $this->assertColumnType('fct_order_tax_rate', $col, 'bigint');
        }
    }

    /** #144 */
    public function test_order_tax_rate_meta_is_json(): void
    {
        $this->assertColumnType('fct_order_tax_rate', 'meta', 'json');
    }

    // =========================================================================
    // fct_product_downloads (#145-148)
    // =========================================================================

    /** #145 */
    public function test_product_downloads_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_product_downloads', [
            'id', 'post_id', 'product_variation_id', 'download_identifier',
            'title', 'type', 'driver', 'file_name', 'file_path', 'file_url',
            'file_size', 'settings', 'serial', 'created_at', 'updated_at',
        ]);
    }

    /** #146 */
    public function test_product_downloads_identifier_is_unique(): void
    {
        $this->assertUniqueIndex('fct_product_downloads', 'download_identifier');
    }

    /** #147 */
    public function test_product_downloads_driver_default(): void
    {
        $this->assertColumnDefault('fct_product_downloads', 'driver', 'local');
    }

    /** #148 */
    public function test_product_downloads_variation_id_is_longtext(): void
    {
        $this->assertColumnType('fct_product_downloads', 'product_variation_id', 'longtext');
    }

    // =========================================================================
    // fct_product_meta (#149-150)
    // =========================================================================

    /** #149 */
    public function test_product_meta_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_product_meta', [
            'id', 'object_id', 'object_type', 'meta_key', 'meta_value', 'created_at', 'updated_at',
        ]);
    }

    /** #150 */
    public function test_product_meta_has_meta_key_index(): void
    {
        $this->assertIndexExists('fct_product_meta', 'fct_pm_meta_key');
    }

    // =========================================================================
    // fct_subscription_meta (#151-153)
    // =========================================================================

    /** #151 */
    public function test_subscription_meta_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_subscription_meta', [
            'id', 'subscription_id', 'meta_key', 'meta_value', 'created_at', 'updated_at',
        ]);
    }

    /** #152 */
    public function test_subscription_meta_has_subscription_id_index(): void
    {
        $this->assertIndexExists('fct_subscription_meta', 'fct_sm_subs_id_key');
    }

    /** #153 */
    public function test_subscription_meta_has_meta_key_index(): void
    {
        $this->assertIndexExists('fct_subscription_meta', 'fct_sm_meta_key');
    }

    // =========================================================================
    // fct_label (#154-155)
    // =========================================================================

    /** #154 */
    public function test_label_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_label', ['id', 'value', 'created_at', 'updated_at']);
    }

    /** #155 */
    public function test_label_value_is_unique(): void
    {
        $this->assertUniqueIndex('fct_label', 'value');
    }

    // =========================================================================
    // fct_label_relationships (#156-158)
    // =========================================================================

    /** #156 */
    public function test_label_relationships_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_label_relationships', [
            'id', 'label_id', 'labelable_id', 'labelable_type', 'created_at', 'updated_at',
        ]);
    }

    /** #157 */
    public function test_label_relationships_has_label_id_index(): void
    {
        $this->assertIndexExists('fct_label_relationships', 'fct_labr_label_id_idx');
    }

    /** #158 */
    public function test_label_relationships_has_labelable_id_index(): void
    {
        $this->assertIndexExists('fct_label_relationships', 'fct_labr_labelable_id_idx');
    }

    // =========================================================================
    // fct_webhook_logger (#159-161)
    // =========================================================================

    /** #159 */
    public function test_webhook_logger_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_webhook_logger', [
            'id', 'source', 'event_type', 'payload', 'status', 'created_at', 'updated_at',
        ]);
    }

    /** #160 */
    public function test_webhook_logger_status_default(): void
    {
        $this->assertColumnDefault('fct_webhook_logger', 'status', 'pending');
    }

    /** #161 */
    public function test_webhook_logger_payload_is_longtext(): void
    {
        $this->assertColumnType('fct_webhook_logger', 'payload', 'longtext');
    }

    // =========================================================================
    // fct_shipping_classes (#162-166)
    // =========================================================================

    /** #162 */
    public function test_shipping_classes_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_shipping_classes', [
            'id', 'name', 'cost', 'per_item', 'type', 'created_at', 'updated_at',
        ]);
    }

    /** #163 */
    public function test_shipping_classes_cost_is_decimal(): void
    {
        $this->assertColumnType('fct_shipping_classes', 'cost', 'decimal(10,2)');
    }

    /** #164 */
    public function test_shipping_classes_per_item_default(): void
    {
        $this->assertColumnDefault('fct_shipping_classes', 'per_item', '0');
    }

    /** #165 */
    public function test_shipping_classes_type_default(): void
    {
        $this->assertColumnDefault('fct_shipping_classes', 'type', 'fixed');
    }

    /** #166 */
    public function test_shipping_classes_has_name_index(): void
    {
        $this->assertIndexExists('fct_shipping_classes', 'fct_sc_name_idx');
    }

    // =========================================================================
    // fct_scheduled_actions (#167-172)
    // =========================================================================

    /** #167 */
    public function test_scheduled_actions_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_scheduled_actions', [
            'id', 'scheduled_at', 'action', 'status', 'group', 'object_id',
            'object_type', 'completed_at', 'retry_count', 'data',
            'created_at', 'updated_at', 'response_note',
        ]);
    }

    /** #168 */
    public function test_scheduled_actions_data_is_json(): void
    {
        $this->assertColumnType('fct_scheduled_actions', 'data', 'json');
    }

    /** #169 */
    public function test_scheduled_actions_response_note_is_longtext(): void
    {
        $this->assertColumnType('fct_scheduled_actions', 'response_note', 'longtext');
    }

    /** #170 */
    public function test_scheduled_actions_retry_count_default(): void
    {
        $this->assertColumnDefault('fct_scheduled_actions', 'retry_count', '0');
    }

    /** #171 */
    public function test_scheduled_actions_has_scheduled_at_index(): void
    {
        $this->assertIndexExists('fct_scheduled_actions', 'fct_sch_var_scheduled_at_idx');
    }

    /** #172 */
    public function test_scheduled_actions_has_status_index(): void
    {
        $this->assertIndexExists('fct_scheduled_actions', 'fct_sch_var_status_idx');
    }

    // =========================================================================
    // fct_retention_snapshots (#173-176)
    // =========================================================================

    /** #173 */
    public function test_retention_snapshots_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_retention_snapshots', [
            'id', 'cohort', 'period', 'product_id', 'cohort_customers', 'cohort_mrr',
            'retained_customers', 'retained_mrr', 'new_customers', 'churned_customers',
            'retention_rate_customers', 'retention_rate_mrr', 'period_offset',
            'created_at', 'updated_at',
        ]);
    }

    /** #175 */
    public function test_retention_snapshots_has_individual_indexes(): void
    {
        $this->assertIndexExists('fct_retention_snapshots', 'fct_rs_cohort_idx');
        $this->assertIndexExists('fct_retention_snapshots', 'fct_rs_period_idx');
        $this->assertIndexExists('fct_retention_snapshots', 'fct_rs_product_idx');
        $this->assertIndexExists('fct_retention_snapshots', 'fct_rs_offset_idx');
    }

    /** #176 */
    public function test_retention_snapshots_rate_columns_are_decimal(): void
    {
        // MRR retention passes 999.99% when upgrades grow a cohort
        $this->assertColumnType('fct_retention_snapshots', 'retention_rate_customers', 'decimal(10,2)');
        $this->assertColumnType('fct_retention_snapshots', 'retention_rate_mrr', 'decimal(10,2)');
    }

    // =========================================================================
    // fct_tax_classes (#177-178)
    // =========================================================================

    /** #177 */
    public function test_tax_classes_has_core_columns(): void
    {
        $this->assertColumnsExist('fct_tax_classes', [
            'id', 'title', 'slug', 'description', 'meta', 'created_at', 'updated_at',
        ]);
    }

    /** #178 */
    public function test_tax_classes_meta_is_json(): void
    {
        $this->assertColumnType('fct_tax_classes', 'meta', 'json');
    }
}

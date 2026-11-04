<?php

namespace Tests\Integration\Database;

use Tests\Support\DatabaseTestCase;

/**
 * Spec: database.md #1-35 — All 35 fct_* tables must exist after migration.
 */
class TableExistenceTest extends DatabaseTestCase
{
    /** #1 */
    public function test_fct_meta_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_meta'));
    }

    /** #2 */
    public function test_fct_atts_groups_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_atts_groups'));
    }

    /** #3 */
    public function test_fct_atts_relations_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_atts_relations'));
    }

    /** #4 */
    public function test_fct_atts_terms_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_atts_terms'));
    }

    /** #5 */
    public function test_fct_carts_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_carts'));
    }

    /** #6 */
    public function test_fct_coupons_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_coupons'));
    }

    /** #7 */
    public function test_fct_customer_addresses_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_customer_addresses'));
    }

    /** #8 */
    public function test_fct_customer_meta_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_customer_meta'));
    }

    /** #9 */
    public function test_fct_customers_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_customers'));
    }

    /** #10 */
    public function test_fct_order_addresses_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_order_addresses'));
    }

    /** #11 */
    public function test_fct_order_download_permissions_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_order_download_permissions'));
    }

    /** #12 */
    public function test_fct_order_meta_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_order_meta'));
    }

    /** #13 */
    public function test_fct_order_operations_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_order_operations'));
    }

    /** #14 */
    public function test_fct_order_items_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_order_items'));
    }

    /** #15 */
    public function test_fct_orders_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_orders'));
    }

    /** #16 */
    public function test_fct_order_tax_rate_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_order_tax_rate'));
    }

    /** #17 */
    public function test_fct_order_transactions_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_order_transactions'));
    }

    /** #18 */
    public function test_fct_product_details_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_product_details'));
    }

    /** #19 */
    public function test_fct_product_downloads_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_product_downloads'));
    }

    /** #20 */
    public function test_fct_product_meta_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_product_meta'));
    }

    /** #21 */
    public function test_fct_subscription_meta_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_subscription_meta'));
    }

    /** #22 */
    public function test_fct_subscriptions_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_subscriptions'));
    }

    /** #23 */
    public function test_fct_tax_classes_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_tax_classes'));
    }

    /** #24 */
    public function test_fct_tax_rates_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_tax_rates'));
    }

    /** #25 */
    public function test_fct_product_variations_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_product_variations'));
    }

    /** #26 */
    public function test_fct_applied_coupons_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_applied_coupons'));
    }

    /** #27 */
    public function test_fct_label_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_label'));
    }

    /** #28 */
    public function test_fct_label_relationships_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_label_relationships'));
    }

    /** #29 */
    public function test_fct_activity_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_activity'));
    }

    /** #30 */
    public function test_fct_webhook_logger_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_webhook_logger'));
    }

    /** #31 */
    public function test_fct_shipping_zones_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_shipping_zones'));
    }

    /** #32 */
    public function test_fct_shipping_methods_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_shipping_methods'));
    }

    /** #33 */
    public function test_fct_shipping_classes_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_shipping_classes'));
    }

    /** #34 */
    public function test_fct_scheduled_actions_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_scheduled_actions'));
    }

    /** #35 */
    public function test_fct_retention_snapshots_table_exists(): void
    {
        $this->assertTrue($this->tableExists('fct_retention_snapshots'));
    }
}

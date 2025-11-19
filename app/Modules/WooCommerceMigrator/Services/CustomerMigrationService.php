<?php

namespace FluentCart\App\Modules\WooCommerceMigrator\Services;

use FluentCart\Framework\Support\Arr;

class CustomerMigrationService extends BaseMigrationService
{
    const CUSTOMER_MAPPING_KEY = '__fluent_cart_wc_customer_map';

    /**
     * Check if the migration dependencies are met
     *
     * @return bool
     */
    public function checkDependencies(): bool
    {
        if (!$this->checkWooCommerceDependencies()) {
            return false;
        }

        global $wpdb;
        
        // Check if FluentCart customer tables exist
        $fluentTables = [
            $wpdb->prefix . 'fct_customers',
            $wpdb->prefix . 'fct_customer_addresses',
            $wpdb->prefix . 'fct_customer_meta'
        ];

        foreach ($fluentTables as $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Safe as it's just a table name
            $result = $wpdb->get_var(
                $wpdb->prepare("SHOW TABLES LIKE %s", $table)
            );
            if ($result !== $table) {
                $this->logError("Required FluentCart table {$table} does not exist");
                return false;
            }
        }

        return true;
    }

    /**
     * Run the customer migration
     *
     * @param array $options Migration options
     * @return array Migration results with counts and status
     */
    public function migrate(array $options = []): array
    {
        $this->initStats();

        if (!$this->checkDependencies()) {
            return $this->getStats();
        }

        global $wpdb;

        // Get all WooCommerce customers from the customer lookup table
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $customers = $wpdb->get_results("
            SELECT DISTINCT u.ID, u.user_login, u.user_email, u.user_nicename, 
                   u.display_name, u.user_registered,
                   cl.customer_id as wc_customer_id
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->prefix}wc_customer_lookup cl ON u.ID = cl.user_id
            WHERE cl.user_id > 0
            ORDER BY u.user_registered ASC
        ");

        $this->stats['total'] = count($customers);

        foreach ($customers as $customer) {
            try {
                $result = $this->migrateCustomer($customer, $options);
                if (!$result) {
                    $this->logError("Failed to migrate customer {$customer->ID}", $customer);
                }
            } catch (\Exception $e) {
                $this->logError("Failed to migrate customer {$customer->ID}: " . $e->getMessage(), $customer);
            }
        }

        $this->finalizeStats();
        return $this->getStats();
    }

    /**
     * Migrate a single customer based on EDD migration pattern
     *
     * @param object $wooCustomer WordPress user object
     * @param array $options Migration options
     * @return int|false FluentCart customer ID or false on failure
     */
    private function migrateCustomer($wooCustomer, $options = [])
    {
        global $wpdb;

        // Check if customer already exists (unless forcing)
        if (empty($options['force'])) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $existingCustomer = $wpdb->get_row(
                $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fct_customers WHERE email = %s OR user_id = %d",
                $wooCustomer->user_email,
                $wooCustomer->ID
            ));

            if ($existingCustomer) {
                $this->logSkipped("Customer {$wooCustomer->ID} already exists");
                return $existingCustomer->id;
            }
        }

        // Get all user meta for the customer
        $customerMeta = get_user_meta($wooCustomer->ID);

        // Prepare essential customer data following FluentCart schema
        $customerData = [
            'user_id' => $wooCustomer->ID,
            'email' => $wooCustomer->user_email,
            'first_name' => $this->getMetaValue($customerMeta, 'first_name') ?: $this->getMetaValue($customerMeta, 'billing_first_name'),
            'last_name' => $this->getMetaValue($customerMeta, 'last_name') ?: $this->getMetaValue($customerMeta, 'billing_last_name'),
            'status' => 'active',
            'country' => $this->getMetaValue($customerMeta, 'billing_country'),
            'city' => $this->getMetaValue($customerMeta, 'billing_city'),
            'state' => $this->getMetaValue($customerMeta, 'billing_state'),
            'postcode' => $this->getMetaValue($customerMeta, 'billing_postcode'),
            'created_at' => $wooCustomer->user_registered,
            'updated_at' => current_time('mysql')
        ];

        // Clean empty values
        $customerData = array_filter($customerData, function($value) {
            return $value !== null && $value !== '';
        });

        // Insert customer
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $insertResult = $wpdb->insert($wpdb->prefix . 'fct_customers', $customerData);
        
        if (!$insertResult) {
            $this->logError("Failed to insert customer: " . $wpdb->last_error, $wooCustomer);
            return false;
        }

        $fluentCustomerId = $wpdb->insert_id;

        // Migrate billing address
        $this->migrateBillingAddress($fluentCustomerId, $customerMeta);

        // Migrate shipping address (if different from billing)
        $this->migrateShippingAddress($fluentCustomerId, $customerMeta);

        // Update customer purchase statistics
        $this->updateCustomerStats($fluentCustomerId, $wooCustomer->ID);

        // Store mapping for reference
        $this->getOrSetMapping(self::CUSTOMER_MAPPING_KEY, $wooCustomer->ID, $fluentCustomerId);

        $this->logSuccess("Customer {$wooCustomer->ID} migrated to {$fluentCustomerId}");
        return $fluentCustomerId;
    }

    /**
     * Migrate billing address to FluentCart customer addresses table
     *
     * @param int $fluentCustomerId
     * @param array $customerMeta
     */
    private function migrateBillingAddress($fluentCustomerId, $customerMeta)
    {
        // Combine first and last name for the name field
        $firstName = $this->getMetaValue($customerMeta, 'billing_first_name');
        $lastName = $this->getMetaValue($customerMeta, 'billing_last_name');
        $name = trim($firstName . ' ' . $lastName);

        $billingData = [
            'customer_id' => $fluentCustomerId,
            'is_primary' => 1,
            'type' => 'billing',
            'status' => 'active',
            'label' => 'Billing',
            'name' => $name,
            'address_1' => $this->getMetaValue($customerMeta, 'billing_address_1'),
            'address_2' => $this->getMetaValue($customerMeta, 'billing_address_2'),
            'city' => $this->getMetaValue($customerMeta, 'billing_city'),
            'state' => $this->getMetaValue($customerMeta, 'billing_state'),
            'postcode' => $this->getMetaValue($customerMeta, 'billing_postcode'),
            'country' => $this->getMetaValue($customerMeta, 'billing_country'),
            'phone' => $this->getMetaValue($customerMeta, 'billing_phone'),
            'email' => $this->getMetaValue($customerMeta, 'billing_email'),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ];

        $this->insertAddressIfValid($billingData);
    }

    /**
     * Migrate shipping address to FluentCart customer addresses table
     *
     * @param int $fluentCustomerId
     * @param array $customerMeta
     */
    private function migrateShippingAddress($fluentCustomerId, $customerMeta)
    {
        // Combine first and last name for the name field
        $firstName = $this->getMetaValue($customerMeta, 'shipping_first_name');
        $lastName = $this->getMetaValue($customerMeta, 'shipping_last_name');
        $name = trim($firstName . ' ' . $lastName);

        $shippingData = [
            'customer_id' => $fluentCustomerId,
            'is_primary' => 1, // First shipping address is always primary
            'type' => 'shipping',
            'status' => 'active',
            'label' => 'Shipping',
            'name' => $name,
            'address_1' => $this->getMetaValue($customerMeta, 'shipping_address_1'),
            'address_2' => $this->getMetaValue($customerMeta, 'shipping_address_2'),
            'city' => $this->getMetaValue($customerMeta, 'shipping_city'),
            'state' => $this->getMetaValue($customerMeta, 'shipping_state'),
            'postcode' => $this->getMetaValue($customerMeta, 'shipping_postcode'),
            'country' => $this->getMetaValue($customerMeta, 'shipping_country'),
            'phone' => $this->getMetaValue($customerMeta, 'shipping_phone'),
            'email' => '', // WooCommerce doesn't typically store shipping email
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ];

        $this->insertAddressIfValid($shippingData);
    }

    /**
     * Calculate and update customer purchase statistics
     *
     * @param int $fluentCustomerId
     * @param int $wooUserId
     */
    private function updateCustomerStats($fluentCustomerId, $wooUserId)
    {
        $stats = $this->calculateCustomerStats($wooUserId);
        
        if ($stats) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $wpdb->prefix . 'fct_customers',
                [
                    'purchase_count' => $stats['order_count'],
                    'purchase_value' => json_encode([$stats['currency'] => $stats['total_spent_cents']]),
                    'first_purchase_date' => $stats['first_order_date'],
                    'last_purchase_date' => $stats['last_order_date'],
                    'aov' => $stats['aov_cents'],
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $fluentCustomerId]
            );
        }
    }

    /**
     * Calculate customer statistics from WooCommerce orders
     *
     * @param int $wooUserId
     * @return array|null
     */
    private function calculateCustomerStats($wooUserId)
    {
        global $wpdb;

        // Calculate stats from WooCommerce orders
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $orderStats = $wpdb->get_row(
            $wpdb->prepare("
            SELECT 
                COUNT(*) as order_count,
                SUM(CAST(pm_total.meta_value AS DECIMAL(10,2))) as total_spent,
                MIN(p.post_date) as first_order_date,
                MAX(p.post_date) as last_order_date,
                pm_currency.meta_value as currency
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_customer ON p.ID = pm_customer.post_id 
                AND pm_customer.meta_key = '_customer_user' 
                AND pm_customer.meta_value = %d
            INNER JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id 
                AND pm_total.meta_key = '_order_total'
            LEFT JOIN {$wpdb->postmeta} pm_currency ON p.ID = pm_currency.post_id 
                AND pm_currency.meta_key = '_order_currency'
            WHERE p.post_type = 'shop_order' 
            AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
        ", $wooUserId));

        if ($orderStats && $orderStats->order_count > 0) {
            $totalSpent = (float) $orderStats->total_spent;
            $currency = $orderStats->currency ?: get_woocommerce_currency();
            
            return [
                'order_count' => (int) $orderStats->order_count,
                'total_spent_cents' => (int) ($totalSpent * 100), // Convert to cents
                'first_order_date' => $orderStats->first_order_date,
                'last_order_date' => $orderStats->last_order_date,
                'aov_cents' => (int) (($totalSpent / $orderStats->order_count) * 100), // AOV in cents
                'currency' => $currency
            ];
        }

        return null;
    }

    /**
     * Insert address data if valid, removing empty values but keeping required fields
     *
     * @param array $addressData
     */
    private function insertAddressIfValid($addressData)
    {
        global $wpdb;

        // Remove null/empty values but keep required fields
        $requiredFields = ['customer_id', 'is_primary', 'type', 'status', 'label', 'created_at', 'updated_at'];
        $addressData = array_filter($addressData, function($value, $key) use ($requiredFields) {
            return in_array($key, $requiredFields) || ($value !== null && $value !== '');
        }, ARRAY_FILTER_USE_BOTH);

        // Only insert if we have meaningful address data
        if (!empty($addressData['address_1']) || !empty($addressData['city']) || !empty($addressData['country'])) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert($wpdb->prefix . 'fct_customer_addresses', $addressData);
        }
    }

    /**
     * Helper to get meta value from meta array
     *
     * @param array $meta
     * @param string $key
     * @return string|null
     */
    private function getMetaValue($meta, $key)
    {
        return isset($meta[$key][0]) ? $meta[$key][0] : null;
    }

    /**
     * Clean up migration data (for fresh migrations)
     *
     * @return bool
     */
    public function cleanup(): bool
    {
        global $wpdb;

        try {
            // Delete all FluentCart customers and related data
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query("DELETE FROM {$wpdb->prefix}fct_customers");
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query("DELETE FROM {$wpdb->prefix}fct_customer_addresses");
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query("DELETE FROM {$wpdb->prefix}fct_customer_meta");

            // Clear mapping
            $this->clearMapping(self::CUSTOMER_MAPPING_KEY);

            return true;
        } catch (\Exception $e) {
            $this->logError("Failed to cleanup customer data: " . $e->getMessage());
            return false;
        }
    }
} 

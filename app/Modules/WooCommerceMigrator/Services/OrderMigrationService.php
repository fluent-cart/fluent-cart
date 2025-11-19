<?php

namespace FluentCart\App\Modules\WooCommerceMigrator\Services;

use FluentCart\App\Modules\WooCommerceMigrator\Contracts\MigrationServiceInterface;
use FluentCart\Framework\Support\Arr;

/**
 * OrderMigrationService - Migrates WooCommerce orders to FluentCart
 * 
 * Handles:
 * - Order headers (wp_wc_orders -> wp_fct_orders)
 * - Order items (wp_woocommerce_order_items -> wp_fct_order_items)
 * - Order addresses (wp_wc_order_addresses -> wp_fct_order_addresses)
 * - Applied coupons (coupon line items -> wp_fct_applied_coupons)
 * - Fee items (fee line items -> custom handling)
 * - Order metadata (wp_wc_orders_meta -> wp_fct_order_meta)
 */
class OrderMigrationService extends BaseMigrationService implements MigrationServiceInterface
{
    protected $entityName = 'orders';
    protected $batchSize = 50; // Lower batch size for complex order data
    protected $migrated = 0;

    /**
     * Set batch size for migration
     */
    public function setBatchSize(int $size): void
    {
        $this->batchSize = max(1, min($size, 100)); // Min 1, Max 100
    }

    /**
     * Add an error message
     */
    protected function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    /**
     * Get all error messages
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Increment migrated counter
     */
    protected function incrementMigrated(): void
    {
        $this->migrated++;
    }

    /**
     * Check if migration can proceed
     */
    public function canMigrate(): bool
    {
        if (!$this->isWooCommerceActive()) {
            $this->addError('WooCommerce is not active');
            return false;
        }

        // Check if HPOS is active
        if (!$this->isHPOSActive()) {
            $this->addError('WooCommerce HPOS (High-Performance Order Storage) is not active');
            return false;
        }

        // Check dependencies - customers and products must be migrated first
        if (!$this->areDependenciesMigrated()) {
            $this->addError('Dependencies not satisfied. Please migrate customers and products first.');
            return false;
        }

        return true;
    }

    /**
     * Get total count of orders to migrate
     */
    public function getTotalCount(): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}wc_orders 
            WHERE type = 'shop_order'
        ");
    }

    /**
     * Get count of already migrated orders
     */
    public function getMigratedCount(): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}fct_orders 
            WHERE invoice_no LIKE 'WC-%'
        ");
    }

    /**
     * Discover orders to migrate
     */
    public function discoverItems(int $offset = 0, int $limit = null): array
    {
        global $wpdb;
        
        $limit = $limit ?: $this->batchSize;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $orders = $wpdb->get_results($wpdb->prepare("
            SELECT o.*, 
                   c.user_id as woo_customer_user_id,
                   fc.id as fluent_customer_id
            FROM {$wpdb->prefix}wc_orders o
            LEFT JOIN {$wpdb->prefix}wc_customer_lookup c ON o.customer_id = c.customer_id
            LEFT JOIN {$wpdb->prefix}fct_customers fc ON c.user_id = fc.user_id
            WHERE o.type = 'shop_order'
            AND NOT EXISTS (
                SELECT 1 FROM {$wpdb->prefix}fct_orders fo 
                WHERE fo.invoice_no = CONCAT('WC-', o.id)
            )
            ORDER BY o.id ASC
            LIMIT %d OFFSET %d
        ", $limit, $offset));

        return $orders;
    }

    /**
     * Migrate a single order
     */
    public function migrateSingle($wooOrder): bool
    {
        global $wpdb;

        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query('START TRANSACTION');

            // 1. Migrate main order record
            $fluentOrderId = $this->migrateOrderRecord($wooOrder);
            if (!$fluentOrderId) {
                throw new \Exception("Failed to migrate order record for WooCommerce order {$wooOrder->id}");
            }

            // 2. Migrate order addresses
            $this->migrateOrderAddresses($wooOrder->id, $fluentOrderId);

            // 3. Migrate order items (products, shipping, fees, coupons)
            $this->migrateOrderItems($wooOrder->id, $fluentOrderId, $wooOrder->customer_id);

            // 4. Migrate order metadata
            $this->migrateOrderMeta($wooOrder->id, $fluentOrderId);

            // 5. Update order totals and validate
            $this->updateOrderTotals($fluentOrderId);

            // 6. Update customer purchase statistics
            if ($wooOrder->fluent_customer_id) {
                $this->updateCustomerPurchaseStats($wooOrder->fluent_customer_id);
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query('COMMIT');
            
            $this->incrementMigrated();
            return true;

        } catch (\Exception $e) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query('ROLLBACK');
            $this->addError("Order {$wooOrder->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Migrate main order record
     */
    private function migrateOrderRecord($wooOrder): ?int
    {
        global $wpdb;

        // Convert status
        $status = $this->convertOrderStatus($wooOrder->status);
        $paymentStatus = $this->convertPaymentStatus($wooOrder->status);

        // Convert amounts to cents
        $subtotal = $this->convertToCents($wooOrder->total_amount - $wooOrder->tax_amount - $wooOrder->shipping_amount);
        $taxTotal = $this->convertToCents($wooOrder->tax_amount);
        $shippingTotal = $this->convertToCents($wooOrder->shipping_amount);
        $totalAmount = $this->convertToCents($wooOrder->total_amount);
        $discountTotal = $this->convertToCents($wooOrder->discount_amount);

        $orderData = [
            'parent_id' => 0,
            'customer_id' => $wooOrder->fluent_customer_id,
            'status' => $status,
            'payment_status' => $paymentStatus,
            'fulfillment_type' => 'physical', // Default, will be updated based on items
            'type' => 'checkout',
            'mode' => 'live',
            'payment_method' => $wooOrder->payment_method ?: 'unknown',
            'payment_method_title' => $wooOrder->payment_method_title ?: 'Unknown',
            'currency' => $wooOrder->currency ?: 'USD',
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'shipping_total' => $shippingTotal,
            'manual_discount_total' => 0,
            'coupon_discount_total' => $discountTotal, // Same as discount for now
            'total_amount' => $totalAmount,
            'total_paid' => $paymentStatus === 'paid' ? $totalAmount : 0,
            'shipping_status' => 'unshipped',
            'rate' => 1.0000,
            'uuid' => wp_generate_uuid4(),
            'invoice_no' => 'WC-' . $wooOrder->id,
            'created_at' => $wooOrder->date_created_gmt ?: current_time('mysql', true),
            'updated_at' => $wooOrder->date_modified_gmt ?: current_time('mysql', true),
        ];

        // Remove null customer_id if guest order
        if (!$orderData['customer_id']) {
            unset($orderData['customer_id']);
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->insert($wpdb->prefix . 'fct_orders', $orderData);
        
        if ($result === false) {
            throw new \Exception(
                sprintf(
                    'Failed to insert order record: %s',
                    esc_html(sanitize_text_field($wpdb->last_error))
                )
            );
        }

        return $wpdb->insert_id;
    }

    /**
     * Migrate order addresses
     */
    private function migrateOrderAddresses(int $wooOrderId, int $fluentOrderId): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $addresses = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}wc_order_addresses 
            WHERE order_id = %d
        ", $wooOrderId));

        foreach ($addresses as $address) {
            $addressData = [
                'order_id' => $fluentOrderId,
                'type' => $address->address_type ?: 'billing',
                'name' => trim(($address->first_name ?: '') . ' ' . ($address->last_name ?: '')),
                'address_1' => $address->address_1 ?: '',
                'address_2' => $address->address_2 ?: '',
                'city' => $address->city ?: '',
                'state' => $address->state ?: '',
                'postcode' => $address->postcode ?: '',
                'country' => $address->country ?: '',
                'created_at' => current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
            ];
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->insert($wpdb->prefix . 'fct_order_addresses', $addressData);
        }
    }

    /**
     * Migrate order items (products, shipping, fees, coupons)
     */
    private function migrateOrderItems(int $wooOrderId, int $fluentOrderId, ?int $customerId): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $items = $wpdb->get_results($wpdb->prepare("
            SELECT oi.*, oim.meta_key, oim.meta_value
            FROM {$wpdb->prefix}woocommerce_order_items oi
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
            WHERE oi.order_id = %d
            ORDER BY oi.order_item_id, oim.meta_id
        ", $wooOrderId));

        // Group items by ID and type
        $groupedItems = [];
        foreach ($items as $item) {
            if (!isset($groupedItems[$item->order_item_id])) {
                $groupedItems[$item->order_item_id] = [
                    'item' => $item,
                    'meta' => []
                ];
            }
            if ($item->meta_key) {
                $groupedItems[$item->order_item_id]['meta'][$item->meta_key] = $item->meta_value;
            }
        }

        // Process each item type
        foreach ($groupedItems as $itemData) {
            $item = $itemData['item'];
            $meta = $itemData['meta'];

            switch ($item->order_item_type) {
                case 'line_item':
                    $this->migrateProductItem($item, $meta, $fluentOrderId);
                    break;
                    
                case 'coupon':
                    $this->migrateCouponItem($item, $meta, $fluentOrderId, $customerId);
                    break;
                    
                case 'fee':
                    $this->migrateFeeItem($item, $meta, $fluentOrderId);
                    break;
                    
                case 'shipping':
                    // Shipping is handled in order totals, but we can store method info
                    $this->migrateShippingMeta($item, $meta, $fluentOrderId);
                    break;
            }
        }
    }

    /**
     * Migrate product line item
     */
    private function migrateProductItem($item, array $meta, int $fluentOrderId): void
    {
        global $wpdb;

        $productId = (int) Arr::get($meta, '_product_id', 0);
        $variationId = (int) Arr::get($meta, '_variation_id', 0);
        $quantity = (int) Arr::get($meta, '_qty', 1);
        
        // Handle variation ID (0 means simple product)
        $objectId = $variationId > 0 ? $variationId : null;
        
        // Convert amounts
        $lineTotal = $this->convertToCents(Arr::get($meta, '_line_total', 0));
        $subtotal = $this->convertToCents(Arr::get($meta, '_line_subtotal', 0));
        $unitPrice = $quantity > 0 ? intval($lineTotal / $quantity) : 0;
        $discountTotal = $subtotal - $lineTotal;

        $itemData = [
            'order_id' => $fluentOrderId,
            'post_id' => $productId,
            'object_id' => $objectId,
            'post_title' => $item->order_item_name,
            'title' => $item->order_item_name,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'subtotal' => $subtotal,
            'line_total' => $lineTotal,
            'discount_total' => $discountTotal,
            'tax_amount' => $this->convertToCents(Arr::get($meta, '_line_tax', 0)),
            'fulfillment_type' => 'physical', // Default
            'payment_type' => 'onetime', // Default
            'cart_index' => 1,
            'cost' => 0,
            'shipping_charge' => 0,
            'refund_total' => 0,
            'rate' => 1,
            'fulfilled_quantity' => 0,
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert($wpdb->prefix . 'fct_order_items', $itemData);
    }

    /**
     * Migrate coupon line item to FluentCart coupon system
     */
    private function migrateCouponItem($item, array $meta, int $fluentOrderId, ?int $customerId): void
    {
        global $wpdb;

        $couponCode = $item->order_item_name;
        $discountAmount = $this->convertToCents(Arr::get($meta, 'discount_amount', 0));

        if (empty($couponCode) || $discountAmount <= 0) {
            return; // Skip invalid coupons
        }

        // Create coupon if it doesn't exist
        $couponId = $this->getOrCreateCoupon($couponCode, $discountAmount);

        // Insert applied coupon
        $appliedCouponData = [
            'order_id' => $fluentOrderId,
            'coupon_id' => $couponId,
            'customer_id' => $customerId,
            'code' => $couponCode,
            'amount' => $discountAmount,
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert($wpdb->prefix . 'fct_applied_coupons', $appliedCouponData);
    }

    /**
     * Migrate fee line item as custom order item
     */
    private function migrateFeeItem($item, array $meta, int $fluentOrderId): void
    {
        global $wpdb;

        $feeAmount = $this->convertToCents(Arr::get($meta, '_fee_amount', 0));
        $feeTax = $this->convertToCents(Arr::get($meta, '_line_tax', 0));
        $feeTotal = $feeAmount + $feeTax;

        if ($feeTotal <= 0) {
            return; // Skip zero fees
        }

        $itemData = [
            'order_id' => $fluentOrderId,
            'post_id' => 0,
            'object_id' => null,
            'post_title' => $item->order_item_name,
            'title' => $item->order_item_name,
            'quantity' => 1,
            'unit_price' => $feeTotal,
            'subtotal' => $feeAmount,
            'line_total' => $feeTotal,
            'discount_total' => 0,
            'tax_amount' => $feeTax,
            'fulfillment_type' => 'digital', // Fees are typically service-based
            'payment_type' => 'onetime',
            'cart_index' => 999, // Put fees at the end
            'cost' => 0,
            'shipping_charge' => 0,
            'refund_total' => 0,
            'rate' => 1,
            'fulfilled_quantity' => 1, // Fees are immediately fulfilled
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert($wpdb->prefix . 'fct_order_items', $itemData);
    }

    /**
     * Store shipping method info as order metadata
     */
    private function migrateShippingMeta($item, array $meta, int $fluentOrderId): void
    {
        global $wpdb;

        $shippingData = [
            'method_title' => $item->order_item_name,
            'method_id' => Arr::get($meta, 'method_id', ''),
            'cost' => Arr::get($meta, 'cost', 0),
            'taxes' => Arr::get($meta, 'taxes', ''),
        ];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert($wpdb->prefix . 'fct_order_meta', [
            'order_id' => $fluentOrderId,
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_key' => 'shipping_method',
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'meta_value' => json_encode($shippingData),
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ]);
    }

    /**
     * Migrate order metadata
     */
    private function migrateOrderMeta(int $wooOrderId, int $fluentOrderId): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $metaData = $wpdb->get_results($wpdb->prepare("
            SELECT meta_key, meta_value 
            FROM {$wpdb->prefix}wc_orders_meta 
            WHERE order_id = %d
        ", $wooOrderId));

        // Important meta keys to migrate
        $importantKeys = [
            '_payment_method_title',
            '_transaction_id',
            '_customer_note',
            '_order_key',
            '_billing_phone',
            '_shipping_phone',
            '_order_version',
            '_cart_hash',
            '_utm_source',
            '_utm_medium',
            '_utm_campaign',
        ];

        foreach ($metaData as $meta) {
            if (in_array($meta->meta_key, $importantKeys)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->insert($wpdb->prefix . 'fct_order_meta', [
                    'order_id' => $fluentOrderId,
                    //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                    'meta_key' => $meta->meta_key,
                    //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                    'meta_value' => $meta->meta_value,
                    'created_at' => current_time('mysql', true),
                    'updated_at' => current_time('mysql', true),
                ]);
            }
        }
    }

    /**
     * Update order totals and validate
     */
    private function updateOrderTotals(int $fluentOrderId): void
    {
        global $wpdb;

        // Recalculate totals from migrated items
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $totals = $wpdb->get_row($wpdb->prepare("
            SELECT 
                SUM(subtotal) as calculated_subtotal,
                SUM(line_total) as calculated_total,
                SUM(tax_amount) as calculated_tax,
                SUM(discount_total) as calculated_discount
            FROM {$wpdb->prefix}fct_order_items 
            WHERE order_id = %d
        ", $fluentOrderId));

        // Update order with calculated totals (for validation)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $wpdb->prefix . 'fct_orders',
            [
                'item_count' => $wpdb->get_var($wpdb->prepare("
                    SELECT SUM(quantity) FROM {$wpdb->prefix}fct_order_items 
                    WHERE order_id = %d AND post_id > 0
                ", $fluentOrderId)),
                'updated_at' => current_time('mysql', true),
            ],
            ['id' => $fluentOrderId]
        );
    }

    /**
     * Update customer purchase statistics after order migration
     */
    private function updateCustomerPurchaseStats(int $customerId): void
    {
        global $wpdb;

        // Calculate customer order statistics grouped by currency
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $orderStats = $wpdb->get_results($wpdb->prepare("
            SELECT 
                currency,
                COUNT(*) as order_count,
                SUM(total_amount) as total_purchase_value,
                AVG(total_amount) as average_order_value,
                MIN(created_at) as first_purchase_date,
                MAX(created_at) as last_purchase_date
            FROM {$wpdb->prefix}fct_orders 
            WHERE customer_id = %d 
            AND status NOT IN ('failed', 'cancelled')
            GROUP BY currency
        ", $customerId));

        if (!empty($orderStats)) {
            // Build purchase_value JSON object by currency
            $purchaseValueByCurrency = [];
            $totalOrderCount = 0;
            $allAmounts = [];
            $firstDate = null;
            $lastDate = null;

            foreach ($orderStats as $currencyStats) {
                $purchaseValueByCurrency[$currencyStats->currency] = (int) $currencyStats->total_purchase_value;
                $totalOrderCount += (int) $currencyStats->order_count;
                $allAmounts[] = (int) $currencyStats->average_order_value;
                
                if (!$firstDate || $currencyStats->first_purchase_date < $firstDate) {
                    $firstDate = $currencyStats->first_purchase_date;
                }
                if (!$lastDate || $currencyStats->last_purchase_date > $lastDate) {
                    $lastDate = $currencyStats->last_purchase_date;
                }
            }

            $averageOrderValue = !empty($allAmounts) ? intval(array_sum($allAmounts) / count($allAmounts)) : 0;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $wpdb->prefix . 'fct_customers',
                [
                    'purchase_count' => $totalOrderCount,
                    'purchase_value' => json_encode($purchaseValueByCurrency),
                    'aov' => $averageOrderValue,
                    'first_purchase_date' => $firstDate,
                    'last_purchase_date' => $lastDate,
                    'updated_at' => current_time('mysql', true),
                ],
                ['id' => $customerId]
            );
        }
    }

    /**
     * Get or create coupon in FluentCart
     */
    private function getOrCreateCoupon(string $couponCode, int $discountAmount): int
    {
        global $wpdb;

        // Check if coupon exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $existingCoupon = $wpdb->get_var($wpdb->prepare("
            SELECT id FROM {$wpdb->prefix}fct_coupons 
            WHERE code = %s
        ", strtoupper($couponCode)));

        if ($existingCoupon) {
            return (int) $existingCoupon;
        }

        // Create new coupon with basic settings
        $couponData = [
            'title' => 'Migrated: ' . $couponCode,
            'code' => strtoupper($couponCode),
            'type' => 'fixed', // Default to fixed amount
            'amount' => $discountAmount,
            'status' => 'active',
            'stackable' => 'yes',
            'priority' => 10,
            'use_count' => 0,
            'notes' => 'Auto-created during WooCommerce migration',
            'show_on_checkout' => 'no', // Don't show migrated coupons in checkout
            'conditions' => json_encode([
                'min_purchase_amount' => 0,
                'max_discount_amount' => null,
                'apply_to_whole_cart' => 'yes',
            ]),
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert($wpdb->prefix . 'fct_coupons', $couponData);
        return $wpdb->insert_id;
    }

    /**
     * Convert WooCommerce order status to FluentCart status
     */
    private function convertOrderStatus(string $wooStatus): string
    {
        $statusMap = [
            'wc-completed' => 'completed',
            'wc-pending' => 'pending',
            'wc-processing' => 'processing',
            'wc-on-hold' => 'on-hold',
            'wc-cancelled' => 'failed',
            'wc-refunded' => 'refunded',
            'wc-failed' => 'failed',
        ];

        return $statusMap[$wooStatus] ?? 'pending';
    }

    /**
     * Convert WooCommerce order status to FluentCart payment status
     */
    private function convertPaymentStatus(string $wooStatus): string
    {
        $paymentStatusMap = [
            'wc-completed' => 'paid',
            'wc-processing' => 'paid',
            'wc-pending' => 'pending',
            'wc-on-hold' => 'pending',
            'wc-cancelled' => 'failed',
            'wc-refunded' => 'refunded',
            'wc-failed' => 'failed',
        ];

        return $paymentStatusMap[$wooStatus] ?? 'pending';
    }



    /**
     * Check if WooCommerce is active
     */
    private function isWooCommerceActive(): bool
    {
        return class_exists('WooCommerce');
    }

    /**
     * Check if HPOS is active
     */
    private function isHPOSActive(): bool
    {
        return class_exists('Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore') 
               && get_option('woocommerce_custom_orders_table_enabled') === 'yes';
    }

    /**
     * Check if dependencies are migrated
     */
    private function areDependenciesMigrated(): bool
    {
        global $wpdb;

        // Check if customers are migrated
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $customerCount = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_customers");
        if ($customerCount == 0) {
            return false;
        }

        // Check if products/variations exist
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $productCount = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_product_variations");
        if ($productCount == 0) {
            return false;
        }

        return true;
    }

    /**
     * Get cleanup instructions
     */
    public function getCleanupInstructions(): array
    {
        return [
            'description' => 'Remove migrated FluentCart orders and related data',
            'operations' => [
                'Delete from wp_fct_orders WHERE invoice_no LIKE "WC-%"',
                'Delete from wp_fct_order_items WHERE order_id IN (migrated orders)',
                'Delete from wp_fct_order_addresses WHERE order_id IN (migrated orders)',
                'Delete from wp_fct_applied_coupons WHERE order_id IN (migrated orders)',
                'Delete from wp_fct_order_meta WHERE order_id IN (migrated orders)',
                'Delete auto-created coupons with notes containing "Auto-created during WooCommerce migration"',
            ],
            'warning' => 'This will permanently remove all migrated order data from FluentCart'
        ];
    }

    /**
     * Check if the migration dependencies are met
     */
    public function checkDependencies(): bool
    {
        return $this->canMigrate();
    }

    /**
     * Run the migration
     */
    public function migrate(array $options = []): array
    {
        if (!$this->canMigrate()) {
            return [
                'success' => 0,
                'failed' => 0,
                'skipped' => 0,
                'errors' => $this->getErrors(),
                'warnings' => []
            ];
        }

        $this->initStats();
        $totalCount = $this->getTotalCount();
        $this->stats['total'] = $totalCount;

        $offset = 0;
        $batchSize = $this->batchSize;

        while (true) {
            $orders = $this->discoverItems($offset, $batchSize);
            
            if (empty($orders)) {
                break; // No more orders to process
            }

            foreach ($orders as $order) {
                if ($this->migrateSingle($order)) {
                    $this->stats['success']++;
                } else {
                    $this->stats['failed']++;
                }
            }

            $offset += $batchSize;
        }

        $this->finalizeStats();

        return [
            'success' => $this->stats['success'],
            'failed' => $this->stats['failed'],
            'skipped' => $this->stats['skipped'],
            'errors' => $this->errors,
            'warnings' => []
        ];
    }

    /**
     * Update all customer purchase statistics (useful for existing migrations)
     */
    public function updateAllCustomerStats(): array
    {
        global $wpdb;

        $updatedCount = 0;
        $errorCount = 0;

        // Get all customers who have migrated orders
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $customers = $wpdb->get_results("
            SELECT DISTINCT c.id 
            FROM {$wpdb->prefix}fct_customers c
            INNER JOIN {$wpdb->prefix}fct_orders o ON c.id = o.customer_id
            WHERE o.invoice_no LIKE 'WC-%'
        ");

        foreach ($customers as $customer) {
            try {
                $this->updateCustomerPurchaseStats($customer->id);
                $updatedCount++;
            } catch (\Exception $e) {
                $errorCount++;
                $this->addError("Failed to update customer {$customer->id}: " . $e->getMessage());
            }
        }

        return [
            'updated' => $updatedCount,
            'errors' => $errorCount,
            'message' => "Updated statistics for {$updatedCount} customers" . ($errorCount > 0 ? " with {$errorCount} errors" : "")
        ];
    }

    /**
     * Clean up migration data (for fresh migrations)
     */
    public function cleanup(): bool
    {
        global $wpdb;

        try {
            // Remove migrated orders and related data
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query("DELETE FROM {$wpdb->prefix}fct_orders WHERE invoice_no LIKE 'WC-%'");
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query("DELETE FROM {$wpdb->prefix}fct_order_items WHERE order_id NOT IN (SELECT id FROM {$wpdb->prefix}fct_orders)");
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query("DELETE FROM {$wpdb->prefix}fct_order_addresses WHERE order_id NOT IN (SELECT id FROM {$wpdb->prefix}fct_orders)");
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query("DELETE FROM {$wpdb->prefix}fct_applied_coupons WHERE order_id NOT IN (SELECT id FROM {$wpdb->prefix}fct_orders)");
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query("DELETE FROM {$wpdb->prefix}fct_order_meta WHERE order_id NOT IN (SELECT id FROM {$wpdb->prefix}fct_orders)");
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query("DELETE FROM {$wpdb->prefix}fct_coupons WHERE notes LIKE '%Auto-created during WooCommerce migration%'");

            return true;
        } catch (\Exception $e) {
            $this->addError('Cleanup failed: ' . $e->getMessage());
            return false;
        }
    }
} 

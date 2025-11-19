<?php

namespace FluentCart\App\Modules\WooCommerceMigrator\Services;

use FluentCart\App\Modules\WooCommerceMigrator\Contracts\MigrationServiceInterface;
use FluentCart\Framework\Support\Arr;

abstract class BaseMigrationService implements MigrationServiceInterface
{
    protected $stats = [
        'total' => 0,
        'success' => 0,
        'failed' => 0,
        'skipped' => 0,
        'start_time' => null,
        'end_time' => null
    ];

    protected $errors = [];

    /**
     * Initialize migration statistics
     */
    protected function initStats()
    {
        $this->stats['start_time'] = microtime(true);
        $this->stats['total'] = 0;
        $this->stats['success'] = 0;
        $this->stats['failed'] = 0;
        $this->stats['skipped'] = 0;
        $this->errors = [];
    }

    /**
     * Finalize migration statistics
     */
    protected function finalizeStats()
    {
        $this->stats['end_time'] = microtime(true);
        $this->stats['duration'] = round($this->stats['end_time'] - $this->stats['start_time'], 2);
    }

    /**
     * Log an error during migration
     *
     * @param string $message
     * @param mixed $context
     */
    protected function logError($message, $context = null)
    {
        $this->stats['failed']++;
        $this->errors[] = [
            'message' => $message,
            'context' => $context,
            'time' => current_time('mysql')
        ];
    }

    /**
     * Log a successful migration
     *
     * @param string $message
     */
    protected function logSuccess($message)
    {
        $this->stats['success']++;
    }

    /**
     * Log a skipped migration
     *
     * @param string $message
     */
    protected function logSkipped($message)
    {
        $this->stats['skipped']++;
    }

    /**
     * Check if WooCommerce is active and available
     *
     * @return bool
     */
    protected function checkWooCommerceDependencies(): bool
    {
        if (!class_exists('WooCommerce')) {
            $this->logError('WooCommerce is not active or installed');
            return false;
        }

        global $wpdb;
        
        // Check if required WooCommerce tables exist
        $tables = [
            $wpdb->prefix . 'posts',
            $wpdb->prefix . 'postmeta',
            $wpdb->prefix . 'woocommerce_order_items',
            $wpdb->prefix . 'woocommerce_order_itemmeta'
        ];

        foreach ($tables as $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Safe as it's just a table name
            $result = $wpdb->get_var(
                $wpdb->prepare("SHOW TABLES LIKE %s", $table)
            );
            if ($result !== $table) {
                $this->logError("Required table {$table} does not exist");
                return false;
            }
        }

        return true;
    }

    /**
     * Get migration statistics
     *
     * @return array
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'errors' => $this->errors,
            'success_rate' => $this->stats['total'] > 0 ? 
                round(($this->stats['success'] / $this->stats['total']) * 100, 2) : 0
        ]);
    }

    /**
     * Convert WooCommerce price to FluentCart cents
     *
     * @param float|string $price
     * @return int
     */
    protected function convertToCents($price): int
    {
        if (empty($price)) {
            return 0;
        }
        return round(floatval($price) * 100);
    }

    /**
     * Get or create mapping between WooCommerce and FluentCart IDs
     *
     * @param string $mapKey Option key for storing the mapping
     * @param int $wooId WooCommerce ID
     * @param int $fluentId FluentCart ID (null to just retrieve)
     * @return int|null FluentCart ID if found
     */
    protected function getOrSetMapping($mapKey, $wooId, $fluentId = null)
    {
        $mapping = get_option($mapKey, []);
        
        if ($fluentId !== null) {
            $mapping[$wooId] = $fluentId;
            update_option($mapKey, $mapping);
            return $fluentId;
        }
        
        return $mapping[$wooId] ?? null;
    }

    /**
     * Clear all mappings for this migration type
     *
     * @param string $mapKey Option key for the mapping
     */
    protected function clearMapping($mapKey)
    {
        delete_option($mapKey);
    }
} 

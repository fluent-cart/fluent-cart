<?php

namespace FluentCart\App\Modules\WooCommerceMigrator;

class WooCommerceMigratorHelper
{
    public static function doBulkInsert($table, $data)
    {
        if (empty($data)) {
            return false;
        }
        
        global $wpdb;
        $firstRow = reset($data);
        $columns = array_keys($firstRow);
        $values = [];
        $placeHolders = [];
        
        foreach ($data as $row) {
            $rowPlaceholders = [];
            foreach ($columns as $column) {
                $values[] = $row[$column];
                $rowPlaceholders[] = is_numeric($row[$column]) ? '%d' : '%s';
            }
            $placeHolders[] = '(' . implode(',', $rowPlaceholders) . ')';
        }
        
        $query = "INSERT INTO {$wpdb->prefix}{$table} (`" . implode('`,`', $columns) . "`) VALUES " . implode(',', $placeHolders);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared on this line with values
        return $wpdb->query($wpdb->prepare($query, $values));
    }

    public static function logMigrationError($productId, $error)
    {
        $failedLogs = get_option('_fluent_wc_failed_migration_logs', []);
        $failedLogs[$productId] = is_wp_error($error) ? $error->get_error_message() : $error;
        update_option('_fluent_wc_failed_migration_logs', $failedLogs);
    }

    public static function updateMigrationStatus($step, $status)
    {
        $migrationSteps = get_option('__fluent_cart_wc_migration_steps', []);
        $migrationSteps[$step] = $status;
        update_option('__fluent_cart_wc_migration_steps', $migrationSteps);
    }

    public static function checkRequiredTables()
    {
        global $wpdb;
        $requiredTables = [
            'fct_product_details',
            'fct_product_variations',
            'fct_product_downloads'
        ];
        
        foreach ($requiredTables as $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}{$table}'") != $wpdb->prefix . $table) {
                return new \WP_Error('wc_migrator_error', "Required table {$table} does not exist.");
            }
        }
        
        return true;
    }


} 

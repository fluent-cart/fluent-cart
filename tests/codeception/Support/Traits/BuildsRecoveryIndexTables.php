<?php

namespace Tests\Support\Traits;

use FluentCart\Database\Migrations\CartMigrator;
use FluentCart\Database\Migrations\OrderDownloadPermissionsMigrator;
use FluentCart\Database\Migrations\SubscriptionsMigrator;
use FluentCart\Framework\Database\Schema;

trait BuildsRecoveryIndexTables
{
    protected array $recoveryIndexTables = [];

    protected function createRecoveryIndexTables(bool $legacy = true): void
    {
        foreach ([SubscriptionsMigrator::class, OrderDownloadPermissionsMigrator::class, CartMigrator::class] as $class) {
            $this->recoveryIndexTables[$class] = $class::$tableName;
            $class::$tableName = 'fct_index_test_' . substr(md5($class), 0, 8);
            $sql = $class::getSqlSchema();
            if ($legacy) {
                $sql = preg_replace('/,\s*INDEX `' . preg_quote($class::CUSTOMER_RECOVERY_INDEX, '/') . '` \([^)]*\)/', '', $sql);
            }
            Schema::createTableIfNotExist($class::getTableName(), $sql);
        }
    }

    protected function dropRecoveryIndexTables(): void
    {
        global $wpdb;
        foreach ($this->recoveryIndexTables as $class => $original) {
            $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $class::getTableName()));
            $class::$tableName = $original;
        }
        $this->recoveryIndexTables = [];
    }

    protected function seedRecoveryIndexRows(string $class, int $count): void
    {
        global $wpdb;
        for ($i = 0; $i < $count; $i++) {
            $row = ['customer_id' => $i % 500 === 0 ? 42 : 1000 + $i];
            if ($class === CartMigrator::class) {
                $row['cart_hash'] = sprintf('cart-%08d', $i);
            } elseif ($class === SubscriptionsMigrator::class) {
                $row += ['uuid' => 'sub-' . $i, 'parent_order_id' => $i + 1, 'product_id' => 1, 'variation_id' => 1, 'item_name' => 'Index fixture'];
            } else {
                $row += ['order_id' => $i + 1, 'variation_id' => 1, 'download_id' => 1];
            }
            if ($wpdb->insert($class::getTableName(), $row) === false) {
                throw new \RuntimeException($wpdb->last_error);
            }
        }
    }
}

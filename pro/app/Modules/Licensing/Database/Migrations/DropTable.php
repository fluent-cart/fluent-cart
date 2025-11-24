<?php

namespace FluentCartPro\App\Modules\Licensing\Database\Migrations;

trait DropTable
{

    public static function getTableName()
    {
        global $wpdb;
        return $wpdb->prefix . static::$tableName;
    }

    public static function dropTable()
    {
        global $wpdb;
        //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if (in_array( static::getTableName(), $wpdb->tables)) {
            $wpdb->query("ALTER TABLE " . static::getTableName() . " DISABLE KEYS;");
        }
        //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query("DROP TABLE IF EXISTS " . static::getTableName());
    }

}
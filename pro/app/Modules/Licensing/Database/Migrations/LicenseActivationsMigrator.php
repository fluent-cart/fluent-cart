<?php

namespace FluentCartPro\App\Modules\Licensing\Database\Migrations;

class LicenseActivationsMigrator
{

    use DropTable;

    public static $tableName = 'fct_license_activations';
	/**
	 * Migrate the table.
	 *
	 * @return void
	 */
	public static function migrate()
    {
		global $wpdb;

		$charsetCollate = $wpdb->get_charset_collate();

        $table = static::getTableName();

		$indexPrefix = $wpdb->prefix . 'fct_sl_ac_';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) != $table ) {
			$sql = "CREATE TABLE $table (
                `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `site_id` BIGINT(20) UNSIGNED NOT NULL,
                `license_id` BIGINT(20) UNSIGNED NOT NULL,
                `status` VARCHAR(45) NULL DEFAULT 'active',
                `is_local` TINYINT NULL DEFAULT 0,
                `product_id` BIGINT(20) UNSIGNED NOT NULL,
                `variation_id` BIGINT(20) UNSIGNED NOT NULL,
                `activation_method` VARCHAR(45) NULL DEFAULT 'key_based',
                `activation_hash` VARCHAR(99) NULL DEFAULT '',
                `last_update_version` VARCHAR(45) NULL DEFAULT '',
                `last_update_date` DATETIME NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                 INDEX `{$indexPrefix}_license_id_idx` (`license_id` ASC),
                 INDEX `{$indexPrefix}_site_id_idx` (`site_id` ASC)
            ) $charsetCollate;";

			dbDelta( $sql );
		}
	}
}

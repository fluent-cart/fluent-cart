<?php

namespace FluentCartPro\App\Modules\Licensing\Database\Migrations;
class LicenseMetaMigrator
{

    use DropTable;

    public static $tableName = 'fct_license_meta';
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

		$indexPrefix = $wpdb->prefix . 'fct_slm_';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) != $table ) {
			$sql = "CREATE TABLE $table (
                `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `object_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                `object_type` VARCHAR(100) NULL DEFAULT NULL COMMENT 'Can be: license / avtivation / site',
                `meta_key` VARCHAR(255) NULL DEFAULT NULL,
                `meta_value` LONGTEXT NULL DEFAULT NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                
                 INDEX `{$indexPrefix}_meta_key` (`meta_key`),
                 INDEX `{$indexPrefix}_object_type` (`object_type`),
                 INDEX `{$indexPrefix}_obj_id` (`object_id`)
            ) $charsetCollate;";

			dbDelta( $sql );
		}
	}
}






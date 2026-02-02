<?php

namespace FluentCartPro\App\Modules\Licensing\Database\Migrations;
class LicenseSitesMigrator
{

    use DropTable;

    public static $tableName = 'fct_license_sites';
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

		$indexPrefix = $wpdb->prefix . 'fct_sls_';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) != $table ) {
			$sql = "CREATE TABLE $table (
                `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `site_url` VARCHAR(192) NULL DEFAULT NULL,
                `server_version` VARCHAR(50) NULL DEFAULT NULL,
                `platform_version` VARCHAR(50) NULL DEFAULT NULL,
                `other` JSON NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                 INDEX `{$indexPrefix}_site_url` (`site_url`)
            ) $charsetCollate;";

			dbDelta( $sql );
		}
	}
}






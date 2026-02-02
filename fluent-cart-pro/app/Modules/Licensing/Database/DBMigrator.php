<?php

namespace FluentCartPro\App\Modules\Licensing\Database;

use FluentCartPro\App\Modules\Licensing\Database\Migrations\LicenseActivationsMigrator;
use FluentCartPro\App\Modules\Licensing\Database\Migrations\LicenseMetaMigrator;
use FluentCartPro\App\Modules\Licensing\Database\Migrations\LicenseSitesMigrator;
use FluentCartPro\App\Modules\Licensing\Database\Migrations\LicensesMigrator;

require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

class DBMigrator
{
    public function run($networkWide = false)
    {
        $this->migrate();
    }

    public function migrate()
    {
        LicensesMigrator::migrate();
        LicenseSitesMigrator::migrate();
        LicenseActivationsMigrator::migrate();
        LicenseMetaMigrator::migrate();
    }

    public function dropTable()
    {
        LicensesMigrator::dropTable();
        LicenseSitesMigrator::dropTable();
        LicenseActivationsMigrator::dropTable();
        LicenseMetaMigrator::dropTable();
    }

}

<?php

namespace FluentCart\Database;

use FluentCart\App\App;
use FluentCart\Database\Seeder\CouponSeeder;
use FluentCart\Database\Seeder\CustomerAddressSeeder;
use FluentCart\Database\Seeder\OrderAddressSeeder;
use FluentCart\Database\Seeder\OrderOperationSeeder;
use FluentCart\Database\Seeder\ProductSeeder;
use FluentCart\Database\Seeder\CustomerSeeder;
use FluentCart\Database\Seeder\OrderSeeder;
use FluentCart\Database\Seeder\AppliedCouponsSeeder;
use FluentCart\Database\Seeder\OrderMetaSeeder;
use FluentCart\Database\Seeder\SubscriptionSeeder;
use FluentCart\Database\Seeder\TaxSeeder;

class DBSeeder
{
    public static function run($count = 10, $entity = null, $checkDev = true, $assoc_args = [])
    {
        static::ensureFakerAliases();

        $seeders = [
            'customer'         => CustomerSeeder::class,
            'customer_address' => CustomerAddressSeeder::class,
            'coupon'           => CouponSeeder::class,
            'product'          => ProductSeeder::class,
            'order'            => OrderSeeder::class,
            'order_operation'  => OrderOperationSeeder::class,
            'order_address'    => OrderAddressSeeder::class,
            'subscription'     => SubscriptionSeeder::class,
            'tax'              => TaxSeeder::class,
        ];


        if (empty($entity)) {
            foreach ($seeders as $value) {
                /**
                 * @var CustomerSeeder|ProductSeeder|OrderSeeder|OrderOperationSeeder|OrderAddressSeeder|CouponSeeder|SubscriptionSeeder $value
                 */
                $value::seed($count, $assoc_args);
            }
        } else {
            if ($entity === 'order') {
                /**
                 * @var OrderSeeder $seeders ['order']
                 */
                $seeders['order']::seed($count, $assoc_args);
                $seeders['order_operation']::seed($count, $assoc_args);
            } elseif (isset($seeders[$entity])) {
                /**
                 * @var CustomerSeeder|ProductSeeder|OrderAddressSeeder $seeders ['order']
                 */
                $seeders[$entity]::seed($count, $assoc_args);
            }
        }

    }

    /**
     * Boot Faker and make the namespace the seeders import resolvable.
     *
     * Two things stand between the seeders and Faker in a normal install:
     *
     * 1. The generated Composer autoloader is classmap-authoritative and
     *    composer.json excludes `/vendor/fakerphp/` from the classmap, so
     *    `Faker\Factory` never autoloads. Faker ships its own PSR-0 autoloader,
     *    so register that instead of touching the Composer config.
     * 2. Every seeder imports `FluentCart\Faker\Factory` (and ProductNameProvide
     *    extends `FluentCart\Faker\Provider\Base`). That prefixed namespace only
     *    exists once dev/ComposerScript rewrites the vendor autoloader, and
     *    composer.json sets extra.wpfluent.excludes to ["*"], which skips generic
     *    packages such as fakerphp/faker — so alias the upstream classes onto it.
     *
     * Without this, every seeder fatals with
     * "Class FluentCart\Faker\Factory not found".
     *
     * @return void
     */
    protected static function ensureFakerAliases()
    {
        if (class_exists('FluentCart\\Faker\\Factory')) {
            return;
        }

        if (!class_exists('Faker\\Factory')) {
            $fakerAutoload = FLUENTCART_PLUGIN_PATH . 'vendor/fakerphp/faker/src/autoload.php';

            if (!file_exists($fakerAutoload)) {
                return;
            }

            require_once $fakerAutoload;
        }

        $aliases = [
            'Faker\\Factory'         => 'FluentCart\\Faker\\Factory',
            'Faker\\Generator'       => 'FluentCart\\Faker\\Generator',
            'Faker\\Provider\\Base' => 'FluentCart\\Faker\\Provider\\Base',
        ];

        foreach ($aliases as $original => $alias) {
            if (class_exists($original) && !class_exists($alias, false)) {
                class_alias($original, $alias);
            }
        }
    }
}

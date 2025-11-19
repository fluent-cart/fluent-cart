<?php

namespace FluentCart\Database;

use FluentCart\App\App;
use FluentCart\Database\Seeder\AttributeSeeder;
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
}

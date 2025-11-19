<?php

namespace FluentCart\Database\Seeder;

use FluentCart\App\Models\AppliedCoupon;
use FluentCart\Faker\Factory;

class AppliedCouponsSeeder
{
    public static function seed($count)
    {
        $faker = Factory::create();

        $appliedCouponsLists = [];

        if (defined('WP_CLI') && WP_CLI) {
            $progress = \WP_CLI\Utils\make_progress_bar('%CSeeding AppliedCoupons', $count);
        }
        for ($i = 0; $i <= $count - 1; $i++) {
            if($i%2==0){
                $code = 'TEST101';
            }
            else {
                $code = 'TEST102';
            }
            $appliedCouponsLists[] = [
                'title' => $faker->text(200),
                'code' => $code,
                'status' => $faker->text(20),
                'type' => $faker->text(20),
            ];

            if (defined('WP_CLI') && WP_CLI) {
                if ($i !== $count - 1) {
                        $progress->tick();
                }
            }

        }
        AppliedCoupon::insert($appliedCouponsLists);
        if (defined('WP_CLI') && WP_CLI) {
            $progress->tick();
            $progress->finish();
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo \WP_CLI::colorize('%n');
        }
    }
}
<?php

namespace FluentCart\Database\Seeder;

use FluentCart\App\Models\OrderMeta;
use FluentCart\Faker\Factory;

class OrderMetaSeeder
{
    public static function seed($count)
    {
        $faker = Factory::create();

        $metaList = [];

        $json = '{"id":1,"parent":"0","title":"Test1","code":"TEST101","status":"active","type":"fixed","scope":"all","amount_type":"","amount":"500","stackable":"yes","priority":"1","settings":"","max_uses":"100","use_count":"0","max_per_customer":"100","min_purchase_amount":"2000","max_discount_amount":"0","notes":"","start_date":"2024-01-24 00:00:00","end_date":"2024-01-31 00:00:00","created_at":"2024-01-24T08:44:43+00:00","updated_at":"2024-01-24T09:19:46+00:00","discounted_amount":500}';

        if (defined('WP_CLI') && WP_CLI) {
            $progress = \WP_CLI\Utils\make_progress_bar('%CSeeding OrderMeta', $count);
        }
        for ($i = 0; $i <= $count - 1; $i++) {
            $metaList[] = [
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_key' => 'applied_coupon',
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'meta_value' => $json
            ];

            if (defined('WP_CLI') && WP_CLI) {
                if ($i !== $count - 1) {
                        $progress->tick();
                }
            }

        }
        OrderMeta::insert($metaList);
        if (defined('WP_CLI') && WP_CLI) {
            $progress->tick();
            $progress->finish();
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo \WP_CLI::colorize('%n');
        }
    }
}
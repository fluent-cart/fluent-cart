<?php

namespace FluentCart\Database\Seeder;

use FluentCart\App\Models\Coupon;
use FluentCart\App\Services\DateTime\DateTime;

class CouponSeeder
{
    public static function seed($count = 10)
    {
        $types = ['fixed', 'percentage'];

        $titles = [
            'New Year Discount',
            'Summer Sale',
            'Black Friday Deal',
            'Cyber Monday',
            'Holiday Special',
            'Clearance Offer',
            'Buy More Save More',
            'Exclusive Member Offer',
            'Flash Deal',
            'Welcome Gift'
        ];

        $codes = [
            'NEWYEAR2025',
            'SUMMER25',
            'BLACKFRIDAY',
            'CYBERMON',
            'HOLIDAY22',
            'CLEAR50',
            'BUYMORE10',
            'MEMBEREX',
            'FLASH10',
            'WELCOME5'
        ];

        $coupons = [];

        if (defined('WP_CLI') && WP_CLI) {
            $progress = \WP_CLI\Utils\make_progress_bar('%CSeeding Coupons', count($titles));
        }

        for ($i = 0; $i < count($titles); $i++) {
            $type = $types[array_rand($types)];

            // Random start_date within ±100 days
            $startDate = DateTime::gmtNow()->addDays(wp_rand(-100, 100));
            $endDate = (clone $startDate)->addDays(wp_rand(1, 60));

            // Determine status based on end date
            $status = $endDate->isPast() ? 'expired' : 'active';

            $amount = $type === 'fixed' ? wp_rand(1, 100) : wp_rand(1, 50);
            $min_purchase_amount = $type === 'fixed' ? wp_rand($amount + 1, 150) : 0;
            $max_discount_amount = $type === 'percentage' ? wp_rand(10, 20) * 100 : 0;

            $max_uses = wp_rand(1, 100);
            $max_per_customer = wp_rand(1, $max_uses);

            $conditions = [
                'min_purchase_amount' => $min_purchase_amount,
                'max_purchase_amount' => 0,
                'apply_to_whole_cart' => 'yes',
                'max_per_customer'    => $max_per_customer,
                'max_discount_amount' => $max_discount_amount,
                'max_uses'            => $max_uses
            ];

            $coupons[] = [
                'title'      => $titles[$i],
                'code'       => $codes[$i],
                'status'     => $status,
                'type'       => $type,
                'notes'      => '',
                'priority'   => wp_rand(0, 9),
                'amount'     => $type === 'fixed' ? $amount * 100 : $amount,
                'stackable'  => wp_rand(0, 1) ? 'yes' : 'no',
                'conditions' => json_encode($conditions),
                'start_date' => $startDate,
                'end_date'   => $endDate
            ];

            if (defined('WP_CLI') && WP_CLI) {
                $progress->tick();
            }
        }

        Coupon::query()->insert($coupons);

        if (defined('WP_CLI') && WP_CLI) {
            $progress->finish();
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo \WP_CLI::colorize('%n');
        }
    }
}

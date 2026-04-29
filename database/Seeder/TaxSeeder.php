<?php

namespace FluentCart\Database\Seeder;

use FluentCart\App\Models\Order;
use FluentCart\Faker\Factory;
use FluentCart\Framework\Support\Arr;

class TaxSeeder
{
    public static function seed($count = null, $assoc_args = [])
    {
        $db = \FluentCart\App\App::getInstance('db');
        $faker = Factory::create();

        if (defined('WP_CLI') && WP_CLI) {
            $progress = \WP_CLI\Utils\make_progress_bar('%CSeeding Tax Data', 3);
        }

        // Step 1: Seed Tax Classes
        static::seedTaxClasses($db, $faker);
        if (defined('WP_CLI') && WP_CLI) {
            $progress->tick();
        }

        // Step 2: Seed Tax Rates
        $taxClassIds = static::seedTaxRates($db, $faker);
        if (defined('WP_CLI') && WP_CLI) {
            $progress->tick();
        }

        // Step 3: Seed Order Tax Rates for specific orders
        static::seedOrderTaxRates($db, $faker, $taxClassIds);
        if (defined('WP_CLI') && WP_CLI) {
            $progress->tick();
            $progress->finish();
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo \WP_CLI::colorize('%n');
        }

        if (!defined('WP_CLI') || !WP_CLI) {
            echo "Tax seeding completed successfully!<br>";
        }
    }

    private static function seedTaxClasses($db, $faker)
    {
        $taxClasses = [
            [
                'title' => 'Standard Rate',
                'slug' => 'standard',
                'description' => 'Standard tax rate for most products',
                'meta' => json_encode(['is_default' => true]),
                'created_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ],
            [
                'title' => 'Reduced Rate',
                'slug' => 'reduced',
                'description' => 'Reduced tax rate for essential goods',
                'meta' => json_encode(['is_default' => false]),
                'created_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ],
            [
                'title' => 'Zero Rate',
                'slug' => 'zero',
                'description' => 'Zero tax rate for exempt products',
                'meta' => json_encode(['is_default' => false]),
                'created_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ],
            [
                'title' => 'Digital Goods',
                'slug' => 'digital',
                'description' => 'Tax rate for digital products and services',
                'meta' => json_encode(['is_default' => false]),
                'created_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ],
        ];

        $db->table('fct_tax_classes')->insert($taxClasses);

        if (!defined('WP_CLI') || !WP_CLI) {
            echo "Inserted " . count($taxClasses) . " tax classes<br>";
        }
    }

    private static function seedTaxRates($db, $faker)
    {
        // Get inserted tax class IDs
        $taxClassIds = $db->table('fct_tax_classes')->pluck('id');

        $taxRates = [];
        $countries = ['US', 'CA', 'GB', 'DE', 'FR', 'AU', 'IN', 'JP'];
        $usTaxRates = [
            ['country' => 'US', 'state' => 'CA', 'rate' => '8.75', 'name' => 'California Sales Tax'],
            ['country' => 'US', 'state' => 'NY', 'rate' => '8.00', 'name' => 'New York Sales Tax'],
            ['country' => 'US', 'state' => 'TX', 'rate' => '6.25', 'name' => 'Texas Sales Tax'],
            ['country' => 'US', 'state' => 'FL', 'rate' => '6.00', 'name' => 'Florida Sales Tax'],
            ['country' => 'US', 'state' => 'WA', 'rate' => '6.50', 'name' => 'Washington Sales Tax'],
        ];

        // US State Tax Rates
        foreach ($usTaxRates as $usRate) {
            foreach ($taxClassIds as $classId) {
                $rate = $usRate['rate'];
                if ($classId == 2) { // Reduced rate
                    $rate = number_format(floatval($rate) * 0.5, 2);
                } elseif ($classId == 3) { // Zero rate
                    $rate = '0.00';
                } elseif ($classId == 4) { // Digital goods - slightly higher
                    $rate = number_format(floatval($rate) * 1.1, 2);
                }

                $taxRates[] = [
                    'class_id' => $classId,
                    'country' => $usRate['country'],
                    'state' => $usRate['state'],
                    'postcode' => null,
                    'city' => null,
                    'rate' => $rate,
                    'name' => $usRate['name'] . ' - Class ' . $classId,
                    'group' => 'state',
                    'priority' => 1,
                    'is_compound' => 0,
                    'for_shipping' => $faker->boolean(30) ? 1 : 0,
                    'for_order' => 1,
                    // 'tax_rate_class' => $classId,
                ];
            }
        }

        // International VAT Rates
        $vatRates = [
            ['country' => 'GB', 'rate' => '20.00', 'name' => 'UK VAT'],
            ['country' => 'DE', 'rate' => '19.00', 'name' => 'German VAT'],
            ['country' => 'FR', 'rate' => '20.00', 'name' => 'French VAT'],
            ['country' => 'CA', 'rate' => '13.00', 'name' => 'Canadian HST'],
            ['country' => 'AU', 'rate' => '10.00', 'name' => 'Australian GST'],
        ];

        foreach ($vatRates as $vatRate) {
            foreach ($taxClassIds as $classId) {
                $rate = $vatRate['rate'];
                if ($classId == 2) { // Reduced rate
                    $rate = number_format(floatval($rate) * 0.5, 2);
                } elseif ($classId == 3) { // Zero rate
                    $rate = '0.00';
                }

                $taxRates[] = [
                    'class_id' => $classId,
                    'country' => $vatRate['country'],
                    'state' => null,
                    'postcode' => null,
                    'city' => null,
                    'rate' => $rate,
                    'name' => $vatRate['name'] . ' - Class ' . $classId,
                    'group' => 'country',
                    'priority' => 1,
                    'is_compound' => 0,
                    'for_shipping' => $faker->randomElement([null, 0, 5, 10, 20, 30]),
                    'for_order' => 1,
                    // 'tax_rate_class' => $classId,
                ];
            }
        }

        // City-specific rates (examples)
        $cityRates = [
            ['country' => 'US', 'state' => 'CA', 'city' => 'Los Angeles', 'rate' => '10.25'],
            ['country' => 'US', 'state' => 'NY', 'city' => 'New York City', 'rate' => '8.875'],
            ['country' => 'US', 'state' => 'IL', 'city' => 'Chicago', 'rate' => '10.75'],
        ];

        foreach ($cityRates as $cityRate) {
            $taxRates[] = [
                'class_id' => $taxClassIds[0], // Standard rate only for cities
                'country' => $cityRate['country'],
                'state' => $cityRate['state'],
                'postcode' => null,
                'city' => $cityRate['city'],
                'rate' => $cityRate['rate'],
                'name' => $cityRate['city'] . ' Local Tax',
                'group' => 'city',
                'priority' => 2,
                'is_compound' => 1,
                'for_shipping' => null,
                'for_order' => 1,
                // 'tax_rate_class' => $taxClassIds[0],
            ];
        }

        $db->table('fct_tax_rates')->insert($taxRates);

        if (!defined('WP_CLI') || !WP_CLI) {
            echo "Inserted " . count($taxRates) . " tax rates<br>";
        }

        return $taxClassIds;
    }

    private static function seedOrderTaxRates($db, $faker, $taxClassIds)
    {
        // Get orders in the specified range
        $orderIds = range(7536149, 7536237);
        $existingOrders = $db->table('fct_orders')
            ->whereIn('id', $orderIds)
            ->pluck('id');

        if (empty($existingOrders)) {
            if (!defined('WP_CLI') || !WP_CLI) {
                echo "No orders found in the specified range (7536149-7536237)<br>";
            }
            return;
        }

        // Get available tax rates
        $taxRates = $db->table('fct_tax_rates')->get();
        $orderTaxRates = [];

        foreach ($existingOrders as $orderId) {
            // Get order details to calculate realistic tax amounts
            $order = $db->table('fct_orders')->where('id', $orderId)->first();
            if (!$order) {
                continue;
            }

            $subtotal = $order->subtotal ?? 10000; // Default to $100 in cents if null

            // Randomly assign 1-3 tax rates per order
            $numTaxRates = $faker->numberBetween(1, 3);
            $selectedTaxRates = $faker->randomElements($taxRates, $numTaxRates);

            foreach ($selectedTaxRates as $taxRate) {
                $taxRatePercentage = floatval($taxRate->rate) / 100;

                // Calculate tax amounts (in cents, following the pattern)
                $orderTax = intval($subtotal * $taxRatePercentage);
                $shippingTax = $taxRate->for_shipping ? intval($faker->numberBetween(50, 500)) : 0;
                $totalTax = $orderTax + $shippingTax;

                $orderTaxRates[] = [
                    'order_id' => $orderId,
                    'tax_rate_id' => $taxRate->id,
                    'shipping_tax' => $shippingTax,
                    'order_tax' => $orderTax,
                    'total_tax' => $totalTax,
                    'meta' => json_encode([
                        'rate_percentage' => $taxRate->rate,
                        'tax_name' => $taxRate->name,
                        'calculated_on' => gmdate('Y-m-d H:i:s'),
                    ]),
                    'filed_at' => $faker->boolean(70) ? $faker->dateTimeThisYear()->format('Y-m-d H:i:s') : null,
                    'created_at' => gmdate('Y-m-d H:i:s'),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ];
            }
        }

        if (!empty($orderTaxRates)) {
            $db->table('fct_order_tax_rate')->insert($orderTaxRates);

            // Update order tax_total field to reflect the calculated taxes
            foreach ($existingOrders as $orderId) {
                $totalOrderTax = array_sum(array_column(
                    array_filter($orderTaxRates, function ($otr) use ($orderId) {
                        return $otr['order_id'] == $orderId;
                    }),
                    'total_tax'
                ));

                if ($totalOrderTax > 0) {
                    $db->table('fct_orders')
                        ->where('id', $orderId)
                        ->update(['tax_total' => $totalOrderTax]);
                }
            }
        }

        if (!defined('WP_CLI') || !WP_CLI) {
            echo "Inserted " . count($orderTaxRates) . " order tax rate records for " . count($existingOrders) . " orders<br>";
        }
    }
}

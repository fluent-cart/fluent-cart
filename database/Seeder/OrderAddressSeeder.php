<?php

namespace FluentCart\Database\Seeder;

use FluentCart\Faker\Factory;
use FluentCart\App\Models\OrderAddress;
use FluentCart\App\Models\Order;

class OrderAddressSeeder
{
    public static function seed($count)
    {
        $faker = Factory::create();
        $addressLists = [];
        $orderIds = Order::query()->pluck('id')->toArray();

        if (empty($orderIds)) {
            echo "No orders found. Skipping order address seeding.\n";
            return;
        }

        if (defined('WP_CLI') && WP_CLI) {
            $progress = \WP_CLI\Utils\make_progress_bar('%CSeeding Order Addresses', $count);
        }

        $countryCodes = [
            'US' => ['New York', 'Los Angeles', 'Chicago', 'Houston'],
            'CA' => ['Toronto', 'Vancouver', 'Montreal', 'Calgary'],
            'GB' => ['London', 'Manchester', 'Birmingham', 'Liverpool'],
            'AU' => ['Sydney', 'Melbourne', 'Brisbane', 'Perth'],
            'DE' => ['Berlin', 'Munich', 'Hamburg', 'Frankfurt'],
            'FR' => ['Paris', 'Marseille', 'Lyon', 'Toulouse'],
            'IT' => ['Rome', 'Milan', 'Naples', 'Turin'],
            'ES' => ['Madrid', 'Barcelona', 'Seville', 'Valencia'],
            'BR' => ['São Paulo', 'Rio de Janeiro', 'Salvador', 'Brasília'],
            'JP' => ['Tokyo', 'Osaka', 'Kyoto', 'Sapporo'],
            'BD' => ['Sylhet', 'Moulvi Bazar', 'Sreemangal', 'Noakhali']
        ];

        for ($i = 0; $i < $count; $i++) {
            $country = $faker->randomElement(array_keys($countryCodes));
            $city = $faker->randomElement($countryCodes[$country]);

            $addressLists[] = [
                'order_id' => $faker->randomElement($orderIds),
                'type' => $faker->randomElement(['billing', 'shipping']),
                'name' => $faker->name(),
                'address_1' => $faker->streetAddress(),
                'address_2' => $faker->optional()->secondaryAddress(),
                'city' => $city,
                'state' => $faker->state(),
                'postcode' => $faker->postcode(),
                'country' => $country, // short code saved here
                'created_at' => $faker->dateTimeBetween('-700 days', 'now'),
                'updated_at' => new \DateTime(),
            ];

            if (defined('WP_CLI') && WP_CLI) {
                if ($i !== $count - 1) {
                    $progress->tick();
                }
            } else {
                echo wp_kses_post( sprintf(
                    /* translators: %d: order address ID */
                    __('Inserting Order Address%1$s<br>', 'fluent-cart'),
                    esc_html($i + 1)
                ) );
            }
        }

        OrderAddress::query()->insert($addressLists);

        if (defined('WP_CLI') && WP_CLI) {
            $progress->tick();
            $progress->finish();
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo \WP_CLI::colorize('%n');
        }
    }
}

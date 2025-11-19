<?php

namespace FluentCart\Database\Seeder;

use FluentCart\Faker\Factory;
use FluentCart\App\Models\Customer;

class CustomerSeeder
{
    public static function seed($count, $assoc_args = [])
    {
        $faker = Factory::create();
        $customerLists = [];

        if (defined('WP_CLI') && WP_CLI) {
            $progress = \WP_CLI\Utils\make_progress_bar('%CSeeding Customer', $count);
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
            $gender = $faker->randomElement(['male', 'female']);
            $country = $faker->randomElement(array_keys($countryCodes));
            $city = $faker->randomElement($countryCodes[$country]);

            $customerLists[] = [
                'email' => $faker->email(),
                'first_name' => $faker->firstName($gender),
                'last_name' => $faker->lastName($gender),
                'notes' => $faker->text(30),
                'country' => $country,
                'city' => $city,
                'state' => $faker->state(),
                'postcode' => $faker->postcode(),
                'created_at' => $faker->dateTimeBetween('-700 days', 'now')
            ];

            if (defined('WP_CLI') && WP_CLI) {
                if ($i !== $count - 1) {
                    $progress->tick();
                }
            } else {
                /* translators: 1: Customer number */
                echo wp_kses_post( sprintf(
                    /* translators: %d: customer ID */
                    __('Inserting Customer %1$s<br>', 'fluent-cart'),
                    esc_html($i + 1)
                ) );
            }
        }

        Customer::query()->insert($customerLists);

        if (defined('WP_CLI') && WP_CLI) {
            $progress->tick();
            $progress->finish();
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo \WP_CLI::colorize('%n');
        }
    }
}

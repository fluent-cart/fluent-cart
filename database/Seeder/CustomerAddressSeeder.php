<?php

namespace FluentCart\Database\Seeder;

use FluentCart\Faker\Factory;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\CustomerAddresses;

class CustomerAddressSeeder
{
    public static function seed($count, $assoc_args = [])
    {
        $faker = Factory::create();
        $allCustomers = Customer::query()->pluck('id')->toArray();

        if (empty($allCustomers)) {
            echo "No customers found to seed addresses.\n";
            return;
        }

        $addressList = [];

        if (defined('WP_CLI') && WP_CLI) {
            $progress = \WP_CLI\Utils\make_progress_bar('%CSeeding Customer Addresses', count($allCustomers) * 2);
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

        foreach ($allCustomers as $customerId) {
            foreach (['shipping', 'billing'] as $type) {
                $gender = $faker->randomElement(['male', 'female']);
                $country = $faker->randomElement(array_keys($countryCodes));
                $city = $faker->randomElement($countryCodes[$country]);

                $addressList[] = [
                    'customer_id' => $customerId,
                    'is_primary' => ($type === 'shipping') ? 1 : 0,
                    'type' => $type,
                    'status' => $faker->randomElement(['active', 'inactive']),
                    'label' => $faker->word(),
                    'name' => $faker->name($gender),
                    'address_1' => $faker->streetAddress(),
                    'address_2' => $faker->secondaryAddress(),
                    'city' => $city,
                    'state' => $faker->state(),
                    'postcode' => $faker->postcode(),
                    'phone' => $faker->phoneNumber(),
                    'email' => $faker->email(),
                    'country' => $country,
                    'created_at' => $faker->dateTimeBetween('-700 days', 'now')->format('Y-m-d H:i:s'),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ];

                if (defined('WP_CLI') && WP_CLI) {
                    $progress->tick();
                } else {
                        echo wp_kses_post( sprintf(
                            /* translators: 1: Address type, 2: Customer ID */
                            __('Inserting %1$s address for customer %2$s<br>', 'fluent-cart'),
                            esc_html($type),
                            esc_html($customerId)
                        ) );

                }

                if (isset($count) && count($addressList) >= $count) {
                    break 2;
                }
            }
        }

        CustomerAddresses::query()->insert($addressList);

        if (defined('WP_CLI') && WP_CLI) {
            $progress->finish();
            //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo \WP_CLI::colorize('%n');
        }
    }
}

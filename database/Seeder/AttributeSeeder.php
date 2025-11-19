<?php

namespace FluentCart\Database\Seeder;

use FluentCart\App\Models\AttributeGroup;
use FluentCart\App\Models\AttributeTerm;

class AttributeSeeder
{
    public static function seed()
    {
        if (AttributeGroup::count() > 2) {
            return;
        }

        $groups = [
            [
                'title' => 'Digital Product Licensing',
                'slug' => 'plugin-license',
                'description' => 'Digital product license types.',
            ],
            [
                'title' => 'License Period',
                'slug' => 'plugin-license-period',
                'description' => 'Plugin license period i.e. - annual, lifetime.',
            ],
            [
                'title' => 'Cloth sizes',
                'slug' => 'cloth-size',
                'description' => 'Clothes sizes',
            ],
            [
                'title' => 'Cloth color',
                'slug' => 'cloth-color',
                'description' => 'Clothes color',
            ],
            [
                'title' => 'Cloth attributes',
                'slug' => 'cloth-attributes',
                'description' => 'Clothes logo, collar, long sleeve',
            ],
        ];

        $attr = [
            'plugin-license' => [
                [
                    'serial' => 1,
                    'group_id' => 1,
                    'title' => 'Single License',
                    'slug' => 'single-license',
                ],
                [
                    'serial' => 2,
                    'group_id' => 1,
                    'title' => '1 Site License',
                    'slug' => '1-site-license',
                ],
                [
                    'serial' => 3,
                    'group_id' => 1,
                    'title' => '5 Sites License',
                    'slug' => '5-site-license',
                ],
                [
                    'serial' => 4,
                    'group_id' => 1,
                    'title' => '50 Sites License',
                    'slug' => '50-site-license',
                ],
                [
                    'serial' => 5,
                    'group_id' => 1,
                    'title' => 'Agency License',
                    'slug' => 'agency-license',
                ],
                [
                    'serial' => 6,
                    'group_id' => 1,
                    'title' => 'Unlimited License',
                    'slug' => 'unlimited-license',
                ],
            ],
            'plugin-license-period' => [
                [
                    'serial' => 1,
                    'group_id' => 1,
                    'title' => 'Monthly',
                    'slug' => 'monthly',
                ],
                [
                    'serial' => 3,
                    'group_id' => 1,
                    'title' => 'Annual',
                    'slug' => 'annual',
                ],
                [
                    'serial' => 4,
                    'group_id' => 1,
                    'title' => 'Lifetime',
                    'slug' => 'lifetime',
                ],
            ],
            'cloth-size' => [
                [
                    'serial' => 1,
                    'group_id' => 1,
                    'title' => 'XS',
                    'slug' => 'extra-small',
                ],
                [
                    'serial' => 2,
                    'group_id' => 1,
                    'title' => 'S',
                    'slug' => 'small',
                ],
                [
                    'serial' => 3,
                    'group_id' => 1,
                    'title' => 'M',
                    'slug' => 'medium',
                ],
                [
                    'serial' => 4,
                    'group_id' => 1,
                    'title' => 'L',
                    'slug' => 'large',
                ],
                [
                    'serial' => 5,
                    'group_id' => 1,
                    'title' => 'XL',
                    'slug' => 'extra-large',
                ],
                [
                    'serial' => 6,
                    'group_id' => 1,
                    'title' => 'XXL',
                    'slug' => 'extra-extra-large',
                ],
                [
                    'serial' => 7,
                    'group_id' => 1,
                    'title' => '3XL',
                    'slug' => 'extra-3-large',
                ],
            ],
            'cloth-color' => [
                [
                    'serial' => 1,
                    'group_id' => 1,
                    'title' => 'Red',
                    'slug' => 'red',
                ],
                [
                    'serial' => 2,
                    'group_id' => 1,
                    'title' => 'Green',
                    'slug' => 'green',
                ],
                [
                    'serial' => 3,
                    'group_id' => 1,
                    'title' => 'Yellow',
                    'slug' => 'yellow',
                ],
                [
                    'serial' => 4,
                    'group_id' => 1,
                    'title' => 'Grey',
                    'slug' => 'grey',
                ],
                [
                    'serial' => 5,
                    'group_id' => 1,
                    'title' => 'Black',
                    'slug' => 'black',
                ],
                [
                    'serial' => 6,
                    'group_id' => 1,
                    'title' => 'White',
                    'slug' => 'white',
                ],
                [
                    'serial' => 7,
                    'group_id' => 1,
                    'title' => 'Gold',
                    'slug' => 'gold',
                ],
            ],
            'cloth-attributes' => [
                [
                    'serial' => 1,
                    'group_id' => 1,
                    'title' => 'Logo',
                    'slug' => 'with-logo',
                ],
                [
                    'serial' => 2,
                    'group_id' => 1,
                    'title' => 'Without Logo',
                    'slug' => 'without-logo',
                ],
                [
                    'serial' => 3,
                    'group_id' => 1,
                    'title' => 'Collar',
                    'slug' => 'with-collar',
                ],
                [
                    'serial' => 4,
                    'group_id' => 1,
                    'title' => 'Long sleeve',
                    'slug' => 'long-sleeve',
                ],
                [
                    'serial' => 5,
                    'group_id' => 1,
                    'title' => 'Sleeveless',
                    'slug' => 'sleeveless',
                ],
                [
                    'serial' => 6,
                    'group_id' => 1,
                    'title' => 'Short sleeve',
                    'slug' => 'short-sleeve',
                ],
                [
                    'serial' => 7,
                    'group_id' => 1,
                    'title' => 'High collar',
                    'slug' => 'high-collar',
                ],
            ],
        ];


        AttributeGroup::insert($groups);

        $grs = AttributeGroup::get();

        if (defined('WP_CLI') && WP_CLI) {
            $progress = \WP_CLI\Utils\make_progress_bar('%CSeeding Attributed', count($grs));
        }

        foreach ($grs as $gr) {
            if (isset($attr[$gr->slug])) {

                $tmp = [];

                foreach ($attr[$gr->slug] as $item) {

                    $item['group_id'] = $gr->id;
                    $tmp[] = $item;
                }

                AttributeTerm::insert($tmp);
                if (defined('WP_CLI') && WP_CLI) {
                    $progress->tick();
                }
            }
        }
        if (defined('WP_CLI') && WP_CLI) {
            $progress->tick();
            $progress->finish();
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo \WP_CLI::colorize('%n');
        }
    }
}

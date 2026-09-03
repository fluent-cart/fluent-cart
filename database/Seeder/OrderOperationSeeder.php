<?php

namespace FluentCart\Database\Seeder;

use FluentCart\Faker\Factory;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderOperation;

class OrderOperationSeeder
{
    public static $socialMedias = [
        'facebook',
        'instagram',
        'youtube',
        'linkedin',
        'twitter',
        'pinterest',
        'reddit'
    ];

    /**
     * Attribution presets the Order Sources report is meant to be read through.
     *
     * The report groups by utm_campaign + utm_source + utm_medium, so every entry
     * below becomes one row. They are deliberately a small, plausible pool rather
     * than random strings: a human reading the report should recognise
     * "google / cpc / summer_sale" as a channel, and popular sources repeat across
     * entries so the spread looks like a real store's traffic mix.
     */
    public static $utmParameters = [
        [
            'source'   => 'google',
            'medium'   => 'cpc',
            'campaign' => 'summer_sale',
            'term'     => 'buy_online',
            'content'  => 'search_ad_a'
        ],
        [
            'source'   => 'google',
            'medium'   => 'cpc',
            'campaign' => 'brand_defense',
            'term'     => 'brand_name',
            'content'  => 'search_ad_b'
        ],
        [
            'source'   => 'google',
            'medium'   => 'organic',
            'campaign' => 'evergreen_seo',
            'term'     => 'best_price',
            'content'  => 'landing_page'
        ],
        [
            'source'   => 'google',
            'medium'   => 'display',
            'campaign' => 'retargeting',
            'term'     => 'cart_abandoners',
            'content'  => 'display_banner'
        ],
        [
            'source'   => 'facebook',
            'medium'   => 'cpc',
            'campaign' => 'black_friday',
            'term'     => 'doorbuster',
            'content'  => 'carousel_ad'
        ],
        [
            'source'   => 'facebook',
            'medium'   => 'social',
            'campaign' => 'product_launch',
            'term'     => 'new_release',
            'content'  => 'page_post'
        ],
        [
            'source'   => 'instagram',
            'medium'   => 'social',
            'campaign' => 'creator_collab',
            'term'     => 'influencer',
            'content'  => 'story_ad'
        ],
        [
            'source'   => 'instagram',
            'medium'   => 'cpc',
            'campaign' => 'flash_sale',
            'term'     => '24hr_special',
            'content'  => 'reel_ad'
        ],
        [
            'source'   => 'newsletter',
            'medium'   => 'email',
            'campaign' => 'weekly_digest',
            'term'     => 'latest_news',
            'content'  => 'header_link'
        ],
        [
            'source'   => 'newsletter',
            'medium'   => 'email',
            'campaign' => 'abandoned_cart',
            'term'     => 'come_back',
            'content'  => 'recovery_link'
        ],
        [
            'source'   => 'twitter',
            'medium'   => 'social',
            'campaign' => 'brand_awareness',
            'term'     => 'spread_the_word',
            'content'  => 'tweet_link'
        ],
        [
            'source'   => 'youtube',
            'medium'   => 'video',
            'campaign' => 'tutorial_series',
            'term'     => 'how_to',
            'content'  => 'description_link'
        ],
        [
            'source'   => 'bing',
            'medium'   => 'cpc',
            'campaign' => 'summer_sale',
            'term'     => 'buy_online',
            'content'  => 'search_ad_a'
        ],
        [
            'source'   => 'affiliate',
            'medium'   => 'referral',
            'campaign' => 'partner_program',
            'term'     => 'review_post',
            'content'  => 'inline_link'
        ],
        [
            'source'   => 'linkedin',
            'medium'   => 'social',
            'campaign' => 'b2b_outreach',
            'term'     => 'team_plan',
            'content'  => 'infeed_ad'
        ],
        [
            'source'   => 'reddit',
            'medium'   => 'social',
            'campaign' => 'community_awareness',
            'term'     => 'ama_thread',
            'content'  => 'comment_link'
        ],
        [
            'source'   => 'direct',
            'medium'   => 'none',
            'campaign' => 'direct_visit',
            'term'     => '',
            'content'  => ''
        ]
    ];

    /**
     * Share of seeded orders that carry no attribution at all.
     *
     * Real stores always have unattributed orders, and the Sources report guards
     * against them with whereNotNull('oo.utm_source'), so the seed data has to
     * contain some or that guard is never exercised.
     */
    const NO_UTM_RATIO = 20;

    public static function seed($count, $assoc_args = [])
    {
        // Newest first: DBSeeder runs this straight after OrderSeeder, so the
        // orders that still need an operation row are the ones just inserted.
        // (The old query took the OLDEST $count orders, which duplicated rows on
        // any store that had been seeded before and left new orders with none.)
        $orders = Order::query()
            ->select(['id', 'created_at'])
            ->orderBy('id', 'desc')
            ->limit($count)
            ->get();

        if ($orders->isEmpty()) {
            return;
        }

        $orderIds = $orders->pluck('id')->toArray();

        // Bounded by $count on both sides — never insert a second operation row
        // for an order that already has one.
        $alreadySeeded = OrderOperation::query()
            ->whereIn('order_id', $orderIds)
            ->pluck('order_id')
            ->toArray();

        $alreadySeeded = array_flip($alreadySeeded);

        $operationData = [];
        $faker = Factory::create();

        if (defined('WP_CLI') && WP_CLI) {
            $progress = \WP_CLI\Utils\make_progress_bar('%CSeeding Order Operations', count($orders));
        }

        foreach ($orders as $order) {
            if (isset($alreadySeeded[$order->id])) {
                if (defined('WP_CLI') && WP_CLI) {
                    $progress->tick();
                }
                continue;
            }

            $hasUtm = wp_rand(1, 100) > self::NO_UTM_RATIO;

            $utmParameter = $hasUtm
                ? $faker->randomElement(self::$utmParameters)
                : ['source' => '', 'medium' => '', 'campaign' => '', 'term' => '', 'content' => ''];

            // The report filters orders by o.created_at, so the operation row is
            // dated with its own order rather than a random date since 1970.
            $createdAt = (string) $order->created_at;

            // has_tax / has_discount / coupons_counted are still listed in the
            // OrderOperation model's $fillable, but OrderOperationsMigrator never
            // creates those columns — writing them killed every seed run with
            // "Unknown column 'coupons_counted' in 'field list'".
            $operationData[] = [
                'order_id'        => $order->id,
                'created_via'     => 'web',
                'emails_sent'     => wp_rand(0, 1),
                'sales_recorded'  => wp_rand(0, 1),
                'utm_campaign'    => $utmParameter['campaign'],
                'utm_term'        => $utmParameter['term'],
                'utm_source'      => $utmParameter['source'],
                'utm_content'     => $utmParameter['content'],
                'utm_medium'      => $utmParameter['medium'],
                'utm_id'          => $hasUtm ? (string) wp_rand(1, 7) : '',
                'cart_hash'       => wp_rand(100, 2345),
                'refer_url'       => in_array($utmParameter['source'], self::$socialMedias, true)
                    ? 'https://www.' . $utmParameter['source'] . '.com'
                    : '',
                'created_at'      => $createdAt,
                'updated_at'      => $createdAt,
            ];

            if (defined('WP_CLI') && WP_CLI) {
                $progress->tick();
            }
        }

        if ($operationData) {
            OrderOperation::query()->insert($operationData);
        }

        if (defined('WP_CLI') && WP_CLI) {
            $progress->finish();
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo \WP_CLI::colorize('%n');
        }
    }
}

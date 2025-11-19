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
        'google',
        'youtube',
        'linkedin',
        'twitter'
    ];

    public static $utmParameters = [
        [
            'source'   => 'social_media',
            'medium'   => 'cpc',
            'campaign' => 'launch2024',
            'term'     => 'discount_code',
            'content'  => 'banner1'
        ],
        [
            'source'   => 'newsletter',
            'medium'   => 'email',
            'campaign' => 'spring_sale',
            'term'     => 'special_offer',
            'content'  => 'top_banner'],
        [
            'source'   => 'affiliate',
            'medium'   => 'referral',
            'campaign' => 'affiliate_promo',
            'term'     => 'product_launch',
            'content'  => 'sidebar_ad'
        ],
        ['source'   => 'google',
         'medium'   => 'organic',
         'campaign' => 'summer_discounts',
         'term'     => 'new_product',
         'content'  => 'footer_link'],
        [
            'source'   => 'facebook',
            'medium'   => 'social',
            'campaign' => 'holiday_specials',
            'term'     => 'limited_time_offer',
            'content'  => 'video_ad'
        ],
        [
            'source'   => 'youtube',
            'medium'   => 'video',
            'campaign' => '2024_trends',
            'term'     => 'feature_highlight',
            'content'  => 'watch_demo'
        ],
        [
            'source'   => 'instagram',
            'medium'   => 'paid',
            'campaign' => 'exclusive_deal',
            'term'     => 'top_sellers',
            'content'  => 'image_post'
        ],
        [
            'source'   => 'linkedin',
            'medium'   => 'display',
            'campaign' => 'professional_gear',
            'term'     => 'upgrade',
            'content'  => 'infeed_ad'
        ],
        [
            'source'   => 'twitter',
            'medium'   => 'social',
            'campaign' => 'winter_sale',
            'term'     => 'must_have',
            'content'  => 'tweet_link'
        ],
        [
            'source'   => 'blog',
            'medium'   => 'banner',
            'campaign' => 'new_arrivals',
            'term'     => 'just_arrived',
            'content'  => 'read_more_button'
        ],
        [
            'source'   => 'email_campaign',
            'medium'   => 'newsletter',
            'campaign' => 'mid_year_sale',
            'term'     => 'promo_july',
            'content'  => 'email_link1',
        ],
        [
            'source'   => 'search_engine',
            'medium'   => 'seo',
            'campaign' => 'organic_growth',
            'term'     => 'keyword_optimization',
            'content'  => 'organic_link',
        ],
        [
            'source'   => 'partner_site',
            'medium'   => 'affiliate',
            'campaign' => 'partner_discount',
            'term'     => 'affiliate2024',
            'content'  => 'partner_banner',
        ],
        [
            'source'   => 'linkedin',
            'medium'   => 'ad',
            'campaign' => 'b2b_leads',
            'term'     => 'lead_gen',
            'content'  => 'profile_ad',
        ],
        [
            'source'   => 'twitter',
            'medium'   => 'tweet',
            'campaign' => 'brand_awareness',
            'term'     => 'spread_the_word',
            'content'  => 'tweet1',
        ],
        [
            'source'   => 'facebook',
            'medium'   => 'post',
            'campaign' => 'product_launch',
            'term'     => 'new_release',
            'content'  => 'post_link',
        ],
        [
            'source'   => 'google_ads',
            'medium'   => 'ppc',
            'campaign' => 'adwords_boost',
            'term'     => 'click_through',
            'content'  => 'ad_variation1',
        ],
        [
            'source'   => 'youtube',
            'medium'   => 'channel',
            'campaign' => 'video_campaign',
            'term'     => 'tutorial_series',
            'content'  => 'video1',
        ],
        [
            'source'   => 'instagram',
            'medium'   => 'story',
            'campaign' => 'flash_sale',
            'term'     => '24hr_special',
            'content'  => 'story_ad',
        ],
        [
            'source'   => 'pinterest',
            'medium'   => 'pin',
            'campaign' => 'home_decor',
            'term'     => 'interior_tips',
            'content'  => 'pin_post',
        ],
        [
            'source'   => 'email_blast',
            'medium'   => 'direct',
            'campaign' => 'exclusive_offer',
            'term'     => 'vip_list',
            'content'  => 'email1',
        ],
        [
            'source'   => 'webinar',
            'medium'   => 'live_event',
            'campaign' => 'educational_series',
            'term'     => 'session1',
            'content'  => 'signup_page',
        ],
        [
            'source'   => 'google',
            'medium'   => 'display',
            'campaign' => 'retargeting',
            'term'     => 'visitor_retarget',
            'content'  => 'display_ad1',
        ],
        [
            'source'   => 'twitter',
            'medium'   => 'promotion',
            'campaign' => 'tweet_special',
            'term'     => 'limited_offer',
            'content'  => 'promo_tweet',
        ],
        [
            'source'   => 'facebook',
            'medium'   => 'video_ad',
            'campaign' => 'video_promotion',
            'term'     => 'watch_now',
            'content'  => 'video_banner',
        ],
        [
            'source'   => 'instagram',
            'medium'   => 'photo_ad',
            'campaign' => 'image_campaign',
            'term'     => 'product_photo',
            'content'  => 'product_image',
        ],
        [
            'source'   => 'pinterest',
            'medium'   => 'image',
            'campaign' => 'diy_projects',
            'term'     => 'do_it_yourself',
            'content'  => 'diy_pin',
        ],
        [
            'source'   => 'newsletter',
            'medium'   => 'email',
            'campaign' => 'weekly_update',
            'term'     => 'latest_news',
            'content'  => 'newsletter_link',
        ],
        [
            'source'   => 'google',
            'medium'   => 'organic',
            'campaign' => 'seo_boost',
            'term'     => 'search_term',
            'content'  => 'landing_page',
        ],
        [
            'source'   => 'email',
            'medium'   => 'retargeting_mail',
            'campaign' => 'customer_followup',
            'term'     => 'follow_up',
            'content'  => 'second_email',
        ]

    ];


    public static function seed($count, $assoc_args = [])
    {
        $orders = Order::query()->limit($count)->get();
        $operationData = [];
        $faker = Factory::create();

        if (defined('WP_CLI') && WP_CLI) {
            $progress = \WP_CLI\Utils\make_progress_bar('%CSeeding Order Operations', $count);
        }

        foreach ($orders as $order) {
            $utmParameter = $faker->randomElement(self::$utmParameters);
            $operationData[] = [
                'order_id'        => $order->id,
                'created_via'     => 'web',
                'has_tax'         => wp_rand(0, 1),
                'has_discount'    => wp_rand(0, 1),
                'coupons_counted' => wp_rand(1, 3),
                'emails_sent'     => wp_rand(0, 1),
                'sales_recorded'  => wp_rand(0, 1),
                'utm_campaign'    => $utmParameter['campaign'],
                'utm_term'        => $utmParameter['term'],
                'utm_source'      => $utmParameter['source'],
                'utm_content'     => $utmParameter['content'],
                'utm_medium'      => $utmParameter['medium'],
                'utm_id'          => wp_rand(1, 7),
                'cart_hash'       => wp_rand(100, 2345),
                'refer_url'       => in_array($utmParameter['source'], self::$socialMedias, '') ?
                    'https://www.' . $utmParameter['source'] . '.com' : '',
                'created_at'      => $faker->date('Y-m-d H:i:s'),
                'updated_at'      => $faker->date('Y-m-d H:i:s'),
            ];

            if (defined('WP_CLI') && WP_CLI) {
                $progress->tick();
            }
        }


        OrderOperation::query()->insert($operationData);

        if (defined('WP_CLI') && WP_CLI) {
            $progress->tick();
            $progress->finish();
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo \WP_CLI::colorize('%n');
        }
    }
}

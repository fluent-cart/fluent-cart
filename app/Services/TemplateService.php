<?php

namespace FluentCart\App\Services;

use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class TemplateService
{
    public static function getTemplateByPathName($name, $viewData)
    {
        ob_start();
        $defaultBannerImage = Vite::getAssetUrl('images/email-template/email-banner.png');
        $viewData = Arr::wrap($viewData);
        $viewData = array_merge($viewData, [
            'default_banner_image' => $defaultBannerImage,
        ]);
        App::make('view')->render('emails.' . $name, $viewData);
        return ob_get_clean();
    }

    public static function getInvoicePackingTemplateByPathName($name)
    {
        ob_start();
        App::make('view')->render('invoice.' . $name);
        return ob_get_clean();
    }

    public static function getCurrentFcPageType()
    {
        $pageId = null;
        if (is_page() && is_main_query()) {
            $pageId = get_queried_object_id();
        }

        static $pageType = null;

        if ($pageType !== null) {
            return $pageType; // Return cached page type
        }

        if (!$pageId) {
            if (is_singular('fluent-products')) {
                $pageType = 'single_product';
                return $pageType;
            }

            if (is_tax(get_object_taxonomies('fluent-products'))) {
                $pageType = 'product_taxonomy';
                return $pageType;
            }

        }

        $pagesConfig = (new StoreSettings())->getPagesSettings();

        if (!in_array($pageId, $pagesConfig)) {
            $pageType = '';
            return $pageType;
        }

        // find the key of the current page ID in the pagesConfig array
        $pageTypeKey = array_search($pageId, $pagesConfig);
        if ($pageTypeKey === false) {
            $pageType = '';
            return $pageType;
        }

        $pageTypeMaps = [
            'checkout_page_id'         => 'checkout',
            'registration_page_id'     => 'registration',
            'login_page_id'            => 'login',
            'cart_page_id'             => 'cart',
            'receipt_page_id'          => 'receipt',
            'shop_page_id'             => 'shop',
            'customer_profile_page_id' => 'customer_dashboard',
        ];

        // Return the corresponding page type
        $pageType = $pageTypeMaps[$pageTypeKey] ?? null;

        return $pageType;
    }

    public static function isFcPageType($type)
    {
        $currentPageType = self::getCurrentFcPageType();
        return $currentPageType === $type;
    }

    public static function getCustomerProfileUrl($extension = '')
    {
        $url = (new StoreSettings())->getCustomerProfilePage();
        $url = rtrim($url, '/');

        if ($extension) {
            // add the extension with a leading slash
            $url .= '/' . rtrim($extension, '/');
        }

        return $url;
    }

    public static function getAdminUrl($extension = '')
    {
        return URL::getDashboardUrl($extension);
    }


    public static function getCelebration($type = 'order')
    {
        if (apply_filters('fluent_cart/disable_email_celebration_messages', false, ['type' => $type])) {
            return '';
        }

        $orderCelebrations = [
            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Woo-Hoo! Another Sale to Celebrate! %1$s', 'Email Celebration', 'fluent-cart'), '🎉✨'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Cha-Ching! Your Shop\'s Raking It In! %1$s', 'Email Celebration', 'fluent-cart'), '💰🚀'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Yasss! New Order, Let\'s Party! %1$s', 'Email Celebration', 'fluent-cart'), '🥳🎈'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('High-Five! You Nailed Another Sale! %1$s', 'Email Celebration', 'fluent-cart'), '🙌💥'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Boom! Your Store\'s Popping Off! %1$s', 'Email Celebration', 'fluent-cart'), '🌟🎊'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Heck Yeah! Another Sale in the Bag! %1$s', 'Email Celebration', 'fluent-cart'), '🛍️🔥'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Dance Party! New Order Just Dropped! %1$s', 'Email Celebration', 'fluent-cart'), '💃🕺'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Cheers! Your Shop\'s Got Another Win! %1$s', 'Email Celebration', 'fluent-cart'), '🥂🎉'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Jackpot! Another Sale to Celebrate! %1$s', 'Email Celebration', 'fluent-cart'), '🎰✨'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Epic Win! Your Store\'s Crushing It! %1$s', 'Email Celebration', 'fluent-cart'), '🏆🚀'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Sweet Victory! Another Sale to Celebrate! %1$s', 'Email Celebration', 'fluent-cart'), '🏆🎉'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Bling Bling! Your Shop\'s Cashin\' In! %1$s', 'Email Celebration', 'fluent-cart'), '💰✨'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Huzzah! New Order, Time to Party! %1$s', 'Email Celebration', 'fluent-cart'), '🎈🥳'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Fist Bump! You Scored Another Sale! %1$s', 'Email Celebration', 'fluent-cart'), '👊🔥'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Wowza! Your Store\'s on a Roll! %1$s', 'Email Celebration', 'fluent-cart'), '🚀🌟'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Yippee! Another Sale in the Books! %1$s', 'Email Celebration', 'fluent-cart'), '🛍️🎊'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Let\'s Groove! New Order Just Landed! %1$s', 'Email Celebration', 'fluent-cart'), '🕺💃'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Pop the Confetti! Another Sale Win! %1$s', 'Email Celebration', 'fluent-cart'), '🎉🚨'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Money Moves! Your Shop\'s Killing It! %1$s', 'Email Celebration', 'fluent-cart'), '💸🏅'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Epic Haul! Another Sale to Cheer! %1$s', 'Email Celebration', 'fluent-cart'), '🥂⚡️'),
        ];


        $renewalCelebrations = [
            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Your Subscription\'s Renewed! Keep Rockin\' It! %1$s', 'Email Celebration', 'fluent-cart'), '🎉🚀'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Big News! Another Subscription Locked In! %1$s', 'Email Celebration', 'fluent-cart'), '💰✨'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Woohoo! A Customer Renewed Their Sub! %1$s', 'Email Celebration', 'fluent-cart'), '🥳🎈'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('High-Five! Your Shop\'s Got a Renewal! %1$s', 'Email Celebration', 'fluent-cart'), '🙌🔥'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Cha-Ching! Subscription Renewed, Boss! %1$s', 'Email Celebration', 'fluent-cart'), '💸🌟'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Yasss! Another Loyal Sub Stays On! %1$s', 'Email Celebration', 'fluent-cart'), '🏆🎊'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Boom! Your Store\'s Sub Count Grows! %1$s', 'Email Celebration', 'fluent-cart'), '💥🛍️'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Hooray! A Renewal Just Hit Your Shop! %1$s', 'Email Celebration', 'fluent-cart'), '🥂⚡️'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Confetti Time! Subscription Renewed! %1$s', 'Email Celebration', 'fluent-cart'), '🎉🚨'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Keep Shining! Another Sub Stays With You! %1$s', 'Email Celebration', 'fluent-cart'), '🌟💪'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Party On! Your Shop\'s Got a Renewal! %1$s', 'Email Celebration', 'fluent-cart'), '🎈🕺'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Score! A Customer Renewed Their Plan! %1$s', 'Email Celebration', 'fluent-cart'), '🏅✨'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Woot Woot! Subscription Renewal Alert! %1$s', 'Email Celebration', 'fluent-cart'), '🛵🎉'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Money Moves! Another Sub Renewed! %1$s', 'Email Celebration', 'fluent-cart'), '💰🔥'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Epic Win! Your Shop\'s Sub is Back! %1$s', 'Email Celebration', 'fluent-cart'), '🎰⚡️'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Cheers! A Loyal Customer Renewed! %1$s', 'Email Celebration', 'fluent-cart'), '🥳🥂'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Fist Bump! Subscription Renewed, Champ! %1$s', 'Email Celebration', 'fluent-cart'), '👊🌟'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Let\'s Dance! Another Sub Stays On Board! %1$s', 'Email Celebration', 'fluent-cart'), '💃🎊'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('Jackpot! Your Shop\'s Got a Renewal! %1$s', 'Email Celebration', 'fluent-cart'), '🎉🏆'),

            // translators: %1$s is celebration emoji(s)
            wp_sprintf(_x('You\'re Killing It! Another Sub Renewed! %1$s', 'Email Celebration', 'fluent-cart'), '🚀✨'),
        ];


        if ($type === 'renewal') {
            $celebrations = $renewalCelebrations;
        } else {
            $celebrations = $orderCelebrations;
        }

        // send a random celebration message
        return Arr::random($celebrations);
    }

}

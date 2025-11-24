<?php

namespace FluentCartPro\App\Services\Translations;

class Translations
{
    public static function getTranslations()
    {
        $translations = [
            'You can replace this module from %s/resources/admin/Components/Dashboard.vue%s' => __('You can replace this module from %s/resources/admin/Components/Dashboard.vue%s', 'fluent-cart-pro'),
            'A New Era of Commerce With WordPress' => __('A New Era of Commerce With WordPress', 'fluent-cart-pro'),
            'FluentCart requires the Core Plugin to be installed first. Let\'s get you set up in just one click.' => __('FluentCart requires the Core Plugin to be installed first. Let\'s get you set up in just one click.', 'fluent-cart-pro'),
            'Quick & Easy Setup' => __('Quick & Easy Setup', 'fluent-cart-pro'),
            'Go from Setup to first sale in minutes. Configuration is straightforward with the Onboarding Wizard.' => __('Go from Setup to first sale in minutes. Configuration is straightforward with the Onboarding Wizard.', 'fluent-cart-pro'),
            'Clean, Modern Interface' => __('Clean, Modern Interface', 'fluent-cart-pro'),
            'Manage your store with a clean dashboard that gives you everything you need in an intuitive workflow.' => __('Manage your store with a clean dashboard that gives you everything you need in an intuitive workflow.', 'fluent-cart-pro'),
            'Robust & Reliable Performance' => __('Robust & Reliable Performance', 'fluent-cart-pro'),
            'Built for lightning-fast processing, your store will perform under any stress without breaking your server' => __('Built for lightning-fast processing, your store will perform under any stress without breaking your server', 'fluent-cart-pro'),
            'cancel' => __('cancel', 'fluent-cart-pro'),
            'confirm' => __('confirm', 'fluent-cart-pro'),
            'Are you sure to delete this?' => __('Are you sure to delete this?', 'fluent-cart-pro'),
        ];

        return apply_filters("fluent_cart_pro/admin_translations", $translations);
    }
}
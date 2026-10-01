<?php

namespace FluentCart\App\Modules\Turnstile;

use FluentCart\Api\ModuleSettings;
use FluentCart\Framework\Support\Arr;

class TurnstileInit
{
    public function register($app)
    {
        // Register module settings fields
        add_filter('fluent_cart/module_setting/fields', function ($fields, $args) {
            $fields['turnstile'] = [
                'title'       => __('Cloudflare Turnstile', 'fluent-cart'),
                'description' => __('Protect your checkout page from spam and bots using Cloudflare Turnstile invisible reCAPTCHA.', 'fluent-cart'),
                'type'        => 'component',
                'component'   => 'TurnstileSettings',
            ];
            return $fields;
        }, 10, 2);

        // Register default values
        add_filter('fluent_cart/module_setting/default_values', function ($values, $args) {
            if (empty($values['turnstile']['active'])) {
                $values['turnstile']['active'] = 'no';
            }
            if (empty($values['turnstile']['site_key'])) {
                $values['turnstile']['site_key'] = '';
            }
            if (empty($values['turnstile']['secret_key'])) {
                $values['turnstile']['secret_key'] = '';
            }

            return $values;
        }, 10, 2);

        // Boot the module if active.
        // Deferred to `init` on purpose: ModuleSettings::getAllSettings() memoizes on its
        // first call, and that is where `module_setting/default_values` is applied. Reading
        // settings here would freeze the cache before modules registered later in
        // Hooks/actions.php add their own defaults, leaving those modules permanently
        // inactive. Every module must read settings after registration has finished.
        add_action('init', function () {
            if (ModuleSettings::isActive('turnstile')) {
                (new TurnstileBoot())->register();
            }
        }, 1);
    }
}


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

        // Boot the module if active
        $isActive = ModuleSettings::isActive('turnstile');
        
        if ($isActive) {
            (new TurnstileBoot())->register();
        }
    }
}


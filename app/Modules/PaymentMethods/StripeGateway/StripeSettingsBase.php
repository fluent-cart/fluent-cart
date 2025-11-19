<?php

namespace FluentCart\App\Modules\PaymentMethods\StripeGateway;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;

class StripeSettingsBase extends BaseGatewaySettings
{

    public $settings;

    public $methodHandler = 'fluent_cart_payment_settings_stripe';

    public function __construct()
    {
        parent::__construct();
        $settings = $this->getCachedSettings();
        $defaults = static::getDefaults();

        if (!$settings || !is_array($settings) || empty($settings)) {
            $settings = $defaults;
        } else {
            $settings = wp_parse_args($settings, $defaults);
        }

        //define key's handle
        $isTestDefined = defined('FCT_STRIPE_TEST_PUBLIC_KEY') && defined('FCT_STRIPE_TEST_SECRET_KEY');
        $isLiveDefined = defined('FCT_STRIPE_LIVE_PUBLIC_KEY') && defined('FCT_STRIPE_LIVE_SECRET_KEY');
        if ($isTestDefined || $isLiveDefined) {
            $settings['define_test_keys'] = $isTestDefined;
            $settings['define_live_keys'] = $isLiveDefined;
            $settings['provider'] = 'api_keys';
        }


        $this->settings = apply_filters('fluent_cart/stripe_settings', $settings);
    }

    /**
     * @return array with default fields value
     */
    public static function getDefaults()
    {
        return [
            'is_active'            => 'no',
            'provider'             => 'connect',
            //define keys
            'define_test_keys'     => false,
            'define_live_keys'     => false,
            // test keys
            'test_publishable_key' => '',
            'test_secret_key'      => '',
            'test_webhook_secret'  => '',
            // Live keys
            'live_publishable_key' => '',
            'live_secret_key'      => '',
            'live_webhook_secret'  => '',
            // Others
            'payment_mode'         => 'live',
            'checkout_mode'        => 'onsite',
            'live_is_encrypted'    => 'no',
            'test_is_encrypted'    => 'no',
            'secure'               => 'yes'
        ];
    }

    public function isActive(): bool
    {
        return $this->settings['is_active'] == 'yes';
    }

    public function get($key = '')
    {
        $settings = $this->settings;

        if ($key && isset($this->settings[$key])) {
            return $this->settings[$key];
        }
        return $settings;
    }

    public function updateSettings($settings)
    {
        $defaults = static::getDefaults();
        $settings = wp_parse_args($settings, $defaults);

        if (defined('FCT_STRIPE_LIVE_PUBLIC_KEY')) {
            $settings['live_publishable_key'] = '';
        }

        if (defined('FCT_STRIPE_TEST_PUBLIC_KEY')) {
            $settings['test_publishable_key'] = '';
        }

        if (defined('FCT_STRIPE_LIVE_SECRET_KEY')) {
            $settings['live_secret_key'] = '';
        }

        if (defined('FCT_STRIPE_TEST_SECRET_KEY')) {
            $settings['test_secret_key'] = '';
        }

        fluent_cart_update_option($this->methodHandler, $settings);

        return $settings;
    }

    public function getMode()
    {
        // return store mode
        return (new StoreSettings)->get('order_mode');
    }

    public function getPublicKey()
    {
        if ($this->getMode() === 'test') {
            return defined('FCT_STRIPE_TEST_PUBLIC_KEY') ? FCT_STRIPE_TEST_PUBLIC_KEY : $this->get()['test_publishable_key'] . '';
        }

        return defined('FCT_STRIPE_LIVE_PUBLIC_KEY') ? FCT_STRIPE_LIVE_PUBLIC_KEY : $this->get()['live_publishable_key'] . '';
    }

    public function getApiKey($mode = 'current')
    {
        if ($mode == 'current' || !$mode) {
            $mode = $this->getMode();
        }

        if ($mode === 'test') {
            return defined('FCT_STRIPE_TEST_SECRET_KEY') ? FCT_STRIPE_TEST_SECRET_KEY : Helper::decryptKey($this->get()['test_secret_key']) . '';
        }

        return defined('FCT_STRIPE_LIVE_SECRET_KEY') ? FCT_STRIPE_LIVE_SECRET_KEY : Helper::decryptKey($this->get()['live_secret_key']) . '';
    }

}

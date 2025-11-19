<?php

namespace FluentCart\App\Modules\PaymentMethods\PayPalGateway;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\Framework\Support\Arr;
use FluentCart\Api\StoreSettings;

class PayPalSettingsBase extends BaseGatewaySettings
{
    public $methodHandler = 'fluent_cart_payment_settings_paypal';

    public $settings;
    public $storeSettings = null;

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
        
        // if key defined
        $isTestDefined = $this->hasManualTesKeys();
        $isLiveDefined = $this->hasManualLiveKeys();

        if ($isTestDefined || $isLiveDefined) {
            $settings['define_test_keys'] = $isTestDefined;
            $settings['define_live_keys'] = $isLiveDefined;
            $settings['provider'] = 'api_keys';
        }

        $this->settings = $settings;

        if (!$this->storeSettings) {
            $this->storeSettings = new StoreSettings();
        }
    }

    private function hasManualTesKeys(): bool
    {
        return defined('FCT_PAYPAL_TEST_PUBLIC_KEY') && defined('FCT_PAYPAL_TEST_SECRET_KEY');
    }

    private function hasManualLiveKeys(): bool
    {
        return defined('FCT_PAYPAL_LIVE_PUBLIC_KEY') && defined('FCT_PAYPAL_LIVE_SECRET_KEY');
    }

    public static function getDefaults(): array
    {

        return [
            'is_active'           => 'no',
            'provider'            => 'connect',
            //define keys
            'define_test_keys' => false,
            'define_live_keys' => false,
            'payment_mode'        => 'live',
            'live_email_address'  => '',
            'test_email_address'  => '',
            'checkout_mode'       => 'paypal_pro',
            'test_client_id'      => '',
            'live_client_id'      => '',
            'test_client_secret'  => '',
            'live_client_secret'  => '',
            'notify_method'       => 'webhook',
            'test_webhook_id'     => '',
            'live_webhook_id'     => '',
            'test_webhook_events' => [],
            'live_webhook_events' => [],

            'disable_ipn_verification' => 'no',
        ];
    }

    public function getProviderType()
    {
        return Arr::get( $this->settings, 'provider');
    }


    public function isActive(): bool
    {
        $settings = $this->get();

        if ($settings['is_active'] === 'yes' && Arr::get($settings, 'checkout_mode') === 'paypal_pro') {

            if ( $this->getProviderType() === 'api_keys') {
                return Arr::get($settings, 'define_test_keys', true) || Arr::get($settings, 'define_live_keys', true);
            }

            $mode = $this->storeSettings->get('order_mode');
            $id = $mode . '_client_id';

            return !empty($settings[$id]);
        }

        return $settings['is_active'] === 'yes' && !!$this->getVendorEmail();
    }

    public function get($key = '')
    {
        $settings = $this->settings;
        if ($key) {
            return $this->settings[$key] ?? null;
        }
        return $settings;
    }

    public function getMode()
    {
        if (!$this->storeSettings) {
            $this->storeSettings = new StoreSettings();
        }
        return $this->storeSettings->get('order_mode');
    }

    public function getVendorEmail()
    {
        if ($this->getMode() === 'test') {
            return $this->settings['test_email_address'];
        }
        return $this->settings['live_email_address'];
    }

    public function getApiKey($mode = '')
    {
        // if no custom mode is provided, get the mode from the store settings
        if (!$mode) {
            $mode = $this->getMode();
        }

        if ($mode === 'test') {
            return defined('FCT_PAYPAL_TEST_SECRET_KEY') ? FCT_PAYPAL_TEST_SECRET_KEY : Helper::decryptKey($this->get()['test_client_secret']) . '';
        }

        return defined('FCT_PAYPAL_LIVE_SECRET_KEY') ? FCT_PAYPAL_LIVE_SECRET_KEY : Helper::decryptKey($this->get()['live_client_secret']) . '';
    }

    public function getPublicKey($mode = '')
    {
        if (!$mode) {
            $mode = $this->getMode();
        }

        if ($mode === 'test') {
            return defined('FCT_PAYPAL_TEST_PUBLIC_KEY') ? FCT_PAYPAL_TEST_PUBLIC_KEY : $this->get()['test_client_id'];
        }

        return defined('FCT_PAYPAL_LIVE_PUBLIC_KEY') ? FCT_PAYPAL_LIVE_PUBLIC_KEY : $this->get()['live_client_id'];

    }

    public function getMerchantId()
    {
        return $this->get($this->getMode() . '_account_id');
    }


    public function updateNonSensitiveData($data)
    {
        $data = Arr::except($data, ['live_client_secret', 'test_client_secret']);
        $settings = wp_parse_args($data, $this->settings);
        fluent_cart_update_option('fluent_cart_payment_settings_paypal', $settings);
    }
}

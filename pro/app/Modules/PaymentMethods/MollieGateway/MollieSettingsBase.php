<?php

namespace FluentCartPro\App\Modules\PaymentMethods\MollieGateway;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;

class MollieSettingsBase extends BaseGatewaySettings
{
    public $settings;
    public $methodHandler = 'fluent_cart_payment_settings_mollie';

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

        $this->settings = apply_filters('fluent_cart/mollie_settings', $settings);
    }

    public static function getDefaults()
    {
        return [
            'is_active'     => 'no',
            'test_api_key'  => '',
            'live_api_key'  => '',
            'payment_mode'  => 'test',
            'webhook_url'   => '',
            'is_authorize_a_success_state' => 'no' ,
            'render_selected_methods_only' => 'no'
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

    public function getMode()
    {
        // return store mode
        return (new StoreSettings)->get('order_mode');
    }

    public function getApiKey($mode = 'current')
    {
        if ($mode == 'current' || !$mode) {
            $mode = $this->getMode();
        }

        if ($mode === 'test') {
            $apiKey = $this->get('test_api_key');
        } else {
            $apiKey = $this->get('live_api_key');
        }

        return Helper::decryptKey($apiKey);
    }
}

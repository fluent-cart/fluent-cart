<?php

namespace FluentCart\App\Modules\PaymentMethods\AirwallexGateway;

use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\Framework\Support\Arr;

class AirwallexSettingsBase extends BaseGatewaySettings
{
    public $settings;
    public $methodHandler = 'fluent_cart_payment_settings_airwallex';

    public static function getDefaults()
    {
        return [
            'is_active' => 'no',
            'test_client_id' => '',
            'live_client_id' => '',
            'test_api_key' => '',
            'live_api_key' => '',
            'payment_mode' => 'test',
        ];
    }

    public function isActive(): bool
    {
        return $this->settings['is_active'] == 'yes';
    }

    public function get($key = '')
    {
        if ($key && isset($this->settings[$key])) {
            return $this->settings[$key];
        }
        return $this->settings;
    }

    public function getMode()
    {
        return $this->get('payment_mode');
    }

    public function getClientId()
    {
        return $this->get('client_id');
    }

    public function getApiKey()
    {
        return Helper::decryptKey($this->get('api_key'));
    }

    public function getWebhookSecret()
    {
        return Helper::decryptKey($this->get('webhook_secret'));
    }
}

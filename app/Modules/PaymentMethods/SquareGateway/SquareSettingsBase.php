<?php

namespace FluentCart\App\Modules\PaymentMethods\SquareGateway;

use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\Framework\Support\Arr;

class SquareSettingsBase extends BaseGatewaySettings
{
    public $settings;
    public $methodHandler = 'fluent_cart_payment_settings_square';


    public static function getDefaults()
    {
        return [
            'is_active' => 'no',
            'application_id' => '',
            'access_token' => '',
            'location_id' => '',
            'webhook_signature_key' => '',
            'payment_mode' => 'sandbox',
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

    public function getApplicationId()
    {
        return $this->get('application_id');
    }

    public function getAccessToken()
    {
        return Helper::decryptKey($this->get('access_token'));
    }

    public function getLocationId()
    {
        return $this->get('location_id');
    }

    public function getWebhookSignatureKey()
    {
        return Helper::decryptKey($this->get('webhook_signature_key'));
    }
}

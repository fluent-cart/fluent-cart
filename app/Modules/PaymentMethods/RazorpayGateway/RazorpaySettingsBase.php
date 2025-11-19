<?php

namespace FluentCart\App\Modules\PaymentMethods\RazorpayGateway;

use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\Framework\Support\Arr;

class RazorpaySettingsBase extends BaseGatewaySettings
{
    public $settings;
    public $methodHandler = 'fluent_cart_payment_settings_razorpay';


    public static function getDefaults()
    {
        return [
            'is_active' => 'no',
            'key_id' => '',
            'key_secret' => '',
            'webhook_secret' => '',
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

    public function getKeyId()
    {
        return $this->get('key_id');
    }

    public function getKeySecret()
    {
        return Helper::decryptKey($this->get('key_secret'));
    }

    public function getWebhookSecret()
    {
        return Helper::decryptKey($this->get('webhook_secret'));
    }
}

<?php

namespace FluentCart\App\Modules\PaymentMethods\AuthorizeNetGateway;

use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\Framework\Support\Arr;

class AuthorizeNetSettingsBase extends BaseGatewaySettings
{
    public $settings;
    public $methodHandler = 'fluent_cart_payment_settings_authorize_net';

    public static function getDefaults()
    {
        return [
            'is_active' => 'no',
            'test_api_login' => '',
            'live_api_login' => '',
            'test_transaction_key' => '',
            'live_transaction_key' => '',
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

    public function getApiLoginId()
    {
        return $this->get('api_login_id');
    }

    public function getTransactionKey()
    {
        return Helper::decryptKey($this->get('transaction_key'));
    }

    public function getClientKey()
    {
        return Helper::decryptKey($this->get('client_key'));
    }

    public function getSignatureKey()
    {
        return Helper::decryptKey($this->get('signature_key'));
    }
}

<?php

namespace FluentCart\App\Modules\PaymentMethods\Cod;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;

class CodSettingsBase extends BaseGatewaySettings
{

    public $settings;
    public $methodHandler = 'fluent_cart_payment_settings_offline_payment';


    public static function getDefaults(): array
    {
        return [
            'is_active'            => 'no',
            'payment_mode'         => 'live',
        ];
    }

    public function isActive(): bool
    {
        return $this->settings['active'] == 'yes';
    }

    public function getMode()
    {
        return (new StoreSettings)->get('order_mode');
    }

    public function getPublicKey()
    {
        // TODO: Implement getPublicKey() method.
    }

    public function getApiKey()
    {
        // TODO: Implement getApiKey() method.
    }

    public function get($key = '')
    {
        $settings = $this->settings;

        if ($key && isset($this->settings[$key])) {
            return $this->settings[$key];
        }
        return $settings;
    }
}

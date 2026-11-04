<?php

namespace Tests\Support\Doubles;

use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;

class CheckoutGatewaySettings extends BaseGatewaySettings
{
    public $methodHandler = 'fluent_cart_payment_settings_wp_browser_stub';

    public static function getDefaults(): array
    {
        return ['is_active' => 'yes', 'payment_mode' => 'test'];
    }

    public function get($key = '')
    {
        return $key !== '' ? ($this->settings[$key] ?? null) : $this->settings;
    }

    public function getMode()
    {
        return 'test';
    }

    public function isActive(): bool
    {
        return true;
    }
}

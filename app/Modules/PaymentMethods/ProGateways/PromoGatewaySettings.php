<?php

namespace FluentCart\App\Modules\PaymentMethods\ProGateways;

use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;

class PromoGatewaySettings extends BaseGatewaySettings
{
    protected $gatewaySlug;

    public function __construct($gatewaySlug)
    {
        $this->gatewaySlug = $gatewaySlug;
        $this->methodHandler = 'fluent_cart_payment_settings_promo_gateway';
        parent::__construct();
    }

    public static function getDefaults()
    {
        return [
            'is_active' => 'no'
        ];
    }

    public function get($key = null)
    {
        if ($key === 'is_active') {
            return 'no';
        }
        
        if ($key) {
            return isset($this->settings[$key]) ? $this->settings[$key] : null;
        }
        
        return $this->settings;
    }
    
    public function getMode()
    {
        return 'test';
    }
    
    public function isActive(): bool
    {
        return false;
    }
}

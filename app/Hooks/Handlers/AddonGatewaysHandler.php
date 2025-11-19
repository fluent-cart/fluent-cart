<?php

namespace FluentCart\App\Hooks\Handlers;

use FluentCart\App\Modules\PaymentMethods\AddonGateways\PaystackAddon;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;

class AddonGatewaysHandler
{
    public function register()
    {
        add_action('fluent_cart/register_payment_methods', [$this, 'registerPromoGateways'], 20);
    }
    
    public function registerPromoGateways()
    {
        foreach ([
            'paystack' => PaystackAddon::class,
        ] as $slug => $addonClass) {
            $isGatewayRegistered = GatewayManager::has($slug);
            if (!$isGatewayRegistered) {
                $gateway = GatewayManager::getInstance();
                $gateway->register($slug, new $addonClass());
            }
        }
        
    }
    
}

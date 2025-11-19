<?php

namespace FluentCart\App\Hooks\Handlers;

use FluentCart\App\Modules\PaymentMethods\ProGateways\PaddlePromo;
use FluentCart\App\Modules\PaymentMethods\ProGateways\MolliePromo;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;

class PromoGatewaysHandler
{
    public function register()
    {
        add_action('fluent_cart/register_payment_methods', [$this, 'registerPromoGateways'], 20);
    }
    
    public function registerPromoGateways()
    {
        $isProActive = defined('FLUENTCART_PRO_PLUGIN_VERSION');   

        foreach ([
            'paddle' => PaddlePromo::class,
            'mollie' => MolliePromo::class,
        ] as $slug => $promoClass) {
            $isGatewayRegistered = GatewayManager::has($slug);
            if (!$isGatewayRegistered && !$isProActive) {
                $gateway = GatewayManager::getInstance();
                $gateway->register($slug, new $promoClass());
            }
        }
        
    }
    
}

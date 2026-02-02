<?php

namespace FluentCart\App\Hooks\Handlers;

use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\App\Modules\PaymentMethods\PromoGateways\Addons\PaystackAddon;
use FluentCart\App\Modules\PaymentMethods\PromoGateways\Addons\RazorpayAddon;
use FluentCart\App\Modules\PaymentMethods\PromoGateways\Addons\MercadoPagoAddon;

class AddonGatewaysHandler
{
    /**
     * Default addon gateways to register
     * @var array
     */
    protected $defaultGateways = [
        'paystack' => PaystackAddon::class,
        'razorpay' => RazorpayAddon::class,
        'mercado_pago' => MercadoPagoAddon::class,
    ];
    
    public function register()
    {
        add_action('fluent_cart/register_payment_methods', [$this, 'registerPromoGateways'], 20);
    }
    
    /**
     * Register addon gateways
     * Can be filtered to add or remove gateways
     */
    public function registerPromoGateways()
    {
        // Allow filtering of addon gateways
        $gateways = apply_filters('fluent_cart/addon_gateways', $this->defaultGateways);
        
        foreach ($gateways as $slug => $addonClass) {
            // Skip if class doesn't exist
            if (!class_exists($addonClass)) {
                continue;
            }
            
            $isGatewayRegistered = GatewayManager::has($slug);
            if (!$isGatewayRegistered) {
                $gateway = GatewayManager::getInstance();
                try {
                    $gateway->register($slug, new $addonClass());
                } catch (\Exception $e) {
                    // Log error but continue with other gateways
                    error_log(sprintf(
                        'Failed to register addon gateway %s: %s',
                        $slug,
                        $e->getMessage()
                    ));
                }
            }
        }
    }
    
    /**
     * Add a new addon gateway to the default list
     * 
     * @param string $slug
     * @param string $className
     */
    public function addGateway($slug, $className)
    {
        $this->defaultGateways[$slug] = $className;
    }
    
    /**
     * Remove an addon gateway from the default list
     * 
     * @param string $slug
     */
    public function removeGateway($slug)
    {
        unset($this->defaultGateways[$slug]);
    }
}

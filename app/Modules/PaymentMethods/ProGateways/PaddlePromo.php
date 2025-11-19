<?php

namespace FluentCart\App\Modules\PaymentMethods\ProGateways;

use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Vite;

class PaddlePromo extends AbstractPaymentGateway
{
    public array $supportedFeatures = [];

    public function __construct()
    {
        parent::__construct(new PromoGatewaySettings('paddle'));
    }

    public function meta(): array
    {
        return [
            'title' => 'Paddle',
            'route' => 'paddle',
            'slug' => 'paddle',
            'description' => 'Accept credit cards and PayPal payments securely with Paddle. Available in FluentCart Pro.',
            'logo' => Vite::getAssetUrl("images/payment-methods/paddle-logo.svg"),
            'icon' => Vite::getAssetUrl("images/payment-methods/paddle-logo.svg"),
            'brand_color' => '#7c3aed',
            'status' => false,
            'upcoming' => false,
            'requires_pro' => true,
            'upgrade_url' => '',
            'supported_features' => $this->supportedFeatures
        ];
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        // This will not be called since the gateway is not active
        return null;
    }

    public function handleIPN()
    {
        // This will not be called since the gateway is not active
    }

    public function getOrderInfo(array $data)
    {
        // This will not be called since the gateway is not active
        return null;
    }

    public function fields()
    {
        // This will show the Upgrade to Pro message
        return [
        ];
    }
}

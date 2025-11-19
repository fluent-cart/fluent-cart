<?php

namespace FluentCart\App\Modules\PaymentMethods\ProGateways;

use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Vite;

class MolliePromo extends AbstractPaymentGateway
{
    public array $supportedFeatures = [];

    public function __construct()
    {
        parent::__construct(new PromoGatewaySettings('mollie'));
    }

    public function meta(): array
    {
        return [
            'title' => 'Mollie',
            'route' => 'mollie',
            'slug' => 'mollie',
            'description' => 'Pay securely with Mollie - Credit Card, PayPal, SEPA, and more.',
            'logo' => Vite::getAssetUrl("images/payment-methods/mollie-logo.svg"),
            'icon' => Vite::getAssetUrl("images/payment-methods/mollie-icon.svg"),
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

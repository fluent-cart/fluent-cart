<?php

namespace FluentCart\App\Modules\PaymentMethods\PromoGateways\Pro;

use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Vite;

class PaddlePromo extends AbstractPaymentGateway
{
    public array $supportedFeatures = [];

    public function __construct()
    {
        $settings = new PromoGatewaySettings('paddle');
        parent::__construct($settings);
    }

    public function meta(): array
    {
        return [
            'title' => 'Paddle',
            'route' => 'paddle',
            'slug' => 'paddle',
            'description' => __('Accept credit cards and PayPal payments securely with Paddle. Available in FluentCart Pro.', 'fluent-cart'),
            'logo' => Vite::getAssetUrl("images/payment-methods/paddle-logo.svg"),
            'icon' => Vite::getAssetUrl("images/payment-methods/paddle-logo.svg"),
            'brand_color' => '#7c3aed',
            'status' => false,
            'requires_pro' => true,
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

    /**
     * Get Paddle-specific notice configuration
     */
    private function getNoticeConfig()
    {
        return [
            'title' => __('Paddle Payment Gateway', 'fluent-cart'),
            'description' => __('Accept credit cards and PayPal securely with Paddle. Complete checkout solution with built-in tax handling, invoicing, and subscription management.', 'fluent-cart'),
            'features' => [
                __('Credit Card & PayPal payments', 'fluent-cart'),
                __('Recurring billing & subscriptions', 'fluent-cart'),
                __('Built-in tax & VAT handling', 'fluent-cart'),
                __('Automatic invoicing & refunds', 'fluent-cart')
            ],
            'icon_path' => 'M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z',
            'upgrade_url' => 'https://fluentcart.com/pricing/'
        ];
    }

    /**
     * Generate upgrade to pro message
     */
    public function upgradeToProMessage()
    {
        return $this->settings->generateUpgradeNotice($this->getNoticeConfig());
    }

    public function fields()
    {
        return [
            'notice' => [
                'value' => $this->upgradeToProMessage(),
                'label' => __('Paddle Payment Gateway', 'fluent-cart'),
                'type'  => 'html_attr'
            ],
        ];
    }
}

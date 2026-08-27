<?php

namespace FluentCart\App\Modules\PaymentMethods\PromoGateways\Pro;

use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Vite;

class MolliePromo extends AbstractPaymentGateway
{
    public array $supportedFeatures = [];

    public function __construct()
    {
        $settings = new PromoGatewaySettings('mollie');
        parent::__construct($settings);
    }

    public function meta(): array
    {
        return [
            'title' => 'Mollie',
            'route' => 'mollie',
            'slug' => 'mollie',
            'description' => __('Pay securely with Mollie - Credit Card, PayPal, SEPA, and more.', 'fluent-cart'),
            'logo' => Vite::getAssetUrl("images/payment-methods/mollie-logo.svg"),
            'icon' => Vite::getAssetUrl("images/payment-methods/mollie-icon.svg"),
            'brand_color' => '#7c3aed',
            'status' => false,
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

    /**
     * Get Mollie-specific notice configuration
     */
    private function getNoticeConfig()
    {
        return [
            'title' => __('Mollie Payment Gateway', 'fluent-cart'),
            'description' => __('Accept payments through Mollie - Credit Card, PayPal, SEPA, iDEAL, Bancontact, and more. Popular in Europe with seamless checkout experience.', 'fluent-cart'),
            'features' => [
                __('Multiple payment methods in one gateway', 'fluent-cart'),
                __('Recurring payments & subscriptions', 'fluent-cart'),
                __('Automatic payment confirmation', 'fluent-cart'),
                __('Refund management & webhooks', 'fluent-cart')
            ],
            'icon_path' => 'M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z',
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
                'label' => __('Mollie Payment Gateway', 'fluent-cart'),
                'type'  => 'html_attr'
            ],
        ];
    }
}

<?php

namespace FluentCart\App\Modules\PaymentMethods\PromoGateways\Pro;

use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Vite;

class AuthorizeNetPromo extends AbstractPaymentGateway
{
    public array $supportedFeatures = [];

    public function __construct()
    {
        $settings = new PromoGatewaySettings('authorize_dot_net');
        
        // Optional: Set custom color gradient if needed
        // $settings->setCustomStyles([
        //     'primary_gradient' => 'linear-gradient(135deg, #0066cc 0%, #004999 100%)'
        // ]);
        
        parent::__construct($settings);
    }

    public function meta(): array
    {
        return [
            'title' => 'Authorize.Net',
            'route' => 'authorize_dot_net',
            'slug' => 'authorize_dot_net',
            'description' => __('Pay securely with Authorize.Net - Credit and Debit Cards', 'fluent-cart'),
            'logo' => Vite::getAssetUrl("images/payment-methods/authorize_dot_net-logo.svg"),
            'icon' => Vite::getAssetUrl("images/payment-methods/authorize_dot_net-logo.svg"),
            'brand_color' => '#0066cc',
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

   
    private function getNoticeConfig()
    {
        return [
            'title' => __('Authorize.Net Payment Gateway', 'fluent-cart'),
            'description' => __('Accept credit card and eCheck payments securely with Authorize.Net. Features include on-site checkout, fraud detection, and recurring billing support.', 'fluent-cart'),
            'features' => [
                __('On-site checkout with AcceptUI', 'fluent-cart'),
                __('Credit Card and eCheck payments', 'fluent-cart'),
                __('Automatic payment confirmation', 'fluent-cart'),
                __('Refund management & webhooks', 'fluent-cart')
            ],
            'icon_path' => 'M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z',
            'upgrade_url' => 'https://fluentcart.com/pricing/'
        ];
    }

   
    public function upgradeToProMessage()
    {
        return $this->settings->generateUpgradeNotice($this->getNoticeConfig());
    }

    public function fields()
    {
        return [
            'notice' => [
                'value' => $this->upgradeToProMessage(),
                'label' => __('Authorize.Net Payment Gateway', 'fluent-cart'),
                'type'  => 'html_attr'
            ],
        ];
    }
}


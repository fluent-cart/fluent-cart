<?php

namespace FluentCart\App\Modules\PaymentMethods\PromoGateways\Addons;

use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Modules\PaymentMethods\PromoGateways\Addons\AddonGatewaySettings;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Services\PluginInstaller\PaymentAddonManager;
use FluentCart\App\Vite;

class PaystackAddon extends AbstractPaymentGateway
{
    public array $supportedFeatures = [];

    private $addonSlug = 'paystack-for-fluent-cart';
    private $addonFile = 'paystack-for-fluent-cart/paystack-for-fluent-cart.php';

    public function __construct()
    {
        $settings = new AddonGatewaySettings('paystack');
        
        $settings->setCustomStyles([
            'light' => [
                'icon_bg' => '#dcfce7',
                'icon_color' => '#16a34a'
            ],
            'dark' => [
                'icon_bg' => 'rgba(34, 197, 94, 0.15)',
                'icon_color' => '#4ade80'
            ]
        ]);
        
        parent::__construct($settings);
    }

    public function meta(): array
    {
        $addonStatus = PaymentAddonManager::getAddonStatus($this->addonSlug, $this->addonFile);

        return [
            'title' => 'Paystack',
            'route' => 'paystack',
            'slug' => 'paystack',
            'description' => 'Pay securely with Paystack - Cards, Bank Transfer, USSD, and Mobile Money',
            'logo' => Vite::getAssetUrl("images/payment-methods/paystack-logo.svg"),
            'icon' => Vite::getAssetUrl("images/payment-methods/paystack-logo.svg"),
            'brand_color' => '#0fa958',
            'status' => false,
            'is_addon' => true,
            'addon_status' => $addonStatus,
            'addon_source' => [
                'type' => 'cdn',
                'link' => 'https://addons-cdn.fluentcart.com/paystack-for-fluent-cart.zip',
                'slug' => 'paystack-for-fluent-cart',
                'repo_link' => 'https://fluentcart.com/fluentcart-addons'
            ]
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
     * Get Paystack-specific notice configuration
     */
    private function getNoticeConfig()
    {
        $meta = $this->meta();
        
        return [
            'title' => __('Paystack Payment Gateway', 'fluent-cart'),
            'description' => __('Accept payments with Paystack - Cards, Bank Transfer, USSD, and Mobile Money. Perfect for businesses in Africa.', 'fluent-cart'),
            'features' => [
                __('Card payments & Bank transfers', 'fluent-cart'),
                __('USSD & Mobile Money support', 'fluent-cart'),
                __('Recurring payments & subscriptions', 'fluent-cart'),
                __('Free & Open Source addon', 'fluent-cart')
            ],
            'icon_path' => 'M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zM9 6c0-1.66 1.34-3 3-3s3 1.34 3 3v2H9V6zm9 14H6V10h12v10zm-6-3c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2z',
            'addon_slug' => $this->addonSlug,
            'addon_file' => $this->addonFile,
            'repo_link' => 'https://fluentcart.com/fluentcart-addons',
            'addon_source' => $meta['addon_source'] ?? [],
            'footer_text' => __('Free addon - Click the button above to get started', 'fluent-cart')
        ];
    }

    /**
     * Generate addon notice message
     */
    public function addonNoticeMessage()
    {
        return $this->settings->generateAddonNotice($this->getNoticeConfig());
    }

    public function fields()
    {
        return [
            'notice' => [
                'value' => $this->addonNoticeMessage(),
                'label' => __('Paystack Payment Gateway', 'fluent-cart'),
                'type'  => 'html_attr'
            ],
        ];
    }
}

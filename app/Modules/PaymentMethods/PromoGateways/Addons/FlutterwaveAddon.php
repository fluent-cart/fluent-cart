<?php

namespace FluentCart\App\Modules\PaymentMethods\PromoGateways\Addons;

use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Modules\PaymentMethods\PromoGateways\Addons\AddonGatewaySettings;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Services\PluginInstaller\PaymentAddonManager;
use FluentCart\App\Vite;

class FlutterwaveAddon extends AbstractPaymentGateway
{
    public array $supportedFeatures = [];

    private $addonSlug = 'flutterwave-for-fluent-cart';
    private $addonFile = 'flutterwave-for-fluent-cart/flutterwave-for-fluent-cart.php';

    public function __construct()
    {
        $settings = new AddonGatewaySettings('flutterwave');
        
        $settings->setCustomStyles([
            'light' => [
                'icon_bg' => '#fff7ed',
                'icon_color' => '#F5A623'
            ],
            'dark' => [
                'icon_bg' => 'rgba(245, 166, 35, 0.15)',
                'icon_color' => '#fbbf24'
            ]
        ]);
        
        parent::__construct($settings);
    }

    public function meta(): array
    {
        $addonStatus = PaymentAddonManager::getAddonStatus($this->addonSlug, $this->addonFile);

        return [
            'title' => 'Flutterwave',
            'route' => 'flutterwave',
            'slug' => 'flutterwave',
            'description' => 'Pay securely with Flutterwave - Card, Bank Transfer, Mobile Money, and more',
            'logo' => Vite::getAssetUrl("images/payment-methods/flutterwave-logo.svg"),
            'icon' => Vite::getAssetUrl("images/payment-methods/flutterwave-logo.svg"),
            'brand_color' => '#F5A623',
            'status' => false,
            'is_addon' => true,
            'addon_status' => $addonStatus,
            'addon_source' => [
                'type' => 'cdn',
                'link' => 'https://addons-cdn.fluentcart.com/flutterwave-for-fluent-cart.zip',
                'slug' => 'flutterwave-for-fluent-cart',
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
     * Get Flutterwave-specific notice configuration
     */
    private function getNoticeConfig()
    {
        $meta = $this->meta();
        
        return [
            'title' => __('Flutterwave Payment Gateway', 'fluent-cart'),
            'description' => __('Accept payments with Flutterwave - Card, Bank Transfer, Mobile Money, USSD, M-Pesa and more. Perfect for businesses in Africa.', 'fluent-cart'),
            'features' => [
                __('Card payments & Bank transfers', 'fluent-cart'),
                __('Mobile Money, USSD & M-Pesa', 'fluent-cart'),
                __('Free & Open Source addon', 'fluent-cart')
            ],
            'icon_path' => 'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm.31-8.86c-1.77-.45-2.34-.94-2.34-1.67 0-.84.79-1.43 2.1-1.43 1.38 0 1.9.66 1.94 1.64h1.71c-.05-1.34-.87-2.57-2.49-2.97V5H10.9v1.69c-1.51.32-2.72 1.3-2.72 2.81 0 1.79 1.49 2.69 3.66 3.21 1.95.46 2.34 1.15 2.34 1.87 0 .53-.39 1.39-2.1 1.39-1.6 0-2.23-.72-2.32-1.64H8.04c.1 1.7 1.36 2.66 2.86 2.97V19h2.34v-1.67c1.52-.29 2.72-1.16 2.73-2.77-.01-2.2-1.9-2.96-3.66-3.42z',
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
                'label' => __('Flutterwave Payment Gateway', 'fluent-cart'),
                'type'  => 'html_attr'
            ],
        ];
    }
}

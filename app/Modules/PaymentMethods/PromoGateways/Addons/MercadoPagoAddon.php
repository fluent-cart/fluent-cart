<?php

namespace FluentCart\App\Modules\PaymentMethods\PromoGateways\Addons;

use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Modules\PaymentMethods\PromoGateways\Addons\AddonGatewaySettings;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Services\PluginInstaller\PaymentAddonManager;
use FluentCart\App\Vite;

class MercadoPagoAddon extends AbstractPaymentGateway
{
    public array $supportedFeatures = [];

    private $addonSlug = 'mercado-pago-for-fluent-cart';
    private $addonFile = 'mercado-pago-for-fluent-cart/mercado-pago-for-fluent-cart.php';

    public function __construct()
    {
        $settings = new AddonGatewaySettings('mercado_pago');
        
        $settings->setCustomStyles([
            'light' => [
                'icon_bg' => '#e0f2fe',
                'icon_color' => '#009EE3'
            ],
            'dark' => [
                'icon_bg' => 'rgba(0, 158, 227, 0.15)',
                'icon_color' => '#38bdf8'
            ]
        ]);
        
        parent::__construct($settings);
    }

    public function meta(): array
    {
        $addonStatus = PaymentAddonManager::getAddonStatus($this->addonSlug, $this->addonFile);

        return [
            'title' => 'Mercado Pago',
            'route' => 'mercado_pago',
            'slug' => 'mercado_pago',
            'description' => 'Pay securely with Mercado Pago - Cards, Pix, Boleto, OXXO, and more',
            'logo' => Vite::getAssetUrl("images/payment-methods/mercado-pago-logo.svg"),
            'icon' => Vite::getAssetUrl("images/payment-methods/mercado-pago-logo.svg"),
            'brand_color' => '#009EE3',
            'status' => false,
            'is_addon' => true,
            'addon_status' => $addonStatus,
            'addon_source' => [
                'type' => 'github', // 'github' or 'wordpress' , only github and wordpress are supported
                'link' => 'https://github.com/WPManageNinja/mercado-pago-for-fluent-cart/releases/latest', // link not needed for wordpress
                'slug' => 'mercado-pago-for-fluent-cart'
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
     * Get Mercado Pago-specific notice configuration
     */
    private function getNoticeConfig()
    {
        $meta = $this->meta();
        
        return [
            'title' => __('Mercado Pago Payment Gateway', 'fluent-cart'),
            'description' => __('Accept payments with Mercado Pago - Cards, Pix, Boleto, OXXO, and more. Perfect for businesses in Latin America.', 'fluent-cart'),
            'features' => [
                __('Card payments & Bank transfers', 'fluent-cart'),
                __('Pix, Boleto & Virtual Debit Card', 'fluent-cart'),
                __('Free & Open Source addon', 'fluent-cart')
            ],
            'icon_path' => 'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm.31-8.86c-1.77-.45-2.34-.94-2.34-1.67 0-.84.79-1.43 2.1-1.43 1.38 0 1.9.66 1.94 1.64h1.71c-.05-1.34-.87-2.57-2.49-2.97V5H10.9v1.69c-1.51.32-2.72 1.3-2.72 2.81 0 1.79 1.49 2.69 3.66 3.21 1.95.46 2.34 1.15 2.34 1.87 0 .53-.39 1.39-2.1 1.39-1.6 0-2.23-.72-2.32-1.64H8.04c.1 1.7 1.36 2.66 2.86 2.97V19h2.34v-1.67c1.52-.29 2.72-1.16 2.73-2.77-.01-2.2-1.9-2.96-3.66-3.42z',
            'addon_slug' => $this->addonSlug,
            'addon_file' => $this->addonFile,
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
                'label' => __('Mercado Pago Payment Gateway', 'fluent-cart'),
                'type'  => 'html_attr'
            ],
        ];
    }
}


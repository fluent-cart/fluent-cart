<?php

namespace FluentCart\App\Modules\PaymentMethods\PromoGateways\Addons;

use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Modules\PaymentMethods\PromoGateways\Addons\AddonGatewaySettings;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Services\PluginInstaller\PaymentAddonManager;
use FluentCart\App\Vite;

class AirwallexAddon extends AbstractPaymentGateway
{
    public array $supportedFeatures = [];

    private $addonSlug = 'airwallex-for-fluent-cart';
    private $addonFile = 'airwallex-for-fluent-cart/airwallex-for-fluent-cart.php';

    public function __construct()
    {
        $settings = new AddonGatewaySettings('airwallex', 'fluent_cart_payment_settings_airwallex');

        $settings->setCustomStyles([
            'light' => [
                'icon_bg'    => '#ede9fe',
                'icon_color' => '#6c5ce7',
            ],
            'dark' => [
                'icon_bg'    => 'rgba(108, 92, 231, 0.15)',
                'icon_color' => '#a78bfa',
            ],
        ]);

        parent::__construct($settings);
    }

    public function meta(): array
    {
        $addonStatus = PaymentAddonManager::getAddonStatus($this->addonSlug, $this->addonFile);

        return [
            'title'        => 'Airwallex',
            'route'        => 'airwallex',
            'slug'         => 'airwallex',
            'description'  => __('Pay securely with Airwallex - Global payment processing with Drop-in Element for onsite checkout', 'fluent-cart'),
            'logo'         => Vite::getAssetUrl('images/payment-methods/airwallex-logo.svg'),
            'icon'         => Vite::getAssetUrl('images/payment-methods/airwallex-icon.svg'),
            'brand_color'  => '#6c5ce7',
            'status'       => false,
            'is_addon'     => true,
            'requires_pro' => true,
            'addon_status' => $addonStatus,
            'addon_source' => [
                'type'      => 'cdn',
                'link'      => 'https://fluentcart.com/?fluent-cart=get_license_version',
                'slug'      => 'airwallex-for-fluent-cart',
                'repo_link' => 'https://fluentcart.com/pricing/',
            ],
        ];
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        return null;
    }

    public function handleIPN()
    {
        // not active
    }

    public function getOrderInfo(array $data)
    {
        return null;
    }

    public function addonNoticeMessage()
    {
        $meta = $this->meta();

        $config = [
            'title'       => __('Airwallex Payment Gateway', 'fluent-cart'),
            'description' => __('Accept global payments with Airwallex - Cards, Apple Pay, Google Pay, and more. Onsite checkout with no redirect.', 'fluent-cart'),
            'features'    => [
                __('Onsite checkout - no redirect', 'fluent-cart'),
                __('Apple Pay & Google Pay', 'fluent-cart'),
                __('Global payment processing', 'fluent-cart'),
                __('Automatic refunds via webhooks', 'fluent-cart'),
            ],
            'icon_path'   => 'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.78L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z',
            'addon_slug'  => $this->addonSlug,
            'addon_file'  => $this->addonFile,
            'repo_link'    => 'https://fluentcart.com/pricing/',
            'pro_required' => true,
            'addon_source' => $meta['addon_source'] ?? [],
            'footer_text'  => __('Premium addon - requires FluentCart Pro', 'fluent-cart'),
        ];

        return $this->settings->generateAddonNotice($config);
    }

    public function fields()
    {
        return [
            'notice' => [
                'value' => $this->addonNoticeMessage(),
                'label' => __('Airwallex Payment Gateway', 'fluent-cart'),
                'type'  => 'html_attr',
            ],
        ];
    }
}

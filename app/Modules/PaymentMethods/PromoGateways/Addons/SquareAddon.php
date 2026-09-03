<?php

namespace FluentCart\App\Modules\PaymentMethods\PromoGateways\Addons;

use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Modules\PaymentMethods\PromoGateways\Addons\AddonGatewaySettings;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Services\PluginInstaller\PaymentAddonManager;
use FluentCart\App\Vite;

class SquareAddon extends AbstractPaymentGateway
{
    public array $supportedFeatures = [];

    private $addonSlug = 'square-for-fluent-cart';
    private $addonFile = 'square-for-fluent-cart/square-for-fluent-cart.php';

    public function __construct()
    {
        $settings = new AddonGatewaySettings('square', 'fluent_cart_payment_settings_square');

        $settings->setCustomStyles([
            'light' => [
                'icon_bg'    => '#e5e7eb',
                'icon_color' => '#3e4348',
            ],
            'dark' => [
                'icon_bg'    => 'rgba(62, 67, 72, 0.2)',
                'icon_color' => '#9ca3af',
            ],
        ]);

        parent::__construct($settings);
    }

    public function meta(): array
    {
        $addonStatus = PaymentAddonManager::getAddonStatus($this->addonSlug, $this->addonFile);

        return [
            'title'        => 'Square',
            'route'        => 'square',
            'slug'         => 'square',
            'description'  => 'Pay securely with Square - Credit and Debit Cards, Apple Pay, Google Pay, and Cash App Pay',
            'logo'         => Vite::getAssetUrl('images/payment-methods/square-logo.svg'),
            'icon'         => Vite::getAssetUrl('images/payment-methods/square-logo.svg'),
            'brand_color'  => '#3e4348',
            'status'       => false,
            'is_addon'     => true,
            'requires_pro' => true,
            'addon_status' => $addonStatus,
            'addon_source' => [
                'type'      => 'cdn',
                'link'      => 'https://fluentcart.com/?fluent-cart=get_license_version',
                'slug'      => 'square-for-fluent-cart',
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
            'title'       => __('Square Payment Gateway', 'fluent-cart'),
            'description' => __('Accept payments with Square - Cards, Apple Pay, Google Pay, and Cash App Pay. Works worldwide with no redirect.', 'fluent-cart'),
            'features'    => [
                __('Inline card form - no redirect', 'fluent-cart'),
                __('Apple Pay & Google Pay', 'fluent-cart'),
                __('Recurring subscriptions', 'fluent-cart'),
                __('Automatic refunds via webhooks', 'fluent-cart'),
            ],
            'icon_path'   => 'M17,22H7c-2.8,0-5-2.2-5-5V7c0-2.8,2.2-5,5-5h10c2.8,0,5,2.2,5,5v10C22,19.7,19.8,22,17,22zM7,4C5.3,4,4,5.3,4,7v10c0,1.7,1.3,3,3,3h10c1.7,0,3-1.3,3-3V7c0-1.7-1.3-3-3-3H7z M14,9h-4c-0.6,0-1,0.4-1,1v4c0,0.6,0.4,1,1,1h4c0.6,0,1-0.4,1-1v-4C15,9.4,14.6,9,14,9z',
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
                'label' => __('Square Payment Gateway', 'fluent-cart'),
                'type'  => 'html_attr',
            ],
        ];
    }
}

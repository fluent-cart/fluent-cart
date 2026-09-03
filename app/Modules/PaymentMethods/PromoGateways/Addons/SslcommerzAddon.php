<?php

namespace FluentCart\App\Modules\PaymentMethods\PromoGateways\Addons;

use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Services\PluginInstaller\PaymentAddonManager;
use FluentCart\App\Vite;

class SslcommerzAddon extends AbstractPaymentGateway
{
    public array $supportedFeatures = [];

    private $addonSlug = 'sslcommerz-for-fluent-cart';
    private $addonFile = 'sslcommerz-for-fluent-cart/sslcommerz-for-fluent-cart.php';

    public function __construct()
    {
        $settings = new AddonGatewaySettings('sslcommerz', 'fluent_cart_payment_settings_sslcommerz');

        $settings->setCustomStyles([
            'light' => [
                'icon_bg'    => '#dcfce7',
                'icon_color' => '#0b9e48',
            ],
            'dark' => [
                'icon_bg'    => 'rgba(11, 158, 72, 0.18)',
                'icon_color' => '#22c55e',
            ],
        ]);

        parent::__construct($settings);
    }

    public function meta(): array
    {
        $addonStatus = PaymentAddonManager::getAddonStatus($this->addonSlug, $this->addonFile);

        return [
            'title'        => 'SSLCommerz',
            'route'        => 'sslcommerz',
            'slug'         => 'sslcommerz',
            'label'        => 'SSLCommerz',
            'admin_title'  => 'SSLCommerz',
            'description'  => 'Accept SSLCommerz payments in FluentCart with cards, mobile banking, internet banking, and refunds.',
            'logo'         => Vite::getAssetUrl('images/payment-methods/sslcommerz-logo.svg'),
            'icon'         => Vite::getAssetUrl('images/payment-methods/sslcommerz-logo.svg'),
            'brand_color'  => '#0b9e48',
            'status'       => false,
            'is_addon'     => true,
            'addon_status' => $addonStatus,
            'addon_source' => [
                'type'      => 'cdn',
                'link'      => 'https://addons-cdn.fluentcart.com/sslcommerz-for-fluent-cart.zip',
                'slug'      => $this->addonSlug,
                'repo_link' => 'https://fluentcart.com/fluentcart-addons/',
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
            'title'        => __('SSLCommerz Payment Gateway', 'fluent-cart'),
            'description'  => __('Accept payments with SSLCommerz for Bangladesh, including cards, mobile banking, internet banking, and supported wallet methods.', 'fluent-cart'),
            'features'     => [
                __('Hosted checkout and modal checkout', 'fluent-cart'),
                __('Cards, mobile banking, and internet banking', 'fluent-cart'),
                __('IPN payment verification', 'fluent-cart'),
                __('Refund support', 'fluent-cart'),
            ],
            'icon_path'    => 'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm5 7.5h-1.8c-.3-.9-.9-1.7-1.7-2.2V5.5c1.6.5 2.9 2 3.5 4zm-5-2.3c1.5 0 2.8 1 3.2 2.3H8.8c.4-1.3 1.7-2.3 3.2-2.3zM7 9.5c.6-2 2.2-3.5 4-4v1.8c-.8.5-1.4 1.3-1.7 2.2H7zm-2.7 4c-.1-.5-.3-1-.3-1.5s.1-1 .3-1.5h15.4c.1.5.3 1 .3 1.5s-.1 1-.3 1.5H4.3zM7 14.5h2.3c.3.9.9 1.7 1.7 2.2v1.8c-1.8-.5-3.4-2-4-4zm5 2.3c-1.5 0-2.8-1-3.2-2.3h6.4c-.4 1.3-1.7 2.3-3.2 2.3zm1.5 1.7v-1.8c.8-.5 1.4-1.3 1.7-2.2H17c-.6 2-1.9 3.5-3.5 4z',
            'addon_slug'   => $this->addonSlug,
            'addon_file'   => $this->addonFile,
            'repo_link'    => 'https://fluentcart.com/fluentcart-addons/',
            'addon_source' => $meta['addon_source'] ?? [],
            'footer_text'  => __('Download manually with FluentCart free, or install automatically with FluentCart Pro.', 'fluent-cart'),
        ];

        if ($this->settings instanceof AddonGatewaySettings) {
            return $this->settings->generateAddonNotice($config);
        }

        return null;
    }

    public function fields()
    {
        return [
            'notice' => [
                'value' => $this->addonNoticeMessage(),
                'label' => __('SSLCommerz Payment Gateway', 'fluent-cart'),
                'type'  => 'html_attr',
            ],
        ];
    }
}

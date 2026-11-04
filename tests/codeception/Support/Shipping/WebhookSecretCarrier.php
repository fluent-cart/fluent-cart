<?php

namespace Tests\Support\Shipping;

use FluentCart\App\Modules\Shipping\Carriers\Core\AbstractShippingCarrier;
use FluentCart\App\Modules\Shipping\Carriers\Core\BaseCarrierSettings;
use FluentCart\App\Modules\Shipping\Carriers\Core\CarrierManager;

/**
 * A carrier shaped like Shippo: its settings live in the database and its first
 * save issues the webhook secret that its webhook URL embeds.
 */
class WebhookSecretCarrier extends AbstractShippingCarrier
{
    public const SLUG = 'webhook_secret_stub';

    public static function install(): void
    {
        if (!CarrierManager::carrier(self::SLUG)) {
            CarrierManager::getInstance()->register(self::SLUG, new self(new WebhookSecretCarrierSettings()));
        }
    }

    public function meta(): array
    {
        return [
            'slug'     => self::SLUG,
            'name'     => 'Webhook Secret Stub',
            'supports' => ['labels'],
        ];
    }

    public function fields(): array
    {
        return [
            ['key' => 'is_active', 'type' => 'checkbox', 'label' => 'Enable'],
            ['key' => 'api_token', 'type' => 'password', 'label' => 'API Token'],
        ];
    }

    public function validateSettings(array $settings)
    {
        if ((string) $this->settings->get('webhook_secret') === '') {
            $settings['webhook_secret'] = wp_generate_password(40, false);
        }

        return $settings;
    }

    public function getWebhookUrl(): string
    {
        $secret = (string) $this->settings->get('webhook_secret');
        if ($secret === '') {
            return '';
        }

        return add_query_arg([
            'fluent-cart' => 'fct_shipping_listener_webhook',
            'carrier'     => self::SLUG,
            'fcs_token'   => $secret,
        ], home_url('/'));
    }
}

class WebhookSecretCarrierSettings extends BaseCarrierSettings
{
    protected string $metaKey = 'fluent_cart_carrier_settings_webhook_secret_stub';

    public function getDefaults(): array
    {
        return [
            'is_active'      => 'no',
            'api_token'      => '',
            'webhook_secret' => '',
        ];
    }
}

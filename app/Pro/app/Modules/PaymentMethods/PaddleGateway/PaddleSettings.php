<?php

namespace FluentCartPro\App\Modules\PaymentMethods\PaddleGateway;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\Framework\Support\Arr;

class PaddleSettings extends BaseGatewaySettings
{
    public $settings;
    public $methodHandler = 'fluent_cart_payment_settings_paddle';

    public function __construct()
    {
        parent::__construct();
        $settings = $this->getCachedSettings();
        $defaults = static::getDefaults();

        if (!$settings || !is_array($settings)) {
            $settings = $defaults;
        } else {
            $settings = wp_parse_args($settings, $defaults);
        }

        if (is_array($settings)) {
            $settings = Arr::mergeMissingValues($settings, $defaults);
        }

        $this->settings = $settings;
    }

    public static function getDefaults()
    {
        return [
            'is_active' => 'no',
            'provider' => 'api_keys',
            // API Credentials
            'payment_mode'        => 'live',
            'test_api_key' => '',
            'live_api_key' => '',
            'test_client_token' => '',
            'live_client_token' => '',
            'test_is_encrypted' => 'no',
            'live_is_encrypted' => 'no',
            'tax_mode' => 'account_setting',
            'paddle_checkout_button_text' => __('Pay with Paddle', 'fluent-cart-pro'),
            'paddle_checkout_button_color' => '',
            'paddle_checkout_button_hover_color' => '',
            'paddle_checkout_button_text_color' => '',
            'paddle_checkout_button_font_size' => '',
            'paddle_checkout_theme' => 'light',

            // Webhook Configuration
            'test_notification_id' => '',
            'live_notification_id' => '',
            'test_webhook_secret' => '',
            'live_webhook_secret' => '',
            'webhook_events' => [
                'transaction.paid',
                'transaction.completed',
                'transaction.paid',
                'transaction.payment_failed',
                'subscription.created',
                'subscription.updated',
                'subscription.canceled',
                'subscription.paused',
                'subscription.resumed',
                'subscription.past_due',
                'adjustment.created',
                'adjustment.updated'
            ],

            // Additional Settings
            'checkout_mode' => 'overlay',
            'disable_webhook_verification' => 'no',
        ];
    }

    public function isActive(): bool
    {
        return $this->settings['is_active'] == 'yes';
    }

    public function get($key = '')
    {
        if ($key && isset($this->settings[$key])) {
            return $this->settings[$key];
        }
        return $this->settings;
    }

    public function getMode()
    {
        // return store mode
        return (new StoreSettings)->get('order_mode');
    }

    public function getApiKey($mode = '')
    {
        if (!$mode) {
            $mode = $this->getMode();
        }

        $keyField = $mode . '_api_key';

        $apiKey = $this->get($keyField);

        return Helper::decryptKey($apiKey);
    }

    public function getClientToken($mode = '')
    {
        if (!$mode) {
            $mode = $this->getMode();
        }

        return $this->get($mode . '_client_token');
    }

    public function getWebhookSecret($mode = '')
    {
        if (!$mode) {
            $mode = $this->getMode();
        }

        return $this->get($mode . '_webhook_secret');
    }

    public function getWebhookEvents()
    {
        return $this->get('webhook_events');
    }


    public function isWebhookVerificationDisabled()
    {
        return $this->get('disable_webhook_verification') === 'yes';
    }

    public function getApiBaseUrl($mode = '')
    {
        if (!$mode) {
            $mode = $this->getMode();
        }

        return $mode === 'test'
            ? 'https://sandbox-api.paddle.com'
            : 'https://api.paddle.com';
    }

    public function getCheckoutUrl($mode = '')
    {
        if (!$mode) {
            $mode = $this->getMode();
        }

        return $mode === 'test'
            ? 'https://sandbox-checkout.paddle.com'
            : 'https://checkout.paddle.com';
    }

    // subscribe to webhook events
    public function getWebhookEventsToSubscribe()
    {
        $events = $this->get('webhook_events');
        return array_unique($events);
    }

    public function getCachedSettings()
    {
        return Arr::get(self::$allSettings, $this->methodHandler);
    }


}

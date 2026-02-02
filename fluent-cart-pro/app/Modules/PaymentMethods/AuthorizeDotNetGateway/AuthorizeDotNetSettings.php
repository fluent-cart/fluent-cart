<?php

namespace FluentCartPro\App\Modules\PaymentMethods\AuthorizeDotNetGateway;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\Framework\Support\Arr;

class AuthorizeDotNetSettings extends BaseGatewaySettings
{
    public $methodHandler = 'fluent_cart_payment_settings_authorize_dot_net';

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

        $this->settings = $settings;
    }

    public static function getDefaults(): array
    {
        return [
            'is_active'              => 'no',
            'payment_mode'           => 'test',
            'enable_echeck'          => 'yes',
            'debug_log'              => 'no',
            'test_api_login_id'      => '',
            'test_transaction_key'   => '',
            'test_client_key'        => '',
            'test_signature_key'     => '',
            'live_api_login_id'      => '',
            'live_transaction_key'   => '',
            'live_client_key'        => '',
            'live_signature_key'     => '',
        ];
    }

    public function isActive(): bool
    {
        return $this->settings['is_active'] === 'yes';
    }

    public function get($key = '')
    {
        if ($key) {
            return Arr::get($this->settings, $key);
        }

        return $this->settings;
    }

    public function getMode(): string
    {
        return (new StoreSettings())->get('order_mode', $this->settings['payment_mode']);
    }

    public function getConfiguredMode(): string
    {
        return Arr::get($this->settings, 'payment_mode', 'test');
    }

    public function getApiLoginId(?string $mode = null): string
    {
        $mode = empty($mode) || $mode == 'current' ? $this->getMode() : $mode;
        $field = $mode === 'test' ? 'test_api_login_id' : 'live_api_login_id';
        
        return (string) Arr::get($this->settings, $field, '');
    }

    public function getTransactionKey(?string $mode = null): string
    {
        $mode = empty($mode) || $mode == 'current' ? $this->getMode() : $mode;

        $field = $mode === 'test' ? 'test_transaction_key' : 'live_transaction_key';
        $encrypted = Arr::get($this->settings, $field, '');
        return $encrypted ? (string) Helper::decryptKey($encrypted) : '';
    }

    public function getClientKey(?string $mode = null): string
    {
        $mode = empty($mode) || $mode == 'current' ? $this->getMode() : $mode;
        $field = $mode === 'test' ? 'test_client_key' : 'live_client_key';
        $value = Arr::get($this->settings, $field, '');
        return $value;
    }

    public function getSignatureKey(?string $mode = null): string
    {
        $mode = empty($mode) || $mode == 'current' ? $this->getMode() : $mode;
        $field = $mode === 'test' ? 'test_signature_key' : 'live_signature_key';
        $value = Arr::get($this->settings, $field, '');
        return $value;
    }

    public function isECheckEnabled(): bool
    {
        return Arr::get($this->settings, 'enable_echeck') === 'yes';
    }

    public function shouldLog(): bool
    {
        return Arr::get($this->settings, 'debug_log') === 'yes';
    }

    public static function beforeSave(array $data): array
    {
        foreach (['test_transaction_key', 'live_transaction_key'] as $field) {
            if (!empty($data[$field])) {
                $data[$field] = Helper::encryptKey($data[$field]);
            }
        }

        return $data;
    }

    public function getWebhookSignatureKey(): string
    {
        $mode = empty($mode) || $mode == 'current' ? $this->getMode() : $mode;
        $field = $mode === 'test' ? 'test_signature_key' : 'live_signature_key';
        $value = Arr::get($this->settings, $field, '');
        return $value;
    }
}

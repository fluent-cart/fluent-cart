<?php

namespace FluentCart\App\Modules\StorageDrivers\S3;

use FluentCart\App\Helpers\Helper;
use FluentCart\Framework\Support\Arr;

class S3Settings
{
    protected const MISSING_DEFINE_CREDENTIALS_MESSAGE = 'S3 was configured previously, but the wp-config.php credentials are missing now. Add them again to continue.';

    protected $settings;

    protected $driverHandler = 'fluent_cart_storage_settings_s3';

    static ?array $cachedSettings = null;

    public bool $isUsingDefineMode = false;

    public function __construct()
    {
        $this->isUsingDefineMode = static::hasDefinedCredentials();

        if (self::$cachedSettings) {
            $this->settings = self::$cachedSettings;
            return;
        }

        $settings = fluent_cart_get_option($this->driverHandler, []);
        $hasStoredAuthMethod = !empty(Arr::get($settings, 'auth_method'));

        $this->settings = wp_parse_args($settings, static::getDefaults());

        if (!$hasStoredAuthMethod && $this->isUsingDefineMode) {
            $this->settings['auth_method'] = 'define';
        }

        $this->settings['defined_in_wp_config'] = $this->isUsingDefineMode;
        $this->applyDefineCredentialsState();

        self::$cachedSettings = $this->settings;
    }

    public static function getDefaults()
    {
        return [
            'is_active'                          => 'no',
            'auth_method'                        => 'db',
            'secret_key'                         => '',
            'access_key'                         => '',
            'bucket'                             => '',
            'region'                             => 'us-east-1',
            'block_public_access'                => 'yes',
            'object_ownership'                   => 'yes',
            'define_credentials_missing'         => false,
            'define_credentials_missing_message' => '',
        ];
    }

    public function get($key = '')
    {
        $settings = $this->settings;
        $authMethod = Arr::get($settings, 'auth_method', 'db');

        if ($authMethod === 'define') {
            $definedCredentials = static::getDefinedCredentials();

            if ($definedCredentials) {
                $settings['access_key'] = Arr::get($definedCredentials, 'access_key', '');
                $settings['secret_key'] = Arr::get($definedCredentials, 'secret_key', '');
                $settings['provider'] = Arr::get($definedCredentials, 'provider', 'aws');
            }
        } else {
            if (!empty($settings['access_key'])) {
                $settings['access_key'] = Helper::decryptKey($settings['access_key']) ?: $settings['access_key'];
            }

            if (!empty($settings['secret_key'])) {
                $settings['secret_key'] = Helper::decryptKey($settings['secret_key']) ?: $settings['secret_key'];
            }
        }

        if ($key) {
            return Arr::get($settings, $key);
        }

        return $settings;
    }

    public static function resolveConfiguredBucket(array $settings): string
    {
        return trim((string) Arr::get($settings, 'bucket', ''));
    }

    public static function resolveEffectiveBucket(array $settings): string
    {
        $bucket = static::resolveConfiguredBucket($settings);
        if ($bucket !== '') {
            return $bucket;
        }

        $legacyBuckets = Arr::get($settings, 'buckets', []);
        if (!is_array($legacyBuckets)) {
            return '';
        }

        foreach ($legacyBuckets as $legacyBucket) {
            $legacyBucket = trim((string) $legacyBucket);
            if ($legacyBucket !== '') {
                return $legacyBucket;
            }
        }

        return '';
    }

    protected function applyDefineCredentialsState(): void
    {
        $isDefineWithoutCredentials = Arr::get($this->settings, 'auth_method') === 'define' && !$this->isUsingDefineMode;
        $hadPreviousDefineContext = !empty(Arr::get($this->settings, 'bucket')) || Arr::get($this->settings, 'is_active') === 'yes';

        $this->settings['define_credentials_missing'] = $isDefineWithoutCredentials && $hadPreviousDefineContext;
        $this->settings['define_credentials_missing_message'] = $this->settings['define_credentials_missing']
            ? __(self::MISSING_DEFINE_CREDENTIALS_MESSAGE, 'fluent-cart')
            : '';

        if ($this->settings['define_credentials_missing']) {
            $this->settings['is_active'] = 'no';
        }
    }

    public function isActive()
    {
        $settings = $this->get();

        $isActive = Arr::get($settings, 'is_active') === 'yes';

        if ($isActive) {
            if ($this->getAuthMethod() === 'define') {
                return static::hasDefinedCredentials();
            }

            $requiredKeys = ['secret_key', 'access_key', 'region'];

            return $this->hasRequiredKeys($settings, $requiredKeys);
        }

        return false;
    }

    public function getAuthMethod()
    {
        return Arr::get($this->settings, 'auth_method', 'db');
    }

    private function hasRequiredKeys(array $settings, array $requiredKeys): bool
    {
        foreach ($requiredKeys as $key) {
            if (empty(Arr::get($settings, $key))) {
                return false;
            }
        }

        return true;
    }

    public static function hasDefinedCredentials(): bool
    {
        return !empty(static::getDefinedCredentials());
    }

    public static function getDefinedCredentials(): array
    {
        if (defined('FCT_S3_ACCESS_KEY') && defined('FCT_S3_SECRET_KEY')) {
            return [
                'access_key' => FCT_S3_ACCESS_KEY,
                'secret_key' => FCT_S3_SECRET_KEY,
                'provider'   => 'aws'
            ];
        }

        if (defined('FLUENT_CART_AWS_ACCESS_KEY_ID') && defined('FLUENT_CART_AWS_SECRET_ACCESS_KEY')) {
            return [
                'access_key' => FLUENT_CART_AWS_ACCESS_KEY_ID,
                'secret_key' => FLUENT_CART_AWS_SECRET_ACCESS_KEY,
                'provider'   => 'aws'
            ];
        }

        if (defined('AWS_ACCESS_KEY_ID') && defined('AWS_SECRET_ACCESS_KEY')) {
            return [
                'access_key' => AWS_ACCESS_KEY_ID,
                'secret_key' => AWS_SECRET_ACCESS_KEY,
                'provider'   => 'aws'
            ];
        }

        return [];
    }
}

<?php

namespace FluentCart\App\Modules\StorageDrivers\S3;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Services\FileSystem\Drivers\S3\S3ConnectionVerify;
use FluentCart\App\Services\FileSystem\Drivers\S3\S3Driver;
use FluentCart\App\Services\FileSystem\Drivers\S3\S3InputValidator;
use FluentCart\App\Vite;
use FluentCart\App\Modules\StorageDrivers\BaseStorageDriver;
use FluentCart\Framework\Support\Arr;

class S3 extends BaseStorageDriver
{
    /**
     * title, slug, brandColor
     */
    public function __construct()
    {
        parent::__construct(
            __('S3', 'fluent-cart'),
            's3',
            '#4f94d4'
        );
    }

    public function registerHooks()
    {
        add_filter('fluent_cart/verify_driver_connect_info_' . $this->slug, [$this, 'verifyConnectInfo'], 10, 2);
        add_filter('fluent_cart/get_dynamic_search_s3_bucket_list', [$this, 'getBucketList'], 10, 2);
    }

    public function getBucketList($buckets = [], $args = []): array
    {
        $driverSettings = Arr::get($args, 'driver_settings', []);
        $query = strtolower(trim((string) Arr::get($args, 'query', '')));
        $resolvedCredentials = $this->resolveCredentials($driverSettings);

        if (empty($resolvedCredentials['access_key']) || empty($resolvedCredentials['secret_key'])) {
            return [];
        }

        $bucketList = \FluentCart\App\Services\FileSystem\Drivers\S3\S3BucketList::get(
            $resolvedCredentials['secret_key'],
            $resolvedCredentials['access_key'],
            'us-east-1',
            $resolvedCredentials['session_token']
        );

        if (is_wp_error($bucketList)) {
            return [];
        }

        if ($query !== '') {
            $bucketList = array_values(array_filter($bucketList, function ($bucket) use ($query) {
                return strpos(strtolower((string) $bucket), $query) !== false;
            }));
        }

        $bucketList = array_slice($bucketList, 0, 50);

        $buckets = [];
        foreach ($bucketList as $bucket) {
            $buckets[] = array(
                "label" => $bucket,
                "value" => $bucket,
            );
        }
        return $buckets;
    }

    public function getLogo(): string
    {
        return Vite::getAssetUrl("images/storage-drivers/s3.svg");
    }

    public function getDarkLogo()
    {
        return Vite::getAssetUrl("images/storage-drivers/s3-dark.svg");
    }

    public function hasBucket(): bool
    {
        return true;
    }

    public function needsReconfigure(): bool
    {
        return S3Settings::resolveConfiguredBucket($this->getSettings()) === '';
    }


    public function getDescription()
    {
        return esc_html__('S3 bucket allows to configure storage options and others for efficient and secure cloud-based file storage', 'fluent-cart');
    }

    public function isEnabled(): bool
    {
        $settings = $this->getSettings();
        $isActive = Arr::get($settings, 'is_active') === 'yes';
        $hasSelectedBucket = S3Settings::resolveEffectiveBucket($settings) !== '';
        return $isActive && $hasSelectedBucket;
    }

    public function getSettings()
    {
        return (new S3Settings())->get();
    }

    public function getSettingsTemplate(): ?string
    {
        $templatePath = dirname(__DIR__, 4) . '/app/Modules/StorageDrivers/S3/VueTemplates/SettingsPage.vue';

        if (!file_exists($templatePath)) {
            return null;
        }

        $template = file_get_contents($templatePath);

        return $template ?: null;
    }

    public function getSettingsTemplatePayload(array $response = []): array
    {
        return array_merge(parent::getSettingsTemplatePayload($response), [
            'connection_verify_endpoint' => 'settings/storage-drivers/verify-info',
            'create_bucket_endpoint'     => 'settings/storage-drivers/create-bucket',
            'bucket_list_endpoint'       => 'settings/storage-drivers/bucket-list',
            'save_endpoint'              => 'settings/storage-drivers',
            'reset_endpoint'             => 'settings/storage-drivers/reset',
            'supports_template_mode'     => true,
        ]);
    }

    public function listBuckets(array $data, array $args = [])
    {
        $credentialError = $this->requireCredentials($data);
        if ($credentialError) {
            return $credentialError;
        }

        return $this->getBucketList([], [
            'driver_settings' => $data,
            'query'           => Arr::get($args, 'query')
        ]);
    }

    public function createBucket(array $data)
    {
        $credentialError = $this->requireCredentials($data);
        if ($credentialError) {
            return $credentialError;
        }

        $resolvedCredentials = $this->resolveCredentials($data);

        if (empty($resolvedCredentials['access_key']) || empty($resolvedCredentials['secret_key'])) {
            return new \WP_Error('invalid_credentials', __('Invalid credentials for bucket creation', 'fluent-cart'));
        }

        $newBucket = sanitize_text_field(Arr::get($data, 'new_bucket_name'));
        $newBucket = strtolower($newBucket);
        $newBucket = preg_replace('/[^a-z0-9\.-]/', '-', $newBucket);
        $newBucket = preg_replace('/-+/', '-', $newBucket);
        $newBucket = trim($newBucket, '-.');

        $newRegion = sanitize_text_field(Arr::get($data, 'new_bucket_region', 'us-east-1')) ?: 'us-east-1';

        if (!$newBucket) {
            return new \WP_Error('invalid_bucket', __('Bucket name is required', 'fluent-cart'));
        }

        $validation = S3InputValidator::validateBucketAndRegion($newBucket, $newRegion);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $creation = \FluentCart\App\Services\FileSystem\Drivers\S3\S3BucketCreator::create(
            $resolvedCredentials['secret_key'],
            $resolvedCredentials['access_key'],
            $newBucket,
            $newRegion,
            $resolvedCredentials['session_token']
        );

        if (is_wp_error($creation)) {
            return $creation;
        }

        $bucketCheck = S3ConnectionVerify::checkBucketExistence(
            $resolvedCredentials['secret_key'],
            $resolvedCredentials['access_key'],
            $resolvedCredentials['session_token'],
            $newBucket,
            $newRegion
        );

        if (is_wp_error($bucketCheck)) {
            return $bucketCheck;
        }

        $region = Arr::get($bucketCheck, 'region', $newRegion);

        $securitySettings = S3ConnectionVerify::checkSecuritySettings(
            $resolvedCredentials['secret_key'],
            $resolvedCredentials['access_key'],
            $resolvedCredentials['session_token'],
            $newBucket,
            $region
        );

        return [
            'message'             => __('Bucket created successfully', 'fluent-cart'),
            'bucket'              => $newBucket,
            'region'              => $region,
            'connection_error'    => '',
            'block_public_access' => !empty($securitySettings['block_public_access']) ? 'yes' : 'no',
            'object_ownership'    => !empty($securitySettings['object_ownership']) ? 'yes' : 'no'
        ];
    }

    public function updateSettings($data)
    {
        $settings = $this->getSettings();

        if (!array_key_exists('auth_method', $data) && isset($settings['auth_method'])) {
            $data['auth_method'] = $settings['auth_method'];
        }

        if (!array_key_exists('buckets', $data) && isset($settings['buckets'])) {
            $data['buckets'] = $settings['buckets'];
        }

        $mergeKeys = array_diff($this->hiddenSettingKeys(), ['create_new_bucket', 'new_bucket_name', 'new_bucket_region']);
        foreach ($mergeKeys as $key) {
            if (!array_key_exists($key, $data) && isset($settings[$key])) {
                $data[$key] = $settings[$key];
            }
        }

        if (
            Arr::get($data, 'is_active') === 'no' &&
            !Arr::get($data, 'verify_only_credentials') &&
            !Arr::get($data, 'check_bucket_exists')
        ) {
            $data['is_active'] = 'no';

            if (!Arr::get($data, 'preserve_settings')) {
                $resetSettings = $this->resetSettings();

                return [
                    'data'         => Arr::except($resetSettings, $this->hiddenSettingKeys()),
                    'message'      => __('S3 settings have been reset successfully', 'fluent-cart'),
                    'shouldReload' => false
                ];
            }

            if (!empty($data['bucket'])) {
                $data['show_buckets'] = 'yes';
            }

            parent::updateSettings($this->prepareSettingsForStorage($data));

            return [
                'data'         => Arr::except($data, $this->hiddenSettingKeys()),
                'message'      => __('S3 is deactivated successfully', 'fluent-cart'),
                'shouldReload' => false
            ];
        }

        $credentialError = $this->requireCredentials($data);
        if ($credentialError) {
            return $credentialError;
        }

        $resolvedCredentials = $this->resolveCredentials($data);
        $userBlockPublicAccess = Arr::get($data, 'block_public_access');
        $userObjectOwnership = Arr::get($data, 'object_ownership');
        $verificationResult = true;

        if (Arr::get($data, 'verify_only_credentials')) {
            $verificationResult = $this->verifyConnectInfo($data);
            if (is_wp_error($verificationResult)) {
                return $verificationResult;
            }
        }

        if (!empty($data['bucket'])) {
            $bucketValidation = S3InputValidator::validateBucket($data['bucket']);
            if (is_wp_error($bucketValidation)) {
                return $bucketValidation;
            }

            if (!empty($data['region'])) {
                $regionValidation = S3InputValidator::validateRegion($data['region']);
                if (is_wp_error($regionValidation)) {
                    return $regionValidation;
                }
            }

            $region = Arr::get($data, 'region') ?: self::getBucketRegion($data['bucket']) ?: 'us-east-1';

            $bucketCheck = S3ConnectionVerify::checkBucketExistence(
                $resolvedCredentials['secret_key'],
                $resolvedCredentials['access_key'],
                $resolvedCredentials['session_token'],
                $data['bucket'],
                $region
            );

            if (is_wp_error($bucketCheck)) {
                $errorSettings = $settings;
                $errorSettings['connection_error'] = $bucketCheck->get_error_message();

                parent::updateSettings($this->prepareSettingsForStorage($errorSettings));

                return $bucketCheck;
            }

            $data['connection_error'] = '';

            if (isset($bucketCheck['region']) && $bucketCheck['region'] !== Arr::get($data, 'region')) {
                $data['region'] = $bucketCheck['region'];
            }

            $securitySettings = S3ConnectionVerify::checkSecuritySettings(
                $resolvedCredentials['secret_key'],
                $resolvedCredentials['access_key'],
                $resolvedCredentials['session_token'],
                $data['bucket'],
                Arr::get($data, 'region') ?: $region
            );

            $bucketCheck['block_public_access'] = Arr::get($securitySettings, 'block_public_access');
            $bucketCheck['object_ownership'] = Arr::get($securitySettings, 'object_ownership');
            $verificationResult = $bucketCheck;
        }

        if (is_array($verificationResult) && array_key_exists('block_public_access', $verificationResult) && $userBlockPublicAccess === null) {
            $data['block_public_access'] = $verificationResult['block_public_access'] ? 'yes' : 'no';
        }

        if (is_array($verificationResult) && array_key_exists('object_ownership', $verificationResult) && $userObjectOwnership === null) {
            $data['object_ownership'] = $verificationResult['object_ownership'] ? 'yes' : 'no';
        }

        if (
            !empty($data['bucket']) &&
            Arr::get($data, 'is_active') === 'yes' &&
            !Arr::get($data, 'verify_only_credentials') &&
            !is_wp_error($verificationResult)
        ) {
            $bucketRegion = Arr::get($data, 'region') ?: self::getBucketRegion($data['bucket']) ?: 'us-east-1';

            if ($userBlockPublicAccess !== null) {
                $enableBlock = $this->isTrue($userBlockPublicAccess);
                $syncResult = S3ConnectionVerify::updatePublicAccessBlock(
                    $resolvedCredentials['secret_key'],
                    $resolvedCredentials['access_key'],
                    $resolvedCredentials['session_token'],
                    $data['bucket'],
                    $enableBlock,
                    $bucketRegion
                );

                if (is_wp_error($syncResult)) {
                    return $syncResult;
                }

                $data['block_public_access'] = $enableBlock ? 'yes' : 'no';
            }

            if ($userObjectOwnership !== null) {
                $enableOwnership = $this->isTrue($userObjectOwnership);
                $syncResult = S3ConnectionVerify::updateObjectOwnership(
                    $resolvedCredentials['secret_key'],
                    $resolvedCredentials['access_key'],
                    $resolvedCredentials['session_token'],
                    $data['bucket'],
                    $enableOwnership,
                    $bucketRegion
                );

                if (is_wp_error($syncResult)) {
                    return $syncResult;
                }

                $data['object_ownership'] = $enableOwnership ? 'yes' : 'no';
            }
        }

        if (Arr::get($data, 'verify_only_credentials') || !empty($data['bucket']) || Arr::get($data, 'is_active') === 'yes') {
            $data['show_buckets'] = 'yes';
        }

        if (Arr::get($data, 'is_active') === 'yes') {
            $data['is_active'] = 'yes';
            $data['connection_error'] = '';
        }

        parent::updateSettings($this->prepareSettingsForStorage($data));

        $shouldReload = Arr::get($data, 'is_active') === 'yes';

        if (empty($message)) {
            if (Arr::get($data, 'is_active') === 'yes') {
                $message = __('Your s3 storage is activated successfully', 'fluent-cart');
            } else {
                $message = __('Settings saved successfully.', 'fluent-cart');
            }
        }


        return [
            'data'         => Arr::except($data, $this->hiddenSettingKeys()),
            'message'      => $message,
            'shouldReload' => $shouldReload
        ];
    }

    private function requireCredentials(array $data)
    {
        $settings  = $this->getSettings();
        $authMethod = Arr::get($data, 'auth_method', Arr::get($settings, 'auth_method', 'db'));

        if ($authMethod === 'define' && !S3Settings::hasDefinedCredentials()) {
            return new \WP_Error(
                'missing_credentials',
                __('S3 credentials are not defined in wp-config.php. Please add them and try again.', 'fluent-cart')
            );
        }

        return null;
    }

    private function resolveCredentials(array $data): array
    {
        $settings = $this->getSettings();
        $authMethod = Arr::get($data, 'auth_method', Arr::get($settings, 'auth_method', 'db'));

        if ($authMethod === 'define') {
            $definedCredentials = S3Settings::getDefinedCredentials();

            if (!empty($definedCredentials)) {
                return [
                    'access_key' => Arr::get($definedCredentials, 'access_key', ''),
                    'secret_key' => Arr::get($definedCredentials, 'secret_key', ''),
                    'session_token' => null
                ];
            }
        }

        $accessKey = Arr::get($data, 'access_key');
        if (empty($accessKey)) {
            $accessKey = Arr::get($settings, 'access_key');
        }

        $secretKey = Arr::get($data, 'secret_key');
        if (empty($secretKey)) {
            $secretKey = Arr::get($settings, 'secret_key');
        }

        return [
            'access_key' => $accessKey,
            'secret_key' => $secretKey,
            'session_token' => null
        ];
    }

    public function resetSettings()
    {
        fluent_cart_update_option($this->driverHandler, []);
        S3Settings::$cachedSettings = null;

        return $this->getSettings();
    }

    private function prepareSettingsForStorage(array $settings): array
    {
        if (Arr::get($settings, 'auth_method') === 'define') {
            unset($settings['access_key'], $settings['secret_key'], $settings['session_token']);
        } else {
            if (!empty($settings['access_key'])) {
                $settings['access_key'] = Helper::encryptKey($settings['access_key']);
            }

            if (!empty($settings['secret_key'])) {
                $settings['secret_key'] = Helper::encryptKey($settings['secret_key']);
            }
        }

        unset($settings['create_new_bucket'], $settings['new_bucket_name'], $settings['new_bucket_region']);
        unset($settings['verify_credentials'], $settings['verify_only_credentials'], $settings['preserve_settings'], $settings['check_bucket_exists']);

        return $settings;
    }

    /**
     * Verify Connect configuration
     */
    public function verifyConnectInfo(array $data, $args = [])
    {
        $credentialError = $this->requireCredentials($data);
        if ($credentialError) {
            return $credentialError;
        }

        $resolvedCredentials = $this->resolveCredentials($data);
        $accessKey = Arr::get($resolvedCredentials, 'access_key');
        $secretKey = Arr::get($resolvedCredentials, 'secret_key');
        $sessionToken = Arr::get($resolvedCredentials, 'session_token');

        if (empty($secretKey) || empty($accessKey)) {
            return new \WP_Error('invalid_credentials', __('Invalid credentials', 'fluent-cart'));
        }

        $verifyOnlyCredentials = (bool) Arr::get($data, 'verify_only_credentials');
        $bucket = '';
        $region = 'us-east-1';

        if (!$verifyOnlyCredentials) {
            $bucket = Arr::get($data, 'bucket');

            if ($bucket) {
                $bucketValidation = S3InputValidator::validateBucket($bucket);
                if (is_wp_error($bucketValidation)) {
                    return $bucketValidation;
                }

                $region = Arr::get($data, 'region');

                if ($region) {
                    $regionValidation = S3InputValidator::validateRegion($region);
                    if (is_wp_error($regionValidation)) {
                        return $regionValidation;
                    }
                }

                if (!$region) {
                    $region = self::getBucketRegion($bucket);
                }
            }
        }

        if (!$region && $bucket) {
            return new \WP_Error('missing_region', __('Region information is missing for the selected bucket.', 'fluent-cart'));
        }

        $result = S3ConnectionVerify::verify(
            $secretKey,
            $accessKey,
            $sessionToken,
            $bucket,
            $region ?: 'us-east-1'
        );

        if (!is_wp_error($result)) {
            $result['is_active'] = 'yes';
        }

        return $result;
    }

    public function fields(): array
    {
        $settings = $this->getSettings();
        $showBucket = Arr::get($settings, 'show_buckets') === 'yes';
        $usingDefineMode = Arr::get($settings, 'auth_method') === 'define';

        $schema = [
            'is_active'  => [
                'value'      => '',
                'label'      => __('Enable s3 driver', 'fluent-cart'),
                'type'       => 'checkbox',
                'attributes' => [
                    'disabled' => !$showBucket
                ],
            ],
            'access_key' => [
                'conditions'  => [
                    [
                        'key'      => 'is_active',
                        'operator' => '==',
                        'value'    => 'yes'
                    ],
                ],
                'value'       => '',
                'label'       => __('Access Key', 'fluent-cart'),
                'type'        => 'text',
                'placeholder' => __('Enter access key', 'fluent-cart')
            ],
            'secret_key' => [
                'conditions'  => [
                    [
                        'key'      => 'is_active',
                        'operator' => '==',
                        'value'    => 'yes'
                    ],
                ],
                'value'       => '',
                'label'       => __('Secret Key', 'fluent-cart'),
                'type'        => 'text',
                'attributes'  => [
                    'type' => 'password'
                ],
                'placeholder' => __('Enter secret key', 'fluent-cart')
            ],
        ];


        if ($showBucket) {
            $bucketList = Arr::get($settings, 'buckets', []);
            $buckets = [];

            foreach ($bucketList as $bucket) {
                $buckets[] = array(
                    "label" => $bucket,
                    "value" => $bucket,
                );
            }

            $schema['buckets'] = [
                'conditions'  => [
                    [
                        'key'      => 'is_active',
                        'operator' => '==',
                        'value'    => 'yes'
                    ],
                ],
                'value'               => '',
                'label'               => __('Buckets', 'fluent-cart'),
                'type'                => 'remote_select',
                'remote_key'          => 's3_bucket_list',
                'multiple'            => true,
                'options'             => [],
                'placeholder'         => __('Select bucket', 'fluent-cart'),
                'search_only_on_type' => false,
            ];
        }

        if ($usingDefineMode) {
            unset($schema['access_key'], $schema['secret_key']);
            $schema['is_using_define_mode'] = [
                'value' => Arr::get($settings, 'define_credentials_missing')
                    ? Arr::get($settings, 'define_credentials_missing_message')
                    : __('Using Define Mode', 'fluent-cart'),
                'type'  => 'html'
            ];
        }

        return [
            'view' => [
                'title'           => __('S3 Settings', 'fluent-cart'),
                'type'            => 'section',
                'disable_nesting' => true,
                'columns'         => [
                    'default' => 1,
                    'md'      => 1
                ],
                'schema'          => $schema
            ]
        ];
    }

    public function getDriverClass(): string
    {
        return S3Driver::class;
    }

    public function hiddenSettingKeys(): array
    {
        return [
            'show_buckets',
            'access_key',
            'secret_key',
            'session_token',
            'create_new_bucket',
            'new_bucket_name',
            'new_bucket_region',
            'verify_credentials',
            'verify_only_credentials',
            'preserve_settings',
            'check_bucket_exists',
        ];
    }

    public static function getBucketRegion($bucket)
    {
        if (!S3InputValidator::isValidBucket($bucket)) {
            return null;
        }

        $cacheKey = 'fct_s3_region';
        $existingMeta = \FluentCart\App\Models\Meta::query()->where('meta_key', $cacheKey)->first();


        if ($existingMeta) {
            $region = Arr::get($existingMeta->meta_value, $bucket);
            if ($region) {
                return $region;
            }
        }

        //$url = "https://{$bucket}.s3.amazonaws.com"; // The global endpoint
        if (strpos($bucket, '.') !== false) {
            // If bucket contains dot, use path-style (required for SSL)
            $url = "https://s3.amazonaws.com/{$bucket}";
        } else {
            // Normal bucket: use virtual-hosted style
            $url = "https://{$bucket}.s3.amazonaws.com";
        }


        $response = wp_remote_head($url);

        if (is_wp_error($response)) {
            return null;
        }

        $headers = wp_remote_retrieve_headers($response);

        $region = Arr::get($headers, 'x-amz-bucket-region') ?? 'us-east-1';
        if (!S3InputValidator::isValidRegion($region)) {
            $region = 'us-east-1';
        }

        // 5. Save or update the Meta record
        if ($existingMeta) {
            $values = $existingMeta->meta_value;
            $values[$bucket] = $region;
            $existingMeta->meta_value = $values;
            $existingMeta->save();
        } else {
            \FluentCart\App\Models\Meta::query()->create([
                'meta_key'   => $cacheKey,
                'meta_value' => [
                    $bucket => $region
                ],
            ]);
        }

        return $region;
    }

    private function isTrue($value)
    {
        if (is_string($value)) {
            $value = strtolower($value);

            return in_array($value, ['yes', 'true', '1', 'on'], true);
        }

        return (bool) $value;
    }
}

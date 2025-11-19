<?php

namespace FluentCart\App\Modules\StorageDrivers\S3;

use FluentCart\App\Services\FileSystem\Drivers\S3\S3ConnectionVerify;
use FluentCart\App\Services\FileSystem\Drivers\S3\S3Driver;
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

    public function getBucketList(): array
    {
        $bucketList = (new S3Driver())->buckets();
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

    public function hasBucket(): bool
    {
        return true;
    }


    public function getDescription()
    {
        return esc_html__('S3 bucket allows to configure storage options and others for efficient and secure cloud-based file storage', 'fluent-cart');
    }

    public function isEnabled(): bool
    {
        $settings = $this->getSettings();
        $isActive = Arr::get($settings, 'is_active') === 'yes';
        $hasSelectedBuckets = !empty(Arr::get($settings, 'buckets')) && is_array(Arr::get($settings, 'buckets')) && count(Arr::get($settings, 'buckets')) > 0;
        return $isActive && $hasSelectedBuckets;
    }

    public function getSettings()
    {
        return (new S3Settings())->get();
    }

    public function updateSettings($data)
    {
        $settings = $this->getSettings();
        foreach ($this->hiddenSettingKeys() as $key) {
            if (empty($data[$key]) && isset($settings[$key])) {
                $data[$key] = $settings[$key];
            }
        }

        $isVarified = true;
        if (Arr::get($data, 'is_active') === 'yes') {
            $isVarified = $this->verifyConnectInfo($data);

            if (!is_wp_error($isVarified)) {
                $data['is_active'] = 'yes';
            }
            $data['show_buckets'] = 'yes';
        } else {
            $data['is_active'] = 'no';
            $data['access_key'] = '';
            $data['secret_key'] = '';
            $data['buckets'] = [];
            $data['show_buckets'] = 'no';
            $cacheKey = 'fct_s3_region';
            \FluentCart\App\Models\Meta::query()->where('meta_key', $cacheKey)->delete();
            $message = __('S3 is deactivated successfully', 'fluent-cart');
        }


//        if (empty($data['buckets'])) {
//            $data['is_active'] = 'no';
//            $data['buckets'] = [];
//        } else {
//            if ($isVarified) {
//                //$data['is_active'] = 'yes';
//            }
//        }

        if (is_wp_error($isVarified)) {
            return $isVarified;
        }


        parent::updateSettings($data);

        $shouldReload = true;

        if (empty($message)) {
            if (Arr::get($data, 'is_active') === 'yes') {
                $message = __('Your s3 storage is activated successfully', 'fluent-cart');
            } else {
                $message = __('S3 is configured successfully but not activated.', 'fluent-cart');
                $shouldReload = true;
            }
        }


        return [
            'data'         => Arr::except($data, $this->hiddenSettingKeys()),
            'message'      => $message,
            'shouldReload' => $shouldReload
        ];
    }

    /**
     * Verify Connect configuration
     */
    public function verifyConnectInfo(array $data, $args = [])
    {
        $settings = $this->getSettings();
        $isUsingDefineMode = Arr::get($settings, 'is_using_define_mode');

        $accessKey = Arr::get($data, 'access_key', null);
        $secretKey = Arr::get($data, 'secret_key', null);

        if (empty($secretKey)) {
            $secretKey = Arr::get($settings, 'secret_key');
        }


        if ($isUsingDefineMode) {
            $secretKey = FCT_S3_SECRET_KEY;
            $accessKey = FCT_S3_ACCESS_KEY;
        }

        if (empty($secretKey) || empty($accessKey)) {
            return new \WP_Error('invalid_credentials', __('Invalid credentials', 'fluent-cart'));
        }
        return S3ConnectionVerify::verify(
            $secretKey,
            $accessKey
        );
    }

    public function fields(): array
    {
        $settings = $this->getSettings();
        $showBucket = Arr::get($settings, 'show_buckets') === 'yes';
        $usingDefineMode = Arr::get($settings, 'is_using_define_mode');

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
                'value' => __('Using Define Mode', 'fluent-cart'),
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
            //'secret_key',
            'region',
            'show_buckets'
        ];
    }

    public static function getBucketRegion($bucket)
    {
        $cacheKey = 'fct_s3_region';
        $existingMeta = \FluentCart\App\Models\Meta::query()->where('meta_key', $cacheKey)->first();


        if ($existingMeta) {
            $region = Arr::get($existingMeta->meta_value, $bucket);
            if ($region) {
                return $region;
            }
        }

        $url = "https://{$bucket}.s3.amazonaws.com"; // The global endpoint

        $response = wp_remote_head($url);

        if (is_wp_error($response)) {
            return null;
        }

        $headers = wp_remote_retrieve_headers($response);

        $region = Arr::get($headers, 'x-amz-bucket-region') ?? 'us-east-1';

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
}

<?php

namespace FluentCart\App\Modules\StorageDrivers;

use FluentCart\Api\Helper;
use FluentCart\Api\StorageDrivers;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Modules\StorageDrivers\Contracts\BaseStorageInterface;
use FluentCart\Framework\Support\Collection;
use FluentCart\Framework\Validator\Validator;
use WP_Error;

abstract class BaseStorageDriver implements BaseStorageInterface
{
    public $slug;

    public $title;

    public $brandColor = '#ccc';

    protected $driverHandler;

    public static $drivers = [];

    public static $routes = [];

    abstract public function getLogo();

    abstract public function getDescription();

    abstract public function getSettings();

    abstract public function fields();

    public function __construct($title, $slug, $brandColor)
    {
        $this->title = $title;
        $this->slug = $slug;
        $this->brandColor = $brandColor;
        $this->driverHandler = 'fluent_cart_storage_settings_' . $slug;
    }

    /**
     * @param array $data
     * @return array|true|WP_Error
     */
    public function verifyConnectInfo(array $data, $args = [])
    {
        return true;
    }

    public abstract function hiddenSettingKeys(): array;

    public function init()
    {
        add_filter('fluent_cart/storage/get_global_storage_settings_' . $this->slug, [$this, 'globalFields'], 10, 2);
        add_filter('fluent_cart/storage/get_global_storage_drivers', [$this, 'register'], 10, 2);
        add_filter('fluent_cart/storage/storage_driver_settings_routes', [$this, 'setRoutes'], 10, 2);
        add_filter('fluent_cart/storage/get_global_storage_driver_status_' . $this->slug, [$this, 'getActiveStatus'], 10, 2);

        $this->registerHooks();
    }

    public function registerHooks()
    {
        //This hook will allow others to register their storage driver with individual storage providers
    }

    public function handleRedirectData()
    {
        return '';
    }

    public function setRoutes($data, $args)
    {
        static::$routes[] = [
            'path' => $this->slug,
            'name' => $this->slug,
            'meta' => [
                'title' => $this->title
            ]
        ];
        return static::$routes;
    }

    public function register($data, $args)
    {

        $data = [
            "title"       => $this->title,
            "route"       => $this->slug,
            "description" => $this->getDescription(),
            "logo"        => $this->getLogo(),
            "status"      => $this->isEnabled(),
            "brand_color" => $this->brandColor,
            "has_bucket"  => $this->hasBucket(),
            "instance"    => $this
        ];

        if ($this->hasBucket()) {
            $bucketList = Arr::get($this->getSettings(), 'buckets', []);

            $buckets = [];

            if (is_array($bucketList)) {
                foreach ($bucketList as $bucket) {
                    $buckets[] = array(
                        "label" => $bucket,
                        "value" => $bucket,
                    );
                }
            }

            $data['buckets'] = $buckets;
        }
        static::$drivers[$this->slug] = $data;
        return static::$drivers;
    }

    public function hasBucket(): bool
    {
        return false;
    }

    public function getActiveStatus($data, $args)
    {
        $settings = $this->getSettings();
        return Arr::get($settings, 'is_active') === 'yes' ? true : false;
    }

    public function getTitle($scope = 'admin')
    {
        return $this->title;
    }

    public function renderDescription()
    {
        echo '';
    }

    public function saveSettings($data)
    {
        return $this->updateSettings($data);
    }

    public function updateSettings($data)
    {
        $oldSettings = $this->getSettings();

        $settings = $data;

        $settings = apply_filters('fluent_cart/storage/storage_settings_before_update_' . $this->slug, $settings, $oldSettings);

        $settings = Helper::sanitize($settings, $this->fields());

        fluent_cart_update_option($this->driverHandler, $settings);

        return $this->getSettings();
    }

    public function globalFields($data, $args)
    {
        // ignore hidden fields
        $filtered = Collection::make($this->fields())->filter(function ($item) {
            return Arr::get($item, 'visible', 'yes') === 'yes';
        })->toArray();

        return [
            'fields'   => $filtered,
            'settings' => Arr::except($this->getSettings(), $this->hiddenSettingKeys())
        ];
    }

    public function sanitize($data, $fields)
    {
        foreach ($fields as $key => $value) {
            if (isset($data[$key])) {
                if ('email' === $value['type']) {
                    $data[$key] = sanitize_email($data[$key]);
                } else {
                    $data[$key] = sanitize_text_field($data[$key]);
                }
            }
        }

        return $data;
    }

    protected function validate($data, array $rules = [])
    {
        $validator = (new Validator())->make($data, $rules);
        if ($validator->validate()->fails()) {
            wp_send_json($validator->errors(), 423);
        }
    }

    abstract public function getDriverClass(): string;
}


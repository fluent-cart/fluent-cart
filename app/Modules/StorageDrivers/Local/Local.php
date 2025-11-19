<?php

namespace FluentCart\App\Modules\StorageDrivers\Local;

use FluentCart\App\Services\FileSystem\Drivers\Local\LocalDriver;
use FluentCart\App\Vite;
use FluentCart\App\Modules\StorageDrivers\BaseStorageDriver;

class Local extends BaseStorageDriver
{
    /**
     * title, slug, brandColor
     */
    public function __construct()
    {
        parent::__construct(
            __('Local', 'fluent-cart'),
            'local',
            '#136196'
        );
    }

    public function registerHooks()
    {
        //
    }

    public function getLogo(): string
    {
        return Vite::getAssetUrl("images/storage-drivers/local.svg");
    }


    public function getDescription(): string
    {
        return esc_html__('Local allows to upload file in local file storage', 'fluent-cart');
    }

    public function isEnabled(): bool
    {
        return (new LocalSettings())->isActive();
    }

    public function getSettings()
    {
        return (new LocalSettings())->get();
    }

    public function fields(): array
    {
        return [
            'view' => [
                'title'           => __('Local Settings', 'fluent-cart'),
                'type'            => 'section',
                'disable_nesting' => true,
                'columns'         => [
                    'default' => 1,
                    'md'      => 1,
                    'lg'      => 1
                ],
                'schema'          => [
                    'is_active' => [
                        'value' => '',
                        'label' => __('Enable local driver', 'fluent-cart'),
                        'type'  => 'checkbox'
                    ]
                ]
            ]
        ];
    }

    public function getDriverClass(): string
    {
        return LocalDriver::class;
    }

    public function hiddenSettingKeys(): array
    {
       return [];
    }
}

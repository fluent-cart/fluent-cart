<?php

namespace Tests\Support\Factory;

use FluentCart\App\Models\Meta;
use FluentCart\App\Services\Cache;
use FluentCart\App\Services\Email\EmailNotifications;

class EmailNotificationConfigFactory
{
    /**
     * Persist arbitrary email notification config payload to fct_meta and forget cache.
     */
    public function create(array $config = []): Meta
    {
        $meta = Meta::query()->updateOrCreate(
            ['meta_key' => EmailNotifications::META_KEY, 'object_type' => 'email_notification'],
            ['meta_value' => $config]
        );

        Cache::forget(EmailNotifications::META_KEY);

        return $meta;
    }

    /**
     * Persist per-notification override under notification_config.{name}.
     */
    public function withNotificationConfig(string $name, array $settings, array $rootConfig = []): Meta
    {
        $payload = $rootConfig;
        $payload['notification_config'] = $payload['notification_config'] ?? [];
        $payload['notification_config'][$name] = $settings;

        return $this->create($payload);
    }

    /**
     * Reset cache + delete stored row so the registry returns to defaults.
     */
    public function reset(): void
    {
        Meta::query()
            ->where('object_type', 'email_notification')
            ->where('meta_key', EmailNotifications::META_KEY)
            ->delete();

        Cache::forget(EmailNotifications::META_KEY);
    }
}

<?php

namespace FluentCart\App\Services;

use FluentCart\App\App;

class Cache
{

    public static function get($key, $callback = false, $expire = 3600)
    {
        $key = static::getKey($key);

        $value = wp_cache_get($key, static::getGroupName());

        if ($value !== false) {
            return $value;
        }

        if ($callback) {
            $value = $callback();
            if ($value) {
                static::set($key, $value, $expire);
            }
        }

        return $value;
    }

    public static function forget($key): bool
    {
        return wp_cache_delete(static::getKey($key), static::getGroupName());
    }

    public static function set($key, $value, $expire = 3600): bool
    {
        return wp_cache_set(static::getKey($key), $value, static::getGroupName(), $expire);
    }

    public static function update($key, $value, $expire = 3600): bool
    {
        return static::set(static::getKey($key), $value, $expire);
    }

    private static function getKey(string $key): string
    {
        return static::getKeyPrefix() . '_' . $key;
    }

    private static function getKeyPrefix()
    {
        return App::config()->get('slug');
    }

    private static function getGroupName(): string
    {
        return 'fluent-cart';
    }
}
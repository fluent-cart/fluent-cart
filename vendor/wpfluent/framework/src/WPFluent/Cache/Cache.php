<?php

namespace FluentCart\Framework\Cache;

use Closure;
use Throwable;
use RuntimeException;
use FluentCart\Framework\Support\Path;
use FluentCart\Framework\Foundation\App;

class Cache
{
    protected static $cacheDir = null;

    /**
     * Initialize the cache directory.
     */
    public static function init($cacheDir = null)
    {
        if (static::isPersistent()) return;

        static::$cacheDir = static::getDir($cacheDir);

        if (!is_dir(static::$cacheDir) && !mkdir(static::$cacheDir, 0755, true)) {
            throw new RuntimeException(
                "Unable to create cache directory: " . static::$cacheDir
            );
        }

        return new static;
    }

    /**
     * Resolve cache directory.
     * 
     * @param  string|null $cacheDir
     * @return string
     */
    public static function getDir($cacheDir = null)
    {
        $slug = App::config()->get('app.slug');
        
        $cacheDir ??= WP_CONTENT_DIR . "/uploads/{$slug}/storage/app/cache";

        return rtrim($cacheDir, '/');
    }

    /**
     * Store data in the cache using transients.
     *
     * @param  string  $key
     * @param  mixed   $value
     * @param  int     $ttl   Time to live (in seconds)
     * @return bool
     */
    public static function put($key, $value, $ttl = HOUR_IN_SECONDS)
    {
        $key = static::key($key);

        if (static::isPersistent()) {
            return wp_cache_set($key, $value, static::cacheGroup(), $ttl);
        } else {
            return static::filePut($key, $value, $ttl);
        }
    }

    /**
     * Store data in the cache using transients.
     *
     * @param  string  $key
     * @param  mixed   $value
     * @param  int     $ttl   Time to live (in seconds)
     * @return bool
     */
    public static function set($key, $value, $ttl = HOUR_IN_SECONDS)
    {
        return static::put($key, $value, $ttl);
    }

    /**
     * Retrieve data from the cache.
     *
     * @param  string  $key
     * @return mixed
     */
    public static function get($key)
    {
        $key = static::key($key);

        if (static::isPersistent()) {
            $value = wp_cache_get($key, static::cacheGroup(), false, $found);
            $value = $found ? $value : null;
        } else {
            $value = static::fileGet($key) ?: null;
        }

        return $value;
    }

    /**
     * Remove data from the cache.
     *
     * @param  string  $key
     * @return bool
     */
    public static function forget($key)
    {
        $key = static::key($key);

        if (static::isPersistent()) {
            return wp_cache_delete($key, static::cacheGroup());
        }

        return static::fileForget($key);
    }

    /**
     * The group key for the cache.
     * 
     * @return string
     */
    public static function cacheGroup()
    {
        $slug = App::config()->get('app.slug');

        return 'wpfluent_cache_group_' . $slug;
    }

    /**
     * Store data in the cache "forever" using 5 years of expiry time.
     *
     * @param  string  $key
     * @param  mixed   $value
     * @return bool
     */
    public static function forever($key, $value)
    {
        $key = static::key($key);

        if (static::isPersistent()) {
            return wp_cache_set($key, $value, static::cacheGroup(), 0);
        } else {
            return static::filePut($key, $value, 0);
        }
    }

    /**
     * Get an item from the cache, or store the default value if not present.
     *
     * @param  string   $key
     * @param  \Closure $callback
     * @param  int      $ttl   Time to live (in seconds)
     * @return mixed
     */
    public static function remember(
        $key, Closure $callback, $ttl = HOUR_IN_SECONDS
    )
    {
        $value = static::get($key);

        if ($value !== null) {
            return $value;
        }

        try {
            $value = $callback();
            if (!is_wp_error($value)) {
                static::set($key, $value, $ttl);
                return $value;
            }
        } catch (Throwable $e) {

        }
    }

    /**
     * Increment the value of an item in the cache.
     * 
     * @param  string  $key
     * @param  integer $count
     * @return bool
     */
    public static function increment($key, $count = 1)
    {
        $value = static::get($key);

        if (is_numeric($value)) {
            return static::set($key, $value + $count);
        }

        return false;
    }

    /**
     * Decrement the value of an item in the cache.
     * 
     * @param  string  $key
     * @param  integer $count
     * @return bool
     */
    public static function decrement($key, $count = 1)
    {
        $value = static::get($key);

        if (is_numeric($value)) {
            return static::set($key, $value - $count);
        }

        return false;
    }

    /**
     * Delete all cached items for the plugin.
     * 
     * @return void
     */
    public static function flush()
    {
        if (static::isPersistent()) {
            // Preferred: Use wp_cache_flush_group if available
            if (function_exists('wp_cache_flush_group')) {
                return wp_cache_flush_group(static::cacheGroup());
            }

            // Fallback: Manual flush for in-memory cache
            global $wp_object_cache;
            $group = static::cacheGroup();

            if (!isset($wp_object_cache->cache[$group])) {
                return false;
            }

            foreach (array_keys($wp_object_cache->cache[$group]) as $key) {
                unset($wp_object_cache->cache[$group][$key]);
            }

            return true;
        }

        // For non-persistent cache (e.g., file cache fallback)
        return static::fileFlush();
    }

    /**
     * Check if a key exists in the cache.
     *
     * @param  string  $key
     * @return bool
     */
    public static function has($key)
    {
        return static::get($key) !== null;
    }

    /**
     * Make unique key with config.app.slug
     * 
     * @param  string $key
     * @return string
     */
    protected static function key($key)
    {
        $slug = App::config()->get('app.slug');
        
        return $slug . '_wpfluent_cache_' . sanitize_key($key);
    }

    /**
     * Check if persistent cache is available.
     *
     * @return bool
     */
    protected static function isPersistent()
    {
        return wp_using_ext_object_cache();
    }

     /**
     * Store data in a file if no persistent cache is available.
     *
     * @param string $key
     * @param mixed $data
     * @param int $expiration
     */
    protected static function filePut($key, $data, $expiration)
    {
        try {
            return static::store($key, [
                'data' => $data,
                'expiration' => time() + $expiration,
            ]);
        } catch (Throwable $e) {
            throw $e;
        }

    }

    /**
     * Store the data in a file if no persistent cache is available.
     * 
     * @param  string $key
     * @param  mixed  $payload
     * @return bool
     */
    protected static function store($key, $payload)
    {
        if (!static::$cacheDir || !is_readable(static::$cacheDir)) {
            static::init();
        }

        $data = $payload['data'];

        if (is_object($data) && method_exists($data, 'toArray')) {
            $payload['data'] = $data->toArray();
        }

        $filePath = static::$cacheDir . '/' . md5($key) . '.cache';

        return file_put_contents($filePath, serialize($payload)) !== false;
    }

    /**
     * Retrieve data from the file cache.
     *
     * @param string $key
     * @return mixed|null
     */
    protected static function fileGet($key)
    {
        $filePath = static::$cacheDir . '/' . md5($key) . '.cache';
        
        if (file_exists($filePath)) {
            $data = unserialize(file_get_contents($filePath));
            if ($data['expiration'] >= time()) {
                return $data['data'];
            }
            static::fileForget($key);
        }

        return false;
    }

    /**
     * Remove an item from the file cache.
     *
     * @param string $key
     * @return bool
     */
    protected static function fileForget($key)
    {
        $filePath = static::$cacheDir . '/' . md5($key) . '.cache';
        
        if (file_exists($filePath)) {
            unlink($filePath);
            return true;
        }

        return false;
    }

    /**
     * Flush all file cache entries.
     *
     * @return bool
     */
    protected static function fileFlush()
    {
        if (!is_dir(static::$cacheDir)) {
            return false;
        }

        $files = glob(static::$cacheDir . '/*.cache');

        foreach ($files as $file) {
            @unlink($file);
        }

        return true;
    }
}

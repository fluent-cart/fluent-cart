<?php

namespace FluentCart\Framework\Foundation;

use FluentCart\Framework\Support\Arr;

class Config
{
    /**
     * Eagerly-set or lazily-loaded config data, keyed by top-level config name.
     * @var array
     */
    protected $data = [];

    /**
     * Map of top-level key (e.g. 'i18n', 'app') → absolute file path.
     * Files are required on first access to their top-level key. Defers
     * any side-effecting code in config files (notably `__()` calls in
     * config/i18n.php) until consumers actually read them — which always
     * happens after `init`, so WP 6.7+'s `_doing_it_wrong` notice on
     * early textdomain load is no longer triggered by the framework.
     *
     * Eagerly-supplied $data still works (back-compat for tests / direct
     * construction without a file map).
     *
     * @var array<string, string>
     */
    protected $files = [];

    /**
     * Top-level keys whose backing file has been required. Tracks loaded
     * state separately from $data because a config file may legitimately
     * `return null` / `return []`, and we must not re-require those.
     *
     * @var array<string, bool>
     */
    protected $loaded = [];

    /**
     * Construct the Config instance.
     *
     * @param array $data  Eagerly-populated data (keyed by top-level name).
     * @param array $files Map of top-level name → file path for lazy load.
     */
    public function __construct($data, $files = [])
    {
        $this->data = $data;
        $this->files = $files;

        // Anything passed in $data is already resolved.
        foreach (array_keys($data) as $top) {
            $this->loaded[$top] = true;
        }
    }

    /**
     * Retrieve all config data. Force-loads every lazy file.
     *
     * @return array
     */
    public function all()
    {
        foreach (array_keys($this->files) as $top) {
            $this->ensureLoaded($top);
        }

        return $this->data;
    }

    /**
     * Retrieve specific item from config array.
     *
     * @param  string $key
     * @param  mixed  $default
     * @return mixed
     */
    public function get($key = null, $default = null)
    {
        if ($key === null || $key === '') {
            return $this->all();
        }

        $key = $this->resolveKey($key);

        $this->ensureLoaded($this->topKey($key));

        return Arr::get($this->data, $key, $default);
    }

    /**
     * Get only specific items from the config array.
     *
     * @param  string|array $key ($key1, $key2 or [$key1, $key2])
     * @return array
     */
    public function only($key)
    {
        $keys = array_map(function ($key) {
            return $this->resolveKey($key);
        }, is_array($key) ? $key : func_get_args());

        $result = [];

        foreach ($keys as $key) {
            $this->ensureLoaded($this->topKey($key));
            $result[] = Arr::get($this->data, $key);
        }

        return array_values(array_filter($result));
    }

    /**
     * Set an item into the config array on the fly. Forces the underlying
     * file to load first, so the write isn't clobbered by a later lazy load.
     *
     * @param string $key
     * @param mixed  $value
     */
    public function set($key, $value)
    {
        $key = $this->resolveKey($key);
        $this->ensureLoaded($this->topKey($key));
        Arr::set($this->data, $key, $value);
    }

    /**
     * Lazy-load a config file the first time its top-level key is touched.
     * Idempotent: subsequent calls are no-ops.
     *
     * @param  string $topKey
     * @return void
     */
    protected function ensureLoaded($topKey)
    {
        if (!$topKey || isset($this->loaded[$topKey])) {
            return;
        }

        if (isset($this->files[$topKey])) {
            $this->data[$topKey] = require $this->files[$topKey];
        }

        $this->loaded[$topKey] = true;
    }

    /**
     * Extract the top-level key from a dotted path. 'app.text_domain' → 'app'.
     *
     * @param  string $key
     * @return string
     */
    protected function topKey($key)
    {
        $dot = strpos($key, '.');
        return $dot === false ? $key : substr($key, 0, $dot);
    }

    /**
     * Resolve the config key, add `app.` prefix when the key is short and
     * not a known top-level config name (matches eagerly-loaded data OR a
     * deferred file). Without the file lookup, `get('i18n')` would resolve
     * to `app.i18n` before the i18n config file had been touched.
     *
     * @param  string $key
     * @return string
     */
    protected function resolveKey($key)
    {
        if (!$key) return $key;

        if (
            array_key_exists($key, $this->data)
            || array_key_exists($key, $this->files)
        ) {
            return $key;
        }

        return str_contains($key, '.') ? $key : "app.{$key}";
    }
}

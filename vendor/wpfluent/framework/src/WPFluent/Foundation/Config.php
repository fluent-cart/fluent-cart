<?php

namespace FluentCart\Framework\Foundation;

use FluentCart\Framework\Support\Arr;

class Config
{
    /**
     * The config data
     * @var array
     */
    protected $data = [];

    /**
     * Construct the Config instance
     * @param array $data
     */
    public function __construct($data)
    {
        $this->data = $data;
    }

    /**
     * Retrieve all config data
     * @return array
     */
    public function all()
    {
        return $this->get();
    }

    /**
     * Retrieve specific item from config array.
     * 
     * @param  string $key
     * @param  string $default
     * @return mixed
     */
    public function get($key = null, $default = null)
    {
        $key = $this->resolveKey($key);

        return $key ? Arr::get($this->data, $key, $default) : $this->data;
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
        }, is_Array($key) ? $key : func_get_args());

        $result = [];

        foreach ($keys as $key) {
            $result[] = Arr::get($this->data, $key);
        }

        return array_values(array_filter($result));
    }

    /**
     * Set an item into the config array on the fly.
     * @param string $key
     * @param mixed $value
     */
    public function set($key, $value)
    {
        Arr::set($this->data, $key, $value);
    }

    /**
     * Resolove the config key, add app. prefix if needed.
     * 
     * @param  string $key
     * @return string
     */
    protected function resolveKey($key)
    {
        if (!$key) return $key;
        
        if (array_key_exists($key, $this->data)) {
            return $key;
        }
        
        return str_contains($key, '.') ? $key : "app.{$key}";
    }
}

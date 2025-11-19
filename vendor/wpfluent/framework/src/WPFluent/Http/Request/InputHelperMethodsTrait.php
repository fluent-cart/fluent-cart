<?php

namespace FluentCart\Framework\Http\Request;

use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Sanitizer;

trait InputHelperMethodsTrait
{
    /**
     * Get an item from the request filtering by the callback(s).
     *
     * @param  string|array|null $key
     * @param  callable|array|string|null $callback
     * @param  mixed $default
     * @return mixed
     */
    public function getSafe($key, $callback = null, $default = null)
    {
        $array = $result = [];
        $expectsArray = true;

        if (!is_array($key)) {
            $key = [$key];
            $expectsArray = false;
        }

        if ($callback) {
            $callback = is_array($callback) ? $callback : [$callback];
            
            foreach ($key as $field) {
                $array[$field] = $callback;
            }
        } else {
            foreach ($key as $k => $v) {
                if (is_int($k)) {
                    $k = $v;
                    $v = fn($v) => $v;
                }

                $array[$k] = is_array($v) ? $v : [$v];
            }
        }

        $result = Sanitizer::sanitize($this->all(), $array);

        $result = $this->pickKeys(array_keys($array), $result);

        return $expectsArray ? $result : reset($result);
    }

    /**
     * Pick only the given keys from data.
     *
     * @param array $keys
     * @param array $allData
     * @return array
     */
    public function pickKeys($keys, $allData, $default = null)
    {
        $result = [];

        $walk = function ($data, $segments, &$res) use (&$walk, $default) {
            $segment = array_shift($segments);

            if ($segment === null) {
                if (is_array($data)) {
                    $res = array_merge_recursive($res ?? [], $data);
                } else {
                    $res = $data ?? $default;
                }
                return;
            }

            if ($segment === '*') {
                if (!is_array($data)) return;

                foreach ($data as $index => $value) {
                    $res[$index] ??= [];
                    $walk($value, $segments, $res[$index]);
                }
            } else {
                if (is_array($data) && array_key_exists($segment, $data)) {
                    $res[$segment] ??= [];
                    $walk($data[$segment], $segments, $res[$segment]);
                } else {
                    if (empty($segments)) {
                        $res[$segment] = $default;
                    }
                }
            }
        };

        foreach ($keys as $k) {
            if (is_array($k)) {
                $k = implode('.', $k);
            }

            $segments = explode('.', $k);
            $walk($allData, $segments, $result);
        }

        return $result;
    }

    /**
     * Returns a sanitized integer.
     *
     * @param  string $key
     * @param  string $default
     * @return int
     */
    public function getInt($key, $default = null)
    {
        return Sanitizer::sanitizeInt($this->get($key, $default));
    }

    /**
     * Retrieve input as a float value.
     *
     * @param  string|null $key
     * @param  float $default
     * @return float
     */
    public function getFloat($key, $default = null)
    {
        return Sanitizer::sanitizeFloat($this->get($key, $default));
    }

    /**
     * Returns a sanitized string.
     *
     * @param  string $key
     * @param  string $default
     * @return string
     */
    public function getText($key, $default = null)
    {
        return Sanitizer::sanitizeText($this->get($key, $default));
    }

    /**
     * Returns a string as title.
     *
     * @param  string $key
     * @param  string $default
     * @return string
     */
    public function getTitle($key, $default = null)
    {
        return Sanitizer::sanitizeTitle($this->get($key, $default));
    }

    /**
     * Returns sanitized email.
     *
     * @param  string $key
     * @param  string $default
     * @return string
     */
    public function getEmail($key, $default = null)
    {
        return Sanitizer::sanitizeEmail($this->get($key, $default));
    }

    /**
     * Returns boolean value.
     *
     * @param  string $key
     * @param  string $default
     * @return bool|null TRUE for "1", "true", "on", "yes"; FALSE for "0", "false", "off", "no"; NULL otherwise
     */
    public function getBool($key, $default = null)
    {
        return Sanitizer::sanitizeBool($this->get($key, $default));
    }

    /**
     * Returns a DateTime object.
     *
     * @param  string $key
     * @param  string|null $format
     * @param  string|null $tz
     * @return \FluentCart\Framework\Support\DateTime|null
     */
    public function getDate($key, $format = null, $tz = null)
    {
        if (!$value = $this->get($key)) {
            return null;
        }

        return Sanitizer::sanitizeDate($value, $format, $tz);
    }
}

<?php

namespace FluentCart\Framework\Support;

use RuntimeException;

class Env
{
    protected static $localStore = [];

    /**
     * Load environment variables from a file.
     */
    public static function load(string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException(
                "Environment file not found at: $filePath"
            );
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $line, 2), 2, null);
            $name = trim($name);
            $value = trim((string) $value, "\"'");

            if ($name === '') {
                continue;
            }

            static::set($name, static::normalize($value));
        }
    }

    /**
     * Get an environment variable.
     */
    public static function get(string $key, $default = null)
    {
        return array_key_exists($key, static::$localStore)
            ? static::$localStore[$key]
            : $default;
    }

    /**
     * Set an environment variable.
     */
    public static function set(string $key, $value): void
    {
        static::$localStore[$key] = $value;
    }

    /**
     * Get all environment variables.
     */
    public static function all(): array
    {
        return static::$localStore;
    }

    /**
     * Dump and die.
     */
    public static function dd(): void
    {
        if (function_exists('dd')) {
            dd(static::all());
        } else {
            print_r(static::all());
            die;
        }
    }

    /**
     * Normalize string values to PHP native types.
     */
    protected static function normalize($value)
    {
        if (!is_string($value)) {
            return $value;
        }

        $trimmed = strtolower(trim($value));

        switch ($trimmed) {
            case 'true':
            case '(true)':
            case '1':
                return true;

            case 'false':
            case '(false)':
            case '0':
                return false;

            case 'null':
            case '(null)':
                return null;

            default:
                return $value;
        }
    }
}

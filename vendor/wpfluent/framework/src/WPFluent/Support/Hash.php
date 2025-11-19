<?php

namespace FluentCart\Framework\Support;

class Hash
{
    /**
     * $algo Hashing algorithm
     * @var int
     */
    protected static $algo = PASSWORD_BCRYPT;

    /**
     * Hash a value using the default algorithm.
     *
     * @param  string  $value
     * @return string
     */
    public static function make($value)
    {
        return password_hash($value, static::$algo);
    }

    /**
     * Check if the given value is already hashed.
     *
     * @param  string  $value
     * @return bool
     */
    public static function isHashed($value)
    {
        return is_string($value) &&
        strlen($value) === 60 &&
        strpos($value, '$') === 0;
    }

    /**
     * Verify the hashed value's configuration.
     *
     * @param  string  $hash
     * @return bool
     */
    public static function verifyConfiguration($hash)
    {
        return password_needs_rehash($hash, static::$algo) === false;
    }

    /**
     * Verify if the given plain value matches the hash.
     *
     * @param  string  $value
     * @param  string  $hash
     * @return bool
     */
    public static function check($value, $hash)
    {
        return password_verify($value, $hash);
    }

    /**
     * Check if two given hashes match.
     * 
     * @param  string $hash1
     * @param  string $hash2
     * @return bool
     */
    public static function compare($hash1, $hash2)
    {
        return hash_equals($hash1, $hash2);
    }
}

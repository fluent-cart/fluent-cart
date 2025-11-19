<?php

namespace FluentCart\Api\Hasher;

class Hash
{
    private static $algo = 'md5';

    private static $secret = 'wp-fluent-cart-secret-key';

    public static function random(): string
    {
        $str = wp_generate_uuid4() . '_' . time();

        return hash_hmac(static::$algo, $str, static::$secret);
    }

    public static function make(string $string): string
    {
        return hash_hmac(static::$algo, $string, static::$secret);
    }

    public static function verify(string $knownString, string $userString): bool
    {
        return hash_equals($knownString, $userString);
    }
}

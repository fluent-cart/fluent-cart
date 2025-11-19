<?php

namespace FluentCart\Api\Cookie;

class Cookie
{
    private static string $key = 'fct_cart_hash';

    public static function getCartHash(string $default = ''): string
    {
        $hash = \FluentCart\Framework\Http\Cookie::get(static::getCartHashKey(), $default);
        return trim($hash);
    }

    public static function getCartHashKey(): string
    {
        return self::$key;
    }

    public static function setCartHash(string $cartHash): void
    {
        $expireTime = apply_filters('fluent_cart/cart_cookie_minutes', time() + 24 * 60 * 30);
        setcookie(static::getCartHashKey(), $cartHash, [
            'expires'  => $expireTime,
            'path'     => COOKIEPATH,
            'domain'   => COOKIE_DOMAIN,
            'secure'   => is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    public static function deleteCartHash(): void
    {
        \FluentCart\Framework\Http\Cookie::delete(
            static::getCartHashKey(),
            COOKIEPATH,
            COOKIE_DOMAIN
        );
        //setcookie(static::getCartHashKey(), false, time() - 1000, COOKIEPATH, COOKIE_DOMAIN);
    }
}

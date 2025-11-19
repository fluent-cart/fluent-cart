<?php

namespace FluentCart\Framework\Database\Orm\Casts;

class Json
{
    /**
     * The custom JSON encoder.
     *
     * @var callable|null
     */
    protected static $encoder;

    /**
     * The custom JSON decode.
     *
     * @var callable|null
     */
    protected static $decoder;

    /**
     * Encode the given value.
     */
    public static function encode($value)
    {
        return isset(static::$encoder) ? (static::$encoder)($value) : json_encode($value);
    }

    /**
     * Decode the given value.
     */
    public static function decode($value, $associative = true)
    {
        return isset(static::$decoder)
                ? (static::$decoder)($value, $associative)
                : json_decode($value, $associative);
    }

    /**
     * Encode all values using the given callable.
     */
    public static function encodeUsing(callable $encoder)
    {
        static::$encoder = $encoder;
    }

    /**
     * Decode all values using the given callable.
     */
    public static function decodeUsing(callable $decoder)
    {
        static::$decoder = $decoder;
    }
}

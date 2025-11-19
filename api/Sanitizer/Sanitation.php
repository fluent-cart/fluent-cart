<?php

namespace FluentCart\Api\Sanitizer;

abstract class Sanitation
{

    protected static function defaultSanitizerMap(): array
    {
        return Sanitizer::getDefaultSanitizerMap();
    }

    private static function getSanitizeMap(): array
    {
        return static::defaultSanitizerMap();
    }

    public static function sanitizeAll($data): array
    {
        return Sanitizer::sanitize($data, static::getSanitizeMap());
    }
}

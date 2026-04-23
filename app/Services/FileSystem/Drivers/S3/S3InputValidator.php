<?php

namespace FluentCart\App\Services\FileSystem\Drivers\S3;

class S3InputValidator
{
    private const REGION_PATTERN = '/^(af|ap|ca|cn|eu|il|me|mx|sa|us)(-gov)?-[a-z0-9-]+-\d+$/';

    public static function isValidBucket($bucket): bool
    {
        if (!is_string($bucket)) {
            return false;
        }

        $bucket = trim($bucket);

        if ($bucket === '' || strlen($bucket) < 3 || strlen($bucket) > 63) {
            return false;
        }

        if (!preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/', $bucket)) {
            return false;
        }

        if (strpos($bucket, '..') !== false || strpos($bucket, '.-') !== false || strpos($bucket, '-.') !== false) {
            return false;
        }

        if (preg_match('/^\d+\.\d+\.\d+\.\d+$/', $bucket)) {
            return false;
        }

        return true;
    }

    public static function isValidRegion($region): bool
    {
        if (!is_string($region)) {
            return false;
        }

        $region = trim($region);

        if ($region === '' || strlen($region) > 32) {
            return false;
        }

        return (bool) preg_match(self::REGION_PATTERN, $region);
    }

    public static function validateBucket($bucket)
    {
        if (self::isValidBucket($bucket)) {
            return true;
        }

        return new \WP_Error(
            'invalid_bucket',
            __('Invalid S3 bucket name.', 'fluent-cart')
        );
    }

    public static function validateRegion($region)
    {
        if (self::isValidRegion($region)) {
            return true;
        }

        return new \WP_Error(
            'invalid_region',
            __('Invalid S3 region.', 'fluent-cart')
        );
    }

    public static function validateBucketAndRegion($bucket, $region)
    {
        $bucketValidation = self::validateBucket($bucket);
        if (is_wp_error($bucketValidation)) {
            return $bucketValidation;
        }

        $regionValidation = self::validateRegion($region);
        if (is_wp_error($regionValidation)) {
            return $regionValidation;
        }

        return true;
    }
}

<?php

namespace FluentCart\App\Services\FileSystem;

use FluentCart\Api\StorageDrivers;
use FluentCart\Framework\Support\Arr;

/**
 * Resolves which bucket a storage driver is configured to use.
 *
 * A downloadable file's bucket is server state: the store configures exactly one
 * bucket per driver, and the admin file browser only ever echoes that same bucket
 * back into the payload. Trusting the submitted value let a product editor point a
 * download at any sibling bucket the store credentials could read, so both the
 * write path (ProductDownloadablesController) and the read path (DownloadService)
 * resolve it here instead.
 */
class StorageBucketResolver
{
    /**
     * @param string $driver driver slug, e.g. 'local', 's3', 'r2'
     * @return string|\WP_Error '' for drivers that have no bucket (local);
     *                          WP_Error when the driver is unknown, disabled,
     *                          or bucket-backed without a configured bucket
     */
    public static function resolve($driver)
    {
        $driver = sanitize_text_field((string)$driver);

        if ($driver === '') {
            return new \WP_Error(
                'fluent_cart_storage_driver_missing',
                __('A storage driver is required.', 'fluent-cart')
            );
        }

        $instance = Arr::get((new StorageDrivers())->getActive(true), $driver . '.instance');

        if (empty($instance)) {
            return new \WP_Error(
                'fluent_cart_storage_driver_invalid',
                /* translators: %1$s: the storage driver slug that was submitted */
                sprintf(__('The storage driver "%1$s" is not enabled.', 'fluent-cart'), $driver)
            );
        }

        // Third-party drivers only have to implement BaseStorageInterface, which
        // declares neither method; treat those as bucketless rather than fataling.
        if (!method_exists($instance, 'hasBucket') || !$instance->hasBucket()) {
            return '';
        }

        $bucket = method_exists($instance, 'getEffectiveBucket')
            ? (string)$instance->getEffectiveBucket()
            : '';

        if ($bucket === '') {
            return new \WP_Error(
                'fluent_cart_storage_bucket_missing',
                /* translators: %1$s: the storage driver slug that was submitted */
                sprintf(__('No bucket is configured for the "%1$s" storage driver.', 'fluent-cart'), $driver)
            );
        }

        return $bucket;
    }

    /**
     * Read-path variant: never fails, so a misconfigured driver degrades to the
     * driver's own default bucket instead of breaking an existing download.
     * Returns null when nothing can be resolved, which every driver reads as
     * "use my configured bucket".
     *
     * @param string $driver
     * @return string|null
     */
    public static function resolveOrNull($driver)
    {
        $bucket = static::resolve($driver);

        if (is_wp_error($bucket) || $bucket === '') {
            return null;
        }

        return $bucket;
    }
}

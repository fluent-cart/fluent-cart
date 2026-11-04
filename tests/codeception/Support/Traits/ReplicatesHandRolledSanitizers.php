<?php

namespace Tests\Support\Traits;

use FluentCart\Framework\Support\Arr;

/**
 * Verbatim transcriptions of the hand-rolled sanitize loops that currently live
 * inline in controller bodies, where they cannot be called directly.
 *
 * These exist so WildcardSanitizerEquivalenceTest can diff "what production does
 * today" against "what the framework wildcard map would do instead", before any
 * of those loops are replaced. Each method is a faithful copy of the cited
 * source — do NOT tidy, reorder, or "improve" them: their value is being
 * byte-equivalent to the code they stand in for.
 *
 * Sanitizers that ARE directly callable (Helper::sanitizeTextAll,
 * TaxConfigurationController::sanitizeNestedArray, and every RequestGuard
 * sanitize() map) are invoked for real by the test and deliberately not copied
 * here.
 *
 * Line numbers rot. Locate by behaviour before trusting a citation.
 */
trait ReplicatesHandRolledSanitizers
{
    /**
     * Copy of ProductDownloadablesController::syncDownloadableFiles()'s
     * sanitization loop (app/Http/Controllers/ProductDownloadablesController.php,
     * the `foreach ($data as $downloadableFile)` building $sanitizedData).
     *
     * @param array $downloadableFiles raw `downloadable_files` request value
     * @return array list of sanitized rows
     */
    protected function handRolledDownloadableFiles(array $downloadableFiles): array
    {
        $sanitizedData = [];

        foreach ($downloadableFiles as $downloadableFile) {

            $variationIds = Arr::get($downloadableFile, 'product_variation_id');
            if (is_array($variationIds)) {
                $variationIds = array_map('intval', $variationIds);
            } else {
                $variationIds = [];
            }

            $sanitizedData[] = [
                'id'                   => intval(Arr::get($downloadableFile, 'id')),
                'post_id'              => intval(Arr::get($downloadableFile, 'post_id')),
                'product_variation_id' => $variationIds,
                'title'                => sanitize_text_field(Arr::get($downloadableFile, 'title')),
                'type'                 => sanitize_text_field(Arr::get($downloadableFile, 'type')),
                'driver'               => sanitize_text_field(Arr::get($downloadableFile, 'driver')),
                'bucket'               => sanitize_text_field(Arr::get($downloadableFile, 'bucket')),
                'file_name'            => sanitize_text_field(Arr::get($downloadableFile, 'file_name')),
                'file_path'            => sanitize_text_field(Arr::get($downloadableFile, 'file_path')),
                'file_url'             => sanitize_text_field(Arr::get($downloadableFile, 'file_url')),
                'settings'             => Arr::get($downloadableFile, 'settings'),
                'serial'               => sanitize_text_field(Arr::get($downloadableFile, 'serial')),
                'file_size'            => sanitize_text_field(Arr::get($downloadableFile, 'file_size')),
            ];
        }

        return $sanitizedData;
    }

    /**
     * Copy of OrderController::calculateTax()'s item-coercion loop
     * (app/Http/Controllers/OrderController.php, the `foreach ($rawItems as $item)`
     * building $items), including its non-array guard and 100-item cap.
     *
     * @param mixed $rawItems raw `items` request value
     * @return array list of coerced items
     */
    protected function handRolledTaxItems($rawItems): array
    {
        if (!is_array($rawItems)) {
            $rawItems = [];
        } elseif (count($rawItems) > 100) {
            $rawItems = array_slice($rawItems, 0, 100);
        }

        $items = [];
        foreach ($rawItems as $item) {
            $items[] = [
                'post_id'         => (int) Arr::get($item, 'post_id', 0),
                'object_id'       => (int) Arr::get($item, 'object_id', 0),
                'subtotal'        => (int) Arr::get($item, 'subtotal', 0),
                'discount_total'  => (int) Arr::get($item, 'discount_total', 0),
                'shipping_charge' => (int) Arr::get($item, 'shipping_charge', 0),
                'quantity'        => max(1, (int) Arr::get($item, 'quantity', 1)),
            ];
        }

        return $items;
    }
}

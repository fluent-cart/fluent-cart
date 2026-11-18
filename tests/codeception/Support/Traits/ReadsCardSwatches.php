<?php

namespace Tests\Support\Traits;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Models\ProductDetail;
use Tests\Support\Factory\ProductFactory;

/**
 * Reads the data the Product Swatches block prints for the card script, runs a render
 * with stock management on, and builds simple-variation products.
 */
trait ReadsCardSwatches
{
    /** Each variant's terms, price, stock and purchase data from the rows' data-variants. */
    protected function variantData(string $html): array
    {
        preg_match('/data-variants="([^"]*)"/', $html, $match);

        return json_decode(html_entity_decode($match[1], ENT_QUOTES), true);
    }

    /** The card button config, or null when the rows leave the card on View Options. */
    protected function cardButtonConfig(string $html): ?array
    {
        if (!preg_match('/data-card-button="([^"]*)"/', $html, $match)) {
            return null;
        }

        return json_decode(html_entity_decode($match[1], ENT_QUOTES), true);
    }

    /** A simple-variations product with one variant per title, in that order. */
    protected function optionProduct(array $titles)
    {
        $product = (new ProductFactory())->createWithVariants(array_map(function ($title) {
            return ['variation_title' => $title];
        }, $titles), ['post_title' => 'Option Product']);
        ProductDetail::query()->where('post_id', $product->ID)->update(['variation_type' => 'simple_variations']);

        return $product;
    }

    /** Runs the callback with the stock management module on, then restores the saved settings. */
    protected function withStockManagement(callable $callback)
    {
        $saved = get_option(ModuleSettings::MODULE_SETTINGS_OPTION, []);
        $settings = is_array($saved) ? $saved : [];
        $settings['stock_management'] = ['active' => 'yes'];
        update_option(ModuleSettings::MODULE_SETTINGS_OPTION, $settings);
        ModuleSettings::getAllSettings(false);

        try {
            return $callback();
        } finally {
            update_option(ModuleSettings::MODULE_SETTINGS_OPTION, $saved);
            ModuleSettings::getAllSettings(false);
        }
    }
}

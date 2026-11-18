<?php

namespace Tests\Support\Traits;

use FluentCart\App\Models\Product;

/**
 * Renders the Product Quick View block as a product loop does: for the loop's current product.
 */
trait RendersProductQuickView
{
    protected function renderQuickView($product, array $attributes = []): string
    {
        $GLOBALS['fct_product'] = Product::query()->with(['detail', 'variants'])->find($product->ID);

        try {
            return do_blocks('<!-- wp:fluent-cart/product-quick-view ' . wp_json_encode((object) $attributes) . ' /-->');
        } finally {
            unset($GLOBALS['fct_product']);
        }
    }
}

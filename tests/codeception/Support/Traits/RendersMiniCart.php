<?php

namespace Tests\Support\Traits;

use FluentCart\App\Services\Renderer\MiniCartRenderer;

/**
 * Capture helper for MiniCartRenderer, which echoes its markup rather than
 * returning it.
 *
 * Renders against an empty cart so the output path stays independent of cart
 * fixtures: renderMiniCart() short-circuits the CartCheckoutHelper subtotal
 * lookup when there are no items, leaving the caller-supplied presentation
 * attributes as the only variable in the markup.
 */
trait RendersMiniCart
{
    protected function renderMiniCartMarkup(array $atts): string
    {
        $renderer = new MiniCartRenderer([], ['item_count' => 0]);

        ob_start();
        $renderer->renderMiniCart(array_merge(['is_shortcode' => true], $atts));

        return ob_get_clean();
    }
}

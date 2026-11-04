<?php

namespace Tests\Support\Traits;

use FluentCart\App\Models\Product;
use FluentCart\App\Services\Renderer\ProductCardRender;
use FluentCart\App\Services\Renderer\ProductRenderer;
use FluentCart\App\Services\Renderer\RenderContext;

/**
 * Capture + gate helpers for the two product renderers, which echo their markup
 * rather than returning it.
 *
 * Every product surface in the plugin — shop grid, carousels, related products,
 * shortcodes, Bricks, Divi, and the AJAX filter/infinite-scroll fragments —
 * funnels through one of these two classes, so exercising them directly covers
 * the visibility gates everywhere they apply.
 *
 * gateOff()/gateOn()/gateWith() record what they registered so resetProductGates()
 * can unhook it; a leaked gate would silently blank a later test's markup and
 * read as a passing assertion.
 */
trait RendersProductMarkup
{
    /** @var array<int, array{0: string, 1: callable|string}> */
    protected $registeredProductGates = [];

    protected function renderProductCard(Product $product): string
    {
        ob_start();
        (new ProductCardRender($product))->render();

        return ob_get_clean();
    }

    protected function renderSingleProduct(Product $product): string
    {
        ob_start();
        (new ProductRenderer($product))->render();

        return ob_get_clean();
    }

    protected function gateOff(string $section): void
    {
        $this->gateWith('fluent_cart/product/show_' . $section, '__return_false');
    }

    protected function gateOn(string $section): void
    {
        $this->gateWith('fluent_cart/product/show_' . $section, '__return_true');
    }

    protected function gateWith(string $hook, $callback): void
    {
        add_filter($hook, $callback, 10, 2);
        $this->registeredProductGates[] = [$hook, $callback];
    }

    protected function resetProductGates(): void
    {
        foreach ($this->registeredProductGates as $gate) {
            remove_filter($gate[0], $gate[1], 10);
        }

        $this->registeredProductGates = [];

        // The render-source stack is request-scoped state. A test that pushed
        // without popping would mislabel the next test's renders and read as a
        // pass, so it is cleared with the gates.
        RenderContext::reset();
    }

    /**
     * Record "source / name" for every gated region a render passes through.
     *
     * Returns an array that fills as rendering happens — read it after the
     * render call, not before.
     *
     * @return array<int, string>
     */
    protected function &recordRenderSources(string $section = 'title'): array
    {
        $recorded = [];

        $this->gateWith('fluent_cart/product/show_' . $section, function ($show, $context) use (&$recorded) {
            $recorded[] = $context['source'] . '/' . $context['source_name'];

            return $show;
        });

        return $recorded;
    }
}

<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\Framework\Support\Arr;

/**
 * Visibility gate for product display regions.
 *
 * Every product surface in FluentCart — the single product page, the shop
 * grid, carousels, related products, shortcodes, Bricks elements and the Divi
 * modules — funnels through ProductRenderer or ProductCardRender. Gating a
 * region here therefore gates it everywhere, including the AJAX fragments the
 * filter/infinite-scroll paths render, without a template override or CSS.
 *
 * Returning false OMITS the region's markup entirely. That is deliberate:
 * blanking the existing *_text filters leaves an empty styled button in the
 * DOM (a wasted click target and a hole in the layout), which is exactly what
 * a catalog-mode integration cannot use.
 *
 * Two filters run per region, in this order:
 *
 *   fluent_cart/product/show_{$section}   — one region, every surface
 *   fluent_cart/product/show_section      — catch-all, sees the result above
 *
 * The catch-all runs last and therefore has the final say, so "hide every
 * purchase affordance" stays a single add_filter instead of one per region.
 *
 * Callers narrow by the payload rather than by the hook name: `scope` says
 * which surface is rendering (product_card, single_product) and `section` says
 * which region. One hook name per region across all surfaces — a listener that
 * wants the shop grid but not the product page checks $context['scope'].
 */
class RenderGate
{
    /**
     * A region rendered on the shop/listing product card.
     */
    const SCOPE_CARD = 'product_card';

    /**
     * A region rendered on the single product page.
     */
    const SCOPE_SINGLE = 'single_product';

    /**
     * Gateable regions. Each maps to a fluent_cart/product/show_{section}
     * filter. Kept as a list so documentation and tests can enumerate the
     * contract instead of grepping for apply_filters calls.
     */
    const SECTIONS = [
        'image',
        'title',
        'excerpt',
        'price',
        'quantity',
        'actions',
        'add_to_cart_button',
        'buy_now_button',
        'buy_section',
    ];

    /**
     * Build the context payload a gate and its surrounding actions share.
     *
     * Single construction point so `source` / `source_name` cannot be present
     * on one region's payload and missing from the next one's.
     *
     * @param mixed  $product
     * @param string $scope   One of the SCOPE_* constants
     * @param mixed  $variant Variation-specific regions only
     * @return array
     */
    public static function context($product, $scope, $variant = null)
    {
        return RenderContext::decorate([
            'product' => $product,
            'variant' => $variant,
            'scope'   => $scope,
        ]);
    }

    /**
     * Should this region render?
     *
     * @param string $section One of self::SECTIONS
     * @param array  $context At minimum 'product' and 'scope'; 'variant' when
     *                        the region is variation-specific
     * @return bool
     */
    public static function shouldRender($section, array $context = [])
    {
        $context = wp_parse_args($context, [
            'product' => null,
            'variant' => null,
            'scope'   => '',
        ]);

        // Set after the merge so a caller cannot pass a 'section' that
        // disagrees with the hook name listeners are actually bound to.
        $context['section'] = $section;

        // Idempotent: decorate() leaves existing keys alone, so a context that
        // already came from self::context() is unchanged here.
        $context = RenderContext::decorate($context);

        $show = apply_filters("fluent_cart/product/show_{$section}", true, $context);

        return (bool) apply_filters('fluent_cart/product/show_section', $show, $context);
    }

    /**
     * Convenience for the two purchase buttons: the whole action row has to be
     * visible before an individual button inside it can be.
     *
     * Without this, hiding 'actions' would still leave a listener on
     * 'add_to_cart_button' able to resurrect a button into a row that is not
     * being rendered.
     *
     * @param string $section 'add_to_cart_button' or 'buy_now_button'
     * @param array  $context
     * @return bool
     */
    public static function shouldRenderPurchaseButton($section, array $context = [])
    {
        if (!self::shouldRender('actions', $context)) {
            return false;
        }

        return self::shouldRender($section, $context);
    }

    /**
     * CSS classes for a product card wrapper.
     *
     * @param array $classes
     * @param array $context
     * @return string Space-separated, escaped-safe class list
     */
    public static function cardClasses(array $classes, array $context = [])
    {
        $classes = apply_filters('fluent_cart/product/card_classes', $classes, RenderContext::decorate(wp_parse_args($context, [
            'product' => null,
            'scope'   => self::SCOPE_CARD,
        ])));

        if (!is_array($classes)) {
            return '';
        }

        // Split on whitespace before sanitising so a listener may return either
        // one class per entry or a space-separated string — sanitize_html_class()
        // strips the space and would otherwise weld 'sale badge' into 'salebadge'.
        $flat = [];
        foreach (Arr::flatten($classes) as $class) {
            foreach (preg_split('/\s+/', (string) $class) as $piece) {
                $flat[] = $piece;
            }
        }

        $flat = array_filter(array_map('sanitize_html_class', $flat));

        return implode(' ', array_unique($flat));
    }
}

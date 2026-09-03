<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\Framework\Support\Arr;

/**
 * Which surface asked for the current product render.
 *
 * RenderGate's `scope` answers "which region of the page" (product_card |
 * single_product). This answers "which builder is drawing it", so a listener
 * can gate a region on the shop grid without touching the same region in a
 * Divi module.
 *
 * Gutenberg needs no wiring at all: WordPress already tracks the block being
 * rendered in WP_Block_Supports::$block_to_render, which is the same signal
 * RenderHelper::getBlockWrapperAttributes() reads. Shortcodes declare
 * themselves in the one closure they all register through. Everything else —
 * Bricks, Divi, page builders we have never heard of — reports `unknown` until
 * it opts in with a single filter:
 *
 *     add_filter('fluent_cart/product/render_source', function ($source) {
 *         return bricks_is_rendering()
 *             ? ['source' => 'bricks', 'name' => 'fct-products']
 *             : $source;
 *     });
 *
 * An honest `unknown` beats a guess. Nothing here inspects the call stack.
 */
class RenderContext
{
    const SOURCE_GUTENBERG = 'gutenberg';
    const SOURCE_SHORTCODE = 'shortcode';
    const SOURCE_BRICKS    = 'bricks';
    const SOURCE_DIVI      = 'divi';
    const SOURCE_UNKNOWN   = 'unknown';

    /**
     * Set only by callers that WordPress gives us no way to detect.
     *
     * @var array{source: string, name: string}|null
     */
    protected static $declared = null;

    /**
     * Run $callback with the caller declared, restoring whatever was in force
     * before — including when the callback throws. A leaked declaration would
     * mislabel every later render in the request.
     *
     * @param string   $source
     * @param string   $name
     * @param callable $callback
     * @return mixed
     */
    public static function declaring($source, $name, callable $callback)
    {
        $previous = self::$declared;
        self::$declared = ['source' => (string) $source, 'name' => (string) $name];

        try {
            return $callback();
        } finally {
            self::$declared = $previous;
        }
    }

    /**
     * @return array{source: string, name: string}
     */
    public static function current()
    {
        // A declaration wins over block detection: a shortcode rendering inside
        // a block leaves $block_to_render pointing at the outer block, and the
        // shortcode is the more useful answer.
        if (self::$declared !== null) {
            return self::$declared;
        }

        $block = \WP_Block_Supports::$block_to_render;

        if (is_array($block) && !empty($block['blockName'])) {
            return [
                'source' => self::SOURCE_GUTENBERG,
                'name'   => (string) $block['blockName'],
            ];
        }

        $fallback = ['source' => self::SOURCE_UNKNOWN, 'name' => ''];
        $filtered = apply_filters('fluent_cart/product/render_source', $fallback);

        if (!is_array($filtered) || empty($filtered['source'])) {
            return $fallback;
        }

        return [
            'source' => (string) $filtered['source'],
            'name'   => (string) Arr::get($filtered, 'name', ''),
        ];
    }

    /**
     * Add `source` and `source_name` to a hook payload. Existing keys win.
     *
     * @param array $payload
     * @return array
     */
    public static function decorate(array $payload)
    {
        $current = self::current();

        return $payload + [
            'source'      => $current['source'],
            'source_name' => $current['name'],
        ];
    }

    /**
     * Test seam — a declaration left standing would mislabel the next test.
     *
     * @return void
     */
    public static function reset()
    {
        self::$declared = null;
    }
}

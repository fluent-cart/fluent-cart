<?php

namespace FluentCart\App\Services\Renderer;

/**
 * The cart the checkout currently being drawn resolved, and what it was drawn
 * from.
 *
 * A checkout is one parent (the `fluent-cart/checkout` block, or the
 * `[fluent_cart_checkout]` shortcode) wrapping up to nineteen inner blocks.
 * Until now every one of those inner blocks re-resolved the cart for itself
 * through CartHelper::getCart(), which is both wasteful and wrong the moment
 * the parent is rendering from a cart that is not the device's own — an add-on
 * serving an offer page its own cart, say. The parent already knows the answer;
 * this is how it says so.
 *
 * Scoped to one render pass, never memoized by the consumer: the same
 * InnerBlocks instance can be reused across passes, and a stale cart is worse
 * than an extra lookup.
 *
 *     CheckoutRenderContext::rendering($cart, ['surface' => 'checkout-block'], function () {
 *         // inner blocks here see $cart
 *     });
 */
class CheckoutRenderContext
{
    /**
     * @var \FluentCart\App\Models\Cart|null
     */
    protected static $cart = null;

    /**
     * @var array
     */
    protected static $meta = [];

    /**
     * Run $callback with $cart in force, restoring whatever was in force before
     * — including when the callback throws. A leaked cart would follow every
     * later checkout render in the request.
     *
     * @param \FluentCart\App\Models\Cart|null $cart
     * @param array $meta ['surface' => string, 'attributes' => array]
     * @param callable $callback
     * @return mixed
     */
    public static function rendering($cart, array $meta, callable $callback)
    {
        $previousCart = self::$cart;
        $previousMeta = self::$meta;

        self::$cart = $cart;
        self::$meta = $meta;

        try {
            return $callback();
        } finally {
            self::$cart = $previousCart;
            self::$meta = $previousMeta;
        }
    }

    /**
     * The cart the enclosing checkout resolved, or null outside a render pass.
     *
     * @return \FluentCart\App\Models\Cart|null
     */
    public static function cart()
    {
        return self::$cart;
    }

    /**
     * What the enclosing checkout was drawn from: at least `surface`
     * ('checkout-block' | 'checkout-shortcode') and the `attributes` it was
     * given.
     *
     * @return array
     */
    public static function meta()
    {
        return self::$meta;
    }

    /**
     * @return void
     */
    public static function reset()
    {
        self::$cart = null;
        self::$meta = [];
    }
}

<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors\Cart\InnerBlocks;

use FluentCart\Api\Contracts\CanEnqueue;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Services\Renderer\CartRenderer;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\Framework\Support\Arr;

/**
 * Child blocks of fluent-cart/cart.
 *
 * The cart page is three regions — the item rows, the totals, and the checkout
 * button — and CartRenderer already exposes each as its own method. Registering
 * them as separate blocks lets an editor reorder or drop a region, or put their
 * own blocks between them, the same way the Checkout block works.
 */
class InnerBlocks
{
    use CanEnqueue;

    public static $parentBlock = 'fluent-cart/cart';

    /**
     * No style supports on any cart block.
     *
     * The cart's appearance is owned end to end by core's cart stylesheet, which
     * both this block and the [fluent_cart_cart] shortcode render through. Block
     * supports would layer wrapper-level colour, spacing and border on top of
     * that, so the Styles tab is deliberately empty: `html => false` is a
     * capability switch, not a style.
     */
    private static function blockSupports(): array
    {
        return [
            'html' => false,
        ];
    }

    private $cart = null;

    private $renderer = null;

    private function getCart()
    {
        if ($this->cart === null) {
            $this->cart = CartHelper::getCart();
        }

        return $this->cart;
    }

    /**
     * One renderer per request, shared by all three children. Building a fresh
     * CartRenderer per block would re-run loadBundleChild() on every region.
     */
    private function getRenderer(): CartRenderer
    {
        if ($this->renderer === null) {
            $cart = $this->getCart();
            $this->renderer = new CartRenderer($cart->cart_data ?? []);
        }

        return $this->renderer;
    }

    private function hasItems(): bool
    {
        $cart = $this->getCart();

        return $cart && !empty($cart->cart_data);
    }

    public static function register()
    {
        $self = new self();

        foreach ($self->getInnerBlocks() as $block) {
            register_block_type($block['slug'], [
                'apiVersion'      => 3,
                'api_version'     => 3,
                'version'         => 3,
                'title'           => $block['title'],
                'category'        => 'fluent-cart',
                'parent'          => array_merge($block['parent'] ?? [], [static::$parentBlock]),
                'render_callback' => $block['callback'],
                'supports'        => Arr::get($block, 'supports', []),
                'attributes'      => Arr::get($block, 'attributes', []),
            ]);
        }

        add_action('enqueue_block_editor_assets', function () use ($self) {
            $self->enqueueScripts();
        });
    }

    public function getInnerBlocks(): array
    {
        return [
            [
                'title'     => __('Cart Items', 'fluent-cart'),
                'slug'      => 'fluent-cart/cart-items',
                'callback'  => [$this, 'renderItems'],
                'component' => 'CartItemsBlock',
                'icon'      => 'list-view',
                'supports'  => self::blockSupports(),
            ],
            [
                'title'     => __('Cart Total', 'fluent-cart'),
                'slug'      => 'fluent-cart/cart-total',
                'callback'  => [$this, 'renderTotal'],
                'component' => 'CartTotalBlock',
                'icon'       => 'money-alt',
                // Empty default, not the translated string: an empty value means
                // "use whatever CartRenderer says", so the label keeps following
                // the site language until someone deliberately overrides it.
                'attributes' => [
                    'total_label' => [
                        'type'    => 'string',
                        'default' => '',
                    ],
                ],
                'supports'  => self::blockSupports(),
            ],
            [
                'title'     => __('Cart Checkout Button', 'fluent-cart'),
                'slug'      => 'fluent-cart/cart-checkout-button',
                'callback'  => [$this, 'renderCheckoutButton'],
                'component' => 'CartCheckoutButtonBlock',
                'icon'       => 'button',
                'attributes' => [
                    'button_text' => [
                        'type'    => 'string',
                        'default' => '',
                    ],
                ],
                'supports'  => self::blockSupports(),
            ],
        ];
    }

    public function renderItems($attributes = [], $content = '', $block = null)
    {
        if (!$this->hasItems()) {
            return '';
        }

        ob_start();
        $this->getRenderer()->renderItems();

        return ob_get_clean();
    }

    public function renderTotal($attributes = [], $content = '', $block = null)
    {
        if (!$this->hasItems()) {
            return '';
        }

        return $this->renderWithLabelOverride(
            'fluent_cart/cart/total_label',
            Arr::get((array) $attributes, 'total_label', ''),
            function () {
                $this->getRenderer()->renderTotal();
            }
        );
    }

    /**
     * Render a region with its label temporarily overridden.
     *
     * The strings live in CartRenderer so the block and the [fluent_cart_cart]
     * shortcode stay identical; a custom label is applied through the renderer's
     * own filter rather than by rebuilding its markup here, which would drift.
     * The filter is removed straight after so it cannot leak into anything else
     * rendered later in the request.
     *
     * @param string   $hook
     * @param string   $label
     * @param callable $render
     * @return string
     */
    private function renderWithLabelOverride($hook, $label, callable $render)
    {
        $label = sanitize_text_field((string) $label);
        $override = null;

        if ($label !== '') {
            $override = function () use ($label) {
                return $label;
            };
            add_filter($hook, $override);
        }

        ob_start();
        $render();
        $html = ob_get_clean();

        if ($override) {
            remove_filter($hook, $override);
        }

        return $html;
    }

    public function renderCheckoutButton($attributes = [], $content = '', $block = null)
    {
        if (!$this->hasItems()) {
            return '';
        }

        return $this->renderWithLabelOverride(
            'fluent_cart/cart/checkout_button_text',
            Arr::get((array) $attributes, 'button_text', ''),
            function () {
                // The wrapper carries the data attribute the cart JS uses to
                // swap the button state, so it has to stay around the button.
                ?>
                <div class="fluent-cart-cart-cart-button-wrap" data-fluent-cart-cart-checkout-button-wrap>
                    <?php $this->getRenderer()->renderCheckoutButton(); ?>
                </div>
                <?php
            }
        );
    }

    public function localizeData(): array
    {
        return [
            $this->getLocalizationKey()     => [
                'blocks' => array_map(function ($block) {
                    return Arr::except($block, ['callback']);
                }, $this->getInnerBlocks()),
            ],
            // The child components in this bundle call blocktranslate(), so the
            // bundle carries the string map itself rather than relying on the
            // container's script having printed the same global first.
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    public function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/ReactSupport.js',
                'dependencies' => ['wp-blocks', 'wp-components']
            ],
            [
                'source'       => 'admin/BlockEditor/Cart/InnerBlocks/InnerBlocks.jsx',
                'dependencies' => ['wp-blocks', 'wp-components', 'wp-block-editor']
            ]
        ];
    }

    protected function generateEnqueueSlug(): string
    {
        return 'fluent_cart_cart_inner_blocks';
    }

    /**
     * Pinned rather than derived: the trait's default appends '_data' to the
     * enqueue slug, and the JS reads this global by name. Checkout's inner
     * blocks pin theirs the same way.
     */
    protected function getLocalizationKey(): string
    {
        return 'fluent_cart_cart_inner_blocks';
    }
}

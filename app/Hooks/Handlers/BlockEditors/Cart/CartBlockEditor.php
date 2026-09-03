<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors\Cart;

use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Hooks\Cart\CartLoader;
use FluentCart\App\Hooks\Handlers\BlockEditors\BlockEditor;
use FluentCart\App\Hooks\Handlers\BlockEditors\Cart\InnerBlocks\InnerBlocks;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\CartRenderer;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Vite;

/**
 * Container block for the cart page.
 *
 * Composes the cart out of three child blocks (items, totals, checkout button)
 * rather than emitting one fixed lump of markup, so an editor can reorder the
 * regions or drop their own blocks between them — the same shape the Checkout
 * block uses. The empty-cart state stays in the container: it replaces every
 * region at once, so no individual child owns it.
 */
class CartBlockEditor extends BlockEditor
{
    protected static string $editorName = 'cart';

    public function init(): void
    {
        parent::init();

        InnerBlocks::register();
    }

    public function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/Cart/CartBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components']
            ]
        ];
    }

    public function getStyles(): array
    {
        return [
            'admin/BlockEditor/Cart/style/cart-block-editor.scss'
        ];
    }

    /**
     * No style supports.
     *
     * The cart's appearance is owned end to end by core's cart stylesheet, which
     * this block and the [fluent_cart_cart] shortcode both render through. Any
     * block support would layer wrapper-level colour, spacing or border on top
     * of that and let a page drift away from the shipped design, so the Styles
     * tab is deliberately empty. `html => false` is a capability switch, not a
     * style; `align` is placement, and it is what lets the cart go full width.
     */
    public function supports(): array
    {
        return [
            'html'  => false,
            'align' => ['wide', 'full'],
        ];
    }

    /**
     * The container renders its children itself so it can wrap them in the
     * cart section and short-circuit to the empty state.
     */
    protected function skipInnerBlocks(): bool
    {
        return true;
    }

    public function localizeData(): array
    {
        return [
            $this->getLocalizationKey()     => [
                'slug'              => $this->slugPrefix,
                'name'              => static::getEditorName(),
                'title'             => __('Cart', 'fluent-cart'),
                'description'       => __('This block will display the shopping cart.', 'fluent-cart'),
                'placeholder_image' => Vite::getAssetUrl('images/placeholder.svg'),
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    public function render(array $shortCodeAttribute, $block = null)
    {
        (new CartLoader())->registerDependency();
        AssetLoader::markFrontendAssetsRequired();

        $cart = CartHelper::getCart();
        $cartItems = $cart->cart_data ?? [];
        $renderer = new CartRenderer($cartItems);

        ob_start();

        if (!$cart || !$cart->cart_data) {
            $renderer->renderEmpty();
        } else {
            // Coupons can expire or stop qualifying between add-to-cart and this
            // page view; the shortcode revalidates for the same reason.
            $cart->reValidateCoupons();

            $hasInnerBlocks = $block instanceof \WP_Block && !empty($block->inner_blocks);

            if ($hasInnerBlocks) {
                $this->renderRegions($block);
            } else {
                // No children: a bare <!-- wp:fluent-cart/cart /--> (inserted by
                // a pattern or left behind after the regions were deleted) must
                // still show a usable cart, not an empty box. CartRenderer emits
                // its own section wrapper, so nothing is added around it here.
                $renderer->render();
            }
        }

        return sprintf(
            '<div %s>%s</div>',
            get_block_wrapper_attributes(['class' => 'fct-cart-block']),
            ob_get_clean()
        );
    }

    /**
     * Wrap the child regions in the cart section.
     *
     * Labelled with aria-label rather than CartRenderer's aria-labelledby +
     * screen-reader heading: that markup hardcodes id="fct-cart-page-title", so
     * reusing it would emit a duplicate id whenever a page holds two carts, or a
     * cart block alongside the [fluent_cart_cart] shortcode.
     *
     * @param \WP_Block $block
     * @return void
     */
    private function renderRegions($block)
    {
        ?>
        <section class="fct-cart-page" role="region"
                 aria-label="<?php esc_attr_e('Your Shopping Cart', 'fluent-cart'); ?>">
            <?php
            foreach ($block->inner_blocks as $innerBlock) {
                echo $innerBlock->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
            ?>
        </section>
        <?php
    }
}

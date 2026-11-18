<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\RenderGate;
use FluentCart\App\Services\Renderer\RenderHelper;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

/**
 * Quick view trigger for the current product of a product loop. It opens the same
 * modal as View Options; the card script places it beside the buy button or on the image.
 * The editor sets Position from where the block sits: in the image block, or beside the button.
 */
class ProductQuickViewBlockEditor extends BlockEditor
{
    protected static string $editorName = 'product-quick-view';

    const POSITIONS = ['button', 'image'];

    const SHOW_ON = ['always', 'hover'];

    const DISPLAYS = ['icon', 'icon_text'];

    // Where on the image, as the badges have it; the first is the default.
    const IMAGE_POSITIONS = ['bottom-center', 'bottom-left', 'bottom-right', 'top-left', 'top-center', 'top-right'];

    public function init(): void
    {
        parent::init();

        // WP 6.9+ dequeues what an empty block queued. This block is empty for simple products,
        // yet cards loaded later by AJAX still need its styles.
        add_filter('enqueue_empty_block_content_assets', function ($enqueue, $blockName) {
            return $blockName === $this->slugPrefix . '/' . static::getEditorName() ? true : $enqueue;
        }, 10, 2);
    }

    public function ancestor(): array
    {
        return [
            'fluent-cart/product-card',
            'fluent-cart/products',
            'fluent-cart/product-carousel',
            'fluent-cart/related-product',
        ];
    }

    public function supports(): array
    {
        return [
            'html'    => false,
            'spacing' => [
                'margin'  => true,
                'padding' => true,
            ],
        ];
    }

    public function blockAttributes(): array
    {
        return [
            'position' => ['type' => 'string', 'default' => 'button'],
            'show_on'  => ['type' => 'string', 'default' => 'always'],
            'display'  => ['type' => 'string', 'default' => 'icon'],
            'image_position' => ['type' => 'string', 'default' => 'bottom-center'],
        ];
    }

    public function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/ProductQuickView/ProductQuickViewBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components']
            ]
        ];
    }

    public function getStyles(): array
    {
        return [
            'admin/BlockEditor/ProductQuickView/style/product-quick-view-block-editor.scss'
        ];
    }

    public function localizeData(): array
    {
        return [
            $this->getLocalizationKey()     => [
                'slug'        => $this->slugPrefix,
                'name'        => static::getEditorName(),
                'title'       => __('Product Quick View', 'fluent-cart'),
                'description' => __('Opens the product in a quick view, beside the button or on the image.', 'fluent-cart'),
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    public function render(array $shortCodeAttribute, $block = null)
    {
        // Queue assets before any early return: cards loaded later by AJAX (pagination,
        // filters) bring no stylesheet, so a first page without a trigger must still load it.
        Vite::enqueueStyle(
            'fluent-cart-product-quick-view',
            'admin/BlockEditor/ProductQuickView/style/product-quick-view-block-editor.scss'
        );
        // The modal opener and the placement ship with the product card script.
        AssetLoader::loadProductCardAssets();
        wp_enqueue_script('fluent-cart-product-card-js');

        $product = fluent_cart_get_current_product();
        if (!$product) {
            return '';
        }

        // A simple product has nothing to pick: its card button already adds it or buys it.
        if (!$product->detail || $product->detail->variation_type === Helper::PRODUCT_TYPE_SIMPLE) {
            return '';
        }

        // Quick view is browsing, not buying: it follows the card's action row like View Options.
        if (!RenderGate::shouldRender('actions', RenderGate::context($product, RenderGate::SCOPE_CARD))) {
            return '';
        }

        $position = self::pick($shortCodeAttribute, 'position', self::POSITIONS);
        $showOn = self::pick($shortCodeAttribute, 'show_on', self::SHOW_ON);
        $display = self::pick($shortCodeAttribute, 'display', self::DISPLAYS);
        $imagePosition = self::pick($shortCodeAttribute, 'image_position', self::IMAGE_POSITIONS);
        // Beside the button there is room for an icon only, and it must stay reachable.
        if ($position === 'button') {
            $showOn = 'always';
            $display = 'icon';
        }
        $label = __('Quick view', 'fluent-cart');

        ob_start();
        ?>
        <div <?php echo get_block_wrapper_attributes(['class' => 'fct-card-quick-view']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
             data-fct-card-quick-view
             data-position="<?php echo esc_attr($position); ?>"
             data-show="<?php echo esc_attr($showOn); ?>"
             <?php if ($position === 'image') : ?>data-image-position="<?php echo esc_attr($imagePosition); ?>"<?php endif; ?>>
            <button type="button"
                    class="fct-card-quick-view-button <?php echo esc_attr($display === 'icon' ? 'is-icon' : 'is-icon-text'); ?>"
                    data-fluent-cart-single-product-card-view-button
                    data-product-id="<?php echo esc_attr($product->ID); ?>"
                    aria-label="<?php
                    /* translators: 1: product title */
                    echo esc_attr(sprintf(__('Quick view of %1$s', 'fluent-cart'), $product->post_title)); ?>">
                <?php echo RenderHelper::quickViewIconSvg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup ?>
                <?php if ($display !== 'icon') : ?>
                    <span class="fct-card-quick-view-text" aria-hidden="true"><?php echo esc_html($label); ?></span>
                <?php endif; ?>
            </button>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function pick(array $attributes, string $key, array $allowed): string
    {
        $value = (string) Arr::get($attributes, $key, '');

        return in_array($value, $allowed, true) ? $value : $allowed[0];
    }
}

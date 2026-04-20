<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Models\Product;

class SoldOutBadgeBlockEditor extends BlockEditor
{
    protected static string $editorName = 'sold-out-badge';

    public function ancestor(): array
    {
        return [
            'fluent-cart/products',
            'fluent-cart/product-carousel',
            'fluent-cart/product-info',
            'fluent-cart/related-product',
        ];
    }

    public function supports(): array
    {
        return [
            'html'                 => false,
            'align'                => ['left', 'center', 'right'],
            'color'                => [
                'text'       => true,
                'background' => true,
                'gradients'  => true,
            ],
            'typography'           => [
                'fontSize'                      => true,
                '__experimentalFontWeight'      => true,
                '__experimentalLetterSpacing'   => true,
                '__experimentalTextTransform'   => true,
                '__experimentalDefaultControls' => [
                    'fontSize' => true,
                ],
            ],
            'spacing'              => [
                'padding' => true,
                'margin'  => true,
            ],
            '__experimentalBorder' => [
                'color'  => true,
                'radius' => true,
                'style'  => true,
                'width'  => true,
            ],
            'shadow'               => true,
        ];
    }

    public function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/SoldOutBadge/SoldOutBadgeBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components']
            ]
        ];
    }

    public function getStyles(): array
    {
        return [
            'admin/BlockEditor/SoldOutBadge/style/sold-out-badge-block-editor.scss'
        ];
    }

    public function localizeData(): array
    {
        return [
            $this->getLocalizationKey()     => [
                'slug'        => $this->slugPrefix,
                'name'        => static::getEditorName(),
                'title'       => __('Sold Out Badge', 'fluent-cart'),
                'description' => __('Displays a sold out badge when a product is out of stock.', 'fluent-cart'),
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    public function render(array $shortCodeAttribute, $block = null): string
    {
        // Enqueue frontend styles
        Vite::enqueueStyle(
            'fluent-cart-sold-out-badge',
            'admin/BlockEditor/SoldOutBadge/style/sold-out-badge-block-editor.scss'
        );

        // Validate attributes
        $badgeStyle = Arr::get($shortCodeAttribute, 'badge_style', 'badge');
        if (!in_array($badgeStyle, ['badge', 'ribbon', 'tag'], true)) {
            $badgeStyle = 'badge';
        }

        $badgePosition = Arr::get($shortCodeAttribute, 'badge_position', 'top-left');
        if (!in_array($badgePosition, ['top-left', 'top-right', 'bottom-left', 'bottom-right'], true)) {
            $badgePosition = 'top-left';
        }

        $badgeText = sanitize_text_field(Arr::get($shortCodeAttribute, 'badge_text', __('Out of Stock', 'fluent-cart')));

        // Get product — explicit product_id takes priority, then current context
        $productId = absint(Arr::get($shortCodeAttribute, 'product_id', 0));
        if ($productId) {
            $product = Product::query()->where('post_status', 'publish')->with(['detail'])->find($productId);
        } else {
            $product = fluent_cart_get_current_product();
        }

        if (!$product || !$product->detail) {
            return '';
        }

        // Check if product is out of stock
        $isOutOfStock = $product->detail->stock_availability === Helper::OUT_OF_STOCK;

        // If in stock, render nothing
        if (!$isOutOfStock) {
            return '';
        }

        // Build CSS classes (values are pre-validated via in_array above)
        $classes = ['fct-sold-out-badge'];
        $classes[] = 'fct-sold-out-badge--' . esc_attr($badgeStyle);
        $classes[] = 'fct-sold-out-badge--' . esc_attr($badgePosition);

        $wrapper_attributes = get_block_wrapper_attributes([
            'class' => implode(' ', $classes),
        ]);

        return sprintf(
            '<span %s>%s</span>',
            $wrapper_attributes,
            esc_html($badgeText)
        );
    }
}

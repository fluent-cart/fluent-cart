<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Models\Product;

class SaleBadgeBlockEditor extends BlockEditor
{
    protected static string $editorName = 'sale-badge';

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
                'source'       => 'admin/BlockEditor/SaleBadge/SaleBadgeBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components']
            ]
        ];
    }

    public function getStyles(): array
    {
        return [
            'admin/BlockEditor/SaleBadge/style/sale-badge-block-editor.scss'
        ];
    }

    public function localizeData(): array
    {
        return [
            $this->getLocalizationKey()     => [
                'slug'        => $this->slugPrefix,
                'name'        => static::getEditorName(),
                'title'       => __('Sale Badge', 'fluent-cart'),
                'description' => __('Displays a sale badge when a product is on sale.', 'fluent-cart'),
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    public function render(array $shortCodeAttribute, $block = null): string
    {
        // Enqueue frontend styles
        Vite::enqueueStyle(
            'fluent-cart-sale-badge',
            'admin/BlockEditor/SaleBadge/style/sale-badge-block-editor.scss'
        );

        // 1. Validate attributes
        $badgeStyle = Arr::get($shortCodeAttribute, 'badge_style', 'badge');
        if (!in_array($badgeStyle, ['badge', 'ribbon', 'tag'], true)) {
            $badgeStyle = 'badge';
        }

        // Default to 'top-left' but allow empty string to pass through — when the block
        // is used outside a visual container (e.g. inline in a column), the JS editor clears
        // badge_position to '' so the badge renders inline without absolute positioning.
        // This differs intentionally from SoldOutBadgeBlockEditor which always forces a position.
        $badgePosition = Arr::get($shortCodeAttribute, 'badge_position', 'top-left');
        if ($badgePosition && !in_array($badgePosition, ['top-left', 'top-right', 'bottom-left', 'bottom-right'], true)) {
            $badgePosition = '';
        }

        $priceSource = Arr::get($shortCodeAttribute, 'price_source', 'default_variant');
        if (!in_array($priceSource, ['default_variant', 'best_discount'], true)) {
            $priceSource = 'default_variant';
        }

        $showPercentage = !empty($shortCodeAttribute['show_percentage']);
        $badgeText = sanitize_text_field(Arr::get($shortCodeAttribute, 'badge_text', __('Sale!', 'fluent-cart')));
        $percentageText = sanitize_text_field(Arr::get($shortCodeAttribute, 'percentage_text', '-{percent}%'));

        // 2. Get product — explicit product_id takes priority, then current context
        $productId = absint(Arr::get($shortCodeAttribute, 'product_id', 0));
        if ($productId) {
            $product = Product::query()->where('post_status', 'publish')->with(['detail', 'variants'])->find($productId);
        } else {
            $product = fluent_cart_get_current_product();
        }

        if (!$product || !$product->detail || !$product->variants || $product->variants->isEmpty()) {
            return '';
        }

        // 3. Determine sale status based on price_source setting
        $isOnSale = false;
        $discountPercent = 0;

        if ($priceSource === 'default_variant') {
            $defaultVariantId = $product->detail->default_variation_id ?? null;
            
            $variant = $defaultVariantId
                ? ($product->variants->firstWhere('id', $defaultVariantId) ?? $product->variants->first())
                : $product->variants->first();

            if ($variant && $variant->compare_price > $variant->item_price && $variant->compare_price > 0) {
                $isOnSale = true;
                $discountPercent = max(0, min(100, round((($variant->compare_price - $variant->item_price) / $variant->compare_price) * 100)));
            }
        } else {
            // best_discount — scan all variants, use highest discount
            foreach ($product->variants as $variant) {
                if ($variant->compare_price > $variant->item_price && $variant->compare_price > 0) {
                    $isOnSale = true;
                    $discount = max(0, min(100, round((($variant->compare_price - $variant->item_price) / $variant->compare_price) * 100)));
                    if ($discount > $discountPercent) {
                        $discountPercent = $discount;
                    }
                }
            }
        }

        // 4. If not on sale, render nothing
        if (!$isOnSale) {
            return '';
        }

        // 5. Build badge text
        if ($showPercentage && $discountPercent > 0) {
            $displayText = str_replace('{percent}', $discountPercent, $percentageText);
        } else {
            $displayText = $badgeText;
        }

        // 6. Build CSS classes (values are pre-validated via in_array above)
        $classes = ['fct-sale-badge'];
        $classes[] = 'fct-sale-badge--' . esc_attr($badgeStyle);
        if ($badgePosition) {
            $classes[] = 'fct-sale-badge--' . esc_attr($badgePosition);
        }

        $wrapper_attributes = get_block_wrapper_attributes([
            'class' => implode(' ', $classes),
        ]);

        return sprintf(
            '<span %s>%s</span>',
            $wrapper_attributes,
            esc_html($displayText)
        );
    }
}

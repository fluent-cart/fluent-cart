<?php

namespace FluentCart\App\Modules\Templating\Bricks;

use Bricks\Frontend;
use FluentCart\App\Helpers\Helper;
use FluentCart\Framework\Support\Arr;

class BricksHelper
{

    static public $forcedPost = null;

    /**
     * Settings of the Products Collection element currently being rendered.
     * Exposed so DynamicData::renderValue() can read Sale/Sold Out badge
     * settings when resolving the {fct_product_image} tag — that filter
     * only receives ($tag, $post, $context), not the calling element.
     */
    static public $imageBadgeSettings = [];

    public static function getFormCurrentPost()
    {
        return self::$forcedPost;
    }

    public static function setFormCurrentPost($post)
    {
        self::$forcedPost = $post;
    }

    public static function getCategoriesOptions()
    {
        $categories = get_terms(array(
            'taxonomy'   => 'product-categories',
            'hide_empty' => false,
            'orderby'    => 'name'
        ));

        $options = [];
        if (!is_wp_error($categories) && !empty($categories)) {
            foreach ($categories as $category) {
                $options[$category->term_id] = $category->name;
            }
        }

        return $options;
    }

    public static function renderCollectionCard($settings, $post, $post_index = 1, $uid = '')
    {
        self::$imageBadgeSettings = $settings;

        $content = Frontend::get_content_wrapper($settings, Arr::get($settings, 'fields', []), $post);

        // Scope the badge settings strictly to the get_content_wrapper() call above —
        // {fct_product_image} is a generic dynamic tag usable outside Products Collection
        // too, and it must not inherit stale settings from this render.
        self::$imageBadgeSettings = [];

        if ($post_index === 1) {
            echo "<div data-fluent-client-id='" . esc_attr($uid) . "' data-template-provider='bricks' data-fct-product-card class='fct-product-card repeater-item'>";
        } else {
            echo "<div data-fct-product-card class='fct-product-card repeater-item'>";
        }

        $linkedProduct = isset($settings['linkProduct']) ? $settings['linkProduct'] : false;

        if ($linkedProduct && strpos($content, '<a ') === false) {
            echo '<a href="' . esc_attr(get_the_permalink($post)) . '">';
        }

        echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        if ($linkedProduct && strpos($content, '<a ') === false) {
            echo '</a>';
        }

        echo '</div>';
    }

    public static function isTemplate()
    {
         $is_template = false;

        if (isset($_GET['bricks']) && $_GET['bricks'] === 'run') {
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            $is_template = strpos($request_uri, '/template/') !== false;
        }

        return $is_template;
    }

    /**
     * Returns concatenated Sale Badge / Sold Out Badge <span> markup for
     * $product based on $settings (the Products Collection element's
     * settings array). Mirrors the detection logic used by core's
     * SaleBadgeBlockEditor::render() / SoldOutBadgeBlockEditor::render().
     */
    public static function renderProductBadges($product, array $settings)
    {
        if (!$product) {
            return '';
        }

        return self::renderSaleBadge($product, $settings) . self::renderSoldOutBadge($product, $settings);
    }

    private static function renderSaleBadge($product, array $settings)
    {
        if (empty($settings['showSaleBadge'])) {
            return '';
        }

        if (!$product->variants || $product->variants->isEmpty()) {
            return '';
        }

        $priceSource = Arr::get($settings, 'saleBadgePriceSource', 'default_variant');
        if (!in_array($priceSource, ['default_variant', 'best_discount'], true)) {
            $priceSource = 'default_variant';
        }

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

        if (!$isOnSale) {
            return '';
        }

        $showPercentage = !empty($settings['saleBadgeShowPercentage']);
        $badgeText = sanitize_text_field(Arr::get($settings, 'saleBadgeText', '')) ?: __('Sale!', 'fluent-cart');
        $percentageText = sanitize_text_field(Arr::get($settings, 'saleBadgePercentageText', '')) ?: '-{percent}%';

        if ($showPercentage && $discountPercent > 0) {
            $displayText = str_replace('{percent}', $discountPercent, $percentageText);
        } else {
            $displayText = $badgeText;
        }

        $badgeShape = Arr::get($settings, 'saleBadgeShape', 'badge');
        if (!in_array($badgeShape, ['badge', 'ribbon'], true)) {
            $badgeShape = 'badge';
        }

        $badgePosition = Arr::get($settings, 'saleBadgePosition', 'top-left');
        if (!in_array($badgePosition, ['top-left', 'top-right', 'bottom-left', 'bottom-right'], true)) {
            $badgePosition = 'top-left';
        }

        $classes = 'fct-sale-badge fct-sale-badge--' . $badgeShape . ' fct-sale-badge--' . $badgePosition;

        return '<span class="' . esc_attr($classes) . '">' . esc_html($displayText) . '</span>';
    }

    private static function renderSoldOutBadge($product, array $settings)
    {
        if (empty($settings['showSoldOutBadge'])) {
            return '';
        }

        if (!$product->detail) {
            return '';
        }

        $isOutOfStock = $product->detail->stock_availability === Helper::OUT_OF_STOCK;
        if (!$isOutOfStock) {
            return '';
        }

        $badgeText = sanitize_text_field(Arr::get($settings, 'soldOutBadgeText', '')) ?: __('Out of Stock', 'fluent-cart');

        $badgeShape = Arr::get($settings, 'soldOutBadgeShape', 'badge');
        if (!in_array($badgeShape, ['badge', 'ribbon'], true)) {
            $badgeShape = 'badge';
        }

        $badgePosition = Arr::get($settings, 'soldOutBadgePosition', 'top-left');
        if (!in_array($badgePosition, ['top-left', 'top-right', 'bottom-left', 'bottom-right'], true)) {
            $badgePosition = 'top-left';
        }

        $classes = 'fct-sold-out-badge fct-sold-out-badge--' . $badgeShape . ' fct-sold-out-badge--' . $badgePosition;

        return '<span class="' . esc_attr($classes) . '">' . esc_html($badgeText) . '</span>';
    }

    public static function getAllowedHtmlForContent()
    {
        $allowed_html = wp_kses_allowed_html('post');
        $allowed_html['iframe'] = [
            'src'             => true,
            'width'           => true,
            'height'          => true,
            'frameborder'     => true,
            'allow'           => true,
            'allowfullscreen' => true,
            'title'           => true,
            'referrerpolicy'  => true,
        ];
        return $allowed_html;
    }
}

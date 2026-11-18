<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\App\Hooks\Handlers\BlockEditors\ProductQuickViewBlockEditor;
use FluentCart\App\Services\Filter\ShopAttributeFilter;
use FluentCart\Framework\Support\Arr;

class ProductCardLayoutPresets
{
    private static array $built = [];

    public static function resetCache(): void
    {
        static::$built = [];
    }

    public static function all(): array
    {
        return [
            // The standard flat card, so any layout can be switched back to it.
            'classic' => [
                'label' => __('Classic', 'fluent-cart'),
                'description' => __('Full-width button under the details. Works with any theme.', 'fluent-cart'),
                'template' => [
                    // Badges and Quick View on the picture, as in the other layouts.
                    ['fluent-cart/product-image', [], [
                        ['fluent-cart/sale-badge'],
                        ['fluent-cart/sold-out-badge'],
                        ['fluent-cart/product-quick-view', self::QUICK_VIEW_ON_IMAGE],
                    ]],
                    ['fluent-cart/product-title'],
                    ['fluent-cart/product-rating'],
                    ['fluent-cart/price-range'],
                    ['fluent-cart/product-swatches', self::DEFAULT_SWATCHES],
                    ['fluent-cart/shopapp-product-buttons'],
                ],
                'attributes' => [
                    'fluent-cart/product-swatches' => self::DEFAULT_SWATCHES,
                    'fluent-cart/product-quick-view' => self::QUICK_VIEW_ON_IMAGE,
                ],
            ],
            'compact' => [
                'label' => __('Compact', 'fluent-cart'),
                'description' => __('Price and button share one row. Good for 4+ columns.', 'fluent-cart'),
                'template' => self::template('compact', self::COMPACT_SWATCHES, [], self::QUICK_VIEW_ON_IMAGE),
                'attributes' => [
                    'fluent-cart/product-swatches' => self::COMPACT_SWATCHES,
                    'fluent-cart/product-quick-view' => self::QUICK_VIEW_ON_IMAGE,
                ],
                'products' => self::GRID,
            ],
            // The Gutenberg card has a Masonry switch on every layout instead; page builders keep this
            // layout until they get the switch.
            'masonry' => [
                'builder_only' => true,
                'label' => __('Masonry', 'fluent-cart'),
                'description' => __('Compact cards in a staggered grid, where each image keeps its own height.', 'fluent-cart'),
                'template' => self::template('masonry', self::COMPACT_SWATCHES, [], self::QUICK_VIEW_ON_IMAGE),
                'attributes' => [
                    'fluent-cart/product-swatches' => self::COMPACT_SWATCHES,
                    'fluent-cart/product-quick-view' => self::QUICK_VIEW_ON_IMAGE,
                ],
                'products' => ['view_mode' => 'grid', 'product_box_grid_size' => 4],
            ],
            'floating' => [
                'label' => __('Floating', 'fluent-cart'),
                'description' => __('Details float over a tall image. Best for photo-led shops.', 'fluent-cart'),
                'template' => self::template('floating', self::FLOATING_SWATCHES),
                'attributes' => ['fluent-cart/product-swatches' => self::FLOATING_SWATCHES],
                'products' => self::GRID,
                // Grid only: the Gutenberg editor removes the Products block's grid/list switch (builders ignore this key).
                'view_switcher' => false,
            ],
            'framed' => [
                'label' => __('Framed', 'fluent-cart'),
                'description' => __('Details in an inset tile. Best for products with many options.', 'fluent-cart'),
                'template' => self::template('framed', self::FRAMED_SWATCHES, self::QUICK_VIEW_BESIDE_BUTTON),
                // Applied over a block's kept settings when switching, since the layout's look depends on them.
                'attributes' => [
                    'fluent-cart/product-swatches' => self::FRAMED_SWATCHES,
                    'fluent-cart/product-quick-view' => self::QUICK_VIEW_BESIDE_BUTTON,
                ],
                'products' => self::GRID,
            ],
        ];
    }

    /** The layouts shown only as a grid (Floating), which get no Grid/List switch. */
    public static function gridOnlyLayouts(): array
    {
        static $gridOnly = null;
        if ($gridOnly === null) {
            $gridOnly = array_keys(array_filter(static::all(), static function ($preset) {
                return ($preset['view_switcher'] ?? true) === false;
            }));
        }
        return $gridOnly;
    }

    public static function isGridOnly(string $layout): bool
    {
        return $layout !== '' && in_array($layout, static::gridOnlyLayouts(), true);
    }

    /** A layout's Product Card block, parsed as the editor saves it; null for an unknown layout. */
    public static function cardBlock(string $layout): ?array
    {
        if (array_key_exists($layout, static::$built)) {
            return static::$built[$layout];
        }
        $presets = static::all();
        if (!isset($presets[$layout])) {
            return static::$built[$layout] = null;
        }
        $template = $presets[$layout]['template'];
        // A grouped layout's outer group is the card's own wrapper, so the card keeps only its children.
        if ($layout !== 'classic') {
            $template = $template[0][2];
        }
        $attributes = [
            'template_version' => 1,
            'card_layout'      => $layout,
        ];
        // The builders' Masonry layout staggers; the mark also keeps it from the Gutenberg fallback to Classic.
        if (!empty($presets[$layout]['builder_only'])) {
            $attributes['masonry'] = true;
        }
        $markup = get_comment_delimited_block_content('fluent-cart/product-card', $attributes, self::templateMarkup($template));

        return static::$built[$layout] = parse_blocks($markup)[0];
    }

    /**
     * The Products block's loop with the Classic card, as the editor inserts it. For a template
     * registered before init: it skips the registry check and the cardBlock() cache, which would
     * otherwise keep a card built before the blocks exist.
     */
    public static function classicLoopMarkup(): string
    {
        $card = get_comment_delimited_block_content(
            'fluent-cart/product-card',
            ['template_version' => 1, 'card_layout' => 'classic', 'card_width' => '100%'],
            self::templateMarkup(static::all()['classic']['template'], false)
        );

        return '<div class="fluent-cart-product-loop fct-product-block-editor-product-card">' . $card . '</div>';
    }

    /**
     * A layout card with a builder's settings applied. 'swatches': false drops the swatch block,
     * an array sets its settings; 'sale_badge', 'sold_out_badge' or 'rating' false drops that block,
     * and a badge's array sets its settings. 'elements' (cardParts() keys) leaves out the parts not
     * listed and orders the rest, each within its own area of the card. 'masonry' true staggers the cards.
     */
    public static function withCardOptions(array $cardBlock, array $options): array
    {
        // AJAX pages send these back as query strings, where false arrives as "false".
        $isOff = function ($key) use ($options) {
            return array_key_exists($key, $options) && !is_array($options[$key])
                && filter_var($options[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === false;
        };
        if (filter_var(Arr::get($options, 'masonry', false), FILTER_VALIDATE_BOOLEAN)) {
            $cardBlock['attrs']['masonry'] = true;
        }
        $drop = [];
        if ($isOff('sale_badge')) {
            $drop[] = 'fluent-cart/sale-badge';
        }
        if ($isOff('sold_out_badge')) {
            $drop[] = 'fluent-cart/sold-out-badge';
        }
        if ($isOff('rating')) {
            $drop[] = 'fluent-cart/product-rating';
        }
        if ($isOff('quick_view')) {
            $drop[] = 'fluent-cart/product-quick-view';
        }
        $swatches = Arr::get($options, 'swatches');
        if ($isOff('swatches')) {
            $drop[] = 'fluent-cart/product-swatches';
        }
        $attributes = array_filter([
            'fluent-cart/product-swatches' => is_array($swatches) ? self::swatchAttributes($swatches) : [],
            'fluent-cart/sale-badge'       => is_array(Arr::get($options, 'sale_badge')) ? self::badgeAttributes($options['sale_badge'], true) : [],
            'fluent-cart/sold-out-badge'   => is_array(Arr::get($options, 'sold_out_badge')) ? self::badgeAttributes($options['sold_out_badge'], false) : [],
            'fluent-cart/product-rating'   => is_array(Arr::get($options, 'rating')) ? self::ratingAttributes($options['rating']) : [],
            'fluent-cart/product-quick-view' => is_array(Arr::get($options, 'quick_view')) ? self::quickViewAttributes($options['quick_view']) : [],
        ]);
        $order = self::cardElements(Arr::get($options, 'elements'));
        if ($order) {
            foreach (array_diff(array_keys(self::CARD_PARTS), $order) as $part) {
                $drop = array_merge($drop, [self::CARD_PARTS[$part]], array_keys(self::CLASSIC_PART_BLOCKS, $part, true));
            }
        }
        if (!$drop && !$attributes && !$order) {
            return $cardBlock;
        }

        return self::applyCardOptions($cardBlock, $drop, $attributes, $order);
    }

    /** A layout's parts in card order, as cardParts keys: image, quick_view, category, rating, ... */
    public static function cardParts(string $layout): array
    {
        $presets = static::all();
        if ($layout === 'classic' || !isset($presets[$layout])) {
            return [];
        }

        // Read from the template, not cardBlock(), which needs the blocks registered first.
        return self::partsIn($presets[$layout]['template']);
    }

    /** The Classic layout's parts in card order, for a builder's Classic Card Elements list. */
    public static function classicCardParts(): array
    {
        $parts = self::partsIn(static::all()['classic']['template']);

        // Builders draw their Classic card part by part, so Quick View keeps its old place at the end.
        return array_values(array_merge(array_diff($parts, ['quick_view']), ['quick_view']));
    }

    /** The cardParts key a block draws, or null. */
    private static function partOf(string $blockName): ?string
    {
        $part = array_search($blockName, self::CARD_PARTS, true);

        return $part !== false ? $part : (self::CLASSIC_PART_BLOCKS[$blockName] ?? null);
    }

    /** A layout's own settings for one part's block (cardParts key), as its template sets them. */
    public static function partAttributes(string $layout, string $part): array
    {
        $presets = static::all();
        if (!isset($presets[$layout], self::CARD_PARTS[$part])) {
            return [];
        }
        $find = function (array $nodes) use (&$find, $part) {
            foreach ($nodes as $node) {
                if (self::partOf($node[0]) === $part) {
                    return (array) ($node[1] ?? []);
                }
                if (($found = $find($node[2] ?? [])) !== null) {
                    return $found;
                }
            }
            return null;
        };

        return (array) $find($presets[$layout]['template']);
    }

    /**
     * A Rating, Swatches or Quick View block for a builder's own card: the Classic layout's
     * settings with the builder's over them. Null for any other part.
     */
    public static function partBlock(string $part, array $settings = []): ?array
    {
        $sanitizers = ['rating' => 'ratingAttributes', 'swatches' => 'swatchAttributes', 'quick_view' => 'quickViewAttributes'];
        if (!isset($sanitizers[$part])) {
            return null;
        }
        $attributes = array_merge(static::partAttributes('classic', $part), static::{$sanitizers[$part]}($settings));

        return parse_blocks(get_comment_delimited_block_content(self::CARD_PARTS[$part], $attributes, ''))[0];
    }

    private static function partsIn(array $nodes): array
    {
        $parts = [];
        foreach ($nodes as $node) {
            $part = self::partOf($node[0]);
            // A part can hold another, as the image holds Quick View.
            $parts = array_merge($parts, $part !== null ? [$part] : [], self::partsIn($node[2] ?? []));
        }

        return array_values(array_unique($parts));
    }

    /** The listed cardParts keys, in their order; unknown and repeated ones are left out. */
    private static function cardElements($elements): array
    {
        if (!is_array($elements)) {
            return [];
        }
        $parts = array_map(function ($part) {
            return is_scalar($part) ? sanitize_key((string) $part) : '';
        }, array_slice(array_values($elements), 0, 2 * count(self::CARD_PARTS)));

        return array_values(array_unique(array_intersect($parts, array_keys(self::CARD_PARTS))));
    }

    /** The rating block's thresholds from a builder, kept in range. */
    private static function ratingAttributes(array $settings): array
    {
        $attributes = [];
        if (isset($settings['minReviewCount']) && is_numeric($settings['minReviewCount'])) {
            $attributes['minReviewCount'] = max(0, min(100000, (int) $settings['minReviewCount']));
        }
        if (isset($settings['minAverageRating']) && is_numeric($settings['minAverageRating'])) {
            $attributes['minAverageRating'] = max(0.0, min(5.0, (float) $settings['minAverageRating']));
        }

        return $attributes;
    }

    /** The Quick View block's placement from a builder; unknown values keep the layout's. */
    private static function quickViewAttributes(array $settings): array
    {
        $choices = [
            'position' => ProductQuickViewBlockEditor::POSITIONS,
            'show_on'  => ProductQuickViewBlockEditor::SHOW_ON,
            'display'  => ProductQuickViewBlockEditor::DISPLAYS,
            'image_position' => ProductQuickViewBlockEditor::IMAGE_POSITIONS,
        ];
        $attributes = [];
        foreach ($choices as $key => $allowed) {
            if (isset($settings[$key]) && in_array($settings[$key], $allowed, true)) {
                $attributes[$key] = $settings[$key];
            }
        }

        return $attributes;
    }

    /** A badge block's own settings from a builder; unknown values keep the block's. */
    private static function badgeAttributes(array $settings, bool $isSale): array
    {
        $text = function ($key) use ($settings) {
            return isset($settings[$key]) && is_scalar($settings[$key]) ? sanitize_text_field((string) $settings[$key]) : '';
        };
        $attributes = [];
        $keys = $isSale ? ['badge_text', 'percentage_text'] : ['badge_text'];
        foreach ($keys as $key) {
            if ($text($key) !== '') {
                $attributes[$key] = $text($key);
            }
        }
        $choices = [
            'badge_style'    => ['badge', 'ribbon', 'tag'],
            'badge_position' => ['top-left', 'top-right', 'bottom-left', 'bottom-right'],
        ];
        if ($isSale) {
            $choices['price_source'] = ['default_variant', 'best_discount'];
            if (array_key_exists('show_percentage', $settings)) {
                $attributes['show_percentage'] = filter_var($settings['show_percentage'], FILTER_VALIDATE_BOOLEAN);
            }
        }
        foreach ($choices as $key => $allowed) {
            if (in_array($text($key), $allowed, true)) {
                $attributes[$key] = $text($key);
            }
        }

        return $attributes;
    }

    /**
     * layout_options from a shortcode's comma list of parts to leave out, e.g. "sale_badge,rating".
     * Shortcode attributes cannot carry JSON, so builders that render through a shortcode use this.
     */
    public static function hiddenCardParts(string $list): array
    {
        $options = [];
        foreach (array_unique(array_map('sanitize_key', array_slice(explode(',', $list), 0, 5))) as $part) {
            if (in_array($part, ['swatches', 'sale_badge', 'sold_out_badge', 'rating', 'quick_view'], true)) {
                $options[$part] = false;
            }
        }

        return $options;
    }

    /** The card attributes a card layout shortcode takes; see shortcodeCardOptions(). */
    public const SHORTCODE_CARD_ATTRIBUTES = [
        'masonry'             => '',
        'layout_hide'         => '',
        'swatch_count'        => '',
        'swatch_selected_name' => '',
        'swatch_group_name'   => '',
        'swatch_sold_out'     => '',
        'quick_view'          => '',
    ];

    /**
     * layout_options from a shortcode's card attributes; an empty or unknown value keeps the layout's own:
     * swatch_count (1-12, or 0 / all for every option), swatch_selected_name and swatch_group_name (yes/no), swatch_sold_out (normal, cross,
     * hide), quick_view (image, button), masonry (yes) and layout_hide (see hiddenCardParts()).
     */
    public static function shortcodeCardOptions(array $atts): array
    {
        $value = function ($key) use ($atts) {
            return strtolower(trim((string) Arr::get($atts, $key, '')));
        };
        $yesNo = function ($key) use ($value) {
            return $value($key) === '' ? null : filter_var($value($key), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        };

        // Raw settings: withCardOptions() clamps and validates them, for every page.
        $swatches = [];
        if ($value('swatch_count') === 'all') {
            $swatches['max_visible'] = $swatches['simple_max_visible'] = 0;
        } elseif (is_numeric($value('swatch_count'))) {
            // Only a real 0 is All; a fraction such as 0.5 would cast to 0, so it counts as one option.
            $count = (float) $value('swatch_count') == 0 ? 0 : max(1, (int) $value('swatch_count'));
            $swatches['max_visible'] = $swatches['simple_max_visible'] = $count;
        }
        if ($yesNo('swatch_selected_name') !== null) {
            $swatches['show_label'] = $yesNo('swatch_selected_name');
        }
        if ($yesNo('swatch_group_name') !== null) {
            $swatches['show_group_name'] = $swatches['simple_show_label'] = $yesNo('swatch_group_name');
        }
        if ($value('swatch_sold_out') !== '') {
            $swatches['sold_out_display'] = $value('swatch_sold_out');
        }

        $options = [];
        if ($swatches) {
            $options['swatches'] = $swatches;
        }
        if ($value('quick_view') !== '') {
            $options['quick_view'] = ['position' => $value('quick_view')];
        }
        if ($yesNo('masonry')) {
            $options['masonry'] = true;
        }

        // A part left out wins over its settings.
        return array_merge($options, static::hiddenCardParts($value('layout_hide')));
    }

    private static function applyCardOptions(array $block, array $drop, array $attributes, array $order): array
    {
        if (!empty($attributes[$block['blockName']])) {
            $block['attrs'] = array_merge((array) $block['attrs'], $attributes[$block['blockName']]);
        }

        // innerContent holds a null where each inner block renders, so a dropped block loses its slot too.
        $innerBlocks = [];
        $innerContent = [];
        $index = 0;
        foreach ((array) $block['innerContent'] as $chunk) {
            if ($chunk !== null) {
                $innerContent[] = $chunk;
                continue;
            }
            $child = $block['innerBlocks'][$index++];
            if (in_array($child['blockName'], $drop, true)) {
                continue;
            }
            $hadChildren = !empty($child['innerBlocks']);
            $child = self::applyCardOptions($child, $drop, $attributes, $order);
            // An area whose parts are all left out would leave an empty box in the card.
            if ($hadChildren && !$child['innerBlocks'] && $child['blockName'] === 'core/group') {
                continue;
            }
            $innerBlocks[] = $child;
            $innerContent[] = null;
        }
        $block['innerBlocks'] = $order ? self::orderParts($innerBlocks, $order) : $innerBlocks;
        $block['innerContent'] = $innerContent;

        return $block;
    }

    /** Sorts an area's parts by the builder's list; areas and other blocks keep their places. */
    private static function orderParts(array $children, array $order): array
    {
        $rank = array_flip($order);
        $slots = [];
        $parts = [];
        foreach ($children as $index => $child) {
            $part = self::partOf((string) $child['blockName']);
            if ($part !== null && isset($rank[$part])) {
                $slots[] = $index;
                $parts[$rank[$part]] = $child;
            }
        }
        ksort($parts);
        foreach (array_values($parts) as $position => $child) {
            $children[$slots[$position]] = $child;
        }

        return $children;
    }

    /** A swatch count as the swatches block saves it: exactly 0 is All, anything else 1-12. */
    private static function swatchCount($value): int
    {
        return (string) $value === '0' ? 0 : max(1, min(12, (int) $value));
    }

    /** The swatch block's own settings from a builder, typed as the block saves them. */
    private static function swatchAttributes(array $settings): array
    {
        $attributes = [];
        foreach (['max_visible', 'simple_max_visible'] as $key) {
            if (array_key_exists($key, $settings)) {
                $attributes[$key] = self::swatchCount($settings[$key]);
            }
        }
        foreach (['show_label', 'show_group_name', 'advanced_enabled', 'simple_enabled', 'simple_show_label'] as $key) {
            if (array_key_exists($key, $settings)) {
                $attributes[$key] = filter_var($settings[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }
        foreach (['sold_out_display', 'simple_sold_out_display'] as $key) {
            if (in_array(Arr::get($settings, $key), ['normal', 'cross', 'hide'], true)) {
                $attributes[$key] = $settings[$key];
            }
        }
        // These two have no block default: unset keeps the old rule, so an empty value is left out.
        foreach (['show_other_groups', 'simple_show_selected'] as $key) {
            if (isset($settings[$key]) && $settings[$key] !== '') {
                $attributes[$key] = filter_var($settings[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }
        foreach (['selected_position', 'simple_selected_position'] as $key) {
            if (in_array(Arr::get($settings, $key), ['name', 'below'], true)) {
                $attributes[$key] = $settings[$key];
            }
        }
        if (array_key_exists('hidden_group_ids', $settings)) {
            // Bounded as the swatch renderer bounds it.
            $hidden = array_map('absint', array_slice((array) $settings['hidden_group_ids'], 0, 1000));
            $attributes['hidden_group_ids'] = array_values(array_unique(array_filter($hidden)));
        }
        if (array_key_exists('simple_label', $settings)) {
            $attributes['simple_label'] = sanitize_text_field((string) $settings['simple_label']);
        }
        if (array_key_exists('group_ids', $settings)) {
            $maxGroups = ShopAttributeFilter::maxGroups();
            $ids = array_map('absint', array_slice((array) $settings['group_ids'], 0, $maxGroups * 2));
            $attributes['group_ids'] = array_slice(array_values(array_unique(array_filter($ids))), 0, $maxGroups);
        }

        return $attributes;
    }

    // What layout cards need when none are on the first page: AJAX pages and filters bring no assets.
    public static function enqueueAssets(): void
    {
        \FluentCart\App\Vite::enqueueStyle('fluentcart-card-layouts', 'public/product-card/style/card-layouts.scss');
        \FluentCart\App\Vite::enqueueStyle(
            'fluent-cart-product-quick-view',
            'admin/BlockEditor/ProductQuickView/style/product-quick-view-block-editor.scss'
        );
        ProductCardSwatchesRenderer::enqueueAssets();
    }

    private static function templateMarkup(array $nodes, bool $onlyRegistered = true): string
    {
        $registry = \WP_Block_Type_Registry::get_instance();
        $html = '';
        foreach ($nodes as $node) {
            // Blocks from an inactive plugin (the wishlist) are left out, as the editor does.
            if ($onlyRegistered && !$registry->is_registered($node[0])) {
                continue;
            }
            $attributes = (array) ($node[1] ?? []);
            $inner = self::templateMarkup($node[2] ?? [], $onlyRegistered);
            if ($node[0] === 'core/group') {
                $inner = '<div class="wp-block-group ' . esc_attr($attributes['className'] ?? '') . '">' . $inner . '</div>';
            }
            $html .= get_comment_delimited_block_content($node[0], $attributes, $inner);
        }
        return $html;
    }

    // A layout card's parts a builder can leave out or reorder, by the block that draws each.
    private const CARD_PARTS = [
        'image'      => 'fluent-cart/shopapp-product-image',
        'quick_view' => 'fluent-cart/product-quick-view',
        'category'   => 'core/post-terms',
        'rating'     => 'fluent-cart/product-rating',
        'title'      => 'fluent-cart/shopapp-product-title',
        'swatches'   => 'fluent-cart/product-swatches',
        'price'      => 'fluent-cart/shopapp-product-price',
        'button'     => 'fluent-cart/shopapp-product-buttons',
    ];

    // The Classic layout draws these parts with the standard card's blocks.
    private const CLASSIC_PART_BLOCKS = [
        'fluent-cart/product-image' => 'image',
        'fluent-cart/product-title' => 'title',
        'fluent-cart/price-range'   => 'price',
    ];

    // The grid the card layouts are designed for.
    private const GRID = ['view_mode' => 'grid', 'product_box_grid_size' => 3];

    // The chosen option's name above the options: "OPTION · 500 G" for simple variations, "LIGHT" for attributes.
    private const DEFAULT_SWATCHES = ['show_label' => true, 'show_group_name' => false, 'simple_show_label' => true];

    // Two options with "OPTION · 6 EGGS" above them.
    private const COMPACT_SWATCHES = ['max_visible' => 2, 'simple_max_visible' => 2, 'show_label' => true, 'show_group_name' => true, 'simple_show_label' => true];

    // Two options alone, without a caption.
    private const FLOATING_SWATCHES = ['max_visible' => 2, 'simple_max_visible' => 2, 'show_label' => false, 'show_group_name' => false, 'simple_show_label' => false];

    // Two options and only the chosen one's name as the caption; the rest collapse into "+N".
    // simple_show_label is the simple variations' group name ("Option"), so it is off like show_group_name.
    private const FRAMED_SWATCHES = ['max_visible' => 2, 'simple_max_visible' => 2, 'show_label' => true, 'show_group_name' => false, 'simple_show_label' => false];

    private const QUICK_VIEW_BESIDE_BUTTON = ['position' => 'button', 'display' => 'icon'];

    // An eye and "Quick View" on the picture, shown on hover.
    private const QUICK_VIEW_ON_IMAGE = ['position' => 'image', 'show_on' => 'hover', 'display' => 'icon_text'];

    private static function group(string $className, array $children): array
    {
        // Named for the editor's list view, where every group would otherwise read "Group".
        $names = [
            'fct-card-layout-media'   => __('Card Image', 'fluent-cart'),
            'fct-card-layout-details' => __('Card Details', 'fluent-cart'),
            'fct-card-layout-meta'    => __('Card Meta', 'fluent-cart'),
            'fct-card-layout-footer'  => __('Card Footer', 'fluent-cart'),
        ];
        $attributes = [
            'className' => $className,
            'layout' => ['type' => 'default'],
        ];
        if (isset($names[$className])) {
            $attributes['metadata'] = ['name' => $names[$className]];
        }
        return ['core/group', $attributes, $children];
    }

    /**
     * @param array $footerQuickView Quick View settings for one right after the button, or [] for none.
     * @param array $mediaQuickView  Quick View settings for one in the image block, or [] for none.
     */
    private static function template(string $layout, array $swatches, array $footerQuickView = [], array $mediaQuickView = []): array
    {
        $media = self::group('fct-card-layout-media', [
            ['fluent-cart/shopapp-product-image', (object) [], array_merge([
                ['fluent-cart/sale-badge'],
                ['fluent-cart/sold-out-badge'],
                ['fluent-cart-wishlist/wishlist-button', ['buttonPosition' => 'image-overlay-top-right']],
            ], $mediaQuickView ? [['fluent-cart/product-quick-view', $mediaQuickView]] : [])],
        ]);
        $meta = self::group('fct-card-layout-meta', [
            ['core/post-terms', ['term' => 'product-categories', 'className' => 'fct-card-layout-category', 'separator' => ' ']],
            ['fluent-cart/product-rating'],
        ]);
        $footer = self::group('fct-card-layout-footer', array_merge([
            ['fluent-cart/shopapp-product-price'],
            ['fluent-cart/shopapp-product-buttons'],
        ], $footerQuickView ? [['fluent-cart/product-quick-view', $footerQuickView]] : []));
        $details = self::group('fct-card-layout-details', [
            $meta,
            ['fluent-cart/shopapp-product-title'],
            ['fluent-cart/product-swatches', $swatches],
            $footer,
        ]);

        return [self::group('fct-card-layout fct-card-layout--' . $layout, [$media, $details])];
    }
}

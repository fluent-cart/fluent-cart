<?php

namespace FluentCart\App\Modules\Templating\Bricks;

use FluentCart\App\Models\AttributeTerm;
use FluentCart\App\Services\Filter\ShopAttributeFilter;
use FluentCart\App\Services\Renderer\ProductCardLayoutPresets;
use FluentCart\Framework\Support\Arr;

/**
 * Card layouts in Bricks elements, here and in the Bricks add-on. "Classic" is the element's own
 * card, saved as '' or 'classic'; core's Classic preset is not offered. A layout has the Classic
 * card's settings with its own values: a Card Elements list per layout and a copy of each badge
 * setting. Its badges are on for a new element (Bricks saves a checkbox's default when the element
 * is added and drops the setting when it is unticked).
 */
class LayoutCardControls
{
    /** The element's own card. */
    const ELEMENT_CARD = 'classic';

    /** Tells an add-on that core has the shared Swatches panel (Gutenberg's). */
    const SHARED_SWATCH_PANEL = true;

    /** A layout's badge Show toggle => ProductCardRender layout_options key. */
    const TOGGLES = [
        'layoutShowSaleBadge'    => 'sale_badge',
        'layoutShowSoldOutBadge' => 'sold_out_badge',
    ];

    /** The Classic card's badge settings => the badge block's own settings. */
    const BADGE_SETTINGS = [
        'sale_badge'     => [
            'saleBadgeText'           => 'badge_text',
            'saleBadgeShowPercentage' => 'show_percentage',
            'saleBadgePercentageText' => 'percentage_text',
            'saleBadgePriceSource'    => 'price_source',
            'saleBadgeShape'          => 'badge_style',
            'saleBadgePosition'       => 'badge_position',
        ],
        'sold_out_badge' => [
            'soldOutBadgeText'     => 'badge_text',
            'soldOutBadgeShape'    => 'badge_style',
            'soldOutBadgePosition' => 'badge_position',
        ],
    ];

    /** A required condition for the Classic card ('' or 'classic'). */
    public static function classicOnly(): array
    {
        return ['cardLayout', '=', ['', self::ELEMENT_CARD]];
    }

    /** A required condition for core's layouts. */
    public static function layoutOnly(): array
    {
        return ['cardLayout', '!=', ['', self::ELEMENT_CARD]];
    }

    /** A control's required condition(s) with the Classic-card condition added. */
    public static function withClassicOnly($required): array
    {
        return self::withCondition($required, self::classicOnly());
    }

    /**
     * Keeps the Classic card's settings in these groups to the Classic card, as a layout draws its
     * own parts; a layout shows only its Show toggle there.
     */
    public static function classicOnlyInGroups(array $controls, array $groups): array
    {
        foreach ($controls as $key => $control) {
            if (in_array(Arr::get($control, 'group'), $groups, true) && !isset(self::TOGGLES[$key])) {
                $controls[$key]['required'] = self::withClassicOnly(Arr::get($control, 'required'));
            }
        }

        return $controls;
    }

    /**
     * A layout's copy of each Classic badge setting in these groups, with the same defaults
     * ("saleBadgeText" is "layoutSaleBadgeText"). The Classic settings stay the Classic card's.
     */
    public static function layoutBadgeControls(array $controls, array $groups): array
    {
        $keys = [];
        foreach ($controls as $key => $control) {
            if (in_array(Arr::get($control, 'group'), $groups, true) && strpos($key, 'layout') !== 0) {
                $keys[] = $key;
            }
        }

        $copies = [];
        foreach ($keys as $key) {
            $required = Arr::get($controls[$key], 'required');
            $copy = $controls[$key];
            $copy['required'] = self::withCondition(self::renamedConditions($required, $keys), self::layoutOnly());
            if (in_array($key, ['showSaleBadge', 'showSoldOutBadge'], true)) {
                $copy['default'] = true;
            }
            $copies[self::layoutKey($key)] = $copy;
            $controls[$key]['required'] = self::withClassicOnly($required);
        }

        return $controls + $copies;
    }

    /** A Card Elements list for each layout, holding its own parts in its own order. */
    public static function elementsControls(string $group): array
    {
        $controls = [];
        foreach (ProductCardLayoutPresets::all() as $layout => $preset) {
            $parts = ProductCardLayoutPresets::cardParts($layout);
            // A builder-only layout (Masonry) is a switch, so it has no Card Elements list.
            if ($parts && empty($preset['builder_only'])) {
                $controls['layoutElements_' . $layout] = self::elementsControl($layout, $parts, $group);
            }
        }

        return $controls;
    }

    private static function elementsControl(string $layout, array $parts, string $group): array
    {
        $labels = [
            'image'      => esc_html__('Image', 'fluent-cart'),
            'quick_view' => esc_html__('Quick View', 'fluent-cart'),
            'category'   => esc_html__('Category', 'fluent-cart'),
            'rating'     => esc_html__('Rating', 'fluent-cart'),
            'title'      => esc_html__('Title', 'fluent-cart'),
            'swatches'   => esc_html__('Swatches', 'fluent-cart'),
            'price'      => esc_html__('Price', 'fluent-cart'),
            'button'     => esc_html__('Button', 'fluent-cart'),
        ];

        return [
            'tab'           => 'content',
            'group'         => $group,
            'label'         => esc_html__('Card Elements', 'fluent-cart'),
            'description'   => esc_html__('Each element moves within its own area of the card. Empty shows them all.', 'fluent-cart'),
            'type'          => 'repeater',
            'titleProperty' => 'element_type',
            'fields'        => [
                'element_type' => [
                    'type'    => 'select',
                    'label'   => esc_html__('Element', 'fluent-cart'),
                    'options' => array_intersect_key(array_replace(array_flip($parts), $labels), array_flip($parts)),
                ],
            ],
            'default'       => array_map(function ($part) {
                return ['element_type' => $part];
            }, $parts),
            'required'      => ['cardLayout', '=', $layout],
            'rerender'      => true,
        ];
    }

    /** Content and Style groups for a layout's Swatches, Product Rating and Quick View. */
    public static function partGroups(array $classicParts = []): array
    {
        $titles = [
            'swatches'   => esc_html__('Swatches', 'fluent-cart'),
            'rating'     => esc_html__('Product Rating', 'fluent-cart'),
            'quick_view' => esc_html__('Quick View', 'fluent-cart'),
        ];

        $groups = [];
        foreach ($titles as $part => $title) {
            $layouts = self::layoutsWith($part, $classicParts);
            if (!$layouts) {
                continue;
            }
            // The Classic card is saved as '' too.
            $required = ['cardLayout', '=', in_array(self::ELEMENT_CARD, $layouts, true) ? array_merge([''], $layouts) : $layouts];
            $groups[self::partGroup($part)] = ['title' => $title, 'tab' => 'content', 'required' => $required];
            $groups[self::partGroup($part) . 'Style'] = ['title' => $title, 'tab' => 'style', 'required' => $required];
        }

        return $groups;
    }

    /**
     * Each layout's own copy of its Swatches, Product Rating and Quick View settings, pre-filled
     * with that layout's values ("layoutSwatchesMaxVisible_compact"), and their style settings.
     * $classicParts are the parts the element's own card can draw; they get the Classic layout's.
     */
    public static function partControls(array $classicParts = []): array
    {
        $controls = [];
        // Bricks builds controls for every element it renders on the storefront too: one query a request.
        static $groupOptions = null;
        foreach (self::partSettings() as $part => $entries) {
            foreach (self::layoutsWith($part, $classicParts) as $layout) {
                $values = ProductCardLayoutPresets::partAttributes($layout, $part);
                foreach ($entries as $name => $entry) {
                    $control = $entry['control'] + ['tab' => 'content', 'group' => self::partGroup($part)];
                    if (!empty($entry['groupOptions'])) {
                        if ($groupOptions === null) {
                            $groupOptions = self::groupOptionsForPicker();
                        }
                        $control['options'] = $groupOptions;
                    }
                    $conditions = [$layout === self::ELEMENT_CARD ? self::classicOnly() : ['cardLayout', '=', $layout]];
                    // Shown, as in Gutenberg, only while the switches above them are on.
                    foreach ((array) ($entry['whenOn'] ?? []) as $switch) {
                        $conditions[] = [self::partKey($part, $switch, $layout), '=', true];
                    }
                    if (!empty($entry['unlessOn'])) {
                        $conditions[] = [self::partKey($part, $entry['unlessOn'], $layout), '!=', true];
                    }
                    if (!empty($entry['whenEmpty'])) {
                        $conditions[] = [self::partKey($part, $entry['whenEmpty'], $layout), '=', ''];
                    }
                    if (!empty($entry['onImage'])) {
                        // Not saved yet reads as the layout's own position.
                        $onImage = ($values['position'] ?? '') === 'image' ? ['!=', 'button'] : ['=', 'image'];
                        $conditions[] = [self::partKey($part, 'Position', $layout), $onImage[0], $onImage[1]];
                    }
                    $control['required'] = count($conditions) === 1 ? $conditions[0] : $conditions;
                    if (isset($entry['allOf'])) {
                        // All: the layout's count is 0.
                        $control['default'] = ($values[$entry['allOf']] ?? null) === 0;
                        $control['rerender'] = true;
                    } elseif (isset($entry['attribute'])) {
                        $value = $values[$entry['attribute']] ?? (isset($entry['defaultFrom']) ? ($values[$entry['defaultFrom']] ?? $entry['default']) : $entry['default']);
                        if (!empty($entry['checkbox'])) {
                            $value = (bool) $value;
                        }
                        $control['default'] = $value;
                        // The placeholder names the layout's value, shown while the setting is empty.
                        if (empty($entry['checkbox']) && !is_array($value) && !isset($control['placeholder'])) {
                            $control['placeholder'] = (string) ($control['options'][$value] ?? $value);
                        }
                        $control['rerender'] = true;
                    }
                    $controls[self::partKey($part, $name, $layout)] = $control;
                }
            }
        }

        return $controls + self::partStyleControls();
    }

    /**
     * The Classic card's Card Elements list, which draws core's Classic layout in any order. A new
     * element starts with it; an element saved before, or one whose list is emptied, has none.
     */
    public static function classicElementsControl(string $group): array
    {
        $control = self::elementsControl(self::ELEMENT_CARD, ProductCardLayoutPresets::classicCardParts(), $group);
        $control['required'] = self::classicOnly();
        $control['description'] = esc_html__('Empty uses the Fields group instead.', 'fluent-cart');

        return ['layoutElements_' . self::ELEMENT_CARD => $control];
    }

    /** The Classic card drawn from its Card Elements list, or '' for the element's own card. */
    public static function classicListSelected(array $settings): string
    {
        $rows = Arr::get($settings, 'layoutElements_' . self::ELEMENT_CARD);

        return is_array($rows) && $rows ? self::ELEMENT_CARD : '';
    }

    /** @return array<string, string> Classic, then core's other layouts, for the select's options. */
    public static function layoutOptions(): array
    {
        $options = [self::ELEMENT_CARD => esc_html__('Classic', 'fluent-cart')];
        foreach (ProductCardLayoutPresets::all() as $layout => $preset) {
            // A builder-only layout (Masonry) is a switch now, as in Gutenberg.
            if ($layout !== self::ELEMENT_CARD && empty($preset['builder_only'])) {
                $options[$layout] = (string) Arr::get($preset, 'label', $layout);
            }
        }

        return $options;
    }

    /** The saved layout when core can render it, else '' for the element's own card. */
    public static function selected($value): string
    {
        $layout = sanitize_key((string) $value);
        if ($layout === '' || $layout === self::ELEMENT_CARD) {
            return '';
        }

        return ProductCardLayoutPresets::cardBlock($layout) ? $layout : '';
    }

    /** ProductCardRender's layout_options from the selected layout's own settings. */
    /**
     * ProductCardRender's layout_options. $layout is the card drawn, when the element knows it:
     * 'classic' for its Classic Card Elements list, whose badges are the Classic card's own settings.
     */
    public static function cardOptions(array $settings, bool $withSoldOut = true, ?string $layout = null): array
    {
        $options = [];
        $layout = $layout ?? self::selected(Arr::get($settings, 'cardLayout', ''));
        // Classic's badge settings are its own; a layout's are copies ("layoutSaleBadgeText").
        $badgeKey = function ($key) use ($layout) {
            return $layout === self::ELEMENT_CARD ? $key : self::layoutKey($key);
        };
        $rows = $layout ? Arr::get($settings, 'layoutElements_' . $layout) : null;
        if (is_array($rows) && $rows) {
            $options['elements'] = array_values(array_filter(array_map(function ($row) {
                return is_array($row) && is_scalar(Arr::get($row, 'element_type')) ? (string) $row['element_type'] : '';
            }, $rows)));
        }

        foreach (['showSaleBadge' => 'sale_badge', 'showSoldOutBadge' => 'sold_out_badge'] as $toggle => $part) {
            if ($part === 'sold_out_badge' && !$withSoldOut) {
                continue;
            }
            if (empty($settings[$badgeKey($toggle)])) {
                $options[$part] = false;
                continue;
            }
            $badge = [];
            foreach (self::BADGE_SETTINGS[$part] as $setting => $attribute) {
                if (array_key_exists($badgeKey($setting), $settings)) {
                    $badge[$attribute] = $settings[$badgeKey($setting)];
                }
            }
            if ($badge) {
                $options[$part] = $badge;
            }
        }

        foreach (self::partSettings() as $part => $entries) {
            $values = $layout ? self::partValues($settings, $part, $layout, $entries) : [];
            if ($values) {
                $options[$part] = $values;
            }
        }

        return $options;
    }

    /**
     * A layout part's saved settings; a setting not saved keeps the layout's. Show/Hide is a select,
     * as Bricks drops an unticked checkbox and "off" would read as "never set".
     */
    private static function partValues(array $settings, string $part, string $layout, array $entries): array
    {
        $values = [];
        $all = [];
        foreach ($entries as $name => $entry) {
            $key = self::partKey($part, $name, $layout);
            $saved = array_key_exists($key, $settings);
            if (isset($entry['allOf'])) {
                if ($saved && !empty($settings[$key])) {
                    $all[] = $entry['allOf'];
                }
                continue;
            }
            if (!isset($entry['attribute'])) {
                continue;
            }
            if (!$saved) {
                // Bricks drops an unticked switch's key and shows a missing one as off, so a missing switch is off.
                if (!empty($entry['checkbox'])) {
                    $values[$entry['attribute']] = false;
                }
                continue;
            }
            $values[$entry['attribute']] = !empty($entry['checkbox']) ? !empty($settings[$key]) : $settings[$key];
            if (!empty($entry['alsoAttribute'])) {
                $values[$entry['alsoAttribute']] = $settings[$key];
            }
        }
        foreach ($all as $attribute) {
            $values[$attribute] = 0;
        }
        // Two selects can hold the same attribute: never wins, as its label says.
        if (!empty($values['group_ids']) && !empty($values['hidden_group_ids'])) {
            $hidden = array_map('intval', (array) $values['hidden_group_ids']);
            $values['group_ids'] = array_values(array_filter((array) $values['group_ids'], function ($id) use ($hidden) {
                return !in_array((int) $id, $hidden, true);
            }));
        }

        return $values;
    }

    /**
     * The attribute pickers' options. In the builder each says how many values it has, as Gutenberg's list does;
     * the storefront builds the same controls but never shows them, so it skips the count query.
     */
    private static function groupOptionsForPicker(): array
    {
        $options = array_column(ShopAttributeFilter::groupOptions(), 'title', 'id');
        $inBuilder = (function_exists('bricks_is_builder') && bricks_is_builder())
            || (function_exists('bricks_is_builder_call') && bricks_is_builder_call());
        if (!$inBuilder || !$options) {
            return $options;
        }
        // One grouped query over at most 100 groups, as the Gutenberg sidebar counts them.
        $counts = AttributeTerm::query()
            ->selectRaw('group_id, COUNT(*) AS total')
            ->whereIn('group_id', array_keys($options))
            ->groupBy('group_id')
            ->get()
            ->pluck('total', 'group_id')
            ->all();
        foreach ($options as $id => $title) {
            $count = (int) ($counts[$id] ?? 0);
            $hint = $count === 0
                ? esc_html__('No values', 'fluent-cart')
                /* translators: %d: how many values an attribute has */
                : sprintf(_n('%d value', '%d values', $count, 'fluent-cart'), $count);
            $options[$id] = $title . ' · ' . $hint;
        }

        return $options;
    }

    /** The element's own card's saved settings for one part, for ProductCardRender::renderCardPart(). */
    public static function classicPartSettings(array $settings, string $part): array
    {
        $entries = self::partSettings()[$part] ?? [];

        return $entries ? self::partValues($settings, $part, self::ELEMENT_CARD, $entries) : [];
    }

    /** The layouts whose card has this part, with Classic first when the element's own card draws it. */
    private static function layoutsWith(string $part, array $classicParts = []): array
    {
        $presets = ProductCardLayoutPresets::all();
        // A builder-only layout (Masonry) is a switch, not a layout, so it gets no settings of its own.
        $layouts = array_values(array_filter(array_keys($presets), function ($layout) use ($part, $presets) {
            return empty($presets[$layout]['builder_only']) && in_array($part, ProductCardLayoutPresets::cardParts($layout), true);
        }));

        return in_array($part, $classicParts, true) ? array_merge([self::ELEMENT_CARD], $layouts) : $layouts;
    }

    private static function partGroup(string $part): string
    {
        return 'layout' . str_replace('_', '', ucwords($part, '_'));
    }

    private static function partKey(string $part, string $name, string $layout): string
    {
        return self::partGroup($part) . $name . '_' . $layout;
    }

    /**
     * Each part's settings: name => attribute (the block's), the block's default, the control.
     * 'checkbox' is an on/off switch (a missing one is off); 'onImage' shows only on the picture.
     */
    private static function partSettings(): array
    {
        // Show up to: a number box of 1 to 12 (the renderer's cap), with All beside it, as in Gutenberg.
        $count = [
            'type'        => 'number',
            'label'       => esc_html__('Show up to', 'fluent-cart'),
            'min'         => 1,
            'max'         => 12,
            'description' => esc_html__('Extras show as "+2", "+3"…', 'fluent-cart'),
        ];
        $all = ['type' => 'checkbox', 'label' => esc_html__('All', 'fluent-cart')];
        $soldOut = [
            'type'    => 'select',
            'label'   => esc_html__('Sold-out options', 'fluent-cart'),
            'options' => ['normal' => esc_html__('No change', 'fluent-cart'), 'cross' => esc_html__('Cross out', 'fluent-cart'), 'hide' => esc_html__('Hide', 'fluent-cart')],
            'inline'  => true,
        ];
        $position = [
            'type'    => 'select',
            'label'   => esc_html__('Where it appears', 'fluent-cart'),
            'options' => ['name' => esc_html__('After the label', 'fluent-cart'), 'below' => esc_html__('Below the options', 'fluent-cart')],
            'inline'  => true,
        ];
        $toggle = function ($label, $description = '') {
            return array_filter([
                'type'        => 'select',
                'label'       => $label,
                'options'     => ['show' => esc_html__('Show', 'fluent-cart'), 'hide' => esc_html__('Hide', 'fluent-cart')],
                'inline'      => true,
                'description' => $description,
            ]);
        };

        return [
            // Gutenberg's swatches panel: its words, its order and, where Bricks has one, its input.
            'swatches'   => [
                // Gutenberg's two tabs, stacked: each type opens with its heading and its switch.
                'AdvancedHeading'    => ['control' => ['type' => 'separator', 'label' => esc_html__('Attribute products', 'fluent-cart')]],
                'Advanced'           => ['attribute' => 'advanced_enabled', 'default' => true, 'checkbox' => true, 'control' => ['type' => 'checkbox', 'label' => esc_html__('Show attributes on the card', 'fluent-cart')]],
                'WhichHeading'       => ['whenOn' => 'Advanced', 'control' => [
                    'type'        => 'separator',
                    'label'       => esc_html__('Which attributes to show', 'fluent-cart'),
                    'description' => esc_html__('Pick to show; rows follow the order you pick.', 'fluent-cart'),
                ]],
                // Options filled in partControls(), so reading saved settings never queries the groups.
                'GroupIds'           => ['attribute' => 'group_ids', 'default' => [], 'groupOptions' => true, 'whenOn' => 'Advanced', 'control' => [
                    'type'        => 'select',
                    'label'       => '',
                    'multiple'    => true,
                    'searchable'  => true,
                    'placeholder' => esc_html__('+ Add another attribute', 'fluent-cart'),
                ]],
                'ShowOtherGroups'    => ['attribute' => 'show_other_groups', 'default' => true, 'checkbox' => true, 'whenOn' => 'Advanced', 'control' => [
                    'type'        => 'checkbox',
                    'label'       => esc_html__('Also show attributes not listed here', 'fluent-cart'),
                    'description' => esc_html__('Any other attribute a product has shows after the ones above.', 'fluent-cart'),
                ]],
                // Gutenberg's unticked rows: they stay off the card even when the other attributes are added.
                'HiddenGroups'       => ['attribute' => 'hidden_group_ids', 'default' => [], 'groupOptions' => true, 'whenOn' => ['Advanced', 'ShowOtherGroups'], 'control' => [
                    'type'        => 'select',
                    'label'       => esc_html__('Never show these attributes', 'fluent-cart'),
                    'description' => esc_html__('They stay off the card even when the other attributes are added.', 'fluent-cart'),
                    'multiple'    => true,
                    'searchable'  => true,
                    'placeholder' => esc_html__('+ Add an attribute to leave out', 'fluent-cart'),
                ]],
                // Gutenberg's warning: nothing picked and the others not added leaves the cards with no options.
                'NoneWarning'        => ['whenOn' => 'Advanced', 'unlessOn' => 'ShowOtherGroups', 'whenEmpty' => 'GroupIds', 'control' => [
                    'type'    => 'info',
                    'content' => esc_html__('Pick at least one attribute, or cards will show no options.', 'fluent-cart'),
                ]],
                'CardHeading'        => ['whenOn' => 'Advanced', 'control' => ['type' => 'separator', 'label' => esc_html__('How it shows on the card', 'fluent-cart')]],
                'MaxVisible'         => ['attribute' => 'max_visible', 'default' => 3, 'whenOn' => 'Advanced', 'unlessOn' => 'MaxVisibleAll', 'control' => $count],
                'MaxVisibleAll'      => ['allOf' => 'max_visible', 'whenOn' => 'Advanced', 'control' => $all],
                'GroupName'          => ['attribute' => 'show_group_name', 'default' => false, 'checkbox' => true, 'whenOn' => 'Advanced', 'control' => [
                    'type'        => 'checkbox',
                    'label'       => esc_html__('Show label', 'fluent-cart'),
                    'description' => esc_html__('e.g. Color above the row', 'fluent-cart'),
                ]],
                'SelectedName'       => ['attribute' => 'show_label', 'default' => false, 'checkbox' => true, 'whenOn' => 'Advanced', 'control' => [
                    'type'        => 'checkbox',
                    'label'       => esc_html__('Show the selected choice', 'fluent-cart'),
                    'description' => esc_html__('e.g. Navy', 'fluent-cart'),
                ]],
                'SelectedPosition'   => ['attribute' => 'selected_position', 'default' => 'name', 'whenOn' => ['Advanced', 'SelectedName'], 'control' => $position],
                'SimpleHeading'      => ['control' => ['type' => 'separator', 'label' => esc_html__('List products', 'fluent-cart')]],
                'Simple'             => ['attribute' => 'simple_enabled', 'default' => true, 'checkbox' => true, 'control' => ['type' => 'checkbox', 'label' => esc_html__('Show the option list on the card', 'fluent-cart')]],
                'SimpleCardHeading'  => ['whenOn' => 'Simple', 'control' => ['type' => 'separator', 'label' => esc_html__('How it shows on the card', 'fluent-cart')]],
                'SimpleMaxVisible'   => ['attribute' => 'simple_max_visible', 'default' => 4, 'whenOn' => 'Simple', 'unlessOn' => 'SimpleMaxVisibleAll', 'control' => $count],
                'SimpleMaxVisibleAll' => ['allOf' => 'simple_max_visible', 'whenOn' => 'Simple', 'control' => $all],
                'SimpleRowLabel'     => ['attribute' => 'simple_show_label', 'default' => false, 'checkbox' => true, 'whenOn' => 'Simple', 'control' => [
                    'type'        => 'checkbox',
                    'label'       => esc_html__('Show label', 'fluent-cart'),
                    'description' => esc_html__('e.g. Option above the row', 'fluent-cart'),
                ]],
                'SimpleRowLabelText' => ['attribute' => 'simple_label', 'default' => '', 'whenOn' => ['Simple', 'SimpleRowLabel'], 'control' => [
                    'type'          => 'text',
                    'hasDynamicData' => false,
                    'label'       => esc_html__('Label name', 'fluent-cart'),
                    'placeholder' => esc_html__('Option', 'fluent-cart'),
                    'description' => esc_html__('Empty shows "Option".', 'fluent-cart'),
                ]],
                // Gutenberg's list follows the attribute setting until it is set; a new element starts from it.
                'SimpleShowSelected' => ['attribute' => 'simple_show_selected', 'default' => false, 'defaultFrom' => 'show_label', 'checkbox' => true, 'whenOn' => 'Simple', 'control' => [
                    'type'        => 'checkbox',
                    'label'       => esc_html__('Show the selected choice', 'fluent-cart'),
                    'description' => esc_html__('e.g. 6 pods', 'fluent-cart'),
                ]],
                'SimpleSelectedPosition' => ['attribute' => 'simple_selected_position', 'default' => 'name', 'whenOn' => ['Simple', 'SimpleShowSelected'], 'control' => $position],
                // One setting for both product types, as Gutenberg's: it also writes the list's own key.
                'SoldOutDisplay'     => ['attribute' => 'sold_out_display', 'alsoAttribute' => 'simple_sold_out_display', 'default' => 'normal', 'control' => $soldOut],
            ],
            'rating'     => [
                'MinReviewCount'   => ['attribute' => 'minReviewCount', 'default' => 0, 'control' => [
                    'type'        => 'number',
                    'label'       => esc_html__('Minimum Reviews', 'fluent-cart'),
                    'min'         => 0,
                    'description' => esc_html__('0 shows the stars on every product, even one with no reviews.', 'fluent-cart'),
                ]],
                'MinAverageRating' => ['attribute' => 'minAverageRating', 'default' => 0, 'control' => [
                    'type'  => 'number',
                    'label' => esc_html__('Minimum Average Rating', 'fluent-cart'),
                    'min'   => 0,
                    'max'   => 5,
                    'step'  => 0.5,
                ]],
            ],
            'quick_view' => [
                'Position' => ['attribute' => 'position', 'default' => 'button', 'control' => [
                    'type'        => 'select',
                    'label'       => esc_html__('Position', 'fluent-cart'),
                    'options'     => ['image' => esc_html__('On the Image', 'fluent-cart'), 'button' => esc_html__('Beside the Button', 'fluent-cart')],
                    'description' => esc_html__('Beside the button it is always shown, as an icon.', 'fluent-cart'),
                ]],
                'ShowOn'   => ['attribute' => 'show_on', 'default' => 'always', 'onImage' => true, 'control' => [
                    'type'    => 'select',
                    'label'   => esc_html__('Show', 'fluent-cart'),
                    'options' => ['always' => esc_html__('Always', 'fluent-cart'), 'hover' => esc_html__('On Hover', 'fluent-cart')],
                    'inline'  => true,
                ]],
                'Display'  => ['attribute' => 'display', 'default' => 'icon', 'onImage' => true, 'control' => [
                    'type'    => 'select',
                    'label'   => esc_html__('Display', 'fluent-cart'),
                    'options' => ['icon' => esc_html__('Icon', 'fluent-cart'), 'icon_text' => esc_html__('Icon and Text', 'fluent-cart')],
                    'inline'  => true,
                ]],
                // Core's block has six corners on the image; the bottom middle is its default.
                'ImagePosition' => ['attribute' => 'image_position', 'default' => 'bottom-center', 'onImage' => true, 'control' => [
                    'type'    => 'select',
                    'label'   => esc_html__('Image Position', 'fluent-cart'),
                    'options' => [
                        'top-left'      => esc_html__('Top Left', 'fluent-cart'),
                        'top-center'    => esc_html__('Top Middle', 'fluent-cart'),
                        'top-right'     => esc_html__('Top Right', 'fluent-cart'),
                        'bottom-left'   => esc_html__('Bottom Left', 'fluent-cart'),
                        'bottom-center' => esc_html__('Bottom Middle', 'fluent-cart'),
                        'bottom-right'  => esc_html__('Bottom Right', 'fluent-cart'),
                    ],
                    'inline'  => true,
                ]],
            ],
        ];
    }

    /** Style settings for a layout's Swatches, Product Rating and Quick View; the same for every layout. */
    private static function partStyleControls(): array
    {
        $style = function ($group, $type, $label, $property, $selector) {
            return ['tab' => 'style', 'group' => $group, 'type' => $type, 'label' => $label, 'css' => [['property' => $property, 'selector' => $selector]]];
        };
        $stars = '.fct-product-card-rating .fct-star';
        $quickView = '.fct-card-quick-view-button';

        return [
            'layoutSwatchesTypography'        => $style('layoutSwatchesStyle', 'typography', esc_html__('Typography', 'fluent-cart'), 'font', '.fct-card-swatch'),
            'layoutSwatchesLabelTypography'   => $style('layoutSwatchesStyle', 'typography', esc_html__('Label Typography', 'fluent-cart'), 'font', '.fct-card-swatch-heading'),
            'layoutSwatchesBorder'            => $style('layoutSwatchesStyle', 'border', esc_html__('Border', 'fluent-cart'), 'border', '.fct-card-swatch'),
            'layoutRatingStarColor'           => $style('layoutRatingStyle', 'color', esc_html__('Star Color', 'fluent-cart'), 'color', $stars . '-filled, ' . $stars . '-half'),
            'layoutRatingEmptyStarColor'      => $style('layoutRatingStyle', 'color', esc_html__('Empty Star Color', 'fluent-cart'), 'color', $stars . ':not(.fct-star-filled):not(.fct-star-half)'),
            'layoutRatingCountTypography'     => $style('layoutRatingStyle', 'typography', esc_html__('Count Typography', 'fluent-cart'), 'font', '.fct-product-card-review-count'),
            'layoutQuickViewTypography'       => $style('layoutQuickViewStyle', 'typography', esc_html__('Typography', 'fluent-cart'), 'font', $quickView),
            'layoutQuickViewTextColor'        => $style('layoutQuickViewStyle', 'color', esc_html__('Text Color', 'fluent-cart'), 'color', $quickView),
            'layoutQuickViewBackgroundColor'  => $style('layoutQuickViewStyle', 'color', esc_html__('Background Color', 'fluent-cart'), 'background-color', $quickView),
            'layoutQuickViewBorder'           => $style('layoutQuickViewStyle', 'border', esc_html__('Border', 'fluent-cart'), 'border', $quickView),
        ];
    }

    private static function layoutKey(string $key): string
    {
        return 'layout' . ucfirst($key);
    }

    private static function withCondition($required, array $condition): array
    {
        if (empty($required) || !is_array($required)) {
            return $condition;
        }
        $conditions = is_array(reset($required)) ? $required : [$required];
        $conditions[] = $condition;

        return $conditions;
    }

    /** The conditions with each copied setting's key changed to its layout copy's. */
    private static function renamedConditions($required, array $keys)
    {
        if (empty($required) || !is_array($required)) {
            return $required;
        }
        $isList = is_array(reset($required));
        $conditions = $isList ? $required : [$required];
        foreach ($conditions as $index => $condition) {
            if (isset($condition[0]) && in_array($condition[0], $keys, true)) {
                $conditions[$index][0] = self::layoutKey($condition[0]);
            }
        }

        return $isList ? $conditions : $conditions[0];
    }
}

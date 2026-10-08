<?php

namespace FluentCart\App\Services\Theme\Readers;

use FluentCart\App\Services\Theme\ColorMath;
use FluentCart\App\Services\Theme\ThemePalette;
use FluentCart\Framework\Support\Arr;

/**
 * Blocksy's Customizer colours, read from its theme mods rather than its slots.
 *
 * Blocksy keeps each semantic colour as a theme mod shaped
 * `['default' => ['color' => …], 'hover' => ['color' => …]]`, whose colour
 * points at a palette slot (`var(--theme-palette-color-N)`) or holds a custom
 * colour. Links, Base Text, Borders, Site Background and Buttons are separate
 * settings — the button is not tied to the link colour — so each role is read
 * from its own mod.
 *
 * A mod the owner never saved, or a sub-key missing from one, takes Blocksy's
 * own default for it, the same fill `blocksy_get_colors()` applies before
 * printing (inc/dynamic-styles/global/all.php). A colour saved as Blocksy's
 * "inherit" sentinel (`CT_CSS_SKIP_RULE…`) counts as unset the same way.
 *
 * Palette references are resolved through
 * `blocksy_manager()->colors->get_color_palette()` (see paletteValues()), and
 * always kept live: Blocksy Companion's dark mode redefines the palette
 * properties under `:root[data-color-mode*="dark"]`, which a bare hex would
 * never follow.
 *
 * Radii (see radii()): Buttons → Border Radius (`buttonRadius`) and Form
 * Elements → Border Radius (`formFieldBorderRadius`). The product card radius
 * (`cardProductRadius`) is a WooCommerce setting Blocksy only prints with
 * WooCommerce, so it is not read.
 *
 * Verified against Blocksy 2.1.57.
 */
class BlocksySettingsReader implements ThemeSettingsReader, ThemeRadiusReader
{
    /**
     * Blocksy's defaults per mod and sub-key (inc/dynamic-styles/global/all.php
     * and background.php).
     *
     * @var array
     */
    protected static $defaults = [
        'linkColor'       => ['default' => 'var(--theme-palette-color-1)', 'hover' => 'var(--theme-palette-color-2)'],
        'fontColor'       => ['default' => 'var(--theme-palette-color-3)'],
        'border_color'    => ['default' => 'var(--theme-palette-color-5)'],
        'buttonColor'     => ['default' => 'var(--theme-palette-color-1)', 'hover' => 'var(--theme-palette-color-2)'],
        'buttonTextColor' => ['default' => '#ffffff', 'hover' => '#ffffff'],
        'site_background' => ['default' => 'var(--theme-palette-color-7)'],
    ];

    /**
     * Detected by Blocksy's own manager, not by theme name, so a child theme
     * or renamed folder keeps working.
     *
     * @return bool
     */
    public static function applies(): bool
    {
        return function_exists('blocksy_get_theme_mod')
            && function_exists('blocksy_manager')
            && is_object(blocksy_manager());
    }

    /**
     * @return array Role => hex or `var(--theme-palette-color-N, #hex)`.
     */
    public static function roles(): array
    {
        if (!self::applies()) {
            return [];
        }

        $roles = [
            'accent'  => self::read('linkColor'),
            'text'    => self::read('fontColor'),
            'border'  => self::read('border_color'),
            'surface' => self::surface(),
        ];

        // Blocksy states both halves of its button, resting and hover. The
        // hover belongs to the resting button: without one there is no hover.
        $button = self::buttonPair('default');

        if ($button) {
            $roles = array_merge($roles, $button, self::buttonPair('hover', 'button_hover_'));
        }

        return array_filter($roles, function ($value) {
            return $value !== '';
        });
    }

    /**
     * Each palette slot's property and current colour: `--theme-palette-color-N`
     * (or the slot's own `variable`) => hex. The same list Blocksy prints the
     * properties from and publishes to the editor palette.
     *
     * @return array Property => colour, as Blocksy saved it.
     */
    public static function paletteValues(): array
    {
        if (!self::applies()) {
            return [];
        }

        $manager = blocksy_manager();
        $colors = isset($manager->colors) ? $manager->colors : null;

        if (!is_object($colors) || !method_exists($colors, 'get_color_palette')) {
            return [];
        }

        $values = [];

        try {
            foreach ((array)$colors->get_color_palette() as $slot) {
                $variable = (string)Arr::get((array)$slot, 'variable', '');

                if ($variable !== '') {
                    $values['--' . ltrim($variable, '-')] = (string)Arr::get((array)$slot, 'color', '');
                }
            }
        } catch (\Throwable $e) {
            // Blocksy's API misbehaving means no palette values: references
            // stay bare and unmeasurable, which resolve() already refuses.
            return [];
        }

        return $values;
    }

    /**
     * One button half-pair: background from `buttonColor`, text from
     * `buttonTextColor`, both at the same state.
     *
     * A background that is set but unwritable (an rgba(), say) is not
     * replaced by a default — Blocksy is painting it, just not in a form
     * FluentCart can write — so the pair is omitted. A text that cannot be
     * written gets the colour that reads on the background.
     *
     * @param string $state  'default' or 'hover'.
     * @param string $prefix Role prefix.
     * @return array
     */
    protected static function buttonPair(string $state, string $prefix = 'button_'): array
    {
        $background = self::read('buttonColor', $state);

        if ($background === '') {
            return [];
        }

        $text = self::read('buttonTextColor', $state);

        if ($text === '') {
            $bgHex = ThemePalette::measurable($background);
            $text = $bgHex !== '' ? ColorMath::readableText($bgHex) : '';
        }

        return [
            $prefix . 'bg'   => $background,
            $prefix . 'text' => $text,
        ];
    }

    /**
     * The body background (Site Background). Blocksy saves it flat or per
     * device; desktop speaks for all, as blocksy_expand_responsive_value()
     * reads it. A gradient paints no colour (Blocksy writes
     * `background-color: initial`), so it states no surface.
     *
     * @return string
     */
    protected static function surface(): string
    {
        $value = blocksy_get_theme_mod('site_background', []);

        if (is_array($value) && isset($value['desktop'])) {
            $value = $value['desktop'];
        }

        $value = is_array($value) ? $value : [];

        if (Arr::get($value, 'background_type', 'color') === 'gradient') {
            return '';
        }

        return self::normalise(
            Arr::get($value, 'backgroundColor.default.color'),
            self::$defaults['site_background']['default']
        );
    }

    /**
     * One colour mod at one state, over Blocksy's default for it.
     *
     * @param string $mod
     * @param string $state
     * @return string
     */
    protected static function read(string $mod, string $state = 'default'): string
    {
        $value = blocksy_get_theme_mod($mod, []);
        $raw = is_array($value) ? Arr::get($value, $state . '.color') : null;

        return self::normalise($raw, (string)Arr::get(self::$defaults, $mod . '.' . $state, ''));
    }

    /**
     * Unset — missing, empty, or Blocksy's inherit sentinel — takes the
     * default; anything else is normalised as saved.
     *
     * @param mixed  $raw
     * @param string $default
     * @return string
     */
    protected static function normalise($raw, string $default): string
    {
        if (!is_string($raw) || trim($raw) === '' || strpos($raw, 'CT_CSS_SKIP_RULE') === 0) {
            $raw = $default;
        }

        return ThemePalette::settingValue($raw);
    }

    /**
     * Blocksy's stylesheet fallback for both radii
     * (`var(--theme-button-border-radius, 3px)`,
     * `var(--theme-form-field-border-radius, 3px)`, static/bundle/main.min.css),
     * and the `empty_value` its dynamic CSS skips printing at.
     *
     * @var int
     */
    protected static $defaultRadius = 3;

    /**
     * The button and form-field radii.
     *
     * Button: `buttonRadius`, a ct-spacing value printed as
     * `--theme-button-border-radius` (inc/dynamic-styles/global/all.php). Its
     * desktop value speaks for all; an empty one is Blocksy's 3px.
     *
     * Form field: `formFieldBorderRadius`, a number of px (default 3) printed
     * as `--theme-form-field-border-radius` (global/forms.php). It is only
     * worn by classic forms: modern forms paint
     * `var(--has-classic-forms, …)` with `--false`, which leaves the field
     * square, so they state 0.
     *
     * @return array Role => length, as Blocksy prints it.
     */
    public static function radii(): array
    {
        if (!self::applies()) {
            return [];
        }

        $radii = [];

        $button = blocksy_get_theme_mod('buttonRadius', null);

        if (is_array($button) && isset($button['desktop'])) {
            $button = $button['desktop'];
        }

        $buttonRadius = self::spacingLength($button);

        if ($buttonRadius !== '') {
            $radii['btn'] = $buttonRadius;
        }

        if (blocksy_get_theme_mod('forms_type', 'classic-forms') !== 'classic-forms') {
            $radii['input'] = '0';
        } else {
            $field = blocksy_get_theme_mod('formFieldBorderRadius', self::$defaultRadius);

            if (is_numeric($field)) {
                $radii['input'] = $field . 'px';
            }
        }

        return $radii;
    }

    /**
     * A ct-spacing value as the one length Blocksy prints, the way
     * blocksy_spacing_prepare_for_device() writes it (inc/css/spacing.php):
     *
     *  - unset, or every side empty: Blocksy prints nothing and its
     *    stylesheet's 3px applies;
     *  - custom (state 3): the custom string as typed;
     *  - otherwise each side's value and unit, an empty side taking 3 when
     *    the sides are linked (0 when not), a side without a unit taking the
     *    others'. Only four equal sides are one length.
     *
     * The pre-`values` format (`top`/`right`/`bottom`/`left` strings) is read
     * the same way.
     *
     * @param mixed $value
     * @return string
     */
    protected static function spacingLength($value): string
    {
        $fallback = self::$defaultRadius . 'px';

        if (!is_array($value)) {
            return $fallback;
        }

        if (!isset($value['values'])) {
            return self::legacySpacingLength($value);
        }

        $state = (int)Arr::get($value, 'state', 1);

        if ($state === 3) {
            $custom = trim((string)Arr::get($value, 'custom', ''));

            return $custom === '' ? $fallback : $custom;
        }

        $sides = [];
        $unit = '';
        $allEmpty = true;

        foreach (array_slice(array_values((array)$value['values']), 0, 4) as $side) {
            $number = is_array($side) ? Arr::get($side, 'value', '') : '';
            $sideUnit = is_array($side) ? (string)Arr::get($side, 'unit', '') : '';

            if ($number === '' || $number === 'auto' || $number === null) {
                $number = $state === 1 ? self::$defaultRadius : 0;
            } else {
                $allEmpty = false;
            }

            if ($sideUnit !== '') {
                $unit = $sideUnit;
            }

            $sides[] = ['value' => (string)$number, 'unit' => $sideUnit];
        }

        if (count($sides) !== 4 || $allEmpty) {
            return $fallback;
        }

        $lengths = [];

        foreach ($sides as $side) {
            $lengths[] = $side['value'] . ($side['unit'] !== '' ? $side['unit'] : $unit);
        }

        return count(array_unique($lengths)) === 1 ? $lengths[0] : '';
    }

    /**
     * The pre-`values` spacing format: four strings carrying their unit.
     * Blocksy writes an empty, `auto` or `0` side as its empty value.
     *
     * @param array $value
     * @return string
     */
    protected static function legacySpacingLength(array $value): string
    {
        $sides = [];

        foreach (['top', 'right', 'bottom', 'left'] as $key) {
            $side = trim((string)Arr::get($value, $key, ''));
            $sides[] = ($side === '' || $side === 'auto' || $side === '0') ? '' : $side;
        }

        if (implode('', $sides) === '') {
            return self::$defaultRadius . 'px';
        }

        $unit = 'px';

        foreach ($sides as $side) {
            if ($side !== '' && preg_match('/^[\d.]+([a-z%]+)$/i', $side, $matches)) {
                $unit = $matches[1];
            }
        }

        foreach ($sides as $index => $side) {
            if ($side === '') {
                $sides[$index] = self::$defaultRadius . $unit;
            }
        }

        return count(array_unique($sides)) === 1 ? $sides[0] : '';
    }
}

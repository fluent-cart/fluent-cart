<?php

namespace FluentCart\App\Services\Theme;

use FluentCart\Framework\Support\Arr;

/**
 * The storefront colour registry.
 *
 * FluentCart's stylesheets declare roughly seventy scoped custom properties,
 * but almost every one of them is written as `var(--fct-<global>, <fallback>)`.
 * The globals listed here are the ones that are referenced but never declared —
 * they are the intended knobs, and setting them cascades to everything
 * downstream without touching a single scoped variable.
 *
 * This class is the single source of truth for three consumers: the CSS the
 * storefront prints, the settings schema the admin renders, and the sanitizer
 * that decides which submitted keys are real.
 */
class ColorPalette
{
    /**
     * Defaults source: FluentCart's own colours, only overrides are written.
     */
    const SOURCE_DEFAULT = 'default';

    /**
     * Defaults source: rebuild the whole palette from the active theme.
     */
    const SOURCE_THEME = 'inherit_from_theme';

    /**
     * Defaults source: the store owner picks the global colours by hand.
     */
    const SOURCE_CUSTOM = 'customize';

    /**
     * Request-level cache for the built registry.
     *
     * @var array|null
     */
    protected static $cachedGlobals = null;

    /**
     * Every accepted value for the source setting.
     *
     * @return array
     */
    public static function sources(): array
    {
        return [self::SOURCE_DEFAULT, self::SOURCE_THEME, self::SOURCE_CUSTOM];
    }

    /**
     * Field groups, in the order the settings screen shows them.
     *
     * @return array
     */
    public static function groups(): array
    {
        return [
            'text'    => __('Text', 'fluent-cart'),
            'surface' => __('Backgrounds and borders', 'fluent-cart'),
            'button'  => __('Buttons', 'fluent-cart'),
            'input'   => __('Form inputs', 'fluent-cart'),
        ];
    }

    /**
     * The global custom properties a store owner can set.
     *
     * Keys are the settings keys (snake_case, safe as form state paths); `var`
     * is the custom property written to the page. `aliases` covers properties
     * that carry a second spelling in the stylesheets — the modal checkout
     * reads `--fct-input-disabled-bg` where every other surface reads
     * `--fct-input-disabled-bg-color`, and a single setting has to drive both
     * or the modal drifts away from the rest of the store.
     *
     * `default` is the effective value FluentCart's own fallbacks resolve to;
     * it is shown as a hint and is never written to the page on its own.
     *
     * @return array
     */
    public static function globals(): array
    {
        if (self::$cachedGlobals !== null) {
            return self::$cachedGlobals;
        }

        $globals = [
            /* ------------------------------------------------------- Text */
            'primary_text_color'           => [
                'var'     => '--fct-primary-text-color',
                'label'   => __('Primary text', 'fluent-cart'),
                'note'    => __('Product titles, prices and headings.', 'fluent-cart'),
                'group'   => 'text',
                'role'    => 'text',
            ],
            'secondary_text_color'         => [
                'var'     => '--fct-secondary-text-color',
                'label'   => __('Secondary text', 'fluent-cart'),
                'note'    => __('Descriptions, captions and inactive navigation.', 'fluent-cart'),
                'group'   => 'text',
                'role'    => 'text_muted',
            ],
            'primary_active_text_color'    => [
                'var'     => '--fct-primary-active-text-color',
                'label'   => __('Active text', 'fluent-cart'),
                'note'    => __('The selected step and active links in the checkout.', 'fluent-cart'),
                'group'   => 'text',
                'role'    => 'accent',
            ],

            /* -------------------------------------- Backgrounds and borders */
            'primary_bg_color'             => [
                'var'     => '--fct-primary-bg-color',
                'label'   => __('Primary background', 'fluent-cart'),
                'note'    => __('The brand color behind active states and selected controls.', 'fluent-cart'),
                'group'   => 'surface',
                'role'    => 'accent',
            ],
            'secondary_bg_color'           => [
                'var'     => '--fct-secondary-bg-color',
                'label'   => __('Secondary background', 'fluent-cart'),
                'note'    => __('The tinted panels behind the shop grid and checkout summary.', 'fluent-cart'),
                'group'   => 'surface',
                'role'    => 'surface_alt',
            ],
            'border_color'                 => [
                'var'     => '--fct-border-color',
                'label'   => __('Border', 'fluent-cart'),
                'note'    => __('Card outlines, input borders and hairlines.', 'fluent-cart'),
                'group'   => 'surface',
                'role'    => 'border',
            ],
            'active_border_color'          => [
                'var'     => '--fct-active-border-color',
                'label'   => __('Active border', 'fluent-cart'),
                'note'    => __('The outline on a selected variant or payment method.', 'fluent-cart'),
                'group'   => 'surface',
                'role'    => 'accent',
            ],
            'secondary_active_border_color' => [
                'var'     => '--fct-secondary-active-border-color',
                'label'   => __('Secondary active border', 'fluent-cart'),
                'note'    => __('The softer active outline used inside the modal checkout.', 'fluent-cart'),
                'group'   => 'surface',
                'role'    => 'accent',
            ],
            'divider_color'                => [
                'var'     => '--fct-divider-color',
                'label'   => __('Divider', 'fluent-cart'),
                'note'    => __('The lighter rules between rows and sections.', 'fluent-cart'),
                'group'   => 'surface',
                'role'    => 'divider',
            ],
            'card_bg_color'                => [
                'var'     => '--fct-card-bg-color',
                'label'   => __('Card background', 'fluent-cart'),
                'note'    => __('The surface behind product cards and panels.', 'fluent-cart'),
                'group'   => 'surface',
                'role'    => 'surface',
            ],


            /* ---------------------------------------------------- Buttons */
            'btn_bg_color'                 => [
                'var'     => '--fct-btn-bg-color',
                'label'   => __('Button background', 'fluent-cart'),
                'note'    => __('Place Order, Buy Now, and every primary action in the store.', 'fluent-cart'),
                'group'   => 'button',
                'role'    => 'button_bg',
            ],
            'btn_text_color'               => [
                'var'     => '--fct-btn-text-color',
                'label'   => __('Button text', 'fluent-cart'),
                'note'    => '',
                'group'   => 'button',
                'role'    => 'button_text',
            ],
            // Left unset, every primary button hovers in its resting colour —
            // the stylesheets fall back to it — which is how they always hovered.
            'btn_hover_bg_color'           => [
                'var'     => '--fct-btn-hover-bg-color',
                'label'   => __('Button hover background', 'fluent-cart'),
                'note'    => __('What the primary buttons turn to under the pointer.', 'fluent-cart'),
                'group'   => 'button',
                'role'    => 'button_hover_bg',
            ],
            'btn_hover_text_color'         => [
                'var'     => '--fct-btn-hover-text-color',
                'label'   => __('Button hover text', 'fluent-cart'),
                'note'    => '',
                'group'   => 'button',
                'role'    => 'button_hover_text',
            ],
            'secondary_btn_bg_color'       => [
                'var'     => '--fct-secondary-btn-bg-color',
                'label'   => __('Secondary button background', 'fluent-cart'),
                'note'    => __('Add to Cart and the other outlined buttons.', 'fluent-cart'),
                'group'   => 'button',
                // Not plain 'surface': the outline always keeps the page
                // surface, but while the theme states its button pair the
                // LABEL borrows the pair's readable half (see
                // ThemePalette::resolve()).
                'role'    => 'secondary_button_bg',
            ],
            'secondary_btn_text_color'     => [
                'var'     => '--fct-secondary-btn-text-color',
                'label'   => __('Secondary button text', 'fluent-cart'),
                'note'    => '',
                'group'   => 'button',
                'role'    => 'secondary_button_text',
            ],
            'secondary_btn_border_color'   => [
                'var'     => '--fct-secondary-btn-border-color',
                'label'   => __('Secondary button border', 'fluent-cart'),
                'note'    => '',
                'group'   => 'button',
                'role'    => 'border',
            ],
            'secondary_btn_hover_bg_color' => [
                'var'     => '--fct-secondary-btn-hover-bg-color',
                'label'   => __('Secondary button hover', 'fluent-cart'),
                'note'    => '',
                'group'   => 'button',
                'role'    => 'surface_mute',
            ],

            /* ------------------------------------------------ Form inputs */
            'input_bg_color'               => [
                'var'     => '--fct-input-bg-color',
                'label'   => __('Input background', 'fluent-cart'),
                'note'    => '',
                'group'   => 'input',
                'role'    => 'surface',
            ],
            'input_text_color'             => [
                'var'     => '--fct-input-text-color',
                'label'   => __('Input text', 'fluent-cart'),
                'note'    => '',
                'group'   => 'input',
                'role'    => 'text',
            ],
            'input_placeholder_text_color' => [
                'var'     => '--fct-input-placeholder-text-color',
                'label'   => __('Placeholder text', 'fluent-cart'),
                'note'    => '',
                'group'   => 'input',
                'role'    => 'text_placeholder',
            ],
            'input_disabled_bg_color'      => [
                'var'     => '--fct-input-disabled-bg-color',
                'aliases' => ['--fct-input-disabled-bg'],
                'label'   => __('Disabled input background', 'fluent-cart'),
                'note'    => '',
                'group'   => 'input',
                'role'    => 'surface_mute',
            ],
        ];

        /**
         * Filter the global storefront colours a store owner can set.
         *
         * Anything added here becomes settable in the admin, sanitised on save
         * and written to the page — the three consumers read this one list.
         *
         * @param array $globals Settings key => definition.
         */
        $globals = apply_filters('fluent_cart/theme/color_globals', $globals);

        foreach ($globals as $key => $definition) {
            $globals[$key] = wp_parse_args($definition, [
                'var'     => '',
                'aliases' => [],
                'label'   => $key,
                'note'    => '',
                'group'   => 'surface',
                'role'    => '',
            ]);
        }

        self::$cachedGlobals = $globals;

        return self::$cachedGlobals;
    }

    /**
     * Drop the request-level registry cache.
     *
     * The registry runs its entries through a filter and wp_parse_args on the
     * first read, so it is built once per request. A filter registered after
     * that first read would otherwise never be seen.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$cachedGlobals = null;
    }

    /**
     * The globals belonging to one group, in registry order.
     *
     * @param string $group
     * @return array
     */
    public static function globalsFor(string $group): array
    {
        $matched = [];

        foreach (self::globals() as $key => $definition) {
            if (Arr::get($definition, 'group') === $group) {
                $matched[$key] = $definition;
            }
        }

        return $matched;
    }

    /**
     * The semantic roles theme inheritance resolves.
     *
     * The first four are anchors read from the theme's palette; the rest are
     * derived from those so that a theme offering two colours still produces a
     * coherent store.
     *
     * @return array Role key => label.
     */
    public static function roles(): array
    {
        return [
            'surface'          => __('Surface', 'fluent-cart'),
            'text'             => __('Body text', 'fluent-cart'),
            'accent'           => __('Accent', 'fluent-cart'),
            'button_bg'        => __('Button', 'fluent-cart'),
            'surface_alt'      => __('Alternate surface', 'fluent-cart'),
            'surface_mute'     => __('Muted surface', 'fluent-cart'),
            'border'           => __('Border', 'fluent-cart'),
            'divider'          => __('Divider', 'fluent-cart'),
            'text_muted'       => __('Muted text', 'fluent-cart'),
            'text_placeholder' => __('Placeholder text', 'fluent-cart'),
            'button_text'      => __('Button text', 'fluent-cart'),
            'button_hover_bg'  => __('Button hover', 'fluent-cart'),
            'button_hover_text' => __('Button hover text', 'fluent-cart'),
            'secondary_button_bg'   => __('Secondary button', 'fluent-cart'),
            'secondary_button_text' => __('Secondary button text', 'fluent-cart'),
        ];
    }

    /**
     * Every custom property one settings key writes, primary name first.
     *
     * @param array $definition
     * @return array
     */
    public static function varsOf(array $definition): array
    {
        $vars = [Arr::get($definition, 'var', '')];
        $aliases = Arr::get($definition, 'aliases', []);

        if (is_array($aliases)) {
            $vars = array_merge($vars, $aliases);
        }

        return array_values(array_filter($vars));
    }
}

<?php

namespace FluentCart\App\Services\Theme;

use FluentCart\Api\StoreSettings;
use FluentCart\Framework\Support\Arr;

/**
 * Writes the storefront's global colour custom properties.
 *
 * FluentCart's stylesheets declare every scoped colour as
 * `var(--fct-<global>, <fallback>)`, so the globals printed here cascade to the
 * whole store — shop grid, product page, cart drawer, checkout and the customer
 * dashboard — without any of those stylesheets being touched.
 *
 * Three sources are supported, and they are mutually exclusive:
 *
 *  - `default`            nothing is printed; FluentCart's own fallbacks apply.
 *  - `inherit_from_theme` the palette is rebuilt from the active theme.
 *  - `customize`          the store owner's own colours, and only the ones
 *                         they actually set.
 */
class FrontendTheme
{
    /**
     * The id on the printed style element, so it can be found in the source.
     */
    const STYLE_ID = 'fluent-cart-storefront-colors';

    /**
     * Marks a storefront still wearing FluentCart's own colours.
     */
    const CLASS_DEFAULT = 'fluent-cart-theme';

    /**
     * Marks a storefront whose colours have been changed from FluentCart's.
     */
    const CLASS_CUSTOM = 'fluent-cart-custom';

    /**
     * Marks a storefront the active theme styles itself.
     *
     * Only possible under `inherit_from_theme`, when nothing could be read from
     * the active theme. The stylesheets key their colour declarations off this:
     * a declaration carrying an unresolvable var() still wins the cascade and
     * then computes to `unset` — it never falls back to the theme's own rule,
     * because that rule was discarded at cascade time. Only the absence of the
     * declaration lets the theme style the element, and absence cannot be
     * expressed with a variable, so it is expressed with this class instead.
     */
    const CLASS_NO_COLORS = 'fluent-cart-no-colors';

    /**
     * Register the front-end hooks.
     *
     * Priority 100 puts the block after wp_print_styles() (which runs on
     * wp_head at 8). Both this block and FluentCart's stylesheets target
     * `:root`, so specificity is a tie and source order decides — printing
     * late is what makes these values win.
     *
     * @return void
     */
    public static function applyTheme(): void
    {
        add_action('wp_head', [__CLASS__, 'printColors'], 100);
        add_filter('body_class', [__CLASS__, 'bodyClasses']);
        add_filter('fluent_cart/stripe_appearance', [__CLASS__, 'filterStripeAppearance'], 5);
    }

    /**
     * Seed the `fluent_cart/stripe_appearance` filter with the palette's
     * answer.
     *
     * A named callback on the public filter rather than a call inside the
     * gateway: the payment module stays unaware of the theme service, and
     * removing or replacing FluentCart's contribution is a *_filter() call.
     *
     * A default-filler, not an owner: the palette supplies its theme, its
     * colour variables and its tab rules, and everything else — a customised seed, or
     * what an earlier listener added (labels, rules, extra variables) —
     * survives. The theme is only taken over from the plain default, since
     * an explicit theme choice is a choice. With no opinion (default source,
     * nothing measurable), the incoming value passes through unchanged.
     *
     * @param mixed $appearance
     * @return array
     */
    public static function filterStripeAppearance($appearance): array
    {
        $appearance = (array)$appearance;
        $palette = self::stripeAppearance();

        if (empty($palette['variables'])) {
            return $appearance;
        }

        $theme = (string)Arr::get($appearance, 'theme', 'stripe');

        if ($theme === '' || $theme === 'stripe') {
            $appearance['theme'] = $palette['theme'];
        }

        $appearance['variables'] = array_merge(
            (array)Arr::get($appearance, 'variables', []),
            $palette['variables']
        );

        // Per selector: the palette sets its properties, and every other
        // selector and property an earlier listener wrote survives. Selectors
        // start with a dot, so they are indexed directly, never via Arr::get().
        $rules = (array)Arr::get($appearance, 'rules', []);

        foreach ((array)Arr::get($palette, 'rules', []) as $selector => $properties) {
            $rules[$selector] = array_merge(
                isset($rules[$selector]) ? (array)$rules[$selector] : [],
                $properties
            );
        }

        if ($rules) {
            $appearance['rules'] = $rules;
        }

        return $appearance;
    }

    /**
     * The Stripe Elements appearance the storefront palette implies.
     *
     * Stripe renders inside its own iframe, where FluentCart's custom
     * properties do not exist — a var() reference handed to Stripe resolves
     * to nothing. So the palette speaks to Stripe only in measurable hexes
     * (a reference's hex fallback counts, per the spec-59 doctrine), only as
     * a pair — the input surface and its text from the same source — and
     * stays silent otherwise: Stripe's default theme is a coherent light
     * design, and half a dark palette printed onto it would read worse than
     * none of it. A dark input surface picks Stripe's own night theme so the
     * parts we never set (placeholders, dividers, the Link box) darken with
     * it.
     *
     * Seeds the `fluent_cart/stripe_appearance` filter's default — a listener
     * still overrides everything here.
     *
     * The button pair reaches Stripe on its own, without the input pair: it
     * is the accent, and it fills the selected payment-method tab.
     *
     * @return array Stripe appearance config: ['theme' => ..., 'variables' => ..., 'rules' => ...].
     */
    public static function stripeAppearance(): array
    {
        $appearance = ['theme' => 'stripe'];

        $colors = self::getMeasurableColors();

        if (!$colors) {
            return $appearance;
        }

        $inputBg = (string)Arr::get($colors, 'input_bg_color', '');
        $inputText = (string)Arr::get($colors, 'input_text_color', '');

        if ($inputBg !== '' && $inputText !== '') {
            // readableOn() picks white on a dark surface — which is exactly
            // when Stripe should start from night instead of its light theme.
            $appearance['theme'] = ColorMath::readableOn($inputBg) === '#ffffff' ? 'night' : 'stripe';
            $appearance['variables'] = [
                'colorBackground' => $inputBg,
                'colorText'       => $inputText,
            ];
        }

        // The accent and the selected payment-method tab follow the button,
        // not primary_bg_color: that is a pale surface behind active states,
        // and as Stripe's accent it washed the selected tab out entirely.
        $buttonBg = (string)Arr::get($colors, 'btn_bg_color', '');

        if ($buttonBg === '') {
            return $appearance;
        }

        $buttonText = (string)Arr::get($colors, 'btn_text_color', '');

        if ($buttonText === '') {
            $buttonText = ColorMath::readableText($buttonBg);
        }

        $appearance['variables']['colorPrimary'] = $buttonBg;

        $selectedTab = [
            'backgroundColor' => $buttonBg,
            'borderColor'     => $buttonBg,
            'color'           => $buttonText,
        ];

        $appearance['rules'] = [
            '.Tab:hover'                => ['borderColor' => $buttonBg],
            '.Tab--selected'            => $selectedTab,
            '.Tab--selected:hover'      => $selectedTab,
            '.Tab--selected:focus'      => $selectedTab,
            '.TabIcon--selected'        => ['fill' => $buttonText],
            '.TabIcon--selected:hover'  => ['fill' => $buttonText],
            '.TabLabel--selected'       => ['color' => $buttonText],
        ];

        return $appearance;
    }

    /**
     * Say on the body which colour source is in force.
     *
     * The custom properties printed above only reach surfaces FluentCart
     * already styles. A class reaches everything else — a theme that wants to
     * stand down once the store owner has picked their own colours, a snippet
     * in the customiser, a child theme adjusting one store. None of those can
     * read the setting; all of them can write a selector.
     *
     * The two markers are alternatives, never both, so `body.fluent-cart-theme`
     * and `body.fluent-cart-custom` partition every storefront page between
     * them.
     *
     * The active theme is named only under `inherit_from_theme`, because that
     * is the one source whose result depends on which theme is running. Naming
     * it under `customize` would invite a selector that breaks on switching
     * theme for no reason, since the owner's colours are the owner's colours
     * either way.
     *
     * @param array $classes
     * @return array
     */
    public static function bodyClasses($classes): array
    {
        if (!is_array($classes)) {
            return [];
        }

        $source = self::getSource();

        // getSource() already collapses an unrecognised stored value to the
        // default, so this agrees with what actually gets printed rather than
        // with what the option happens to say.
        if ($source !== ColorPalette::SOURCE_THEME && $source !== ColorPalette::SOURCE_CUSTOM) {
            $classes[] = self::CLASS_DEFAULT;

            return $classes;
        }

        $classes[] = self::CLASS_CUSTOM;

        if ($source === ColorPalette::SOURCE_THEME) {
            $slug = self::themeClass();

            if ($slug !== '') {
                $classes[] = $slug;
            }

            // Nothing readable means nothing written, and the stylesheets have
            // to know that: their colour declarations must not exist for the
            // theme's own rules to apply, and only a class can express that.
            if (!self::getThemeColors()) {
                $classes[] = self::CLASS_NO_COLORS;
            }
        }

        return $classes;
    }

    /**
     * The active theme as a class name.
     *
     * The template rather than the stylesheet: most real stores run a child
     * theme, whose styling is the parent's plus a few overrides, so a rule
     * written for `astra` is the one that is actually wanted. Naming
     * `astra-child` would leave that rule matching nothing on exactly the sites
     * most likely to need it.
     *
     * A theme directory name is not a class name, and this lands inside a class
     * attribute on every storefront page, so it goes through
     * sanitize_html_class() — which can legitimately return nothing, and an
     * empty class is not worth adding.
     *
     * @return string
     */
    protected static function themeClass(): string
    {
        if (!function_exists('get_template')) {
            return '';
        }

        return (string)sanitize_html_class((string)get_template());
    }

    /**
     * Print the custom-property block.
     *
     * The modal checkout renders in an iframe pointed at a normal WordPress
     * URL, and that view calls wp_head() too, so this one hook covers both the
     * storefront and the modal without a second injection point.
     *
     * @return void
     */
    public static function printColors(): void
    {
        if (is_admin()) {
            return;
        }

        $css = self::buildCss();

        if ($css === '') {
            return;
        }

        /*
         * Not escaped on output because it cannot carry anything to escape:
         * every property name comes from the ColorPalette registry and every
         * value has been through sanitize_hex_color(), so the string is only
         * ever `--fct-name: #rrggbb;`.
         */
        echo '<style id="' . esc_attr(self::STYLE_ID) . '">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Which source the store is configured to use.
     *
     * @return string One of the ColorPalette::SOURCE_* constants.
     */
    public static function getSource(): string
    {
        $source = (new StoreSettings())->get('appearance_source', ColorPalette::SOURCE_DEFAULT);

        return in_array($source, ColorPalette::sources(), true)
            ? $source
            : ColorPalette::SOURCE_DEFAULT;
    }

    /**
     * The colours the store owner set by hand, keyed by settings key.
     *
     * Anything not in the registry, and anything that is not a valid hex, is
     * dropped — the option is sanitised on save, but a stale option written by
     * an older version must not reach the page unchecked.
     *
     * @return array
     */
    public static function getCustomColors(): array
    {
        $stored = (new StoreSettings())->get('appearance_colors', []);

        if (!is_array($stored)) {
            return [];
        }

        $globals = ColorPalette::globals();
        $colors = [];

        foreach ($globals as $key => $definition) {
            $hex = sanitize_hex_color((string)Arr::get($stored, $key, ''));

            if ($hex) {
                $colors[$key] = $hex;
            }
        }

        return $colors;
    }

    /**
     * The colours the active theme supplies, keyed by settings key.
     *
     * @return array
     */
    public static function getThemeColors(): array
    {
        // With nothing to inherit, every role would resolve to FluentCart's own
        // fallback and we would pin twenty properties to values the theme never
        // chose. That is not the same as leaving them alone: FluentCart's
        // per-file fallbacks differ from one surface to the next, so pinning
        // one value everywhere quietly changes the store while claiming to be
        // following the theme. Write nothing instead.
        if (!ThemePalette::hasUsableSource()) {
            return [];
        }

        $roles = ThemePalette::resolve();
        $colors = [];

        foreach (ColorPalette::globals() as $key => $definition) {
            $role = Arr::get($definition, 'role', '');
            $value = self::sanitizeDeclarationValue(Arr::get($roles, $role, ''));

            if ($value !== '') {
                $colors[$key] = $value;
            }
        }

        return $colors;
    }

    /**
     * The only two shapes allowed on the right of one of our declarations.
     *
     * A hex colour, or a bare custom-property reference for the themes that
     * publish their palette that way. Everything else is refused: this string
     * is written into a style element on every storefront page, so the grammar
     * stays narrow enough that nothing can ride along inside it.
     *
     * @param mixed $value
     * @return string The safe value, or '' when it is neither.
     */
    protected static function sanitizeDeclarationValue($value): string
    {
        $value = (string)$value;

        $hex = sanitize_hex_color($value);

        if ($hex) {
            return $hex;
        }

        // The two reference shapes ThemePalette::safeReference() produces: a
        // bare custom property, or one whose sole fallback is a hex colour —
        // the form Customify publishes. Nothing looser.
        return preg_match('/^var\(--[A-Za-z0-9_-]+(?:, #(?:[0-9a-f]{3}|[0-9a-f]{6}))?\)$/', $value) ? $value : '';
    }

    /**
     * Give a customised button what theme inheritance would have given it.
     *
     * Customize lets the owner leave a button's text unset, and every
     * stylesheet then falls back to its own literal text — not always one
     * that reads on the background the owner did set (the product carousel's
     * hovered arrow fell back to a dark icon). Theme inheritance already
     * measures a missing partner; this does the same for the button pair and
     * the hover pair.
     *
     * A button background with no hover background also gets the hover
     * inheritance derives — the button moved 12% away from itself — with the
     * resting text carried over while it reads; otherwise the button hovered
     * in its resting colour, with no visible change. A colour the owner chose
     * is worn as given.
     *
     * @param array $colors Settings key => hex, as the owner set them.
     * @return array
     */
    protected static function withButtonPartners(array $colors): array
    {
        if (isset($colors['btn_bg_color']) && !isset($colors['btn_text_color'])) {
            $colors['btn_text_color'] = ColorMath::readableText($colors['btn_bg_color']);
        }

        if (isset($colors['btn_bg_color']) && !isset($colors['btn_hover_bg_color'])) {
            $colors['btn_hover_bg_color'] = ColorMath::shiftFromItself($colors['btn_bg_color'], 12);

            if (!isset($colors['btn_hover_text_color'])) {
                $colors['btn_hover_text_color'] = ThemePalette::hoverTextFor(
                    $colors['btn_hover_bg_color'],
                    $colors['btn_text_color']
                );
            }
        }

        if (isset($colors['btn_hover_bg_color']) && !isset($colors['btn_hover_text_color'])) {
            $colors['btn_hover_text_color'] = ColorMath::readableText($colors['btn_hover_bg_color']);
        }

        return $colors;
    }

    /**
     * Every colour that will actually be written, keyed by settings key.
     *
     * @return array
     */
    public static function getEffectiveColors(): array
    {
        $source = self::getSource();

        if ($source === ColorPalette::SOURCE_CUSTOM) {
            $colors = self::withButtonPartners(self::getCustomColors());
        } elseif ($source === ColorPalette::SOURCE_THEME) {
            $colors = self::getThemeColors();
        } else {
            $colors = [];
        }

        /**
         * Filter the storefront colours before they are written to the page.
         *
         * @param array $colors Settings key => hex.
         * @param array $context Read-only context for the decision.
         */
        $filteredColors = apply_filters('fluent_cart/theme/storefront_colors', $colors, [
            'source' => $source,
        ]);

        return is_array($filteredColors) ? $filteredColors : $colors;
    }

    /**
     * The effective colours as plain hexes, keyed like getEffectiveColors().
     *
     * A separate reader instead of extra keys in the effective colours: the
     * registry is publicly extensible, so no key shape is safe to reserve
     * there. A reference measures as its shipped hex fallback; anything
     * unmeasurable is simply absent, so absence itself means "no real
     * colour known".
     *
     * @return array Settings key => hex.
     */
    public static function getMeasurableColors(): array
    {
        $measured = [];

        foreach (self::getEffectiveColors() as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            $hex = ThemePalette::measurable($value);

            if ($hex !== '') {
                $measured[$key] = $hex;
            }
        }

        return $measured;
    }

    /**
     * Build the `:root` declaration block.
     *
     * @return string CSS, or '' when there is nothing to write.
     */
    public static function buildCss(): string
    {
        $colors = self::getEffectiveColors();

        if (!$colors) {
            return '';
        }

        $globals = ColorPalette::globals();
        $declarations = '';

        foreach ($colors as $key => $value) {
            if (!isset($globals[$key])) {
                continue;
            }

            // Re-checked here rather than trusted from the source that produced
            // it, because a filter sits between the two.
            $safe = self::sanitizeDeclarationValue($value);

            if ($safe === '') {
                continue;
            }

            foreach (ColorPalette::varsOf($globals[$key]) as $property) {
                $declarations .= $property . ':' . $safe . ';';
            }
        }

        return $declarations === '' ? '' : ':root{' . $declarations . '}';
    }
}

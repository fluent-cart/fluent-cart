<?php

namespace FluentCart\App\Services\Theme;

use FluentCart\App\Services\Theme\Readers\AstraSettingsReader;
use FluentCart\App\Services\Theme\Readers\BlocksySettingsReader;
use FluentCart\App\Services\Theme\Readers\BricksSettingsReader;
use FluentCart\App\Services\Theme\Readers\DiviSettingsReader;
use FluentCart\App\Services\Theme\Readers\GeneratePressSettingsReader;
use FluentCart\App\Services\Theme\Readers\KadenceSettingsReader;
use FluentCart\Framework\Support\Arr;

/**
 * Reads the active theme's colour palette and resolves it into the semantic
 * roles the storefront needs.
 *
 * A block theme publishes a handful of palette entries in theme.json. The
 * storefront needs a surface, a body text tone, an accent and a button colour
 * as anchors, plus the in-between tones — borders, dividers, muted text,
 * placeholder text — that no theme bothers to declare. Those are derived from
 * the anchors rather than invented, so the store follows the theme instead of
 * merely sitting next to it, and keeps following when the palette changes.
 */
class ThemePalette
{
    /**
     * Palette slugs tried for each anchor role, most specific first.
     *
     * These are the slugs themes agree on — `base`/`contrast` come from Twenty
     * Twenty-Four and the themes that copied it, `primary`/`accent` from the
     * classic-adjacent ones, and GeneratePress publishes the same names as
     * references its own stylesheets resolve (measured through its Global
     * Colors; see vendorValues()).
     *
     * Three vendors are supported by name — the ones popular enough to be worth
     * carrying, each mapping written down from the theme's own sources rather
     * than inferred from the numbering:
     *
     *  - Astra numbers 0 brand, 3 body text, 4 background.
     *  - Kadence's theme.json names `theme-palette1` "Accent"; its defaults set
     *    the body font to `palette4` and the content background to `palette9`.
     *  - Blocksy paints its buttons with `palette-color-1`, defaults Base Text
     *    to `palette-color-3`, and its surfaces inherit `palette-color-8`.
     *
     * All publish their palette as `var()` references the theme itself
     * declares on every page, so the reference is written through and the
     * browser resolves it there. Blocksy's references carry hex fallbacks,
     * which makes them measurable as shipped; Astra's and Kadence's are bare,
     * so their current values are read from the vendor's own settings and
     * attached as the fallback (see vendorValues()) — the same measurable
     * shape, arrived at from the source the vendor prints the property from.
     * Either way derived tones and button contrast resolve for real. Should a
     * reference still measure as nothing, the button is not owned at all (see
     * resolve()) — never a guessed text colour on an unknown background.
     *
     * The vendor slugs sit last so the shared names always win first — a real
     * colour can also be mixed and measured, a reference can only be written. Every other vendor still takes the stand-aside path
     * (nothing written, the no-colors marker on the body, the theme's own rules
     * styling the CTAs), or the `fluent_cart/theme/anchor_map` filter.
     *
     * @var array
     */
    protected static $anchorCandidates = [
        'surface'   => ['base', 'background', 'white', 'base-2', 'light', 'ast-global-color-4', 'theme-palette9', 'palette-color-8'],
        'text'      => ['contrast', 'text', 'foreground', 'black', 'dark', 'contrast-2', 'ast-global-color-3', 'theme-palette4', 'palette-color-3'],
        'accent'    => ['primary', 'accent', 'brand', 'accent-1', 'theme-1', 'link', 'ast-global-color-0', 'theme-palette1', 'palette-color-1'],
        'button_bg' => ['primary', 'accent', 'brand', 'contrast', 'accent-1', 'theme-1', 'ast-global-color-0', 'theme-palette1', 'palette-color-1'],
    ];

    /**
     * Request-level cache for the normalised palette.
     *
     * @var array|null
     */
    protected static $cachedPalette = null;

    /**
     * Request-level cache for the resolved roles.
     *
     * @var array|null
     */
    protected static $cachedRoles = null;

    /**
     * Request-level cache for the vendor-declared property values.
     *
     * @var array|null
     */
    protected static $cachedVendorValues = null;

    /**
     * Request-level cache for the roles the theme's own settings state.
     *
     * @var array|null
     */
    protected static $cachedSettingsRoles = null;

    /**
     * Theme settings readers, keyed by the theme name passed to the
     * `fluent_cart/theme/settings_roles` filter. The first that applies wins.
     *
     * Only one theme runs on a site, so the order only matters when several
     * vendors' functions are loaded at once (the test stubs). The stricter
     * checks go first: Blocksy's and Kadence's each need a live instance from
     * the vendor's API, Divi's needs the `$shortname` global to name Divi, and
     * Bricks' needs its running `Bricks\Theme` singleton, and GeneratePress's
     * needs its dynamic-CSS printer hooked on `wp_enqueue_scripts`, none of
     * which a loaded-but-idle stub provides, while Astra's is a bare
     * `function_exists()` that stays true once its stub is loaded — so Astra
     * is asked last. Among the strict ones the order is arbitrary.
     *
     * @var array
     */
    protected static $settingsReaders = [
        'blocksy'       => BlocksySettingsReader::class,
        'kadence'       => KadenceSettingsReader::class,
        'divi'          => DiviSettingsReader::class,
        'bricks'        => BricksSettingsReader::class,
        'generatepress' => GeneratePressSettingsReader::class,
        'astra'         => AstraSettingsReader::class,
    ];

    /**
     * The roles a theme settings reader may state.
     *
     * @var array
     */
    protected static $settingsRoleKeys = [
        'surface', 'text', 'accent', 'border',
        'button_bg', 'button_text', 'button_hover_bg', 'button_hover_text',
    ];

    /**
     * Drop the request-level caches.
     *
     * Reading theme.json and resolving eleven roles is repeated work within a
     * request, so both are cached. Anything that changes what those reads would
     * return — switching theme, editing the palette, a filter registered after
     * a first read — has to clear them or it keeps seeing the old palette.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$cachedPalette = null;
        self::$cachedRoles = null;
        self::$cachedVendorValues = null;
        self::$cachedSettingsRoles = null;
    }

    /**
     * The active theme's colour palette, normalised to hex.
     *
     * @return array List of ['slug' => ..., 'name' => ..., 'color' => ...].
     */
    public static function palette(): array
    {
        if (self::$cachedPalette !== null) {
            return self::$cachedPalette;
        }

        if (!function_exists('wp_get_global_settings')) {
            self::$cachedPalette = [];

            return self::$cachedPalette;
        }

        $raw = wp_get_global_settings(['color', 'palette']);

        self::$cachedPalette = is_array($raw) ? self::normalizePalette($raw) : [];

        return self::$cachedPalette;
    }

    /**
     * Flatten whatever shape wp_get_global_settings() returned.
     *
     * Depending on the WordPress version this is either a flat list or one
     * list per origin. The `default` origin is WordPress's own twelve-colour
     * palette — black, white and the vivid-* set — which every site has
     * whether or not its theme declares anything. Inheriting from it would
     * not be inheriting from the theme, and it would let a theme that
     * publishes nothing report a palette it does not have, so only the
     * theme's own colours and the user's customisations of them are read.
     *
     * @param array $raw
     * @return array
     */
    protected static function normalizePalette(array $raw): array
    {
        $groups = [];

        if (isset($raw['default']) || isset($raw['theme']) || isset($raw['custom'])) {
            // `custom` comes last so a colour edited in the site editor wins
            // over the theme's declared value for the same slug.
            foreach (['theme', 'custom'] as $origin) {
                $originColors = Arr::get($raw, $origin, []);

                if (is_array($originColors) && $originColors) {
                    $groups[] = $originColors;
                }
            }
        } else {
            $groups[] = $raw;
        }

        $entries = [];

        foreach ($groups as $colors) {
            foreach ($colors as $color) {
                $slug = Arr::get($color, 'slug', '');

                if (!$slug) {
                    continue;
                }

                $entries[$slug] = [
                    'slug'  => (string)$slug,
                    'name'  => (string)Arr::get($color, 'name', $slug),
                    'color' => (string)Arr::get($color, 'color', ''),
                ];
            }
        }

        /**
         * Filter the theme palette offered for colour inheritance.
         *
         * @param array $entries List of ['slug', 'name', 'color'].
         */
        $entries = apply_filters('fluent_cart/theme/palette', array_values($entries));

        return self::usableEntries($entries);
    }

    /**
     * Keep only the entries that name a slug and a colour we can actually read.
     *
     * theme.json is not restricted to hex, and several popular themes publish
     * their palette as `var(--theme-colour-0)` references that cannot be
     * resolved server-side. Those are dropped rather than guessed: an
     * unresolvable value handed on as if it were a colour would be mixed into
     * the derived tones, and a muted text colour mixed from nothing collapses
     * to the surface it sits on — invisible text rather than an obvious error.
     *
     * Applied after the filter as well as before it, because a filter is just
     * another source of values and gets no more trust than theme.json does.
     *
     * @param mixed $entries
     * @return array
     */
    protected static function usableEntries($entries): array
    {
        if (!is_array($entries)) {
            return [];
        }

        $usable = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $slug = Arr::get($entry, 'slug', '');
            $raw = (string)Arr::get($entry, 'color', '');
            $hex = ColorMath::hex($raw);

            // A literal colour is preferred because it can also be mixed and
            // measured. A reference cannot be resolved here, but the browser
            // resolves it perfectly well, so it is kept as something we can
            // write — and when it carries a hex fallback, that fallback is the
            // colour it can be measured as too.
            $value = $hex !== '' ? $hex : self::safeReference($raw);

            // A bare reference to a property the active vendor itself declares
            // is only unreadable to an outsider: the vendor prints it from its
            // own settings, and those are one function call away. Attaching
            // that value as the fallback turns the reference into the
            // measurable shape — the browser still follows the live property,
            // the fallback is only what it is measured as here.
            if ($hex === '' && $value !== '' && self::measurable($value) === '') {
                $property = substr($value, 4, -1);
                $vendorHex = (string)Arr::get(self::vendorValues(), $property, '');

                if ($vendorHex !== '') {
                    $value = 'var(' . $property . ', ' . $vendorHex . ')';
                }
            }

            if (!$slug || $value === '') {
                continue;
            }

            $usable[(string)$slug] = [
                'slug'  => (string)$slug,
                'name'  => (string)Arr::get($entry, 'name', $slug),
                'color' => self::measurable($value),
                'value' => $value,
            ];
        }

        return array_values($usable);
    }

    /**
     * The current values of the properties the active vendor declares, keyed
     * by property name.
     *
     * Each mapping reads the same source the vendor prints the property from,
     * so customisations are included — the customiser saves into the very
     * option being read. Astra prints `--ast-global-color-N` from
     * `astra_get_option('global-color-palette')` (its
     * `generate_global_palette_style()`), and Kadence prints
     * `--global-paletteN` from `kadence()->palette_option('paletteN')` (its
     * styles component; see KadenceSettingsReader::paletteValues()).
     * Blocksy's editor palette already ships with hex fallbacks, but its Customizer settings point at the bare
     * `--theme-palette-color-N`, so its palette is read too, from
     * `blocksy_manager()->colors->get_color_palette()` (the list it prints
     * the properties from; see BlocksySettingsReader::paletteValues()).
     * Bricks prints `--bricks-color-{id}` from its colour palette (or the
     * property a palette colour's raw value names; see
     * BricksSettingsReader::paletteValues()). GeneratePress prints `--{slug}`
     * on :root from its Global Colors and publishes them to the editor
     * palette as bare `var(--{slug})` (see
     * GeneratePressSettingsReader::paletteValues()).
     *
     * The values are only ever used as the fallback half of `var(--x, #hex)`,
     * so a wrong or stale answer cannot repaint anything — the browser keeps
     * resolving the live property — it can only mis-measure, which is where
     * resolve() refusing an unmeasurable button still protects the store.
     *
     * @return array Property => hex.
     */
    protected static function vendorValues(): array
    {
        if (self::$cachedVendorValues !== null) {
            return self::$cachedVendorValues;
        }

        $values = [];

        if (function_exists('astra_get_option')) {
            $palette = astra_get_option('global-color-palette');
            $colors = is_array($palette) ? Arr::get($palette, 'palette', []) : [];

            foreach ((array)$colors as $index => $color) {
                $values['--ast-global-color-' . $index] = (string)$color;
            }
        }

        foreach (BlocksySettingsReader::paletteValues() as $property => $color) {
            $values[$property] = $color;
        }

        foreach (KadenceSettingsReader::paletteValues() as $property => $color) {
            $values[$property] = $color;
        }

        foreach (BricksSettingsReader::paletteValues() as $property => $color) {
            $values[$property] = $color;
        }

        foreach (GeneratePressSettingsReader::paletteValues() as $property => $color) {
            $values[$property] = $color;
        }

        /**
         * Filter the vendor-declared custom property values used to measure
         * bare palette references, keyed by property name (`--x` => `#hex`).
         *
         * @param array $values Property => colour.
         */
        $values = apply_filters('fluent_cart/theme/vendor_values', $values);

        // A filter is just another source of values: only a real property
        // name paired with a real hex survives. Kadence's palette10 can be an
        // oklch() expression, which this drops too — an expression cannot be
        // measured, and it must never ride into a declaration as a fallback.
        $clean = [];

        if (is_array($values)) {
            foreach ($values as $property => $color) {
                $colorHex = ColorMath::hex((string)$color);

                if ($colorHex !== '' && preg_match('/^--[A-Za-z0-9_-]+$/', (string)$property)) {
                    $clean[(string)$property] = $colorHex;
                }
            }
        }

        return self::$cachedVendorValues = $clean;
    }

    /**
     * Accept a bare custom-property reference, and nothing more.
     *
     * Themes built on the page-builder stack — Astra, Kadence, GeneratePress —
     * publish their palette as `var(--ast-global-color-0)` rather than as a
     * literal, because the real value lives in their own settings and is
     * emitted as a custom property at runtime. Refusing those makes theme
     * inheritance do nothing on a large share of real stores.
     *
     * Only the single-argument form is accepted: no fallback expression, no
     * nesting, no parentheses beyond the one pair. This string is written into
     * a declaration on every storefront page, so the grammar is kept narrow
     * enough that nothing else can ride along inside it.
     *
     * @param string $value
     * @return string The normalised reference, or '' when it is not one.
     */
    protected static function safeReference($value): string
    {
        $value = trim((string)$value);

        if (preg_match('/^var\(\s*(--[A-Za-z0-9_-]+)\s*\)$/', $value, $matches)) {
            return 'var(' . $matches[1] . ')';
        }

        // One fallback shape is allowed, and only one: a hex colour. Customify
        // publishes its whole palette as `var(--customify-primary, #0e7c7b)` —
        // the reference follows the customiser live, and the fallback is the
        // one kind of fallback that is itself checkable. `red`, expressions and
        // nested var() stay refused.
        if (preg_match('/^var\(\s*(--[A-Za-z0-9_-]+)\s*,\s*(#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}))\s*\)$/', $value, $matches)) {
            return 'var(' . $matches[1] . ', ' . strtolower($matches[2]) . ')';
        }

        return '';
    }

    /**
     * The colour a value can actually be measured as.
     *
     * A hex is itself. A reference with a hex fallback is measured as the
     * fallback — the theme shipped it as the value to use when the property is
     * missing, which makes it the theme's own best answer for what the colour
     * is. A bare reference measures as nothing.
     *
     * @param string $value
     * @return string Hex, or ''.
     */
    public static function measurable($value): string
    {
        $value = (string)$value;
        $hex = ColorMath::hex($value);

        if ($hex !== '') {
            return $hex;
        }

        if (preg_match('/^var\(\s*--[A-Za-z0-9_-]+\s*,\s*(#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}))\s*\)$/', $value, $matches)) {
            return strtolower($matches[1]);
        }

        return '';
    }

    /**
     * Normalise a colour a theme setting states into a value we can write.
     *
     * A hex is itself, lowercased. A custom-property reference stays a live
     * reference; a bare one gains the vendor's current value as its hex
     * fallback (see vendorValues()), so the browser still follows the
     * property while PHP measures the fallback. Anything else — rgba(),
     * named colours, expressions — is refused: the result is written into a
     * declaration on every storefront page and must pass
     * FrontendTheme::sanitizeDeclarationValue().
     *
     * @param mixed $raw
     * @return string Hex, `var(--x)`, `var(--x, #hex)`, or ''.
     */
    public static function settingValue($raw): string
    {
        if (!is_string($raw)) {
            return '';
        }

        $raw = trim($raw);

        if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $raw)) {
            return ColorMath::hex($raw);
        }

        $reference = self::safeReference($raw);

        if ($reference === '' || self::measurable($reference) !== '') {
            return $reference;
        }

        $property = substr($reference, 4, -1);
        $vendorHex = (string)Arr::get(self::vendorValues(), $property, '');

        return $vendorHex !== '' ? 'var(' . $property . ', ' . $vendorHex . ')' : $reference;
    }

    /**
     * The colours the active theme's own settings state, by role.
     *
     * Palette slots say which colours a theme offers; its settings say which
     * one the owner put where. A reader for the active theme (see
     * Readers\ThemeSettingsReader) reports what the owner set — Astra's,
     * Blocksy's, Kadence's, Divi's, Bricks' or GeneratePress's Accent, Body Text, Content Background, Borders
     * and Button colours — and those outrank the palette-slot guesses in anchors() and resolve().
     * Only the site editor's own button (buttonGlobals()) outranks them.
     *
     * Roles: surface, text, accent, border, button_bg, button_text,
     * button_hover_bg, button_hover_text. A role the theme does not state is
     * absent, and is derived exactly as it would be without a reader.
     *
     * @return array Role => hex or reference.
     */
    public static function settingsRoles(): array
    {
        if (self::$cachedSettingsRoles !== null) {
            return self::$cachedSettingsRoles;
        }

        $roles = [];
        $theme = '';

        foreach (self::$settingsReaders as $name => $reader) {
            if ($reader::applies()) {
                $theme = $name;
                $roles = $reader::roles();
                break;
            }
        }

        /**
         * Filter the colours the active theme's settings state, by role.
         *
         * Runs whether or not a built-in reader applied, so a theme FluentCart
         * does not read can be supplied here; return [] to turn the reader off
         * and fall back to palette slots. Values must be a hex or a
         * `var(--x)` / `var(--x, #hex)` reference — anything else is dropped,
         * and a bare reference to a vendor property gains its hex fallback.
         *
         * @param array $roles   Role => colour. Keys: surface, text, accent,
         *                       border, button_bg, button_text,
         *                       button_hover_bg, button_hover_text.
         * @param array $context ['theme' => reader name ('blocksy', 'kadence', 'divi', 'bricks', 'generatepress', 'astra'), or '' when
         *                       no built-in reader applies].
         */
        $roles = apply_filters('fluent_cart/theme/settings_roles', $roles, [
            'theme' => $theme,
        ]);

        // A filter is just another source of values: only known roles with a
        // writable colour survive.
        $clean = [];

        if (is_array($roles)) {
            foreach (self::$settingsRoleKeys as $role) {
                $value = self::settingValue(Arr::get($roles, $role, ''));

                if ($value !== '') {
                    $clean[$role] = $value;
                }
            }
        }

        return self::$cachedSettingsRoles = $clean;
    }

    /**
     * Whether the active theme gave us anything usable.
     *
     * @return bool
     */
    public static function available(): bool
    {
        return count(self::palette()) > 0;
    }

    /**
     * Whether the theme told us anything at all to inherit from.
     *
     * A theme can publish a palette we cannot read — several popular ones
     * declare theirs as `var(--theme-colour-0)` references — and it can set a
     * global background and text colour without publishing a palette. A site
     * can also state nothing but its button colours (Styles → Buttons in the
     * site editor) — a background, a text, or both — and that is still an
     * explicit configuration to wear.
     * Only when all of them come back empty is there genuinely nothing to
     * inherit, and in that case inheriting must stay out of the way rather
     * than write FluentCart's own colours back to the page and call them the
     * theme's.
     *
     * @return bool
     */
    public static function hasUsableSource(): bool
    {
        if (self::available()) {
            return true;
        }

        $globals = self::globalColors();

        if (Arr::get($globals, 'background', '') !== '' || Arr::get($globals, 'text', '') !== '') {
            return true;
        }

        if (self::settingsRoles()) {
            return true;
        }

        $button = self::buttonGlobals();

        return Arr::get($button, 'background', '') !== '' || Arr::get($button, 'text', '') !== '';
    }

    /**
     * Look up one palette colour by slug.
     *
     * @param string $slug
     * @return string Hex, or '' when the slug is unknown.
     */
    public static function color(string $slug): string
    {
        return self::lookup($slug, 'color');
    }

    /**
     * What one palette slug resolves to for writing into CSS.
     *
     * Unlike color(), this can be a custom-property reference — use it when
     * emitting a declaration, and color() when the value has to be reasoned
     * about.
     *
     * @param string $slug
     * @return string Hex, a var() reference, or '' when the slug is unknown.
     */
    public static function value(string $slug): string
    {
        return self::lookup($slug, 'value');
    }

    /**
     * Read one field off a palette entry.
     *
     * @param string $slug
     * @param string $field
     * @return string
     */
    protected static function lookup(string $slug, string $field): string
    {
        if ($slug === '') {
            return '';
        }

        foreach (self::palette() as $entry) {
            if (Arr::get($entry, 'slug') === $slug) {
                return (string)Arr::get($entry, $field, '');
            }
        }

        return '';
    }

    /**
     * The theme's global background and text colours, when it sets them.
     *
     * @return array ['background' => hex, 'text' => hex]; either may be ''.
     */
    public static function globalColors(): array
    {
        $colors = ['background' => '', 'text' => ''];

        if (!function_exists('wp_get_global_styles')) {
            return $colors;
        }

        $styles = wp_get_global_styles(['color']);

        if (!is_array($styles)) {
            return $colors;
        }

        $colors['background'] = self::resolveReference(Arr::get($styles, 'background', ''));
        $colors['text'] = self::resolveReference(Arr::get($styles, 'text', ''));

        return $colors;
    }

    /**
     * The button pair the theme declares and the site editor edits.
     *
     * Styles → Buttons in the site editor saves to
     * `styles.elements.button.color`, and block themes ship their own pair
     * there in theme.json — the most explicit statement either can make
     * about the buttons. The two origins merge per property, the owner's
     * edits over the theme's, which is exactly how WordPress itself paints
     * the button. Core's origin is left out on purpose: WordPress ships a
     * default button colour (#32373c) for every site, and counting that as
     * "the theme said something" would mean no theme could ever stand aside.
     * Backgrounds usually arrive as a preset reference
     * (`var:preset|color|contrast`), which resolves through the palette the
     * same way the page pair's do.
     *
     * The `:hover` pair (`styles.elements.button.:hover.color`) is read the
     * same way, into `hover_background` / `hover_text`.
     *
     * @return array ['background' => hex, 'text' => hex, 'hover_background' => hex,
     *               'hover_text' => hex]; any may be ''.
     */
    public static function buttonGlobals(): array
    {
        $colors = ['background' => '', 'text' => '', 'hover_background' => '', 'hover_text' => ''];

        if (!class_exists('WP_Theme_JSON_Resolver')) {
            return $colors;
        }

        foreach (['get_theme_data', 'get_user_data'] as $origin) {
            if (!method_exists('WP_Theme_JSON_Resolver', $origin)) {
                continue;
            }

            $data = \WP_Theme_JSON_Resolver::$origin();

            if (!is_object($data) || !method_exists($data, 'get_raw_data')) {
                continue;
            }

            $raw = (array)$data->get_raw_data();
            $pairs = [
                ''       => Arr::get($raw, 'styles.elements.button.color', []),
                'hover_' => Arr::get($raw, 'styles.elements.button.:hover.color', []),
            ];

            foreach ($pairs as $prefix => $pair) {
                foreach (['background', 'text'] as $half) {
                    $value = self::resolveReference((string)Arr::get((array)$pair, $half, ''));

                    if ($value !== '') {
                        $colors[$prefix . $half] = $value;
                    }
                }
            }
        }

        return $colors;
    }

    /**
     * Resolve a theme.json colour, which may be a preset reference rather
     * than a literal colour.
     *
     * @param string $value
     * @return string Hex, or ''.
     */
    protected static function resolveReference($value): string
    {
        $value = trim((string)$value);

        if ($value === '') {
            return '';
        }

        if (preg_match('/var:preset\|color\|([\w-]+)/', $value, $matches)) {
            return self::color($matches[1]);
        }

        if (preg_match('/var\(\s*--wp--preset--color--([\w-]+)/', $value, $matches)) {
            return self::color($matches[1]);
        }

        return ColorMath::hex($value);
    }

    /**
     * Which palette slug each anchor role resolves to.
     *
     * @return array Role => palette slug, '' when nothing matched.
     */
    public static function anchorMap(): array
    {
        $map = [];

        foreach (self::$anchorCandidates as $role => $slugs) {
            $map[$role] = '';

            foreach ($slugs as $slug) {
                // value(), not color(): a slug the theme publishes only as a
                // reference still counts as a colour the theme offers.
                if (self::value($slug) !== '') {
                    $map[$role] = $slug;
                    break;
                }
            }
        }

        /**
         * Filter which theme palette slug drives each anchor role.
         *
         * @param array $map Role => palette slug.
         */
        return apply_filters('fluent_cart/theme/anchor_map', $map);
    }

    /**
     * The four anchors, exactly as far as the theme could be read.
     *
     * An anchor the theme did not supply comes back empty rather than standing
     * in FluentCart's own colour for it. The stylesheet that uses the property
     * already carries that colour as its `var(--fct-x, <fallback>)` fallback,
     * so substituting it here would only mean writing the same value twice —
     * and writing it under the active theme's name, which is how a store on a
     * theme nothing could be read from came to report itself as inheriting
     * while wearing FluentCart's palette.
     *
     * @return array Role => hex, a custom-property reference, or '' when the
     *               theme said nothing about it.
     */
    public static function anchors(): array
    {
        $map = self::anchorMap();
        $globals = self::globalColors();
        $settings = self::settingsRoles();

        // Global styles outrank the palette — the palette lists the AVAILABLE
        // colours, global styles say what the body actually RENDERS, and on
        // Twenty Twenty-Five's dark style variation those disagree. The
        // surface comes from global styles, then the theme's settings, then
        // the palette. A body text the owner stated is worn as given below,
        // whichever source the surface came from.
        $gsSurface = (string)Arr::get($globals, 'background', '');
        $gsText = (string)Arr::get($globals, 'text', '');

        $setSurface = (string)Arr::get($settings, 'surface', '');

        if ($gsSurface !== '' && $gsText !== '') {
            // The rendered pair.
            $surface = $gsSurface;
            $text = $gsText;
        } elseif ($setSurface !== '') {
            // The pair the theme's settings state (Astra's Content Background
            // and Body Text). A surface stated without a text takes the
            // palette's own text when it reads there (4.5:1) — Astra saves
            // Body Text empty and still means its text swatch — and only an
            // unreadable one is replaced by a measured partner.
            $surface = $setSurface;
            $text = (string)Arr::get($settings, 'text', '');
            $surfaceHex = self::measurable($surface);

            if ($text === '' && $surfaceHex !== '') {
                $paletteText = self::value(Arr::get($map, 'text', ''));
                $paletteTextHex = self::measurable($paletteText);

                $text = $paletteTextHex !== '' && ColorMath::contrast($surfaceHex, $paletteTextHex) >= 4.5
                    ? $paletteText
                    : ColorMath::readableOn($surfaceHex, '#F3F4F6', '#2F3448');
            }
        } else {
            // The published pair.
            $surface = self::value(Arr::get($map, 'surface', ''));
            $text = self::value(Arr::get($map, 'text', ''));

            if ($text === '' && ColorMath::hex($surface) !== '') {
                // A lone measurable surface derives its partner by contrast,
                // the same way button text does — leaving it unwritten would
                // drop the stylesheets' dark fallback text onto a surface
                // that may itself be dark.
                $text = ColorMath::readableOn($surface, '#F3F4F6', '#2F3448');
            }
        }

        // A body text the owner chose is worn as given, even without a stated
        // background — global styles first, then the theme's settings.
        $statedText = $gsText !== '' ? $gsText : (string)Arr::get($settings, 'text', '');

        if ($statedText !== '') {
            $text = $statedText;
        }

        $accent = (string)Arr::get($settings, 'accent', '');

        if ($accent === '') {
            $accent = self::value(Arr::get($map, 'accent', ''));
        }

        // What the owner set in the site editor (Styles → Buttons) outranks
        // every guess the palette could offer, then the theme's settings. A
        // background brings its own text, or gets one measured against it in
        // resolve(); a text the owner set alone is worn as given on whatever
        // background the button otherwise gets.
        $button = self::buttonGlobals();
        $buttonText = '';

        if ($button['background'] !== '') {
            $buttonBg = $button['background'];
            $buttonText = $button['text'];
        } elseif (Arr::get($settings, 'button_bg', '') !== '') {
            // Next, the button the theme's settings state, with its partner.
            $buttonBg = (string)$settings['button_bg'];
            $buttonText = (string)Arr::get($settings, 'button_text', '');
        } else {
            $buttonBg = self::value(Arr::get($map, 'button_bg', ''));

            if ($buttonBg === '') {
                // Not a default — the theme's own accent, which is what a
                // theme that names one button colour almost always means by it.
                $buttonBg = $accent;
            }

            $buttonText = $button['text'] !== '' ? $button['text'] : (string)Arr::get($settings, 'button_text', '');
        }

        return [
            'surface'     => $surface,
            'text'        => $text,
            'accent'      => $accent,
            'button_bg'   => $buttonBg,
            'button_text' => $buttonText,
        ];
    }

    /**
     * Resolve every semantic role the theme can actually supply.
     *
     * The first block is what the theme said. The second is derived from it —
     * no theme publishes a hairline border or a placeholder tone, so those are
     * mixed out of the body text and the surface.
     *
     * Deriving needs two real colours to mix between. A reference cannot be
     * read here (the browser resolves it on the page, where we are not), and an
     * anchor the theme never supplied is not a colour at all, so in both cases
     * the derived roles come back empty and nothing is written for them. The
     * stylesheet's own fallback is then what applies, which is the same colour
     * it would have been given — arrived at once instead of twice.
     *
     * @return array Role => hex, a reference, or '' when it could not be
     *               resolved.
     */
    public static function resolve(): array
    {
        if (self::$cachedRoles !== null) {
            return self::$cachedRoles;
        }

        $anchors = self::anchors();
        $surface = (string)Arr::get($anchors, 'surface', '');
        $text = (string)Arr::get($anchors, 'text', '');
        $buttonBg = (string)Arr::get($anchors, 'button_bg', '');

        // Measured, not taken literally: a reference with a hex fallback mixes
        // as the fallback the theme shipped, so a Customify-style palette
        // derives instead of standing down.
        $textHex = self::measurable($text);
        $surfaceHex = self::measurable($surface);
        $mixable = $textHex !== '' && $surfaceHex !== '';

        $derived = $mixable
            ? [
                'surface_alt'      => ColorMath::mix($textHex, $surfaceHex, 4),
                'surface_mute'     => ColorMath::mix($textHex, $surfaceHex, 8),
                'divider'          => ColorMath::mix($textHex, $surfaceHex, 10),
                'border'           => ColorMath::mix($textHex, $surfaceHex, 18),
                'text_placeholder' => ColorMath::mix($textHex, $surfaceHex, 45),
                // Muted text is still text: it moves toward the body text only
                // as far as it must to read (4.5:1). A mid-grey body text
                // (Divi's #666666) otherwise mixed down to 2.92:1.
                'text_muted'       => ColorMath::readableMix($textHex, $surfaceHex, 68),
            ]
            : [
                'surface_alt'      => '',
                'surface_mute'     => '',
                'divider'          => '',
                'border'           => '',
                'text_placeholder' => '',
                'text_muted'       => '',
            ];

        // A border the theme's settings state is the border (Astra's Borders);
        // only an unstated one is mixed.
        $settings = self::settingsRoles();

        if (Arr::get($settings, 'border', '') !== '') {
            $derived['border'] = (string)$settings['border'];
        }

        // Contrast needs a real colour to measure against for the same reason.
        // Colours the owner chose (the site editor's, or a theme settings
        // reader's) are worn exactly as given, at any contrast; only a missing
        // one is measured. A background nobody chose whose value cannot be
        // measured (a bare palette reference) is not worn at all: no readable
        // text can be paired with it here, so the stylesheets' own fallback
        // styles the button instead. One the owner chose is still worn, with
        // nothing invented beside it.
        $buttonBgHex = self::measurable($buttonBg);
        $ownText = (string)Arr::get($anchors, 'button_text', '');
        $stated = self::buttonGlobals();
        $buttonStated = $stated['background'] !== '' || Arr::get($settings, 'button_bg', '') !== '';

        if ($buttonBgHex !== '') {
            $derived['button_text'] = $ownText !== '' ? $ownText : ColorMath::readableText($buttonBgHex);
        } else {
            if (!$buttonStated) {
                $anchors['button_bg'] = '';
            }

            $derived['button_text'] = $ownText;
        }

        // The hover follows the button it belongs to: unwritten when the button
        // is. The site editor's `:hover` pair outranks any guess; otherwise the
        // button colour moves away from itself — darker for a light button,
        // lighter for a dark one, and never for a button that cannot be
        // measured. Hover text given with it is worn as given; otherwise the
        // resting text carries over while it still reads (WCAG 4.5:1), and a
        // partner is measured when it does not.
        $derived['button_hover_bg'] = '';
        $derived['button_hover_text'] = '';

        if ($buttonBgHex !== '' || $buttonStated) {
            $hoverBg = $stated['hover_background'];
            $hoverText = $stated['hover_text'];

            // The theme settings' hover belongs to the theme settings' button:
            // it is only used when that button is the one being worn, never
            // paired onto a button the site editor states.
            $settingsButton = $stated['background'] === '' && Arr::get($settings, 'button_bg', '') !== '';

            if ($hoverBg === '' && $settingsButton && Arr::get($settings, 'button_hover_bg', '') !== '') {
                $hoverBg = (string)$settings['button_hover_bg'];

                if ($hoverText === '') {
                    $hoverText = (string)Arr::get($settings, 'button_hover_text', '');
                }
            }

            if ($hoverBg === '' && $buttonBgHex !== '') {
                $hoverBg = ColorMath::shiftFromItself($buttonBgHex, 12);
            }

            $hoverBgHex = self::measurable($hoverBg);

            if ($hoverText === '' && $hoverBgHex !== '') {
                $hoverText = self::hoverTextFor($hoverBgHex, (string)$derived['button_text']);
            }

            $derived['button_hover_bg'] = $hoverBg;
            $derived['button_hover_text'] = $hoverText;
        }

        // The outline secondary button is FluentCart's own invention, and its
        // outline is the hierarchy: it always keeps the page surface as its
        // background. Wearing the theme's stated pair outright painted BOTH
        // CTAs identically (Twenty Twenty-Five states black-on-white, so Add
        // to Cart went black beside a black Buy Now). Instead the label
        // borrows a half of the pair AS WORN by the primary button (stated
        // text, or the measured partner of a lone stated background) —
        // whichever half reads better on the page surface, and only when
        // that half actually reads (WCAG 4.5:1); otherwise the page's own
        // text stays, since a borrowed label that cannot be read matches
        // nothing worth matching. Measurement uses the surface's hex, which
        // for a reference is its fallback — the theme's own best answer, the
        // same trust every derived tone already extends (spec 59).
        $derived['secondary_button_bg'] = $surface;
        $derived['secondary_button_text'] = $text;

        if (self::buttonGlobals()['background'] !== '' && $buttonBgHex !== '' && $surfaceHex !== '') {
            $pairText = (string)$derived['button_text'];

            $label = ColorMath::contrast($surfaceHex, $pairText) >= ColorMath::contrast($surfaceHex, $buttonBgHex)
                ? $pairText
                : $buttonBgHex;

            if (ColorMath::contrast($surfaceHex, $label) >= 4.5) {
                $derived['secondary_button_text'] = $label;
            }
        }

        /**
         * Filter the resolved semantic role colours.
         *
         * @param array $roles Role => hex.
         */
        self::$cachedRoles = apply_filters('fluent_cart/theme/roles', array_merge($anchors, $derived));

        return self::$cachedRoles;
    }

    /**
     * The text for a hover background nobody gave a text: the button's
     * resting text carries over while it still reads (WCAG 4.5:1), and a
     * partner is measured when it does not.
     *
     * @param string $hoverBgHex
     * @param string $restingText
     * @return string
     */
    public static function hoverTextFor(string $hoverBgHex, string $restingText): string
    {
        return ColorMath::contrast($hoverBgHex, $restingText) >= 4.5
            ? $restingText
            : ColorMath::readableText($hoverBgHex);
    }

    /**
     * A one-line description of what the active theme offers, shown under the
     * inherit option so the store owner knows whether it is worth picking.
     *
     * @return string
     */
    public static function sourceLabel(): string
    {
        $count = count(self::palette());

        if (!$count) {
            return __('The active theme does not publish a colour palette, so inheriting would fall back to FluentCart\'s own colours.', 'fluent-cart');
        }

        $theme = wp_get_theme();

        return sprintf(
            /* translators: 1: active theme name, 2: number of palette colours the theme publishes */
            _n('%1$s provides %2$d palette colour.', '%1$s provides %2$d palette colours.', $count, 'fluent-cart'),
            $theme->get('Name'),
            $count
        );
    }
}

<?php

namespace Tests\Integration\Services\Theme;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Services\Theme\ColorPalette;
use FluentCart\App\Services\Theme\FrontendTheme;
use FluentCart\App\Services\Theme\ThemePalette;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * How the storefront body says which colour source is in force.
 *
 * The custom properties FrontendTheme prints only reach surfaces FluentCart
 * already styles. A class on the body reaches everything else — a theme that
 * wants to stand down when the store owner has picked their own colours, a
 * child theme that only needs to adjust one store, a snippet in the customiser.
 * None of those can read the setting, and all of them can write a selector.
 *
 * The theme slug rides along with `inherit_from_theme` for the same reason: it
 * is the one source whose result depends on which theme is active, so it is the
 * one that needs `body.fluent-cart-custom.astra` to be expressible.
 */
class BodyClassTest extends WPTestCase
{
    const OPTION = 'fluent_cart_store_settings';

    protected function setUp(): void
    {
        parent::setUp();
        $this->reset();
    }

    protected function tearDown(): void
    {
        delete_option(self::OPTION);
        $this->reset();
        parent::tearDown();
    }

    protected function reset(): void
    {
        ColorPalette::clearCache();
        ThemePalette::clearCache();
        StoreSettings::clearCache();
    }

    /**
     * Put the store on one source and read back what the body would carry.
     *
     * Read through the `body_class` filter rather than by calling the method,
     * so these also prove the classes are actually wired to the hook that puts
     * them on the page.
     *
     * @param string $source
     * @return array
     */
    protected function classesFor(string $source): array
    {
        update_option(self::OPTION, [
            'appearance_source' => $source,
            'appearance_colors' => [],
        ]);

        $this->reset();

        return apply_filters('body_class', ['existing-theme-class'], []);
    }

    /**
     * Spec: Cases/storefront-colours.md [46]
     *   "FluentCart's own colours mark the body as FluentCart-styled."
     */
    public function test_the_default_source_marks_the_body_as_fluentcart_styled(): void
    {
        $classes = $this->classesFor(ColorPalette::SOURCE_DEFAULT);

        $this->assertContains('fluent-cart-theme', $classes, 'FluentCart\'s own colours are FluentCart\'s own styling');
        $this->assertNotContains('fluent-cart-custom', $classes, 'nothing has been customised, so nothing should say so');
        $this->assertContains('existing-theme-class', $classes, 'the classes already on the body are not ours to drop');
    }

    /**
     * Spec: Cases/storefront-colours.md [47]
     *   "Inheriting marks the body as customised and names the active theme."
     */
    public function test_inheriting_marks_the_body_and_names_the_theme(): void
    {
        add_filter('template', function () {
            return 'kadence';
        });

        $classes = $this->classesFor(ColorPalette::SOURCE_THEME);

        $this->assertContains('fluent-cart-custom', $classes, 'inheriting is a departure from FluentCart\'s own colours');
        $this->assertNotContains('fluent-cart-theme', $classes, 'the two markers are alternatives, never both');

        // This is the only source whose outcome depends on which theme is
        // active, so it is the only one where naming the theme buys anything.
        $this->assertContains('kadence', $classes, 'the active theme has to be nameable in a selector');
    }

    /**
     * Spec: Cases/storefront-colours.md [48]
     *   "Customising marks the body as customised without naming a theme."
     */
    public function test_customising_marks_the_body_without_naming_a_theme(): void
    {
        add_filter('template', function () {
            return 'kadence';
        });

        $classes = $this->classesFor(ColorPalette::SOURCE_CUSTOM);

        $this->assertContains('fluent-cart-custom', $classes, 'colours the owner picked are customised');
        $this->assertNotContains('fluent-cart-theme', $classes, 'the two markers are alternatives, never both');

        // The owner's colours are the owner's colours whatever theme is active,
        // so naming one here would invite a selector that breaks on switching
        // theme for no reason.
        $this->assertNotContains('kadence', $classes, 'this source does not depend on the theme, so it must not name one');
    }

    /**
     * Spec: Cases/storefront-colours.md [49]
     *   "A child theme is named by the theme it is built on."
     */
    public function test_a_child_theme_is_named_by_its_parent(): void
    {
        // Most real stores run a child theme. Its stylesheets are the parent's
        // plus a few overrides, so a rule written for `astra` is what the store
        // actually needs — naming `astra-child` would leave that rule matching
        // nothing on the sites most likely to want it.
        add_filter('template', function () {
            return 'astra';
        });
        add_filter('stylesheet', function () {
            return 'astra-child';
        });

        $classes = $this->classesFor(ColorPalette::SOURCE_THEME);

        $this->assertContains('astra', $classes, 'the parent is where the styling comes from');
        $this->assertNotContains('astra-child', $classes, 'a rule written for the parent has to match on a child theme');
    }

    /**
     * Spec: Cases/storefront-colours.md [50]
     *   "A theme slug that is not safe as a class name is not written as one."
     */
    public function test_an_unsafe_theme_slug_is_not_written_as_a_class(): void
    {
        // A directory name is not a class name. It reaches the page inside a
        // class attribute, so it goes through sanitize_html_class() first —
        // and a slug that survives that as nothing must not leave a stray
        // empty class behind.
        add_filter('template', function () {
            return '<script>alert(1)</script>';
        });

        $classes = $this->classesFor(ColorPalette::SOURCE_THEME);

        $this->assertContains('fluent-cart-custom', $classes, 'the source is still marked');
        $this->assertNotContains('', $classes, 'an unusable slug adds nothing rather than an empty class');

        foreach ($classes as $class) {
            $this->assertStringNotContainsString('<', $class, 'nothing that could break out of the attribute reaches it');
        }
    }

    /**
     * Spec: Cases/storefront-colours.md [57]
     *   "Inheriting from a theme nothing could be read from as one the
     *    theme styles itself."
     */
    public function test_inheriting_from_an_unreadable_theme_marks_the_body_no_colors(): void
    {
        // Sydney's shape: a palette full of references in a vocabulary nothing
        // recognises. No anchor resolves, so FrontendTheme writes no colours at
        // all — and an empty custom property cannot hand the button to the
        // theme, because the losing declaration was discarded at cascade time.
        // Only the absence of FluentCart's own colour declarations lets the
        // theme's rules apply, and this class is what the stylesheets key that
        // absence on.
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'global_color_1', 'name' => 'Global 1', 'color' => 'var(--sydney-global-color-1)'],
                ['slug' => 'global_color_9', 'name' => 'Global 9', 'color' => 'var(--sydney-global-color-9)'],
            ];
        });

        $classes = $this->classesFor(ColorPalette::SOURCE_THEME);

        $this->assertContains('fluent-cart-no-colors', $classes, 'a theme we write nothing for styles the store itself');
        $this->assertContains('fluent-cart-custom', $classes, 'the source is still inheritance, so the source marker stays');
    }

    /**
     * Spec: Cases/storefront-colours.md [58]
     *   "The no-colors marker never appears when FluentCart is writing colours."
     */
    public function test_the_no_colors_marker_never_appears_when_colours_are_written(): void
    {
        // A palette that resolves. FluentCart writes the theme's colours
        // through its own declarations, so those declarations must stay — the
        // marker here would strip the very rules carrying the theme's palette.
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Base', 'color' => '#f9f9f9'],
                ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
                ['slug' => 'primary', 'name' => 'Primary', 'color' => '#7c3aed'],
            ];
        });

        $this->assertNotContains(
            'fluent-cart-no-colors',
            $this->classesFor(ColorPalette::SOURCE_THEME),
            'a readable theme is carried by our declarations, which must not be stripped'
        );
        $this->assertNotContains(
            'fluent-cart-no-colors',
            $this->classesFor(ColorPalette::SOURCE_CUSTOM),
            'customised colours are FluentCart declarations by definition'
        );
        $this->assertNotContains(
            'fluent-cart-no-colors',
            $this->classesFor(ColorPalette::SOURCE_DEFAULT),
            'and so are FluentCart\'s own'
        );
    }

    /**
     * Spec: Cases/storefront-colours.md [51]
     *   "An unrecognised source is marked the same way as FluentCart's own
     *    colours."
     */
    public function test_an_unrecognised_source_is_marked_as_the_default(): void
    {
        // getSource() already falls back to the default for a stored value it
        // does not recognise, so the class has to agree with it. Disagreeing
        // would put a page in FluentCart's colours while telling stylesheets it
        // had been customised.
        $classes = $this->classesFor('something_that_is_not_a_source');

        $this->assertContains('fluent-cart-theme', $classes, 'an unknown source falls back to FluentCart\'s colours, so it is marked that way');
        $this->assertNotContains('fluent-cart-custom', $classes, 'and must not claim to be customised');
    }
}

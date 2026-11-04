<?php

namespace Tests\Integration\Services\Theme;

use FluentCart\App\Services\Theme\ColorMath;
use FluentCart\App\Services\Theme\ColorPalette;
use FluentCart\App\Services\Theme\ThemePalette;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * The registry is the single source of truth for three consumers: the CSS the
 * storefront prints, the settings schema the admin renders, and the sanitizer
 * that decides which submitted keys are real. A registry entry that names a
 * property nothing reads, or a role nothing resolves, is a colour the store
 * owner can set and never see — so the integrity of this list is the feature.
 */
class ColorPaletteTest extends WPTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ColorPalette::clearCache();
        ThemePalette::clearCache();
    }

    protected function tearDown(): void
    {
        ColorPalette::clearCache();
        ThemePalette::clearCache();
        parent::tearDown();
    }

    /**
     * Spec: Cases/storefront-colours.md [16]
     *   "Every global in the registry names a real CSS custom property and a
     *    group that exists."
     */
    public function test_every_global_names_a_property_and_a_known_group(): void
    {
        $groups = ColorPalette::groups();
        $globals = ColorPalette::globals();

        $this->assertNotEmpty($globals, 'the registry drives the whole feature and cannot be empty');

        foreach ($globals as $key => $definition) {
            $this->assertMatchesRegularExpression(
                '/^--fct-[a-z0-9-]+$/',
                $definition['var'],
                "global '{$key}' must name a FluentCart custom property"
            );
            $this->assertArrayHasKey(
                $definition['group'],
                $groups,
                "global '{$key}' is filed under a group the settings screen does not render"
            );
            $this->assertNotSame('', (string) $definition['label'], "global '{$key}' needs a label for the picker");
        }
    }

    /**
     * Spec: Cases/storefront-colours.md [18]
     *   "Every semantic role a global claims is a role the theme resolver
     *    produces."
     */
    public function test_every_claimed_role_is_one_the_theme_resolver_produces(): void
    {
        // Without this, inheriting from a theme silently skips the global —
        // it looks like the theme simply had no colour for it.
        $resolved = array_keys(ThemePalette::resolve());
        $declared = array_keys(ColorPalette::roles());

        foreach (ColorPalette::globals() as $key => $definition) {
            $role = $definition['role'];

            $this->assertNotSame('', $role, "global '{$key}' must claim a role or theme inheritance skips it");
            $this->assertContains($role, $declared, "global '{$key}' claims role '{$role}', which is not declared");
            $this->assertContains($role, $resolved, "global '{$key}' claims role '{$role}', which the resolver never produces");
        }
    }

    /**
     * Spec: Cases/storefront-colours.md [19]
     *   "No two globals write the same CSS custom property."
     */
    public function test_no_two_globals_write_the_same_property(): void
    {
        $seen = [];

        foreach (ColorPalette::globals() as $key => $definition) {
            foreach (ColorPalette::varsOf($definition) as $property) {
                $this->assertArrayNotHasKey(
                    $property,
                    $seen,
                    "'{$key}' and '" . ($seen[$property] ?? '') . "' both write {$property}; the later one would silently win"
                );
                $seen[$property] = $key;
            }
        }
    }
}

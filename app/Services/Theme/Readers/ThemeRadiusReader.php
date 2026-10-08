<?php

namespace FluentCart\App\Services\Theme\Readers;

/**
 * Reads the border radii a theme's owner set in the theme's own settings.
 *
 * A separate contract from ThemeSettingsReader on purpose: adding a method to
 * that interface would break every third-party class implementing it. A
 * reader that can read a radius implements both.
 *
 * Like the colours, a reader states only what the theme states: a role the
 * theme has no setting for is omitted, never guessed, and FluentCart prints
 * nothing for it.
 */
interface ThemeRadiusReader
{
    /**
     * Role => the length the theme states, as the theme prints it.
     *
     * Roles: card, btn, input (RadiusPalette::roles()). ThemePalette::radii()
     * normalises each value through RadiusPalette::sanitizeLength(), so a
     * value outside that grammar (a percentage, calc(), several corners) is
     * dropped there; return '' or omit the role rather than guess.
     *
     * @return array
     */
    public static function radii(): array;
}

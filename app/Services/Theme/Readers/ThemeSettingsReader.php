<?php

namespace FluentCart\App\Services\Theme\Readers;

/**
 * Reads the colours a theme's owner set in the theme's own settings.
 *
 * Palette slots only say which colours a theme offers; settings say which of
 * them the owner put where. A reader reports the second, so inheritance
 * follows the owner's Accent, Body Text, Content Background and Button choices
 * instead of guessing them from slot positions.
 *
 * One reader per vendor. Each states only what the theme actually states: a
 * role the reader cannot read is omitted, never guessed, and ThemePalette
 * derives it exactly as it would without a reader.
 */
interface ThemeSettingsReader
{
    /**
     * Whether this reader applies to the running site.
     *
     * Detect by capability (the vendor's API), not by theme name, so child
     * themes and renamed theme folders keep working.
     *
     * @return bool
     */
    public static function applies(): bool;

    /**
     * Role => value for the roles the theme states.
     *
     * Roles: surface, text, accent, border, button_bg, button_text,
     * button_hover_bg, button_hover_text. A value is a hex, or a live
     * reference with its measurable fallback (`var(--x, #hex)`) — normalise
     * each through ThemePalette::settingValue().
     *
     * @return array
     */
    public static function roles(): array;
}

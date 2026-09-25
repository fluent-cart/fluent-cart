<?php

namespace FluentCart\App\Services\DateTime;

use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\Framework\Support\Arr;

/**
 * Converts the store's PHP date formats into Day.js patterns for the admin SPA
 * and the customer dashboard.
 *
 * The SPA already receives translated month and weekday names, but the pattern
 * itself was a literal inside the bundle, so a localized store ended up with
 * translated names in English order -- "August 16, 2026" on a German site.
 * Shipping the store's own format alongside the names is what fixes that.
 *
 * DateFormatter owns the PHP side; this class is only the bridge to Day.js.
 */
class DayjsFormatter
{
    /**
     * Every character PHP's date() treats as a format token.
     *
     * Used to tell tokens apart from literal separators when deriving one
     * format from another, and to drop a token Day.js cannot express.
     */
    protected static $dateTokens = 'dDjlNSwzWFmMntLoXxYyaABgGhHisuveIOPpTZcrU';

    /**
     * PHP date() token => Day.js token.
     *
     * A PHP token absent from this map has no Day.js equivalent and is dropped.
     * 'S' (ordinal suffix) is handled separately because Day.js folds it into
     * the day token itself ('jS' => 'Do').
     */
    protected static $tokenMap = [
        // Day
        'd' => 'DD',
        'j' => 'D',
        'D' => 'ddd',
        'l' => 'dddd',
        'N' => 'd',
        'w' => 'd',
        'z' => 'DDD',
        // Week
        'W' => 'w',
        // Month
        'F' => 'MMMM',
        'm' => 'MM',
        'M' => 'MMM',
        'n' => 'M',
        // Year
        'o' => 'YYYY',
        'X' => 'YYYY',
        'x' => 'YYYY',
        'Y' => 'YYYY',
        'y' => 'YY',
        // Time
        'a' => 'a',
        'A' => 'A',
        'g' => 'h',
        'G' => 'H',
        'h' => 'hh',
        'H' => 'HH',
        'i' => 'mm',
        's' => 'ss',
        'u' => 'SSS',
        'v' => 'SSS',
        // Timezone
        'e' => 'z',
        'O' => 'ZZ',
        'P' => 'Z',
        'p' => 'Z',
        'T' => 'z',
        // Full date/time
        'c' => 'YYYY-MM-DDTHH:mm:ssZ',
        'r' => 'ddd, DD MMM YYYY HH:mm:ss ZZ',
        'U' => 'X',
    ];

    /**
     * The date/time map for the SPA payload, with Day.js patterns.
     *
     * Same map PHP reads, except the formats are converted from PHP date()
     * syntax to Day.js tokens. Localize this rather than the raw map, or the
     * bundle would receive patterns it cannot parse.
     *
     * @return array<string, mixed>
     */
    public static function localizedStrings(): array
    {
        $strings = TransStrings::dateTimeStrings();

        $strings['formats'] = static::formats();

        return $strings;
    }

    /**
     * The store's formats as Day.js patterns, keyed for the JS helpers.
     *
     * date_short and month_year have no WordPress setting of their own; they
     * are derived from the store's date format so that a compact table column
     * or a chart label keeps the locale's own field order.
     *
     * @return array<string, string>
     */
    public static function formats(): array
    {
        $formats = DateFormatter::formats();

        $date     = (string)Arr::get($formats, 'date', DateFormatter::FALLBACK_DATE);
        $time     = (string)Arr::get($formats, 'time', DateFormatter::FALLBACK_TIME);
        $dateTime = (string)Arr::get($formats, 'date_time', $date . ' ' . $time);

        return [
            'date'       => static::convert($date),
            'date_short' => static::convert(static::abbreviateMonth(static::withoutYear($date))),
            'time'       => static::convert($time),
            'date_time'  => static::convert($dateTime),
            'month_year' => static::convert(static::withoutDay($date)),
            'month_long' => 'MMMM',
        ];
    }

    /**
     * Convert a PHP date() format string to its Day.js equivalent.
     *
     * Characters that are not PHP tokens are emitted as Day.js literals, so a
     * stray letter in a translated format is not read back as a token.
     */
    public static function convert(string $format): string
    {
        $out      = '';
        $literals = '';
        $length   = strlen($format);

        for ($i = 0; $i < $length; $i++) {
            $char = $format[$i];

            // A backslash escapes the next character in PHP date formats.
            if ($char === '\\') {
                if ($i + 1 < $length) {
                    $i++;
                    $literals .= $format[$i];
                }
                continue;
            }

            // 'S' renders the ordinal suffix of the preceding day token.
            // Day.js has no separate suffix token, so fold 'D'/'DD' into 'Do'.
            if ($char === 'S') {
                if (substr($out, -2) === 'DD') {
                    $out = substr($out, 0, -2) . 'Do';
                } elseif (substr($out, -1) === 'D') {
                    $out = substr($out, 0, -1) . 'Do';
                }
                continue;
            }

            if (isset(static::$tokenMap[$char])) {
                if ($literals !== '') {
                    $out .= '[' . $literals . ']';
                    $literals = '';
                }
                $out .= static::$tokenMap[$char];
                continue;
            }

            // A PHP token Day.js cannot express is dropped rather than emitted
            // as a bare letter, which Day.js would read as some other token.
            if (strpos(static::$dateTokens, $char) !== false) {
                continue;
            }

            // Non-token letters must be escaped or Day.js would read them as tokens.
            if (preg_match('/[A-Za-z]/', $char)) {
                $literals .= $char;
                continue;
            }

            if ($literals !== '') {
                $out .= '[' . $literals . ']';
                $literals = '';
            }
            $out .= $char;
        }

        if ($literals !== '') {
            $out .= '[' . $literals . ']';
        }

        return $out;
    }

    /**
     * Drop the year from a PHP date format, keeping the locale's day/month order.
     *
     * Used for compact table columns that omit the year for the current year.
     *
     * Public because this takes and returns a PHP date format, not a Day.js one
     * -- DateFormatter derives its own month-year pattern from the same helper
     * so the e-mail digest and the charts agree on the locale's field order.
     */
    public static function withoutYear(string $format): string
    {
        return static::dropTokens($format, ['Y', 'y', 'o', 'X', 'x']);
    }

    /**
     * Drop the day from a PHP date format, leaving month and year.
     */
    public static function withoutDay(string $format): string
    {
        return static::dropTokens($format, ['d', 'j', 'D', 'l', 'N', 'w', 'z', 'S']);
    }

    /**
     * Replace the full month-name token with the abbreviated one.
     *
     * date_short is for compact table columns, where a German store's
     * "16. August" is wider than the column needs. Swapping the PHP token
     * BEFORE convert() keeps this generic across locales: 'F' becomes 'M',
     * which convert()'s existing token map already renders as Day.js 'MMM'
     * (the store's own translated monthsShort), the same way it always has
     * for a store whose date format already spells the month short. A
     * numeric month token ('n', 'm') is left untouched -- a store that
     * writes the month as a digit should stay numeric, not gain a name.
     */
    public static function abbreviateMonth(string $format): string
    {
        $out    = '';
        $length = strlen($format);

        for ($i = 0; $i < $length; $i++) {
            $char = $format[$i];

            // A backslash escapes the next character; never touch it, or an
            // intentional literal "F" would be shortened like a real token.
            if ($char === '\\') {
                if ($i + 1 < $length) {
                    $out .= $char . $format[$i + 1];
                    $i++;
                }
                continue;
            }

            $out .= $char === 'F' ? 'M' : $char;
        }

        return $out;
    }

    /**
     * Remove the given tokens, plus the separator their removal left stranded.
     *
     * The format is split into token and literal parts first. When a token is
     * dropped, the separator that FOLLOWS it goes too -- or the one that
     * precedes it, when the dropped token was last. That keeps suffix-style
     * locales such as the Japanese 'Y\u5e74n\u6708j\u65e5' intact instead of
     * shaving off their trailing marker.
     *
     * @param string[] $tokens
     */
    protected static function dropTokens(string $format, array $tokens): string
    {
        $parts   = [];
        $literal = '';
        $length  = strlen($format);

        for ($i = 0; $i < $length; $i++) {
            $char = $format[$i];

            // A backslash escapes the next character; it is never a token.
            if ($char === '\\') {
                if ($i + 1 < $length) {
                    $literal .= $char . $format[$i + 1];
                    $i++;
                }
                continue;
            }

            $isToken = strpos(static::$dateTokens, $char) !== false;

            if (!$isToken) {
                $literal .= $char;
                continue;
            }

            if ($literal !== '') {
                $parts[] = ['type' => 'literal', 'value' => $literal, 'drop' => false];
                $literal = '';
            }

            $parts[] = [
                'type'  => 'token',
                'value' => $char,
                'drop'  => in_array($char, $tokens, true),
            ];
        }

        if ($literal !== '') {
            $parts[] = ['type' => 'literal', 'value' => $literal, 'drop' => false];
        }

        $total = count($parts);

        foreach ($parts as $index => $part) {
            if ($part['type'] !== 'token' || !$part['drop']) {
                continue;
            }

            // Prefer the separator after the dropped token, so that a suffix
            // marker leaves with the token it belonged to.
            if ($index + 1 < $total && $parts[$index + 1]['type'] === 'literal') {
                $parts[$index + 1]['drop'] = true;
                continue;
            }

            // The dropped token was last: take the separator in front of it.
            if ($index + 1 >= $total && $index > 0 && $parts[$index - 1]['type'] === 'literal') {
                $parts[$index - 1]['drop'] = true;
            }
        }

        $out = '';

        foreach ($parts as $part) {
            if (!$part['drop']) {
                $out .= $part['value'];
            }
        }

        $out = trim($out);

        return $out === '' ? $format : $out;
    }
}

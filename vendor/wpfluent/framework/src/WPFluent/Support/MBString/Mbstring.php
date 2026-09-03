<?php

namespace FluentCart\Framework\Support\MBString;

/**
 * A minimal, dependency-free fallback for the handful of ext-mbstring
 * functions the framework relies on, for hosts where the extension is
 * not installed.
 *
 * Everything for this polyfill lives under Support/MBString/ — this class,
 * the global mb_* wrappers (polyfill.php), and the Unicode tables (unidata/).
 * Deleting that one folder cleanly removes mbstring fallback support; the
 * autoload shim Support/mbstring-loader.php then becomes a harmless no-op.
 *
 * Length/slice/position helpers are fully multibyte-correct via PCRE's
 * UTF-8 mode (ext-pcre is compiled into PHP core and cannot be disabled).
 *
 * Case helpers (lower/upper/title) do full Unicode simple case mapping
 * via the bundled tables in unidata/ (vendored from symfony/Unicode). They
 * are driven with strtr(), which matches whole UTF-8 characters and never
 * corrupts continuation bytes (unlike strtolower()/strtoupper(), which are
 * locale-dependent and mangle bytes >= 0x80). Only simple (1:1, plus a few
 * 1:n like ß->SS) mappings are covered; locale-specific tailoring (e.g.
 * Turkish dotless i) and full case folding are not.
 *
 * The thin global mb_* wrappers live in polyfill.php and only delegate
 * here when the native function is missing.
 */
class Mbstring
{
    /**
     * Lazily-loaded Unicode case-mapping tables, keyed by file name.
     *
     * @var array<string, array<string, string>>
     */
    protected static $caseMaps = [];

    /**
     * Count the number of characters in a UTF-8 string.
     *
     * @param  string  $string
     * @return int
     */
    public static function strlen($string)
    {
        return (int) preg_match_all('/./us', (string) $string);
    }

    /**
     * Return a character-based slice of a UTF-8 string.
     *
     * @param  string  $string
     * @param  int  $start
     * @param  int|null  $length
     * @return string
     */
    public static function substr($string, $start, $length = null)
    {
        $chars = static::strSplit((string) $string);

        return implode('', array_slice($chars, $start, $length));
    }

    /**
     * Find the position (in characters) of the first occurrence of a needle.
     *
     * @param  string  $haystack
     * @param  string  $needle
     * @param  int  $offset
     * @return int|false
     */
    public static function strpos($haystack, $needle, $offset = 0)
    {
        $haystack = (string) $haystack;
        $needle = (string) $needle;

        $start = $offset < 0 ? max(0, static::strlen($haystack) + $offset) : $offset;
        $tail = static::substr($haystack, $start);

        $bytePos = strpos($tail, $needle);

        if ($bytePos === false) {
            return false;
        }

        return $start + static::strlen(substr($tail, 0, $bytePos));
    }

    /**
     * Find the position (in characters) of the last occurrence of a needle.
     *
     * @param  string  $haystack
     * @param  string  $needle
     * @param  int  $offset
     * @return int|false
     */
    public static function strrpos($haystack, $needle, $offset = 0)
    {
        $haystack = (string) $haystack;
        $needle = (string) $needle;

        if ($needle === '') {
            return static::strlen($haystack);
        }

        $start = $offset < 0 ? max(0, static::strlen($haystack) + $offset) : $offset;
        $tail = static::substr($haystack, $start);

        $bytePos = strrpos($tail, $needle);

        if ($bytePos === false) {
            return false;
        }

        return $start + static::strlen(substr($tail, 0, $bytePos));
    }

    /**
     * Split a UTF-8 string into an array of characters (or chunks).
     *
     * @param  string  $string
     * @param  int  $length
     * @return array
     */
    public static function strSplit($string, $length = 1)
    {
        $chars = preg_split('//u', (string) $string, -1, PREG_SPLIT_NO_EMPTY);

        if ($length <= 1) {
            return $chars;
        }

        return array_map(function ($chunk) {
            return implode('', $chunk);
        }, array_chunk($chars, $length));
    }

    /**
     * Lower-case a UTF-8 string (Unicode simple mapping — see class docblock).
     *
     * @param  string  $string
     * @return string
     */
    public static function strtolower($string)
    {
        return strtr((string) $string, static::caseMap('lowerCase'));
    }

    /**
     * Upper-case a UTF-8 string (Unicode simple mapping — see class docblock).
     *
     * @param  string  $string
     * @return string
     */
    public static function strtoupper($string)
    {
        return strtr((string) $string, static::caseMap('upperCase'));
    }

    /**
     * Apply a case-conversion mode to a UTF-8 string.
     *
     * @param  string  $string
     * @param  int  $mode  One of MB_CASE_UPPER, MB_CASE_LOWER, MB_CASE_TITLE
     * @return string
     */
    public static function convertCase($string, $mode)
    {
        $string = (string) $string;

        switch ($mode) {
            case MB_CASE_UPPER:
                return static::strtoupper($string);
            case MB_CASE_LOWER:
                return static::strtolower($string);
            case MB_CASE_TITLE:
            default:
                // For each word: upper-case the first letter, lower-case the
                // rest. The regex's lookbehind is the Unicode Case_Ignorable
                // set, so word starts after marks/apostrophes are handled the
                // same way symfony/polyfill-mbstring does.
                return preg_replace_callback(static::titleRegexp(), function ($match) {
                    return static::strtoupper($match[1]) . static::strtolower($match[2]);
                }, $string);
        }
    }

    /**
     * Lazily load a Unicode case-mapping table from unidata/.
     *
     * @param  string  $name  'lowerCase' or 'upperCase'
     * @return array<string, string>
     */
    protected static function caseMap($name)
    {
        if (! isset(static::$caseMaps[$name])) {
            static::$caseMaps[$name] = require __DIR__ . '/unidata/' . $name . '.php';
        }

        return static::$caseMaps[$name];
    }

    /**
     * Lazily load the title-case word-boundary regex.
     *
     * @return string
     */
    protected static function titleRegexp()
    {
        static $regexp = null;

        if ($regexp === null) {
            $regexp = require __DIR__ . '/unidata/titleCaseRegexp.php';
        }

        return $regexp;
    }
}

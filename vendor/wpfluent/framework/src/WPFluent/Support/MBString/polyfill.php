<?php

/**
 * Conditional mbstring polyfill.
 *
 * Each function/constant is only defined when it is missing, so this file
 * is a no-op on hosts that have ext-mbstring (or another polyfill) loaded,
 * and costs nothing at runtime in that case.
 *
 * WordPress core already provides mb_substr() and mb_strlen() via
 * wp-includes/compat.php, so those guards usually short-circuit; they are
 * kept here so the framework also works standalone (outside WordPress).
 *
 * Implementations live in FluentCart\Framework\Support\MBString\Mbstring. Case helpers do
 * full Unicode simple case mapping via the bundled unidata/ tables (vendored
 * from symfony/Unicode) — see that class's docblock for scope and limits.
 *
 * This file is loaded via the Support/mbstring-loader.php autoload shim.
 */

use FluentCart\Framework\Support\MBString\Mbstring;

if (! defined('MB_CASE_UPPER')) {
    define('MB_CASE_UPPER', 0);
}

if (! defined('MB_CASE_LOWER')) {
    define('MB_CASE_LOWER', 1);
}

if (! defined('MB_CASE_TITLE')) {
    define('MB_CASE_TITLE', 2);
}

if (! function_exists('mb_strlen')) {
    function mb_strlen($string, $encoding = null)
    {
        return Mbstring::strlen($string);
    }
}

if (! function_exists('mb_substr')) {
    function mb_substr($string, $start, $length = null, $encoding = null)
    {
        return Mbstring::substr($string, $start, $length);
    }
}

if (! function_exists('mb_strpos')) {
    function mb_strpos($haystack, $needle, $offset = 0, $encoding = null)
    {
        return Mbstring::strpos($haystack, $needle, $offset);
    }
}

if (! function_exists('mb_strrpos')) {
    function mb_strrpos($haystack, $needle, $offset = 0, $encoding = null)
    {
        return Mbstring::strrpos($haystack, $needle, $offset);
    }
}

if (! function_exists('mb_str_split')) {
    function mb_str_split($string, $length = 1, $encoding = null)
    {
        return Mbstring::strSplit($string, $length);
    }
}

if (! function_exists('mb_strtolower')) {
    function mb_strtolower($string, $encoding = null)
    {
        return Mbstring::strtolower($string);
    }
}

if (! function_exists('mb_strtoupper')) {
    function mb_strtoupper($string, $encoding = null)
    {
        return Mbstring::strtoupper($string);
    }
}

if (! function_exists('mb_convert_case')) {
    function mb_convert_case($string, $mode, $encoding = null)
    {
        return Mbstring::convertCase($string, $mode);
    }
}

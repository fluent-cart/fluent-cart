<?php

namespace FluentCart\Framework\Support;

class Util
{
	/**
	 * Returns a comma-separated string or array of functions that
	 * have been called to get to the current point in code.
	 * 
	 * @param  string  $ignoreClass
	 * @param  integer $skipFrames
	 * @param  boolean $pretty
	 * @return string|array
	 * @see https://developer.wordpress.org/reference/functions/wp_debug_backtrace_summary/
	 */
	public static function debugBacktraceSummary(
		$ignoreClass = null,
		$skipFrames = 0,
		$pretty = true
	) {
		return wp_debug_backtrace_summary(
			$ignoreClass, $skipFrames, $pretty
		);
	}

	/**
	 * @param  string|array $value
	 * @return mixed
	 * @see https://developer.wordpress.org/reference/functions/wp_slash
	 */
	public static function addslashes($value)
	{
		return wp_slash($value);
	}

	/**
	 * Convert an absolute file path to URL.
	 * 
	 * @param  string $filePath
	 * @return string
	 */
	public static function pathToUrl($filePath = '')
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File does not exist: {$filePath}");
        }

        $url = str_replace(
            wp_normalize_path(untrailingslashit(ABSPATH)),
            site_url(),
            wp_normalize_path($filePath)
        );

        return esc_url_raw($url);
    }

	/**
	 * Get the user locale.
	 * 
	 * @param  int|null $userId
	 * @return \FluentCart\Framework\Support\Locale
	 */
	public static function getLocale($userId = null)
	{
		return Locale::init($userId);
	}
}

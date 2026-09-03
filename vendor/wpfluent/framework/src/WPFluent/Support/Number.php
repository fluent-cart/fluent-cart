<?php

namespace FluentCart\Framework\Support;

use RangeException;
use FluentCart\Framework\Foundation\App;
use FluentCart\Framework\Support\InvalidArgumentException;

class Number
{
	/**
	 * Format a number depending on the locale
	 * 
	 * @param  int|float $value
	 * @param  integer $dec
	 * @return string Formatted number
	 * @see    https://developer.wordpress.org/reference/functions/number_format_i18n
	 */
	public static function format($value, $dec = 0)
	{
		$locale = Locale::init();

		return number_format(
			$value,
			absint($dec),
			// @phpstan-ignore-next-line
			$locale->number_format['decimal_point'],
			// @phpstan-ignore-next-line
			$locale->number_format['thousands_sep']
		);
	}

	/**
	 * Format a number as int
	 * 
	 * @param  int|float $val
	 * @return int
	 */
	public static function toInt($val)
	{
		return intval($val);
	}

	/**
	 * Cast to float, optionally rounding to $dec decimals.
	 *
	 * Default rounds to 2 decimals (preserved for backwards compatibility).
	 * Pass $dec = null to skip rounding entirely and return the raw cast.
	 *
	 * @param  int|float|string $val
	 * @param  int|null $dec  Decimal places to round to, or null to skip rounding
	 * @return float
	 */
	public static function toFloat($val, $dec = 2)
	{
	    $val = (float) $val;

	    return $dec === null ? $val : round($val, $dec);
	}

	/**
	 * Convert a value to bool.
	 *
	 * Standard PHP cast semantics: 0, 0.0, '', '0' → false; everything else → true.
	 *
	 * @param  mixed $val
	 * @return bool
	 */
	public static function toBool($val)
	{
	    return (bool) $val;
	}

	/**
	 * Format a number to currency depending on the locale
	 * 
	 * @param  int|float $value
	 * @param  array $options
	 * @return string Formatted number with currency symbol
	 */
	public static function toCurrency($value, $options = [])
	{
	    $defaults = [
	        'currency_symbol' => '$',
	        'number_of_decimals' => 2,
	        'space_with_currency' => 0,
	        'currency_position' => 'left',
	    ];

	    $args = wp_parse_args($options, $defaults);

	    // Format the absolute number; the negative sign is prepended
	    // separately below so it lands outside the currency symbol
	    // (-$1,234.56 rather than $-1,234.56).
	    $isNegative = $value < 0;

	    $formattedNumber = static::format(
	    	abs($value), $args['number_of_decimals']
	    );

	    // Prepare the currency symbol and spacing
	    $symbol = $args['currency_symbol'];

	    // Apply the framework's plugin-prefixed `currency_symbol` filter
	    // so each plugin can wire its own ecommerce integration without
	    // colliding across plugins on the same site (each gets its own
	    // hook namespace via app.hook_prefix).
	    if ($app = App::getInstance()) {
	        $symbol = $app->applyCustomFilters(
	            '_currency_symbol', $symbol, $value, $args
	        );
	    }

	    $space = $args['space_with_currency'] ? ' ' : '';

	    // Build the body based on symbol position, then prepend the
	    // negative sign in front of the whole thing so it reads
	    // -$1,234.56 (left) or -1,234.56$ (right).
	    if ($args['currency_position'] === 'left') {
	        $body = $symbol . $space . $formattedNumber;
	    } else {
	        $body = $formattedNumber . $space . $symbol;
	    }

	    return $isNegative ? '-' . $body : $body;
	}

	/**
	 * Notation to numbers.
	 *
	 * This function transforms the php.ini notation
	 * for numbers (like '2M') to an integer.
	 *
	 * @param  string $num
	 * @return int
	 */
	public static function notationToNum($num)
	{
	    $num = trim($num);

	    if ($num === '') {
	        throw new InvalidArgumentException(
	        	'Input cannot be empty.'
	        );
	    }

	    // Normalize: uppercase, drop interior whitespace, strip optional
	    // trailing 'B' or 'IB' so 'KB', 'MB', 'GB', 'KiB', 'MiB', 'GiB',
	    // and '2 M' all parse identically to the bare 'K'/'M'/'G' form.
	    $normalized = preg_replace('/\s+/', '', strtoupper($num));
	    $normalized = preg_replace('/I?B$/', '', $normalized);

	    if ($normalized === '') {
	        throw new InvalidArgumentException(
	        	'Invalid numeric value in notation.'
	        );
	    }

	    $unit = substr($normalized, -1);

	    // Determine if the last char is a recognized unit
	    $units = ['P', 'T', 'G', 'M', 'K'];

	    if (in_array($unit, $units, true)) {
	        // Numeric part without the unit
	        $numberPart = substr($normalized, 0, -1);

	        if (!is_numeric($numberPart)) {
	            throw new InvalidArgumentException(
	            	'Invalid numeric value in notation.'
	            );
	        }

	        $value = (float) $numberPart;

	        // Multiply based on unit with fall-through logic
	        switch ($unit) {
	            case 'P':
	                $value *= 1024;
	                // no break
	            case 'T':
	                $value *= 1024;
	                // no break
	            case 'G':
	                $value *= 1024;
	                // no break
	            case 'M':
	                $value *= 1024;
	                // no break
	            case 'K':
	                $value *= 1024;
	                break;
	        }
	    } else {
	        // No unit, just parse the number directly
	        if (!is_numeric($normalized)) {
	            throw new InvalidArgumentException(
	            	'Invalid numeric value without unit.'
	            );
	        }

	        $value = (float) $normalized;
	    }

	    // Guard against silent integer overflow (esp. on 32-bit builds).
	    if ($value > PHP_INT_MAX || $value < PHP_INT_MIN) {
	        throw new RangeException(
	            'Value exceeds PHP_INT_MAX.'
	        );
	    }

	    // Return as integer (bytes)
	    return (int) round($value);
	}

	/**
	 * Calculates the percentage/$percent from the $value/$total
	 *
	 * @param  int|float $percent
	 * @param  int|float $total
	 * @return int|float
	 */
	public static function getPercentage($percent, $total)
	{
		return ($percent / 100) * $total;
	}

	/**
	 * Calculate what percentage $value is of $total.
	 *
	 * Returns 0.0 when $total is 0 (avoids divide-by-zero) — useful for
	 * dashboards and reports where "0 out of 0" should display as 0%.
	 *
	 * Examples:
	 *   percentageOf(50, 200)  → 25.0
	 *   percentageOf(150, 100) → 150.0
	 *   percentageOf(0, 0)     → 0.0
	 *
	 * @param  int|float|string $value
	 * @param  int|float|string $total
	 * @return float
	 */
	public static function percentageOf($value, $total)
	{
		$total = (float) $total;

		if ($total === 0.0) {
			return 0.0;
		}

		return ((float) $value / $total) * 100;
	}

	/**
	 * Converts a number of bytes to human readable format
	 * using the maximum unit available to convert the bytes.
	 * 
	 * @param  int|float $bytes
	 * @param  integer $decimals
	 * @return string Formatted size units of bytes, i.e: 1mb/1gb e.t.c.
	 */
	public static function formatBytes($bytes, $decimals = 0)
	{
		return size_format($bytes, $decimals);
	}

	/**
	 * Makes an ordinal number from the integer.
	 *
	 * Floats are truncated toward zero (2.7 → 2nd, -2.7 → -2nd).
	 * Negatives keep their sign (-21 → -21st, -11 → -11th).
	 * Non-numeric input is returned unchanged.
	 *
	 * @param  int|float|string $number
	 * @return string The ordinal number, i.e: 1st, 5th e.t.c.
	 */
	public static function toOrdinal($number)
	{
	    if (!is_numeric($number)) {
	        return $number;
	    }

	    $int  = (int) $number;
	    $abs  = abs($int);
	    $sign = $int < 0 ? '-' : '';

	    if (($abs % 100) >= 11 && ($abs % 100) <= 13) {
	        $suffix = 'th';
	    } else {
	        switch ($abs % 10) {
	            case 1:
	                $suffix = 'st';
	                break;
	            case 2:
	                $suffix = 'nd';
	                break;
	            case 3:
	                $suffix = 'rd';
	                break;
	            default:
	                $suffix = 'th';
	                break;
	        }
	    }

	    return $sign . $abs . $suffix;
	}

	/**
     * Convert the number to its human readable equivalent.
     *
     * @param  int  $number
     * @param  int  $precision
     * @param  int|null  $maxPrecision
     * @return string
     */
    public static function forHumans(
    	$number, $precision = 0, $maxPrecision = null, $abbr = false
    )
    {
        return static::summarize($number, $precision, $maxPrecision, $abbr ? [
            3  => 'K',
            6  => 'M',
            9  => 'B',
            12 => 'T',
            15 => 'Q',
            18 => 'Qi',
        ] : [
            3  => ' thousand',
            6  => ' million',
            9  => ' billion',
            12 => ' trillion',
            15 => ' quadrillion',
            18 => ' quintillion',
        ]);
    }

    /**
     * Convert the number to its human readable equivalent.
     *
     * @param  int  $number
     * @param  int  $precision
     * @param  int|null  $maxPrecision
     * @param  array  $units
     * @return string
     */
    protected static function summarize(
    	$number, $precision = 0, $maxPrecision = null, $units = []
    )
    {
        if (empty($units)) {
            $units = [
                3  => 'K',
                6  => 'M',
                9  => 'B',
                12 => 'T',
                15 => 'Q',
                18 => 'Qi',
            ];
        }

        // Recursion threshold = the smallest exponent above the highest
        // mapped unit. Numbers at or beyond this fall through the table
        // and get composite naming (e.g. '1KQi' for 1e21 = 1 sextillion).
        $topExponent = max(array_keys($units));
        $overflow    = pow(10, $topExponent + 3);

        switch (true) {
            case floatval($number) === 0.0:
                return $precision > 0 ? static::format(0, $precision) : '0';

            case $number < 0:
                return sprintf('-%s', static::summarize(
                	abs($number), $precision, $maxPrecision, $units
                ));

            case $number >= $overflow:
                return sprintf('%s'.end($units), static::summarize(
                	$number / pow(10, $topExponent), $precision, $maxPrecision, $units
                ));
        }

        $numberExponent = floor(log10($number));
        $displayExponent = $numberExponent - ($numberExponent % 3);
        $number /= pow(10, $displayExponent);

        return trim(
        	sprintf('%s%s', static::format(
        		$number, $precision
        	), $units[$displayExponent] ?? '')
        );
    }
}

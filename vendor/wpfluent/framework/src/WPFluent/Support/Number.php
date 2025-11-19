<?php

namespace FluentCart\Framework\Support;

use FluentCart\Framework\Support\InvalidArgumentException;
use RangeException;
use TypeError;

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

		// @phpstan-ignore-next-line
		if (isset($locale)) {
			$formatted = number_format(
				$value,
				absint($dec),
				// @phpstan-ignore-next-line
				$locale->number_format['decimal_point'],
				// @phpstan-ignore-next-line
				$locale->number_format['thousands_sep']
			);
		} else {
			$formatted = number_format($value, absint($dec));
		}

		return $formatted;
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
	 * Format a number as float
	 * 
	 * @param  int|float $val
	 * @param  integer $dec
	 * @return float
	 */
	public static function toFloat($val, $dec = 2)
	{
	    return round((float) $val, $dec);
	}

	/**
	 * Convert a number to bool
	 * 
	 * @param  int $val
	 * @return bool
	 */
	public static function toBool($val)
	{
		if (is_bool($val)) {
	        return $val;
	    }
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
		$locale = Locale::init();

	    $defaults = [
	        'currency_symbol' => '$',
	        'number_of_decimals' => 2,
	        'space_with_currency' => 0,
	        'currency_position' => 'left',
	    ];

	    $args = wp_parse_args($options, $defaults);

	    // Format the number with the
	    // specified number of decimals
	    $formattedNumber = static::format(
	    	$value, $args['number_of_decimals']
	    );

	    // Prepare the currency symbol and spacing
	    $symbol = $args['currency_symbol'];
	    $space = $args['space_with_currency'] ? ' ' : '';

	    // Return the formatted currency string based
	    // on the position of the currency symbol
	    if ($args['currency_position'] === 'left') {
	        return $symbol . $space . $formattedNumber;
	    } else {
	        return $formattedNumber . $space . $symbol;
	    }
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

	    // Extract the last character to check for unit
	    $unit = strtoupper(substr($num, -1));

	    // Determine if the last char is a recognized unit
	    $units = ['P', 'T', 'G', 'M', 'K'];

	    if (in_array($unit, $units, true)) {
	        // Numeric part without the unit
	        $numberPart = substr($num, 0, -1);

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
	        if (!is_numeric($num)) {
	            throw new InvalidArgumentException(
	            	'Invalid numeric value without unit.'
	            );
	        }
	        
	        $value = (float) $num;
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
	 * Makes an ordinal number from the integer
	 * 
	 * @param  int $number
	 * @return string The ordinal number, i.e: 1st, 5th e.t.c.
	 */
	public static function toOrdinal($number)
	{
	    if (!is_numeric($number)) {
	        return $number;
	    }

	    if (($number % 100) >= 11 && ($number % 100) <= 13) {
	        $suffix = 'th';
	    } else {
	        switch ($number % 10) {
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

	    return $number . $suffix;
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
            3 => 'K',
            6 => 'M',
            9 => 'B',
            12 => 'T',
            15 => 'Q',
        ] : [
            3 => ' thousand',
            6 => ' million',
            9 => ' billion',
            12 => ' trillion',
            15 => ' quadrillion',
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
                3 => 'K',
                6 => 'M',
                9 => 'B',
                12 => 'T',
                15 => 'Q',
            ];
        }

        switch (true) {
            case floatval($number) === 0.0:
                return $precision > 0 ? static::format(0, $precision) : '0';

            case $number < 0:
                return sprintf('-%s', static::summarize(
                	abs($number), $precision, $maxPrecision, $units
                ));

            case $number >= 1e15:
                return sprintf('%s'.end($units), static::summarize(
                	$number / 1e15, $precision, $maxPrecision, $units
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

	/**
	 * Convert a numeric value to words.
	 *
	 * Example:
	 * 199900010500.91 → "one hundred and ninety-nine billion nine hundred million
	 * ten thousand five hundred and 91 cents"
	 *
	 * @param int|float|string $number The number to convert.
	 * @param array $options Optional configuration:
	 *   - 'thousand_separator' => string (default ',')
	 *   - 'decimal_separator'  => string (default '.')
	 *   - 'round'              => bool (default false) Whether to round floats.
	 *   - 'inCents'            => bool (default true) Include the fraction as cents.
	 *
	 * @return string The human-readable word representation of the number.
	 *
	 * @throws \RangeException If the number is out of acceptable bounds.
	 */
	public static function inWords($number, $options = [])
	{
		return (new NumberToWords)->inWords($number, $options);
	}

	/**
	 * Make words from integers
	 * 
	 * @param  int $number
	 * @param  array $numberWords
	 * @param  array $tensWords
	 * @return string
	 */
	protected static function spellInteger($number, $numberWords, $tensWords)
	{
	    $result = '';

	    $result .= static::convertToWords($number, $numberWords, $tensWords);

	    return trim($result);
	}

	/**
	 * Make words from fraction part
	 * 
	 * @param  int $number
	 * @param  array $numberWords
	 * @return string
	 */
	protected static function spellDecimal($number, $numberWords, $decimalSeparator)
	{
	    $result = '';

	    $decimalPosition = strpos($number, $decimalSeparator);
	    
	    if ($decimalPosition !== false) {
	        
	        $decimalAsString = substr($number, $decimalPosition + 1);
	        
	        for ($i = 0; $i < strlen($decimalAsString); $i++) {

	            $digit = intval($decimalAsString[$i]);

	            $result .= $numberWords[$digit] . ' ';
	        }
	    } else {
	        $result .= 'zero';
	    }

	    return trim($result);
	}

	/**
	 * Main function to make the unit words
	 * 
	 * @param  int|float $number
	 * @param  array $numberWords
	 * @param  array $tensWords
	 * @return string
	 */
	protected static function convertToWords($number, $numberWords, $tensWords)
	{
	    if ($number < 20) {
	        return $numberWords[$number];

	    } elseif ($number < 100) {

	        $result = $tensWords[intval($number / 10)];

			if (intval($number) % 10 !== 0) {
			    $result .= ' ' . static::convertToWords(
			    	intval($number) % 10, $numberWords, $tensWords
			    );
			}

			return $result;

	    } elseif ($number < 1000) {

	        $result = $numberWords[intval($number / 100)] . ' hundred';

			if (intval($number) % 100 !== 0) {
			    $result .= ' and ' . static::convertToWords(
			    	intval($number) % 100, $numberWords, $tensWords
			    );
			}

			return $result;

	    } elseif ($number < 1000000) {
	        
	        $result = static::convertToWords(
	        	intval($number / 1000), $numberWords, $tensWords
	        );

	        $result .= ' thousand';

			if (intval($number) % 1000 !== 0) {
			    $result .= ' ' . static::convertToWords(
			    	intval($number) % 1000, $numberWords, $tensWords
			    );
			}

			return $result;

	    } elseif ($number < 1000000000) {
	        
	        $result = static::convertToWords(
	        	intval($number / 1000000), $numberWords, $tensWords
	        );

	        $result .= ' million';

			if (intval($number) % 1000000 !== 0) {
			    $result .= ' ' . static::convertToWords(
			    	intval($number) % 1000000, $numberWords, $tensWords
			    );
			}

			return $result;

	    } elseif ($number < 1000000000000) {
	        
	        $result = static::convertToWords(
	        	intval($number / 1000000000), $numberWords, $tensWords
	        );

	        $result .= ' billion';

			if (intval($number) % 1000000000 !== 0) {
			    $result .= ' ' . static::convertToWords(
			    	intval($number) % 1000000000, $numberWords, $tensWords
			    );
			}

			return $result;

	    } elseif ($number < 1000000000000000) {

	        $result = static::convertToWords(
	        	intval($number / 1000000000000), $numberWords, $tensWords
	        );

	        $result .= ' trillion';

			if (intval($number) % 1000000000000 !== 0) {
			    $result .= ' ' . static::convertToWords(
			    	intval($number) % 1000000000000, $numberWords, $tensWords
			    );
			}

			return $result;

	    } elseif (intval($number) < 1000000000000000000) {

		    $result = static::convertToWords(
		    	intval($number / 1000000000000000), $numberWords, $tensWords
		    );

		    $result .= ' quadrillion';

			if (intval($number) % 1000000000000000 !== 0) {
			    $result .= ' ' . static::convertToWords(
			    	intval($number) % 1000000000000000, $numberWords, $tensWords
			    );
			}

			return $result;

		} elseif (intval($number) < 1000000000000000000000) {

		    $result = static::convertToWords(
		    	intval($number / 1000000000000000000), $numberWords, $tensWords
		    );

		    $result .= ' quintillion';

			if (intval($number) % 1000000000000000000 !== 0) {
			    $result .= ' ' . static::convertToWords(
			    	intval($number) % 1000000000000000000, $numberWords, $tensWords
			    );
			}

			return $result;
		} else {
			throw new RangeException('Out of range', 500);
		}
	}

	/**
	 * Make words for cents
	 * 
	 * @param  int|float $cents
	 * @param  array $numberWords
	 * @param  array $tensWords
	 * @return string
	 */
    protected static function toCents($cents, $numberWords, $tensWords)
    {
	    if (array_key_exists($cents, $numberWords)) {
	    	return $numberWords[$cents];
	    } elseif (array_key_exists($cents, $tensWords)) {
	    	return $tensWords[$cents];
	    }

	    $result = '';
	    $tens = substr(floor($cents / 10) * 10, 0, 1);
	    $unitsPart = $cents % 10;

	    if (array_key_exists($tens, $tensWords)) {
	        $result .= $tensWords[$tens];
	        if ($unitsPart > 0) {
	            $result .= ' ' . $numberWords[$unitsPart];
	        }
	    } elseif (array_key_exists($unitsPart, $numberWords)) {
	        $result .= $numberWords[$unitsPart];
	    }

	    return $result;
	}
}

<?php

namespace FluentCart\Framework\Support;

use TypeError;
use RangeException;
use FluentCart\Framework\Support\InvalidArgumentException;

/**
 * Class NumberToWords
 *
 * This class provides functionality to convert numbers
 * into their English word representation (in words).
 */
class NumberToWords
{
    /** @var array $units The words for numbers 0-19. */
    private $units = [
        '',
        'one',
        'two',
        'three',
        'four',
        'five',
        'six',
        'seven',
        'eight',
        'nine',
        'ten',
        'eleven',
        'twelve',
        'thirteen',
        'fourteen',
        'fifteen',
        'sixteen',
        'seventeen',
        'eighteen',
        'nineteen'
    ];

    /** @var array $tens The words for multiples of ten. */
    private $tens = [
        '',
        'ten',
        'twenty',
        'thirty',
        'forty',
        'fifty',
        'sixty',
        'seventy',
        'eighty',
        'ninety'
    ];

    /** @var array $largeNumbers The words for large number groups. */
    private $largeNumbers = [
        '',
        'thousand',
        'million',
        'billion',
        'trillion',
        'quadrillion',
        'quintillion',
    ];

    /**
     * Convert a number to its word representation.
     *
     * @param mixed $num The number to convert. Can be an integer or a float.
     * @return string|false The word representation of the number, or false if input is invalid.
     */
    public function inWords($num, $options = [])
    {
        $defaults = [
            'thousand_separator' => ',',
            'decimal_separator' => '.',
        ];

        $options = array_merge($defaults, $options);

        foreach ($options as $key => $value) {
            if (!isset($options[$key]) || !$options[$key]) {
                $options[$key] = $defaults[$key];
            }
        }

        $pos = strrpos($num, $options['decimal_separator']);

        if ($pos !== false) {
            $num = substr_replace($num, '.', $pos, 1);
            $numBeforeDec = substr($num, 0, $pos);
            $numBeforeDec = str_replace(
                $options['thousand_separator'], ',', $numBeforeDec
            );
            $num = $numBeforeDec . substr($num, $pos);
        } else {
            $num = str_replace(
                $options['thousand_separator'], ',', $num
            );
        }

        $num = $this->validateNumber($num);

        if ($num == 0) return 'zero dollars';

        // Separate the whole number and decimal part
        $parts = explode('.', (string)$num);
        $wholeNumber = (int)$parts[0];
        $cents = isset($parts[1]) ? (int)substr($parts[1], 0, 2) : 0;

        $words = [];
        $numLength = strlen((string)$wholeNumber);
        $levels = (int)(($numLength + 2) / 3);
        $maxLength = $levels * 3;
        $wholeNumber = substr('00' . $wholeNumber, -$maxLength);
        $numLevels = str_split($wholeNumber, 3);

        for ($i = 0; $i < count($numLevels); $i++) {
            $levels--;

            $level = (int) $numLevels[$i];
            $hundreds = intdiv($level, 100);
            $tens = $level % 100;

            $hundredsWord = $hundreds ? $this->units[$hundreds] . ' hundred' : '';

            $tensWord = '';
            if ($tens < 20) {
                $tensWord = $tens ? $this->units[$tens] : '';
            } else {
                $tensWord = $this->tens[(int)($tens / 10)];
                $onesWord = $this->units[$tens % 10];
                $tensWord .= $onesWord ? ' ' . $onesWord : '';
            }

            // Add "and" inside the triple group if both
            // hundreds and tens/ones exist
            if ($hundredsWord && $tensWord) {
                $wordsPart = trim($hundredsWord . ' and ' . $tensWord);
            } else {
                $wordsPart = trim($hundredsWord . ' ' . $tensWord);
            }

            if ($levels > 0 && (int)$numLevels[$i] !== 0) {
                $wordsPart .= ' ' . $this->largeNumbers[$levels];
            }

            if ($wordsPart) {
                $words[] = $wordsPart;
            }
        }

        // Add "and" only before the last unit and make string
        if ($result = $this->insertAndBeforeLastUnit($words)) {
            // Match all occurrences of ' and '
            $parts = explode(' and ', $result);

            if (count($parts) > 1) {
                $last = array_pop($parts);
                $result = implode(' ', $parts) . ' and ' . $last;
            }
        }

        // Add the cents part if it exists
        $result = $this->addCentsIfExists($result, $cents);

        // Fix spacing issues by trimming individual parts
        // and remove redundant ands and add currency name.
        return $this->fixSpacingIssuesAndAddCurrencyName($result, $num);
    }

    /**
     * Validate the number.
     * 
     * @param  numeric $num The number to validate
     * @return mixed $num
     */
    private function validateNumber($num)
    {
        // Remove commas, spaces, and trim
        $num = str_replace([',', ' '], '', trim($num));

        // Check numeric
        if (!is_numeric($num)) {
            throw new TypeError('Input must be a numeric type.');
        }

        // Reject negatives (string check)
        if (strpos($num, '-') === 0) {
            throw new InvalidArgumentException(
                'Negative numbers are not supported.'
            );
        }

        // Normalize scientific notation first (if present)
        if (stripos($num, 'e') !== false) {
            $num = $this->sciNotationToString($num);
        }

        // Check integer part length against PHP_INT_MAX
        $integerPart = explode('.', $num)[0];
        $integerPart = ltrim($integerPart, '0');
        if ($integerPart === '') {
            $integerPart = '0';
        }

        $phpIntMaxStr = (string) PHP_INT_MAX;

        if (
            strlen($integerPart) > strlen($phpIntMaxStr) || (
                strlen($integerPart) === strlen($phpIntMaxStr)
                && strcmp($integerPart, $phpIntMaxStr) > 0
            )
        ) {
            throw new RangeException("Number exceeds PHP_INT_MAX.");
        }

        // Format as integer string (remove decimals)
        $num = sprintf('%.f', $num);

        return $num;
    }

    /**
     * Convert scientific notation to decimal string without precision loss.
     */
    private function sciNotationToString($num)
    {
        if (!preg_match('/^([\d\.]+)e([+-]?\d+)$/i', $num, $matches)) {
            return $num;
        }

        $base = str_replace('.', '', $matches[1]);
        $decimalPos = strpos($matches[1], '.') !== false ? strlen($matches[1]) - strpos($matches[1], '.') - 1 : 0;
        $exponent = (int) $matches[2];
        $decimalPos -= $exponent;

        if ($decimalPos <= 0) {
            $result = $base . str_repeat('0', abs($decimalPos));
        } else {
            $result = substr(
                $base, 0, -$decimalPos
            ) . '.' . substr($base, -$decimalPos);
            
            $result = rtrim($result, '.');
        }

        $result = ltrim($result, '0');
        
        if (strpos($result, '.') === 0) {
            $result = '0' . $result;
        }

        if ($result === '') {
            $result = '0';
        }

        return $result;
    }

    /**
     * Convert cents to words.
     *
     * @param int $cents The cents to convert (should be between 0 and 99).
     * @return string The word representation of the cents.
     */
    private function convertCents(int $cents): string
    {
        if ($cents < 20) {
            return $this->units[$cents] . ($cents == 1 ? ' cent' : ' cents');
        }

        $tensWord = $this->tens[(int)($cents / 10)];

        $onesWord = $this->units[$cents % 10];

        return trim($tensWord . ($onesWord ? ' ' . $onesWord : '') . ' cents');
    }

    /**
     * Add the and before the last unit.
     * @param  array $words
     * @return string
     */
    private function insertAndBeforeLastUnit($words)
    {
        $count = count($words);

        if ($count > 1) {
            $last = array_pop($words);
            $beforeLast = implode(' ', $words);

            // Only add 'and' if not already present at boundary
            if (
                str_ends_with($beforeLast, ' and')
                || str_starts_with($last, 'and ')
            ) {
                return trim($beforeLast . ' ' . $last);
            }

            return trim($beforeLast . ' and ' . $last);
        }

        return $words[0] ?? '';
    }

    /**
     * Add cents if exists.
     * 
     * @param string $result
     * @param int|float $cents
     * @return string
     */
    private function addCentsIfExists($result, $cents)
    {
        if ($cents > 0) {
            $centsWords = $this->convertCents($cents);
            $result = preg_replace('/( and )+/', ' ', $result);
            $result .= ' and ' . $centsWords;
        }

        return $result;
    }

    /**
     * Fix spacing issues and add currency name.
     * 
     * @param  string $result
     * @return string
     */
    private function fixSpacingIssuesAndAddCurrencyName($result, $num)
    {
        $result = preg_replace('/\s+/', ' ', $result);

        $dollarWord = $num > 1 ? 'dollars' : 'dollar';
        
        $dollarWord = $num < 2 ? 'dollar' : $dollarWord;
        
        if (str_starts_with($result, ' and ')) {
            $result = str_replace(' and ', '', $result);
        }

        if (str_contains($result, 'cent')) {
            if (str_contains($result, ' and ')) {
                $result = str_replace(' and ', " $dollarWord and ", $result);
            }
        } else {
            $result .= " $dollarWord";
        }

        return $result;
    }
}

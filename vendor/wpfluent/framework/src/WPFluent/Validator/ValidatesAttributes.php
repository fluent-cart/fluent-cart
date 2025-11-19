<?php

namespace FluentCart\Framework\Validator;

use Countable;
use Exception;
use ValueError;
use DateTimeInterface;
use InvalidArgumentException;
use FluentCart\Framework\Support\Str;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Helper;
use FluentCart\Framework\Support\DateTime;
use FluentCart\Framework\Validator\Contracts\File;

trait ValidatesAttributes
{
    use ValidateDatabaseRulesTrait;

    /**
     * Require a certain number of parameters to be present.
     *
     * @param int $count
     * @param array $parameters
     * @param string $rule
     *
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    protected function requireParameterCount($count, $parameters, $rule)
    {
        if (count($parameters) < $count) {
            throw new InvalidArgumentException(
                "Validation rule $rule requires at least $count parameters."
            );
        }
    }

    /**
     * Get the size of an attribute.
     *
     * @param string $attribute
     * @param mixed $value
     *
     * @return mixed
     */
    protected function getSize($attribute, $value)
    {
        // This method will determine if the attribute is a number, string, or file and
        // return the proper size accordingly. If it is a number, then number itself
        // is the size. If it is a file, we take kilobytes, and for a string the
        // entire length of the string will be considered the attribute size.
        $type = $this->deduceType($value, $attribute);

        switch ($type) {
            case 'numeric':
                return $value;
            case 'array':
                return count($value);
            case 'file':
                return $value->getSize() / 1024;
            default:
                return mb_strlen($value);
        }
    }

    /**
     * Deduce the value type of an attribute.
     *
     * @param $value
     *
     * @return string
     */
    protected function deduceType($value, $attribute = null)
    {
        if (is_numeric($value)) {
            return $this->guessType($value, $attribute);
        } elseif (is_array($value)) {
            return 'array';
        } elseif ($value instanceof File) {
            return 'file';
        }

        return 'string';
    }

    /**
     * Guess the real type by examining rules.
     * 
     * @param  mixed $value
     * @param  string|null $attribute
     * @return string
     */
    protected function guessType($value, $attribute)
    {
        if ($attribute && in_array('string', $this->rules[$attribute])) {
            return 'string';
        }

        return 'numeric';
    }

    /**
     * Check if parameter should be converted to boolean.
     *
     * @param  string  $parameter
     * @return bool
     */
    protected function shouldConvertToBoolean($parameter)
    {
        return in_array('boolean', Arr::get($this->rules, $parameter, []));
    }

    /**
     * Convert the given values to boolean if they are string "true" / "false".
     *
     * @param array $values
     *
     * @return array
     */
    protected function convertValuesToBoolean($values)
    {
        return array_map(function ($value) {
            if ($value === 'true') {
                return true;
            } elseif ($value === 'false') {
                return false;
            }

            return $value;
        }, $values);
    }

    /**
     * Convert the given values to null if they are string "null".
     *
     * @param  array  $values
     * @return array
     */
    protected function convertValuesToNull($values)
    {
        return array_map(function ($value) {
            return Str::lower($value) === 'null' ? null : $value;
        }, $values);
    }

    /**
     * Validate that a required attribute exists.
     *
     * @param string $attribute
     * @param mixed $value
     *
     * @return bool
     */
    protected function validateRequired($attribute, $value)
    {
        if (is_null($value)) {
            return false;
        } elseif (is_string($value) && trim($value) === '') {
            return false;
        } elseif ((is_array($value) || $value instanceof Countable) && count($value) < 1) {
            return false;
        } elseif ($value instanceof File) {
            return (string) $value->getPath() != '';
        }

        return true;
    }

    /**
     * Validate that an attribute exists when another attribute has a given value.
     *
     * @param string $attribute
     * @param mixed $value
     * @param mixed $parameters
     *
     * @return bool
     */
    protected function validateRequiredIf($attribute, $value, $parameters)
    {
        $this->requireParameterCount(2, $parameters, 'required_if');
        
        if (preg_match('/\.\d\./', $attribute, $matches)) {
            $parameters[0] = str_replace(['.*.'], $matches, $parameters[0]);
        }

        $other = Arr::get($this->data, $parameters[0]);

        $values = array_slice($parameters, 1);

        if (is_bool($other)) {
            $values = $this->convertValuesToBoolean($values);
        }

        if (in_array($other, $values)) {
            return $this->validateRequired($attribute, $value);
        }

        return true;
    }

    /**
     * Validate that an attribute is required only if **any** of the
     * other specified fields are present.
     *
     * @param string $attribute
     * @param mixed $value
     * @param array $parameters
     * @return bool
     */
    protected function validateRequiredWith($attribute, $value, $parameters)
    {
        $this->requireParameterCount(1, $parameters, 'required_with');

        foreach ($parameters as $field) {
            if ($this->isPresent($field) && !$this->isPresent($attribute)) {
                return $this->validateRequired($attribute, $value);
            }
        }

        return true;
    }

    /**
     * Validate that an attribute is required only if **all** of the
     * other specified fields are present.
     *
     * @param string $attribute
     * @param mixed $value
     * @param array $parameters
     * @return bool
     */
    protected function validateRequiredWithAll($attribute, $value, $parameters)
    {
        $this->requireParameterCount(1, $parameters, 'required_with_all');

        $allPresent = true;

        foreach ($parameters as $field) {
            if (!$this->isPresent($field)) {
                $allPresent = false;
                break;
            }
        }

        if ($allPresent && !$this->isPresent($attribute)) {
            return $this->validateRequired($attribute, $value);
        }

        return true;
    }

    /**
     * Determine if the given attribute is present.
     * 
     * @param  string  $attribute
     * @return boolean
     */
    protected function isPresent($attribute)
    {
        $attribute = str_replace(['[', ']'], ['.', ''], $attribute);

        if (!Arr::has($this->data, $attribute)) {
            return false;
        }

        $value = Arr::get($this->data, $attribute);

        return !is_null($value) && $value !== '';
    }

    /**
     * Validate that an attribute is greater than value.
     *
     * @param string $attribute
     * @param mixed $value
     * @param array $parameters
     * @return bool
     */
    protected function validateGt($attribute, $value, $parameters)
    {
        $this->requireParameterCount(1, $parameters, 'gt');

        if (!is_numeric($value) || !is_numeric($parameters[0])) {
            return false;
        }

        return $value > $parameters[0];
    }

    /**
     * Validate that an attribute is greater than or equal to value.
     *
     * @param string $attribute
     * @param mixed $value
     * @param array $parameters
     * @return bool
     */
    protected function validateGte($attribute, $value, $parameters)
    {
        $this->requireParameterCount(1, $parameters, 'gte');

        if (!is_numeric($value) || !is_numeric($parameters[0])) {
            return false;
        }

        return $value >= $parameters[0];
    }

    /**
     * Validate that an attribute is less than value.
     *
     * @param string $attribute
     * @param mixed $value
     * @param array $parameters
     * @return bool
     */
    protected function validateLt($attribute, $value, $parameters)
    {
        $this->requireParameterCount(1, $parameters, 'lt');

        if (!is_numeric($value) || !is_numeric($parameters[0])) {
            return false;
        }

        return $value < $parameters[0];
    }

    /**
     * Validate that an attribute is less than or equal to value.
     *
     * @param string $attribute
     * @param mixed $value
     * @param array $parameters
     * @return bool
     */
    protected function validateLte($attribute, $value, $parameters)
    {
        $this->requireParameterCount(1, $parameters, 'lte');

        if (!is_numeric($value) || !is_numeric($parameters[0])) {
            return false;
        }

        return $value <= $parameters[0];
    }

    /**
     * Validate that an attribute is a valid e-mail address.
     *
     * @param string $attribute
     * @param mixed $value
     *
     * @return bool
     */
    protected function validateEmail($attribute, $value)
    {
        return ! ! is_email($value);
    }

    /**
     * Validate the size of an attribute.
     *
     * @param string $attribute
     * @param mixed $value
     * @param array $parameters
     *
     * @return bool
     */
    protected function validateSize($attribute, $value, $parameters)
    {
        $this->requireParameterCount(1, $parameters, 'size');

        return $this->getSize($attribute, $value) == $parameters[0];
    }

    /**
     * Validate the size of an attribute is greater than a minimum value.
     *
     * @param string $attribute
     * @param mixed $value
     * @param array $parameters
     *
     * @return bool
     */
    protected function validateMin($attribute, $value, $parameters)
    {
        $this->requireParameterCount(1, $parameters, 'min');

        return $this->getSize($attribute, $value) >= $parameters[0];
    }

    /**
     * Validate the size of an attribute is less than a maximum value.
     *
     * @param string $attribute
     * @param mixed $value
     * @param array $parameters
     *
     * @return bool
     */
    protected function validateMax($attribute, $value, $parameters)
    {
        $this->requireParameterCount(1, $parameters, 'max');

        return $this->getSize($attribute, $value) <= $parameters[0];
    }

    /**
     * Validate that two attributes match.
     *
     * @param string $attribute
     * @param mixed $value
     * @param array $parameters
     *
     * @return bool
     */
    protected function validateSame($attribute, $value, $parameters)
    {
        $this->requireParameterCount(1, $parameters, 'same');

        $other = @$this->data[$parameters[0]];

        return $value === $other;
    }

    /**
     * Validate that an attribute is a valid URL.
     *
     * @param string $attribute
     * @param mixed $value
     *
     * @return bool
     */
    protected function validateUrl($attribute, $value)
    {
        return (bool) Str::isUrl($value);
    }

    /**
     * Validate that an attribute is a string.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    protected function validateString($attribute, $value)
    {
        return is_string($value);
    }

    /**
     * Validate that an attribute is a integer.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    protected function validateInt($attribute, $value)
    {
        return $this->validateInteger($attribute, $value);
    }

    /**
     * Validate that an attribute is a integer.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    protected function validateInteger($attribute, $value)
    {
        return is_int($value);
    }

    /**
     * Validate that an attribute has a given number of decimal places.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  array<int, int|string>  $parameters
     * @return bool
     */
    protected function validateDecimal($attribute, $value, $parameters)
    {
        $this->requireParameterCount(1, $parameters, 'decimal');

        if (!$this->validateNumeric($attribute, $value)) {
            return false;
        }

        $matches = [];

        if (preg_match('/^[+-]?\d*\.?(\d*)$/', $value, $matches) !== 1) {
            return false;
        }

        $decimals = strlen(end($matches));

        if (!isset($parameters[1])) {
            return $decimals == $parameters[0];
        }

        return $decimals >= $parameters[0] && $decimals <= $parameters[1];
    }

    /**
     * Validate that an attribute is numeric.
     *
     * @param string $attribute
     * @param mixed $value
     *
     * @return bool
     */
    protected function validateNumeric($attribute, $value)
    {
        return is_numeric($value);
    }

    /**
     * Validate that an attribute is a valid date.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    protected function validateDate($attribute, $value)
    {
        if ($value instanceof DateTimeInterface) {
            return true;
        }

        try {
            if ((!is_string($value) && !is_numeric($value)) || strtotime($value) === false) {
                return false;
            }
        } catch (Exception $e) {
            return false;
        }

        $date = date_parse($value);

        return checkdate($date['month'], $date['day'], $date['year']);
    }

    /**
     * Validate that an attribute matches a date format.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  array<int, int|string>  $parameters
     * @return bool
     */
    protected function validateDateFormat($attribute, $value, $parameters)
    {
        $this->requireParameterCount(1, $parameters, 'date_format');

        if (!is_string($value) && !is_numeric($value)) {
            return false;
        }

        foreach ($parameters as $format) {
            try {
                $date = DateTime::createFromFormat('!'.$format, $value);

                if ($date && $date->format($format) == $value) {
                    return true;
                }
            } catch (ValueError $e) {
                return false;
            }
        }

        return false;
    }

    /**
     * Validate that an attribute is alphabetic.
     *
     * @param string $attribute
     * @param mixed $value
     *
     * @return bool
     */
    protected function validateAlpha($attribute, $value)
    {
        return is_string($value) && preg_match('/^[\pL\pM]+$/u', $value);
    }

    /**
     * Validate that an attribute is alphanum.
     *
     * @param string $attribute
     * @param mixed $value
     *
     * @return bool
     */
    protected function validateAlphanum($attribute, $value)
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return false;
        }

        return preg_match('/^[\pL\pM\pN]+$/u', $value) > 0;
    }

    /**
     * Validate that an attribute is alphanum and -_.
     *
     * @param string $attribute
     * @param mixed $value
     *
     * @return bool
     */
    protected function validateAlphadash($attribute, $value)
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return false;
        }

        return preg_match('/^[\pL\pM\pN_-]+$/u', $value) > 0;
    }

    /**
     * Validate the guessed extension of a file upload is in a set of file extensions.
     *
     * @param string $attribute
     * @param mixed $value
     * @param array $parameters
     *
     * @return bool
     */
    protected function validateMimes($attribute, $value, $parameters)
    {
        if (! $this->isValidFileInstance($value)) {
            return false;
        }

        if ($this->shouldBlockPhpUpload($value, $parameters)) {
            return false;
        }

        /**
         * @var $value \FluentCart\Framework\Validator\Contracts\File
         */
        return $value->getPath() != '' && in_array(
            $value->guessExtension(), $parameters
        );
    }

    /**
     * Validate the MIME type of a file upload attribute is in a set of MIME types.
     *
     * @param  string $attribute
     * @param  mixed  $value
     * @param  array  $parameters
     * @return bool
     */
    protected function validateMimetypes($attribute, $value, $parameters)
    {
        if (! $this->isValidFileInstance($value)) {
            return false;
        }

        if ($this->shouldBlockPhpUpload($value, $parameters)) {
            return false;
        }

        return $value->getPath() != '' && (
            in_array($value->getMimeType(), $parameters) ||
            in_array(explode('/', $value->getMimeType())[0].'/*', $parameters)
        );
    }

    /**
     * Check that the given value is a valid file instance.
     *
     * @param mixed $value
     *
     * @return bool
     */
    protected function isValidFileInstance($value)
    {
        return $value instanceof File && $value->isValid();
    }

    /**
     * Check if PHP uploads are explicitly allowed.
     *
     * @param mixed $value
     * @param array $parameters
     *
     * @return bool
     */
    protected function shouldBlockPhpUpload($value, $parameters)
    {
        if (in_array('php', $parameters)) {
            return false;
        }

        /**
         * @var $value \FluentCart\Framework\Validator\Contracts\File
         */
        return strtolower($value->getClientOriginalExtension()) === 'php';
    }

    /**
     * Validate that an attribute exists even if not filled.
     *
     * @param string $attribute
     * @param mixed $value
     *
     * @return bool
     */
    protected function validatePresent($attribute, $value)
    {
        $attribute = str_replace(['[', ']'], ['.', ''], $attribute);

        return Arr::has($this->data, $attribute);
    }

    /**
     * Validate that an attribute has a given number of digits.
     *
     * @param string $attribute
     * @param mixed $value
     * @param array $parameters
     * 
     * @return bool
     */
    protected function validateDigits($attribute, $value, $parameters)
    {
        $this->requireParameterCount(1, $parameters, 'digits');

        return $this->validateNumeric($attribute, $value) 
                    && strlen((string) $value) == $parameters[0];
    }

    /**
     * Validate that an attribute is an array.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  array  $parameters
     * @return bool
     */
    protected function validateArray($attribute, $value, $parameters = [])
    {
        if (! is_array($value)) {
            return false;
        }

        if (empty($parameters)) {
            return true;
        }

        return empty(array_diff_key($value, array_fill_keys($parameters, '')));
    }

    /**
     * Validate that an array has all of the given keys.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  array<int, int|string>  $parameters
     * @return bool
     */
    protected function validateRequiredArrayKeys($attribute, $value, $parameters)
    {
        $this->requireParameterCount(1, $parameters, 'required_array_keys');

        if (!is_array($value)) {
            return false;
        }

        foreach ($parameters as $key) {
            if (!Arr::exists($value, $key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate that an attribute passes a regular expression check.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  array<int, int|string>  $parameters
     * @return bool
     */
    protected function validateRegex($attribute, $value, $parameters)
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return false;
        }

        $this->requireParameterCount(1, $parameters, 'regex');

        return preg_match($parameters[0], $value) > 0;
    }

    /**
     * Validate an attribute is contained within a list of values.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  array  $parameters
     * @return bool
     */
    protected function validateIn($attribute, $value, $parameters)
    {
        if (is_array($value) && $this->hasRule($attribute, 'Array')) {
            foreach ($value as $element) {
                if (is_array($element)) {
                    return false;
                }
            }

            return count(array_diff($value, $parameters)) === 0;
        }

        return !is_array($value) && in_array((string) $value, $parameters);
    }

    /**
     * Validate an attribute is not contained within a list of values.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  array  $parameters
     * @return bool
     */
    protected function validateNotIn($attribute, $value, $parameters)
    {
        return !$this->validateIn($attribute, $value, $parameters);
    }
}

<?php

namespace FluentCart\App\Http\Rules;

use FluentCart\Framework\Support\Arr;

class MaxLengthRule
{
    public function __invoke($attr, $value, $rules, $data, ...$params)
    {


        $value = trim($value);
        if (is_numeric($value)) {
            $value = (string)$value;
        }

        if(!is_string($value)) {
            return sprintf(
                /* translators: 1: attribute name */
                __('The %s must be a valid text', 'fluent-cart'),
                $attr
            );
        }

        $maxLength = Arr::get($params, '0', 254);

        if(strlen($value) > $maxLength) {
            return sprintf(
                /* translators: 1: attribute name, 2: max length */
                __('The %1$s must not be greater than %2$s characters.', 'fluent-cart'),
                $attr,
                $maxLength
            );
        }

        return null;
    }

    /**
     * Build a closure rule that reuses this rule's length check but returns
     * a caller-supplied message on failure instead of the generic one.
     * Validator::validateAttribute() uses a closure rule's own return value
     * as the final error message, bypassing messages() entirely — the only
     * message channel that Validator::extend()-registered rules honor.
     *
     * @param int $maxLength
     * @param string $message
     * @return \Closure
     */
    public static function withMessage($maxLength, $message)
    {
        return function ($attribute, $value, $rules, $data) use ($maxLength, $message) {
            $error = (new static())($attribute, $value, $rules, $data, $maxLength);

            return $error ? $message : null;
        };
    }
}

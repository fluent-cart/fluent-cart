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


}

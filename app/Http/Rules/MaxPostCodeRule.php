<?php

namespace FluentCart\App\Http\Rules;

class MaxPostCodeRule
{
    public function __invoke($attr, $value, $rules, $data, ...$params)
    {
        if (is_numeric($value)) {
            $value = (string)$value;
        }

        if(!is_string($value)) {
            return sprintf(
                /* translators: 1: attribute name */
                __("The %s must be a valid text", "fluent-cart"),
                $attr
            );
        }

        if(strlen($value) > 100) {
            return sprintf(
                /* translators: 1: attribute name */
                __("The %s must not be greater than 100 characters.", "fluent-cart"),
                $attr
            );
        }


        return null;
    }


}

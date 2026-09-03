<?php

namespace FluentCart\App\Http\Rules;

class SanitizeTextArea
{
    public function __invoke($attr, $value, $rules, $data, ...$params)
    {
        if (!is_string($value)) {
            return sprintf(
                /* translators: %1$s: field attribute name */
                __('The %1$s must be a valid text', 'fluent-cart'),
                $attr
            );
        }
        $value = trim($value);
        
        if (is_numeric($value)) {
            $value = (string)$value;
        }

        $isString = is_string($value);
        if (!$isString) {
            return sprintf(
                /* translators: 1: attribute name */
                __('The %s must be a valid text', 'fluent-cart'),
                $attr
            );
        }
        $sanitizedValue = sanitize_textarea_field($value);
        if ($sanitizedValue !== $value) {
            return sprintf(
                /* translators: 1: attribute name */
                __('The %s must be a valid text', 'fluent-cart'),
                $attr
            );
        }

        return null;
    }

}

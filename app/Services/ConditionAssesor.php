<?php

namespace FluentCart\App\Services;


use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;

class ConditionAssesor
{
    public static function evaluate(&$field, &$inputs)
    {
        $status = Arr::get($field, 'conditionals.status');

        $conditionals = $status ? Arr::get($field, 'conditionals.conditions') : false;

        $hasConditionMet = true;

        if ($conditionals) {
            $toMatch = Arr::get($field, 'conditionals.type');


            foreach ($conditionals as $conditional) {

                $hasConditionMet = static::assess($conditional, $inputs);

                if ($hasConditionMet && $toMatch == 'any') {
                    return true;
                }

                if ($toMatch === 'all' && !$hasConditionMet) {
                    return false;
                }
            }
        }

        return $hasConditionMet;
    }

    public static function assess(&$conditional, &$inputs)
    {
        if ($conditional['field']) {
            $inputValue = Arr::get($inputs, $conditional['field']);

            switch ($conditional['operator']) {
                case '=':
                    if (is_array($inputValue)) {
                        return in_array($conditional['value'], $inputValue);
                    }
                    return $inputValue == $conditional['value'];
                    break;
                case '!=':
                    if (is_array($inputValue)) {
                        return !in_array($conditional['value'], $inputValue);
                    }
                    return $inputValue != $conditional['value'];
                    break;
                case '>':
                    return $inputValue > $conditional['value'];
                    break;
                case '<':
                    return $inputValue < $conditional['value'];
                    break;
                case '>=':
                    return $inputValue >= $conditional['value'];
                    break;
                case '<=':
                    return $inputValue <= $conditional['value'];
                    break;
                case 'startsWith':
                    return Str::startsWith($inputValue, $conditional['value']);
                    break;
                case 'endsWith':
                    return Str::endsWith($inputValue, $conditional['value']);
                    break;
                case 'contains':
                    return Str::contains($inputValue, $conditional['value']);
                    break;
                case 'doNotContains':
                    return !Str::contains($inputValue, $conditional['value']);
                    break;
                case 'length_equal':
                    if (is_array($inputValue)) {
                        return count($inputValue) == $conditional['value'];
                    }
                    $inputValue = strval($inputValue);
                    return strlen($inputValue) == $conditional['value'];
                    break;
                case 'length_less_than':
                    if (is_array($inputValue)) {
                        return count($inputValue) < $conditional['value'];
                    }
                    $inputValue = strval($inputValue);
                    return strlen($inputValue) < $conditional['value'];
                    break;
                case 'length_greater_than':
                    if (is_array($inputValue)) {
                        return count($inputValue) > $conditional['value'];
                    }
                    $inputValue = strval($inputValue);
                    return strlen($inputValue) > $conditional['value'];
                    break;
            }
        }

        return false;
    }
}

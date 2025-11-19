<?php

namespace FluentCart\App\Services\ShortCodeParser;

use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;

trait ValueTransformer
{
    public array $callableFunctions = [
        'trim',
        'ucfirst',
        'strtolower',
        'strtoupper',
        'ucwords'
    ];

    public function transform($value, $code, $data)
    {

        $conditions = $this->evaluateCondition($code);

        $transformer = Arr::get($conditions, 'transformer');
        $defaultValue = Arr::get($conditions, 'default_value');

        if (empty($value) && empty($defaultValue)) {
            $value = apply_filters($this->hookPrefix . 'smartcode_fallback', $value, $code, $data, $conditions);
        }

        if (empty($transformer)) {
            return $value;
        }

        if (in_array($transformer, $this->callableFunctions)) {
            return call_user_func($transformer, $value);
        }

        switch ($transformer) {
            case 'concat_first': // usage: {{contact.first_name||concat_first|Hi
                if ($defaultValue && !empty($value)) {
                    $value = trim($defaultValue . $value);
                }
                return $value;
            case 'concat_last': // usage: {{contact.first_name||concat_last|, => FIRST_NAME,
                if ($defaultValue && !empty($value)) {

                    $value = trim($value . $defaultValue);
                }
                return $value;

            case 'title_case':
                if (empty($value)) {
                    return $value;
                }
                return Str::title($value);

            case 'headline':
                if (empty($value)) {
                    return $value;
                }
                return Str::headline($value);
            case 'show_if': // usage {{contact.first_name||show_if|First name exist
                if (!empty($value)) {
                    $value = $defaultValue;
                } else {
                    $value = '';
                }
                return $value;
            default:
                return $value;
        }
    }


    public function evaluateCondition($smartCode): array
    {
        $conditions = [];
        $parsedCode = explode('|', $smartCode);
        $codeCound = count($parsedCode);
        if ($codeCound >= 3) {
            $conditions = [
                'transformer'   => trim(Arr::get($parsedCode, '2') ?? ''),
                'default_value' => trim(Arr::get($parsedCode, '3') ?? ''),
            ];
        } else if ($codeCound === 2) {
            $conditions = [
                'transformer'   => null,
                'default_value' => trim(Arr::get($parsedCode, '1') ?? ''),
            ];
        }
        $conditions['accessor'] = trim(Arr::get($parsedCode, '0') ?? '');

        return $conditions;
    }
}

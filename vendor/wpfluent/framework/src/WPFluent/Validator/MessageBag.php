<?php

namespace FluentCart\Framework\Validator;

use FluentCart\Framework\Support\Arr;

trait MessageBag
{
    /**
     * The default message bag.
     *
     * @var array
     */
    protected $bag = [
        'array'       => 'The :attribute must be an array.',
        'alpha'       => 'The :attribute must contain only alphabetic characters.',
        'alphanum'    => 'The :attribute must contain only alphanumeric characters.',
        'alphadash'   => 'The :attribute must contain only alphanumeric and _- characters.',
        'email'       => 'The :attribute must be a valid email address.',
        'date_format' => 'Unable to format the :attribute field from the :value format string.',
        'exists'      => 'The selected :attribute is invalid.',
        'gt'          => 'The :attribute must be greater than :value.',
        'lt'          => 'The :attribute must be less than :value.',
        'gte'          => 'The :attribute must be greater than or equal to :value.',
        'lte'          => 'The :attribute must be less than or equal to :value.',
        'in'          => 'The selected :attribute is invalid.',
        'not_in'          => 'The selected :attribute is invalid.',
        'max'         => [
            'numeric' => 'The :attribute may not be greater than :max.',
            'file'    => 'The :attribute may not be greater than :max kilobytes.',
            'string'  => 'The :attribute may not be greater than :max characters.',
            'array'   => 'The :attribute may not have more than :max items.',
        ],
        'mimes'       => 'The :attribute must be a file of type: :values.',
        'mimetypes'   => 'The :attribute must be a file of type: :values.',
        'min'         => [
            'numeric' => 'The :attribute must be at least :min.',
            'file'    => 'The :attribute must be at least :min kilobytes.',
            'string'  => 'The :attribute must be at least :min characters.',
            'array'   => 'The :attribute must have at least :min items.',
        ],
        'string'      => 'The :attribute must be a string.',
        'integer'     => 'The :attribute must be an integer.',
        'numeric'     => 'The :attribute must be a number.',
        'required'    => 'The :attribute field is required.',
        'required_array_keys'    => 'The :attribute must be an array and should contain :keys.',
        'required_if' => 'The :attribute field is required when :other is :value.',
        'required_with' => 'The :attribute field is required when the :other field is present.',
        'required_with_all' => 'The :attribute field is required when the :other field is present.',
        'same'        => 'The :attribute and :other must match.',
        'size'        => [
            'numeric' => 'The :attribute must be :size.',
            'file'    => 'The :attribute must be :size kilobytes.',
            'string'  => 'The :attribute must be :size characters.',
            'array'   => 'The :attribute must contain :size items.',
        ],
        'url'         => 'The :attribute format is invalid.',
        'unique'      => 'The :attribute attribute is already taken and must be unique.',
        'digits'      => 'The :attribute must be :digits characters.'
    ];

    /**
     * Generate a validation error message.
     *
     * @param $attribute
     * @param $rule
     * @param $parameters
     * @param $originalRuleKey
     *
     * @return mixed
     */
    protected function generate($attribute, $rule, $parameters, $originalRuleKey = null)
    {
        $method = 'replace'.str_replace(
                ' ', '', ucwords(str_replace(['-', '_'], ' ', $rule))
            );

        $originalKey = '';

        if (!empty($originalRuleKey)){
            $originalKey = $originalRuleKey.'.'.$rule;
        }

        if ($this->hasMethod($method)) {
            return $this->$method($attribute, $parameters, $originalKey);
        } else {
            return $this->generateDefaultMessage($attribute, $parameters);
        }
    }

    /**
     * Fallback message generator for the failed validation.
     * @param  string $attribute
     * @param  array $parameters
     * @return string
     */
    protected function generateDefaultMessage($attribute, $parameters)
    {
        $msg = "The {$attribute} field has been failed the validation";

        if ($parameters) {
            $msg .= " with parameter \"{$parameters[0]}\"";
        }

        return ($msg . '.');
    }


    /**
     * Get the replacement text of the error message.
     *
     * @param $customKey
     * @param $bagAccessor
     * @param $originalKey
     *
     * @return string
     */
    protected function getReplacementText($customKey, $bagAccessor, $originalKey = null)
    {
        if (isset($this->customMessages[$customKey])) {
            return $this->customMessages[$customKey];
        } elseif (isset($this->customMessages[$originalKey])) {
            return $this->customMessages[$originalKey];
        }

        return Arr::get($this->bag, $bagAccessor, '');
    }

    /**
     * Make bag accessor key.
     *
     * @param $attribute
     * @param $rule
     *
     * @return string
     */
    protected function makeBagKey($attribute, $rule)
    {
        $type = $this->deduceType(
            $this->getValue($attribute), $attribute
        );

        return $rule.'.'.$type;
    }

    /**
     * Replace all place-holders for the string rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     * @return string
     */
    protected function replaceString($attribute, $parameters, $originalKey)
    {
        $text = $this->getReplacementText(
            $attribute.'.string', 'string', $originalKey
        );

        return str_replace(':attribute', $attribute, $text);
    }

    /**
     * Replace all place-holders for the int|integer rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     * @return string
     */
    protected function replaceInt($attribute, $parameters, $originalKey)
    {
        return $this->replaceInteger($attribute, $parameters, $originalKey);
    }

    /**
     * Replace all place-holders for the int|integer rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     *
     * @return string
     */
    protected function replaceInteger($attribute, $parameters, $originalKey)
    {
        $text = $this->getReplacementText(
            $attribute.'.integer', 'integer', $originalKey
        );

        return str_replace(':attribute', $attribute, $text);
    }

    /**
     * Replace all place-holders for the alpha rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     *
     * @return string
     */
    protected function replaceAlpha($attribute, $parameters,  $originalKey)
    {
        $text = $this->getReplacementText(
            $attribute.'.alpha', 'alpha',  $originalKey
        );

        return str_replace(':attribute', $attribute, $text);
    }

    /**
     * Replace all place-holders for the alphanum rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     *
     * @return string
     */
    protected function replaceAlphanum($attribute, $parameters,  $originalKey)
    {
        $text = $this->getReplacementText(
            $attribute.'.alphanum', 'alphanum', $originalKey
        );

        return str_replace(':attribute', $attribute, $text);
    }

    /**
     * Replace all place-holders for the alphadash rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     *
     * @return string
     */
    protected function replaceAlphadash($attribute, $parameters,  $originalKey)
    {
        $text = $this->getReplacementText(
            $attribute.'.alphadash', 'alphadash', $originalKey
        );

        return str_replace(':attribute', $attribute, $text);
    }

    /**
     * Replace all place-holders for the required rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     *
     * @return string
     */
    protected function replaceRequired($attribute, $parameters, $originalKey)
    {
        $text = $this->getReplacementText(
            $attribute.'.required', 'required', $originalKey
        );

        return str_replace(':attribute', $attribute, $text);
    }

    /**
     * Replace all place-holders for the required if rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     *
     * @return string
     */
    protected function replaceRequiredIf($attribute, $parameters,  $originalKey)
    {
        if (preg_match('/\.\d\./', $attribute, $matches)) {
            $parameters[0] = str_replace(['.*.'], $matches, $parameters[0]);
        }

        $text = $this->getReplacementText($attribute.'.required_if', 'required_if', $originalKey);

        $value = end($parameters);

        return str_replace([
            ':attribute', ':other', ':value'],
            [$attribute, $parameters[0], $value],
            $text
        );
    }

    /**
     * Replace all place-holders for the required with rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     *
     * @return string
     */
    protected function replaceRequiredWith($attribute, $parameters,  $originalKey)
    {
        if (preg_match('/\.\d\./', $attribute, $matches)) {
            $parameters[0] = str_replace(['.*.'], $matches, $parameters[0]);
        }

        $text = $this->getReplacementText($attribute.'.required_with', 'required_with', $originalKey);

        $other = '';

        if (count($parameters) === 1) {
            $other = $parameters[0];
        } elseif (count($parameters) === 2) {
            $other = implode(' or ', $parameters);
        } else {
            $last = array_pop($parameters);
            $other = implode(', ', $parameters) . ' or ' . $last;
        }

        return str_replace([
            ':attribute', ':other'],
            [$attribute, $other],
            $text
        );
    }

    /**
     * Replace all place-holders for the required with all rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     *
     * @return string
     */
    protected function replaceRequiredWithAll($attribute, $parameters,  $originalKey)
    {
        if (preg_match('/\.\d\./', $attribute, $matches)) {
            $parameters[0] = str_replace(['.*.'], $matches, $parameters[0]);
        }

        $text = $this->getReplacementText($attribute.'.required_with_all', 'required_with_all', $originalKey);

        $other = '';

        if (count($parameters) === 1) {
            $other = $parameters[0];
        } elseif (count($parameters) === 2) {
            $other = $parameters[0] . ' and ' . $parameters[1];
        } else {
            $last = array_pop($parameters);
            $other = 'all ' . implode(', ', $parameters) . ' and ' . $last;
        }

        $text = 'The :attribute field is required when :other field(s) are present.';

        return str_replace(
            [':attribute', ':other'],
            [$attribute, $other],
            $text
        );
    }

    /**
     * Replace placeholders in a validation message for required array keys.
     *
     * @param string $attribute
     * @param array  $parameters
     * @param string $originalKey
     *
     * @return string
     */
    protected function replaceRequiredArrayKeys(
        $attribute,
        $parameters, 
        $originalKey
    )
    {
        if (preg_match('/\.\d\./', $attribute, $matches)) {
            $parameters[0] = str_replace(['.*.'], $matches, $parameters[0]);
        }

        $text = $this->getReplacementText($attribute.'.required_array_keys', 'required_array_keys', $originalKey);

        $other = '';

        if (count($parameters) === 1) {
            $other = $parameters[0] . ' key';
        } elseif (count($parameters) === 2) {
            $other = $parameters[0] . ' and ' . $parameters[1] . ' keys';
        } else {
            $last = array_pop($parameters);
            $other = 'all ' . implode(', ', $parameters) . ' and ' . $last . ' keys';
        }

        return str_replace(
            [':attribute', ':keys'],
            [$attribute, $other],
            $text
        );
    }

    /**
     * Replace all place-holders for the gt rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     *
     * @return string
     */
    protected function replaceGt($attribute, $parameters,  $originalKey)
    {
        if (preg_match('/\.\d\./', $attribute, $matches)) {
            $parameters[0] = str_replace(['.*.'], $matches, $parameters[0]);
        }

        $text = $this->getReplacementText($attribute.'.gt', 'gt', $originalKey);

        $value = end($parameters);

        return str_replace([
            ':attribute', ':value'],
            [$attribute, $value],
            $text
        );
    }

    /**
     * Replace all place-holders for the lt rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     *
     * @return string
     */
    protected function replaceLt($attribute, $parameters,  $originalKey)
    {
        if (preg_match('/\.\d\./', $attribute, $matches)) {
            $parameters[0] = str_replace(['.*.'], $matches, $parameters[0]);
        }

        $text = $this->getReplacementText($attribute.'.lt', 'lt', $originalKey);

        $value = end($parameters);

        return str_replace([
            ':attribute', ':value'],
            [$attribute, $value],
            $text
        );
    }

    /**
     * Replace all place-holders for the gte rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     *
     * @return string
     */
    protected function replaceGte($attribute, $parameters,  $originalKey)
    {
        if (preg_match('/\.\d\./', $attribute, $matches)) {
            $parameters[0] = str_replace(['.*.'], $matches, $parameters[0]);
        }

        $text = $this->getReplacementText($attribute.'.gte', 'gte', $originalKey);

        $value = end($parameters);

        return str_replace([
            ':attribute', ':value'],
            [$attribute, $value],
            $text
        );
    }

    /**
     * Replace all place-holders for the lte rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     *
     * @return string
     */
    protected function replaceLte($attribute, $parameters,  $originalKey)
    {
        if (preg_match('/\.\d\./', $attribute, $matches)) {
            $parameters[0] = str_replace(['.*.'], $matches, $parameters[0]);
        }

        $text = $this->getReplacementText($attribute.'.lte', 'lte', $originalKey);

        $value = end($parameters);

        return str_replace([
            ':attribute', ':value'],
            [$attribute, $value],
            $text
        );
    }

    /**
     * Replace all place-holders for the email rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     *
     * @return string
     */
    protected function replaceEmail($attribute, $parameters, $originalKey)
    {
        $text = $this->getReplacementText(
            $attribute.'.email', 'email', $originalKey
        );

        return str_replace(':attribute', $attribute, $text);
    }

    /**
     * Replace all place-holders for the email rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     *
     * @return string
     */
    protected function replaceDateformat($attribute, $parameters, $originalKey)
    {
        $text = $this->getReplacementText($attribute.'.date_format', 'date_format', $$originalKey);

        return str_replace(
            [':attribute', ':value'], [$attribute, $parameters[0]], $text
        );
    }

    /**
     * Replace all place-holders for the size rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     *
     * @return string
     */
    protected function replaceSize($attribute, $parameters, $originalKey)
    {
        $text = $this->getReplacementText($attribute.'.size', $this->makeBagKey($attribute, 'size'),  $originalKey);

        return str_replace(
            [':attribute', ':size'], [$attribute, $parameters[0]], $text
        );
    }

    /**
     * Replace all place-holders for the min rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     *
     * @return string
     */
    protected function replaceMin($attribute, $parameters, $originalKey)
    {
        $text = $this->getReplacementText($attribute.'.min', $this->makeBagKey($attribute, 'min'),  $originalKey);

        return str_replace(
            [':attribute', ':min'], [$attribute, $parameters[0]], $text
        );
    }

    /**
     * Replace all place-holders for the max rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     *
     * @return string
     */
    protected function replaceMax($attribute, $parameters, $originalKey)
    {
        $text = $this->getReplacementText($attribute.'.max', $this->makeBagKey($attribute, 'max'), $originalKey);

        return str_replace(
            [':attribute', ':max'], [$attribute, $parameters[0]], $text
        );
    }

    /**
     * Replace all place-holders for the min rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     *
     * @return string
     */
    protected function replaceSame($attribute, $parameters, $originalKey)
    {
        $text = $this->getReplacementText($attribute.'.same', 'same', $originalKey);

        return str_replace(
            [':attribute', ':other'], [$attribute, $parameters[0]], $text
        );
    }

    /**
     * Replace all place-holders for the url rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     *
     * @return string
     */
    protected function replaceUrl($attribute, $parameters, $originalKey)
    {
        $text = $this->getReplacementText($attribute.'.url', 'url', $originalKey);

        return str_replace(':attribute', $attribute, $text);
    }

    /**
     * Replace all place-holders for the numeric rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     *
     * @return string
     */
    protected function replaceNumeric($attribute, $parameters, $originalKey)
    {
        $text = $this->getReplacementText($attribute.'.numeric', 'numeric', $originalKey);

        return str_replace(':attribute', $attribute, $text);
    }

    /**
     * Replace all place-holders for the mimes rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     *
     * @return string
     */
    protected function replaceMimes($attribute, $parameters, $originalKey)
    {
        $text = $this->getReplacementText($attribute.'.mimes', 'mimes', $originalKey);

        return str_replace([':attribute', ':values'], [$attribute, implode(', ', $parameters)], $text);
    }

    /**
     * Replace all place-holders for the mimetypes rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     *
     * @return string
     */
    protected function replaceMimetypes($attribute, $parameters, $originalKey)
    {
        $text = $this->getReplacementText($attribute.'.mimetypes', 'mimetypes', $originalKey);

        return str_replace([':attribute', ':values'], [$attribute, implode(', ', $parameters)], $text);
    }

    /**
     * Replace all place-holders for the unique rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     *
     * @return string
     */
    protected function replaceUnique($attribute, $parameters, $originalKey)
    {
        $text = $this->getReplacementText($attribute.'.unique', 'unique', $originalKey);

        return str_replace(':attribute', $attribute, $text);
    }

    /**
     * Replace all place-holders for the digits rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     *
     * @return string
     */
    protected function replaceDigits($attribute, $parameters, $originalKey)
    {
        $text = $this->getReplacementText($attribute.'.digits', 'digits', $originalKey);

        return str_replace([':attribute', ':digits'], [$attribute, $parameters[0]], $text);
    }

    /**
     * Replace all place-holders for the array rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     *
     * @return string
     */
    protected function replaceArray($attribute, $parameters, $originalKey)
    {
        $text = $this->getReplacementText($attribute.'.array', 'array', $originalKey);

        return str_replace([':attribute', ':array'], [$attribute], $text);
    }

    /**
     * Replace all place-holders for the in rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     *
     * @return string
     */
    protected function replaceIn($attribute, $parameters, $originalKey)
    {
        $text = $this->getReplacementText($attribute.'.in', 'in', $originalKey);

        return str_replace([':attribute', ':in'], [$attribute], $text);
    }

    /**
     * Replace all place-holders for the not_in rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     *
     * @return string
     */
    protected function replaceNotIn($attribute, $parameters, $originalKey)
    {
        $text = $this->getReplacementText($attribute.'.not_in', 'not_in', $originalKey);

        return str_replace([':attribute', ':not_in'], [$attribute], $text);
    }

    /**
     * Replace all place-holders for the exista rule.
     *
     * @param $attribute
     * @param $parameters
     * @param $originalKey
     *
     * @return string
     */
    protected function replaceExists($attribute, $parameters, $originalKey)
    {
        $text = $this->getReplacementText($attribute.'.exists', 'exists', $originalKey);

        return str_replace([':attribute', ':exists'], [$attribute], $text);
    }
}

<?php

namespace FluentCart\Framework\Validator;

use Closure;
use FluentCart\Framework\Framework\Support\Arr;
use FluentCart\Framework\Validator\Rules\Exists;
use FluentCart\Framework\Validator\Rules\Unique;
use FluentCart\Framework\Validator\Rules\ConditionalRules;

class ValidationRuleParser
{
    /**
     * The data being validated.
     *
     * @var array
     */
    public $data;

    /**
     * Create a new validation rule parser.
     *
     * @param array $data
     *
     * @return void
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Parse the human-friendly rules into a full rules array for the validator.
     *
     * @param $rules
     *
     * @return array
     */
    public function explode($rules)
    {
        return $this->explodeRules($rules);
    }

    /**
     * Explode the rules into an array of explicit rules.
     *
     * @param array $rules
     *
     * @return array
     */
    protected function explodeRules($rules)
    {
        foreach ($rules as $attribute => $rule) {
            if (mb_strpos($attribute, '*') !== false) {
                $rules = $this->explodeWildcardRules($rules, $attribute, [$rule]);

                unset($rules[$attribute]);
            } else {
                $rules[$attribute] = $this->explodeExplicitRule($rule);
            }
        }

        return $rules;
    }

    /**
     * Explode the explicit rule into an array if necessary.
     *
     * @param mixed $rule
     *
     * @return array
     */
    protected function explodeExplicitRule($rule)
    {
        $rules = $conditionals = [];

        $ruleArray = is_array($rule) ? $rule : [$rule];
        
        foreach ($ruleArray as  $key => $rule) {

            if ($rule instanceof ConditionalRules) {
                $conditionals = $this->parseConditionalRules($rule);
            } elseif($rule instanceof Closure) {
                $rules[$key] = $rule;
            } else {
                if (
                    ($rule instanceof Exists && $rule->queryCallbacks()) ||
                    ($rule instanceof Unique && $rule->queryCallbacks())
                ) {
                    $rules[] = $rule;
                } else {
                    $rules[] = (string) $rule;
                }
            }
        }

        $rules = array_merge($rules, $conditionals);

        // Now, we'll check if there is any string rule
        // given inside the array using the | sign, i.e:
        // required|alpha, if any, we'll convert it to array.

        $result = [];

        foreach ($rules as $key => $value) {
            if (is_string($value)) {
                $result = array_merge($result, explode('|', $value));
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Parse conditional rules.
     * 
     * @param  \FluentCart\Framework\Validator\Rules\ConditionalRules $rule
     * @return array
     */
    protected function parseConditionalRules($rule)
    {
        $rules = [];

        $conditionals = $rule->passes($this->data)
                                ? array_filter($rule->rules())
                                : array_filter($rule->defaultRules());
                
        if ($conditionals) {
            foreach ($conditionals as $conditional) {
                if (is_object($conditional) && method_exists($conditional, '__toString')) {
                    $rules[] = (string) $conditional;
                } elseif (is_string($conditional)) {
                    $rules[] = $conditional;
                }
            }
        }

        return $rules;
    }

    /**
     * Define a set of rules that apply to each element in an array attribute.
     *
     * @param array $results
     * @param string $attribute
     * @param string|array $rules
     *
     * @return array
     */
    protected function explodeWildcardRules($results, $attribute, $rules)
    {
        $pattern = str_replace('\*', '[^\.]*', preg_quote($attribute));

        $data = ValidationData::initializeAndGatherData($attribute, $this->data);

        foreach ($data as $key => $value) {
            if (substr($key, 0, strlen($attribute)) === $attribute || (bool) preg_match('/^'.$pattern.'\z/', $key)) {
                foreach ((array) $rules as $rule) {
                    $results = $this->mergeRules($results, $key, $rule, $attribute);
                }
            }
        }

        return $results;
    }

    /**
     * Merge additional rules into a given attribute(s).
     *
     * @param array $results
     * @param string|array $attribute
     * @param string|array $rules
     * @param string|null  $originalRuleKey
     *
     * @return array
     */
    public function mergeRules($results, $attribute, $rules = [], $originalRuleKey = null)
    {
        if (is_array($attribute)) {
            foreach ((array) $attribute as $innerAttribute => $innerRules) {
                $results = $this->mergeRulesForAttribute($results, $innerAttribute, $innerRules, $originalRuleKey);
            }

            return $results;
        }

        return $this->mergeRulesForAttribute(
            $results, $attribute, $rules , $originalRuleKey
        );
    }

    /**
     * Merge additional rules into a given attribute.
     *
     * @param array $results
     * @param string $attribute
     * @param string|array $rules
     * @param string|null  $originalRuleKey
     *
     * @return array
     */
    protected function mergeRulesForAttribute($results, $attribute, $rules, $originalRuleKey = null)
    {
        $array = $this->explodeRules([$rules]);

        $merge = reset($array);

        if(!empty($originalRuleKey)){
            $merge['rule_key'] = $originalRuleKey;
        }

        $results[$attribute] = array_merge(
            isset($results[$attribute]) ?
            $this->explodeExplicitRule($results[$attribute])
            : [], $merge
        );

        return $results;
    }

    /**
     * Extract the rule name and parameters from a rule.
     *
     * @param $rule
     *
     * @return array
     */
    public static function parse($rule)
    {
        if ($rule instanceof Closure) {
            return [$rule, []];
        }

        $parameters = [];

        if (strpos($rule, ':') !== false) {
            list($rule, $parameter) = explode(':', $rule, 2);

            $parameters = str_getcsv($parameter, ',', '"', '\\');
        }

        return [trim($rule), $parameters];
    }
}

<?php

namespace FluentCart\Framework\Validator;

use Closure;
use InvalidArgumentException;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;
use FluentCart\Framework\Foundation\App;

class Validator
{
    use ValidatesAttributes, MessageBag;

    /**
     * The rules that should be applied to the data.
     * 
     * @var array
     */
    protected $dependentRules = [];

    /**
     * Indicates whether the validate method is called or not.
     * @var boolean
     */
    protected $isReady = false;

    /**
     * The data under validation.
     *
     * @var array
     */
    protected $data;

    /**
     * The valid data after validation.
     *
     * @var array
     */
    protected $validated;

    /**
     * The rules to be applied to the data.
     *
     * @var array
     */
    protected $rules = [];

    /**
     * All of the error messages.
     *
     * @var array
     */
    protected $messages;

    /**
     * All of the user provided messages.
     *
     * @var array
     */
    protected $customMessages = [];

    /**
     * The validation rules that imply the field is required.
     *
     * @var array
     */
    protected $implicitRules = [
        'Required',
        'Filled',
        'RequiredWith',
        'RequiredWithAll',
        'RequiredWithout',
        'RequiredWithoutAll',
        'RequiredIf',
        'RequiredUnless',
        'Accepted',
        'Present',
    ];

    /**
     * The current rule being handled
     * @var mixed
     */
    protected $currentRule = null;

    /**
     * Custom rules added by developer
     * @var array
     */
    protected static $customRules = [];

    /**
     * Create a new Validator instance.
     *
     * @param array $data
     * @param array $rules
     * @param array $messages
     *
     * @return void
     */
    public function __construct(
        array $data = [], array $rules = [], array $messages = []
    ) {
        $this->data = $data;

        $this->messages = [];

        $this->customMessages = $messages;

        $this->setRules($rules);
    }

    /**
     * Create a new Validator instance.
     *
     * @param array $data
     * @param array $rules
     * @param array $messages
     *
     * @return \FluentCart\Framework\Validator\Validator
     */
    public static function make(array $data, array $rules = [], array $messages = [])
    {
        return new static($data, $rules, $messages);
    }

    /**
     * Set the validation rules.
     *
     * @param array $rules
     *
     * @return $this
     */
    protected function setRules(array $rules = [])
    {
        $this->rules = array_merge_recursive($this->rules, $rules);

        return $this;
    }

    /**
     * Validate the data against the provided rules.
     *
     * @return $this
     */
    public function validate(array $data = [], array $rules = [])
    {
        $this->rules = (new ValidationRuleParser(
            $this->data = $data ?: $this->data
        ))->explode($rules ?: $this->rules);

        foreach ($this->rules as $attribute => $rules) {
            $originalRuleKey = Arr::get($rules, 'rule_key');
            
            unset($rules['rule_key']);

            $rules = $this->filterExcludeables($attribute, $rules);

            if (empty($rules)) {
                continue;
            }

            foreach ($rules as $key => $rule) {
                $this->validateAttribute(
                    $attribute, $rule, $key, $originalRuleKey
                );
            }
        }

        return $this->ready();
    }

    /**
     * Remove rules that can be skipped (nullable, required_if, sometimes).
     * 
     * @param string $attribute
     * @param array $rules
     * @return array
     */
    protected function filterExcludeables(string $attribute, array $rules)
    {
        if (in_array('nullable', $rules) && !$this->getValue($attribute)) {
            return [];
        }

        $rules = $this->filterRequiredIf($attribute, $rules);

        // Handle sometimes
        if (in_array('sometimes', $rules)) {
            $rules = array_diff($rules, ['sometimes']);
            if (!array_key_exists($attribute, $this->data)) {
                return [];
            }
        }

        return $rules;
    }

    /**
     * Filter required_if rules based on other field values.
     * 
     * @param string $attribute
     * @param array $rules
     * @return array
     */
    protected function filterRequiredIf(string $attribute, array $rules)
    {
        $requiredIfRules = [];
        $otherRules = [];

        foreach ($rules as $rule) {
            if ($rule instanceof Closure) continue;

            if (str_starts_with($rule, 'required_if')) {
                $requiredIfRules[] = $rule;
            } else {
                $otherRules[] = $rule;
            }
        }

        if (empty($requiredIfRules)) {
            return $rules;
        }

        foreach ($requiredIfRules as $rule) {
            $params = explode(',', substr($rule, strlen('required_if:')));
            $otherField = trim(array_shift($params));
            $expectedValues = array_map('trim', $params);

            // Resolve wildcards
            $otherValues = $this->getValues($otherField);

            // Keep rule if any match
            foreach ($otherValues as $val) {
                if (in_array($val, $expectedValues)) {
                    return $rules;
                }
            }
        }

        return [];
    }

    /**
     * Resolves wildcard attributes to actual values from data
     *
     * @param string $path
     * @return array
     */
    protected function getValues(string $path)
    {
        // If there is no dot/wildcard, just fetch the value
        if (!str_contains($path, '*') && strpos($path, '.') === false) {
            $value = $this->getValue($path);
            return $value !== null ? [$value] : [];
        }

        $segments = explode('.', $path);
        $results = [$this->data];

        foreach ($segments as $segment) {
            $next = [];

            if ($segment === '*') {
                foreach ($results as $res) {
                    if (is_array($res)) {
                        foreach ($res as $v) {
                            $next[] = $v;
                        }
                    }
                }
            } else {
                foreach ($results as $res) {
                    if (is_array($res) && array_key_exists($segment, $res)) {
                        $next[] = $res[$segment];
                    }
                }
            }

            $results = $next;
        }

        return $results;
    }

    /**
     * Mark that validation is ready.
     * 
     * @return $this
     */
    protected function ready()
    {
        $this->isReady = true;
        return $this;
    }

    /**
     * Validate each of the attribute of the data.
     *
     * @param string $attribute
     * @param string $rule
     * @param string|null $key
     * @param string|null $originalRuleKey
     *
     * @return void
     */
    protected function validateAttribute(
        $attribute,
        $rule,
        $key = null,
        $originalRuleKey = null
    ) {
        $this->currentRule = $rule;

        list($rule, $parameters) = ValidationRuleParser::parse($rule);

        if ($rule === '') {
            return;
        }

        $value = $this->getValue($attribute);

        if ($rule instanceof Closure) {
            $params = [];

            if ($key && strpos($key, ':') !== false) {
                $params = explode(':', $key);
                $params = array_filter(
                    array_map('trim', explode(',', end($params)))
                );
            }

            if (
                $message = $rule(
                    $attribute, $value, $this->rules, $this->data, ...$params
                )
            ) {
                if (is_string($message)) {
                    $this->messages[$attribute][$key] = str_replace(
                        ':attribute', $attribute, $message
                    );
                }
            }

            return $this->setValidatedAttributeData($attribute, $value);
        }

        $shouldValidate = $this->shouldValidate(
            $ruleCamelCase = Str::studly($rule), $attribute, $value
        );

        $method = 'validate'.$ruleCamelCase;

        if ($shouldValidate && !$this->$method($attribute, $value, $parameters)) {
            $this->addFailure($attribute, $rule, $parameters, $originalRuleKey);
        }

        $this->setValidatedAttributeData($attribute, $value);
    }

    /**
     * Determine if the given rule depends on other fields.
     *
     * @param  string  $rule
     * @return bool
     */
    protected function dependsOnOtherFields($rule)
    {
        if (!empty($rule) && is_string($rule)) {
            return in_array(Str::studly($rule), $this->dependentRules);
        }
    }

    /**
     * Access the data by attribute name.
     *
     * @param $attribute
     *
     * @return mixed
     */
    protected function getValue($attribute)
    {
        $attribute = str_replace(['[', ']'], ['.', ''], $attribute);

        return Arr::get($this->data, $attribute);
    }

    /**
     * Add error message upon validation failed of an attribute.
     *
     * @param $attribute
     * @param $rule
     * @param $parameters
     * @param $originalRuleKey
     *
     * @return void
     */
    protected function addFailure($attribute, $rule, $parameters, $originalRuleKey = null)
    {
        $this->messages[$attribute][$rule] = $this->generate(
            $attribute, $rule, $parameters, $originalRuleKey
        );
    }

    /**
     * Add validation error(s) manually.
     * 
     * @param string|array $attribute
     * @param string $message
     * @return void
     */
    public function addError($attribute, $message = 'Something went wrong.')
    {
        if (is_array($attribute)) {
            foreach ($attribute as $field => $errors) {
                foreach ($errors as $rule => $message) {
                    $this->setError($field.'.'.$rule, $message);
                }
            }
        } else {
            $this->setError($attribute, $message);
        }
        
    }

    /**
     * Add a single validation error manually.
     * 
     * @param string $attribute
     * @param string $message
     * @return void
     */
    public function setError($attribute, $message)
    {
        if (!str_contains($attribute, '.')) {
            $attribute .= '.invalid';
        }

        list($attribute, $rule) = explode('.', $attribute);

        $this->messages[$attribute][$rule] = $message;
    }

    /**
     * Manually pass a validation by clearing the messages.
     * 
     * @return void
     */
    public function pass()
    {
        $this->messages = [];
    }

    /**
     * Manually fail a validation by adding messages.
     * 
     * @return void
     */
    public function fail($attribute = 'Field.Rule', $message = 'Something went wrong.')
    {
        $this->addError($attribute, $message);

        throw new ValidationException(
            'Unprocessable Entity!', 422, null, $this->errors()
        );
    }

    /**
     * Get a single validation error message.
     *
     * @return array
     */
    public function error($key)
    {
        return $this->errors($key);
    }

    /**
     * Get one or all of the validation error messages.
     *
     * @return array
     */
    public function errors($key = null)
    {
        return $key ? Arr::get($this->messages, $key, function() use ($key) {
            throw new InvalidArgumentException("The {$key} doesn't exist.");
        }) : $this->messages;
    }

    /**
     * Determine if the data passes the validation rules.
     *
     * @return bool
     */
    public function passes()
    {
        return !$this->fails();
    }

    /**
     * Determine if the data fails the validation rules.
     *
     * @return bool
     */
    public function fails()
    {
        if (!$this->isReady) {
            $this->validate();
        }

        return (bool) count($this->messages);
    }

    /**
     * Get the valid data after validation has been passed.
     *
     * @return array
     */
    public function validated()
    {
        return (array) $this->validated;
    }

    /**
     * Set the valid data after validation has
     * been passed for a specific attribute.
     *
     * @param string $attribute
     * @param mixed $value
     * @return null
     */
    public function setValidatedAttributeData($attribute, $value)
    {
        Arr::set($this->validated, $attribute, $value);
    }

    /**
     * Add conditions to a given field based on a Closure.
     *
     * @param string|array $attribute
     * @param string|array $rules
     * @param callable $callback
     *
     * @return $this
     */
    public function sometimes($attribute, $rules, callable $callback)
    {
        $payload = $this->data;

        if (call_user_func($callback, $payload)) {
            foreach ((array) $attribute as $key) {
                $this->setRules([$key => $rules]);
            }
        }

        return $this;
    }

    /**
     * Determine if the attribute has a required rule.
     *
     * @param $attribute
     *
     * @return bool
     */
    public function hasRequired($attribute)
    {
        if (empty($this->rules[$attribute])) {
            return false;
        }

        foreach ($this->rules[$attribute] as $rule) {
            if (strpos($rule, 'required') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the attribute should be validated.
     *
     * @param $method
     * @param $attribute
     * @param $value
     *
     * @return bool
     */
    public function shouldValidate($rule, $attribute, $value)
    {
        return $this->presentOrRuleIsImplicit($rule, $attribute, $value);
    }

    /**
     * Determines if this object has this method.
     *
     * @param $method
     *
     * @return bool
     */
    public function hasMethod($method)
    {
        return method_exists($this, $method);
    }

    /**
     * Determine if a given rule implies the attribute is required.
     *
     * @param string $rule
     *
     * @return bool
     */
    protected function isImplicit($rule)
    {
        return in_array($rule, $this->implicitRules) || in_array($rule, static::$customRules);
    }

    /**
     * Determine if the field is present, or the rule implies required.
     *
     * @param string $rule
     * @param string $attribute
     * @param mixed $value
     *
     * @return bool
     */
    protected function presentOrRuleIsImplicit($rule, $attribute, $value)
    {
        return $this->validatePresent(
            $attribute, $value
        ) || $this->isImplicit($rule);
    }

    public function extend($rule, $callback, $message = null)
    {
        $ruleCamelCase = ucwords(Str::camel($rule));

        static::$customRules[$ruleCamelCase] = $callback;
    }

    /**
     * Get all the custom rules with handlers
     * 
     * @return array
     */
    public function getExtentions()
    {
        return static::$customRules;
    }

    /**
     * Handle dynamic calls for custom rules
     * @param  string $method
     * @param  array $params
     * @return bool (true)
     */
    public function __call($method, $params)
    {
        if ($this->currentRule === 'nullable') {
            return true;
        }

        $params = array_pad($params, 3, null);

        list($attribute, $value, $params) = $params;

        $rule = substr(
            $this->currentRule, 0, strpos($this->currentRule, ':')
        ) ?: $this->currentRule;

        if ($callback = Arr::get(static::$customRules, ucwords(str::camel($rule)))) {

            if (is_string($callback) && class_exists($callback)) {
                $callback = App::make($callback);
            }

            if ($message = $callback($attribute, $value, $this->rules, $this->data, ...$params)) {

                is_string($message) && $this->messages[$attribute][$rule] = str_replace(
                    ':attribute', $attribute, $message
                );
            }
            return true;
        }

        throw new InvalidArgumentException(
            "The {$rule} rule is undefined or invalid."
        );
    }
}

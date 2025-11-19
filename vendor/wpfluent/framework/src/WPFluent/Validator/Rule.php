<?php

namespace FluentCart\Framework\Validator;

use Closure;
use LogicException;
use ReflectionClass;
use BadMethodCallException;
use InvalidArgumentException;
use FluentCart\Framework\Support\Str;
use FluentCart\Framework\Foundation\App;
use FluentCart\Framework\Validator\Rules\In;
use FluentCart\Framework\Validator\Rules\NotIn;
use FluentCart\Framework\Validator\Rules\Unique;
use FluentCart\Framework\Validator\Rules\Exists;
use FluentCart\Framework\Validator\Rules\RequiredIf;
use FluentCart\Framework\Validator\Rules\Dimensions;
use FluentCart\Framework\Validator\Rules\ConditionalRules;
use FluentCart\Framework\Support\ArrayableInterface;

class Rule
{
    /**
     * Create a new conditional rule set.
     *
     * @param  callable|bool  $condition
     * @param  array|string  $rules
     * @param  array|string  $defaultRules
     * @return \FluentCart\Framework\Validator\Rules\ConditionalRules
     */
    public static function when($condition, $rules, $defaultRules = [])
    {
        return new ConditionalRules($condition, $rules, $defaultRules);
    }

    /**
     * Get a dimensions constraint builder instance.
     *
     * @param  array  $constraints
     * @return \FluentCart\Framework\Validator\Rules\Dimensions
     */
    public static function dimensions(array $constraints = [])
    {
        return new Dimensions($constraints);
    }

    /**
     * Get an exists constraint builder instance.
     *
     * @param  string  $table
     * @param  string  $column
     * @return \FluentCart\Framework\Validator\Rules\Exists
     */
    public static function exists($table, $column = 'NULL')
    {
        return new Exists($table, $column);
    }

    /**
     * Get an in constraint builder instance.
     *
     * @param  \FluentCart\Framework\Support\ArrayableInterface|array|string  $values
     * @return \FluentCart\Framework\Validator\Rules\In
     */
    public static function in($values)
    {
        if ($values instanceof ArrayableInterface) {
            $values = $values->toArray();
        }

        return new In(is_array($values) ? $values : func_get_args());
    }

    /**
     * Get a not_in constraint builder instance.
     *
     * @param  \FluentCart\Framework\Support\ArrayableInterface|array|string  $values
     * @return \FluentCart\Framework\Validator\Rules\NotIn
     */
    public static function notIn($values)
    {
        if ($values instanceof ArrayableInterface) {
            $values = $values->toArray();
        }

        return new NotIn(is_array($values) ? $values : func_get_args());
    }

    /**
     * Get a required_if constraint builder instance.
     *
     * @param  callable|bool  $callback
     * @return \FluentCart\Framework\Validator\Rules\RequiredIf
     */
    public static function requiredIf($callback)
    {
        return new RequiredIf($callback);
    }

    /**
     * Get a unique constraint builder instance.
     *
     * @param  string  $table
     * @param  string  $column
     * @return \FluentCart\Framework\Validator\Rules\Unique
     */
    public static function unique($table, $column = 'NULL')
    {
        return new Unique($table, $column);
    }

    /**
     * Add a custom rule.
     * 
     * @param string $rule
     * @param callable $callback
     * @return null
     * @throws InvalidArgumentException|LogicException
     */
    public static function add($rule, $callback = null)
    {
        if (is_null($callback)) {
            
            $callback = $rule;

            $rule = $msg = null;

            if (is_string($callback)) {
                
                $rule = explode('\\', $callback);

                $rule = Str::snake(end($rule));

            } elseif (is_object($callback)) {

                if ($callback instanceof Closure) {
                    $msg = 'A rule name is required for a closure based rule';
                } elseif ((new ReflectionClass($callback))->isAnonymous()) {
                    $msg = 'A rule name is required for an anonymous class based rule';
                } else {
                    $rule = get_class($callback);
                }
            }

            if($msg && !$rule) throw new InvalidArgumentException($msg, 500);
        }
        
        $classExists = false;

        if (is_string($callback)) {
            $classExists = class_exists($callback);
        }

        $methodExists = $classExists && method_exists($callback, '__invoke');

        if (!($classExists && $methodExists) && !is_callable($callback)) {
            $m = 'The given callback is not callable';

            if (is_object($callback) || ($classExists && !$methodExists)) {
                $m .= ' and must implement the __invoke magic method.';
            }

            throw new LogicException($m, 500);
        }

        App::make('validator')->extend($rule, $callback);
    }

    /**
     * Handle dynamic calls
     * 
     * @param  string $method
     * @param  array $params
     * @return bool/true
     * @throws BadMethodCallException
     */
    public static function __callStatic($method, $params)
    {
        $method = Str::studly($method);

        if ($customRules = App::make('validator')->getExtentions()) {

            if (in_array($method, array_keys($customRules))) {
                return Str::snake($method) . ':' . implode(',', $params);
            }
        }

        throw new BadMethodCallException(
            'Call to undefined method '. __CLASS__ . ':'. $method, 500
        );
    }
}

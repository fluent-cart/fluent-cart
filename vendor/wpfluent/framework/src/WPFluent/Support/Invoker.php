<?php

/**
 * Invoker Class — Overview and Usage Guide
 *
 * What is the Invoker class?
 * 
 * The Invoker class is a utility helper that allows you to access and
 * manipulate private and protected properties and methods of PHP
 * objects and classes at runtime. It uses PHP’s Closure
 * binding and Reflection API under the hood.
 *
 * This is especially useful when working with third-party or legacy code where
 * you don’t have control over visibility or public APIs, but still need to
 * read/write internal state or call hidden methods for testing,
 * debugging, or extending functionality.
 *
 * Key Features:
 * - Access private/protected instance properties
 * — read or modify values not exposed publicly.
 * 
 * - Call private/protected instance methods
 * — invoke internal methods not accessible otherwise.
 * 
 * - Access private/protected static properties on classes.
 * - Call private/protected static methods on classes.
 * - Unified static API for all operations
 * — no need to instantiate the Invoker class.
 * 
 * - Direct closure binding through invoke() for custom, complex access scenarios.
 *
 * When to use the Invoker class?
 * - When you need to test or debug classes with private/protected members.
 * - When working with third-party libraries or legacy code where
 * you can't change visibility.
 * 
 * - For unit tests that require access to internal state or behaviors.
 * - When you want to extend or patch behavior of an existing
 * class without modifying its source.
 * 
 * - When you want to inspect or manipulate class internals safely without
 * reflection boilerplate scattered everywhere.
 *
 * 
 * Examples of using Invoker:
 *
 * // Access private/protected instance properties
 * $value = Invoker::get($object, 'propertyName');
 * Invoker::set($object, 'propertyName', $newValue);
 *
 * // Call private/protected instance methods
 * $result = Invoker::call($object, 'methodName', [$arg1, $arg2]);
 *
 * // Access private/protected static properties
 * $value = Invoker::getStatic(ClassName::class, 'staticProperty');
 * Invoker::setStatic(ClassName::class, 'staticProperty', $newValue);
 *
 * // Call private/protected static methods
 * $result = Invoker::callStatic(ClassName::class, 'staticMethod', [$arg1, $arg2]);
 *
 * // Use custom closure binding for complex scenarios
 * $result = Invoker::invoke($object, function () {
 *     // $this references the bound object instance
 *     return $this->somePrivateProperty + 42;
 * });
 */


namespace FluentCart\Framework\Support;

use Closure;
use ReflectionClass;
use ReflectionMethod;

class Invoker
{
    /**
     * Get the value of a private or protected property from an object.
     *
     * @param object $object   The object instance to read property from.
     * @param string $property The property name to access.
     * @return mixed           The value of the property.
     */
    public static function get($object, $property)
    {
        return static::invoke($object, function () use ($property) {
            // @phpstan-ignore-next-line
            return $this->$property;
        });
    }

    /**
     * Set the value of a private or protected property on an object.
     *
     * @param object $object   The object instance to set property on.
     * @param string $property The property name to modify.
     * @param mixed  $value    The value to assign to the property.
     * @return void
     */
    public static function set($object, $property, $value)
    {
        static::invoke($object, function () use ($property, $value) {
            // @phpstan-ignore-next-line
            $this->$property = $value;
        });
    }

    /**
     * Call a private or protected method on an object.
     *
     * @param object $object The object instance to call method on.
     * @param string $method The method name to invoke.
     * @param array  $args   Optional array of arguments to pass to the method.
     * @return mixed         The result returned by the method.
     */
    public static function call($object, $method, $args = [])
    {
        return static::invoke($object, function () use ($method, $args) {
            // @phpstan-ignore-next-line
            return call_user_func_array([$this, $method], $args);
        });
    }

    /**
     * Get the value of a private or protected static property on a class.
     *
     * @param string $class    The fully qualified class name.
     * @param string $property The static property name.
     * @return mixed           The value of the static property.
     * @throws \ReflectionException When the property does not exist.
     */
    public static function getStatic($class, $property)
    {
        $ref = new ReflectionClass($class);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        return $prop->getValue();
    }

    /**
     * Set the value of a private or protected static property on a class.
     *
     * @param string $class    The fully qualified class name.
     * @param string $property The static property name.
     * @param mixed  $value    The value to assign to the static property.
     * @return void
     * @throws \ReflectionException When the property does not exist.
     */
    public static function setStatic($class, $property, $value)
    {
        $ref = new ReflectionClass($class);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue(null, $value);
    }

    /**
     * Call a private or protected static method on a class.
     *
     * @param string $class  The fully qualified class name.
     * @param string $method The static method name to invoke.
     * @param array  $args   Optional array of arguments to pass to the method.
     * @return mixed         The result returned by the static method.
     * @throws \ReflectionException When the method does not exist.
     */
    public static function callStatic($class, $method, $args = [])
    {
        $ref = new ReflectionMethod($class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs(null, $args);
    }

    /**
     * Bind a closure to an object and invoke it with optional arguments.
     *
     * @param object  $object  The object to bind the closure to.
     * @param Closure $closure The closure to bind and invoke.
     * @param mixed   ...$args Optional arguments to pass to the closure.
     * @return mixed           The result returned by the closure.
     */
    public static function invoke($object, $closure, ...$args)
    {
        $bound = Closure::bind($closure, $object, get_class($object));
        return call_user_func_array($bound, $args);
    }
}

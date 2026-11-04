<?php

namespace Tests\Support\Traits;

/**
 * Reflection plumbing for pinning down private helpers that are an
 * implementation detail of a public entry point (e.g. AbilitiesRegistrar's
 * register() internals). Lives here so test files stay pure — no private
 * helper methods inside test classes.
 */
trait InvokesPrivateMethods
{
    /**
     * Invoke a private/protected method via reflection.
     *
     * @param string      $class    fully-qualified class name
     * @param string      $method   method name
     * @param array       $args     arguments to pass
     * @param object|null $instance instance for non-static methods; null for static
     * @return mixed the method's return value
     */
    protected function invokePrivateMethod(string $class, string $method, array $args = [], $instance = null)
    {
        $ref = new \ReflectionMethod($class, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($instance, $args);
    }
}

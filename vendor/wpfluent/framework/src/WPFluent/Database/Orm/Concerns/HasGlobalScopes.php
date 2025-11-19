<?php

namespace FluentCart\Framework\Database\Orm\Concerns;

use Closure;
use InvalidArgumentException;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Database\Orm\Scope;

trait HasGlobalScopes
{
    /**
     * Register a new global scope on the model.
     *
     * @param  \FluentCart\Framework\Database\Orm\Scope|\Closure|string  $scope
     * @param  \Closure|null  $implementation
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    public static function addGlobalScope($scope, ?Closure $implementation = null)
    {
        if (is_string($scope) && ($implementation instanceof Closure || $implementation instanceof Scope)) {
            return static::$globalScopes[static::class][$scope] = $implementation;
        } elseif ($scope instanceof Closure) {
            return static::$globalScopes[static::class][spl_object_hash($scope)] = $scope;
        } elseif ($scope instanceof Scope) {
            return static::$globalScopes[static::class][get_class($scope)] = $scope;
        } elseif (is_string($scope) && class_exists($scope) && is_subclass_of($scope, Scope::class)) {
            return static::$globalScopes[static::class][$scope] = new $scope;
        }

        throw new InvalidArgumentException('Global scope must be an instance of Closure or Scope or be a class name of a class extending '.Scope::class);
    }

    /**
     * Register multiple global scopes on the model.
     *
     * @param  array  $scopes
     * @return void
     */
    public static function addGlobalScopes(array $scopes)
    {
        foreach ($scopes as $key => $scope) {
            if (is_string($key)) {
                static::addGlobalScope($key, $scope);
            } else {
                static::addGlobalScope($scope);
            }
        }
    }

    /**
     * Determine if a model has a global scope.
     *
     * @param  \FluentCart\Framework\Database\Orm\Scope|string  $scope
     * @return bool
     */
    public static function hasGlobalScope($scope)
    {
        return ! is_null(static::getGlobalScope($scope));
    }

    /**
     * Get a global scope registered with the model.
     *
     * @param  \FluentCart\Framework\Database\Orm\Scope|string  $scope
     * @return \FluentCart\Framework\Database\Orm\Scope|\Closure|null
     */
    public static function getGlobalScope($scope)
    {
        if (is_string($scope)) {
            return Arr::get(static::$globalScopes, static::class.'.'.$scope);
        }

        return Arr::get(
            static::$globalScopes, static::class.'.'.get_class($scope)
        );
    }

    /**
     * Set the current global scopes.
     *
     * @param  array  $scopes
     * @return void
     */
    public static function setAllGlobalScopes($scopes)
    {
        static::$globalScopes = $scopes;
    }

    /**
     * Get the global scopes for this class instance.
     *
     * @return array
     */
    public function getGlobalScopes()
    {
        return Arr::get(static::$globalScopes, static::class, []);
    }
}

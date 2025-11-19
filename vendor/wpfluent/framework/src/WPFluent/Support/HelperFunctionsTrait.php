<?php

namespace FluentCart\Framework\Support;


trait HelperFunctionsTrait
{
    /**
     * Get the class base name
     * 
     * @param  mixed $class
     * @return string
     */
    public static function classBasename($class)
    {
        $class = is_object($class) ? get_class($class) : $class;
        return basename(str_replace('\\', '/', $class));
    }

    /**
     * Retrive all traits used by a class, including traits used by all of its parent classes.
     * @param  string|object $class
     * @return array
     */
    public static function classUsesRecursive($class)
    {
        if (is_object($class)) {
            $class = get_class($class);
        }

        $results = [];

        foreach (array_reverse(class_parents($class)) + [$class => $class] as $class) {
            $results += static::traitUsesRecursive($class);
        }

        return array_unique($results);
    }

    /**
     * Retrieve all traits used by a trait
     * @param  string $trait
     * @return array
     */
    public static function traitUsesRecursive($trait)
    {
        $traits = class_uses($trait) ?: [];

        foreach ($traits as $trait) {
            $traits += static::traitUsesRecursive($trait);
        }

        return $traits;
    }
}

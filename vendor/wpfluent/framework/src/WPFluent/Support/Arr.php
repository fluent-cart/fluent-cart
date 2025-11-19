<?php

namespace FluentCart\Framework\Support;

use Closure;
use ArrayAccess;
use InvalidArgumentException;
use FluentCart\Framework\Support\Helper;
use FluentCart\Framework\Support\Collection;
use FluentCart\Framework\Support\MacroableTrait;

class Arr
{
    use MacroableTrait;

    /**
     * Makes a collection from array
     * 
     * @param  array $array
     * @return \FluentCart\Framework\Support\Collection
     */
    public static function of(array $array)
    {
        return Helper::collect($array);
    }

    /**
     * Determine whether the given value is array accessible.
     *
     * @param  mixed  $value
     * @return bool
     */
    public static function accessible($value)
    {
        return is_array($value) || $value instanceof ArrayAccess;
    }

    /**
     * Add an element to an array using "dot" notation if it doesn't exist.
     *
     * @param  array  $array
     * @param  string  $key
     * @param  mixed  $value
     * @return array
     */
    public static function add($array, $key, $value)
    {
        if (is_null(static::get($array, $key))) {
            static::set($array, $key, $value);
        }

        return $array;
    }

    /**
     * Collapse an array of arrays into a single array.
     *
     * @param  iterable  $array
     * @return array
     */
    public static function collapse($array)
    {
        $results = [];

        foreach ($array as $values) {
            if ($values instanceof Collection) {
                $values = $values->all();
            } elseif (! is_array($values)) {
                continue;
            }

            $results[] = $values;
        }

        return array_merge([], ...$results);
    }

    /**
     * Cross join the given arrays, returning all possible permutations.
     *
     * @param  iterable  ...$arrays
     * @return array
     */
    public static function crossJoin(...$arrays)
    {
        $results = [[]];

        foreach ($arrays as $index => $array) {
            $append = [];

            foreach ($results as $product) {
                foreach ($array as $item) {
                    $product[$index] = $item;

                    $append[] = $product;
                }
            }

            $results = $append;
        }

        return $results;
    }

    /**
     * Divide an array into two arrays. One with keys and the other with values.
     *
     * @param  array  $array
     * @return array
     */
    public static function divide($array)
    {
        return [array_keys($array), array_values($array)];
    }

    /**
     * Flatten a multi-dimensional associative array with dots.
     *
     * @param  iterable  $array
     * @param  string  $prepend
     * @return array
     */
    public static function dot($array, $prepend = '')
    {
        $results = [];

        foreach ($array as $key => $value) {
            if (is_array($value) && ! empty($value)) {
                $results = array_merge($results, static::dot($value, $prepend.$key.'.'));
            } else {
                $results[$prepend.$key] = $value;
            }
        }

        return $results;
    }

    /**
     * Convert a flatten "dot" notation array into an expanded array.
     *
     * @param  iterable  $array
     * @return array
     */
    public static function undot($array)
    {
        $results = [];

        foreach ($array as $key => $value) {
            static::set($results, $key, $value);
        }

        return $results;
    }

    /**
     * Get all of the given array except for a specified array of keys.
     *
     * @param  array  $array
     * @param  array|string  $keys
     * @return array
     */
    public static function except($array, $keys)
    {
        static::forget($array, $keys);

        return $array;
    }

    /**
     * Determine if the given key exists in the provided array.
     *
     * @param  \ArrayAccess|array  $array
     * @param  string|int  $key
     * @return bool
     */
    public static function exists($array, $key)
    {
        if ($array instanceof Enumerable) {
            return $array->has($key);
        }

        if ($array instanceof ArrayAccess) {
            return $array->offsetExists($key);
        }

        return array_key_exists($key, $array);
    }

    /**
     * Alias of exists.
     * @param  \ArrayAccess|array  $array
     * @param  string|int  $key
     * @return bool
     */
    public static function keyExists($array, $key)
    {
        return static::exists($array, $key);
    }

    /**
     * Alias of exists.
     * @param  \ArrayAccess|array  $array
     * @param  string|int  $key
     * @return bool
     */
    public static function arrayKeyExists($array, $key)
    {
        return static::exists($array, $key);
    }

    /**
     * Return the first element in an array passing a given truth test.
     *
     * @param  iterable  $array
     * @param  callable|null  $callback
     * @param  mixed  $default
     * @return mixed
     */
    public static function first($array, ?callable $callback = null, $default = null)
    {
        if (is_null($callback)) {
            if (empty($array)) {
                return Helper::value($default);
            }

            foreach ($array as $item) {
                return $item;
            }
        }

        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return Helper::value($default);
    }

    /**
     * Returns the key of the first item (matching the specified
     * callback if given) or null if there is no such item.
     * 
     * @param  array $array
     * @param  callable|null $callback
     * @return mixed
     */
    public static function firstKey($array, ?callable $callback = null)
    {
        if (!$callback) {
            return array_key_first($array);
        }

        foreach ($array as $k => $v) {
            if ($callback($v, $k, $array)) {
                return $k;
            }
        }

        return null;
    }

    /**
     * Recursively filter an array like array_filter.
     * 
     * @param  array $array
     * @param  callable|null $cb
     * @param  integer $mode (ARRAY_FILTER_USE_BOTH = 1 | ARRAY_FILTER_USE_KEY = 2)
     * @return array
     */
    public static function filterRecursive($array, ?callable $cb = null, $mode = 0)
    {
        $result = [];

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                if (is_int($key)) {
                    $result[] = static::filterRecursive($value, $cb, $mode);
                } else {
                    $result[$key] = static::filterRecursive($value, $cb, $mode);
                }
            } else {
                if (is_null($cb)) {
                    if ($value) {
                        if (is_int($key)) {
                            $result[] = $value;
                        } else {
                            $result[$key] = $value;
                        }
                    }
                } else {
                    if ($mode && call_user_func($cb, $value, $key)) {
                        if (is_int($key)) {
                            $result[] = $value;
                        } else {
                            $result[$key] = $value;
                        }
                    } elseif (!$mode && call_user_func($cb, $value)) {
                        if (is_int($key)) {
                            $result[] = $value;
                        } else {
                            $result[$key] = $value;
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Recursively search the value and return the path of first match.
     * 
     * @param array $array
     * @param mixed $value
     * @param bool $ci (false for case insensitive search, true otherwise)
     * @return string|null
     */
    public static function findPath($array, $value, $ci = false)
    {
        if (!$ci) {
            $value = strtolower($value);
            $array = static::map($array, 'strtolower');
        }

        foreach ($array as $key => $val) {
            if ($val === $value) {
                return $key;
            } elseif (is_array($val) && $path = static::findPath($val, $value, $ci)) {
                return $key . '.' . $path;
            }
        }
    }

    /**
     * Return the last element in an array passing a given truth test.
     *
     * @param  array  $array
     * @param  callable|null  $callback
     * @param  mixed  $default
     * @return mixed
     */
    public static function last($array, ?callable $callback = null, $default = null)
    {
        if (is_null($callback)) {
            return empty($array) ? Helper::value($default) : end($array);
        }

        return static::first(array_reverse($array, true), $callback, $default);
    }

    /**
     * Returns the key of the last item (matching the specified
     * callback if given) or null if there is no such item.
     * 
     * @param  array $array
     * @param  callable|null $callback
     * @return mixed
     */
    public static function lastKey($array, ?callable $callback = null)
    {
        if (!$callback) {
            return array_key_last($array);
        }

        $lastKey = null;

        foreach ($array as $k => $v) {
            if ($callback($v, $k, $array)) {
                $lastKey = $k;
            }
        }

        return $lastKey;
    }

    /**
     * Flatten a multi-dimensional array into a single level.
     *
     * @param  iterable  $array
     * @param  int|float  $depth
     * @return array
     */
    public static function flatten($array, $depth = INF)
    {
        $result = [];

        foreach ($array as $item) {
            $item = $item instanceof Collection ? $item->all() : $item;

            if (! is_array($item)) {
                $result[] = $item;
            } else {
                $values = $depth === 1
                    ? array_values($item)
                    : static::flatten($item, $depth - 1);

                foreach ($values as $value) {
                    $result[] = $value;
                }
            }
        }

        return $result;
    }

    /**
     * Remove one or many array items from a given array using "dot" notation.
     *
     * @param  array  $array
     * @param  array|string  $keys
     * @return void
     */
    public static function forget(&$array, $keys)
    {
        $original = &$array;

        $keys = (array) $keys;

        if (count($keys) === 0) {
            return;
        }

        foreach ($keys as $key) {
            // if the exact key exists in the top-level, remove it
            if (static::exists($array, $key)) {
                unset($array[$key]);

                continue;
            }

            $parts = explode('.', $key);

            // clean up before each pass
            $array = &$original;

            while (count($parts) > 1) {
                $part = array_shift($parts);

                if (isset($array[$part]) && is_array($array[$part])) {
                    $array = &$array[$part];
                } else {
                    continue 2;
                }
            }

            unset($array[array_shift($parts)]);
        }
    }

    /**
     * Get an item from an array using "dot" notation.
     *
     * @param  \ArrayAccess|array  $array
     * @param  string|int|null  $key
     * @param  mixed  $default
     * @return mixed
     */
    public static function get($array, $key, $default = null)
    {
        if (! static::accessible($array)) {
            return Helper::value($default);
        }

        if (is_null($key)) {
            return $array;
        }

        if (static::exists($array, $key)) {
            return $array[$key];
        }

        if (strpos($key, '.') === false) {
            return $array[$key] ?? Helper::value($default);
        }

        foreach (explode('.', $key) as $segment) {
            if (static::accessible($array) && static::exists($array, $segment)) {
                $array = $array[$segment];
            } else {
                return Helper::value($default);
            }
        }

        return $array;
    }

    /**
     * Check if an item or items (using key) exist in an array using "dot" notation.
     *
     * @param  \ArrayAccess|array  $array
     * @param  string|array  $keys
     * @return bool
     */
    public static function has($array, $keys)
    {
        $keys = (array) $keys;

        if (! $array || $keys === []) {
            return false;
        }

        foreach ($keys as $key) {
            $subKeyArray = $array;

            if (static::exists($array, $key)) {
                continue;
            }

            foreach (explode('.', $key) as $segment) {
                if (static::accessible($subKeyArray) && static::exists($subKeyArray, $segment)) {
                    $subKeyArray = $subKeyArray[$segment];
                } else {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Determine if any of the keys exist in an array using "dot" notation.
     *
     * @param  \ArrayAccess|array  $array
     * @param  string|array  $keys
     * @return bool
     */
    public static function hasAny($array, $keys)
    {
        if (is_null($keys)) {
            return false;
        }

        $keys = (array) $keys;

        if (! $array) {
            return false;
        }

        if ($keys === []) {
            return false;
        }

        foreach ($keys as $key) {
            if (static::has($array, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Alias of contains.
     *
     * @param  array $array
     * @param  string|array  $value
     * @return bool
     */
    public static function inArray($array, $value)
    {
        return static::contains($array, $value);
    }

    /**
     * Determines if an array is associative.
     *
     * An array is "associative" if it doesn't have
     * sequential numerical keys beginning with zero.
     *
     * @param  array  $array
     * @return bool
     */
    public static function isAssoc(array $array)
    {
        return !static::isList($array);
    }

    /**
     * Determines if an array is a list.
     *
     * An array is a "list" if all array keys are sequential
     * integers starting from 0 with no gaps in between.
     *
     * @param  array  $array
     * @return bool
     */
    public static function isList($array)
    {
        $i = -1;
        foreach ($array as $k => $v) {
            ++$i;
            if ($k !== $i) {
                return false;
            }
        }
        return true;
    }

    /**
     * Determines if the given key contains a boolean value.
     *
     * Returns true for true, 1, "1", "true", "on" and "yes"
     * Returns false for false, "0", "false", "off", "no", and ""
     * Returns for all non-boolean values.
     *
     * @param  array  $array
     * @param  string $key
     * 
     * @return bool|null
     * @see https://www.php.net/manual/en/filter.filters.validate.php
     */
    public static function isTrue($array, $key)
    {
        return filter_var(
            static::get($array, $key),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );
    }

    /**
     * Get a subset of the items from the given array.
     *
     * @param  array  $array
     * @param  array|string  $keys
     * @return array
     */
    public static function only($array, $keys)
    {
        return array_intersect_key($array, array_flip((array) $keys));
    }

    /**
     * Select an array of values from an array.
     *
     * @param  array  $array
     * @param  array|string  $keys
     * @return array
     */
    public static function select($array, $keys)
    {
        $keys = static::wrap($keys);

        return array_map(function ($item) use ($keys) {
            $result = [];
            
            $item = (array) $item;
            
            foreach ($keys as $key) {
                
                if (static::accessible($item) && static::has($item, $key)) {
                    
                    [$first] = explode('.', $key);

                    $result[$first] = static::get($item, $first);
                }
            }

            return $result;

        }, (array) $array);
    }

    /**
     * Pluck an array of values from an array.
     *
     * @param  iterable  $array
     * @param  string|array|int|null  $value
     * @param  string|array|null  $key
     * @return array
     */
    public static function pluck($array, $value, $key = null)
    {
        $results = [];

        [$value, $key] = static::explodePluckParameters($value, $key);

        foreach ($array as $item) {
            $itemValue = Helper::dataGet($item, $value);

            // If the key is "null", we will just append the value to the array and keep
            // looping. Otherwise we will key the array using the value of the key we
            // received from the developer. Then we'll return the final array form.
            if (is_null($key)) {
                $results[] = $itemValue;
            } else {
                $itemKey = Helper::dataGet($item, $key);

                if (is_object($itemKey) && method_exists($itemKey, '__toString')) {
                    $itemKey = (string) $itemKey;
                }

                $results[$itemKey] = $itemValue;
            }
        }

        return $results;
    }

    /**
     * Explode the "value" and "key" arguments passed to "pluck".
     *
     * @param  string|array  $value
     * @param  string|array|null  $key
     * @return array
     */
    protected static function explodePluckParameters($value, $key)
    {
        $value = is_string($value) ? explode('.', $value) : $value;

        $key = is_null($key) || is_array($key) ? $key : explode('.', $key);

        return [$value, $key];
    }

    /**
     * Push an item onto the beginning of an array.
     *
     * @param  array  $array
     * @param  mixed  $value
     * @param  mixed  $key
     * @return array
     */
    public static function prepend($array, $value, $key = null)
    {
        if (func_num_args() == 2) {
            array_unshift($array, $value);
        } else {
            $array = [$key => $value] + $array;
        }

        return $array;
    }

    /**
     * Get a value from the array, and remove it.
     *
     * @param  array  $array
     * @param  string|int  $key
     * @param  mixed  $default
     * @return mixed
     */
    public static function pull(&$array, $key, $default = null)
    {
        $value = static::get($array, $key, $default);

        static::forget($array, $key);

        return $value;
    }

    /**
     * Convert the array into a query string.
     *
     * @param  array  $array
     * @return string
     */
    public static function query($array)
    {
        return http_build_query($array, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Get one or a specified number of random values from an array.
     *
     * @param  array  $array
     * @param  int|null  $number
     * @param  bool|false  $preserveKeys
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    public static function random($array, $number = null, $preserveKeys = false)
    {
        $requested = is_null($number) ? 1 : $number;

        $count = count($array);

        if ($requested > $count) {
            throw new InvalidArgumentException(
                "You requested {$requested} items, but there are only {$count} items available."
            );
        }

        if (is_null($number)) {
            return $array[array_rand($array)];
        }

        if ((int) $number === 0) {
            return [];
        }

        $keys = array_rand($array, $number);

        $results = [];

        if ($preserveKeys) {
            foreach ((array) $keys as $key) {
                $results[$key] = $array[$key];
            }
        } else {
            foreach ((array) $keys as $key) {
                $results[] = $array[$key];
            }
        }

        return $results;
    }

    /**
     * Set an array item to a given value using "dot" notation.
     *
     * If no key is given to the method, the entire array will be replaced.
     *
     * @param  array  $array
     * @param  string|null  $key
     * @param  mixed  $value
     * @return array
     */
    public static function set(&$array, $key, $value)
    {
        if (is_null($key)) {
            return $array = $value;
        }

        $keys = explode('.', $key);

        foreach ($keys as $i => $key) {
            if (count($keys) === 1) {
                break;
            }

            unset($keys[$i]);

            // If the key doesn't exist at this depth, we will just create an empty array
            // to hold the next value, allowing us to create the arrays to hold final
            // values at the correct depth. Then we'll keep digging into the array.
            if (! isset($array[$key]) || ! is_array($array[$key])) {
                $array[$key] = [];
            }

            $array = &$array[$key];
        }

        $array[array_shift($keys)] = $value;

        return $array;
    }

    /**
     * Shuffle the given array and return the result.
     *
     * @param  array  $array
     * @param  int|null  $seed
     * @return array
     */
    public static function shuffle($array, $seed = null)
    {
        if (!is_null($seed)) {
            mt_srand($seed);
            usort($array, function () {
                return mt_rand(-1, 1);
            });
            mt_srand();
        } else {
            shuffle($array);
        }

        return $array;
    }

    /**
     * Sort the array using the given callback or "dot" notation.
     *
     * @param  array  $array
     * @param  callable|array|string|null  $callback
     * @return array
     */
    public static function sort($array, $callback = null)
    {
        return Collection::make($array)->sortBy($callback)->all();
    }

    /**
     * Sort an array in descending order.
     *
     * @param  array       $array  The input array.
     * @param  int-mask-of<SORT_REGULAR|SORT_NUMERIC|SORT_STRING|SORT_LOCALE_STRING|SORT_NATURAL|SORT_FLAG_CASE>  $flags
     * @return array
     * 
     * @see https://www.php.net/manual/en/function.rsort.php
     */
    public static function rsort($array, $flags = SORT_REGULAR)
    {
        rsort($array, $flags);
        return $array;
    }

    /**
     * Sort an array in ascending order and maintain index association.
     *
     * @param  array  $array
     * @param  int-mask-of<SORT_REGULAR|SORT_NUMERIC|SORT_STRING|SORT_LOCALE_STRING|SORT_NATURAL|SORT_FLAG_CASE>  $flags
     * @return array
     * @see https://www.php.net/manual/en/function.asort.php
     */
    public static function asort($array, $flags = SORT_REGULAR)
    {
        asort($array, $flags);
        return $array;
    }

    /**
     * Sort an array in descending order and maintain index association.
     *
     * @param  array  $array
     * @param  int-mask-of<SORT_REGULAR|SORT_NUMERIC|SORT_STRING|SORT_LOCALE_STRING|SORT_NATURAL|SORT_FLAG_CASE>  $flags
     * @return array
     * @see https://www.php.net/manual/en/function.arsort.php
     */
    public static function arsort($array, $flags = SORT_REGULAR)
    {
        arsort($array, $flags);
        return $array;
    }

    /**
     * Sort an array by key in ascending order.
     *
     * @param  array  $array
     * @param  int-mask-of<SORT_REGULAR|SORT_NUMERIC|SORT_STRING|SORT_LOCALE_STRING|SORT_NATURAL|SORT_FLAG_CASE>  $flags
     * @return array
     * @see https://www.php.net/manual/en/function.ksort.php
     */
    public static function ksort($array, $flags = SORT_REGULAR)
    {
        ksort($array, $flags);
        return $array;
    }

    /**
     * Sort an array by key in descending order.
     *
     * @param  array  $array
     * @param  int-mask-of<SORT_REGULAR|SORT_NUMERIC|SORT_STRING|SORT_LOCALE_STRING|SORT_NATURAL|SORT_FLAG_CASE>  $flags
     * @return array
     * @see https://www.php.net/manual/en/function.krsort.php
     */
    public static function krsort($array, $flags = SORT_REGULAR)
    {
        krsort($array, $flags);
        return $array;
    }

    /**
     * Sort an array using a "natural order" algorithm.
     *
     * @param  array  $array
     * @return array
     * @see https://www.php.net/manual/en/function.natsort.php
     */
    public static function natsort($array)
    {
        natsort($array);
        return $array;
    }

    /**
     * Sort an array using a case insensitive "natural order" algorithm.
     *
     * @param  array  $array
     * @return array
     * @see https://www.php.net/manual/en/function.natcasesort.php
     */
    public static function natcasesort($array)
    {
        natcasesort($array);
        return $array;
    }

    /**
     * Sort an array by values using a user-defined comparison function.
     *
     * @param  array  $array
     * @return array
     * @see https://www.php.net/manual/en/function.usort.php
     */
    public static function usort($array, callable $callback)
    {
        usort($array, $callback);
        return $array;
    }

    /**
     * Sort an array with a user-defined comparison
     * function and maintain index association.
     *
     * @param  array  $array
     * @return array
     * @see https://www.php.net/manual/en/function.uasort.php
     */
    public static function uasort($array, callable $callback)
    {
        uasort($array, $callback);
        return $array;
    }

    /**
     * Sort an array by keys using a user-defined comparison function.
     *
     * @param  array  $array
     * @return array
     * @see https://www.php.net/manual/en/function.uksort.php
     */
    public static function uksort($array, callable $callback)
    {
        uksort($array, $callback);
        return $array;
    }

    /**
     * Recursively sort an array by keys and values.
     *
     * @param  array  $array
     * @param  int  $options
     * @param  bool  $desc
     * @return array
     */
    public static function sortRecursive(
        $array,
        $options = SORT_REGULAR,
        $desc = false
    )
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                $value = static::sortRecursive($value, $options, $desc);
            }
        }

        if (static::isAssoc($array)) {
            $desc
                    ? krsort($array, $options)
                    : ksort($array, $options);
        } else {
            $desc
                    ? rsort($array, $options)
                    : sort($array, $options);
        }

        return $array;
    }

    /**
     * Recursively sort an array by keys and values in Descending order.
     *
     * @param  array  $array
     * @param  int  $options
     * @param  bool  $desc
     * @return array
     */
    public static function sortRecursiveDesc(
        $array,
        $options = SORT_REGULAR,
        $desc = false
    )
    {
        return static::sortRecursive($array, $options, true);
    }

    /**
     * Conditionally compile classes from an array into a CSS class list.
     *
     * @param  array  $array
     * @return string
     */
    public static function toCssClasses($array)
    {
        $classList = static::wrap($array);

        $classes = [];

        foreach ($classList as $class => $constraint) {
            if (is_numeric($class)) {
                $classes[] = $constraint;
            } elseif ($constraint) {
                $classes[] = $class;
            }
        }

        return implode(' ', $classes);
    }

    /**
     * Transforms an array to \stdClass
     * @param  array $array
     * @return \stdClass
     */
    public static function toObject($array)
    {
        return Helper::objectCreate($array);
    }

    /**
     * Filter the array using the given callback.
     *
     * @param  array  $array
     * @param  callable  $callback
     * @return array
     */
    public static function where($array, callable $callback)
    {
        return array_filter($array, $callback, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Filter items where the value is not null.
     *
     * @param  array  $array
     * @return array
     */
    public static function whereNotNull($array)
    {
        return static::where($array, function ($value) {
            return ! is_null($value);
        });
    }

    /**
     * Filter items where the value is not null.
     *
     * @param  array  $array
     * @return array
     */
    public static function whereNotTrue($array, $strict = false)
    {
        return static::where($array, function ($value) use ($strict) {
            return $strict ? $value === false : !$value;
        });
    }

    /**
     * If the given value is not an array and not null, wrap it in one.
     *
     * @param  mixed  $value
     * @return array
     */
    public static function wrap($value)
    {
        if (is_null($value)) {
            return [];
        }

        return is_array($value) ? $value : [$value];
    }

    /**
     * Maps a function to all non-iterable elements of an array or an object.
     *
     * This is similar to `array_walk_recursive()` but acts upon objects too.
     *
     * @param mixed    $value    The array, object, or scalar.
     * @param callable $callback The function to map onto $value.
     * @see https://developer.wordpress.org/reference/functions/map_deep/
     * 
     * @return mixed The value with the callback applied to all non-arrays and non-objects inside it.
     */
    public static function map($value, $callback)
    {
        return map_deep($value, $callback);
    }

    /**
     * Check if the value(s) exist in an array using "dot" notation.
     *
     * @param  array $array
     * @param  string|array  $values
     * @return bool
     */
    public static function contains(array $array, $values)
    {
        $result = [];
        
        $values = is_array($values) ? $values : [$values];

        foreach ($values as $value) {

            if (in_array($value, $array)) {
                $result[] = $value;
                continue;
            }

            $segments = explode('.', $value);

            $value = array_pop($segments);

            $nested = (array) static::get($array, implode('.', $segments));

            if ($nested && in_array($value, $nested)) {
                $result[] = $value;
            }
        }

        return count($result) === count($values);
    }

    /**
     * Check if the any value exist in an array using "dot" notation.
     *
     * @param  array $array
     * @param  string|array  $values
     * @return bool
     */
    public static function containsAny(array $array, $values)
    {
        $result = [];
        
        $values = is_array($values) ? $values : [$values];

        foreach ($values as $value) {

            if (in_array($value, $array)) {
                return true;
            }

            $segments = explode('.', $value);

            $value = array_pop($segments);

            $nested = (array) static::get($array, implode('.', $segments));

            if ($nested && in_array($value, $nested)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compare two nested arrays side by side
     * @param  array $array1
     * @param  array $array2
     * @param  array $path
     * @return array
     */
    public static function compare($array1, $array2, $path = [])
    {
        $differences = [];

        foreach ($array1 as $key => $value1) {
            // Check if the key exists in the second array
            if (!array_key_exists($key, $array2)) {
                $differences[implode('.', array_merge($path, [$key]))] = [
                    'array_1' => $value1,
                    'array_2' => null,
                ];
            } else {
                // If the value is an array, recursively compare
                if (is_array($value1) && is_array($array2[$key])) {
                    $differences = array_merge($differences, static::compare(
                        $value1, $array2[$key], array_merge($path, [$key])
                    ));
                } else {
                    // Compare values
                    if ($value1 !== $array2[$key]) {
                        $differences[implode('.', array_merge($path, [$key]))] = [
                            'array_1' => $value1,
                            'array_2' => $array2[$key],
                        ];
                    }
                }
            }
        }

        // Check for keys in the second array that are not in the first array
        foreach ($array2 as $key => $value2) {
            if (!array_key_exists($key, $array1)) {
                $differences[implode('.', array_merge($path, [$key]))] = [
                    'array_1' => null,
                    'array_2' => $value2,
                ];
            }
        }

        return $differences;
    }

    /**
     * Merge the items from the first array into the
     * second array if the second array is missing it.
     * 
     * @param  array &$array1
     * @param  array &$array2
     * @return array         
     */
    public static function mergeMissing(&$array1, &$array2)
    {
        foreach ($array1 as $key => $value1) {
            // If the key exists in the second array
            if (array_key_exists($key, $array2)) {
                // If the value is an array, recursively add missing items
                if (is_array($value1) && is_array($array2[$key])) {
                    static::mergeMissing($value1, $array2[$key]);
                }
            } else {
                // If the key doesn't exist in the second array,
                // then add it with the corresponding value
                $array2[$key] = $value1;
            }
        }

        return $array2;
    }

    /**
     * Recursively merge the given array with defaults.
     * Overwrite $array with $defaults if only $array
     * contains null or empty values or doesn't exist.
     *
     * @param  array  $array
     * @param  array  $defaults
     * @return array
     */
    public static function mergeMissingValues(array $array, array $defaults)
    {
        $merged = array_merge($defaults, $array);

        foreach ($merged as $key => $value) {
            if (
                is_array($value) &&
                isset($defaults[$key]) &&
                is_array($defaults[$key])
            ) {
                // Recursively merge arrays
                $merged[$key] = static::mergeMissingValues(
                    $value, $defaults[$key]
                );
            } elseif (
                isset($defaults[$key]) &&
                (is_null($value) || $value === '')
            ) {
                // Replace null or empty values
                $merged[$key] = $defaults[$key];
            }
        }

        return $merged;
    }

    /**
     * Return matching items from array (similar to mysql's %LIKE%)
     * 
     * @param  string $pattern (plain string or regex)
     * @param  array $array
     * @return array|false
     */
    public static function like($array, $pattern)
    {
        if (!preg_match('/^([\/#~]).*\1$/', $pattern)) {
            $pattern =  '~'. preg_quote($pattern, '~') . '~i';
        }

        return preg_grep($pattern, $array);
    }

    /**
     * Return non-matching items from array (similar to mysql's NOT %LIKE%)
     * 
     * @param  string $pattern (plain string or regex)
     * @param  array $array
     * @return array|false
     */
    public static function notLike($array, $pattern)
    {
        if (!preg_match('/^([\/#~]).*\1$/', $pattern)) {
            $pattern =  '~'. preg_quote($pattern, '~') . '~i';
        }

        return preg_grep($pattern, $array, PREG_GREP_INVERT);
    }

    /**
     * Return matching starting of items from array (similar to mysql's %LIKE)
     * 
     * @param  string $pattern (plain string or regex)
     * @param  array $array
     * @return array|false
     */
    public static function startsLike($array, $pattern)
    {
        if (!preg_match('/^([\/#~]).*\1$/', $pattern)) {
            $pattern =  '~^'. preg_quote($pattern, '~') . '~i';
        }

        return preg_grep($pattern, $array);
    }

    /**
     * Return non-matching starting of items from array (similar to mysql's NOT %LIKE)
     * 
     * @param  string $pattern (plain string or regex)
     * @param  array $array
     * @return array|false
     */
    public static function DoesNotStartLike($array, $pattern)
    {
        if (!preg_match('/^([\/#~]).*\1$/', $pattern)) {
            $pattern = '~^(?!' . preg_quote($pattern, '~') . ')~i';
        }

        return preg_grep($pattern, $array);
    }

    /**
     * Return matching ending of items from array (similar to mysql's LIKE%)
     * 
     * @param  string $pattern (plain string or regex)
     * @param  array $array
     * @return array|false
     */
    public static function endsLike($array, $pattern)
    {
        if (!preg_match('/^([\/#~]).*\1$/', $pattern)) {
            $pattern =  '~'. preg_quote($pattern, '~') . '$~i';
        }

        return preg_grep($pattern, $array);
    }

    /**
     * Return non-matching ending of items from array (similar to mysql's NOT LIKE%)
     * 
     * @param  string $pattern (plain string or regex)
     * @param  array $array
     * @return array|false
     */
    public static function DoesNotEndLike($array, $pattern)
    {
        if (!preg_match('/^([\/#~]).*\1$/', $pattern)) {
            $pattern =  '~'. preg_quote($pattern, '~') . '$~i';
        }

        return preg_grep($pattern, $array, PREG_GREP_INVERT);
    }

    /**
     * Return matching items from array by keys
     * 
     * @param  string $pattern (plain string or regex)
     * @param  array $array
     * @return array|false
     */
    public static function keysLike($array, $pattern)
    {
        if (!preg_match('/^([\/#~]).*\1$/', $pattern)) {
            $pattern =  '~'. preg_quote($pattern, '~') . '~i';
        }

        $values = [];
        
        $keys = preg_grep($pattern, array_keys($array));
        
        foreach ($keys as $key) {
            $values[$key] = $array[$key];
        }
        
        return $values;
    }

    /**
     * Return non-matching items from array by keys
     * 
     * @param  string $pattern (plain string or regex)
     * @param  array $array
     * @return array|false
     */
    public static function keysNotLike($array, $pattern)
    {
        if (!preg_match('/^([\/#~]).*\1$/', $pattern)) {
            $pattern =  '~'. preg_quote($pattern, '~') . '~i';
        }

        $values = [];
        
        $keys = preg_grep($pattern, array_keys($array), 1);
        
        foreach ($keys as $key) {
            $values[$key] = $array[$key];
        }
        
        return $values;
    }

    /**
     * Return matching starting of items from array by keys
     * 
     * @param  string $pattern (plain string or regex)
     * @param  array $array
     * @return array|false
     */
    public static function keysStartLike($array, $pattern)
    {
        if (!preg_match('/^([\/#~]).*\1$/', $pattern)) {
            $pattern =  '~^'. preg_quote($pattern, '~') . '~i';
        }

        $values = [];
        
        $keys = preg_grep($pattern, array_keys($array));
        
        foreach ($keys as $key) {
            $values[$key] = $array[$key];
        }
        
        return $values;
    }

    /**
     * Return non-matching starting of items from array by keys
     * 
     * @param  string $pattern (plain string or regex)
     * @param  array $array
     * @return array|false
     */
    public static function keysDoesNotStartLike($array, $pattern)
    {
        if (!preg_match('/^([\/#~]).*\1$/', $pattern)) {
            $pattern = '~^(?!' . preg_quote($pattern, '~') . ')~i';
        }

        $values = [];
        
        $keys = preg_grep($pattern, array_keys($array));
        
        foreach ($keys as $key) {
            $values[$key] = $array[$key];
        }
        
        return $values;
    }

    /**
     * Return matching ending of items from array by keys
     * 
     * @param  string $pattern (plain string or regex)
     * @param  array $array
     * @return array|false
     */
    public static function keysEndLike($array, $pattern)
    {
        if (!preg_match('/^([\/#~]).*\1$/', $pattern)) {
            $pattern =  '~'. preg_quote($pattern, '~') . '$~i';
        }

        $values = [];
        
        $keys = preg_grep($pattern, array_keys($array));
        
        foreach ($keys as $key) {
            $values[$key] = $array[$key];
        }
        
        return $values;
    }

    /**
     * Return non-matching ending of items from array by keys
     * 
     * @param  string $pattern (plain string or regex)
     * @param  array $array
     * @return array|false
     */
    public static function keysDoesNotEndLike($array, $pattern)
    {
        if (!preg_match('/^([\/#~]).*\1$/', $pattern)) {
            $pattern =  '~'. preg_quote($pattern, '~') . '$~i';
        }

        $values = [];
        
        $keys = preg_grep($pattern, array_keys($array), PREG_GREP_INVERT);
        
        foreach ($keys as $key) {
            $values[$key] = $array[$key];
        }
        
        return $values;
    }

    /**
     * Insert a new item in the array at the given position.
     * 
     * @param  array $array
     * @param  int $pos
     * @param  mixed $newItem
     * @return array
     */
    public static function insertAt($array, $pos, $newItem)
    {
        if (!isset($array[$pos])) {
            $array[] = $newItem;
        } else {
            $array = array_splice($array, $pos, 0, $newItem);
        }
        
        return $array;
    }

    /**
     * Inserts an item before the specified key in the given array. If the
     * key is not found, inserts the item at the beginning of the array.
     *
     * @param  array $array
     * @param  mixed $key
     * @param  mixed $newKey
     * @param  mixed $newValue
     * @return array $newArray
     */
    public static function insertBefore($array, $key, $newKey, $newValue)
    {
        $newArray = [];
        $keyFound = false;

        foreach ($array as $k => $v) {
            if ($k === $key) {
                $newArray[$newKey] = $newValue;
                $keyFound = true;
            }
            $newArray[$k] = $v;
        }

        if (!$keyFound) {
            $newArray = [$newKey => $newValue] + $newArray;
        }

        return $newArray;
    }

    /**
     * Inserts an item after the specified key in the given array. If the
     * key is not found, inserts the item at the end of the array.
     *
     * @param  array $array
     * @param  mixed $key
     * @param  mixed $newKey
     * @param  mixed $newValue
     * @return array $newArray
     */
    public static function insertAfter($array, $key, $newKey, $newValue)
    {
        $newArray = [];
        $keyFound = false;

        foreach ($array as $k => $v) {
            $newArray[$k] = $v;
            if ($k === $key) {
                $newArray[$newKey] = $newValue;
                $keyFound = true;
            }
        }

        if (!$keyFound) {
            $newArray[$newKey] = $newValue;
        }

        return $newArray;
    }

    /**
     * Tests whether at least one element in the array passes
     * the test implemented by the provided callback.
     * 
     * @param  array $array
     * @param  callable $callback
     * @return bool
     */
    public static function some($array, callable $callback)
    {
        foreach ($array as $k => $v) {
            if ($callback($v, $k, $array)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tests whether all elements in the array pass the
     * test implemented by the provided callback.
     *
     * @param  array $array
     * @param  callable $callback
     * @return bool
     */
    public static function every($array, callable $callback)
    {
        foreach ($array as $k => $v) {
            if (!$callback($v, $k, $array)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Finds the first element in the array that satisfies the
     * condition implemented by the callback function.
     *
     * @param array $array
     * @param callable $callback
     * @return mixed
     */
    public static function find($array, callable $callback, $findKey = false)
    {
        foreach ($array as $k => $v) {
            if ($callback($v, $k, $array)) {
                return $findKey ? $k : $v;
            }
        }

        return null;
    }

    /**
     * Finds the first key in the array that satisfies the
     * condition implemented by the callback function.
     *
     * @param array $array
     * @param callable $callback
     * @return mixed
     */
    public static function findKey($array, callable $callback)
    {
        return static::find($array, $callback, true);
    }

    /**
     * Find similar words in an array.
     * 
     * @param  string  $needle
     * @param  array   $haystack
     * @param  integer $accuracy
     * @return string|null         
     */
    public static function findSimilar($needle, array $haystack, $accuracy = 60)
    {
        $matches = [];

        foreach ($haystack as $item) {
            if (Str::isSimilar($needle, $item, $accuracy)) {
                $matches[] = $item;
            }
        }

        return $matches ? $matches[0] : null;
    }

    /**
     * Pass the items through a series of callbacks.
     * 
     * @param  array   $items
     * @param  array   $callbacks
     * @param  integer $mode
     * @return array
     */
    public static function passThrough(array $items, array $callbacks, $mode = 0)
    {
        foreach ($items as $key => &$item) {
            reset($callbacks);
            foreach ($callbacks as $callback) {
                switch ($mode) {
                    case 1:
                        $items[$key] = $callback($key);
                        break;
                    case 2:
                        $items[$key] = $callback($key, $item ?? '');
                        break;
                    default:
                        $items[$key] = $callback($item ?? '');
                }
            }
        }

        return $items;
    }
}

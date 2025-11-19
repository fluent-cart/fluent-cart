<?php

namespace FluentCart\Framework\Support;

use Closure;
use Exception;
use Throwable;
use InvalidArgumentException;
use FluentCart\Framework\Foundation\App;
use FluentCart\Framework\Support\HigherOrderTapProxy;

class Helper
{
    /**
     * Create a collection from the given value.
     *
     * @param  mixed $value
     * @return \FluentCart\Framework\Support\Collection
     */
    public static function collect($value = null)
    {
        return new Collection($value);
    }

    /**
     * Fill in data where it's missing.
     *
     * @param  mixed        $target
     * @param  string|array $key
     * @param  mixed        $value
     * @return mixed
     */
    public function dataFill(&$target, $key, $value)
    {
        return static::dataSet($target, $key, $value, false);
    }

    /**
     * Get an item from an array or object using "dot" notation.
     *
     * @param  mixed                 $target
     * @param  string|array|int|null $key
     * @param  mixed                 $default
     * @return mixed
     */
    public static function dataGet($target, $key, $default = null)
    {
        if (is_null($key)) {
            return $target;
        }

        $key = is_array($key) ? $key : explode('.', $key);

        while (($segment = array_shift($key)) !== null) {
            if ('*' === $segment) {
                if ($target instanceof Collection) {
                    $target = $target->all();
                } elseif (!is_array($target)) {
                    return static::value($default);
                }

                $result = Arr::pluck($target, $key);

                return in_array('*', $key) ? Arr::collapse($result) : $result;
            }

            if (Arr::accessible($target) && Arr::exists($target, $segment)) {
                $target = $target[$segment];
            } elseif (is_object($target) && isset($target->{$segment})) {
                $target = $target->{$segment};
            } else {
                return static::value($default);
            }
        }

        return $target;
    }

    /**
     * Set an item on an array or object using dot notation.
     *
     * @param  mixed        $target
     * @param  string|array $key
     * @param  mixed        $value
     * @param  bool         $overwrite
     * @return mixed
     */
    public static function dataSet(&$target, $key, $value, $overwrite = true)
    {
        $segments = is_array($key) ? $key : explode('.', $key);

        if (($segment = array_shift($segments)) === '*') {
            if (!Arr::accessible($target)) {
                $target = [];
            }

            if ($segments) {
                foreach ($target as &$inner) {
                    static::dataSet($inner, $segments, $value, $overwrite);
                }
            } elseif ($overwrite) {
                foreach ($target as &$inner) {
                    $inner = $value;
                }
            }
        } elseif (Arr::accessible($target)) {
            if ($segments) {
                if (!Arr::exists($target, $segment)) {
                    $target[$segment] = [];
                }

                static::dataSet($target[$segment], $segments, $value, $overwrite);
            } elseif ($overwrite || !Arr::exists($target, $segment)) {
                $target[$segment] = $value;
            }
        } elseif (is_object($target)) {
            if ($segments) {
                if (!isset($target->{$segment})) {
                    $target->{$segment} = [];
                }

                static::dataSet($target->{$segment}, $segments, $value, $overwrite);
            } elseif ($overwrite || !isset($target->{$segment})) {
                $target->{$segment} = $value;
            }
        } else {
            $target = [];

            if ($segments) {
                static::dataSet($target[$segment], $segments, $value, $overwrite);
            } elseif ($overwrite) {
                $target[$segment] = $value;
            }
        }

        return $target;
    }

    /**
     * Get the first element of an array. Useful for method chaining.
     *
     * @param  array $array
     * @return mixed
     */
    public static function head($array)
    {
        return reset($array);
    }

    /**
     * Get the last element from an array.
     *
     * @param  array $array
     * @return mixed
     */
    public static function last($array)
    {
        return end($array);
    }

    /**
     * Return the default value of the given value.
     *
     * @param  mixed $value
     * @return mixed
     */
    public static function value($value, ...$args)
    {
        return $value instanceof Closure ? $value(...$args) : $value;
    }

    /**
     * Call the given Closure with the given value then return the value.
     *
     * @param  mixed  $value
     * @param  callable|null  $callback
     * @return mixed
     */
    public static function tap($value, $callback = null)
    {
        if (is_null($callback)) {
            return new HigherOrderTapProxy($value);
        }

        $callback($value);

        return $value;
    }

    /**
     * Dispatch an event and call its listeners.
     *
     * @param string|object $event Event name or object
     * @param mixed ...$payload Optional arguments passed to listeners
     * @return mixed Result of the event dispatch
     */
    public static function event($event, ...$payload)
    {
        return App::make('events')->dispatch($event, ...$payload);
    }

    /**
     * Retry an operation a given number of times.
     *
     * @param  int  $times
     * @param  callable  $callback
     * @param  int|\Closure  $sleepMilliseconds
     * @param  callable|null  $when
     * @return mixed
     *
     * @throws \Exception
     */
    public static function retry(
        $times,
        callable $callback,
        $sleepMilliseconds = 0,
        $when = null
    )
    {
        $attempts = 0;

        beginning:
        $attempts++;
        $times--;

        try {
            return $callback($attempts);
        } catch (Exception $e) {
            if ($times < 1 || ($when && ! $when($e))) {
                throw $e;
            }

            if ($sleepMilliseconds) {
                usleep(static::value($sleepMilliseconds, $attempts) * 1000);
            }

            goto beginning;
        }
    }

    /**
     * Retrieve header status text by http code
     * @param  int $code HTTP status code
     * @return string
     */
    public static function getHeaderStatusText($code)
    {
        return get_status_header_desc($code);
    }

    /**
     * Retrieve the writable temp dir path
     * 
     * @return string
     */
    public static function getTempDirPath()
    {
        return get_temp_dir();
    }

    /**
     * Retrieves the list of allowed mime types and file extensions.
     *
     * @return string[] Mime types keyed by the file extension regex.
     */
    public static function getAllowedMimeTypes()
    {
        return get_allowed_mime_types();
    }

    /**
     * Get the content of a JSON file as array.
     * 
     * @param  string $filename
     * @param  array  $options
     * @return array json decoded content of the file
     */
    public static function getJsonFile($filename, $options = [])
    {
        return wp_json_file_decode($filename, $options);
    }

    /**
     * Gets the size of a directory.
     *
     * @param string $dirPath Full path of a directory. 
     * @return int|false|null Size in bytes or false. Null if timeout.
     */
    public static function getSizeOf($dirPath)
    {
        return recurse_dirsize($dirPath);
    }

    /**
     * Creates an \stdClass from an array recursively.
     *
     * @param  array $array
     * @return \stdClass
     */
    public static function objectCreate(array $array)
    {
        $object = new \stdClass;

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $object->{$key} = static::objectCreate($value);
            } else {
                $object->{$key} = $value;
            }
        }

        return $object;
    }

    /**
     * Transforms an \stdClass to array
     * @param  \stdClass $object
     * @return array
     */
    public static function objectToArray(\stdClass $object)
    {
        return json_decode(json_encode($object), true);
    }

    /**
     * Get an item from an object using "dot" notation.
     *
     * @param  object  $object
     * @param  string|null  $key
     * @param  mixed  $default
     * @return mixed
     */
    public static function objectGet($object, $key, $default = null)
    {
        if (is_null($key) || trim($key) === '') {
            return $object;
        }

        foreach (explode('.', $key) as $segment) {
            if (! is_object($object) || ! isset($object->{$segment})) {
                return static::value($default);
            }

            $object = $object->{$segment};
        }

        return $object;
    }

    /**
     * Replace a given pattern with each value in the array in sequentially.
     *
     * @param  string  $pattern
     * @param  array  $replacements
     * @param  string  $subject
     * @return string
     */
    public static function pregReplaceArray($pattern, array $replacements, $subject)
    {
        return preg_replace_callback($pattern, function () use (&$replacements) {
            foreach ($replacements as $value) {
                return array_shift($replacements);
            }
        }, $subject);
    }

    /**
     * Executes a callback and returns the captured output as a string.
     * 
     * @param callable $callback
     * @return string
     */
    public static function capture(callable $callback)
    {
        ob_start(null);
        try {
            $callback();
            return ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }

    /**
     * Executes an action and returns the captured output as a string.
     *
     * @param string $action
     * @param array $params the data to passed in action
     * @return string
     * @throws Throwable
     */
    public static function captureAction($action, ...$params)
    {
        ob_start(null);
        try {
            do_action_ref_array($action, $params);
            return ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }

    /**
     * Compares two values in the same way that PHP does.
     * 
     * @param  mixed  $left
     * @param  string $operator
     * @param  mixed  $right
     * @return bool
     */
    public static function compare($left, $operator, $right)
    {
        switch ($operator) {
            case '>':
                return $left > $right;
            case '>=':
                return $left >= $right;
            case '<':
                return $left < $right;
            case '<=':
                return $left <= $right;
            case '=':
            case '==':
                return $left == $right;
            case '===':
                return $left === $right;
            case '!=':
            case '<>':
                return $left != $right;
            case '!==':
                return $left !== $right;
            default:
                throw new InvalidArgumentException("Unknown operator '$operator'");
        }
    }
}

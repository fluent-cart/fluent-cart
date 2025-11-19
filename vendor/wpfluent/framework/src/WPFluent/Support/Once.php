<?php

namespace FluentCart\Framework\Support;

class Once
{
    protected $trace = null;

    protected $zeroStack = null;

    public function __construct(array $trace)
    {
        $this->trace = $trace[1];

        $this->zeroStack = $trace[0];
    }

    public function getArguments()
    {
        return $this->trace['args'];
    }

    public function getFunctionName()
    {
        return $this->trace['function'];
    }

    public function getObjectName()
    {
        return $this->trace['class'] ?? null;
    }

    public function getObject()
    {
        if ($this->globalFunction()) {
            return $this->zeroStack['file'];
        }

        return $this->staticCall() ? $this->trace['class'] : $this->trace['object'];
    }

    public function getHash($strict)
    {
        $normalizedArguments = array_map(function ($argument) {
            return is_object($argument) ? spl_object_hash($argument) : $argument;
        }, $this->getArguments());

        $object = $this->getObject();

        $prefix = $this->getObjectName() . $this->getFunctionName();
        
        if ($strict) {
            $prefix .= is_object($object) ? spl_object_id($object) : $object;
        }
        
        if (str_contains($prefix, '{closure')) {
            $prefix = $this->zeroStack['line'];
        }

        return md5($prefix.serialize($normalizedArguments));
    }

    protected function staticCall()
    {
        return $this->trace['type'] === '::';
    }

    protected function globalFunction()
    {
        return !isset($this->trace['type']);
    }

    public static function call(callable $callback, $strict = false)
    {
        $backtrace = new static(debug_backtrace(
            DEBUG_BACKTRACE_PROVIDE_OBJECT, 2
        ));

        $hash = $backtrace->getHash($strict);

        if (!$result = wp_cache_get($hash)) {
            wp_cache_set(
                $hash, call_user_func_array(
                    $callback, $backtrace->getArguments()
                )
            );
        }

        return wp_cache_get($hash);
    }
}

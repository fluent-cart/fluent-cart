<?php

namespace FluentCartPro\App\Core;

trait AppTrait
{
	protected static $instance = null;

    public static function setInstance($app)
    {
        static::$instance = $app;
    }

    public static function getInstance($module = null)
    {
        if ($module && isset(static::$instance[$module])) {
            return static::$instance[$module];
        }

        return static::$instance;
    }

    public static function make($module = null)
    {
        return static::getInstance($module);
    }

    public static function __callStatic($method, $params)
    {
        return static::getInstance($method);
    }
}

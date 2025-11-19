<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php

/**
 * Enable Query Log
 */
if (!function_exists('fluentcart_eql')) {
    function fluentcart_eql()
    {
        defined('SAVEQUERIES') || define('SAVEQUERIES', true);
    }
}

/**
 * Get Query Log
 */
if (!function_exists('fluentcart_gql')) {
    function fluentcart_gql()
    {
        $result = [];
        foreach ((array)$GLOBALS['wpdb']->queries as $key => $query) {
            $result[++$key] = array_combine([
                'query', 'execution_time'
            ], array_slice($query, 0, 2));
        }
        return $result;
    }
}

if (!function_exists('dd')) {

    function dd()
    {

        foreach (func_get_args() as $arg) {
            echo "<pre>";
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
            print_r($arg);
            echo "</pre>";
        }
        die();
    }
}

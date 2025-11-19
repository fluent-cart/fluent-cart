<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php

if (!function_exists('dd')) {
    /**
     * Dump & Die
     */
    function dd(/*args*/)
    {
        ob_start();
        foreach (func_get_args() as $arg) {
            echo "<pre>";
            print_r($arg);
            echo "</pre>";
        }
        $ret = ob_get_clean();

        if (str_contains(strtolower(php_sapi_name()), 'cli')) {
            echo PHP_EOL.PHP_EOL . strip_tags($ret) . PHP_EOL.PHP_EOL;
        } else {
            echo strip_tags($ret);
        }
        die;
    }
}

if (!function_exists('ddd')) {
    /**
     * Dump & Don't Die
     */
    function ddd(/*args*/)
    {
        ob_start();
        foreach (func_get_args() as $arg) {
            echo "<pre>";
            print_r($arg);
            echo "</pre>";
        }
        $ret = ob_get_clean();

        if (str_contains(strtolower(php_sapi_name()), 'cli')) {
            echo PHP_EOL.PHP_EOL . strip_tags($ret) . PHP_EOL.PHP_EOL;
        } else {
            echo strip_tags($ret);
        }
    }
}

if (!function_exists('wpf_log')) {
    function wpf_log($m) {
        $m = is_array($m) || is_object($m) ? json_encode($m, JSON_PRETTY_PRINT) : $m;
        $format = get_option('date_format') . ' ' . get_option('time_format');
    }
}

if (!function_exists('wpf_eql')) {
    /**
     * Enable Query Log
     */
    function wpf_eql() {
        $lastIndex = 0;
        defined('SAVEQUERIES') || define('SAVEQUERIES', true);
        if ($queries = (array) $GLOBALS['wpdb']->queries) {
            $lastIndex = count($queries);
        }
        return $lastIndex;
    }
}

if (!function_exists('wpf_gql')) {
    /**
     * Get Query Log
     */
    function wpf_gql($start = 0) {
        $result = [];
        $queries = (array) $GLOBALS['wpdb']->queries;
        $queries = $start > 0 ? array_slice($queries, $start) : $queries;
        foreach ($queries as $key => $query) {
            $result[++$key] = array_combine([
                'query', 'execution_time'
            ], array_slice($query, 0, 2));
        }
        return $result;
    }
}

if (!function_exists('wpf_wql')) {
    /**
     * Write Query Log
     */
    function wpf_wql($start) {
        wpf_log(wpf_gql($start));
    }
}

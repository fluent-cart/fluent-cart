<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php

//phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
set_error_handler(function($errno, $msg, $fn, $ln) use ($file) {

    //phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,  WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting
    if (!(error_reporting() & $errno)) {
        return false;
    }
    
    $levels = [
        E_ALL               => "E_ALL",
        E_ERROR             => "E_ERROR",
        E_WARNING           => "E_WARNING",
        E_PARSE             => "E_PARSE",
        E_NOTICE            => "E_NOTICE",
        E_CORE_ERROR        => "E_CORE_ERROR",
        E_CORE_WARNING      => "E_CORE_WARNING",
        E_COMPILE_ERROR     => "E_COMPILE_ERROR",
        E_COMPILE_WARNING   => "E_COMPILE_WARNING",
        E_USER_ERROR        => "E_USER_ERROR",
        E_USER_WARNING      => "E_USER_WARNING",
        E_USER_NOTICE       => "E_USER_NOTICE",
        E_STRICT            => "E_STRICT",
        E_RECOVERABLE_ERROR => "E_RECOVERABLE_ERROR",
        E_DEPRECATED        => "E_DEPRECATED",
        E_USER_DEPRECATED   => "E_USER_DEPRECATED"
    ];

    $levelName = $levels[$errno] ?? "UNKNOWN ERROR LEVEL";

    // Improved debugging information
    if (strpos($fn, dirname($file) . '/') !== false) {
        $debugInfo = [
            'Error Level' => $levelName,
            'Message' => $msg,
            'File' => $fn,
            'Line' => $ln,
            //phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
            'Backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)
        ];

        throw new ErrorException(sprintf(
            /* translators: %1$s is the error level, %2$s is the error message, %3$s is the file name, %4$s is the line number, %5$s is the debug info */
            '%s - %s in %s at line %s. Debug Info: %s',
            esc_html($levelName),
            esc_html($msg),
            esc_html($fn),
            absint($ln),
            //phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
            esc_html(print_r($debugInfo, true))
        ), 500);
    }

    return false;
});

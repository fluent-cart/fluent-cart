<?php
// Unit suite bootstrap. Loads ONLY the plugin's Composer autoloader — no
// WordPress, no database. Only pure-logic code (no WP function calls, no
// models) may be exercised in this suite; anything else belongs in Integration.
//
// boot/globals.php is registered in the autoloader's "files" section and exits
// unless ABSPATH is defined. It only declares functions, so a placeholder
// ABSPATH is safe here.
defined('ABSPATH') || define('ABSPATH', sys_get_temp_dir() . '/');

require dirname(__DIR__, 3) . '/vendor/autoload.php';

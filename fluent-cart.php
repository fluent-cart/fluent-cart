<?php

defined('ABSPATH') or die;

/*
Plugin Name: FluentCart
Description: FluentCart WordPress Plugin
Version: 1.3.0
Author: FluentCart Team
Author URI: https://fluentcart.com/about-us
Plugin URI: https://fluentcart.com
License: GPLv2 or later
Text Domain: fluent-cart
Domain Path: /language
*/
 
if (!defined('FLUENTCART_PLUGIN_PATH')) {
    define('FLUENTCART_VERSION', '1.3.0');
    define('FLUENTCART_DB_VERSION', '1.0.31');
    define('FLUENTCART_PLUGIN_PATH', plugin_dir_path(__FILE__));
    define('FLUENTCART_URL', plugin_dir_url(__FILE__));
    define('FLUENTCART_PLUGIN_FILE_PATH', __FILE__);
    define('FLUENTCART_UPLOAD_DIR', 'fluent_cart');
    define('FLUENT_CART_DIR_FILE', __FILE__);
    define('FLUENTCART_MIN_PRO_VERSION', '1.3.0');
}

register_activation_hook(__FILE__, function () {
    update_option('fluent_cart_do_activation_redirect', true);
});

return (function ($_) {
    return $_(__FILE__);
})(
    require __DIR__ . '/boot/app.php',
    require __DIR__ . '/vendor/autoload.php',
    //require __DIR__ . '/dev/build-scoped/vendor/scoper-autoload.php'
);

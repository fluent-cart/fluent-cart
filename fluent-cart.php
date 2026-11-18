<?php

defined('ABSPATH') or die;

/*
Plugin Name: FluentCart
Description: FluentCart WordPress Plugin
Version: 2.3.2
Author: FluentCart Team
Author URI: https://fluentcart.com/about-us
Plugin URI: https://fluentcart.com
License: GPLv2 or later
Text Domain: fluent-cart
Domain Path: /language
*/

if (!defined('FLUENTCART_PLUGIN_PATH')) {
    define('FLUENTCART_VERSION', '2.3.2');
    define('FLUENTCART_DB_VERSION', '1.0.59');
    define('FLUENTCART_PLUGIN_PATH', plugin_dir_path(__FILE__));
    define('FLUENTCART_URL', plugin_dir_url(__FILE__));
    define('FLUENTCART_PLUGIN_FILE_PATH', __FILE__);
    define('FLUENTCART_UPLOAD_DIR', 'fluent_cart');
    define('FLUENT_CART_DIR_FILE', __FILE__);
    define('FLUENTCART_MIN_PRO_VERSION', '2.3.2');
    // Admin module contract for add-on bundles: the @fluent-cart/* import map
    // and the exports of resources/admin/shared/module-ui.js. Bump only on a
    // breaking change to that surface. 2: Pro serves the Pro report sections and their routes.
    // 3: the Customer Rights add-on serves the withdrawal requests screen (core no longer builds it),
    //    carrier section render sites (carrier_<slug>_<section key> admin components), the
    //    layout components (Page, Section, SettingRow, SortableList, ...) in @fluent-cart/ui, and
    //    the fct-* components (fct-button, fct-input, ...) registered on the admin and portal apps.
    define('FLUENTCART_MODULE_API', 3);
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

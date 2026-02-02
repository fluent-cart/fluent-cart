<?php
defined('ABSPATH') or die;
/**
 * Plugin Name: FluentCart Pro
 * Description: The Pro version of FluentCart - A New Era of Commerce with WordPress
 * Version: 1.3.9
 * Author: FluentCart Team
 * Author URI: https://fluentcart.com
 * Plugin URI: https://fluentcart.com
 * License: GPLv2 or later
 * Text Domain: fluent-cart-pro
 * Domain Path: /language
 */

if (!defined('FLUENTCART_PRO_PLUGIN_VERSION')) {
    define('FLUENTCART_PRO_PLUGIN_VERSION', '1.3.9');
    define('FLUENTCART_PRO_PLUGIN_DIR', plugin_dir_path(__FILE__));
    define('FLUENTCART_PRO_PLUGIN_URL', plugin_dir_url(__FILE__));
    define('FLUENTCART_PRO_PLUGIN_FILE_PATH', __FILE__);
    define('FLUENTCART_MIN_CORE_VERSION', '1.3.9');
}

if (!defined('FLUENT_CART_PRO_DEV_MODE')) {
    define('FLUENT_CART_PRO_DEV_MODE', 'no');
}

require __DIR__ . '/vendor/autoload.php';

call_user_func(function ($bootstrap) {
    $bootstrap(__FILE__);
}, require(__DIR__ . '/boot/app.php'));

register_activation_hook(__FILE__, function ($network_wide = false) {

    if (defined('FLUENTCART_VERSION')) {
        if (\FluentCart\Api\ModuleSettings::isActive('order_bump')) {
            (new \FluentCartPro\App\Modules\Promotional\PromotionalInit())->maybeMigrateDB();
        }

        if (\FluentCart\Api\ModuleSettings::isActive('license')) {
            (new \FluentCartPro\App\Modules\Licensing\Database\DBMigrator())->migrate();
        }
    }

});

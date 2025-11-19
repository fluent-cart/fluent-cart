<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php

/**
 * Add only the plugin specific bindings here.
 *
 * @var $app \FluentCart\Framework\Foundation\Application
 */

use FluentCart\App\App;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use \FluentCart\App\Services\Localization\LocalizationManager;

$app->singleton(\FluentCart\Api\StoreSettings::class);
$app->alias(\FluentCart\Api\StoreSettings::class, 'store_settings');

// Payment Gateway Manager binding
$app->singleton(GatewayManager::class, function($app) {
    return GatewayManager::getInstance();
});
$app->alias(GatewayManager::class, 'gateway');


// Localization Manager binding
$app->singleton(LocalizationManager::class, function($app) {
    return LocalizationManager::getInstance();
});
$app->alias(LocalizationManager::class, 'localization');


//$app->singleton(Store::class, function($app) {
//    $config = $app->make('config')->get('session');
//    return new Store('fluentcart_session', new DatabaseSessionHandler(
//        $app->make('db'),
//        $config['table'],
//        $config['lifetime'],
//        $app
//    ));
//});
//
//$app->alias(Store::class, 'session');

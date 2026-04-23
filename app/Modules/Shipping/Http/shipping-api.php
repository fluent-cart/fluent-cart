<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var $router Router
 */
use FluentCart\App\Modules\Shipping\Http\Controllers\ShippingZoneController;
use FluentCart\App\Modules\Shipping\Http\Controllers\ShippingClassController;
use FluentCart\App\Modules\Shipping\Http\Controllers\ShippingMethodController;
use FluentCart\Framework\Http\Router;

$router->prefix('shipping')->withPolicy('StoreSensitivePolicy')->group(function (Router $router) {
    $router->get('/zones', [ShippingZoneController::class, 'index']);
    $router->post('/zones', [ShippingZoneController::class, 'store']);
    $router->get('/zones/{id}', [ShippingZoneController::class, 'show']);
    $router->put('/zones/{id}', [ShippingZoneController::class, 'update']);
    $router->delete('/zones/{id}', [ShippingZoneController::class, 'destroy']);
    $router->post('/zones/update-order', [ShippingZoneController::class, 'updateOrder']);

    $router->get('/zone/states', [ShippingZoneController::class, 'getZoneStates']);
    $router->get('/zone/countries', [ShippingZoneController::class, 'getCountriesByContinent']);

    // Methods
    $router->post('/methods', [ShippingMethodController::class, 'store']);
    $router->put('/methods', [ShippingMethodController::class, 'update']);
    $router->delete('/methods/{method_id}', [ShippingMethodController::class, 'destroy']);
    
    // Shipping Classes
    $router->get('/classes', [ShippingClassController::class, 'index']);
    $router->post('/classes', [ShippingClassController::class, 'store']);
    $router->get('/classes/{id}', [ShippingClassController::class, 'show']);
    $router->put('/classes/{id}', [ShippingClassController::class, 'update']);
    $router->delete('/classes/{id}', [ShippingClassController::class, 'destroy']);
    $router->get('/classes/{id}/profile', [ShippingClassController::class, 'getProfile']);

    // Packages (stored in fct_meta, not a DB table)
    $router->get('/packages', [ShippingClassController::class, 'getPackages']);
    $router->post('/packages', [ShippingClassController::class, 'savePackages']);
});

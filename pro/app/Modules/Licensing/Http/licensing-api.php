<?php

use FluentCart\Framework\Http\Router;
use FluentCartPro\App\Modules\Licensing\Http\Controllers\LicenseController;
use FluentCartPro\App\Modules\Licensing\Http\Controllers\ProductLicenseController;


/**
 * @var $router Router
 */

$router->prefix('licensing')->withPolicy('FluentCart\App\Http\Policies\LicensePolicy')->group(function ($router) {
    $router->get('/licenses', [LicenseController::class, 'index'])->meta([
        'permissions' => 'licenses/view'
    ]);
    $router->get('/licenses/customer/{id}', [LicenseController::class, 'getCustomerLicenses'])->int('id')
        ->meta([
            'permissions' => 'licenses/view'
        ]);

    $router->get('/licenses/{id}', [LicenseController::class, 'getLicense'])->int('id')->meta([
        'permissions' => 'licenses/view'
    ]);

    $router->post('/licenses/{id}/regenerate-key', [LicenseController::class, 'regenerateLicenseKey'])->int('id')
        ->meta([
            'permissions' => 'licenses/manage'
        ]);
    $router->post('/licenses/{id}/extend-validity', [LicenseController::class, 'extendValidity'])->int('id')
        ->meta([
            'permissions' => 'licenses/manage'
        ]);
    $router->post('/licenses/{id}/update_status', [LicenseController::class, 'updateStatus'])->int('id')->meta([
        'permissions' => 'licenses/manage'
    ]);
    $router->post('/licenses/{id}/update_limit', [LicenseController::class, 'updateLimit'])->int('id')->meta([
        'permissions' => 'licenses/manage'
    ]);

    $router->post('/licenses/{id}/deactivate_site', [LicenseController::class, 'deactivateSite'])->int('id')->meta([
        'permissions' => 'licenses/manage'
    ]);
    $router->post('/licenses/{id}/activate_site', [LicenseController::class, 'activateSite'])->int('id')->meta([
        'permissions' => 'licenses/manage'
    ]);
    $router->get('/products/{id}/settings', [ProductLicenseController::class, 'getSettings'])->int('id')->meta([
        'permissions' => 'licenses/view'
    ]);
    $router->post('/products/{id}/settings', [ProductLicenseController::class, 'saveSettings'])->int('id')->meta([
        'permissions' => 'licenses/manage'
    ]);
    $router->delete('/licenses/{id}/delete', [LicenseController::class, 'deleteLicense'])->int('id')->meta([
        'permissions' => 'licenses/delete'
    ]);
});


$router->prefix('customer-profile/licenses')->withPolicy('FluentCart\App\Http\Policies\CustomerFrontendPolicy')->group(function ($router) {
    $router->get('/', [\FluentCartPro\App\Modules\Licensing\Http\Controllers\CustomerProfileController::class, 'getLicenses']);
    $router->get('/{license_key}', [\FluentCartPro\App\Modules\Licensing\Http\Controllers\CustomerProfileController::class, 'getLicenseDetails'])->alphaNumDash('license_key');
    $router->get('/{license_key}/activations', [\FluentCartPro\App\Modules\Licensing\Http\Controllers\CustomerProfileController::class, 'getActivations'])->alphaNumDash('license_key');
    $router->post('/{license_key}/deactivate_site', [\FluentCartPro\App\Modules\Licensing\Http\Controllers\CustomerProfileController::class, 'deactivateSite'])->alphaNumDash('license_key');
});

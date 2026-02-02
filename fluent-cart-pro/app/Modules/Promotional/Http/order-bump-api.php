<?php

use FluentCart\Framework\Http\Router;
use FluentCartPro\App\Modules\Promotional\Http\Controllers\OrderBumpController;

/**
 * @var $router Router
 */

$router->prefix('order_bump')->withPolicy('OrderBumpPolicy')->group(function ($router) {
    $router->get('/', [OrderBumpController::class, 'index'])->meta([
        'permissions' => 'store/sensitive' // TODO: i will change this later
    ]);
    $router->post('/', [OrderBumpController::class, 'store'])->meta([
        'permissions' => 'store/sensitive' // TODO: i will change this later
    ]);

    $router->get('/{id}', [OrderBumpController::class, 'show'])->meta([
        'permissions' => 'store/sensitive' // TODO: i will change this later
    ])->int('id');

    $router->put('/{id}', [OrderBumpController::class, 'update'])->meta([
        'permissions' => 'store/sensitive' // TODO: i will change this later
    ])->int('id');

    $router->delete('/{id}', [OrderBumpController::class, 'delete'])->meta([
        'permissions' => 'store/sensitive' // TODO: i will change this later
    ])->int('id');


});

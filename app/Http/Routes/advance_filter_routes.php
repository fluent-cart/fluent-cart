<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php

/**
 * @var $router Router
 */

use FluentCart\App\Http\Controllers\AdvanceFilter\AdvanceFilterController;
use FluentCart\App\Http\Controllers\VariantController;
use FluentCart\Framework\Http\Router;

$router->prefix('advance_filter')
    ->group(function (Router $router) {
        $router->get('/get-filter-options', [AdvanceFilterController::class, 'getFilterOption']);
    });

$router->get('forms/search_options', [AdvanceFilterController::class, 'getSearchOptions'])->withPolicy('AdminPolicy')->meta([
    'permissions' => 'super_admin'
]);
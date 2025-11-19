<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var $router Router
 */

use FluentCart\Framework\Http\Router;

$router->namespace('')
    ->group(function ($router) {
        require __DIR__ . '/api.php';
        require __DIR__ . '/reports.php';
        require __DIR__ . '/frontend_routes.php';
        require __DIR__ . '/advance_filter_routes.php';
    });

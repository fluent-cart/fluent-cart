<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php


use FluentCart\App\Modules\Subscriptions\Http\Controllers\SubscriptionController;

use FluentCart\Framework\Http\Router;
use FluentCart\App\Modules\Subscriptions\Http\Policies\SubscriptionsPolicy;

$router->prefix('subscriptions')->withPolicy('OrderPolicy')->group(function (Router $router) {
    $router->get('/', [SubscriptionController::class, 'index']);
    $router->get('/{subscriptionOrderId}', [SubscriptionController::class, 'getSubscriptionOrderDetails']);
});

$router->prefix('orders')->withPolicy('OrderPolicy')->group(function (Router $router) {
    $router->put('/{order}/subscriptions/{subscription}/cancel', [SubscriptionController::class, 'cancelSubscription']);
    $router->put('/{order}/subscriptions/{subscription}/fetch', [SubscriptionController::class, 'fetchSubscription']);

    // Not available these 3
    $router->put('/{order}/subscriptions/{subscription}/reactivate', [SubscriptionController::class, 'reactivateSubscription']);
    $router->put('/{order}/subscriptions/{subscription}/pause', [SubscriptionController::class, 'pauseSubscription']);
    $router->put('/{order}/subscriptions/{subscription}/resume', [SubscriptionController::class, 'resumeSubscription']);
});


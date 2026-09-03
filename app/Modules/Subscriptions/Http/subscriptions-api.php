<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php


use FluentCart\App\Modules\Subscriptions\Http\Controllers\SubscriptionController;

use FluentCart\Framework\Http\Router;

$router->prefix('subscriptions')->withPolicy('OrderPolicy')->group(function (Router $router) {
    $router->get('/', [SubscriptionController::class, 'index'])->meta([
        'permissions' => 'subscriptions/view'
    ]);
    $router->get('/{subscriptionOrderId}', [SubscriptionController::class, 'getSubscriptionOrderDetails'])->meta([
        'permissions' => 'subscriptions/view'
    ]);
});

$router->prefix('orders')->withPolicy('OrderPolicy')->group(function (Router $router) {
    $router->put('/{order}/subscriptions/{subscription}/cancel', [SubscriptionController::class, 'cancelSubscription'])->meta([
        'permissions' => 'subscriptions/manage'
    ]);
    $router->put('/{order}/subscriptions/{subscription}/fetch', [SubscriptionController::class, 'fetchSubscription'])->meta([
        'permissions' => 'subscriptions/manage'
    ]);

    $router->post('/{order}/subscriptions/{subscription}/early-payment-link', [SubscriptionController::class, 'generateEarlyPaymentLink'])->meta([
        'permissions' => 'subscriptions/manage'
    ]);

    $router->put('/{order}/subscriptions/{subscription}/reactivate', [SubscriptionController::class, 'reactivateSubscription'])->meta([
        'permissions' => 'subscriptions/manage'
    ]);
    $router->put('/{order}/subscriptions/{subscription}/pause', [SubscriptionController::class, 'pauseSubscription'])->meta([
        'permissions' => 'subscriptions/manage'
    ]);
    $router->put('/{order}/subscriptions/{subscription}/resume', [SubscriptionController::class, 'resumeSubscription'])->meta([
        'permissions' => 'subscriptions/manage'
    ]);
    $router->put('/{order}/subscriptions/{subscription}/update', [SubscriptionController::class, 'updateSubscription'])->meta([
        'permissions' => 'subscriptions/manage'
    ]);
    $router->put('/{order}/subscriptions/{subscription}/vendor-ids', [SubscriptionController::class, 'updateVendorIds'])->meta([
        'permissions' => 'subscriptions/manage'
    ]);
    $router->post('/{order}/subscriptions/{subscription}/verify-vendor-ids', [SubscriptionController::class, 'verifyVendorIds'])->meta([
        'permissions' => 'subscriptions/manage'
    ]);
    $router->post('/{order}/subscriptions/{subscription}/create-renewal', [SubscriptionController::class, 'createRenewalNow'])->meta([
        'permissions' => 'subscriptions/manage'
    ]);
    $router->post('/{order}/subscriptions/{subscription}/skip-renewal', [SubscriptionController::class, 'skipRenewal'])->meta([
        'permissions' => 'subscriptions/manage'
    ]);
    $router->post('/{order}/subscriptions/{subscription}/charge-now', [SubscriptionController::class, 'chargeNow'])->meta([
        'permissions' => 'subscriptions/manage'
    ]);
});


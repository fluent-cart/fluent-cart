<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * @var $router \FluentCart\Framework\Http\Router
 */

use FluentCart\App\Http\Controllers\CartController;
use FluentCart\App\Http\Controllers\CheckoutController;
use FluentCart\App\Http\Controllers\FrontendControllers\CustomerController;
use FluentCart\App\Http\Controllers\FrontendControllers\CustomerOrderController;
use FluentCart\App\Http\Controllers\FrontendControllers\CustomerProfileController;
use FluentCart\App\Http\Controllers\FrontendControllers\CustomerSubscriptionController;
use FluentCart\App\Http\Controllers\ShopController;
use FluentCart\App\Http\Controllers\UserController;
use FluentCart\App\Modules\Shipping\Http\Controllers\Frontend\ShippingFrontendController;
use FluentCart\Framework\Http\Router;

$router->prefix('public')
    ->withPolicy('PublicPolicy')->group(function (Router $router) {
        $router->get('products', [ShopController::class, 'getProducts']);
        $router->get('product-views', [ShopController::class, 'getProductViews']);
        $router->get('product-search', [ShopController::class, 'searchProduct']);
    });

$router->prefix('cart')
    ->withPolicy('PublicPolicy')->group(function (Router $router) {
        $router->get('add_item', [CartController::class, 'addToCart']);
        //$router->post('/apply-coupon', [CartController::class, 'applyCoupon']);
        //$router->post('/cancel-coupon', [CartController::class, 'cancelCoupon']);
        //$router->post('/re-apply-coupon', [CartController::class, 'reapplyCoupon']);
    });

$router->prefix('checkout')
    ->withPolicy('PublicPolicy')->group(function (Router $router) {
        $router->post('place-order', [CheckoutController::class, 'placeOrder']);
        $router->get('get-order-info', [CheckoutController::class, 'getOrderInfo']);
        $router->get('get-checkout-summary-view', [CheckoutController::class, 'getCheckoutSummary']);
        $router->get('/get-available-shipping-methods', [ShippingFrontendController::class, 'getAvailableShippingMethods']);
        $router->get('/get-shipping-methods-list-view', [ShippingFrontendController::class, 'getShippingMethodsListView']);
        $router->get('/get-country-info', [ShippingFrontendController::class, 'getCountryInfo']);
    });

$router->prefix('user')->withPolicy('PublicPolicy')->group(function (Router $router) {
    //$router->post('register', [UserController::class, 'register']);
    $router->post('login', [UserController::class, 'login']);
});

$router->prefix('customers')
    ->withPolicy('CustomerFrontendPolicy')->group(function (Router $router) {
        //$router->post('/', [CustomerController::class, 'store']);
        $router->get('/{customerId}', [CustomerController::class, 'getDetails']);
        $router->put('/{customerId}', [CustomerController::class, 'updateDetails']);
        $router->get('/{customerId}/orders', [CustomerController::class, 'getCustomerOrders']);
        $router->get('/{customerAddressId}/update-address-select', [CustomerController::class, 'updateAddressSelect']);
        $router->put('/{customerId}/address', [CustomerController::class, 'updateAddress']);
        $router->post('/add-address', [CustomerController::class, 'createAddress']);
        $router->delete('/{customerId}/address', [CustomerController::class, 'removeAddress']);
        $router->post('/{customerId}/address/make-primary', [CustomerController::class, 'setAddressPrimary']);
    });

$router->prefix('customer-profile')->withPolicy('CustomerFrontendPolicy')->group(function (Router $router) {
    $router->get('/', [CustomerProfileController::class, 'index']);
    $router->get('/downloads', [CustomerProfileController::class, 'getDownloads']);

    $router->get('/profile', [CustomerProfileController::class, 'getCustomerProfileDetails']);
    $router->post('/create-address', [CustomerProfileController::class, 'createCustomerProfileAddress']);

    $router->post('/edit-address', [CustomerProfileController::class, 'updateCustomerProfileAddress']);
    $router->post('/make-primary-address', [CustomerProfileController::class, 'makePrimaryCustomerProfileAddress']);
    $router->post('/delete-address', [CustomerProfileController::class, 'deleteCustomerProfileAddress']);

    $router->post('/update', [CustomerProfileController::class, 'updateCustomerProfileDetails']);


    // orders
    $router->get('orders', [CustomerOrderController::class, 'getOrders']);
    $router->get('orders/{order_uuid}', [CustomerOrderController::class, 'orderDetails'])->alphaNumDash('order_uuid');
    $router->get('orders/{order_uuid}/upgrade-paths', [CustomerProfileController::class, 'getUpgradePaths'])->alphaNumDash('order_uuid');

    // Order billing address
    $router->get('orders/{transaction_uuid}/billing-address', [CustomerOrderController::class, 'getTransactionBillingAddress'])->alphaNumDash('transaction_uuid');
    $router->put('orders/{transaction_uuid}/billing-address', [CustomerOrderController::class, 'saveTransactionBillingAddress'])->alphaNumDash('transaction_uuid');


    // subscriptions
    $router->get('subscriptions', [CustomerSubscriptionController::class, 'getSubscriptions']);
    $router->get('subscriptions/{subscription_uuid}', [CustomerSubscriptionController::class, 'getSubscription'])->alphaNumDash('subscription_uuid');
    $router->post('subscriptions/{subscription_uuid}/update-payment-method', [CustomerSubscriptionController::class, 'updatePaymentMethod'])->alphaNumDash('subscription_uuid');
    $router->post('subscriptions/{subscription_uuid}/get-or-create-plan', [CustomerSubscriptionController::class, 'getOrCreatePlan'])->alphaNumDash('subscription_uuid');
    $router->post('subscriptions/{subscription_uuid}/switch-payment-method', [CustomerSubscriptionController::class, 'switchPaymentMethod'])->alphaNumDash('subscription_uuid');
    $router->post('subscriptions/{subscription_uuid}/confirm-subscription-switch', [CustomerSubscriptionController::class, 'confirmSubscriptionSwitch'])->alphaNumDash('subscription_uuid');
    $router->post('subscriptions/{subscription_uuid}/confirm-subscription-reactivation', [CustomerSubscriptionController::class, 'confirmSubscriptionReactivation'])->alphaNumDash('subscription_uuid');
    $router->post('subscriptions/{subscription_uuid}/cancel-auto-renew', [CustomerSubscriptionController::class, 'cancelAutoRenew'])->alphaNumDash('subscription_uuid');

});

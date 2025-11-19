<?php

namespace FluentCart\App\Http\Routes;

use FluentCart\App\App;
use FluentCart\App\Modules\Coupon\CouponHandler;
use FluentCart\App\Http\Controllers\CheckoutController;
use FluentCart\App\Modules\Shipping\Http\Controllers\Frontend\ShippingFrontendController;
use FluentCart\App\Http\Controllers\CartController;
use FluentCart\App\Http\Controllers\FrontendControllers\CustomerController;
use FluentCart\App\Http\Controllers\UserController;

class AjaxRoute
{
    public static function register()
    {
//        $routes = [
//            'fluent_cart_apply_coupon'       => [CouponHandler::class, 'applyCoupon'],
//            'fluent_cart_remove_coupon'      => [CouponHandler::class, 'removeCoupon'],
//            'fluent_cart_reapply_coupon'     => [CartController::class, 'reapplyCoupon'],
//            'get_checkout_summary_view'      => [CheckoutController::class, 'getCheckoutSummary'],
//            'get_shipping_methods_list_view' => [ShippingFrontendController::class, 'getShippingMethodsListView'],
//            'get_country_info'               => [ShippingFrontendController::class, 'getCountryInfo'],
//            'customers_add_address'          => [CustomerController::class, 'createAddress'],
//            'fluent_cart_user_registration'  => [UserController::class, 'register'],
//            'fluent_cart_get_order_info'     => [CheckoutController::class, 'getOrderInfo'],
//            'fluent_cart_place_order'        => [CheckoutController::class, 'placeOrder'],
//        ];
//
//
//        foreach ($routes as $route => $callback) {
//            add_action('wp_ajax_nopriv_' . $route, function () use ($route, $callback) {
//                static::callback($route, $callback);
//            });
//            add_action('wp_ajax_' . $route, function () use ($route, $callback) {
//                static::callback($route, $callback);
//            });
//        }
    }

    public static function callback($route, $callback)
    {
        $resolved = App::makeWith($callback[0]);
        $data = App::call([$resolved, $callback[1]]);

        if ($data instanceof \WP_REST_Response) {
            $data = $data->get_data();
        }


        wp_send_json(
            $data
        );

    }


}

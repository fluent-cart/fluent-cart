<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use Tests\Support\Doubles\CheckoutGateway;
use Tests\Support\FunctionalTester;

/**
 * GET checkout/get-order-info answers anyone, signed in or not.
 *
 * It hands the `method` query value to GatewayManager::get(), which takes a
 * string and returns null for an unknown slug. A request without a method
 * passed null into it, and an unknown slug returned null that was then
 * called — either way a PHP error, sent back as a 500 plugin_exception.
 * A missing, empty or unknown method is a bad request: 422, not a 500.
 * Board task #23553, support ticket #180472.
 */
class GetOrderInfoPaymentMethodCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->logout();
        GatewayManager::getInstance()->register('wp_browser_stub', new CheckoutGateway());
    }

    public function a_request_without_a_method_is_refused_with_422(FunctionalTester $I): void
    {
        $I->get('/fluent-cart/v2/checkout/get-order-info');

        $I->seeResponseCodeIs(422);
    }

    public function an_empty_method_is_refused_with_422(FunctionalTester $I): void
    {
        $I->get('/fluent-cart/v2/checkout/get-order-info', ['method' => '']);

        $I->seeResponseCodeIs(422);
    }

    public function an_unknown_method_is_refused_with_422(FunctionalTester $I): void
    {
        $I->get('/fluent-cart/v2/checkout/get-order-info', ['method' => 'no_such_gateway']);

        $I->seeResponseCodeIs(422);
    }

    public function an_order_hash_without_a_method_is_still_refused_with_422(FunctionalTester $I): void
    {
        $I->get('/fluent-cart/v2/checkout/get-order-info', ['order_hash' => 'TEST']);

        $I->seeResponseCodeIs(422);
    }

    public function a_registered_method_still_reaches_its_gateway(FunctionalTester $I): void
    {
        $I->get('/fluent-cart/v2/checkout/get-order-info', ['method' => 'wp_browser_stub']);

        $I->seeResponseCodeIs(200);
        $I->seeResponseJsonMatchesSubset(['status' => 'stub']);
    }
}

<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\Models\Order;
use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/checkout.md [14]
 *   "A guest may post place-order at most 5 times in a minute from one IP;
 *    the 6th post gets 429 before the cart is read." (SCAN-08)
 */
class PlaceOrderRateLimitCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    public function _after(FunctionalTester $I): void
    {
        $I->clearRateLimiterWindows();
    }

    public function theSixthPlaceOrderPostInAMinuteIsRefused(FunctionalTester $I): void
    {
        $ordersBefore = Order::query()->count();

        for ($i = 1; $i <= 5; $i++) {
            [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', [], true);
            Assert::assertNotSame(429, $status, "post {$i} is within the limit of 5 and must be answered on its merits. Body: " . wp_json_encode($body));
        }

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', [], true);

        Assert::assertSame(429, $status, 'the 6th place-order post in a minute must be refused. Body: ' . wp_json_encode($body));
        Assert::assertSame($ordersBefore, Order::query()->count(), 'refused posts create no order');
    }
}

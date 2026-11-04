<?php

namespace Tests\Functional\Rest\Shipping;

use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;
use Tests\Support\Shipping\StubShippingCarrier;

/**
 * POST /shipping/carriers/{slug}/actions/{action} runs a settings-page action
 * at once and returns the carrier, so the page redraws without a save.
 */
class CarrierActionCest
{
    public function _before(FunctionalTester $I): void
    {
        StubShippingCarrier::install();
    }

    public function _after(FunctionalTester $I): void
    {
        StubShippingCarrier::uninstall();
    }

    public function actionRunsAndReturnsTheCarrier(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        StubShippingCarrier::$actionResult = ['message' => 'Webhook registered in Shippo'];

        [$status, $body] = $I->post('/fluent-cart/v2/shipping/carriers/' . StubShippingCarrier::SLUG . '/actions/register_webhook', ['source' => 'page']);

        Assert::assertSame(200, $status, 'a known action must return 200. Body: ' . wp_json_encode($body));
        Assert::assertSame('register_webhook', StubShippingCarrier::$actionCalls[0][0] ?? null, 'the carrier must receive the action name');
        Assert::assertSame('page', StubShippingCarrier::$actionCalls[0][1]['source'] ?? null, 'the carrier must receive the posted data');
        Assert::assertSame('Webhook registered in Shippo', $body['message'] ?? null, 'the message must reach the toast');
        Assert::assertTrue($body['ok'] ?? null, 'an action without ok defaults to success');
        Assert::assertSame(StubShippingCarrier::SLUG, $body['carrier']['slug'] ?? null, 'the carrier must come back for the redraw');
    }

    public function actionThatFailsAtTheCarrierStillReturnsTheCarrier(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        StubShippingCarrier::$actionResult = ['message' => "Shippo didn't accept the webhook", 'ok' => false];

        [$status, $body] = $I->post('/fluent-cart/v2/shipping/carriers/' . StubShippingCarrier::SLUG . '/actions/register_webhook');

        Assert::assertSame(200, $status, 'a carrier-side failure still redraws the page. Body: ' . wp_json_encode($body));
        Assert::assertFalse($body['ok'] ?? null, 'ok must carry the failure for an error toast');
        Assert::assertSame(StubShippingCarrier::SLUG, $body['carrier']['slug'] ?? null);
    }

    public function unknownActionIsRefused(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/shipping/carriers/' . StubShippingCarrier::SLUG . '/actions/nope');

        Assert::assertSame(422, $status, 'a carrier without that action must refuse it. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty($body['message'] ?? '');
    }

    public function unknownCarrierIsNotFound(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status] = $I->post('/fluent-cart/v2/shipping/carriers/no-such-carrier/actions/register_webhook');

        Assert::assertSame(404, $status);
    }

    public function actionNeedsStoreSensitivePermission(FunctionalTester $I): void
    {
        StubShippingCarrier::$actionResult = ['message' => 'ran'];
        $I->logout();

        [$status] = $I->post('/fluent-cart/v2/shipping/carriers/' . StubShippingCarrier::SLUG . '/actions/register_webhook');

        Assert::assertContains($status, [401, 403], 'an anonymous caller must be refused');
        Assert::assertSame([], StubShippingCarrier::$actionCalls, 'a refused caller must not reach the carrier');
    }
}

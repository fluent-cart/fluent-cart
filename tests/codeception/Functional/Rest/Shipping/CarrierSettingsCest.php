<?php

namespace Tests\Functional\Rest\Shipping;

use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;
use FluentCart\App\Models\Meta;
use Tests\Support\Shipping\StubShippingCarrier;
use Tests\Support\Shipping\WebhookSecretCarrier;

/**
 * PUT /shipping/carriers/{slug}/settings returns the carrier as the list shows
 * it, so fields whose options come from stored data refresh without a reload.
 */
class CarrierSettingsCest
{
    public function _before(FunctionalTester $I): void
    {
        StubShippingCarrier::install();
    }

    public function _after(FunctionalTester $I): void
    {
        StubShippingCarrier::uninstall();
    }

    public function saveReturnsTheRefreshedCarrier(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        StubShippingCarrier::$webhookUrl = 'https://shop.test/?fluent-cart=fct_shipping_listener_webhook&carrier=stub';
        StubShippingCarrier::$fields     = [
            ['key' => 'service', 'type' => 'select', 'label' => 'Service', 'options' => ['' => 'All', 'ground' => 'Ground']],
        ];

        [$status, $body] = $I->put('/fluent-cart/v2/shipping/carriers/' . StubShippingCarrier::SLUG . '/settings', ['service' => 'ground']);

        Assert::assertSame(200, $status, 'saving carrier settings must return 200. Body: ' . wp_json_encode($body));
        Assert::assertSame(StubShippingCarrier::SLUG, $body['carrier']['slug'] ?? null, 'the saved carrier must be returned');
        Assert::assertSame(StubShippingCarrier::$fields, $body['carrier']['fields'] ?? null, 'the fields must be rebuilt after the save');
        Assert::assertSame(StubShippingCarrier::$webhookUrl, $body['carrier']['webhook_url'] ?? null, 'the carrier payload must match the list payload');
        Assert::assertArrayHasKey('settings', $body, 'the existing settings key must stay');
    }

    public function firstSaveReturnsTheNewWebhookUrl(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        WebhookSecretCarrier::install();
        Meta::query()->where('meta_key', 'fluent_cart_carrier_settings_webhook_secret_stub')->delete();

        [$status, $body] = $I->put('/fluent-cart/v2/shipping/carriers/' . WebhookSecretCarrier::SLUG . '/settings', ['is_active' => 'no']);

        Assert::assertSame(200, $status, 'the first save must return 200. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty($body['message'] ?? '', 'the save must carry a message for the toast');
        Assert::assertStringContainsString(
            'fcs_token=',
            (string) ($body['carrier']['webhook_url'] ?? ''),
            'the secret issued by the first save must already be in the returned webhook URL'
        );
    }
}

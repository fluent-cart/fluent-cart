<?php

namespace Tests\Integration\Http;

use FluentCart\App\Hooks\Handlers\AddonGatewaysHandler;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use Tests\Support\RestTestCase;

/**
 * The add-on install routes take only the add-on key. The download URL comes from the
 * server's own add-on list, never from the request. Without FluentCart Pro the server
 * downloads nothing and returns the URL it would use, so the tests read it there.
 */
class AddonInstallSourceTest extends RestTestCase
{
    private const FOREIGN = 'https://evil.example/payload.zip';

    public function setUp(): void
    {
        parent::setUp();
        (new AddonGatewaysHandler())->registerPromoGateways();
    }

    public function test_each_payment_addon_installs_from_its_listed_url(): void
    {
        $listed = $this->listedPaymentAddons();
        $this->assertCount(7, $listed, 'The seven payment add-ons are listed');

        foreach ($listed as $slug => $link) {
            $response = $this->post('settings/payment-methods/install-addon', ['plugin_slug' => $slug]);

            $this->assertSame(200, $response->get_status(), $slug);
            $this->assertSame($link, $response->get_data()['url'] ?? null, $slug . ' uses its listed URL');
        }
    }

    public function test_a_payment_addon_request_with_a_foreign_link_still_uses_the_listed_url(): void
    {
        $response = $this->post('settings/payment-methods/install-addon', [
            'plugin_slug' => 'razorpay-for-fluent-cart',
            'source_type' => 'github',
            'source_link' => self::FOREIGN,
        ]);

        $this->assertSame(200, $response->get_status());
        $this->assertSame('https://addons-cdn.fluentcart.com/razorpay-for-fluent-cart.zip', $response->get_data()['url'] ?? null);
    }

    public function test_an_unknown_payment_addon_is_refused(): void
    {
        $response = $this->post('settings/payment-methods/install-addon', [
            'plugin_slug' => 'not-a-listed-addon',
            'source_type' => 'cdn',
            'source_link' => self::FOREIGN,
        ]);

        $this->assertSame(422, $response->get_status());
        $this->assertStringNotContainsString('evil.example', wp_json_encode($response->get_data()));
    }

    public function test_a_plugin_addon_request_with_a_foreign_link_still_uses_the_listed_url(): void
    {
        $response = $this->post('settings/modules/plugin-addons/install', [
            'plugin_slug' => 'shipping-for-fluent-cart',
            'source_type' => 'github',
            'source_link' => self::FOREIGN,
            'asset_path'  => 'browser_download_url',
        ]);

        $this->assertSame(200, $response->get_status());
        $this->assertSame('https://addons-cdn.fluentcart.com/shipping-for-fluent-cart.zip', $response->get_data()['url'] ?? null);
    }

    public function test_an_unknown_plugin_addon_is_refused(): void
    {
        $response = $this->post('settings/modules/plugin-addons/install', [
            'plugin_slug' => 'not-a-listed-addon',
            'source_type' => 'cdn',
            'source_link' => self::FOREIGN,
        ]);

        $this->assertSame(422, $response->get_status());
    }

    private function post(string $route, array $body): \WP_REST_Response
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/' . $route);
        $request->set_body_params($body);

        return $this->dispatchRequest($request);
    }

    /** @return array<string, string> plugin slug => listed download link */
    private function listedPaymentAddons(): array
    {
        $listed = [];
        foreach (GatewayManager::getInstance()->all() as $gateway) {
            $meta = $gateway->meta();
            if (!empty($meta['is_addon']) && !empty($meta['addon_source']['slug'])) {
                $listed[$meta['addon_source']['slug']] = $meta['addon_source']['link'];
            }
        }

        return $listed;
    }
}

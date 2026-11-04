<?php

namespace Tests\Integration\Http;

use FluentCart\App\Models\ShippingZone;
use Tests\Support\RestTestCase;

/**
 * Real permission boundary tests for /shipping/* admin routes.
 *
 * Auth-context flips go through RestTestCase's dispatchRequestAsGuest()/
 * dispatchRequestAs() seams, which invalidate the Helper::getCurrentUser()
 * function-static cache before each dispatch. The previous
 * @runInSeparateProcess harness is gone: subprocess WP bootstrap broke
 * dispatch in both directions (anonymous reached 200, authorized got 403
 * off a null current user), so isolation is now cache-reset, not fork.
 *
 * Routes covered as representative admin surface:
 *   GET  /fluent-cart/v2/shipping/zones      (index)
 *   POST /fluent-cart/v2/shipping/zones      (store)
 *   GET  /fluent-cart/v2/shipping/classes    (index)
 *
 * Policy: StoreSensitivePolicy (capability: store/sensitive via GlobalPermissionsHandler).
 */
class ShippingPermissionsTest extends RestTestCase
{
    public function tearDown(): void
    {
        wp_cache_flush();
        gc_collect_cycles();
        parent::tearDown();
    }

    public function test_subscriber_gets_403_on_shipping_zones_index(): void
    {
        $subId = static::factory()->user->create(['role' => 'subscriber']);

        $response = $this->dispatchRequestAs($subId,
            new \WP_REST_Request('GET', '/fluent-cart/v2/shipping/zones'));

        $this->assertSame(403, $response->get_status(),
            'Subscriber must be rejected from admin shipping index. Policy: StoreSensitivePolicy.');
    }

    public function test_subscriber_gets_403_on_shipping_zones_store(): void
    {
        $subId = static::factory()->user->create(['role' => 'subscriber']);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/shipping/zones');
        $request->set_body_params(['name' => 'Unauthorized zone', 'region' => 'US']);
        $response = $this->dispatchRequestAs($subId, $request);

        $this->assertSame(403, $response->get_status(),
            'Subscriber must be rejected from admin shipping POST');
        $body = $response->get_data();
        $this->assertIsArray($body);
        $this->assertArrayHasKey('code', $body,
            'The rejection body is a REST error envelope, not zone data');
        $this->assertSame(0, ShippingZone::query()->where('name', 'Unauthorized zone')->count(),
            'The rejected subscriber store persisted no zone row');
    }

    public function test_subscriber_gets_403_on_shipping_classes_index(): void
    {
        $subId = static::factory()->user->create(['role' => 'subscriber']);

        $response = $this->dispatchRequestAs($subId,
            new \WP_REST_Request('GET', '/fluent-cart/v2/shipping/classes'));

        $this->assertSame(403, $response->get_status(),
            'Subscriber must be rejected from admin shipping classes index');
    }

    public function test_anonymous_gets_401_on_shipping_zones_index(): void
    {
        $response = $this->dispatchRequestAsGuest(
            new \WP_REST_Request('GET', '/fluent-cart/v2/shipping/zones'));

        $this->assertSame(401, $response->get_status(),
            'Anonymous must be rejected from admin shipping routes as unauthenticated');
    }

    public function test_anonymous_gets_401_on_shipping_zones_store(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/shipping/zones');
        $request->set_body_params(['name' => 'Anon zone', 'region' => 'US']);
        $response = $this->dispatchRequestAsGuest($request);

        $this->assertSame(401, $response->get_status(),
            'Anonymous must be rejected from admin shipping POST as unauthenticated');
        $body = $response->get_data();
        $this->assertIsArray($body);
        $this->assertArrayHasKey('code', $body,
            'The rejection body is a REST error envelope, not zone data');
        $this->assertSame(0, ShippingZone::query()->where('name', 'Anon zone')->count(),
            'The rejected anonymous store persisted no zone row');
    }

    /**
     * Positive-path sanity check: the persistent admin (super admin, user 1)
     * reaches the same route the negative tests are rejected from — proving
     * rejections above come from the policy, not a harness that can never
     * reach the controller.
     */
    public function test_fluent_cart_super_admin_can_access_shipping_zones_index(): void
    {
        $response = $this->dispatchRequest(
            new \WP_REST_Request('GET', '/fluent-cart/v2/shipping/zones'));

        $this->assertSame(200, $response->get_status(),
            'The store super admin can read the shipping zones index');
    }
}

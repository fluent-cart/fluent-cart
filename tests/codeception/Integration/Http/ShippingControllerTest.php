<?php

namespace Tests\Integration\Http;

use FluentCart\App\Models\ShippingMethod;
use FluentCart\App\Models\ShippingZone;
use Tests\Support\Factory\ShippingClassFactory;
use Tests\Support\Factory\ShippingMethodFactory;
use Tests\Support\Factory\ShippingZoneFactory;
use Tests\Support\RestTestCase;
use Tests\Support\Traits\AssertsRestResponses;
use Tests\Support\Traits\AssertsRouteRegistration;

/**
 * Shipping HTTP/REST specs (99-134, 135-142).
 *
 * Admin routes (`/shipping/*`) live under `StoreSensitivePolicy`; frontend routes (`/checkout/*`)
 * live under `PublicPolicy`. Tests dispatch real REST requests; route bindings are also
 * verified via shipping-api.php source parse as a cheap regression safety net.
 */
class ShippingControllerTest extends RestTestCase
{
    use AssertsRestResponses;
    use AssertsRouteRegistration;

    private ShippingZoneFactory $zoneFactory;
    private ShippingMethodFactory $methodFactory;
    private ShippingClassFactory $classFactory;

    /**
     * WPFluent Route::getParameter() memoizes the first dispatch's URL params
     * forever (TESTABILITY-DEBT #3); parameterized routes ({id}) need a fresh
     * REST server per dispatch or later PUT/DELETE dispatches reuse stale ids.
     */
    protected bool $rebuildRestServerPerDispatch = true;

    public function setUp(): void
    {
        parent::setUp();
        $this->loadRoutesSource(FLUENTCART_PLUGIN_PATH . 'app/Modules/Shipping/Http/shipping-api.php');
        $this->zoneFactory   = new ShippingZoneFactory();
        $this->methodFactory = new ShippingMethodFactory();
        $this->classFactory  = new ShippingClassFactory();
    }

    public function tearDown(): void
    {
        wp_cache_flush();
        parent::tearDown();
    }

    public function test_zones_index_returns_paginated_collection(): void
    {
        $this->zoneFactory->create();
        $this->zoneFactory->create();

        $response = $this->dispatchRequest(new \WP_REST_Request('GET', '/fluent-cart/v2/shipping/zones'));
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertIsArray($data);
    }

    public function test_zones_store_creates_zone(): void
    {
        $name = 'Zone REST ' . uniqid();
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/shipping/zones');
        $request->set_body_params([
            'name'   => $name,
            'region' => 'DE',
            'order'  => 0,
        ]);
        $response = $this->dispatchRequest($request);
        $this->assertSame(200, $response->get_status());
        $zone = ShippingZone::query()->where('name', $name)->first();
        $this->assertNotNull($zone, 'Created zone row must be persisted');
        $this->assertSame('DE', $zone->region, 'Submitted region must persist');
    }

    public function test_zones_store_without_name_returns_error(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/shipping/zones');
        $request->set_body_params(['region' => 'US']); // missing name
        $response = $this->dispatchRequestSilently($request);
        $this->assertRejectedByValidation($response, 'name',
            'Missing name must be rejected as field validation, not auth or server error');
    }

    public function test_zones_store_all_when_all_already_exists_is_rejected(): void
    {
        $this->zoneFactory->create(['region' => 'all']);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/shipping/zones');
        $request->set_body_params(['name' => 'Duplicate World ' . uniqid(), 'region' => 'all']);
        $response = $this->dispatchRequestSilently($request);
        $this->assertRejectedByValidation($response, null,
            'Duplicate "all" zone must be rejected by the ShippingZoneRequest custom validator');
    }

    public function test_zones_store_selection_with_empty_countries_is_accepted_as_inert_zone(): void
    {
        // No validator (API or admin UI) has ever rejected an empty selection —
        // the accepted contract is a zone that ships nowhere until countries
        // are added (ShippingZone::appliesToCountry returns false for every
        // country when an included selection is empty).
        $name = 'Empty selection ' . uniqid();
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/shipping/zones');
        $request->set_body_params([
            'name'   => $name,
            'region' => 'selection',
            'meta'   => ['countries' => [], 'selection_type' => 'included'],
        ]);
        $response = $this->dispatchRequest($request);
        $this->assertSame(200, $response->get_status(),
            'Empty selection zone is accepted — no validation rule rejects it');

        $zone = ShippingZone::query()->where('name', $name)->first();
        $this->assertNotNull($zone, 'Zone row must be persisted');
        $this->assertSame('selection', $zone->region, 'Region stored as selection');
        $this->assertSame([], $zone->meta['countries'] ?? null, 'Empty countries list stored in meta');
        $this->assertFalse($zone->appliesToCountry('US'),
            'Included selection with no countries applies to no country (inert zone)');
    }

    public function test_zones_update_changing_region_clears_method_states(): void
    {
        $zone = $this->zoneFactory->create(['region' => 'US']);
        $methodA = $this->methodFactory->create(['zone_id' => $zone->id, 'states' => ['CA']]);
        $methodB = $this->methodFactory->create(['zone_id' => $zone->id, 'states' => ['NY']]);
        // Control: a method in an UNRELATED zone must keep its states — a
        // regression dropping the zone_id predicate would wipe store-wide.
        $otherZone = $this->zoneFactory->create(['region' => 'CA']);
        $otherMethod = $this->methodFactory->create(['zone_id' => $otherZone->id, 'states' => ['ON']]);

        $request = new \WP_REST_Request('PUT', '/fluent-cart/v2/shipping/zones/' . $zone->id);
        $request->set_body_params(['name' => $zone->name, 'region' => 'DE']);
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status(), 'Region update must succeed');
        $this->assertSame([], ShippingMethod::query()->find($methodA->id)->states,
            'Changing zone region must reset the first associated method\'s states to []');
        $this->assertSame([], ShippingMethod::query()->find($methodB->id)->states,
            'Changing zone region must reset EVERY associated method\'s states, not just the first');
        $this->assertSame(['ON'], ShippingMethod::query()->find($otherMethod->id)->states,
            'A method in an unrelated zone must keep its states — reset must be scoped to the updated zone');
        $this->assertSame('DE', ShippingZone::query()->find($zone->id)->region,
            'New region must be persisted');
    }

    public function test_zones_destroy_cascades_methods(): void
    {
        $zone = $this->zoneFactory->create();
        $methodA = $this->methodFactory->create(['zone_id' => $zone->id]);
        $methodB = $this->methodFactory->create(['zone_id' => $zone->id]);
        // Control: an unrelated zone and its method must survive — a dropped
        // zone_id scope on the cascade would wipe methods store-wide.
        $otherZone = $this->zoneFactory->create();
        $otherMethod = $this->methodFactory->create(['zone_id' => $otherZone->id]);

        // ShippingZoneController::destroy() runs its own $DB->beginTransaction()/
        // commit(); MySQL has no nested transactions, so that COMMIT also commits
        // this test's wrapper transaction — the surviving US-region zone and its
        // empty-states method would become permanent and shift
        // getApplicableForCountry() counts in ShippingServiceTest's edge tests.
        // Heal in a finally block with an explicit COMMIT (OrderControllerTest
        // deleteOrder precedent) so state ends clean on every path.
        try {
            $response = $this->dispatchRequest(
                new \WP_REST_Request('DELETE', '/fluent-cart/v2/shipping/zones/' . $zone->id)
            );

            $this->assertSame(200, $response->get_status(), 'Zone delete must succeed');
            // Identity-based: rows must be GONE, not merely reparented to another zone_id
            $this->assertNull(ShippingMethod::query()->find($methodA->id),
                'First linked method row must be deleted, not reparented');
            $this->assertNull(ShippingMethod::query()->find($methodB->id),
                'Second linked method row must be deleted, not reparented');
            $this->assertNull(ShippingZone::query()->find($zone->id), 'Zone row must be deleted');
            $this->assertNotNull(ShippingZone::query()->find($otherZone->id),
                'An unrelated zone must survive the delete');
            $survivor = ShippingMethod::query()->find($otherMethod->id);
            $this->assertNotNull($survivor,
                'An unrelated zone\'s method must survive — cascade must stay scoped to the deleted zone');
            $this->assertEquals($otherZone->id, $survivor->zone_id,
                'The surviving method must still belong to its own zone');
        } finally {
            ShippingMethod::query()->whereIn('id', [$methodA->id, $methodB->id, $otherMethod->id])->delete();
            ShippingZone::query()->whereIn('id', [$zone->id, $otherZone->id])->delete();
            global $wpdb;
            $wpdb->query('COMMIT');
        }
    }

    public function test_methods_store_creates_method(): void
    {
        $zone = $this->zoneFactory->create();
        $title = 'Method REST ' . uniqid();
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/shipping/methods');
        $request->set_body_params([
            'zone_id' => $zone->id,
            'title'   => $title,
            'type'    => 'flat_rate',
            'amount'  => 5,
        ]);
        $response = $this->dispatchRequest($request);
        $this->assertSame(200, $response->get_status());
        $created = ShippingMethod::query()->where('zone_id', $zone->id)->where('title', $title)->first();
        $this->assertNotNull($created, 'Created method row must be persisted against its zone');
        $this->assertSame('flat_rate', $created->type, 'Submitted type must be persisted');
        $this->assertEquals(5, $created->amount, 'Submitted amount must be persisted, not defaulted');
    }

    public function test_methods_store_without_zone_id_rejected(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/shipping/methods');
        $request->set_body_params(['title' => 'x', 'type' => 'flat_rate']);
        $response = $this->dispatchRequestSilently($request);
        $this->assertRejectedByValidation($response, 'zone_id',
            'Missing zone_id must be rejected at request validation');
    }

    public function test_methods_store_without_title_rejected(): void
    {
        $zone = $this->zoneFactory->create();
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/shipping/methods');
        $request->set_body_params(['zone_id' => $zone->id, 'type' => 'flat_rate']);
        $response = $this->dispatchRequestSilently($request);
        $this->assertRejectedByValidation($response, 'title');
    }

    public function test_methods_store_without_type_rejected(): void
    {
        $zone = $this->zoneFactory->create();
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/shipping/methods');
        $request->set_body_params(['zone_id' => $zone->id, 'title' => 'x']);
        $response = $this->dispatchRequestSilently($request);
        $this->assertRejectedByValidation($response, 'type');
    }

    public function test_classes_index_returns_paginated_collection(): void
    {
        $this->classFactory->create();
        $response = $this->dispatchRequest(new \WP_REST_Request('GET', '/fluent-cart/v2/shipping/classes'));
        $this->assertSame(200, $response->get_status());
    }

    public function test_classes_store_creates_class(): void
    {
        $name = 'Class REST ' . uniqid();
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/shipping/classes');
        $request->set_body_params([
            'name' => $name,
            'cost' => 7.5,
            'type' => 'fixed',
        ]);
        $response = $this->dispatchRequest($request);
        $this->assertSame(200, $response->get_status());
        $class = \FluentCart\App\Models\ShippingClass::query()->where('name', $name)->first();
        $this->assertNotNull($class, 'Created class row must be persisted');
        $this->assertEquals(7.5, $class->cost, 'Submitted cost must persist, not default');
        $this->assertSame('fixed', $class->type, 'Submitted type must persist');
    }

    public function test_classes_store_without_name_rejected(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/shipping/classes');
        $request->set_body_params(['cost' => 5, 'type' => 'fixed']);
        $response = $this->dispatchRequestSilently($request);
        $this->assertRejectedByValidation($response, 'name');
    }

    public function test_classes_store_negative_cost_rejected(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/shipping/classes');
        $request->set_body_params(['name' => 'neg-cost-' . uniqid(), 'cost' => -5, 'type' => 'fixed']);
        $response = $this->dispatchRequestSilently($request);
        $this->assertRejectedByValidation($response, 'cost');
    }

    public function test_classes_store_invalid_type_rejected(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/shipping/classes');
        $request->set_body_params(['name' => 'bad-type-' . uniqid(), 'cost' => 5, 'type' => 'maybe']);
        $response = $this->dispatchRequestSilently($request);
        $this->assertRejectedByValidation($response, 'type');
    }

    public function test_get_available_methods_without_country_returns_error_payload(): void
    {
        // Public route wraps the controller's plain-array return in a 200;
        // the error contract is status:false + message, not an HTTP 4xx.
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/checkout/get-available-shipping-methods');
        $response = $this->dispatchRequestAsGuest($request);

        $this->assertSame(200, $response->get_status(),
            'Public checkout route returns 200 with an error payload, not a 4xx');
        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertFalse($data['status'] ?? null, 'status must be false when country code is missing');
        $this->assertStringContainsString('Country code is required', (string)($data['message'] ?? ''),
            'Error message must state that the country code is required');
    }

    // =========================================================================
    // Spec 135-138: Hooks
    // =========================================================================

    public function test_user_without_store_caps_gets_403(): void
    {
        // Read denial + write denial with a state-unchanged proof; the wider
        // subscriber/guest route sweep lives in ShippingPermissionsTest.
        $userId = static::factory()->user->create(['role' => 'subscriber']);
        $response = $this->dispatchRequestAs(
            $userId,
            new \WP_REST_Request('GET', '/fluent-cart/v2/shipping/zones')
        );
        $this->assertSame(403, $response->get_status(),
            'Subscriber without store capabilities must be rejected with 403');

        $name = 'Forbidden zone ' . uniqid();
        $write = new \WP_REST_Request('POST', '/fluent-cart/v2/shipping/zones');
        $write->set_body_params(['name' => $name, 'region' => 'US']);
        $writeResponse = $this->dispatchRequestAs($userId, $write);
        $this->assertSame(403, $writeResponse->get_status(),
            'Subscriber must be rejected from the zone write route');
        $this->assertNull(ShippingZone::query()->where('name', $name)->first(),
            'Denied write must leave no zone row behind');
    }

    public function test_unauthenticated_gets_401(): void
    {
        $response = $this->dispatchRequestAsGuest(
            new \WP_REST_Request('GET', '/fluent-cart/v2/shipping/zones')
        );
        $this->assertSame(401, $response->get_status(),
            'Anonymous request to an admin shipping route must be rejected as unauthenticated');

        $name = 'Anon zone ' . uniqid();
        $write = new \WP_REST_Request('POST', '/fluent-cart/v2/shipping/zones');
        $write->set_body_params(['name' => $name, 'region' => 'US']);
        $writeResponse = $this->dispatchRequestAsGuest($write);
        $this->assertSame(401, $writeResponse->get_status(),
            'Anonymous write to an admin shipping route must be rejected as unauthenticated');
        $this->assertNull(ShippingZone::query()->where('name', $name)->first(),
            'Denied anonymous write must leave no zone row behind');
    }
}

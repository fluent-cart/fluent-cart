<?php

namespace Tests\Integration\Http;

use FluentCart\App\Models\OrderTaxRate;
use FluentCart\App\Models\TaxClass;
use FluentCart\App\Models\TaxRate;
use FluentCart\Framework\Support\Arr;
use Tests\Support\Factory\TaxClassFactory;
use Tests\Support\Factory\TaxRateFactory;
use Tests\Support\RestTestCase;
use Tests\Support\Traits\AssertsRouteRegistration;
use Tests\Support\Traits\BuildsTaxFixtures;
use Tests\Support\WpDieCapture;

/**
 * Tax HTTP/REST specs (128-190).
 *
 * Real REST dispatch exercises controller + policy + request validation end-to-end.
 * Structural file-parse checks are retained only as a fast regression safety net that
 * complements — not replaces — the executable dispatches.
 */
class TaxControllerTest extends RestTestCase
{
    use BuildsTaxFixtures;
    use AssertsRouteRegistration;

    /**
     * Fresh Route objects per dispatch — Route::getParameter() memoizes the
     * first dispatch's URL params per instance (TESTABILITY-DEBT #3).
     */
    protected bool $rebuildRestServerPerDispatch = true;

    private TaxClassFactory $classFactory;
    private TaxRateFactory $rateFactory;

    public function setUp(): void
    {
        parent::setUp();
        // Shield against rows leaked by OTHER files' committed controller
        // transactions (TESTABILITY-DEBT #9): a stale fct_product_variations
        // row on a recycled wp_posts id hijacks variant/tax-class resolution
        // (ghost standard-class rates). These deletes run inside the per-test
        // wrapper transaction, so they are invisible cleanup unless this test
        // itself commits — in which case tearDown's healing pass applies.
        TaxRate::query()->delete();
        TaxClass::query()->delete();
        OrderTaxRate::query()->delete();
        \FluentCart\App\Models\ProductVariation::query()->delete();
        \FluentCart\App\Models\ProductDetail::query()->delete();
        \FluentCart\App\Models\Cart::query()->delete();

        $this->loadRoutesSource(FLUENTCART_PLUGIN_PATH . 'app/Http/Routes/api.php');
        $this->classFactory = new TaxClassFactory();
        $this->rateFactory  = new TaxRateFactory();

        $this->resetTaxRuntimeState();
    }

    public function tearDown(): void
    {
        // Some endpoints under test (deleteClass, store) run their own DB
        // transaction; their COMMIT implicitly commits the per-test wrapper
        // transaction, so rows written up to that point survive the rollback
        // and poison later tests/runs (stale fct_product_variations rows get
        // matched by recycled wp_posts ids). These deletes are a no-op when
        // isolation held (rolled back with everything else) and stick exactly
        // when a leak was committed.
        TaxRate::query()->delete();
        TaxClass::query()->delete();
        OrderTaxRate::query()->delete();
        \FluentCart\App\Models\ProductVariation::query()->delete();
        \FluentCart\App\Models\ProductDetail::query()->delete();
        \FluentCart\App\Models\Cart::query()->delete();
        delete_option('fluent_cart_tax_configuration_settings');
        wp_cache_flush();
        gc_collect_cycles();
        parent::tearDown();
    }

    public function test_tax_classes_index_returns_list_wrapped_in_data(): void
    {
        $first  = $this->classFactory->create();
        $second = $this->classFactory->create();
        $third  = $this->classFactory->create();

        $response = $this->dispatchRequest(new \WP_REST_Request('GET', '/fluent-cart/v2/tax/classes'));
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('classes', $data, 'getClasses responds with a classes collection');
        $this->assertArrayHasKey('max_classes', $data, 'Response carries the class cap');
        $this->assertArrayHasKey('next_builtin', $data, 'Response carries the next built-in class hint');

        $classes = $data['classes'];
        $classes = is_object($classes) && method_exists($classes, 'toArray') ? $classes->toArray() : (array) $classes;
        $returnedIds = array_map(function ($row) {
            $row = is_object($row) && method_exists($row, 'toArray') ? $row->toArray() : (array) $row;
            return (int) ($row['id'] ?? 0);
        }, array_values($classes));

        $expected = [(int) $first->id, (int) $second->id, (int) $third->id];
        $this->assertSame($expected, array_values(array_intersect($returnedIds, $expected)),
            'All seeded classes are returned in id-ascending order (the getClasses contract)');
    }

    public function test_tax_classes_store_missing_title_returns_validation_error(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/tax/classes');
        $request->set_body_params([]); // no title
        $response = $this->dispatchRequestSilently($request);
        $this->assertGreaterThanOrEqual(400, $response->get_status());
    }

    public function test_tax_classes_store_creates_with_auto_slug(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/tax/classes');
        $request->set_body_params([
            'title' => 'Custom Tax ' . wp_rand(1000, 9999),
        ]);
        $response = $this->dispatchRequest($request);
        $this->assertSame(200, $response->get_status());

        $last = TaxClass::query()->orderBy('id', 'desc')->first();
        $this->assertNotNull($last, 'createClass persists a fct_tax_classes row');
        $this->assertNotEmpty($last->slug, 'Slug is auto-generated from the title');
    }

    /**
     * BEHAVIOR CHANGED since this test was written: the REST createClass
     * endpoint now REJECTS a duplicate title (423) instead of suffixing the
     * slug — suffixing survives only at the model layer (see
     * test_slug_collision_after_three_saves_produces_suffix_3). The method
     * name is frozen: baseline-check forbids renaming a baselined identity;
     * the Phase 5 Rest/Tax/ migration renames it via the Migration Protocol.
     */
    public function test_tax_classes_store_duplicate_title_suffixes_slug(): void
    {
        $title = 'Duplicated Tax Class';

        $first = new \WP_REST_Request('POST', '/fluent-cart/v2/tax/classes');
        $first->set_body_params(['title' => $title]);
        $this->assertSame(200, $this->dispatchRequest($first)->get_status());

        $second = new \WP_REST_Request('POST', '/fluent-cart/v2/tax/classes');
        $second->set_body_params(['title' => $title]);
        $response = $this->dispatchRequestSilently($second);
        $this->assertSame(423, $response->get_status(),
            'createClass rejects a duplicate title/slug instead of suffixing');
        $this->assertSame(1, TaxClass::query()->where('title', $title)->count(),
            'Only the first class row exists for the duplicated title');
    }

    /**
     * The REST update endpoint is gone (see test above), but the behavior the
     * sentence describes survives at the model layer: TaxClass::updating()
     * regenerates the slug whenever the title changes on a non-built-in class.
     */
    public function test_tax_classes_update_regenerates_slug_on_title_change(): void
    {
        $class = $this->classFactory->create(['title' => 'Original Name']);

        $class->title = 'Renamed Title';
        $class->save();

        $fresh = TaxClass::query()->find($class->id);
        $this->assertStringContainsString('renamed-title', $fresh->slug,
            'Changing the title regenerates the slug at the model layer');
    }

    public function test_tax_classes_delete_invalid_id_returns_error(): void
    {
        $request  = new \WP_REST_Request('DELETE', '/fluent-cart/v2/tax/classes/99999999');
        $response = $this->dispatchRequestSilently($request);
        // Controller may return 2xx with an error payload or 4xx; either signals non-success.
        $data = $response->get_data();
        $isError = $response->get_status() >= 400 || !empty($data['message']) || empty($data['data']);
        $this->assertTrue($isError, 'Deleting a non-existent class must not appear as a plain success');
    }

    public function test_tax_rates_store_without_class_id_defaults_to_standard_class(): void
    {
        $standard = $this->ensureStandardTaxClass();

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/tax/country/rate');
        $request->set_body_params([
            'country' => 'US',
            'rate'    => '10',
            'name'    => 'Sales Tax',
            // class_id deliberately omitted
        ]);
        $response = $this->dispatchRequest($request);
        $this->assertSame(200, $response->get_status(),
            'class_id is optional; the controller falls back to the standard class');

        $this->assertGreaterThan(0,
            TaxRate::query()->where('class_id', $standard->id)->where('country', 'US')->count(),
            'The rate row is attached to the standard tax class'
        );
    }

    /**
     * BEHAVIOR CHANGED since this test was written: class_id is now OPTIONAL
     * (absent → standard class, see the test above). What remains required is
     * VALIDITY: a class_id pointing at no row is rejected. Name frozen for
     * baseline identity; Phase 5 Migration Protocol renames it.
     */
    public function test_tax_rates_store_requires_class_id(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/tax/country/rate');
        $request->set_body_params([
            'class_id' => 99999999,
            'country'  => 'US',
            'rate'     => '10',
        ]);
        $response = $this->dispatchRequestSilently($request);
        $this->assertSame(422, $response->get_status(),
            'A class_id pointing at no fct_tax_classes row is rejected');
    }

    public function test_tax_rates_store_creates_with_class_id(): void
    {
        $class = $this->classFactory->create();
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/tax/country/rate');
        $request->set_body_params([
            'class_id' => $class->id,
            'country'  => 'US',
            'rate'     => '12',
            'name'     => 'Sales Tax',
        ]);
        $response = $this->dispatchRequest($request);
        $this->assertSame(200, $response->get_status());

        $this->assertGreaterThan(0,
            TaxRate::query()->where('class_id', $class->id)->where('country', 'US')->count()
        );
    }

    public function test_tax_rates_delete_invalid_id_returns_error(): void
    {
        $response = $this->dispatchRequestSilently(new \WP_REST_Request('DELETE', '/fluent-cart/v2/tax/country/rate/99999999'));
        $data = $response->get_data();
        $isError = $response->get_status() >= 400 || !empty($data['message']);
        $this->assertTrue($isError);
    }

    /**
     * Companion to the absence-pin above: dispatching the removed
     * delete-by-country endpoint is a plain 404 and touches no rows.
     */
    public function test_tax_rates_delete_country_removes_all_rows_for_country(): void
    {
        $class = $this->classFactory->create();
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '5']);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'DE', 'rate' => '19']);

        $response = $this->dispatchRequestSilently(new \WP_REST_Request('DELETE', '/fluent-cart/v2/tax/country/US'));
        $this->assertSame(404, $response->get_status(),
            'Delete-by-country was removed from the tax surface (d1b3d0207); the URL no longer resolves');

        $this->assertSame(1, TaxRate::query()->where('country', 'US')->count(),
            'No rate rows are touched by the dead URL');
        $this->assertSame(1, TaxRate::query()->where('country', 'DE')->count());
    }

    public function test_tax_rates_update_persists_target_and_leaves_bystander(): void
    {
        $class     = $this->classFactory->create();
        $target    = $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10', 'name' => 'Old Name']);
        $bystander = $this->rateFactory->create(['class_id' => $class->id, 'country' => 'DE', 'rate' => '19', 'name' => 'DE VAT']);

        $request = new \WP_REST_Request('PUT', '/fluent-cart/v2/tax/country/rate/' . $target->id);
        $request->set_body_params([
            'country' => 'US',
            'rate'    => '12.5',
            'name'    => 'Updated Sales Tax',
        ]);
        $response = $this->dispatchRequest($request);
        $this->assertSame(200, $response->get_status());

        $freshTarget = TaxRate::query()->find($target->id);
        $this->assertSame(12.5, (float) $freshTarget->rate, 'The targeted rate row persists the new rate');
        $this->assertSame('Updated Sales Tax', (string) $freshTarget->name, 'The targeted rate row persists the new name');

        $freshBystander = TaxRate::query()->find($bystander->id);
        $this->assertSame(19.0, (float) $freshBystander->rate, 'The bystander rate is untouched');
        $this->assertSame('DE VAT', (string) $freshBystander->name);
    }

    public function test_tax_rates_delete_removes_target_and_leaves_bystander(): void
    {
        $class     = $this->classFactory->create();
        $target    = $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10']);
        $bystander = $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '7', 'state' => 'CA']);

        $response = $this->dispatchRequest(new \WP_REST_Request('DELETE', '/fluent-cart/v2/tax/country/rate/' . $target->id));
        $this->assertSame(200, $response->get_status());

        $this->assertNull(TaxRate::query()->find($target->id), 'The targeted rate row is deleted');
        $this->assertNotNull(TaxRate::query()->find($bystander->id), 'The bystander rate row survives');
    }

    public function test_tax_rates_save_shipping_override_sets_for_shipping_on_target(): void
    {
        $class     = $this->classFactory->create();
        $target    = $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10', 'for_shipping' => null]);
        $bystander = $this->rateFactory->create(['class_id' => $class->id, 'country' => 'DE', 'rate' => '19', 'for_shipping' => null]);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/tax/rates/country/override');
        $request->set_body_params([
            'id'                => $target->id,
            'class_id'          => $class->id,
            'override_tax_rate' => '5',
        ]);
        $response = $this->dispatchRequest($request);
        $this->assertSame(200, $response->get_status());

        $this->assertSame(5.0, (float) TaxRate::query()->find($target->id)->for_shipping,
            'The shipping override value lands on the targeted rate row');
        $this->assertNull(TaxRate::query()->find($bystander->id)->for_shipping,
            'The bystander rate keeps its NULL shipping override');
    }

    public function test_tax_rates_delete_shipping_override_nulls_target_only(): void
    {
        $class     = $this->classFactory->create();
        $target    = $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10', 'for_shipping' => 7]);
        $bystander = $this->rateFactory->create(['class_id' => $class->id, 'country' => 'DE', 'rate' => '19', 'for_shipping' => 3]);

        $response = $this->dispatchRequest(new \WP_REST_Request('DELETE', '/fluent-cart/v2/tax/rates/country/override/' . $target->id));
        $this->assertSame(200, $response->get_status());

        $this->assertNull(TaxRate::query()->find($target->id)->for_shipping,
            'The targeted rate has for_shipping cleared to NULL (not 0)');
        $this->assertSame(3.0, (float) TaxRate::query()->find($bystander->id)->for_shipping,
            'The bystander keeps its shipping override');
    }

    public function test_tax_rates_country_tax_id_round_trip_is_country_scoped(): void
    {
        $empty = $this->dispatchRequest(new \WP_REST_Request('GET', '/fluent-cart/v2/tax/country-tax-id/US'));
        $this->assertSame(200, $empty->get_status());
        $this->assertSame('', (string) Arr::get($empty->get_data(), 'tax_data.tax_id'),
            'Unset country tax id reads back as an empty string');

        $saveUs = new \WP_REST_Request('POST', '/fluent-cart/v2/tax/country-tax-id/US');
        $saveUs->set_body_params(['tax_id' => 'US-EIN-123']);
        $this->assertSame(200, $this->dispatchRequest($saveUs)->get_status());

        $saveDe = new \WP_REST_Request('POST', '/fluent-cart/v2/tax/country-tax-id/DE');
        $saveDe->set_body_params(['tax_id' => 'DE-VAT-999']);
        $this->assertSame(200, $this->dispatchRequest($saveDe)->get_status());

        $readUs = $this->dispatchRequest(new \WP_REST_Request('GET', '/fluent-cart/v2/tax/country-tax-id/US'));
        $this->assertSame('US-EIN-123', (string) Arr::get($readUs->get_data(), 'tax_data.tax_id'),
            'The US tax id reads back exactly as saved — not overwritten by the DE save');

        $meta = \FluentCart\App\Models\Meta::query()
            ->where('meta_key', 'fluent_cart_tax_id_US')
            ->where('object_type', 'tax')
            ->first();
        $this->assertNotNull($meta, 'The tax id persists under the country-scoped fct_meta key');
        $this->assertSame('US-EIN-123', (string) Arr::get($meta->meta_value, 'tax_id'));
    }

    public function test_configuration_get_settings_returns_tax_keys(): void
    {
        $this->enableTax('included', 'shipping');

        $response = $this->dispatchRequest(new \WP_REST_Request('GET', '/fluent-cart/v2/tax/configuration/settings'));
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();

        // Controller returns settings under either top level or a `settings` key; both arrangements should expose the flags.
        $settings = is_array($data) ? ($data['settings'] ?? $data) : [];
        $flat = json_encode($settings);
        $this->assertStringContainsString('"enable_tax"', $flat);
        $this->assertStringContainsString('"tax_inclusion"', $flat);
    }

    public function test_configuration_save_settings_persists_enable_tax(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/tax/configuration/settings');
        $request->set_body_params([
            // saveSettings reads the payload from a top-level `settings` key.
            'settings' => [
                'enable_tax'            => 'yes',
                'tax_inclusion'         => 'excluded',
                'tax_calculation_basis' => 'billing',
                'tax_rounding'          => 'item',
            ],
        ]);
        $response = $this->dispatchRequest($request);
        $this->assertSame(200, $response->get_status());

        $stored = get_option('fluent_cart_tax_configuration_settings');
        $this->assertIsArray($stored, 'Settings are persisted to wp_options');
        $this->assertSame('yes', Arr::get($stored, 'enable_tax'));
        $this->assertSame('excluded', Arr::get($stored, 'tax_inclusion'));
        $this->assertSame('billing', Arr::get($stored, 'tax_calculation_basis'));
        $this->assertSame('item', Arr::get($stored, 'tax_rounding'));
    }

    /**
     * BEHAVIOR CHANGED since this test was written: saveSettings does not
     * REJECT an invalid enable_tax — it NORMALIZES every enum-shaped setting
     * to the first allowed value and returns 200. Name frozen for baseline
     * identity; Phase 5 Migration Protocol renames it.
     */
    public function test_configuration_save_settings_rejects_invalid_enable_tax(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/tax/configuration/settings');
        $request->set_body_params([
            'settings' => [
                'enable_tax'    => 'maybe',
                'tax_inclusion' => 'sideways',
            ],
        ]);
        $response = $this->dispatchRequest($request);
        $this->assertSame(200, $response->get_status());

        // Invalid enum members are coerced to the first allowed value, not rejected.
        $stored = get_option('fluent_cart_tax_configuration_settings');
        $this->assertSame('yes', Arr::get($stored, 'enable_tax'),
            "enable_tax='maybe' is normalized to the first allowed value");
        $this->assertSame('included', Arr::get($stored, 'tax_inclusion'),
            "tax_inclusion='sideways' is normalized to the first allowed value");
    }

    public function test_taxes_index_returns_paginated_shape(): void
    {
        $rowA = OrderTaxRate::query()->create([
            'order_id' => 1, 'tax_rate_id' => 0, 'order_tax' => 500, 'total_tax' => 500,
            'meta'     => ['label' => 'US Sales Tax', 'tax_country' => 'US'],
        ]);
        $rowB = OrderTaxRate::query()->create([
            'order_id' => 2, 'tax_rate_id' => 0, 'order_tax' => 1900, 'total_tax' => 1900,
            'meta'     => ['label' => 'DE VAT', 'tax_country' => 'DE'],
        ]);

        // The /taxes group root only matches WITHOUT a trailing slash.
        $response = $this->dispatchRequest(new \WP_REST_Request('GET', '/fluent-cart/v2/taxes'));
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('taxes', $data, 'index responds with a paginated taxes collection');

        // The paginator must actually carry the seeded accounting records —
        // an empty ['taxes' => []] response is a broken filing report, not a pass.
        $taxes = $data['taxes'];
        $taxes = is_object($taxes) && method_exists($taxes, 'toArray') ? $taxes->toArray() : (array) $taxes;
        $this->assertArrayHasKey('data', $taxes, 'Paginator payload exposes a data page');
        $this->assertArrayHasKey('total', $taxes, 'Paginator payload exposes the total row count');
        $this->assertSame(2, (int) $taxes['total'], 'Both seeded OrderTaxRate rows are counted');
        $returnedIds = array_map(function ($item) {
            $item = is_object($item) && method_exists($item, 'toArray') ? $item->toArray() : (array) $item;
            return (int) ($item['id'] ?? 0);
        }, (array) $taxes['data']);
        $this->assertContains((int) $rowA->id, $returnedIds, 'Seeded US row appears in the page data');
        $this->assertContains((int) $rowB->id, $returnedIds, 'Seeded DE row appears in the page data');
    }

    public function test_taxes_mark_as_filed_requires_ids(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/taxes');
        $request->set_body_params([]);
        $response = $this->dispatchRequestSilently($request);
        $this->assertSame(400, $response->get_status(), 'Empty ids payload is rejected');
    }

    public function test_taxes_mark_as_filed_accepts_ids_payload(): void
    {
        $selected = OrderTaxRate::query()->create([
            'order_id'    => 1,
            'tax_rate_id' => 0,
            'order_tax'   => 500,
            'total_tax'   => 500,
            'meta'        => [],
        ]);
        // A second UNFILED row that is NOT submitted: losing the
        // whereIn('id', $ids) restriction would file every outstanding
        // record — a partial filing action must touch only its ids.
        $bystander = OrderTaxRate::query()->create([
            'order_id'    => 2,
            'tax_rate_id' => 0,
            'order_tax'   => 700,
            'total_tax'   => 700,
            'meta'        => [],
        ]);
        $this->assertNull(OrderTaxRate::query()->find($selected->id)->filed_at);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/taxes');
        $request->set_body_params(['ids' => [$selected->id]]);
        $response = $this->dispatchRequest($request);
        $this->assertSame(200, $response->get_status());

        $this->assertNotNull(OrderTaxRate::query()->find($selected->id)->filed_at,
            'markAsFiled stamps filed_at on the targeted row');
        $this->assertNull(OrderTaxRate::query()->find($bystander->id)->filed_at,
            'An unfiled row whose id was NOT submitted stays unfiled');
    }

    public function test_mark_as_filed_is_idempotent_on_already_filed_rows(): void
    {
        // A back-to-back mark/re-mark comparison cannot see an overwrite:
        // both writes would land in the same DATETIME second. Seed a row
        // already filed at a FIXED historical instant instead — any re-stamp
        // with now() is then an unmissable difference.
        $historicalFiledAt = '2026-01-01 00:00:00';
        $row = OrderTaxRate::query()->create([
            'order_id'    => 1,
            'tax_rate_id' => 0,
            'order_tax'   => 500,
            'total_tax'   => 500,
            'meta'        => [],
            'filed_at'    => $historicalFiledAt,
        ]);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/taxes');
        $request->set_body_params(['ids' => [$row->id]]);
        $this->assertSame(200, $this->dispatchRequest($request)->get_status(),
            'Re-marking an already filed row still succeeds');

        $fresh = OrderTaxRate::query()->find($row->id);
        $this->assertSame(
            $historicalFiledAt,
            date('Y-m-d H:i:s', strtotime((string) $fresh->filed_at)),
            'filed_at keeps its original filing timestamp: the update only targets rows whose filed_at is NULL'
        );
    }

    public function test_user_without_store_caps_gets_403(): void
    {
        $subscriberId = self::factory()->user->create(['role' => 'subscriber']);

        $read = $this->dispatchRequestAs($subscriberId, new \WP_REST_Request('GET', '/fluent-cart/v2/tax/classes'));
        $this->assertSame(403, $read->get_status(),
            'A subscriber without store settings caps cannot read the tax surface');

        // Spec 169 is about WRITES: a valid create payload as the subscriber
        // must be rejected AND leave no row behind — a green read-only check
        // would miss a policy regression scoped to mutating verbs.
        $write = new \WP_REST_Request('POST', '/fluent-cart/v2/tax/classes');
        $write->set_body_params(['title' => 'Forbidden Class']);
        $writeResponse = $this->dispatchRequestAs($subscriberId, $write);
        $this->assertSame(403, $writeResponse->get_status(),
            'A subscriber without store settings caps cannot create a tax class');
        $this->assertSame(0, TaxClass::query()->where('title', 'Forbidden Class')->count(),
            'The rejected write persisted nothing');
    }

    public function test_unauthenticated_gets_401(): void
    {
        $read = $this->dispatchRequestAsGuest(new \WP_REST_Request('GET', '/fluent-cart/v2/tax/classes'));
        $this->assertSame(401, $read->get_status(),
            'An anonymous request to a tax endpoint is rejected as unauthenticated');

        // Writes need their own oracle: a verb-scoped policy regression could
        // reject reads yet accept mutations. Valid payload, expect 401 + no row.
        $write = new \WP_REST_Request('POST', '/fluent-cart/v2/tax/classes');
        $write->set_body_params(['title' => 'Anonymous Class']);
        $writeResponse = $this->dispatchRequestAsGuest($write);
        $this->assertSame(401, $writeResponse->get_status(),
            'An anonymous tax write is rejected as unauthenticated');
        $this->assertSame(0, TaxClass::query()->where('title', 'Anonymous Class')->count(),
            'The rejected anonymous write persisted nothing');
    }

    // =========================================================================
    // Spec 171-174: AJAX endpoints
    // =========================================================================

    public function test_validate_vat_without_nonce_returns_error(): void
    {
        unset($_REQUEST['_wpnonce']);

        $result = WpDieCapture::capture(function () {
            (new \FluentCart\App\Modules\Tax\TaxModule())->handleVatValidation();
        });

        $this->assertTrue($result['died'], 'wp_send_json terminates the request');
        $this->assertSame(403, $result['status']);
        $this->assertIsArray($result['json']);
        $this->assertStringContainsString('Security check failed', (string) Arr::get($result['json'], 'message'));
    }

    public function test_remove_vat_falls_through_without_cart_hash(): void
    {
        // Without fct_cart_hash the handler falls back to CartResource::get(),
        // which resolves the LOGGED-IN USER's latest global cart. Seed VAT
        // state on the admin's cart AND on a competing cart owned by another
        // user: the fallback must strip only the caller's cart — losing the
        // user scoping would let this nopriv AJAX path mutate another
        // shopper's checkout.
        $vatTaxData = [
            'valid'      => true,
            'vat_number' => '123456789',
            'name'       => 'Acme GmbH',
            'address'    => 'Berlin',
            'country'    => 'DE',
        ];
        $checkoutData = [
            'form_data' => ['billing_country' => 'DE'],
            'tax_data'  => $vatTaxData,
        ];

        $ownCart = (new \Tests\Support\Factory\CartFactory())->create([
            'user_id'       => self::$persistentAdminId,
            'checkout_data' => $checkoutData,
        ]);
        $otherUserId = self::factory()->user->create(['role' => 'subscriber']);
        $otherCart = (new \Tests\Support\Factory\CartFactory())->create([
            'user_id'       => $otherUserId,
            'checkout_data' => $checkoutData,
        ]);
        \FluentCart\Api\Resource\FrontendResource\CartResource::resetCartCache();

        $_REQUEST['_wpnonce'] = wp_create_nonce('fluentcart');
        unset($_REQUEST['fct_cart_hash']);

        try {
            $result = WpDieCapture::capture(function () {
                (new \FluentCart\App\Modules\Tax\TaxModule())->removeVat();
            });
        } finally {
            unset($_REQUEST['_wpnonce']);
        }

        $this->assertTrue($result['died'], 'removeVat responds via wp_send_json');
        $this->assertIsArray($result['json']);
        $this->assertTrue((bool) Arr::get($result['json'], 'success'),
            'Without fct_cart_hash the handler falls through to the current session cart and succeeds');

        $ownFresh = \FluentCart\App\Models\Cart::query()->find($ownCart->cart_hash);
        foreach (['valid', 'vat_number', 'name', 'address', 'country'] as $vatKey) {
            $this->assertNull(Arr::get($ownFresh->checkout_data, "tax_data.{$vatKey}"),
                "The caller's own cart has VAT field '{$vatKey}' removed");
        }

        $otherFresh = \FluentCart\App\Models\Cart::query()->find($otherCart->cart_hash);
        $this->assertSame('123456789', (string) Arr::get($otherFresh->checkout_data, 'tax_data.vat_number'),
            "Another user's cart keeps its VAT state — the hashless fallback must stay scoped to the caller");
        $this->assertTrue((bool) Arr::get($otherFresh->checkout_data, 'tax_data.valid'),
            "Another user's cart VAT validity flag is untouched");
    }

    public function test_price_suffix_atts_filter_registered(): void
    {
        $this->enableTax();
        (new \FluentCart\App\Modules\Tax\TaxModule())->register();
        // Since #2271 the price_suffix_atts filter is attached transiently inside the
        // after_price action (self-removing on first apply), not permanently at register().
        do_action('fluent_cart/product/after_price', [
            'product' => null,
            'variant' => null,
            'scope'   => 'product_variant_price',
        ]);
        $this->assertNotFalse(has_filter('fluent_cart/product/price_suffix_atts'));
    }

    public function test_price_suffix_context_filter_injects_tax_inclusion(): void
    {
        $this->enableTax('included');

        (new \FluentCart\App\Modules\Tax\TaxModule())->register();

        do_action('fluent_cart/product/after_price', [
            'product' => null,
            'variant' => null,
            'scope'   => 'product_variant_price',
        ]);

        $context = apply_filters('fluent_cart/product/price_suffix_context', []);

        $this->assertArrayHasKey('tax_inclusion', $context);
        $this->assertSame('included', $context['tax_inclusion']);
    }

    public function test_b2b_extra_fields_action_registered(): void
    {
        $this->enableTax();
        (new \FluentCart\App\Modules\Tax\TaxModule())->register();
        $this->assertNotFalse(has_action('fluent_cart/checkout/b2b_extra_fields'));
    }

    public function test_before_summary_total_action_registered(): void
    {
        $this->enableTax();
        (new \FluentCart\App\Modules\Tax\TaxModule())->register();
        $this->assertNotFalse(has_action('fluent_cart/checkout/before_summary_total'));
    }

    public function test_after_patch_checkout_data_fragments_filter_registered(): void
    {
        $this->enableTax();
        (new \FluentCart\App\Modules\Tax\TaxModule())->register();
        $this->assertNotFalse(has_filter('fluent_cart/checkout/after_patch_checkout_data_fragments'));
    }

    // =========================================================================
    // Spec 180-190: Edge cases
    // =========================================================================

    public function test_empty_cart_produces_empty_tax_data(): void
    {
        $this->enableTax();
        $fillData = [
            'cart_data'     => [],
            'checkout_data' => ['form_data' => ['billing_country' => 'US']],
        ];
        $out = (new \FluentCart\App\Modules\Tax\TaxModule())->calculateCartTax($fillData);
        $this->assertSame(0, (int) $out['checkout_data']['tax_data']['tax_total']);
    }

    public function test_decimal_rate_calculates_correctly(): void
    {
        $this->enableTax('excluded');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '20.5']);

        $line = $this->calcLine($product->ID, 'US');
        // $100.00 * 20.5% exclusive = $20.50 exactly (no rounding remainder).
        $this->assertSame(2050, (int) $line['tax_amount']);
    }

    public function test_zero_rate_class_produces_zero_tax_line(): void
    {
        $this->enableTax('excluded');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '0']);

        $line = $this->calcLine($product->ID, 'US');
        $this->assertSame(0, (int) $line['tax_amount'], 'A 0% rate yields zero tax');
        $this->assertNotEmpty(Arr::get($line, 'line_meta.tax_config.rates'),
            'The matched 0% rate still produces a tax line entry');
    }

    public function test_deleting_tax_class_preserves_stored_order_tax_rate(): void
    {
        $this->ensureStandardTaxClass();
        $class = $this->classFactory->create();
        $rate  = $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10']);

        $orderTax = OrderTaxRate::query()->create([
            'order_id'    => 1,
            'tax_rate_id' => $rate->id,
            'order_tax'   => 1000,
            'total_tax'   => 1000,
            'meta'        => ['label' => 'US Sales Tax', 'tax_country' => 'US'],
        ]);

        $response = $this->dispatchRequest(new \WP_REST_Request('DELETE', '/fluent-cart/v2/tax/classes/' . $class->id));
        $this->assertSame(200, $response->get_status());
        $this->assertNull(TaxClass::query()->find($class->id), 'The class row itself is gone');

        $fresh = OrderTaxRate::query()->find($orderTax->id);
        $this->assertNotNull($fresh, 'Stored order tax record survives the class deletion');
        $this->assertSame(1000, (int) $fresh->total_tax, 'Captured tax amount is not retroactively altered');
        $this->assertSame('US Sales Tax', (string) Arr::get($fresh->meta, 'label'),
            'The meta snapshot taken at order time is preserved');
    }

    /**
     * REST createClass rejects duplicate titles outright now, so suffixing is
     * only reachable at the model layer — pin it there: three saves of the
     * same title produce base, base-2, base-3.
     */
    public function test_slug_collision_after_three_saves_produces_suffix_3(): void
    {
        $first  = TaxClass::query()->create(['title' => 'Luxury Goods']);
        $second = TaxClass::query()->create(['title' => 'Luxury Goods']);
        $third  = TaxClass::query()->create(['title' => 'Luxury Goods']);

        $this->assertSame('luxury-goods', $first->slug);
        $this->assertSame('luxury-goods-2', $second->slug, 'Second save gets suffix -2');
        $this->assertSame('luxury-goods-3', $third->slug, 'Third save gets suffix -3');
    }

    /**
     * A product in vat_reverse_excluded_categories blocks reverse charge:
     * B2B VAT validation succeeds but tax stays fully charged.
     */
    public function test_reverse_charge_excluded_category_keeps_full_tax(): void
    {
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'DE', 'rate' => '19']);
        $term = $this->attachProductToNewCategoryTerm($product->ID);

        $this->setVatNumberFieldEnabled(true);
        $this->enableTax('excluded', 'billing', 'item', [
            'eu_vat_settings' => [
                'method'                          => 'oss',
                'local_reverse_charge'            => 'yes',
                'reverse_charge_price_mode'       => 'fixed',
                'vat_reverse_excluded_categories' => [$term['term_taxonomy_id']],
            ],
        ]);
        $this->setStoreCountry('DE');

        $cart = $this->makeVatCheckoutCart(
            [$this->buildCartLineForProduct($product->ID)],
            ['form_data' => ['billing_country' => 'DE']]
        );
        $this->primeVatAjaxRequest($cart, 'DE123456789');
        $removeMock = $this->mockVatValidation([
            'country' => 'DE', 'vat_number' => '123456789', 'valid' => true,
            'name' => 'Acme GmbH', 'address' => 'Berlin',
        ]);

        $module = $this->makeTaxModule();
        $result = WpDieCapture::capture(function () use ($module) {
            $module->handleVatValidation();
        });
        $removeMock();
        $this->clearVatAjaxRequest();

        $this->assertSame(200, $result['status'], 'VAT validation itself still succeeds');
        $stored  = \FluentCart\App\Models\Cart::query()->find($cart->cart_hash);
        $taxData = Arr::get($stored->checkout_data, 'tax_data', []);
        $this->assertSame(1900, (int) Arr::get($taxData, 'tax_total'),
            'Tax stays fully charged — the excluded category blocks reverse charge');
    }

    /**
     * Switching tax_calculation_basis changes which address feeds the NEXT
     * cart update — exercised through the real trigger path: the registered
     * fluent_cart/cart/cart_data_items_updated listener (recalculateTax)
     * must recalc AND persist the cart, not just return numbers.
     */
    public function test_basis_switch_recalculates_on_next_update(): void
    {
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10']);

        $cart = (new \Tests\Support\Factory\CartFactory())->create([
            'user_id'       => self::$persistentAdminId,
            'cart_data'     => [$this->buildLineItem($product->ID)],
            'checkout_data' => [
                'form_data' => [
                    'billing_country'  => 'US',
                    'shipping_country' => 'DE',
                    'ship_to_different'=> 'yes',
                ],
            ],
        ]);

        $this->enableTax('excluded', 'billing');
        $billingModule = new \FluentCart\App\Modules\Tax\TaxModule();
        $billingModule->register();
        do_action('fluent_cart/cart/cart_data_items_updated', ['cart' => $cart]);

        $persisted = \FluentCart\App\Models\Cart::query()->find($cart->cart_hash);
        $this->assertSame(1000, (int) Arr::get($persisted->checkout_data, 'tax_data.tax_total'),
            'Billing basis taxes the US billing address at 10% — recalculated AND persisted by the cart-update listener');

        // TaxModule captures settings at construction; in production the
        // "next cart update" arrives in a NEW request with a fresh module.
        // Simulate that: retire the old listener, register a fresh module.
        remove_action('fluent_cart/cart/cart_data_items_updated', [$billingModule, 'recalculateTax']);
        $this->enableTax('excluded', 'shipping');
        $this->resetTaxRuntimeState();
        (new \FluentCart\App\Modules\Tax\TaxModule())->register();
        do_action('fluent_cart/cart/cart_data_items_updated', ['cart' => $persisted]);

        $persisted = \FluentCart\App\Models\Cart::query()->find($cart->cart_hash);
        $this->assertSame(0, (int) Arr::get($persisted->checkout_data, 'tax_data.tax_total'),
            'After switching to shipping basis, the next cart update persists zero tax for the untaxed DE address');
    }

    public function test_get_shipping_tax_zero_when_all_charges_zero(): void
    {
        $this->enableTax();
        $calc = new \FluentCart\App\Modules\Tax\TaxCalculator(
            [[
                'post_id'        => 0,
                'quantity'       => 1,
                'subtotal'       => 10000,
                'discount_total' => 0,
                'shipping_charge'=> 0,
                'other_info'     => [],
                'line_meta'      => [],
            ]],
            ['country' => 'US']
        );
        $this->assertSame(0, (int) $calc->getShippingTax());
    }

    public function test_calculate_cart_tax_with_only_non_taxable_fee_is_empty(): void
    {
        $this->enableTax();
        $fillData = [
            'cart_data'     => [],
            'checkout_data' => [
                'form_data' => ['billing_country' => 'US'],
                'fees'      => [['taxable' => false, 'amount' => 100, 'name' => 'Nothing']],
            ],
        ];
        $out = (new \FluentCart\App\Modules\Tax\TaxModule())->calculateCartTax($fillData);
        $this->assertSame(0, (int) $out['checkout_data']['tax_data']['tax_total']);
        $this->assertSame(0, (int) $out['checkout_data']['tax_data']['fee_tax']);
    }


    /**
     * SURFACE REMOVED: the PUT classes/{id} route (TaxClassController::update)
     * no longer exists — class renames are not exposed over REST anymore.
     * The frozen identity now pins the ABSENCE of the route so it cannot
     * quietly reappear half-wired.
     */
    public function test_tax_classes_update_route_registered(): void
    {
        $this->assertRouteNotRegistered('put', 'classes/{id}');

        $class = $this->classFactory->create();
        $request = new \WP_REST_Request('PUT', '/fluent-cart/v2/tax/classes/' . $class->id);
        $request->set_body_params(['title' => 'Renamed']);
        $response = $this->dispatchRequestSilently($request);
        $this->assertSame(404, $response->get_status(),
            'Dispatching the removed update route returns 404');
    }
}

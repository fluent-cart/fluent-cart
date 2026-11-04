<?php

namespace Tests\Integration\Modules\Shipping;

use FluentCart\App\Helpers\AddressHelper;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Shipping\SourcedStubCarrier;
use Tests\Support\Shipping\StubShippingCarrier;

/**
 * Progressive checkout rates: a live carrier that splits its quote into rate
 * sources is never called while the list renders; checkout fetches each pending
 * source on its own, and each source caches, backs off and spends budget alone.
 */
class LiveRateSourcesTest extends DatabaseTestCase
{
    private const DESTINATION = ['postcode' => '10001', 'city' => 'New York'];

    public function setUp(): void
    {
        parent::setUp();
        AddressHelper::resetRateCache();
        $this->clearLiveRateTransients();
        SourcedStubCarrier::install(['ups' => 'UPS', 'fedex' => 'FedEx'], [
            'ups'   => [SourcedStubCarrier::rate('carrier:stub_sourced:ups:ground', 1500)],
            'fedex' => [SourcedStubCarrier::rate('carrier:stub_sourced:fedex:home', 1200)],
        ]);
    }

    public function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        SourcedStubCarrier::uninstall();
        StubShippingCarrier::uninstall();
        AddressHelper::resetRateCache();
        $this->clearLiveRateTransients();
        parent::tearDown();
    }

    public function test_the_rate_list_calls_no_source_and_lists_each_as_pending(): void
    {
        $rates = $this->rateIds();

        $this->assertSame([], SourcedStubCarrier::$fetches, 'no carrier call while the list renders');
        $this->assertSame([], $rates);

        $state = AddressHelper::rateSourceState();
        $this->assertSame([
            ['carrier' => 'stub_sourced', 'source' => 'ups', 'label' => 'UPS'],
            ['carrier' => 'stub_sourced', 'source' => 'fedex', 'label' => 'FedEx'],
        ], $state['pending']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $state['key']);
    }

    public function test_fetching_one_source_calls_only_it_and_later_lists_include_it(): void
    {
        $rates = AddressHelper::fetchRateSource(SourcedStubCarrier::SLUG, 'fedex', 'US', 'NY', [], self::DESTINATION);

        $this->assertSame(['fedex'], SourcedStubCarrier::$fetches);
        $this->assertSame(['carrier:stub_sourced:fedex:home'], array_column($rates, 'id'));
        $this->assertSame('fedex', $rates[0]['rate_source']);
        $this->assertSame(SourcedStubCarrier::SLUG, $rates[0]['carrier']);
        $keyAfterFetch = AddressHelper::rateSourceState()['key'];

        AddressHelper::resetRateCache();
        SourcedStubCarrier::$fetches = [];

        $this->assertSame(['carrier:stub_sourced:fedex:home'], $this->rateIds(), 'the cached source shows at once');
        $this->assertSame([], SourcedStubCarrier::$fetches, 'from the cache, with no call');
        $this->assertSame(['ups'], array_column(AddressHelper::rateSourceState()['pending'], 'source'));
        $this->assertSame($keyAfterFetch, AddressHelper::rateSourceState()['key'], 'the fetch answers for the list it was asked from');
    }

    public function test_a_source_the_carrier_does_not_declare_is_never_fetched(): void
    {
        $this->assertSame([], AddressHelper::fetchRateSource(SourcedStubCarrier::SLUG, 'dhl', 'US', 'NY', [], self::DESTINATION));
        $this->assertSame([], AddressHelper::fetchRateSource('no_such_carrier', 'ups', 'US', 'NY', [], self::DESTINATION));

        SourcedStubCarrier::$enabled = false;
        $this->assertSame([], AddressHelper::fetchRateSource(SourcedStubCarrier::SLUG, 'ups', 'US', 'NY', [], self::DESTINATION));

        $this->assertSame([], SourcedStubCarrier::$fetches);
    }

    public function test_a_failing_source_backs_off_alone(): void
    {
        SourcedStubCarrier::$answers['ups'] = new \WP_Error('timeout', 'no answer', ['http_code' => 0]);

        $this->assertSame([], AddressHelper::fetchRateSource(SourcedStubCarrier::SLUG, 'ups', 'US', 'NY', [], self::DESTINATION));
        $this->assertNotEmpty(AddressHelper::fetchRateSource(SourcedStubCarrier::SLUG, 'fedex', 'US', 'NY', [], self::DESTINATION), 'the other source still answers');

        AddressHelper::resetRateCache();
        SourcedStubCarrier::$fetches = [];
        $this->assertSame([], AddressHelper::fetchRateSource(SourcedStubCarrier::SLUG, 'ups', 'US', 'NY', [], ['postcode' => '90210', 'city' => 'Beverly Hills']));
        $this->assertSame([], SourcedStubCarrier::$fetches, 'the failed source is not called again during its backoff, at any address');

        AddressHelper::resetRateCache();
        $this->assertSame(['carrier:stub_sourced:fedex:home'], $this->rateIds());
        $this->assertSame([], AddressHelper::rateSourceState()['pending'], 'a source in backoff is not offered for fetching');
    }

    public function test_the_visitor_budget_counts_every_source_fetch(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        SourcedStubCarrier::$budget = 1; // one full quote = one fetch per source

        AddressHelper::fetchRateSource(SourcedStubCarrier::SLUG, 'ups', 'US', 'NY', [], self::DESTINATION);
        AddressHelper::fetchRateSource(SourcedStubCarrier::SLUG, 'fedex', 'US', 'NY', [], self::DESTINATION);
        $this->assertSame(['ups', 'fedex'], SourcedStubCarrier::$fetches);

        AddressHelper::resetRateCache();
        $this->assertSame([], AddressHelper::fetchRateSource(SourcedStubCarrier::SLUG, 'ups', 'US', 'NY', [], ['postcode' => '90210', 'city' => 'Beverly Hills']));
        $this->assertSame(['ups', 'fedex'], SourcedStubCarrier::$fetches, 'a third fetch is over the budget');
    }

    public function test_place_order_answers_from_quotes_and_never_calls_a_source(): void
    {
        AddressHelper::fetchRateSource(SourcedStubCarrier::SLUG, 'ups', 'US', 'NY', [], self::DESTINATION);
        AddressHelper::resetRateCache();
        SourcedStubCarrier::$fetches = [];

        AddressHelper::reuseCarrierQuotes();
        $this->assertSame(['carrier:stub_sourced:ups:ground'], $this->rateIds());
        $this->assertSame([], SourcedStubCarrier::$fetches, 'the uncached source is not called at Place Order');
    }

    public function test_a_carrier_without_sources_still_quotes_once_while_the_list_renders(): void
    {
        SourcedStubCarrier::$sources = [];
        SourcedStubCarrier::$answers = ['' => [SourcedStubCarrier::rate('carrier:stub_sourced:all', 900)]];

        $this->assertSame(['carrier:stub_sourced:all'], $this->rateIds());
        $this->assertSame([''], SourcedStubCarrier::$fetches, 'one unsplit quote, as before');
        $this->assertSame([], AddressHelper::rateSourceState()['pending']);
    }

    public function test_a_caller_that_does_not_defer_gets_the_one_unsplit_quote(): void
    {
        SourcedStubCarrier::$answers[''] = [SourcedStubCarrier::rate('carrier:stub_sourced:all', 900)];
        $carrier = new SourcedStubCarrier(new \Tests\Support\Shipping\SourcedStubSettings());

        $rates = $carrier->getRates(['country' => 'US', 'state' => 'NY', 'postcode' => '10001', 'city' => 'New York']);

        $this->assertSame(['carrier:stub_sourced:all'], array_column($rates, 'id'));
        $this->assertSame([''], SourcedStubCarrier::$fetches, 'a direct caller keeps the single quote');
        $this->assertSame([], $carrier->getPendingRateSources());
    }

    public function test_a_carrier_without_the_trait_gets_the_same_call(): void
    {
        SourcedStubCarrier::$enabled = false;
        StubShippingCarrier::install([[
            'id' => 'stub:ground', 'title' => 'Stub Ground', 'cost' => 1000, 'currency' => 'USD', 'states' => [],
        ]]);

        $this->assertSame(['stub:ground'], $this->rateIds());
        $this->assertCount(1, StubShippingCarrier::$calls);
        $this->assertArrayNotHasKey('fetch_source', StubShippingCarrier::$calls[0]);
    }

    private function rateIds(): array
    {
        $rates = AddressHelper::getShippingMethods('US', 'NY', null, [], self::DESTINATION);

        return is_wp_error($rates) ? [] : array_column($rates, 'id');
    }

    private function clearLiveRateTransients(): void
    {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%fluent\\_cart\\_live\\_rates\\_%'");
        wp_cache_flush();
    }
}

<?php

namespace Tests\Integration\Services\Report;

use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Http\Controllers\Reports\SourceReportController;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderOperation;
use FluentCart\App\Services\Report\ReportHelper;
use FluentCart\App\Services\Report\SourceReportService;
use FluentCart\Framework\Database\Query\Builder as Query;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * Backend plumbing for the Order Sources advanced filter.
 *
 * The free plugin only has to (1) carry `filter_type` / `advanced_filters` from
 * the request through sanitisation into the processed params and (2) hand the
 * query plus those params to `fluent_cart/report/sources_query` so FluentCart
 * Pro can apply the conditions. Parsing/applying the filters is Pro's job, so
 * these tests use a local filter callback as the stand-in for Pro.
 */
class SourceReportAdvancedFilterTest extends WPTestCase
{
    private const HOOK = 'fluent_cart/report/sources_query';

    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private SourceReportService $service;

    /** @var callable[] */
    private array $registeredCallbacks = [];

    public function setUp(): void
    {
        parent::setUp();

        // The sources report aggregates over the whole fct_orders table inside the
        // date window, so stray committed rows would shift every group. Clear both
        // tables inside this test's transaction before seeding (TC-010 pattern).
        Order::query()->delete();
        OrderOperation::query()->delete();

        $this->customerFactory = new CustomerFactory();
        $this->orderFactory    = new OrderFactory();
        $this->service         = SourceReportService::make();
    }

    public function tearDown(): void
    {
        foreach ($this->registeredCallbacks as $callback) {
            remove_filter(self::HOOK, $callback, 10);
        }
        $this->registeredCallbacks = [];

        parent::tearDown();
    }

    private function onSourcesQuery(callable $callback): void
    {
        $this->registeredCallbacks[] = $callback;
        add_filter(self::HOOK, $callback, 10, 2);
    }

    /**
     * A paid order attributed to $utmSource, created now so it falls inside the
     * report window whether or not the date range is clamped for non-Pro.
     */
    private function paidOrderFromSource(string $utmSource, string $utmCampaign = 'spring'): Order
    {
        $order = $this->orderFactory->create([
            'customer_id'    => $this->customerFactory->create()->id,
            'type'           => Status::ORDER_TYPE_PAYMENT,
            'status'         => Status::ORDER_COMPLETED,
            'payment_status' => Status::PAYMENT_PAID,
            'currency'       => 'USD',
            'total_amount'   => Helper::toCent(100),
            'total_paid'     => Helper::toCent(100),
        ]);

        OrderOperation::create([
            'order_id'     => $order->id,
            'utm_source'   => $utmSource,
            'utm_medium'   => 'email',
            'utm_campaign' => $utmCampaign,
        ]);

        return $order;
    }

    /**
     * The report params the controller would produce, with a window wide enough
     * that it survives the non-Pro clamp in processParams() either way.
     */
    private function reportParams(array $overrides = []): array
    {
        return ReportHelper::processParams(array_merge([
            'startDate' => gmdate('Y-m-d', strtotime('-7 days')),
            'endDate'   => gmdate('Y-m-d', strtotime('+1 day')),
        ], $overrides), ['orderTypes', 'paymentStatus', 'filter_type', 'advanced_filters']);
    }

    private static function advancedFiltersJson(string $value = 'newsletter'): string
    {
        return json_encode([
            [
                [
                    'source'      => ['orders', 'utm_source'],
                    'operator'    => '=',
                    'value'       => $value,
                    'filter_type' => 'simple',
                ],
            ],
        ]);
    }

    // =========================================================================
    // (a) The hook fires with the query + params, and can constrain the results
    // =========================================================================

    public function test_a_callback_can_constrain_the_source_report_result_set(): void
    {
        $this->paidOrderFromSource('newsletter');
        $this->paidOrderFromSource('google');

        $unfiltered = $this->service->getSourceReportData($this->reportParams());
        $this->assertCount(2, $unfiltered, 'Both attributed orders should be reported before any filtering');

        $this->onSourcesQuery(function ($query, $params) {
            return $query->where('oo.utm_source', 'newsletter');
        });

        $filtered = $this->service->getSourceReportData($this->reportParams([
            'filter_type'      => 'advanced',
            'advanced_filters' => self::advancedFiltersJson(),
        ]));

        $this->assertCount(1, $filtered, 'A hook callback must be able to narrow the reported sources');
        $this->assertSame('newsletter', $filtered[0]->utm_source);
    }

    public function test_a_callback_returning_a_non_query_value_leaves_the_report_working(): void
    {
        $this->paidOrderFromSource('newsletter');

        $this->onSourcesQuery(function ($query, $params) {
            return null; // a broken Pro/third-party callback
        });

        $result = $this->service->getSourceReportData($this->reportParams());

        $this->assertCount(1, $result, 'A callback returning a non-builder must fall back to the unmodified query, not fatal');
        $this->assertSame('newsletter', $result[0]->utm_source);
    }

    // =========================================================================
    // (b) filter_type / advanced_filters survive sanitisation into $params
    // =========================================================================

    public function test_filter_type_and_advanced_filters_survive_process_params(): void
    {
        $json = self::advancedFiltersJson();

        $params = $this->reportParams([
            'filter_type'      => 'advanced',
            'advanced_filters' => $json,
        ]);

        $this->assertSame('advanced', $params['filter_type']);
        $this->assertSame($json, $params['advanced_filters'], 'A well-formed payload must survive sanitisation byte-for-byte');
    }

    /**
     * Free deliberately does NOT scrub the payload. Validating a filter condition
     * requires the option definitions, which live in Pro — and Pro rebuilds every
     * condition from those definitions, taking only `operator` and `value` from
     * the request. Decoding and running sanitize_text_field() over the values here
     * would mangle legitimate input (any '<' in a filter value) while adding no
     * guarantee, so the contract is: hold the type, pass the payload on.
     */
    public function test_advanced_filters_payload_is_passed_through_untouched(): void
    {
        $json = self::advancedFiltersJson('news<letter');

        $params = $this->reportParams([
            'filter_type'      => 'advanced',
            'advanced_filters' => $json,
        ]);

        $this->assertSame($json, $params['advanced_filters'], 'The payload must reach the consumer byte-for-byte, markup included');
    }

    public function test_advanced_filters_preserves_non_string_condition_values(): void
    {
        $json = json_encode([[['source' => ['orders', 'total_paid'], 'operator' => '>', 'value' => 1500]]]);

        $params = $this->reportParams(['advanced_filters' => $json]);

        $decoded = json_decode($params['advanced_filters'], true);

        $this->assertSame(1500, $decoded[0][0]['value'], 'Numeric filter values must keep their type — the filter engine compares against them');
    }

    /**
     * Core ships NO sanitization rule for advanced_filters. It cannot sanitize a
     * payload whose shape it does not know, so the value reaches the consumer in
     * whatever form it arrived and the consumer pushes its own rule in.
     */
    public function test_core_ships_no_rule_for_advanced_filters(): void
    {
        $groups = [[['source' => ['orders', 'utm_source'], 'operator' => '=', 'value' => 'newsletter']]];

        $params = $this->reportParams(['advanced_filters' => $groups]);

        $this->assertSame($groups, $params['advanced_filters'], 'Unruled keys are passed through by Sanitizer::sanitize() untouched');
    }

    /**
     * The extension point the consumer (Pro) uses to sanitize the payload core
     * deliberately leaves alone.
     */
    public function test_a_listener_can_push_a_sanitization_rule_for_advanced_filters(): void
    {
        $rule = function ($rules) {
            $rules['advanced_filters'] = function ($value) {
                return is_array($value) ? wp_json_encode($value) : (is_string($value) ? $value : '');
            };

            return $rules;
        };

        add_filter('fluent_cart/report/sanitize_params_rules', $rule);

        try {
            $groups = [[['source' => ['orders', 'utm_source'], 'operator' => '=', 'value' => 'newsletter']]];

            $params = $this->reportParams(['advanced_filters' => $groups]);

            $this->assertIsString($params['advanced_filters'], 'The pushed rule must run');
            $this->assertSame($groups, json_decode($params['advanced_filters'], true), 'The rule must not alter the conditions');

            $empty = $this->reportParams(['advanced_filters' => 12345]);

            $this->assertSame('', $empty['advanced_filters'], 'The pushed rule owns what an unusable value becomes');
        } finally {
            remove_filter('fluent_cart/report/sanitize_params_rules', $rule);
        }
    }

    /**
     * The rules map reaches listeners with core's own rules intact, so a listener
     * adds to the vocabulary rather than replacing it.
     */
    public function test_sanitize_rules_filter_receives_the_core_rules(): void
    {
        $seen = null;

        $spy = function ($rules) use (&$seen) {
            $seen = $rules;

            return $rules;
        };

        add_filter('fluent_cart/report/sanitize_params_rules', $spy);

        try {
            $this->reportParams(['filter_type' => 'advanced']);

            $this->assertIsArray($seen);
            $this->assertArrayHasKey('startDate', $seen, 'Core rules must survive to the listener');
            $this->assertArrayHasKey('filter_type', $seen);
            $this->assertArrayNotHasKey('advanced_filters', $seen, 'Core must not define a rule for a payload it does not understand');
        } finally {
            remove_filter('fluent_cart/report/sanitize_params_rules', $spy);
        }
    }

    /**
     * Malformed JSON is no longer rejected here: free cannot tell a malformed
     * payload from one whose shape it simply does not know. It travels to the
     * consumer, which decodes it and declines to apply anything.
     */
    public function test_malformed_advanced_filters_payload_reaches_the_consumer_unchanged(): void
    {
        $params = $this->reportParams(['advanced_filters' => '{not json at all']);

        $this->assertSame('{not json at all', $params['advanced_filters']);
    }

    /**
     * Documents the empirically verified behaviour this plumbing relies on:
     * Sanitizer::sanitize() mutates and returns the SAME array, so a key with no
     * rule is passed through untouched rather than dropped. That is exactly why
     * advanced_filters needs an explicit rule — without one the raw JSON would
     * still reach the params, just unsanitised.
     */
    public function test_sanitizer_passes_unruled_keys_through_untouched(): void
    {
        $params = $this->reportParams(['someUnknownKey' => '<b>kept</b>']);

        $this->assertArrayNotHasKey('someUnknownKey', $params, 'processParams() only exposes whitelisted keys');

        $sanitized = \FluentCart\Framework\Support\Sanitizer::sanitize(
            ['someUnknownKey' => '<b>kept</b>'],
            ['startDate' => 'sanitize_text_field']
        );

        $this->assertSame(
            ['someUnknownKey' => '<b>kept</b>'],
            $sanitized,
            'Sanitizer::sanitize() does not drop unknown keys — it returns the input array with the ruled keys rewritten'
        );
    }

    // =========================================================================
    // (c) The comparison-period query gets the same advanced filters
    // =========================================================================
}

<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\MCP\Tools\SubscriptionTools;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\SubscriptionFactory;

/**
 * list-subscriptions summary_only: aggregates across ALL matching subscriptions
 * (count by status, summed recurring_total, total remaining installments) with
 * no per-record array. Must honor the same filters and leave the normal
 * (non-summary) response untouched.
 */
class SubscriptionSummaryOnlyTest extends DatabaseTestCase
{
    private int $cid;
    private int $oid;

    public function setUp(): void
    {
        parent::setUp();

        // The aggregates scan the whole fct_subscriptions table (no
        // customer/order scope on the tool), so stray committed rows from
        // other suites would shift every count. Clear the table inside this
        // test's transaction before seeding the known fixture set.
        Subscription::query()->delete();

        $this->cid = (new CustomerFactory())->create()->id;
        $this->oid = (new OrderFactory())->create(['customer_id' => $this->cid])->id;

        // 2 active perpetual ($10), 1 active finite (5 of 8 installments left, $9),
        // 1 canceled ($5).
        $rows = [
            ['status' => 'active', 'recurring_total' => Helper::toCent(10), 'bill_times' => 0],
            ['status' => 'active', 'recurring_total' => Helper::toCent(10), 'bill_times' => 0],
            ['status' => 'active', 'recurring_total' => Helper::toCent(9), 'bill_times' => 8, 'bill_count' => 3],
            ['status' => 'canceled', 'recurring_total' => Helper::toCent(5), 'bill_times' => 0],
        ];
        foreach ($rows as $overrides) {
            (new SubscriptionFactory())->create(array_merge([
                'customer_id'     => $this->cid,
                'parent_order_id' => $this->oid,
                'product_id'      => 9801,
                'variation_id'    => 98011,
            ], $overrides));
        }
    }

    public function test_summary_only_returns_aggregates_without_records(): void
    {
        $res  = SubscriptionTools::listSubscriptions(['summary_only' => true]);
        $data = $res['data'];

        $this->assertArrayNotHasKey('subscriptions', $data, 'no per-record array in summary mode');
        $this->assertTrue($data['summary_only'], 'summary mode must flag itself in the payload');
        $this->assertSame(4, $data['total_count'], 'all seeded subscriptions are counted');
        $this->assertSame(Helper::toCent(34), Helper::toCent($data['recurring_total_sum']), '10 + 10 + 9 + 5');
        $this->assertSame(5, $data['remaining_installments_total'], '8 - 3 on the one finite plan');

        $this->assertSame(3, $data['count_by_status']['active']['count'], 'three active subscriptions');
        $this->assertSame(1, $data['count_by_status']['canceled']['count'], 'one canceled subscription');
        $this->assertSame(
            Helper::toCent(29),
            Helper::toCent($data['count_by_status']['active']['recurring_total_sum']),
            'active bucket sums 10 + 10 + 9'
        );
    }

    public function test_summary_only_survives_a_plan_billed_more_times_than_planned(): void
    {
        // bill_count > bill_times: both columns are unsigned, so a plain subtraction errors in MySQL
        (new SubscriptionFactory())->create([
            'customer_id' => $this->cid, 'parent_order_id' => $this->oid, 'product_id' => 9801, 'variation_id' => 98011,
            'status' => 'completed', 'recurring_total' => Helper::toCent(9), 'bill_times' => 3, 'bill_count' => 4,
        ]);

        $res = SubscriptionTools::listSubscriptions(['summary_only' => true]);

        $this->assertIsArray($res);
        $this->assertSame(5, $res['data']['remaining_installments_total'], 'The over-billed plan owes 0, not a negative count');
    }

    public function test_summary_only_honors_filters(): void
    {
        $res  = SubscriptionTools::listSubscriptions(['summary_only' => true, 'status' => 'active']);
        $data = $res['data'];

        $this->assertSame(3, $data['total_count'], 'status filter must scope the aggregate count');
        $this->assertArrayHasKey('active', $data['count_by_status'], 'filtered status bucket present');
        $this->assertArrayNotHasKey('canceled', $data['count_by_status'], 'filtered-out status bucket absent');
        $this->assertSame(
            Helper::toCent(29),
            Helper::toCent($data['recurring_total_sum']),
            'filtered aggregate sums only active plans: 10 + 10 + 9'
        );
        $this->assertSame(5, $data['remaining_installments_total'], 'the one finite plan is active, so its 8 - 3 remain');
    }

    public function test_without_summary_only_records_are_returned(): void
    {
        $res  = SubscriptionTools::listSubscriptions([]);
        $data = $res['data'];

        $this->assertArrayHasKey('subscriptions', $data, 'normal mode returns per-record rows');
        $this->assertCount(4, $data['subscriptions'], 'one row per seeded subscription');
        $this->assertArrayNotHasKey('summary_only', $data, 'normal mode carries no summary flag');

        $statuses = array_count_values(array_column($data['subscriptions'], 'status'));
        $this->assertSame(3, $statuses['active'] ?? 0, 'rows carry the seeded active statuses');
        $this->assertSame(1, $statuses['canceled'] ?? 0, 'rows carry the seeded canceled status');
        foreach ($data['subscriptions'] as $row) {
            $this->assertArrayHasKey('subscription_id', $row, 'every row keeps its subscription_id');
        }
    }
}

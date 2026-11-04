<?php
/**
 * Customer cohorts: a customer joins the cohort of the month they first paid (a free trial when it first pays, never
 * if it does not), stays retained while any subscription runs at a month's end, counts again after coming back,
 * counts once with two products, and the rows split by product and by billing interval.
 */

use FluentCart\App\App;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\RetentionSnapshotService;
use FluentCart\App\Services\Report\SubscriptionReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these rows.
$fcCohortCurrency = 'XTS';

$fcCohortSubscription = function ($id, $customer, $product, $interval, $cents, $status, $createdAt, array $extra = []) use ($fcCohortCurrency) {
    return (object) array_merge([
        'id'                => 1900000000 + $id,
        'customer_id'       => 1900000000 + $customer,
        'product_id'        => 1900000000 + $product,
        'parent_order_id'   => 1900000000 + $id,
        'currency'          => $fcCohortCurrency,
        'status'            => $status,
        'billing_interval'  => $interval,
        'recurring_total'   => $cents,
        'created_at'        => $createdAt,
        'canceled_at'       => null,
        'expire_at'         => null,
        'next_billing_date' => null,
        'updated_at'        => $createdAt,
        'trial_days'        => 0,
        'config'            => '{}',
    ], $extra);
};

return [
    [
        'id'            => 'reports-cohort-customers',
        'name'          => 'Cohorts count customers from their first payment, with comebacks, product and interval rows',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC', // fixtures are UTC days; reports-cohort-cancel-or-expiry covers other zones
        'run'           => function () use ($fcCohortCurrency, $fcCohortSubscription) {
            // A store updated from a release before the cohort columns (schema version 4, the old unique key) gets them
            // on its next admin load. The snapshot table holds derived rows only; migrate() restores it if this fails.
            global $wpdb;
            $table = $wpdb->prefix . 'fct_retention_snapshots';
            \FluentCart\Database\Migrations\RetentionSnapshotsMigrator::migrate(); // start from the current schema
            // The test needs the table to itself: dropping the cohort columns would collapse the store's rows into
            // duplicates and block both unique keys, and generated_at reads every row. The store's rows go back at the end.
            $storeRows = $wpdb->get_results("SELECT * FROM {$table}", ARRAY_A); // phpcs:ignore
            $restoreStoreRows = function () use ($wpdb, $table, &$storeRows) {
                foreach ($storeRows as $row) {
                    $wpdb->insert($table, $row);
                }
                $storeRows = [];
            };
            $wpdb->query("DELETE FROM {$table}"); // phpcs:ignore
            $schemaChecked = false;
            try {
                $wpdb->query("ALTER TABLE {$table} DROP INDEX {$wpdb->prefix}fct_rs_cohort_dims_idx"); // phpcs:ignore
                $wpdb->query("ALTER TABLE {$table} DROP COLUMN rebuy_adjusted, DROP COLUMN billing_interval"); // phpcs:ignore
                $wpdb->query("ALTER TABLE {$table} ADD UNIQUE INDEX {$wpdb->prefix}fct_rs_cohort_period_product_currency_idx (cohort, period, product_id, currency)"); // phpcs:ignore
                update_option('_fluent_cart_currency_schema_version', '4', false);
                \FluentCart\Database\DBMigrator::maybeMigrateCurrencySchema();
                FcTest::assertSame([\FluentCart\Database\DBMigrator::CURRENCY_SCHEMA_VERSION, true], [
                    get_option('_fluent_cart_currency_schema_version'),
                    \FluentCart\Database\Migrations\RetentionSnapshotsMigrator::hasCohortDimensions(),
                ], 'The schema upgrade adds the snapshot interval and rebuy columns and only then records itself done');
                $schemaChecked = true;
            } finally {
                \FluentCart\Database\Migrations\RetentionSnapshotsMigrator::migrate();
                if (!$schemaChecked) {
                    $restoreStoreRows();
                }
            }
            $db = App::db();
            $foreign = (int) $db->table('fct_retention_snapshots')->where('currency', $fcCohortCurrency)->count();
            if ($foreign !== 0) {
                throw new RuntimeException('Refusing to seed: ' . $foreign . ' retention snapshots already use currency ' . $fcCohortCurrency);
            }

            $service = new class extends RetentionSnapshotService {
                public function build(array $subscriptions, array $months, array $firstPaid, string $currency, bool $rebuyAdjusted = false): int
                {
                    $aggregates = $this->aggregate($subscriptions, $months, null, $firstPaid, $rebuyAdjusted, []);
                    // One range is already one row per snapshot key, so it publishes as is
                    $rows = $this->publishedRows($this->buildRows($aggregates[$currency] ?? [], $months, $currency, (int) $rebuyAdjusted));

                    return $this->insertBatch('fct_retention_snapshots', $rows);
                }
            };

            $months = ['2001-01', '2001-02', '2001-03', '2001-04', '2001-05', '2001-06', '2001-07', '2001-08'];
            $sub = $fcCohortSubscription;
            $subscriptions = [
                // Customer 1: monthly, canceled in March, back in June on a new subscription
                $sub(1, 1, 1, 'monthly', 1000, 'canceled', '2001-01-10 10:00:00', ['canceled_at' => '2001-03-20 10:00:00']),
                $sub(2, 1, 1, 'monthly', 1000, 'active', '2001-06-05 10:00:00'),
                // Customer 2: yearly, still active
                $sub(3, 2, 2, 'yearly', 12000, 'active', '2001-01-15 10:00:00'),
                // Customer 3: a free trial from February, first paid in March
                $sub(4, 3, 1, 'monthly', 1000, 'active', '2001-02-01 10:00:00', ['trial_days' => 14]),
                // Customer 4: a free trial that never paid
                $sub(5, 4, 1, 'monthly', 1000, 'canceled', '2001-02-03 10:00:00', ['trial_days' => 14, 'canceled_at' => '2001-02-10 10:00:00']),
                // Customer 5: two products; the yearly one canceled in April
                $sub(6, 5, 1, 'monthly', 500, 'active', '2001-01-03 10:00:00'),
                $sub(7, 5, 2, 'yearly', 6000, 'canceled', '2001-02-10 10:00:00', ['canceled_at' => '2001-04-02 10:00:00']),
                // Customer 6: canceled a monthly plan the day after buying (paid until 8 February), bought it again on 1 March
                $sub(8, 6, 3, 'monthly', 1000, 'canceled', '2001-01-08 10:00:00', ['canceled_at' => '2001-01-09 10:00:00']),
                $sub(9, 6, 3, 'monthly', 1000, 'active', '2001-03-01 10:00:00'),
            ];
            usort($subscriptions, function ($a, $b) {
                return [$a->customer_id, $a->created_at] <=> [$b->customer_id, $b->created_at];
            });
            $firstPaid = [1900000004 => '2001-03-01 10:00:00'];

            try {
                $service->build($subscriptions, $months, $firstPaid, $fcCohortCurrency);
                $service->build($subscriptions, $months, $firstPaid, $fcCohortCurrency, true);
                $read = function (array $extra = []) use ($fcCohortCurrency) {
                    $result = SubscriptionReportService::make()->getCohortData(array_merge([
                        'startDate' => new DateTime('2001-01-01 00:00:00'), 'endDate' => new DateTime('2001-08-31 23:59:59'),
                        'groupBy' => 'month', 'currency' => $fcCohortCurrency, 'maxPeriods' => 7,
                    ], $extra));
                    $rows = [];
                    foreach ($result['cohorts'] as $cohort) {
                        $rows[$cohort['cohort']] = [$cohort['start_count'], array_map(function ($period) {
                            return $period['retained_count'];
                        }, $cohort['periods'])];
                    }

                    return [$rows, $result];
                };

                [$all, $result] = $read();
                FcTest::assertSame(['2001-01', '2001-03'], array_keys($all), 'Cohorts start in January and March; the unpaid trial joins none, the paid one joins March');
                FcTest::assertSame([4, [3, 3, 3, 3, 4, 4, 4]], $all['2001-01'],
                    'January: 4 customers (two products count once); the canceled one is gone from March to May and back in June; '
                    . 'the next-day cancel is paid up through January, missing in February, back in March');
                FcTest::assertSame([1, [1, 1, 1, 1, 1, null, null]], $all['2001-03'], 'The trial that paid in March');

                $mrr = array_map(function ($period) {
                    return $period['retained_mrr'];
                }, $result['cohorts'][0]['periods']);
                FcTest::assertSame([35.0, [30.0, 30.0, 30.0, 30.0, 40.0, 40.0, 40.0]], [(float) $result['cohorts'][0]['start_mrr'], array_map('floatval', $mrr)],
                    'January MRR: 35 at the start; the second product (yearly, canceled in April, paid for the year) adds 5 from February; '
                    . 'the rebuy adds 10 from March; the comeback adds 10 in June');

                // Counting cancel-and-rebuy as kept: the customer who rebought around the year's end never left
                [$kept, $keptResult] = $read(['rebuyAdjusted' => true]);
                FcTest::assertSame([4, [4, 3, 3, 3, 4, 4, 4]], $kept['2001-01'], 'With rebuys counted, the next-day cancel that rebought has no gap in February');
                FcTest::assertSame([40.0, 30.0, 30.0, 30.0, 40.0, 40.0, 40.0], array_map(function ($period) {
                    return (float) $period['retained_mrr'];
                }, $keptResult['cohorts'][0]['periods']), 'Its MRR stays too');

                [$yearly] = $read(['billingInterval' => 'yearly']);
                FcTest::assertSame(['2001-01' => [1, [1, 1, 1, 1, 1, 1, 1]], '2001-02' => [1, [1, 1, 1, 1, 1, 1, null]]], $yearly,
                    'Yearly plans only: each customer joins the cohort of their first yearly plan');

                // The page sends no dates: every year, so old cohorts show
                [$everyYear] = $read(['startDate' => null, 'endDate' => null, 'groupBy' => 'year']);
                FcTest::assertSame([2001], array_keys($everyYear), 'With no dates, the yearly view reads every cohort year');

                [$product] = $read(['productIds' => [1900000002]]);
                FcTest::assertSame(['2001-01', '2001-02'], array_keys($product), 'The second product: customer 2 from January, customer 5 from February');
                // Snapshots built before the rebuy rows existed: the switch asks for a new build instead of showing no customers
                $db->table('fct_retention_snapshots')->where('currency', $fcCohortCurrency)->where('rebuy_adjusted', 1)->delete();
                FcTest::assertSame([true, null], [empty($read(['rebuyAdjusted' => true])[0]), $read(['rebuyAdjusted' => true])[1]['generated_at'] ?? null],
                    'No rebuy rows: not generated yet');
                $service->build($subscriptions, $months, $firstPaid, $fcCohortCurrency, true);

                $intervals = $result['intervals'];
                sort($intervals);
                FcTest::assertSame(['monthly', 'yearly'], $intervals, 'The response lists the intervals the snapshots hold');
            } finally {
                $db->table('fct_retention_snapshots')->where('currency', $fcCohortCurrency)->delete();
                $restoreStoreRows();
            }
        },
    ],
];

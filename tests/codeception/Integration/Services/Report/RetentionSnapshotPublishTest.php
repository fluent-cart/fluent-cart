<?php

namespace Tests\Integration\Services\Report;

use FluentCart\App\App;
use FluentCart\App\Services\Report\RetentionSnapshotService;
use Tests\Support\DatabaseTestCase;

/**
 * The last build step publishes the build table. A cohort whose MRR grew past ten times its start (upgrades) must
 * publish its real rate, and a publish that stops halfway must leave the Cohorts page flagged for a rebuild.
 */
class RetentionSnapshotPublishTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new class extends RetentionSnapshotService {
            public function prepare(): void
            {
                $this->ensureTableExists();
                $this->prepareBuildTable();
            }
        })->prepare();
        delete_option(RetentionSnapshotService::STALE_OPTION);
    }

    protected function tearDown(): void
    {
        App::db()->table('fct_retention_snapshots')->delete();
        App::db()->table(RetentionSnapshotService::BUILD_TABLE)->delete();
        delete_option(RetentionSnapshotService::STALE_OPTION);
        parent::tearDown();
    }

    private function buildRow(array $override = []): array
    {
        return array_merge([
            'cohort' => '2004-03', 'period' => '2004-04', 'product_id' => null, 'currency' => 'USD', 'billing_interval' => '',
            'rebuy_adjusted' => 0, 'period_offset' => 1, 'cohort_customers' => 1, 'cohort_x12' => 12 * 1000,
            'retained_customers' => 1, 'retained_x12' => 12 * 15000,
        ], $override);
    }

    private function publisher(): RetentionSnapshotService
    {
        return new class extends RetentionSnapshotService {
            public function publishNow(): void
            {
                $this->publish(['2004-03', '2004-04']);
            }
        };
    }

    public function test_a_cohort_that_grew_fifteen_times_publishes_its_real_rate(): void
    {
        App::db()->table(RetentionSnapshotService::BUILD_TABLE)->insert($this->buildRow());

        $this->publisher()->publishNow();

        $row = App::db()->table('fct_retention_snapshots')->where('cohort', '2004-03')->first();
        $this->assertSame(['15000', '1500.00'], [(string) $row->retained_mrr, (string) $row->retention_rate_mrr]);
    }

    public function test_a_publish_that_fails_leaves_the_rebuild_flag(): void
    {
        App::db()->table(RetentionSnapshotService::BUILD_TABLE)->insert($this->buildRow());
        // The request dies after the old rows are gone (time limit, lost connection)
        $publisher = new class extends RetentionSnapshotService {
            public function publishNow(): void
            {
                $this->publish(['2004-03', '2004-04']);
            }

            protected function insertBatch(string $table, array $batch): int
            {
                throw new \RuntimeException('Lost connection');
            }
        };

        try {
            $publisher->publishNow();
            $failed = false;
        } catch (\Throwable $e) {
            $failed = true;
        }

        $this->assertSame([true, '1'], [$failed, (string) get_option(RetentionSnapshotService::STALE_OPTION)]);
    }
}

<?php

namespace Tests\Integration\Services\Report;

use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\SubscriptionReportService;
use Tests\Support\DatabaseTestCase;

/**
 * A retention row carries the ISO week of its last day. Dec 31 can fall in week 01 of the
 * next year: the key then needs that ISO year, not the calendar year of the day.
 */
class RetentionWeekKeyTest extends DatabaseTestCase
{
    public function test_a_period_that_ends_in_iso_week_one_of_the_next_year_names_that_year(): void
    {
        // 2024-12-31 is a Tuesday in ISO week 1 of 2025
        $rows = SubscriptionReportService::make()->getRetentionData([
            'startDate'    => new DateTime('2024-11-01 00:00:00'),
            'endDate'      => new DateTime('2024-12-31 23:59:59'),
            'variationIds' => [987651],
        ]);

        $weeks = array_column($rows, 'week', 'day');

        $this->assertSame('2025-01', $weeks['2024-12-31'], 'Not 2024-01, which reads as the first week of 2024');
        $this->assertSame('2024-48', $weeks['2024-11-30'], 'A day inside the year is unchanged');
    }
}

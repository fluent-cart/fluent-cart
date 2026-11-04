<?php

namespace Tests\Unit\Services\Report;

use Codeception\Test\Unit;
use DateTimeZone;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\ReportHelper;

/**
 * The compare range derived by ReportHelper::getCompareRange() must cover the
 * same number of elapsed days as the primary range and must end before the
 * primary range starts. Three derivations broke that on develop:
 *
 *   - previous_quarter snapped its start to the first of the month but kept
 *     the unsnapped end, so one day (2026-10-15) compared against fifteen
 *     (2026-07-01..2026-07-15).
 *   - previous_month added the primary length to "start minus one month", so
 *     This Month (2026-10-01..2026-10-31) compared against
 *     2026-09-01..2026-10-01 and overlapped the primary range by a day.
 *     (Pinned on July here: the October preset also runs past today, which
 *     the third case covers.)
 *   - A primary range that runs past today (the This Month preset ends on the
 *     31st) was compared at its full requested length, so on 2026-10-15 the
 *     fifteen elapsed days of October compared against the 31 days of
 *     2026-08-31..2026-09-30.
 */
class ReportHelperCompareRangeTest extends Unit
{
    public function test_previous_quarter_keeps_the_primary_length(): void
    {
        $range = ReportHelper::getCompareRange('previous_quarter', $this->range('2026-10-15', '2026-10-15'));

        $this->assertSame(
            ['2026-07-15 00:00:00', '2026-07-15 23:59:59'],
            $this->format($range),
            'A one-day primary range must compare against one day, three months earlier'
        );
    }

    // July is the same case as the reported October one (a 31-day month after
    // a 30-day month) but lies wholly in the past, so the today clamp in the
    // third test cannot shorten it.
    public function test_previous_month_ends_the_day_before_the_primary_start(): void
    {
        $range = ReportHelper::getCompareRange('previous_month', $this->range('2026-07-01', '2026-07-31'));

        $this->assertSame(
            ['2026-06-01 00:00:00', '2026-06-30 23:59:59'],
            $this->format($range),
            'A full month compared with the previous month must stop on its last day, not reach into the primary month'
        );
    }

    public function test_primary_range_past_today_compares_its_elapsed_days_only(): void
    {
        $today = DateTime::now()->endOfDay();
        $start = $today->copy()->startOfMonth();
        $elapsedDays = $today->diff($start)->days;

        // The end is always past today so the clamp is exercised on the last day of a month too.
        $range = ReportHelper::getCompareRange('previous_period', [
            'startDate' => $start->copy(),
            'endDate'   => $today->copy()->addDays(10),
        ]);

        $this->assertSame(
            [
                $start->copy()->subDays($elapsedDays + 1)->format('Y-m-d H:i:s'),
                $start->copy()->subDays(1)->endOfDay()->format('Y-m-d H:i:s'),
            ],
            $this->format($range),
            'This Month on a mid-month day must compare only its elapsed days against the days just before it'
        );
    }

    private function range(string $start, string $end): array
    {
        $utc = new DateTimeZone('UTC');

        return [
            'startDate' => (new DateTime($start, $utc))->startOfDay(),
            'endDate'   => (new DateTime($end, $utc))->endOfDay(),
        ];
    }

    private function format(array $range): array
    {
        return [$range[0]->format('Y-m-d H:i:s'), $range[1]->format('Y-m-d H:i:s')];
    }
}

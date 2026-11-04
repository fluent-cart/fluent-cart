<?php

namespace Tests\Unit\Services\DateTime;

use Codeception\Test\Unit;
use FluentCart\App\Services\DateTime\DayjsFormatter;

/**
 * Unit coverage for DayjsFormatter — converting a PHP date() format to its
 * Day.js equivalent for the admin SPA and the customer dashboard.
 *
 * Reported by a German store on 1.6.1: dates rendered as "Aug 16, 2026 03:30 PM"
 * regardless of locale, because the patterns were literals in the source. The
 * month names were already translatable; the ORDER of day and month and the
 * 12-hour clock were not reachable by any translation or filter. These tests
 * pin the conversion that lets the store's own WordPress format reach the SPA.
 *
 * Everything here is pure string work — no WordPress, no options, no timezone.
 * The PHP-side rendering is covered in
 * Tests\Integration\Services\DateTime\DateFormatterTest.
 */
class DayjsFormatterTest extends Unit
{
    // =========================================================================
    // toDayjs — PHP date() tokens to Day.js tokens
    // =========================================================================

    /**
     * @dataProvider phpFormatProvider
     */
    public function test_convert_converts_php_format($phpFormat, $expected, $why): void
    {
        $this->assertSame(
            $expected,
            DayjsFormatter::convert($phpFormat),
            $why
        );
    }

    public function phpFormatProvider(): array
    {
        return [
            'WordPress US default' => [
                'F j, Y', 'MMMM D, YYYY',
                'The WordPress default date format must survive the round trip unchanged in meaning',
            ],
            'German day-first' => [
                'j. F Y', 'D. MMMM YYYY',
                'A German store writes the day first; that order is the whole point of the bug report',
            ],
            'ISO' => [
                'Y-m-d', 'YYYY-MM-DD',
                'Zero-padded numeric tokens map to their doubled Day.js counterparts',
            ],
            'UK slash-separated' => [
                'd/m/Y', 'DD/MM/YYYY',
                'Separators that are not letters pass through untouched',
            ],
            '24-hour time' => [
                'H:i', 'HH:mm',
                'A 24-hour clock must not come back as a 12-hour one',
            ],
            '12-hour time' => [
                'g:i a', 'h:mm a',
                'Lowercase meridiem stays lowercase',
            ],
            'ordinal day folds into Do' => [
                'jS F Y', 'Do MMMM YYYY',
                'Day.js has no standalone ordinal-suffix token, so jS must fold into Do',
            ],
            'padded ordinal day folds into Do' => [
                'dS F Y', 'Do MMMM YYYY',
                'The suffix folds into the padded day token too',
            ],
            'weekday and ordinal together' => [
                'l, F jS, Y', 'dddd, MMMM Do, YYYY',
                'A full weekday name and an ordinal day coexist in one format',
            ],
            'Japanese suffix markers' => [
                'Y年n月j日', 'YYYY年M月D日',
                'Non-ASCII suffix markers are not tokens and must pass through as literals',
            ],
            'backslash-escaped literals' => [
                '\T\o\d\a\y: F j', '[Today]: MMMM D',
                'PHP escapes a literal with a backslash; Day.js brackets it, or it would read as tokens',
            ],
            'RFC-style' => [
                'D, d M Y H:i:s', 'ddd, DD MMM YYYY HH:mm:ss',
                'Short weekday and short month names convert alongside a full time',
            ],
        ];
    }

    public function test_convert_brackets_a_stray_non_token_letter(): void
    {
        // 'k' and 'q' are not PHP date tokens. Left bare they would be read as
        // Day.js tokens by a future Day.js version and silently corrupt output.
        $this->assertSame(
            '[k]YYYY[q]',
            DayjsFormatter::convert('kYq'),
            'A letter that is not a PHP token must be emitted as a Day.js literal, never left bare'
        );
    }

    public function test_convert_groups_consecutive_literal_letters(): void
    {
        $this->assertSame(
            '[of] YYYY',
            DayjsFormatter::convert('\o\f Y'),
            'Consecutive escaped letters collapse into one bracket group rather than one group each'
        );
    }

    public function test_convert_drops_tokens_with_no_dayjs_equivalent(): void
    {
        // 't' (days in month) and 'L' (leap year) have no Day.js counterpart.
        $this->assertSame(
            'YYYY',
            DayjsFormatter::convert('tLY'),
            'A PHP token Day.js cannot express is dropped rather than emitted as a bare letter'
        );
    }

    public function test_convert_returns_empty_string_for_empty_format(): void
    {
        $this->assertSame(
            '',
            DayjsFormatter::convert(''),
            'An empty format converts to an empty pattern rather than throwing'
        );
    }

    public function test_convert_handles_a_trailing_backslash(): void
    {
        // A format ending in a lone backslash has nothing to escape; the loop
        // must not read past the end of the string.
        $this->assertSame(
            'YYYY',
            DayjsFormatter::convert('Y\\'),
            'A dangling escape at the end of a format must not overrun the string'
        );
    }

    // =========================================================================
    // abbreviateMonth — shrink date_short's month alongside dropping its year
    // =========================================================================

    /**
     * @dataProvider abbreviateMonthProvider
     */
    public function test_abbreviate_month($phpFormat, $expected, $why): void
    {
        $this->assertSame(
            $expected,
            DayjsFormatter::abbreviateMonth($phpFormat),
            $why
        );
    }

    public function abbreviateMonthProvider(): array
    {
        return [
            'German day-first' => [
                'j. F Y', 'j. M Y',
                'The full-name month token F must become the abbreviated M',
            ],
            'numeric month stays numeric' => [
                'd/m/Y', 'd/m/Y',
                'A zero-padded numeric month token must not be touched or turned into a name',
            ],
            'unpadded numeric month stays numeric' => [
                'Y年n月j日', 'Y年n月j日',
                'The unpadded numeric month token n must not be touched either',
            ],
            'already abbreviated month is left alone' => [
                'M j, Y', 'M j, Y',
                'A format that already spells the month short has nothing to shorten',
            ],
            'escaped literal F is not a token' => [
                '\F F j', '\F M j',
                'A backslash-escaped F is a literal letter, not the month token, and must survive untouched',
            ],
            'no month token at all' => [
                'Y-m-d', 'Y-m-d',
                'A format without a full-name month token is returned unchanged',
            ],
        ];
    }

    public function test_abbreviate_month_returns_empty_string_for_empty_format(): void
    {
        $this->assertSame(
            '',
            DayjsFormatter::abbreviateMonth(''),
            'An empty format must not throw'
        );
    }

    public function test_abbreviate_month_handles_a_trailing_backslash(): void
    {
        // Matches convert()'s own trailing-backslash behavior: a dangling
        // escape with nothing to escape is dropped rather than read past the
        // end of the string.
        $this->assertSame(
            'M',
            DayjsFormatter::abbreviateMonth('F\\'),
            'A dangling escape at the end of a format must not overrun the string, and the real F token before it still shortens'
        );
    }
}

<?php

namespace Tests\Integration\Services\DateTime;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Services\DateTime\DayjsFormatter;
use FluentCart\App\Services\Translations\TransStrings;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Integration coverage for the bridge that hands the store's date formats to
 * the admin SPA and the customer dashboard.
 *
 * The SPA already received translated month and weekday names, but the pattern
 * was a literal inside the bundle, so a German store got translated names in
 * English order -- "August 16, 2026". Nothing on the site could reach it: the
 * bundle is an ES module, so there was no global formatter to override.
 *
 * The pure conversion is covered in
 * Tests\Unit\Services\DateTime\DayjsFormatterTest.
 */
class DayjsFormatterTest extends WPTestCase
{
    /** @var string */
    private $originalDateFormat;

    /** @var string */
    private $originalTimeFormat;

    /** @var array */
    private $originalStoreSettings;

    public function setUp(): void
    {
        parent::setUp();
        $this->originalDateFormat    = get_option('date_format');
        $this->originalTimeFormat    = get_option('time_format');
        $this->originalStoreSettings = get_option('fluent_cart_store_settings', []);

        // These cases are about converting the WordPress formats, so pin the
        // store to that source. The shipped default is 'fluent_cart', which
        // deliberately ignores the WordPress options -- that default has its
        // own coverage in DateFormatterTest.
        $this->useWordPressFormats();
    }

    public function tearDown(): void
    {
        update_option('date_format', $this->originalDateFormat);
        update_option('time_format', $this->originalTimeFormat);
        update_option('fluent_cart_store_settings', $this->originalStoreSettings);
        StoreSettings::clearCache();
        remove_all_filters('fluent_cart/date_time_strings');
        TransStrings::dateTimeStrings(true);
        parent::tearDown();
    }

    private function useWordPressFormats(): void
    {
        $settings = get_option('fluent_cart_store_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $settings['date_time_format_source'] = 'wordpress';

        update_option('fluent_cart_store_settings', $settings);
        StoreSettings::clearCache();
        TransStrings::dateTimeStrings(true);
    }

    public function test_formats_follow_a_german_store_setting(): void
    {
        update_option('date_format', 'j. F Y');
        update_option('time_format', 'H:i');
        TransStrings::dateTimeStrings(true);

        $formats = DayjsFormatter::formats();

        $this->assertSame('D. MMMM YYYY', $formats['date'], 'The SPA must receive the day-first pattern, not MMM DD, YYYY');
        $this->assertSame('HH:mm', $formats['time'], 'The SPA must receive a 24-hour pattern, not h:mm A');
        $this->assertSame('D. MMMM YYYY HH:mm', $formats['date_time'], 'The combined pattern follows the store too');
    }

    public function test_formats_derive_the_compact_and_month_variants(): void
    {
        update_option('date_format', 'j. F Y');
        TransStrings::dateTimeStrings(true);

        $formats = DayjsFormatter::formats();

        // Ticket: a German store's compact table columns rendered "16. August"
        // -- wider than the column needs -- because date_short only dropped the
        // year, keeping the full month name from the store's own date format.
        // date_short must additionally abbreviate the month, rendering
        // "16. Aug." from the store's own localized monthsShort. month_year
        // (chart/label context) is unaffected on purpose -- only date_short's
        // compact-table use case asked for the shorter month.
        $this->assertSame('D. MMM', $formats['date_short'], 'The compact column drops the year AND abbreviates the month, but keeps the locale order');
        $this->assertSame('MMMM YYYY', $formats['month_year'], 'A month label drops the day and the comma it stranded; month_year keeps the full month name unchanged');
        $this->assertSame('MMMM', $formats['month_long'], 'The long-month pattern is the standalone month');
    }

    public function test_a_suffix_locale_keeps_its_trailing_marker(): void
    {
        update_option('date_format', 'Y年n月j日');
        TransStrings::dateTimeStrings(true);

        $formats = DayjsFormatter::formats();

        $this->assertSame('YYYY年M月D日', $formats['date'], 'Suffix markers are literals and must survive conversion');
        // 'n' is PHP's NUMERIC month token, converting to Day.js's numeric 'M'
        // (not the abbreviated-name 'MMM') -- abbreviateMonth() only rewrites
        // the full-name token 'F', so a store that spells its month as a digit
        // must not gain a month name here.
        $this->assertSame('M月D日', $formats['date_short'], 'Dropping the year takes the marker that belonged to it, not the trailing one, and a numeric month token stays numeric');
        $this->assertSame('YYYY年M月', $formats['month_year'], 'Dropping the day takes its own trailing marker');
    }

    public function test_date_short_keeps_a_numeric_month_numeric(): void
    {
        update_option('date_format', 'd/m/Y');
        TransStrings::dateTimeStrings(true);

        $this->assertSame(
            'DD/MM',
            DayjsFormatter::formats()['date_short'],
            'A store that writes the month as a zero-padded digit must not have it turned into a month name'
        );
    }

    public function test_date_short_leaves_an_already_abbreviated_month_untouched(): void
    {
        update_option('date_format', 'M j, Y');
        TransStrings::dateTimeStrings(true);

        $this->assertSame(
            'MMM D',
            DayjsFormatter::formats()['date_short'],
            'A store whose date format already spells the month short must not be double-shortened or altered'
        );
    }

    public function test_the_single_filter_reaches_the_spa_payload(): void
    {
        update_option('date_format', 'F j, Y');
        add_filter('fluent_cart/date_time_strings', function ($strings) {
            $strings['formats']['date'] = 'Y-m-d';
            return $strings;
        });
        TransStrings::dateTimeStrings(true);

        $this->assertSame(
            'YYYY-MM-DD',
            DayjsFormatter::formats()['date'],
            'Overriding the PHP format through the one filter must change what the SPA renders too'
        );
    }

    public function test_localized_strings_carry_names_and_converted_formats(): void
    {
        update_option('date_format', 'j. F Y');
        TransStrings::dateTimeStrings(true);

        $payload = DayjsFormatter::localizedStrings();

        $this->assertArrayHasKey('months', $payload, 'The payload keeps the translated month names');
        $this->assertArrayHasKey('monthsShort', $payload, 'The payload keeps the short month names');
        $this->assertArrayHasKey('numericSystem', $payload, 'The payload keeps the numeral map');
        $this->assertSame(
            'D. MMMM YYYY',
            $payload['formats']['date'],
            'The payload must carry Day.js patterns, never the raw PHP format the bundle cannot parse'
        );
    }
}

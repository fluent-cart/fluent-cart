<?php

namespace Tests\Integration\Services\DateTime;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Services\DateTime\DateFormatter;
use FluentCart\App\Services\Translations\TransStrings;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Integration coverage for DateFormatter — the half that reads WordPress
 * options and renders an actual date.
 *
 * Reported by a German store on 1.6.1 (locale de_DE, date format "j. F Y",
 * time format "H:i", timezone Europe/Berlin): every FluentCart date came out
 * as "Aug 16, 2026 03:30 PM". The month names were already translatable, but
 * the day/month ORDER and the 12-hour clock were literals in the source, so
 * no translation and no filter could reach them.
 *
 * Two defects are pinned here:
 *   1. the format must come from the store's WordPress settings, and
 *   2. the value must be rendered in the store's timezone -- the shortcode
 *      paths used date()/gmdate(), which run in the PHP default timezone and
 *      could report the wrong day around midnight.
 *
 * The pure format-conversion half lives in
 * Tests\Unit\Services\DateTime\DateFormatterTest.
 */
class DateFormatterTest extends WPTestCase
{
    /** @var string */
    private $originalDateFormat;

    /** @var string */
    private $originalTimeFormat;

    /** @var string */
    private $originalTimezone;

    /** @var array */
    private $originalStoreSettings;

    public function setUp(): void
    {
        parent::setUp();
        $this->originalDateFormat    = get_option('date_format');
        $this->originalTimeFormat    = get_option('time_format');
        $this->originalTimezone      = get_option('timezone_string');
        $this->originalStoreSettings = get_option('fluent_cart_store_settings', []);

        // Most cases below pin the WordPress-sourced behaviour. The shipped
        // default is 'fluent_cart' on both axes so that an upgrading store
        // keeps its old strings; that default has its own cases further down.
        $this->useSources('wordpress', 'wordpress');
    }

    public function tearDown(): void
    {
        update_option('date_format', $this->originalDateFormat);
        update_option('time_format', $this->originalTimeFormat);
        update_option('timezone_string', $this->originalTimezone);
        update_option('fluent_cart_store_settings', $this->originalStoreSettings);
        StoreSettings::clearCache();
        remove_all_filters('fluent_cart/date_time_strings');
        remove_all_filters('fluent_cart/date_time_separator');
        TransStrings::dateTimeStrings(true);
        parent::tearDown();
    }

    /**
     * Point the two store settings at a source and rebuild every cache that
     * stands between them and DateFormatter.
     */
    private function useSources(string $formatSource, string $timezoneSource): void
    {
        $settings = get_option('fluent_cart_store_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $settings['date_time_format_source'] = $formatSource;
        $settings['timezone_source']         = $timezoneSource;

        update_option('fluent_cart_store_settings', $settings);
        StoreSettings::clearCache();
        TransStrings::dateTimeStrings(true);
    }

    // =========================================================================
    // The reported bug: a German store must get German date output
    // =========================================================================

    public function test_format_renders_a_german_store_date_in_german_order(): void
    {
        update_option('date_format', 'j. F Y');
        update_option('time_format', 'H:i');
        update_option('timezone_string', 'Europe/Berlin');
        TransStrings::dateTimeStrings(true);
        TransStrings::dateTimeStrings(true);

        // 13:30 UTC on 2026-08-16 is 15:30 in Berlin (CEST, UTC+2).
        $this->assertSame(
            '16. August 2026 15:30',
            DateFormatter::format('2026-08-16 13:30:00', true),
            'A German store must get day-first order and a 24-hour clock, not "Aug 16, 2026 03:30 PM"'
        );
    }

    public function test_format_renders_the_wordpress_us_default(): void
    {
        update_option('date_format', 'F j, Y');
        update_option('time_format', 'g:i a');
        update_option('timezone_string', 'UTC');
        TransStrings::dateTimeStrings(true);

        $this->assertSame(
            'August 16, 2026 3:30 pm',
            DateFormatter::format('2026-08-16 15:30:00', true),
            'A store on the WordPress defaults must get the WordPress default rendering'
        );
    }

    public function test_format_without_time_omits_the_time(): void
    {
        update_option('date_format', 'j. F Y');
        update_option('time_format', 'H:i');
        update_option('timezone_string', 'Europe/Berlin');
        TransStrings::dateTimeStrings(true);

        $this->assertSame(
            '16. August 2026',
            DateFormatter::format('2026-08-16 13:30:00'),
            'Omitting the time flag must leave the time off entirely'
        );
    }

    // =========================================================================
    // The second defect: store timezone, not the PHP default
    // =========================================================================

    public function test_format_converts_gmt_into_the_store_timezone_across_midnight(): void
    {
        update_option('date_format', 'Y-m-d');
        update_option('timezone_string', 'Europe/Berlin');
        TransStrings::dateTimeStrings(true);

        // 22:30 UTC is already the NEXT day in Berlin. The old date()/gmdate()
        // shortcode paths reported 2026-08-16 here, a day early.
        $this->assertSame(
            '2026-08-17',
            DateFormatter::format('2026-08-16 22:30:00'),
            'A GMT time past the store-timezone midnight must report the store-local day'
        );
    }

    public function test_format_converts_gmt_backwards_for_a_negative_offset(): void
    {
        update_option('date_format', 'Y-m-d');
        update_option('timezone_string', 'America/New_York');
        TransStrings::dateTimeStrings(true);

        // 01:30 UTC is still the PREVIOUS day in New York.
        $this->assertSame(
            '2026-08-15',
            DateFormatter::format('2026-08-16 01:30:00'),
            'A GMT time before the store-timezone midnight must report the previous store-local day'
        );
    }

    // =========================================================================
    // Empty and malformed input
    // =========================================================================

    /**
     * @dataProvider emptyValueProvider
     */
    public function test_format_returns_empty_string_for_an_absent_date($value, $why): void
    {
        update_option('date_format', 'F j, Y');
        TransStrings::dateTimeStrings(true);

        $this->assertSame('', DateFormatter::format($value), $why);
    }

    public function emptyValueProvider(): array
    {
        return [
            'null'      => [null, 'A null date must render as an empty string, not as "now"'],
            'empty'     => ['', 'An empty date must render as an empty string'],
            'zero date' => ['0000-00-00 00:00:00', 'A MySQL zero date must render as an empty string, not year -0001'],
        ];
    }

    public function test_format_returns_empty_string_for_an_unparseable_date(): void
    {
        update_option('date_format', 'F j, Y');
        TransStrings::dateTimeStrings(true);

        $this->assertSame(
            '',
            DateFormatter::format('not a date at all'),
            'An unparseable value must return an empty string rather than throwing out of an email template'
        );
    }

    public function test_default_formats_combine_date_and_time(): void
    {
        update_option('date_format', 'j. F Y');
        update_option('time_format', 'H:i');
        TransStrings::dateTimeStrings(true);

        $formats = DateFormatter::defaultFormats();

        $this->assertSame('j. F Y', $formats['date'], 'The date format comes straight from the store setting');
        $this->assertSame('H:i', $formats['time'], 'The time format comes straight from the store setting');
        $this->assertSame('j. F Y H:i', $formats['date_time'], 'The combined format is the date then the time');
    }

    // =========================================================================
    // Filters
    // =========================================================================

    public function test_the_date_format_is_overridable_through_the_single_filter(): void
    {
        update_option('date_format', 'F j, Y');
        add_filter('fluent_cart/date_time_strings', function ($strings) {
            $strings['formats']['date'] = 'Y-m-d';
            return $strings;
        });
        TransStrings::dateTimeStrings(true);

        $this->assertSame(
            '2026-08-16',
            DateFormatter::format('2026-08-16 12:00:00'),
            'One filter over the whole map must be enough to override the rendered date format'
        );
    }

    public function test_the_date_time_format_is_overridable_through_the_single_filter(): void
    {
        update_option('timezone_string', 'UTC');
        add_filter('fluent_cart/date_time_strings', function ($strings) {
            $strings['formats']['date_time'] = 'Y-m-d H:i:s';
            return $strings;
        });
        TransStrings::dateTimeStrings(true);

        $this->assertSame(
            '2026-08-16 12:00:30',
            DateFormatter::format('2026-08-16 12:00:30', true),
            'The combined date-time format must be overridable from the same single filter'
        );
    }

    public function test_date_time_strings_passes_through_its_filter(): void
    {
        add_filter('fluent_cart/date_time_strings', function ($strings) {
            $strings['months']['January'] = 'Januar';
            return $strings;
        });

        $strings = TransStrings::dateTimeStrings(true);

        $this->assertSame(
            'Januar',
            $strings['months']['January'],
            'dateTimeStrings() must expose a filter like every other map in TransStrings, so a store can correct the names'
        );
    }

    public function test_date_time_strings_still_returns_its_map_without_a_filter(): void
    {
        $strings = TransStrings::dateTimeStrings();

        $this->assertArrayHasKey('weekdays', $strings, 'The unfiltered map must still carry weekdays');
        $this->assertArrayHasKey('monthsShort', $strings, 'The unfiltered map must still carry monthsShort');
        $this->assertCount(12, $strings['monthsShort'], 'monthsShort must carry all twelve entries');
    }

    // =========================================================================
    // The two store settings: FluentCart's own format and timezone
    //
    // Both default to 'fluent_cart' so that a store upgrading into these
    // settings keeps every string it rendered before they existed. These cases
    // are the back-compat guard for that promise.
    // =========================================================================

    public function test_fluent_cart_format_source_ignores_the_wordpress_date_format(): void
    {
        update_option('date_format', 'j. F Y');
        update_option('time_format', 'H:i');
        update_option('timezone_string', 'UTC');
        $this->useSources('fluent_cart', 'wordpress');

        $this->assertSame(
            'Aug 16, 2026 03:30 PM',
            DateFormatter::format('2026-08-16 15:30:00', true),
            "On the FluentCart format source the store's WordPress format must be ignored entirely"
        );
    }

    public function test_wordpress_format_source_is_the_shipped_default(): void
    {
        update_option('date_format', 'j. F Y');
        update_option('time_format', 'H:i');
        update_option('timezone_string', 'UTC');

        // Drop both keys so the value comes from getDefaultSettings(), which is
        // what an upgrading store with no saved preference actually reads.
        $settings = get_option('fluent_cart_store_settings', []);
        unset($settings['date_time_format_source'], $settings['timezone_source']);
        update_option('fluent_cart_store_settings', $settings);
        StoreSettings::clearCache();
        TransStrings::dateTimeStrings(true);

        // This deliberately does NOT reproduce the pre-settings string. The
        // FluentCart literals render a half-translated date on a localized
        // store -- a translated month in English field order -- so the shipped
        // default follows Settings > General, which is correct in every locale.
        // A store that wants 'Aug 16, 2026 03:30 PM' back selects 'fluent_cart'.
        $this->assertSame(
            '16. August 2026 15:30',
            DateFormatter::format('2026-08-16 15:30:00', true),
            'A store that has never touched these settings must follow Settings > General'
        );
    }

    public function test_fluent_cart_format_source_still_reproduces_the_pre_settings_string(): void
    {
        update_option('date_format', 'j. F Y');
        update_option('time_format', 'H:i');
        update_option('timezone_string', 'UTC');
        $this->useSources('fluent_cart', 'fluent_cart');

        $this->assertSame(
            'Aug 16, 2026 03:30 PM',
            DateFormatter::format('2026-08-16 15:30:00', true),
            'Opting into the FluentCart source must still give back the pre-settings literal'
        );
    }

    public function test_wordpress_format_source_follows_the_wordpress_date_format(): void
    {
        update_option('date_format', 'j. F Y');
        update_option('time_format', 'H:i');
        update_option('timezone_string', 'UTC');
        $this->useSources('wordpress', 'wordpress');

        $this->assertSame(
            '16. August 2026 15:30',
            DateFormatter::format('2026-08-16 15:30:00', true),
            'Opting into the WordPress format source must follow Settings > General'
        );
    }

    public function test_fluent_cart_timezone_source_renders_in_the_order_timezone(): void
    {
        update_option('date_format', 'Y-m-d');
        update_option('time_format', 'H:i');
        update_option('timezone_string', 'America/New_York');
        $this->useSources('wordpress', 'fluent_cart');

        $order = (object)['config' => ['user_tz' => 'Europe/Berlin']];

        // 13:30 UTC is 15:30 in Berlin. The site timezone (New York) must not win.
        $this->assertSame(
            '2026-08-16 15:30',
            DateFormatter::format('2026-08-16 13:30:00', true, $order),
            'On the FluentCart timezone source the order timezone captured at checkout must win over the site timezone'
        );
    }

    public function test_fluent_cart_timezone_source_reads_a_plain_config_array(): void
    {
        update_option('date_format', 'Y-m-d');
        update_option('time_format', 'H:i');
        update_option('timezone_string', 'UTC');
        $this->useSources('wordpress', 'fluent_cart');

        $this->assertSame(
            '2026-08-16 15:30',
            DateFormatter::format('2026-08-16 13:30:00', true, ['user_tz' => 'Europe/Berlin']),
            'A bare order config array must be accepted as the timezone context'
        );
    }

    public function test_fluent_cart_timezone_source_accepts_a_raw_timezone_string(): void
    {
        update_option('date_format', 'Y-m-d');
        update_option('time_format', 'H:i');
        update_option('timezone_string', 'UTC');
        $this->useSources('wordpress', 'fluent_cart');

        $this->assertSame(
            '2026-08-16 15:30',
            DateFormatter::format('2026-08-16 13:30:00', true, 'Europe/Berlin'),
            'A raw timezone string must be accepted as the timezone context'
        );
    }

    public function test_fluent_cart_timezone_source_falls_back_to_utc_without_a_context(): void
    {
        update_option('date_format', 'Y-m-d');
        update_option('time_format', 'H:i');
        update_option('timezone_string', 'Europe/Berlin');
        $this->useSources('wordpress', 'fluent_cart');

        // Customer-level dates (FluentCRM first/last purchase) have no single
        // order behind them, so they render in UTC — which is how the old
        // gmdate()/date() paths rendered them.
        $this->assertSame(
            '2026-08-16 13:30',
            DateFormatter::format('2026-08-16 13:30:00', true),
            'With no order context the FluentCart timezone source must fall back to UTC, not the site timezone'
        );
    }

    public function test_fluent_cart_timezone_source_falls_back_to_utc_for_a_deprecated_alias(): void
    {
        update_option('date_format', 'Y-m-d');
        update_option('time_format', 'H:i');
        update_option('timezone_string', 'Europe/Berlin');
        $this->useSources('wordpress', 'fluent_cart');

        // user_tz is browser-captured, so it can carry a value PHP 8.4 refuses.
        $order = (object)['config' => ['user_tz' => 'Not/AZone']];

        $this->assertSame(
            '2026-08-16 13:30',
            DateFormatter::format('2026-08-16 13:30:00', true, $order),
            'An unusable order timezone must fall back to UTC rather than throwing out of an email template'
        );
    }

    public function test_wordpress_timezone_source_ignores_the_order_timezone(): void
    {
        update_option('date_format', 'Y-m-d');
        update_option('time_format', 'H:i');
        update_option('timezone_string', 'America/New_York');
        $this->useSources('wordpress', 'wordpress');

        $order = (object)['config' => ['user_tz' => 'Europe/Berlin']];

        // 13:30 UTC is 09:30 in New York.
        $this->assertSame(
            '2026-08-16 09:30',
            DateFormatter::format('2026-08-16 13:30:00', true, $order),
            'On the WordPress timezone source the site timezone must win over anything captured on the order'
        );
    }

    public function test_the_payload_carries_the_timezone_source_for_the_bundle(): void
    {
        update_option('timezone_string', 'Europe/Berlin');
        $this->useSources('wordpress', 'fluent_cart');

        $strings = TransStrings::dateTimeStrings(true);

        $this->assertSame(
            'fluent_cart',
            $strings['timezone']['source'],
            'The bundle needs the timezone source to decide whether to render in the browser timezone'
        );
        $this->assertSame(
            'Europe/Berlin',
            $strings['timezone']['site'],
            'The bundle needs the site timezone to render in it when the source is wordpress'
        );
    }

    // =========================================================================
    // Derived patterns: month-year and the hour-of-day axis
    //
    // Neither has a WordPress setting of its own, so both are derived from the
    // store's formats rather than hard-coded in English.
    // =========================================================================

    public function test_month_year_format_drops_the_day_from_the_store_format(): void
    {
        update_option('date_format', 'j. F Y');
        $this->useSources('wordpress', 'wordpress');

        $this->assertSame(
            'F Y',
            DateFormatter::monthYearFormat(),
            'The month-year pattern must keep the month and year and drop only the day'
        );
    }

    public function test_month_year_format_keeps_a_year_first_field_order(): void
    {
        update_option('date_format', 'Y-m-d');
        $this->useSources('wordpress', 'wordpress');

        $this->assertSame(
            'Y-m',
            DateFormatter::monthYearFormat(),
            'A year-first store must keep its own field order, not be rewritten to the English month-year'
        );
    }

    public function test_hour_format_follows_a_24_hour_store(): void
    {
        update_option('time_format', 'H:i');
        $this->useSources('wordpress', 'wordpress');

        $this->assertSame(
            'H',
            DateFormatter::hourFormat(),
            'An hour-of-day axis on a 24-hour store must read 0-23, not 1 AM - 12 PM'
        );
    }

    public function test_hour_format_follows_a_12_hour_store(): void
    {
        update_option('time_format', 'g:i a');
        $this->useSources('wordpress', 'wordpress');

        $this->assertSame(
            'g A',
            DateFormatter::hourFormat(),
            'A 12-hour store keeps the meridiem hour label'
        );
    }

    public function test_hour_format_honours_the_G_token_for_24_hours(): void
    {
        update_option('time_format', 'G:i');
        $this->useSources('wordpress', 'wordpress');

        $this->assertSame(
            'H',
            DateFormatter::hourFormat(),
            'G is the other 24-hour token and must be detected too'
        );
    }

    /**
     * The pre-settings literals were not uniform: date-only call sites used
     * 'M j, Y' and date-and-time ones 'M d, Y h:i A'. Both must survive the
     * Smart source verbatim, or one of them silently renumbers.
     */
    public function test_fluent_cart_source_keeps_the_unpadded_date_only_literal(): void
    {
        update_option('date_format', 'j. F Y');
        update_option('timezone_string', 'UTC');
        $this->useSources('fluent_cart', 'wordpress');

        $this->assertSame(
            'Aug 6, 2026',
            DateFormatter::format('2026-08-06 15:30:00'),
            "A date-only render must keep the unpadded 'M j, Y' the shortcode parsers and FluentCRM stats used"
        );
    }

    public function test_fluent_cart_source_keeps_the_padded_date_time_literal(): void
    {
        update_option('date_format', 'j. F Y');
        update_option('timezone_string', 'UTC');
        $this->useSources('fluent_cart', 'wordpress');

        $this->assertSame(
            'Aug 06, 2026 03:30 PM',
            DateFormatter::format('2026-08-06 15:30:00', true),
            "A date-and-time render must keep the padded 'M d, Y h:i A' every e-mail view used"
        );
    }

    public function test_the_two_fluent_cart_literals_really_do_differ(): void
    {
        $this->useSources('fluent_cart', 'wordpress');

        $formats = DateFormatter::defaultFormats();

        $this->assertNotSame(
            $formats['date'] . ' ' . $formats['time'],
            $formats['date_time'],
            'date_time is intentionally NOT date + time on this source; if these ever converge, one legacy has been lost'
        );
    }

    // =========================================================================
    // The names PHP renders and the names the SPA receives must be one set
    // =========================================================================

    public function test_wordpress_source_takes_its_names_from_wp_locale(): void
    {
        global $wp_locale;

        update_option('date_format', 'j. F Y');
        update_option('time_format', 'H:i');
        update_option('timezone_string', 'UTC');
        $this->useSources('wordpress', 'wordpress');

        $strings = TransStrings::dateTimeStrings(true);

        // wp_date() renders from $wp_locale. If the payload the SPA receives
        // comes from anywhere else, one locale produces two different answers:
        // PHP said "Aout" while the bundle said "Aug", because fluent-cart
        // ships only a .pot and its own catalogue was never translated.
        $monthIndex = 1;
        foreach ($strings['months'] as $key => $value) {
            $this->assertSame(
                $wp_locale->get_month(str_pad((string)$monthIndex, 2, '0', STR_PAD_LEFT)),
                $value,
                "months[{$key}] must be the name wp_date() would render"
            );
            $monthIndex++;
        }

        $weekdayIndex = 0;
        foreach ($strings['weekdays'] as $key => $value) {
            $this->assertSame(
                $wp_locale->get_weekday($weekdayIndex),
                $value,
                "weekdays[{$key}] must be the name wp_date() would render"
            );
            $weekdayIndex++;
        }

        // The abbreviations are the ones that bite: a locale's short month is
        // not a three-letter cut of the long one. German keeps a trailing
        // period ('Aug.') and never abbreviates Marz/Mai/Juni/Juli at all,
        // and $wp_locale is the only source that knows that.
        $monthIndex = 1;
        foreach ($strings['monthsShort'] as $key => $value) {
            $longName = $wp_locale->get_month(str_pad((string)$monthIndex, 2, '0', STR_PAD_LEFT));
            $this->assertSame(
                $wp_locale->get_month_abbrev($longName),
                $value,
                "monthsShort[{$key}] must be the abbreviation wp_date() would render"
            );
            $monthIndex++;
        }
    }

    public function test_fluent_cart_source_keeps_the_text_domain_names(): void
    {
        update_option('date_format', 'j. F Y');
        update_option('time_format', 'H:i');
        update_option('timezone_string', 'UTC');
        $this->useSources('fluent_cart', 'fluent_cart');

        $strings = TransStrings::dateTimeStrings(true);

        // The FluentCart source exists to reproduce what each side rendered
        // before these settings landed, and the bundle's names came from the
        // fluent-cart text domain. Swapping them here would change the very
        // output this source promises to preserve.
        $this->assertSame(
            _x('August', 'months', 'fluent-cart'),
            $strings['months']['August'],
            'The FluentCart source must keep the text-domain month names'
        );
        $this->assertSame(
            _x('Aug', 'monthsShort', 'fluent-cart'),
            $strings['monthsShort']['aug'],
            'The FluentCart source must keep the text-domain month abbreviations'
        );
    }

    // =========================================================================
    // The date/time separator is a filter, because a space is English
    // =========================================================================

    public function test_date_and_time_are_joined_with_a_space_by_default(): void
    {
        update_option('date_format', 'j. F Y');
        update_option('time_format', 'H:i');
        update_option('timezone_string', 'UTC');
        $this->useSources('wordpress', 'wordpress');

        $this->assertSame(
            '16. August 2026 15:30',
            DateFormatter::format('2026-08-16 15:30:00', true),
            'WordPress core joins the two options with a space; so does the default here'
        );
    }

    public function test_the_date_time_separator_is_filterable(): void
    {
        update_option('date_format', 'j. F Y');
        update_option('time_format', 'H:i');
        update_option('timezone_string', 'UTC');

        add_filter('fluent_cart/date_time_separator', function () {
            return ', ';
        });
        $this->useSources('wordpress', 'wordpress');

        // German separates the date from the time with a comma, and neither
        // WordPress option can express that on its own.
        $this->assertSame(
            '16. August 2026, 15:30',
            DateFormatter::format('2026-08-16 15:30:00', true),
            'A filtered separator must reach the combined format'
        );
    }

    public function test_the_separator_filter_receives_the_two_formats_as_one_context_array(): void
    {
        update_option('date_format', 'j. F Y');
        update_option('time_format', 'H:i');
        update_option('timezone_string', 'UTC');

        $received = null;

        // Repository hook rules cap apply_filters at three args, the third
        // being a read-only context array -- so a listener declaring 2 accepted
        // args must get the whole context, not just the date format.
        add_filter('fluent_cart/date_time_separator', function ($separator, $context) use (&$received) {
            $received = $context;
            return $separator;
        }, 10, 2);
        $this->useSources('wordpress', 'wordpress');

        DateFormatter::format('2026-08-16 15:30:00', true);

        $this->assertSame(
            ['date' => 'j. F Y', 'time' => 'H:i'],
            $received,
            'The separator filter must pass the store formats as one keyed context array'
        );
    }

    public function test_a_filtered_separator_containing_letters_is_not_read_as_tokens(): void
    {
        update_option('date_format', 'j. F Y');
        update_option('time_format', 'H:i');
        update_option('timezone_string', 'UTC');

        add_filter('fluent_cart/date_time_separator', function () {
            return ' um ';
        });
        $this->useSources('wordpress', 'wordpress');

        // The separator lands inside a PHP date() pattern, where 'u' is
        // microseconds and 'm' is the month number. Unescaped, ' um ' renders
        // as ' 00000008 '. A filter must not have to know that.
        $this->assertSame(
            '16. August 2026 um 15:30',
            DateFormatter::format('2026-08-16 15:30:00', true),
            'Letters in a filtered separator must survive as literal text'
        );
    }

    public function test_an_unusable_filtered_separator_falls_back_to_a_space(): void
    {
        update_option('date_format', 'j. F Y');
        update_option('time_format', 'H:i');
        update_option('timezone_string', 'UTC');

        add_filter('fluent_cart/date_time_separator', function () {
            return 12345;
        });
        $this->useSources('wordpress', 'wordpress');

        $this->assertSame(
            '16. August 2026 15:30',
            DateFormatter::format('2026-08-16 15:30:00', true),
            'A non-string separator must not reach the pattern'
        );
    }

    public function test_the_separator_filter_does_not_touch_the_fluent_cart_literal(): void
    {
        update_option('date_format', 'j. F Y');
        update_option('time_format', 'H:i');
        update_option('timezone_string', 'UTC');

        add_filter('fluent_cart/date_time_separator', function () {
            return ', ';
        });
        $this->useSources('fluent_cart', 'fluent_cart');

        // On the FluentCart source date_time is a verbatim literal, not date
        // plus time, so there is no join for the separator to sit in.
        $this->assertSame(
            'Aug 16, 2026 03:30 PM',
            DateFormatter::format('2026-08-16 15:30:00', true),
            'The FluentCart literal must stay byte-identical regardless of the separator filter'
        );
    }

    /**
     * @dataProvider shortDateSources
     */
    public function test_short_date_format_matches_the_js_date_short(string $formatSource, string $expected): void
    {
        update_option('date_format', 'j. F Y');
        $this->useSources($formatSource, 'wordpress');

        $this->assertSame($expected, DateFormatter::shortDateFormat());
        $this->assertSame(
            \FluentCart\App\Services\DateTime\DayjsFormatter::convert($expected),
            \FluentCart\App\Services\DateTime\DayjsFormatter::formats()['date_short']
        );
    }

    public function shortDateSources(): array
    {
        return [
            'wordpress'   => ['wordpress', 'j. M'],
            'fluent_cart' => ['fluent_cart', 'M j'],
        ];
    }
}

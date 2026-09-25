<?php

namespace FluentCart\App\Services\DateTime;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\Framework\Support\Arr;

/**
 * Renders dates using the store's date/time format and timezone preference.
 *
 * Every user-facing date in FluentCart should go through this class rather than
 * calling ->format() with a literal pattern. A literal pattern cannot be
 * localized: DateTime::format() has no locale, so 'M' is always 'Aug', and the
 * day/month order and the 12-hour clock are baked into the source.
 *
 * Two store settings steer the output:
 *
 *  - date_time_format_source: DEFAULTS TO 'wordpress', following Settings >
 *    General. 'fluent_cart' keeps FALLBACK_DATE/FALLBACK_TIME instead.
 *  - timezone_source: defaults to 'fluent_cart', rendering in the timezone
 *    captured on the order at checkout (falling back to UTC); 'wordpress' uses
 *    the site timezone.
 *
 * The format source deliberately does NOT default to reproducing the
 * pre-settings strings, and the reason is worth keeping in view. The FluentCart
 * patterns are literals, so on a localized store wp_date() translates the month
 * name but cannot reorder the fields: German renders 'Juli 30, 2026' -- a German
 * month in English order, which a German reader takes day-first and misreads.
 * A fully English date would at least be recognized as English. There is no
 * literal that is right in every locale, so the default follows the one source
 * that is: the site's own WordPress format. English stores see a changed string
 * on upgrade ('Aug 16, 2026' -> 'August 16, 2026'); a store that wants the old
 * output back selects 'fluent_cart'.
 */
class DateFormatter
{
    /**
     * FluentCart's own patterns — the literals the call sites carried before
     * these settings existed.
     *
     * These were never uniform, and the difference is load-bearing: date-only
     * sites (the shortcode parsers, the FluentCRM contact stats) used 'M j, Y'
     * with an unpadded day, while date-and-time sites (every e-mail view) used
     * 'M d, Y h:i A' with a padded one. Reproducing one from the other would
     * silently renumber whichever side lost, so both are kept verbatim.
     *
     * The JS side has the same split for its own literals; see
     * LEGACY_DATE_FORMATS in utils/dateFormats.js.
     */
    const FALLBACK_DATE      = 'M j, Y';
    const FALLBACK_TIME      = 'h:i A';
    const FALLBACK_DATE_TIME = 'M d, Y h:i A';

    /**
     * Whether the store follows the WordPress date/time format options.
     */
    public static function usesWordPressFormats(): bool
    {
        return (new StoreSettings())->get('date_time_format_source') === 'wordpress';
    }

    /**
     * Whether the store renders dates in the WordPress site timezone.
     */
    public static function usesWordPressTimezone(): bool
    {
        return (new StoreSettings())->get('timezone_source') === 'wordpress';
    }

    /**
     * The store's formats, before the date/time filter runs.
     *
     * Unfiltered on purpose: this is what TransStrings::dateTimeStrings()
     * starts from before running the single `fluent_cart/date_time_strings`
     * filter over the whole map. Read formats() instead of this.
     *
     * @return array<string, string>
     */
    public static function defaultFormats(): array
    {
        if (!static::usesWordPressFormats()) {
            // Verbatim, not derived: date_time is deliberately not date + time
            // here, because the two literals never matched. See the constants.
            return [
                'date'      => static::FALLBACK_DATE,
                'time'      => static::FALLBACK_TIME,
                'date_time' => static::FALLBACK_DATE_TIME,
            ];
        }

        $date = (string)get_option('date_format') ?: static::FALLBACK_DATE;
        $time = (string)get_option('time_format') ?: static::FALLBACK_TIME;

        return [
            'date'      => $date,
            'time'      => $time,
            'date_time' => $date . static::dateTimeSeparator($date, $time) . $time,
        ];
    }

    /**
     * What goes between the date and the time in the combined format.
     *
     * WordPress has no combined date/time option -- core itself joins the two
     * with a space, and that is the default here for the same reason. But the
     * space is an English convention, not a universal one: German writes
     * '16. August 2026, 15:30' with a comma, and a locale that wants no
     * separator at all cannot express that by editing either option.
     *
     * The separator is a LITERAL in a PHP date() pattern, so a separator
     * containing a date token would be rendered as one. Anything a filter
     * returns is escaped before it reaches the pattern, which means a filter
     * can safely return ', ' without knowing that 'a' is the meridiem token.
     *
     * Listeners receive the two store formats as one read-only context array
     * and must register with `add_filter('fluent_cart/date_time_separator',
     * $callback, 10, 2)`.
     *
     * @param string $date The store's date format, for context only
     * @param string $time The store's time format, for context only
     */
    protected static function dateTimeSeparator(string $date, string $time): string
    {
        $separator = apply_filters('fluent_cart/date_time_separator', ' ', [
            'date' => $date,
            'time' => $time,
        ]);

        if (!is_string($separator) || $separator === '') {
            return ' ';
        }

        return static::escapeDateLiteral($separator);
    }

    /**
     * Backslash-escape every character PHP's date() would read as a token.
     *
     * Without this a filter returning ', ' would be fine but one returning
     * ' at ' would render the 'a' as 'pm' and the 't' as the month length.
     */
    protected static function escapeDateLiteral(string $literal): string
    {
        $out    = '';
        $length = strlen($literal);

        for ($i = 0; $i < $length; $i++) {
            $char = $literal[$i];
            // Only ASCII letters and the backslash are meaningful to date();
            // punctuation, digits and multibyte characters pass through.
            // ctype_alpha() rather than a regex: the character class needed to
            // match a backslash is itself a backslash-escaping trap.
            if (ctype_alpha($char) || $char === chr(92)) {
                $out .= chr(92);
            }
            $out .= $char;
        }

        return $out;
    }

    /**
     * The store's formats after the single date/time filter has run.
     *
     * @return array<string, string>
     */
    public static function formats(): array
    {
        return (array)Arr::get(TransStrings::dateTimeStrings(), 'formats', []);
    }

    /**
     * The store's date format, e.g. 'F j, Y' or 'j. F Y'.
     */
    public static function dateFormat(): string
    {
        return (string)Arr::get(static::formats(), 'date', static::FALLBACK_DATE);
    }

    /**
     * The store's time format, e.g. 'g:i a' or 'H:i'.
     */
    public static function timeFormat(): string
    {
        return (string)Arr::get(static::formats(), 'time', static::FALLBACK_TIME);
    }

    /**
     * The store's month-and-year pattern, e.g. 'F Y' or 'Y年n月'.
     *
     * There is no WordPress setting for this, so derive it from the store's date
     * format by dropping the day. That keeps a year-first or suffix-marked locale
     * in its own field order instead of assuming the English 'month year', and it
     * matches how DayjsFormatter derives `month_year` for the charts.
     */
    public static function monthYearFormat(): string
    {
        return DayjsFormatter::withoutDay(static::dateFormat());
    }

    /**
     * The store's hour-only pattern for an hour-of-day axis, 'H' or 'g A'.
     *
     * Derived from the store's time format: if it asks for a 24-hour clock
     * (G or H), label the axis 0-23 rather than forcing 1 AM - 12 PM.
     */
    public static function hourFormat(): string
    {
        $time = static::timeFormat();

        return (strpos($time, 'H') !== false || strpos($time, 'G') !== false) ? 'H' : 'g A';
    }

    /**
     * The timezone a date should be displayed in.
     *
     * On 'wordpress' this is always the site timezone. On 'fluent_cart' there is
     * no browser to read here — PHP renders e-mails from cron and invoices from
     * a queue — so we use the timezone captured on the order at checkout, which
     * is the same source ReceiptRenderer and OrderParser already read. Records
     * with no order behind them (customer-level dates, for instance) fall back
     * to UTC, which is how they rendered before these settings existed.
     *
     * @param mixed $context Order model, order config array, timezone string, or null
     */
    public static function displayTimezone($context = null): \DateTimeZone
    {
        if (static::usesWordPressTimezone()) {
            return wp_timezone();
        }

        $timezone = static::contextTimezone($context);

        // timezone_open() first: PHP 8.4 throws on deprecated aliases like
        // Asia/Saigon, and user_tz is browser-captured, so it can carry one.
        if ($timezone !== '' && @timezone_open($timezone) !== false) {
            return new \DateTimeZone($timezone);
        }

        return new \DateTimeZone('UTC');
    }

    /**
     * Pull a timezone name out of whatever the call site had to hand.
     *
     * @param mixed $context
     */
    protected static function contextTimezone($context): string
    {
        if ($context instanceof \DateTimeZone) {
            return $context->getName();
        }

        if (is_string($context)) {
            return $context;
        }

        if (is_array($context)) {
            return (string)Arr::get($context, 'user_tz', '');
        }

        if (is_object($context) && isset($context->config)) {
            return (string)Arr::get((array)$context->config, 'user_tz', '');
        }

        return '';
    }

    /**
     * Format a GMT/UTC datetime for display.
     *
     * @param string|\DateTimeInterface|int|null $datetime GMT datetime, timestamp, or null for now
     * @param bool $withTime Append the store's time format
     * @param mixed $context Order model, order config array, or timezone string,
     *                       used only when timezone_source is 'fluent_cart'
     * @return string Empty string when $datetime is absent or unparseable
     */
    public static function format($datetime, bool $withTime = false, $context = null): string
    {
        if ($datetime === null || $datetime === '' || $datetime === '0000-00-00 00:00:00') {
            return '';
        }

        try {
            // Only the instant matters here; displayTimezone() decides how it renders.
            $timestamp = DateTime::gmtToTimezone($datetime, new \DateTimeZone('UTC'))->getTimestamp();
        } catch (\Exception $e) {
            return '';
        }

        $key    = $withTime ? 'date_time' : 'date';
        $format = (string)Arr::get(static::formats(), $key, static::dateFormat());

        // wp_date() applies the translated month/weekday names from WordPress
        // core, which DateTime::format() cannot do.
        return (string)wp_date($format, $timestamp, static::displayTimezone($context));
    }
}

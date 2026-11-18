<?php

namespace FluentCart\App\Modules\MCP\Support;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Api\CurrencySettings;

/**
 * Shared formatting + validation utilities for the FluentCart MCP module.
 *
 * Mirrors FluentCRM's MCPHelper role: every tool funnels its output through
 * here so responses are uniform, token-lean, and safe for an AI agent to
 * reason over. Three rules this file enforces everywhere:
 *
 *   1. Money leaves the boundary exactly once, never as raw cents. Detail
 *      views get {amount, amount_cents, currency, display}; list rows get a
 *      compact decimal + a shared meta.currency (see money() vs moneyCompact()).
 *   2. Dates are ISO-8601 UTC strings — never the raw DB datetime, never a
 *      timezone-ambiguous value.
 *   3. Every successful tool returns the same envelope: a one-line `summary`
 *      the agent can quote, the `data`, and `meta` (schema_version, paging,
 *      currency, warnings, truncation). Errors return WP_Error so the adapter
 *      surfaces them as isError results the agent can self-correct against.
 */
class MCPHelper
{
    const SCHEMA_VERSION = '1.0';

    // Default per-tool ceiling. Individual tools may opt up to HARD_MAX_PER_PAGE
    // when their rows are compact (see pagination()'s $maxPerPage).
    const MAX_PER_PAGE = 100;

    // Absolute ceiling no tool can exceed, however large a per_page it requests.
    const HARD_MAX_PER_PAGE = 200;

    const PREVIEW_CHARS = 150;

    /**
     * The canonical success envelope. Returning an array (not echoing) lets the
     * MCP Adapter serialize it into structuredContent + a text digest.
     *
     * @param string $summary One human-readable line. The agent quotes this; it
     *                        should answer the question, not restate the schema.
     * @param mixed  $data    The payload.
     * @param array  $meta    Merged into the meta block (paging, range, etc.).
     */
    public static function envelope($summary, $data, array $meta = [])
    {
        $base = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at'   => self::toIso8601(DateTime::gmtNow()),
            'currency'       => self::currencyCode(),
        ];

        return [
            'summary' => $summary,
            'data'    => $data,
            'meta'    => array_merge($base, $meta),
        ];
    }

    /**
     * Structured, self-correcting error. `code` is a stable machine string the
     * agent can branch on; `message` says what went wrong + what was expected;
     * `details` can carry hint / required_permission / current_state / next_tool.
     */
    public static function error($code, $message, array $details = [])
    {
        // The MCP adapter forwards only the WP_Error *message* to the agent — it
        // drops error_data. So we encode a structured envelope INTO the message
        // as JSON, mirroring our success payloads, so the agent can branch on a
        // stable `code`, see which `fields` were at fault, read a `hint`/
        // `next_step`, and know whether retrying the identical call could succeed
        // (`retryable`, default false). Humans read error.message.
        $error = array_merge([
            'code'      => $code,
            'message'   => $message,
            'retryable' => false,
        ], $details);

        $json = wp_json_encode(['error' => $error]);

        return new \WP_Error($code, $json !== false ? $json : $message, $details);
    }

    // -----------------------------------------------------------------
    // Money
    // -----------------------------------------------------------------

    /**
     * Full money object for detail views. The agent never does cents math and
     * never mis-renders: `amount` is the decimal number for comparisons,
     * `display` is the ready-to-quote string.
     *
     * @param int|null    $cents
     * @param string|null $currencyCode Falls back to the store currency.
     */
    public static function money($cents, $currencyCode = null)
    {
        $cents = (int) $cents;
        // Normalize to uppercase ISO-4217 — gateway values can arrive lowercase
        // (Stripe stores "usd"); the agent should always see "USD".
        $code  = strtoupper($currencyCode ? $currencyCode : self::currencyCode());

        return [
            'amount'       => Helper::toDecimalWithoutComma($cents),
            'amount_cents' => $cents,
            'currency'     => $code,
            'display'      => self::displayAmount($cents, $code),
        ];
    }

    /**
     * Formatted, agent-readable money string. Helper::toDecimal HTML-encodes the
     * currency sign (e.g. "&#36;19.99"); we decode it so the agent sees "$19.99".
     */
    public static function displayAmount($cents, $currencyCode = null)
    {
        $code = $currencyCode ? $currencyCode : self::currencyCode();

        return html_entity_decode(Helper::toDecimal((int) $cents, true, $code), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Compact money for list rows: just the decimal number. The currency lives
     * once in meta.currency, so we don't repeat it on every row (token saving).
     * Only fall back to the full object when a result set spans currencies.
     */
    public static function moneyCompact($cents)
    {
        return Helper::toDecimalWithoutComma((int) $cents);
    }

    public static function currencyCode()
    {
        $code = CurrencySettings::get('currency');

        return $code ? $code : 'USD';
    }

    /**
     * Full currency descriptor for get-store-context, so the agent can format
     * money itself when it wants to (zero-decimal currencies, separators, etc.).
     */
    public static function currencyContext()
    {
        $settings = CurrencySettings::get();
        if (!is_array($settings)) {
            $settings = [];
        }

        $code = strtoupper(isset($settings['currency']) ? $settings['currency'] : 'USD');

        // Derive decimals exactly how Helper::toDecimal does: 2 places, or 0 for
        // a zero-decimal currency. Reading a stored decimal_points key drifted
        // from the actual money formatting (reported 0 for USD while amounts
        // rendered with 2 places).
        $isZeroDecimal = (bool) Helper::shopConfig('is_zero_decimal');

        return [
            'code'            => $code,
            'sign'            => isset($settings['currency_sign']) ? $settings['currency_sign'] : '$',
            'position'        => isset($settings['currency_position']) ? $settings['currency_position'] : 'before',
            'decimal_points'  => $isZeroDecimal ? 0 : 2,
            'is_zero_decimal' => $isZeroDecimal,
            'example'         => Helper::toDecimal(123456, true, $code),
        ];
    }

    // -----------------------------------------------------------------
    // Dates
    // -----------------------------------------------------------------

    /**
     * Whether a date input starts with a real calendar day. PHP alone is too lenient:
     * it reads "x" as a timezone and rolls 2026-02-31 into March.
     */
    public static function isReadableDate($value)
    {
        $value = trim((string) $value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:$|[T ])/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return false;
        }
        try {
            new \DateTime($value);
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * A date filter bound as a stored UTC 'Y-m-d H:i:s', or null when it is not a date.
     *
     * A value with no offset is store time, as in the report windows, so a list and a
     * report agree on what a day is; a day alone covers the whole store day. A value
     * with an offset or Z is that exact moment.
     *
     * @param bool $isEnd true for an upper bound: a day alone then ends at 23:59:59
     */
    public static function storeDateBound($value, $isEnd = false)
    {
        $value = trim((string) $value);
        if (!self::isReadableDate($value)) {
            return null;
        }

        $date = new \DateTime($value, wp_timezone());
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $date->setTime($isEnd ? 23 : 0, $isEnd ? 59 : 0, $isEnd ? 59 : 0);
        }

        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * The error for the first unreadable date among the named params, or null.
     */
    public static function unreadableDateError(array $params, array $fields)
    {
        foreach ($fields as $field) {
            if (!empty($params[$field]) && !self::isReadableDate($params[$field])) {
                return self::error(
                    'invalid_param',
                    sprintf('%1$s is not a valid date. Use YYYY-MM-DD or ISO 8601.', $field),
                    ['fields' => [$field]]
                );
            }
        }

        return null;
    }

    /**
     * The error for a start bound later than its end bound, or null. A reversed filter
     * matches nothing, which reads as "no records" instead of a mistake.
     *
     * @param array $pairs after-param => before-param
     */
    public static function reversedBoundsError(array $params, array $pairs)
    {
        foreach ($pairs as $after => $before) {
            if (empty($params[$after]) || empty($params[$before])) {
                continue;
            }
            // The same reading the filter uses: store time, and a bare end day runs to 23:59:59
            $from = self::storeDateBound($params[$after], false);
            $to   = self::storeDateBound($params[$before], true);
            if ($from !== null && $to !== null && $from > $to) {
                return self::error(
                    'invalid_param',
                    sprintf('%1$s is later than %2$s. Swap the two dates and retry.', $after, $before),
                    ['fields' => [$after, $before]]
                );
            }
        }

        return null;
    }

    /**
     * Normalize any stored datetime to an ISO-8601 UTC string. Accepts a
     * DateTime, a {date,timezone} object, or a Y-m-d H:i:s string (DB values
     * are GMT). Returns null for empty input so the key stays present.
     */
    public static function toIso8601($value)
    {
        if (!$value) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        if (is_object($value) && isset($value->date)) {
            $tz = isset($value->timezone) ? $value->timezone : 'UTC';
            return (new \DateTime($value->date, new \DateTimeZone($tz)))->format('c');
        }

        if (is_string($value)) {
            // MySQL zero-dates ('0000-00-00 00:00:00') are truthy strings but not
            // real dates; DateTime underflows them to year -0001 and emits a
            // misleading '-001-11-30...'. Treat them as empty.
            if (strpos($value, '0000-00-00') === 0) {
                return null;
            }
            try {
                $dt = new \DateTime($value, new \DateTimeZone('UTC'));
                // Guard any other underflow to a non-positive year.
                if ((int) $dt->format('Y') < 1) {
                    return null;
                }
                return $dt->format('c');
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    // -----------------------------------------------------------------
    // Text
    // -----------------------------------------------------------------

    /** Strip HTML/markup to clean plain text — agents reason better over text than markup. */
    public static function htmlToText($html)
    {
        if (!$html) {
            return '';
        }

        $text = wp_strip_all_tags((string) $html);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /** Truncated preview for list rows so descriptions/notes don't blow context. */
    public static function preview($html, $chars = self::PREVIEW_CHARS)
    {
        $text = self::htmlToText($html);
        if (mb_strlen($text) > $chars) {
            return mb_substr($text, 0, $chars) . '…';
        }

        return $text;
    }

    // -----------------------------------------------------------------
    // Pagination
    // -----------------------------------------------------------------

    /**
     * Clamp page/per_page from agent input. Defaults small (15) and caps at 100
     * so a careless `per_page: 5000` can never flood the context window.
     *
     * $maxPerPage lets a specific tool raise its own ceiling above the shared
     * default (e.g. compact subscription rows tolerate 200) without lifting the
     * cap for every other list tool. It is itself clamped to MAX_PER_PAGE so a
     * caller can never push past the global guardrail.
     *
     * @return array{page:int, per_page:int}
     */
    public static function pagination($params, $defaultPerPage = 15, $maxPerPage = self::MAX_PER_PAGE)
    {
        $page    = isset($params['page']) ? (int) $params['page'] : 1;
        $perPage = isset($params['per_page']) ? (int) $params['per_page'] : $defaultPerPage;

        $max = ($maxPerPage > self::HARD_MAX_PER_PAGE) ? self::HARD_MAX_PER_PAGE : (int) $maxPerPage;

        if ($page < 1) {
            $page = 1;
        }
        if ($perPage < 1) {
            $perPage = $defaultPerPage;
        }
        if ($perPage > $max) {
            $perPage = $max;
        }

        return ['page' => $page, 'per_page' => $perPage];
    }

    /**
     * Build the meta.page block from a FluentCart Paginator (which exposes
     * current_page / per_page / total / last_page). Gives the agent everything
     * it needs to decide whether to fetch the next page.
     */
    public static function pagingMeta($paginator)
    {
        if (is_object($paginator) && method_exists($paginator, 'total')) {
            $current = method_exists($paginator, 'currentPage') ? (int) $paginator->currentPage() : 1;
            $perPage = method_exists($paginator, 'perPage') ? (int) $paginator->perPage() : 0;
            $total   = (int) $paginator->total();
            $last    = method_exists($paginator, 'lastPage') ? (int) $paginator->lastPage() : 1;
        } else {
            $arr     = is_array($paginator) ? $paginator : (array) $paginator;
            $current = isset($arr['current_page']) ? (int) $arr['current_page'] : 1;
            $perPage = isset($arr['per_page']) ? (int) $arr['per_page'] : 0;
            $total   = isset($arr['total']) ? (int) $arr['total'] : 0;
            $last    = isset($arr['last_page']) ? (int) $arr['last_page'] : 1;
        }

        return [
            'page'     => [
                'current'  => $current,
                'per_page' => $perPage,
                'total'    => $total,
                'pages'    => $last,
                'has_more' => $current < $last,
            ],
        ];
    }

    /** Total row count from a Paginator (uses ->total() method; array fallback). */
    public static function paginatorTotal($paginator)
    {
        if (is_object($paginator) && method_exists($paginator, 'total')) {
            return (int) $paginator->total();
        }
        $arr = is_array($paginator) ? $paginator : (array) $paginator;
        return isset($arr['total']) ? (int) $arr['total'] : 0;
    }

    /** Pull the row models out of a Paginator regardless of its concrete shape. */
    public static function paginatorItems($paginator)
    {
        if (is_object($paginator) && method_exists($paginator, 'items')) {
            return $paginator->items();
        }

        $arr = is_array($paginator) ? $paginator : (array) $paginator;

        return isset($arr['data']) ? $arr['data'] : [];
    }

    // -----------------------------------------------------------------
    // People / labels
    // -----------------------------------------------------------------

    // -----------------------------------------------------------------
    // Field selection
    // -----------------------------------------------------------------

    /**
     * Project a record down to a caller-requested subset of top-level keys, to
     * shrink heavy payloads. Returns the record UNCHANGED when $fields is empty
     * or not an array (the default, backward-compatible behavior). Only keys that
     * actually exist are kept, in the record's own order; unknown requested keys
     * are ignored. Keys in $alwaysKeep (the record's identifier) are retained
     * regardless so a projected record is never anonymous.
     *
     * @param array $row
     * @param mixed $fields     array of key names, or null/non-array for "all"
     * @param array $alwaysKeep keys to keep even if not requested (e.g. the id)
     */
    public static function pickFields($row, $fields, array $alwaysKeep = [])
    {
        if (empty($fields) || !is_array($fields)) {
            return $row;
        }

        $wanted = [];
        foreach ($alwaysKeep as $k) {
            $wanted[$k] = true;
        }
        foreach ($fields as $f) {
            $wanted[(string) $f] = true;
        }

        $out = [];
        foreach ($row as $key => $val) {
            if (isset($wanted[$key])) {
                $out[$key] = $val;
            }
        }
        return $out;
    }

    /** "First Last <email>" style name from a customer/person-ish model. */
    public static function personName($model)
    {
        if (!$model) {
            return null;
        }

        $first = isset($model->first_name) ? $model->first_name : '';
        $last  = isset($model->last_name) ? $model->last_name : '';
        $name  = trim($first . ' ' . $last);

        return $name !== '' ? $name : null;
    }
}

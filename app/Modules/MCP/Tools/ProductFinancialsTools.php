<?php

namespace FluentCart\App\Modules\MCP\Tools;

use FluentCart\App\Models\Product;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\MCP\Support\MCPHelper;
use FluentCart\App\Modules\MCP\Support\PermissionGate;
use FluentCart\App\Modules\MCP\Support\ProductFinancialsCalculator as Calc;

/**
 * get-product-financials — the one honest financial picture for a single
 * product: one-time revenue, finite split-pay commitments, and perpetual
 * run-rate (MRR/ARR + forward schedule), with no cross-currency summing and no
 * folding of forward commitments into "revenue this period".
 *
 * The subtle rule the whole tool is built around (see spec §6):
 *   - The `window` (range/date_from/date_to + date_basis) applies to the
 *     one_time block ONLY.
 *   - Everything under subscriptions.*, payment_schedule, and totals is
 *     point-in-time as of `as_of` (lifetime-to-date or forward).
 * This is echoed back in meta.field_semantics so an agent cannot misread it.
 *
 * All math lives in the pure ProductFinancialsCalculator (unit-tested without a
 * DB); this class only loads rows, scopes currency, and wraps money.
 */
class ProductFinancialsTools
{
    /** Payment statuses that count as realized revenue (matches ReportTools/ContextTools). */
    const PAID = ['paid', 'partially_paid', 'partially_refunded'];

    /** Safety ceiling on subscriptions loaded for one product. */
    const MAX_SUBS = 50000;

    public static function definitions()
    {
        return [
            'fluent-cart/get-product-financials' => [
                'label'       => __('Get Product Financials', 'fluent-cart'),
                'description' => __('One product\'s complete financials in a call: one-time revenue, finite split-pay commitments (bill_times > 0), and perpetual run-rate (MRR/ARR) with a forward payment_schedule. Never sums across currencies. KEY: the time window (range/date_from/date_to + date_basis) applies ONLY to the one_time block — subscriptions.*, payment_schedule and totals are point-in-time as of as_of (lifetime-to-date or forward), so "revenue in the last 30 days" must read one_time.* only and never folds in contract value or MRR. For a windowed time series use get-sales-trend; for renewal cash in a period use query-products with order_type=renewal. totals.total_contracted is null while any perpetual subscription is active — read recurring.mrr/arr and payment_schedule instead, and meta.notes says why. Single currency: store default, other currencies for the product listed in meta.other_currencies. Money is {amount, amount_cents, currency, display}.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'product_id'   => ['type' => 'integer', 'description' => 'Product to report on.'],
                        'variation_id' => ['type' => 'integer', 'description' => 'Restrict to one variation.'],
                        'currency'     => ['type' => 'string', 'description' => 'ISO currency for the (single-currency) report. Defaults to store currency.'],
                        'range'        => ['type' => 'string', 'enum' => ['today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'mtd', 'qtd', 'ytd', 'last_quarter', 'last_year', 'all_time', 'since_launch'], 'default' => 'all_time', 'description' => 'Window for the one_time block only. Resolved in UTC. all_time (alias: since_launch) spans all data.'],
                        'date_from'    => ['type' => 'string', 'description' => 'YYYY-MM-DD or ISO 8601, UTC. Overrides range.'],
                        'date_to'      => ['type' => 'string', 'description' => 'YYYY-MM-DD or ISO 8601, UTC. Overrides range.'],
                        'date_basis'   => ['type' => 'string', 'enum' => ['created_at', 'paid_at'], 'default' => 'paid_at', 'description' => 'One-time window basis: paid_at = order completed_at (cash received); created_at = order placed. Does not affect forward fields.'],
                        'as_of'        => ['type' => 'string', 'description' => 'ISO 8601, UTC. Point-in-time for forward metrics. Default now.'],
                        'horizon'      => ['type' => 'string', 'enum' => ['3m', '6m', '12m'], 'default' => '12m', 'description' => 'How far to project payment_schedule.'],
                        'schedule_bucket' => ['type' => 'string', 'enum' => ['month', 'week'], 'default' => 'month', 'description' => 'Calendar granularity of payment_schedule.'],
                        'subscription_status' => [
                            'type'        => 'array',
                            'description' => 'Which statuses feed forward metrics (scheduled_remaining, mrr, next_*_scheduled, payment_schedule). Default [active]. Use [all] to include every status. status_breakdown is always the full picture regardless.',
                            'items'       => ['type' => 'string', 'enum' => ['active', 'trialing', 'past_due', 'paused', 'canceled', 'all']],
                        ],
                        'include' => [
                            'type'        => 'array',
                            'description' => 'Opt-in sections. schedule = the full payment_schedule[] (can be long; next_30d/next_90d scalars are always returned).',
                            'items'       => ['type' => 'string', 'enum' => ['schedule']],
                        ],
                    ],
                    'required' => ['product_id'],
                ],
                'execute_callback'    => [self::class, 'getProductFinancials'],
                'permission_callback' => function () {
                    return PermissionGate::can('reports/view');
                },
                'annotations' => ['readonly' => true],
            ],
        ];
    }

    public static function getProductFinancials($params = [])
    {
        if (empty($params['product_id'])) {
            return MCPHelper::error('missing_identifier', __('product_id is required.', 'fluent-cart'));
        }
        $productId = (int) $params['product_id'];

        $product = Product::query()->where('ID', $productId)->first();
        if (!$product) {
            return MCPHelper::error('product_not_found', __('No product found for the given product_id.', 'fluent-cart'));
        }

        $variationId = !empty($params['variation_id']) ? (int) $params['variation_id'] : null;
        $currency    = !empty($params['currency']) ? strtoupper(sanitize_text_field($params['currency'])) : MCPHelper::currencyCode();

        $window  = self::resolveWindow($params);
        if (is_wp_error($window)) {
            return $window;
        }
        $asOf    = self::resolveAsOf($params);
        $horizon = self::resolveHorizonMonths($params);
        $bucket  = (isset($params['schedule_bucket']) && $params['schedule_bucket'] === 'week') ? 'week' : 'month';
        $forward = self::resolveForwardStatuses($params);
        $include = isset($params['include']) ? (array) $params['include'] : [];

        // ---- One-time revenue (respects window + date_basis) ----
        $oneTime = self::loadOneTime($productId, $variationId, $currency, $window);

        // ---- Subscriptions (all statuses so status_breakdown is complete) ----
        $subLoad = self::loadSubscriptions($productId, $variationId);
        list($kept, $otherCurrencies) = Calc::filterByCurrency($subLoad['rows'], $currency);

        // ---- Pure computation ----
        $result = Calc::compute($kept, [
            'as_of'            => $asOf,
            'horizon_months'   => $horizon,
            'bucket'           => $bucket,
            'forward_statuses' => $forward,
            'include_schedule' => in_array('schedule', $include, true),
            'one_time'         => $oneTime,
        ]);

        $data = self::formatData($product, $currency, $asOf, $window, $result);

        $meta = [
            'currency'         => $currency,
            'other_currencies' => $otherCurrencies,
            'field_semantics'  => [
                'respect_window' => ['one_time'],
                'point_in_time'  => ['subscriptions', 'payment_schedule', 'totals'],
            ],
            'notes'            => $result['meta_notes'],
        ];
        if ($subLoad['truncated']) {
            $meta['warnings'] = [sprintf(
                /* translators: %1$d: subscription load cap */
                __('More than %1$d subscriptions exist for this product; figures use the first %1$d and may be incomplete.', 'fluent-cart'),
                self::MAX_SUBS
            )];
        }

        return MCPHelper::envelope(self::summary($product, $currency, $result), $data, $meta);
    }

    // -----------------------------------------------------------------
    // Loaders
    // -----------------------------------------------------------------

    /**
     * One-time revenue: non-subscription line items on realized-revenue orders,
     * scoped to the report currency and the window (by date_basis column on the
     * parent order). Returns integer cents/counts for the calculator.
     */
    private static function loadOneTime($productId, $variationId, $currency, $window)
    {
        $dateCol = $window['basis'] === 'created_at' ? 'created_at' : 'completed_at';
        $from    = $window['from'];
        $to      = $window['to'];

        $query = OrderItem::query()
            ->where('post_id', $productId)
            ->where('payment_type', '!=', 'subscription')
            ->whereHas('order', function ($q) use ($currency, $dateCol, $from, $to) {
                $q->whereIn('payment_status', self::PAID)
                    ->where('currency', $currency)
                    ->where($dateCol, '>=', $from)
                    ->where($dateCol, '<=', $to);
            });

        if ($variationId !== null) {
            $query->where('object_id', $variationId);
        }

        $row = $query->selectRaw(
            'COALESCE(SUM(quantity), 0) as units, '
            . 'COALESCE(SUM(line_total), 0) as gross, '
            . 'COALESCE(SUM(refund_total), 0) as refunds, '
            . 'COUNT(DISTINCT order_id) as orders'
        )->first();

        return [
            'units'   => $row ? (int) $row->units : 0,
            'gross'   => $row ? (int) $row->gross : 0,
            'refunds' => $row ? (int) $row->refunds : 0,
            'orders'  => $row ? (int) $row->orders : 0,
        ];
    }

    /**
     * All subscriptions for the product (every status — the breakdown must be
     * complete). Currency is derived per row from config JSON (there is no
     * currency column) so the calculator can scope by it. Columns are limited
     * and the model is read attribute-by-attribute so the heavy $appends
     * accessors (url, billingInfo, overridden_status, …) never fire.
     */
    private static function loadSubscriptions($productId, $variationId)
    {
        $store = MCPHelper::currencyCode();

        $query = Subscription::query()->where('product_id', $productId);
        if ($variationId !== null) {
            $query->where('variation_id', $variationId);
        }

        /** @var \FluentCart\Framework\Database\Orm\Collection $subs */
        $subs = $query
            ->orderBy('id', 'ASC')
            ->limit(self::MAX_SUBS + 1)
            ->get(['id', 'billing_interval', 'recurring_total', 'bill_count', 'bill_times', 'status', 'next_billing_date', 'variation_id', 'config']);

        $truncated = false;
        if (method_exists($subs, 'count') && $subs->count() > self::MAX_SUBS) {
            $truncated = true;
            $subs = $subs->slice(0, self::MAX_SUBS);
        }

        $rows = [];
        foreach ($subs as $sub) {
            $config = is_array($sub->config) ? $sub->config : [];
            $cur    = isset($config['currency']) && $config['currency'] !== '' ? strtoupper((string) $config['currency']) : strtoupper($store);
            $rows[] = [
                'currency'          => $cur,
                'billing_interval'  => $sub->billing_interval,
                'recurring_total'   => (int) $sub->recurring_total,
                'bill_count'        => (int) $sub->bill_count,
                'bill_times'        => (int) $sub->bill_times,
                'status'            => (string) $sub->status,
                'next_billing_date' => $sub->next_billing_date,
            ];
        }

        return ['rows' => $rows, 'truncated' => $truncated];
    }

    // -----------------------------------------------------------------
    // Formatting (cents -> money envelope)
    // -----------------------------------------------------------------

    private static function formatData($product, $currency, $asOf, $window, $result)
    {
        $data = [
            'product_id'   => (int) $product->ID,
            'product_name' => $product->post_title,
            'currency'     => $currency,
            'as_of'        => MCPHelper::toIso8601($asOf),
            'window'       => [
                'from'  => MCPHelper::toIso8601($window['from']),
                'to'    => MCPHelper::toIso8601($window['to']),
                'range' => $window['range'],
                'basis' => $window['basis'] === 'created_at' ? 'created_at' : 'paid_at',
            ],
            'one_time'      => self::formatOneTime($result['one_time'], $currency),
            'subscriptions' => self::formatSubscriptions($result['subscriptions'], $currency),
            'totals'        => self::formatTotals($result['totals'], $currency),
        ];

        if ($result['payment_schedule'] !== null) {
            $data['payment_schedule'] = self::formatSchedule($result['payment_schedule'], $currency);
        }

        return $data;
    }

    private static function formatOneTime($o, $currency)
    {
        if ($o === null) {
            return null;
        }
        return [
            'units'           => $o['units'],
            'orders'          => $o['orders'],
            'gross_collected' => MCPHelper::money($o['gross_collected'], $currency),
            'refunds'         => MCPHelper::money($o['refunds'], $currency),
            'net_collected'   => MCPHelper::money($o['net_collected'], $currency),
            'aov'             => MCPHelper::money($o['aov'], $currency),
        ];
    }

    private static function formatSubscriptions($s, $currency)
    {
        $finite = $s['finite'];
        $finiteByInterval = [];
        foreach ($finite['by_interval'] as $iv => $b) {
            $finiteByInterval[$iv] = [
                'count'               => $b['count'],
                'scheduled_remaining' => MCPHelper::money($b['scheduled_remaining'], $currency),
            ];
        }

        $recurring = $s['recurring'];
        $recurringByInterval = [];
        foreach ($recurring['by_interval'] as $iv => $b) {
            $recurringByInterval[$iv] = [
                'count'               => $b['count'],
                'recurring_total_sum' => MCPHelper::money($b['recurring_total_sum'], $currency),
                'mrr'                 => MCPHelper::money($b['mrr'], $currency),
                'next_30d_scheduled'  => MCPHelper::money($b['next_30d_scheduled'], $currency),
            ];
        }

        return [
            'finite' => [
                'count'                  => $finite['count'],
                'collected_to_date'      => MCPHelper::money($finite['collected_to_date'], $currency),
                'scheduled_remaining'    => MCPHelper::money($finite['scheduled_remaining'], $currency),
                'remaining_installments' => $finite['remaining_installments'],
                'total_contract_value'   => MCPHelper::money($finite['total_contract_value'], $currency),
                'avg_completion'         => $finite['avg_completion'],
                'by_interval'            => $finiteByInterval,
            ],
            'recurring' => [
                'count'              => $recurring['count'],
                'collected_to_date'  => MCPHelper::money($recurring['collected_to_date'], $currency),
                'mrr'                => MCPHelper::money($recurring['mrr'], $currency),
                'arr'                => MCPHelper::money($recurring['arr'], $currency),
                'by_interval'        => $recurringByInterval,
                'next_30d_scheduled' => MCPHelper::money($recurring['next_30d_scheduled'], $currency),
                'next_90d_scheduled' => MCPHelper::money($recurring['next_90d_scheduled'], $currency),
            ],
            'status_breakdown' => $s['status_breakdown'],
        ];
    }

    private static function formatTotals($t, $currency)
    {
        return [
            'collected_to_date' => MCPHelper::money($t['collected_to_date'], $currency),
            'committed_finite'  => MCPHelper::money($t['committed_finite'], $currency),
            'total_contracted'  => $t['total_contracted'] === null ? null : MCPHelper::money($t['total_contracted'], $currency),
            'mrr'               => MCPHelper::money($t['mrr'], $currency),
            'arr'               => MCPHelper::money($t['arr'], $currency),
        ];
    }

    private static function formatSchedule($schedule, $currency)
    {
        $out = [];
        foreach ($schedule as $b) {
            $out[] = [
                'period'              => $b['period'],
                'finite_installments' => MCPHelper::money($b['finite_installments'], $currency),
                'recurring_renewals'  => MCPHelper::money($b['recurring_renewals'], $currency),
                'total_expected'      => MCPHelper::money($b['total_expected'], $currency),
            ];
        }
        return $out;
    }

    private static function summary($product, $currency, $result)
    {
        $collected = MCPHelper::displayAmount($result['totals']['collected_to_date'], $currency);

        if ($result['has_perpetual']) {
            return sprintf(
                /* translators: 1: product title, 2: collected to date, 3: MRR, 4: ARR */
                __('%1$s — %2$s collected to date; MRR %3$s (ARR %4$s). No single total_contracted: this product has perpetual subscriptions.', 'fluent-cart'),
                $product->post_title,
                $collected,
                MCPHelper::displayAmount($result['totals']['mrr'], $currency),
                MCPHelper::displayAmount($result['totals']['arr'], $currency)
            );
        }

        $contracted = $result['totals']['total_contracted'] === null
            ? MCPHelper::displayAmount(0, $currency)
            : MCPHelper::displayAmount($result['totals']['total_contracted'], $currency);

        return sprintf(
            /* translators: 1: product title, 2: collected to date, 3: total contracted */
            __('%1$s — %2$s collected to date; total contracted %3$s (all one-time and/or finite split-pay).', 'fluent-cart'),
            $product->post_title,
            $collected,
            $contracted
        );
    }

    // -----------------------------------------------------------------
    // Param resolvers
    // -----------------------------------------------------------------

    private static function resolveAsOf($params)
    {
        if (!empty($params['as_of'])) {
            try {
                return (new \DateTime((string) $params['as_of'], new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                // fall through to now
            }
        }
        return gmdate('Y-m-d H:i:s');
    }

    private static function resolveHorizonMonths($params)
    {
        $map = ['3m' => 3, '6m' => 6, '12m' => 12];
        $h   = isset($params['horizon']) ? (string) $params['horizon'] : '12m';
        return isset($map[$h]) ? $map[$h] : 12;
    }

    private static function resolveForwardStatuses($params)
    {
        $allowed = ['active', 'trialing', 'past_due', 'paused', 'canceled', 'all'];
        $in      = isset($params['subscription_status']) ? (array) $params['subscription_status'] : ['active'];
        $out     = [];
        foreach ($in as $s) {
            $s = strtolower(trim((string) $s));
            if (in_array($s, $allowed, true) && !in_array($s, $out, true)) {
                $out[] = $s;
            }
        }
        if (empty($out)) {
            $out = ['active'];
        }
        if (in_array('all', $out, true)) {
            return ['all'];
        }
        return $out;
    }

    /**
     * Resolve range / date_from / date_to into a UTC window for the one_time
     * block. Mirrors the report tools: relative ranges resolve in UTC, custom
     * dates override. all_time spans epoch..now.
     *
     * @return array{from:string,to:string,range:string,basis:string}|\WP_Error
     */
    private static function resolveWindow($params)
    {
        $tz    = new \DateTimeZone('UTC');
        $basis = (isset($params['date_basis']) && $params['date_basis'] === 'created_at') ? 'created_at' : 'paid_at';

        // Explicit custom dates take precedence.
        if (!empty($params['date_from']) || !empty($params['date_to'])) {
            $from = self::dayBound(!empty($params['date_from']) ? $params['date_from'] : '1970-01-01', $tz, false);
            $to   = self::dayBound(!empty($params['date_to']) ? $params['date_to'] : 'now', $tz, true);
            if ($from === null || $to === null) {
                return MCPHelper::error('invalid_date', __('date_from / date_to must be YYYY-MM-DD or ISO 8601.', 'fluent-cart'), ['fields' => ['date_from', 'date_to']]);
            }
            return ['from' => $from, 'to' => $to, 'range' => 'custom', 'basis' => $basis];
        }

        $ranges = ['today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'mtd', 'qtd', 'ytd', 'last_quarter', 'last_year', 'all_time', 'since_launch'];
        $range  = (isset($params['range']) && in_array($params['range'], $ranges, true)) ? $params['range'] : 'all_time';

        // since_launch is an alias of all_time (epoch..now) so the range vocabulary
        // matches the report tools, which use since_launch. Echo whichever was asked.
        if ($range === 'all_time' || $range === 'since_launch') {
            return ['from' => '1970-01-01 00:00:00', 'to' => gmdate('Y-m-d H:i:s'), 'range' => $range, 'basis' => $basis];
        }

        $now     = new \DateTime('now', $tz);
        $startDt = clone $now;
        $endDt   = clone $now;

        if ($range === 'yesterday') {
            $startDt->modify('-1 day');
            $endDt->modify('-1 day');
        } elseif ($range === 'last_7_days') {
            $startDt->modify('-6 days');
        } elseif ($range === 'last_30_days') {
            $startDt->modify('-29 days');
        } elseif ($range === 'this_month' || $range === 'mtd') {
            $startDt = new \DateTime($now->format('Y-m-01'), $tz);
        } elseif ($range === 'last_month') {
            $startDt = new \DateTime($now->format('Y-m-01'), $tz);
            $startDt->modify('-1 month');
            $endDt = (clone $startDt)->modify('last day of this month');
        } elseif ($range === 'qtd') {
            $startDt = self::quarterStart($now, $tz);
        } elseif ($range === 'last_quarter') {
            $qs      = self::quarterStart($now, $tz);
            $startDt = (clone $qs)->modify('-3 months');
            $endDt   = (clone $qs)->modify('-1 day');
        } elseif ($range === 'ytd') {
            $startDt = new \DateTime($now->format('Y-01-01'), $tz);
        } elseif ($range === 'last_year') {
            $year    = (int) $now->format('Y') - 1;
            $startDt = new \DateTime($year . '-01-01', $tz);
            $endDt   = new \DateTime($year . '-12-31', $tz);
        }

        return [
            'from'  => (clone $startDt)->setTime(0, 0, 0)->format('Y-m-d H:i:s'),
            'to'    => (clone $endDt)->setTime(23, 59, 59)->format('Y-m-d H:i:s'),
            'range' => $range,
            'basis' => $basis,
        ];
    }

    private static function quarterStart($now, $tz)
    {
        $month       = (int) $now->format('n');
        $qStartMonth = (int) (floor(($month - 1) / 3) * 3 + 1);
        return new \DateTime($now->format('Y') . '-' . str_pad($qStartMonth, 2, '0', STR_PAD_LEFT) . '-01', $tz);
    }

    /** Parse a date bound to a UTC 'Y-m-d H:i:s' at day start/end; null on failure. */
    private static function dayBound($value, $tz, $endOfDay)
    {
        try {
            $dt = new \DateTime((string) $value, $tz);
        } catch (\Exception $e) {
            return null;
        }
        if ($endOfDay) {
            // Only pin to end-of-day for date-only input; keep explicit times intact.
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) $value))) {
                $dt->setTime(23, 59, 59);
            }
        } else {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) $value))) {
                $dt->setTime(0, 0, 0);
            }
        }
        $dt->setTimezone(new \DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    }
}

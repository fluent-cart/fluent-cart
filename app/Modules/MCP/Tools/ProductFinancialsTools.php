<?php

namespace FluentCart\App\Modules\MCP\Tools;

use FluentCart\App\App;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\MCP\Support\MCPHelper;
use FluentCart\App\Modules\MCP\Support\PermissionGate;
use FluentCart\App\Modules\MCP\Support\ProductFinancialsCalculator as Calc;
use FluentCart\App\Services\Report\ProductReportService;
use FluentCart\App\Services\Payments\SubscriptionHelper;

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
    /** Orders that captured payment, fully refunded ones included (same set as ReportTools::PAID). */
    const CAPTURED = ['paid', 'partially_paid', 'partially_refunded', 'refunded'];

    /** Safety ceiling on subscriptions loaded for one product. */
    const MAX_SUBS = 50000;

    public static function definitions()
    {
        return [
            'fluent-cart/get-product-financials' => [
                'label'       => 'Get Product Financials',
                'description' => 'One product\'s money: one-time revenue, installment commitments, MRR/ARR and a payment_schedule. One-time revenue is cash collected, tax included, minus refunds; split-pay plans have bill_times > 0. Never sums across currencies. one_time.aov = gross_collected / orders for the one-time lines of this product (cash, tax included), not the net-sales aov of get-sales-report. KEY: the time window (range/date_from/date_to + date_basis) applies ONLY to the one_time block — subscriptions.*, payment_schedule and totals are point-in-time as of as_of (lifetime-to-date or forward), so "revenue in the last 30 days" must read one_time.* only and never folds in contract value or MRR. For a windowed time series use get-sales-report with interval; for renewal cash in a period use query-products with order_type=renewal. totals.collected_to_date is always lifetime (all-time one-time net + all subscription payments), whatever the window. totals.total_contracted is null while any perpetual subscription is active — read recurring.mrr/arr and payment_schedule instead, and meta.notes says why. Other currencies of the product are listed in meta.other_currencies.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'product_id'   => ['type' => 'integer', 'description' => 'Product to report on.'],
                        'variation_id' => ['type' => 'integer', 'description' => 'Restrict to one variation.'],
                        'currency'     => ['type' => 'string', 'description' => 'One currency; no __all__.'],
                        'range'        => ['type' => 'string', 'enum' => ['today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'mtd', 'qtd', 'ytd', 'last_quarter', 'last_year', 'all_time', 'since_launch'], 'default' => 'all_time', 'description' => 'Standard window, for the one_time block only. Default all_time.'],
                        'date_from'    => ['type' => 'string', 'description' => 'Window start: ISO 8601 or YYYY-MM-DD, store timezone unless it has an offset. With date_to; overrides range. one_time block only.'],
                        'date_to'      => ['type' => 'string', 'description' => 'Window end, same format as date_from.'],
                        'date_basis'   => ['type' => 'string', 'enum' => ['created_at', 'paid_at'], 'default' => 'paid_at', 'description' => 'One-time window basis: paid_at = order completed_at (cash received); created_at = order placed. Does not affect forward fields.'],
                        'as_of'        => ['type' => 'string', 'description' => 'ISO 8601, store timezone unless an offset is given. Point-in-time for forward metrics. Default now.'],
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
            return MCPHelper::error('invalid_param', 'product_id is required.');
        }
        $productId = (int) $params['product_id'];

        $product = Product::query()->where('ID', $productId)->first();
        if (!$product) {
            return MCPHelper::error('product_not_found', 'No product found for the given product_id.');
        }

        $variationId = !empty($params['variation_id']) ? (int) $params['variation_id'] : null;
        $currency    = ReportTools::currency($params);
        if (is_wp_error($currency)) {
            return $currency;
        }

        $badDate = MCPHelper::unreadableDateError($params, ['date_from', 'date_to', 'as_of']);
        if ($badDate) {
            return $badDate;
        }

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
        $opts = [
            'as_of'            => $asOf,
            'horizon_months'   => $horizon,
            'bucket'           => $bucket,
            'forward_statuses' => $forward,
            'include_schedule' => in_array('schedule', $include, true),
            'one_time'         => $oneTime,
            // Store days, as the reports bucket
            'bucket_timezone'  => wp_timezone(),
        ];

        // totals.* are lifetime, so a windowed one_time block cannot feed them.
        $isWindowed = !in_array($window['range'], ['all_time', 'since_launch'], true);
        if ($isWindowed) {
            $allTime = self::loadOneTime($productId, $variationId, $currency, self::resolveWindow(['range' => 'all_time', 'date_basis' => $window['basis']]));
            $opts['one_time_lifetime_net'] = $allTime['gross'] - $allTime['refunds'];
        }

        $result = Calc::compute($kept, $opts);

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
        if ($isWindowed) {
            $meta['notes'][] = 'The date window applies to one_time only. totals.collected_to_date is lifetime: all-time one-time net plus all subscription payments. For money in the window read one_time.net_collected.';
        }
        if ($subLoad['truncated']) {
            $meta['warnings'] = [sprintf(
                'More than %1$d subscriptions exist for this product; figures use the first %1$d and may be incomplete.',
                self::MAX_SUBS
            )];
        }

        return MCPHelper::envelope(self::summary($product, $currency, $result), $data, $meta);
    }

    // -----------------------------------------------------------------
    // Loaders
    // -----------------------------------------------------------------

    /**
     * One-time revenue: non-subscription line items on orders that captured payment,
     * fully refunded ones included, scoped to the report currency and the window (by date_basis column on the
     * parent order). Returns integer cents/counts for the calculator.
     */
    private static function loadOneTime($productId, $variationId, $currency, $window)
    {
        $dateCol = $window['basis'] === 'created_at' ? 'created_at' : 'completed_at';
        $from    = $window['from'];
        $to      = $window['to'];

        $items = App::db()->getTableName('fct_order_items');
        $line = ProductReportService::lineTotalSql($items, 'fctod');
        $split = ProductReportService::itemRefundSplitSql($line, 0, $items, 'fctord', 'fctod');
        $charged = Arr::get($split, 'charged');
        $refund = Arr::get($split, 'refund');

        $orders = App::db()->table('fct_orders as o')
            ->whereIn('o.payment_status', self::CAPTURED)
            ->where('o.currency', $currency)
            ->where('o.' . $dateCol, '>=', $from)
            ->where('o.' . $dateCol, '<=', $to)
            ->whereIn('o.id', function ($q) use ($productId) {
                $q->select('order_id')->from('fct_order_items')->where('post_id', $productId);
            });

        $query = OrderItem::query()
            ->join('fct_orders as fctord', 'fct_order_items.order_id', '=', 'fctord.id')
            ->leftJoinSub(ProductReportService::orderDiscountSplitQuery($orders), 'fctod', 'fctod.order_id', '=', 'fct_order_items.order_id')
            ->where('fct_order_items.post_id', $productId)
            ->where('fct_order_items.payment_type', '!=', 'subscription')
            ->whereIn('fctord.payment_status', self::CAPTURED)
            ->where('fctord.currency', $currency)
            ->where('fctord.' . $dateCol, '>=', $from)
            ->where('fctord.' . $dateCol, '<=', $to);

        if ($variationId !== null) {
            $query->where('fct_order_items.object_id', $variationId);
        }

        // Cash basis: what the buyer paid for the line after order credits, tax included, and what came back
        $row = $query->selectRaw(
            "COALESCE(SUM({$items}.quantity), 0) as units, "
            . "COALESCE(SUM({$charged}), 0) as gross, "
            . "COALESCE(SUM({$refund}), 0) as refunds, "
            . "COUNT(DISTINCT {$items}.order_id) as orders"
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
                'billing_schedule'  => SubscriptionHelper::getBillingSchedule($sub),
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
                '%1$s — %2$s collected to date (lifetime); MRR %3$s (ARR %4$s). No single total_contracted: this product has perpetual subscriptions.',
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
            '%1$s — %2$s collected to date (lifetime); total contracted %3$s (all one-time and/or finite split-pay).',
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
        return !empty($params['as_of']) ? MCPHelper::storeDateBound($params['as_of']) : gmdate('Y-m-d H:i:s');
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
     * Window for the one_time block, in store days like the report tools; custom dates override.
     * all_time spans epoch..now.
     *
     * @return array{from:string,to:string,range:string,basis:string}|\WP_Error
     */
    private static function resolveWindow($params)
    {
        $basis = (isset($params['date_basis']) && $params['date_basis'] === 'created_at') ? 'created_at' : 'paid_at';

        // Explicit custom dates take precedence.
        if (!empty($params['date_from']) || !empty($params['date_to'])) {
            $from = !empty($params['date_from']) ? MCPHelper::storeDateBound($params['date_from']) : '1970-01-01 00:00:00';
            $to   = !empty($params['date_to']) ? MCPHelper::storeDateBound($params['date_to'], true) : gmdate('Y-m-d H:i:s');
            if ($from > $to) {
                return ReportTools::reversedRangeError($from, $to, ['date_from', 'date_to']);
            }
            return ['from' => $from, 'to' => $to, 'range' => 'custom', 'basis' => $basis];
        }

        $range = (isset($params['range']) && in_array($params['range'], ReportTools::RANGES, true)) ? $params['range'] : 'all_time';

        // since_launch is an alias of all_time (epoch..now) so the range vocabulary
        // matches the report tools, which use since_launch. Echo whichever was asked.
        if ($range === 'all_time' || $range === 'since_launch') {
            return ['from' => '1970-01-01 00:00:00', 'to' => gmdate('Y-m-d H:i:s'), 'range' => $range, 'basis' => $basis];
        }

        $resolved = ReportTools::resolveRange(['range' => $range]);

        return ['from' => $resolved['start'], 'to' => $resolved['end'], 'range' => $range, 'basis' => $basis];
    }
}

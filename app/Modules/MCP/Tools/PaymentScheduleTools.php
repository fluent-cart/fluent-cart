<?php

namespace FluentCart\App\Modules\MCP\Tools;

use FluentCart\App\Models\Subscription;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\MCP\Support\MCPHelper;
use FluentCart\App\Modules\MCP\Support\PermissionGate;
use FluentCart\App\Modules\MCP\Support\PaymentProjector;
use FluentCart\App\Modules\MCP\Support\ProductFinancialsCalculator as Calc;

/**
 * get-upcoming-payments — the renewal cohort view: expected billings grouped by
 * date over a window, separating finite (split-pay) installments from open-ended
 * recurring renewals, with an at_risk figure derived from past_due subscriptions
 * and the store's historical renewal success rate.
 *
 * It anchors on next_billing_date (never created_at), steps by each
 * subscription's interval, and stops finite plans at their remaining bills —
 * the exact projection get-product-financials uses, via the shared
 * PaymentProjector, so the two never disagree.
 *
 * Single-currency (defaults to store currency); other currencies present are
 * listed in meta.other_currencies rather than silently summed.
 */
class PaymentScheduleTools
{
    /** Statuses that produce a future charge and so feed the projection. */
    const FORWARD_STATUSES = ['active', 'past_due', 'trialing'];

    /** Safety ceiling on subscriptions loaded for one projection. */
    const MAX_SUBS = 50000;

    public static function definitions()
    {
        return [
            'fluent-cart/get-upcoming-payments' => [
                'label'       => __('Get Upcoming Payments', 'fluent-cart'),
                'description' => __('Forward view of expected subscription billings grouped by date, split into finite_installments (split-pay, bill_times > 0) vs recurring_renewals (open-ended), plus an at_risk figure from past_due subscriptions discounted by the store\'s historical renewal success rate. Anchors on next_billing_date, steps by each plan\'s interval, stops finite plans at their remaining bills. Optional product_id/variation_id scope to one product. Single currency: store default, others in meta.other_currencies. date_from/date_to default to now..+90 days. Money is {amount, amount_cents, currency, display}.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'product_id'   => ['type' => 'integer', 'description' => 'Limit to subscriptions for one product. Omit for the whole store.'],
                        'variation_id' => ['type' => 'integer', 'description' => 'Limit to one variation.'],
                        'currency'     => ['type' => 'string', 'description' => 'ISO currency for the (single-currency) report. Defaults to store currency.'],
                        'date_from'    => ['type' => 'string', 'description' => 'ISO 8601 or YYYY-MM-DD, UTC. Window start. Default now.'],
                        'date_to'      => ['type' => 'string', 'description' => 'ISO 8601 or YYYY-MM-DD, UTC. Window end. Default 90 days out.'],
                        'bucket'       => ['type' => 'string', 'enum' => ['day', 'month'], 'default' => 'day', 'description' => 'Calendar granularity of the schedule.'],
                    ],
                ],
                'execute_callback'    => [self::class, 'getUpcomingPayments'],
                'permission_callback' => function () {
                    return PermissionGate::can('reports/view');
                },
                'annotations' => ['readonly' => true],
            ],
        ];
    }

    public static function getUpcomingPayments($params = [])
    {
        $productId   = !empty($params['product_id']) ? (int) $params['product_id'] : null;
        $variationId = !empty($params['variation_id']) ? (int) $params['variation_id'] : null;
        $currency    = !empty($params['currency']) ? strtoupper(sanitize_text_field($params['currency'])) : MCPHelper::currencyCode();
        $bucket      = (isset($params['bucket']) && $params['bucket'] === 'month') ? 'month' : 'day';

        $window = self::resolveWindow($params);
        if (is_wp_error($window)) {
            return $window;
        }

        $load = self::loadSubs($productId, $variationId);
        list($kept, $otherCurrencies) = Calc::filterByCurrency($load['rows'], $currency);

        // Build projection descriptors (drop rows with no usable next-bill anchor).
        $projSubs   = [];
        $pastDueSubs = [];
        foreach ($kept as $row) {
            $ps = self::toProjSub($row);
            if ($ps === null) {
                continue;
            }
            $projSubs[] = $ps;
            if ($row['status'] === 'past_due') {
                $pastDueSubs[] = $ps;
            }
        }

        $fromTs = strtotime($window['from'] . ' UTC');
        $toTs   = strtotime($window['to'] . ' UTC');

        $projection   = PaymentProjector::project($projSubs, $fromTs, $toTs, $bucket);
        $pastDueProj  = PaymentProjector::project($pastDueSubs, $fromTs, $toTs, $bucket);

        $buckets      = self::formatBuckets($projection['buckets'], $currency);
        $totals       = self::totals($projection['buckets']);
        $pastDueTotal = self::totals($pastDueProj['buckets'])['total_expected'];

        $successRate = self::renewalSuccessRate();
        // Unknown history -> treat the whole past_due expectation as at risk.
        $atRiskCents = ($successRate === null)
            ? $pastDueTotal
            : (int) round($pastDueTotal * (1 - $successRate));

        $data = [
            'window' => [
                'from'   => MCPHelper::toIso8601($window['from']),
                'to'     => MCPHelper::toIso8601($window['to']),
                'bucket' => $bucket,
            ],
            'schedule' => $buckets,
            'totals'   => [
                'finite_installments' => MCPHelper::money($totals['finite'], $currency),
                'recurring_renewals'  => MCPHelper::money($totals['recurring'], $currency),
                'total_expected'      => MCPHelper::money($totals['total_expected'], $currency),
                'expected_charges'    => $totals['finite_count'] + $totals['recurring_count'],
            ],
            'at_risk'  => [
                'amount'                  => MCPHelper::money($atRiskCents, $currency),
                'from_past_due_expected'  => MCPHelper::money($pastDueTotal, $currency),
                'past_due_subscriptions'  => count($pastDueSubs),
                'historical_success_rate' => $successRate,
                'basis'                   => $successRate === null
                    ? 'no renewal history yet — full past_due expectation shown as at risk'
                    : 'store-wide renewal charge success rate (succeeded / (succeeded + failed))',
            ],
        ];

        $meta = [
            'currency'         => $currency,
            'other_currencies' => $otherCurrencies,
            'forward_statuses' => self::FORWARD_STATUSES,
            'note'             => 'Projected from next_billing_date, stepping by each plan\'s interval; finite plans stop at remaining bills. Forward-billing statuses only (active, past_due, trialing).',
        ];
        if ($load['truncated']) {
            $meta['warnings'] = [sprintf(
                /* translators: %1$d: subscription load cap */
                __('More than %1$d subscriptions matched; the projection uses the first %1$d and may be incomplete.', 'fluent-cart'),
                self::MAX_SUBS
            )];
        }

        return MCPHelper::envelope(self::summary($totals, $atRiskCents, $currency, count($buckets)), $data, $meta);
    }

    // -----------------------------------------------------------------
    // Loaders / builders
    // -----------------------------------------------------------------

    /**
     * Forward-billing subscriptions, optionally scoped to one product/variation.
     * Currency is derived per row from the config JSON (there is no currency
     * column) so the caller can single-currency scope. Reads a limited column set
     * so the model's heavy $appends accessors never fire.
     */
    private static function loadSubs($productId, $variationId)
    {
        $store = MCPHelper::currencyCode();

        $query = Subscription::query()->whereIn('status', self::FORWARD_STATUSES);
        if ($productId !== null) {
            $query->where('product_id', $productId);
        }
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

    /** Normalize a sub row into a PaymentProjector descriptor, or null if it can't bill. */
    private static function toProjSub($row)
    {
        $anchor = isset($row['next_billing_date']) ? $row['next_billing_date'] : null;
        if ($anchor === null || $anchor === '' || strpos((string) $anchor, '0000-00-00') === 0) {
            return null;
        }
        $billTimes = (int) $row['bill_times'];
        $billCount = (int) $row['bill_count'];
        $settle    = Calc::settlement($billTimes);

        return [
            'settlement'      => $settle,
            'interval'        => Calc::normalizeInterval($row['billing_interval']),
            'recur'           => (int) $row['recurring_total'],
            'remaining_bills' => ($settle === 'finite') ? max(0, $billTimes - $billCount) : -1,
            'anchor'          => (string) $anchor,
            'status'          => $row['status'],
        ];
    }

    private static function formatBuckets($buckets, $currency)
    {
        $out = [];
        foreach ($buckets as $period => $b) {
            $out[] = [
                'period'              => $period,
                'finite_installments' => MCPHelper::money((int) $b['finite'], $currency),
                'recurring_renewals'  => MCPHelper::money((int) $b['recurring'], $currency),
                'total_expected'      => MCPHelper::money((int) ($b['finite'] + $b['recurring']), $currency),
                'finite_count'        => (int) $b['finite_count'],
                'recurring_count'     => (int) $b['recurring_count'],
            ];
        }
        return $out;
    }

    private static function totals($buckets)
    {
        $t = ['finite' => 0, 'recurring' => 0, 'finite_count' => 0, 'recurring_count' => 0];
        foreach ($buckets as $b) {
            $t['finite']          += (int) $b['finite'];
            $t['recurring']       += (int) $b['recurring'];
            $t['finite_count']    += (int) $b['finite_count'];
            $t['recurring_count'] += (int) $b['recurring_count'];
        }
        $t['total_expected'] = $t['finite'] + $t['recurring'];
        return $t;
    }

    /**
     * Store-wide renewal charge success rate: succeeded / (succeeded + failed) over
     * transaction_type=charge, order_type=renewal. null when there is no renewal
     * history to learn from. Store-wide (not product-scoped) because renewal
     * success is gateway/dunning-driven, and per-product scoping would not scale.
     */
    private static function renewalSuccessRate()
    {
        $base = OrderTransaction::query()
            ->where('transaction_type', 'charge')
            ->where('order_type', 'renewal');

        $succeeded = (int) (clone $base)->where('status', 'succeeded')->count();
        $failed    = (int) (clone $base)->where('status', 'failed')->count();
        $total     = $succeeded + $failed;

        return $total > 0 ? round($succeeded / $total, 4) : null;
    }

    private static function resolveWindow($params)
    {
        $tz   = new \DateTimeZone('UTC');
        $from = !empty($params['date_from']) ? self::instant($params['date_from'], $tz, false) : gmdate('Y-m-d H:i:s');
        $to   = !empty($params['date_to']) ? self::instant($params['date_to'], $tz, true) : gmdate('Y-m-d H:i:s', strtotime('+90 days'));

        if ($from === null || $to === null) {
            return MCPHelper::error('invalid_date', __('date_from / date_to must be ISO 8601 or YYYY-MM-DD.', 'fluent-cart'), ['fields' => ['date_from', 'date_to']]);
        }
        return ['from' => $from, 'to' => $to];
    }

    /** Parse a date bound to UTC 'Y-m-d H:i:s'; date-only snaps to the day edge. */
    private static function instant($value, $tz, $isEnd)
    {
        try {
            $dt = new \DateTime((string) $value, $tz);
        } catch (\Exception $e) {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) $value))) {
            $dt->setTime($isEnd ? 23 : 0, $isEnd ? 59 : 0, $isEnd ? 59 : 0);
        }
        $dt->setTimezone(new \DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    }

    private static function summary($totals, $atRiskCents, $currency, $bucketCount)
    {
        return sprintf(
            /* translators: 1: total expected, 2: number of buckets, 3: at-risk amount */
            __('%1$s expected across %2$d billing periods; %3$s at risk from past-due plans.', 'fluent-cart'),
            MCPHelper::displayAmount($totals['total_expected'], $currency),
            $bucketCount,
            MCPHelper::displayAmount($atRiskCents, $currency)
        );
    }
}

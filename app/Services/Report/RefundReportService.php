<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;
use FluentCart\App\App;

class RefundReportService extends ReportService
{

    /**
     * The params with the order date bound lifted: refunds in the period on orders of any date
     */
    protected function anyOrderDate(array $params): array
    {
        return array_merge($params, [
            'startDate' => new \FluentCart\App\Services\DateTime\DateTime('1970-01-01 00:00:00'),
            'endDate'   => new \FluentCart\App\Services\DateTime\DateTime('2100-01-01 00:00:00'),
        ]);
    }

    /**
     * The Refunds page. Money out (cards, chart, full vs partial, sale type, payment method, reasons, days to refund)
     * is counted on the refund date; refund rates divide it by the sales placed in the period; products use the order date.
     */
    public function getOverview(array $params): array
    {
        $period = [$params['startDate'], $params['endDate']];
        // Refunds in the period on orders of any date: the order filters apply, the order date bound does not
        $anyOrderDate = $this->anyOrderDate($params);
        $refunds = function () use ($params, $anyOrderDate, $period) {
            $query = ReportCurrency::joinTransactions(ReportCurrency::fromOrders(App::db()->query(), $params), $params)
                ->where('ot.transaction_type', \FluentCart\App\Helpers\Status::TRANSACTION_TYPE_REFUND)
                ->where('ot.status', \FluentCart\App\Helpers\Status::TRANSACTION_REFUNDED)
                ->whereBetween('ot.created_at', $period);

            return $this->applyFilters($query, $anyOrderDate);
        };

        $totals = $refunds()->selectRaw('COUNT(*) AS refunds, COUNT(DISTINCT o.id) AS orders, COALESCE(SUM(ot.total), 0) AS amount')->first();
        // Full: the order's refunds now cover what was paid
        $perOrder = $refunds()->selectRaw('o.id, SUM(ot.total) AS amount, (o.total_paid > 0 AND o.total_refund >= o.total_paid) AS is_full')
            ->groupBy('o.id', 'o.total_paid', 'o.total_refund');
        $split = App::db()->query()->fromSub($perOrder, 'po')
            ->selectRaw('SUM(is_full) AS full_count, COALESCE(SUM(CASE WHEN is_full THEN amount END), 0) AS full_amount,
                SUM(1 - is_full) AS partial_count, COALESCE(SUM(CASE WHEN is_full THEN 0 ELSE amount END), 0) AS partial_amount')
            ->first();

        // Days from order to its first refund ever, for orders first refunded in this period; calendar days in store
        // time, so a refund the next morning is 1 day, as the owner reads it
        $firstRefunds = App::db()->table('fct_order_transactions')
            ->selectRaw('order_id, MIN(created_at) AS first_at')
            ->where('transaction_type', \FluentCart\App\Helpers\Status::TRANSACTION_TYPE_REFUND)
            ->where('status', \FluentCart\App\Helpers\Status::TRANSACTION_REFUNDED)
            ->groupBy('order_id');
        $firsts = $this->applyFilters(ReportCurrency::ordersTable($params)->joinSub($firstRefunds, 'fr', 'fr.order_id', '=', 'o.id'), $anyOrderDate)
            ->whereBetween('fr.first_at', $period)
            ->select('o.created_at', 'fr.first_at')
            ->get();
        $localDay = function ($gmt) {
            return ReportHelper::local(new \DateTime((string) $gmt, new \DateTimeZone('UTC')))->format('Y-m-d');
        };
        $days = [];
        foreach ($firsts as $row) {
            $days[] = max(0, (int) (new \DateTime($localDay($row->created_at)))->diff(new \DateTime($localDay($row->first_at)))->format('%r%a'));
        }
        sort($days);
        $median = $days ? (count($days) % 2 ? $days[intdiv(count($days), 2)] : ($days[count($days) / 2 - 1] + $days[count($days) / 2]) / 2) : 0;

        // Refunds in the period per sales placed in it (the returns-to-sales ratio), and the headline: the share of
        // the period's orders refunded in part or full, as the other reports count it
        $placed = $this->applyFilters(ReportCurrency::ordersTable($params), $params)
            ->selectRaw('COUNT(*) AS orders, COALESCE(SUM(o.total_paid), 0) AS paid, SUM(o.total_refund > 0) AS refunded')
            ->first();

        $refundCount = (int) $totals->refunds;
        $amount = (float) $totals->amount / 100;

        return [
            'summary'           => [
                'refunded_amount'    => $amount,
                'refunds'            => $refundCount,
                'refunded_orders'    => (int) $totals->orders,
                'average_refund'     => $refundCount ? $amount / $refundCount : 0,
                'full'               => ['count' => (int) $split->full_count, 'amount' => (float) $split->full_amount / 100],
                'partial'            => ['count' => (int) $split->partial_count, 'amount' => (float) $split->partial_amount / 100],
                // No orders placed in the period: no rate, as on the chart
                'refund_rate_cohort' => $placed->orders ? (int) $placed->refunded / $placed->orders * 100 : null,
                'refund_rate_orders' => $placed->orders ? (int) $totals->orders / $placed->orders * 100 : null,
                'refund_rate_amount' => $placed->paid > 0 ? (float) $totals->amount / $placed->paid * 100 : null,
                'median_days'        => (float) $median,
            ],
            // A period running into the future stops at today, as the other charts do
            'chart'             => $this->refundChart($refunds, $this->clampParamsToToday($params)),
            'by_sale_type'      => $this->refundSplit($refunds, ReportHelper::saleTypeSql(), ReportHelper::saleTypeLabels()),
            'by_payment_method' => $this->refundSplit($refunds, "COALESCE(NULLIF(ot.payment_method, ''), 'unknown')", []),
            // Callers that show no product table skip its query
            'by_product'        => ($params['withProducts'] ?? true) ? $this->refundsByProduct($params) : null,
            'reasons'           => $this->refundReasons($refunds, $refundCount),
        ];
    }

    private function refundChart(callable $refunds, array $params): array
    {
        $group = ReportHelper::processGroup($params['startDate'], $params['endDate'], $params['groupKey'] ?? null);
        $formats = ['yearly' => '%Y', 'monthly' => '%Y-%m', 'daily' => '%Y-%m-%d'];
        $format = $formats[$group['key']] ?? '%Y-%m-%d';
        $bucket = function ($column) use ($params, $format) {
            return 'DATE_FORMAT(' . ReportHelper::storeTimeSql($column, $params['startDate'], $params['endDate']) . ", '{$format}')";
        };

        $range = $this->getPeriodRange($params['startDate'], $params['endDate'], $group['key'], ['amount', 'count', 'refunded_orders', 'orders']);
        foreach ($refunds()->selectRaw($bucket('ot.created_at') . ' AS bucket, SUM(ot.total) / 100 AS amount, COUNT(*) AS count, COUNT(DISTINCT o.id) AS refunded_orders')->groupBy('bucket')->get() as $row) {
            if (isset($range[$row->bucket])) {
                $range[$row->bucket]['amount'] = (float) $row->amount;
                $range[$row->bucket]['count'] = (int) $row->count;
                $range[$row->bucket]['refunded_orders'] = (int) $row->refunded_orders;
            }
        }
        // Orders placed in each period, for its refund rate
        $placed = $this->applyFilters(ReportCurrency::ordersTable($params), $params)
            ->selectRaw($bucket('o.created_at') . ' AS bucket, COUNT(*) AS orders')->groupBy('bucket')->get();
        foreach ($placed as $row) {
            if (isset($range[$row->bucket])) {
                $range[$row->bucket]['orders'] = (int) $row->orders;
            }
        }
        foreach ($range as &$row) {
            // Orders refunded in the period per order placed in it; a period without orders has no rate
            $row['refund_rate'] = $row['orders'] ? round($row['refunded_orders'] / $row['orders'] * 100, 2) : null;
        }
        unset($row);

        return array_values($range);
    }

    private function refundSplit(callable $refunds, string $keySql, array $labels): array
    {
        $rows = $refunds()->selectRaw("{$keySql} AS split_key, SUM(ot.total) / 100 AS amount, COUNT(*) AS refunds")
            ->groupBy('split_key')->orderByRaw('amount DESC')->get();

        $result = [];
        foreach ($rows as $row) {
            $result[] = ['key' => (string) $row->split_key, 'label' => $labels[$row->split_key] ?? (string) $row->split_key,
                'amount' => (float) $row->amount, 'refunds' => (int) $row->refunds];
        }

        return $result;
    }

    /**
     * Products sold in the period, by refunded amount, with each one's refund rate (refunded / sold). Sold includes tax
     * added on top of the price, because an item's refund can include it; renewal lines already hold their tax.
     */
    private function refundsByProduct(array $params, int $limit = 10): array
    {
        $rows = $this->applyFilters(ReportCurrency::joinOrderItems(ReportCurrency::ordersTable($params), $params), $params)
            ->selectRaw('oi.object_id AS variation_id, MAX(oi.post_title) AS product, MAX(oi.title) AS variation,
                SUM(oi.line_total + CASE WHEN o.tax_behavior = 1 AND o.type <> \'renewal\' THEN oi.tax_amount ELSE 0 END) AS sold, SUM(oi.refund_total) AS refunded,
                COUNT(DISTINCT CASE WHEN oi.refund_total > 0 THEN o.id END) AS refunded_orders')
            ->groupBy('oi.object_id')
            ->havingRaw('refunded > 0')
            ->orderByRaw('refunded DESC')
            ->limit($limit)
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'variation_id'    => (int) $row->variation_id,
                'product'         => (string) $row->product,
                'variation'       => (string) $row->variation,
                'sold'            => (float) $row->sold / 100,
                'refunded'        => (float) $row->refunded / 100,
                'refunded_orders' => (int) $row->refunded_orders,
                'refund_rate'     => $row->sold > 0 ? round($row->refunded / $row->sold * 100, 2) : 0,
            ];
        }

        return $result;
    }

    /**
     * How many refunds have a reason, and the most common ones (same text, any letter case).
     */
    private function refundReasons(callable $refunds, int $total): array
    {
        $counts = [];
        $withReason = 0;
        foreach ($refunds()->select('ot.meta')->get() as $row) {
            $meta = is_array($row->meta) ? $row->meta : (json_decode((string) $row->meta, true) ?: []);
            $reason = trim((string) ($meta['reason'] ?? ''));
            if ($reason === '') {
                continue;
            }
            $withReason++;
            $key = function_exists('mb_strtolower') ? mb_strtolower($reason) : strtolower($reason);
            if (!isset($counts[$key])) {
                $counts[$key] = ['reason' => $reason, 'count' => 0];
            }
            $counts[$key]['count']++;
        }
        usort($counts, function ($a, $b) {
            return $b['count'] <=> $a['count'] ?: strcmp($a['reason'], $b['reason']);
        });

        return [
            'coverage' => $total ? $withReason / $total * 100 : 0,
            'top'      => array_slice($counts, 0, 5),
        ];
    }
}

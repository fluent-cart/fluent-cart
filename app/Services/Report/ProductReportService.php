<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\Modules\MultiCurrency\Services\BaseAmountSql;
use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;
use FluentCart\App\App;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Foundation\Exceptions\HttpException;

class ProductReportService extends ReportService
{
    // Header discounts are normally mirrored into item discount_total; admin and legacy orders can leave a remainder,
    // and in the ALL mode per-item conversion can disagree with converting the order once. Such orders get
    // their order-level net split in whole cents: floor per item, leftover cents to the first item.
    private const LINE_TOTAL = '(CASE WHEN od.order_id IS NULL OR od.net = od.line_sum THEN oi.line_total ELSE
            COALESCE(FLOOR(od.net * oi.line_total / NULLIF(od.line_sum, 0)), 0)
            + CASE WHEN oi.id = od.first_item_id THEN od.net - od.floored ELSE 0 END
        END)';

    // Discounts subtract LINE_TOTAL from this, so the subtotal must share its order-once rounding,
    // or an undiscounted converted order reports a cent of negative discount
    private const LINE_SUBTOTAL = '(CASE WHEN od.order_id IS NULL OR od.sub = od.sub_sum THEN oi.subtotal ELSE
            COALESCE(FLOOR(od.sub * oi.subtotal / NULLIF(od.sub_sum, 0)), 0)
            + CASE WHEN oi.id = od.first_item_id THEN od.sub - od.floored_sub ELSE 0 END
        END)';

    private const NET_SALE = 'SUM(' . self::LINE_TOTAL . ' - oi.refund_total - oi.tax_amount)';

    private const DISCOUNTS = 'SUM(' . self::LINE_SUBTOTAL . ') - SUM(' . self::LINE_TOTAL . ')';

    // Picks past this are dropped in pick order; the page says so
    public const MAX_COMPARE_VARIATIONS = 50;

    // A year of daily points; the series payload is picks × buckets, so a finer grouping over a longer range coarsens
    public const MAX_COMPARE_BUCKETS = 366;

    public function getProductTopChart(array $params)
    {
        $orderItemsSubQuery = App::db()->table('fct_order_items')
            ->selectRaw('order_id, object_id, post_id, title, post_title, SUM(quantity) AS sum_quantity')
            ->groupByRaw('order_id, object_id, post_id')
            ->whereBetween('created_at', [$params['startDate'], $params['endDate']])
            ->when(!empty($params['variationIds']), fn($q) =>
                $q->whereIn('object_id', $params['variationIds'])
            );

        $query = ReportCurrency::ordersTable($params)
            ->joinSub($orderItemsSubQuery, 'oi', function ($join) {
                $join->on('oi.order_id', '=', 'o.id');
            })
            ->leftJoin('posts as p', 'p.ID', '=', 'oi.post_id')
            ->leftJoin('fct_product_variations as pv', 'pv.id', '=', 'oi.object_id')
            ->selectRaw("
                DATE_FORMAT(o.created_at, '%Y-%m') as month,

                oi.object_id,

                SUM(oi.sum_quantity) as total_quantity,

                pv.variation_title AS latest_title,
                
                p.post_title AS latest_post_title
            ")
            ->groupByRaw("DATE_FORMAT(o.created_at, '%Y-%m'), oi.object_id")
            ->orderByRaw("DATE_FORMAT(o.created_at, '%Y-%m')");

        $query = $this->applyFilters($query, $params);

        $raw = $query->get();

        $result = [];

        foreach ($raw as $item) {
            $month = $item->month;
            if (!isset($result[$month])) {
                $result[$month] = [];
            }
            $result[$month][] = [
                'name'         => $item->latest_title,
                'post_title'   => $item->latest_post_title,
                'value'        => (int)$item->total_quantity,
                'variation_id' => (int)$item->object_id,
            ];
        }

        return $result;
    }

    public function getProductReportData($params = [])
    {
        $params = $this->clampParamsToToday($params);
        $group = ReportHelper::processGroup(
            $params['startDate'], $params['endDate'], $params['groupKey']
        );

        $lineTotal = self::LINE_TOTAL;
        $netSale = self::NET_SALE;
        $discounts = self::DISCOUNTS;

        $query = $this->productLinesQuery($params)
            ->selectRaw("
                {$group['field']},

                SUM(oi.quantity) AS units_sold,

                SUM({$lineTotal}) / 100 AS gross_sale,

                SUM(oi.refund_total) / 100 AS total_refunds,

                ({$discounts}) / 100 AS total_discounts,

                {$netSale} / 100 AS net_sale,

                ROUND(SUM({$lineTotal}) / NULLIF(SUM(oi.quantity), 0) / 100, 2) AS average_selling_price,

                COUNT(DISTINCT o.customer_id) AS customers_count
            ")
            ->groupByRaw($group['by'])
            ->orderByRaw($group['by']);

        $results = $query->get();

        $summary = [
            'units_sold'            => 0,
            'gross_sale'            => 0,
            'total_refunds'         => 0,
            'total_discounts'       => 0,
            'net_sale'              => 0,
            'average_selling_price' => 0,
            'customer_count'        => 0,
        ];
        
        $groups = $this->getPeriodRange(
            $params['startDate'], $params['endDate'], $group['key'], array_keys($summary)
        );

        foreach ($results as $row) {
            $item = [
                'year'                  => (int) $row->year,
                'group'                 => $row->group,
                'units_sold'            => (int) $row->units_sold,
                'gross_sale'            => (float) $row->gross_sale,
                'total_refunds'         => (float) $row->total_refunds,
                'total_discounts'       => (float) $row->total_discounts,
                'net_sale'              => (float) $row->net_sale,
                'average_selling_price' => (float) $row->average_selling_price,
                'customer_count'        => (int) $row->customers_count,
            ];

            $groups[$row->group] = $item;

            $summary['units_sold'] += $item['units_sold'];
            $summary['gross_sale'] += $item['gross_sale'];
            $summary['total_refunds'] += $item['total_refunds'];
            $summary['total_discounts'] += $item['total_discounts'];
            $summary['net_sale'] += $item['net_sale'];
        }

        $summary['customer_count'] = (int) $this->productItemsQuery($params)
            ->selectRaw('COUNT(DISTINCT o.customer_id) AS customer_count')
            ->value('customer_count');

        if ($summary['units_sold']) {
            $summary['average_selling_price'] = $summary['gross_sale'] / $summary['units_sold'];
        }

        return [
            'summary' => $summary,
            'grouped' => array_values($groups),
        ];
    }

    public function getProductCompare(array $params): array
    {
        $variationIds = array_slice(array_values(array_unique(array_filter(
            array_map('intval', (array) Arr::get($params, 'variationIds', []))
        ))), 0, self::MAX_COMPARE_VARIATIONS);
        $params['variationIds'] = $variationIds;
        $comparePeriod = Arr::get($params, 'comparePeriod');

        $result = [
            'currency'        => $params['currency'],
            'variation_limit' => self::MAX_COMPARE_VARIATIONS,
            'range'           => [
                'start' => $params['startDate']->format('Y-m-d H:i:s'),
                'end'   => $params['endDate']->format('Y-m-d H:i:s'),
            ],
            'compare_range' => ReportHelper::formatCompareRange($comparePeriod),
            'compare_ranges' => ReportHelper::formatCompareRanges($params),
        ];

        [$group, $buckets] = $this->compareGrouping($params);
        $result['group_key'] = $group['key'];

        if (!$variationIds) {
            return array_merge([
                'entities'        => [],
                'buckets'         => $buckets,
                'series'          => [],
                'totals'          => [],
                'previous_totals' => $comparePeriod ? [] : null,
            ], $result);
        }

        $entities = [];
        $totals = [];
        $empty = ['units' => 0, 'revenue' => 0.0, 'orders' => 0, 'customers' => 0, 'discount' => 0.0, 'refunds' => 0.0];

        // A pick with no sales in the range still gets its zero row, or A vs B would silently show only A
        $catalog = App::db()->table('fct_product_variations as pv')
            ->leftJoin('posts as p', 'p.ID', '=', 'pv.post_id')
            ->whereIn('pv.id', $variationIds)
            ->select(['pv.id', 'pv.post_id', 'p.post_title', 'pv.variation_title'])
            ->get();

        foreach ($catalog as $row) {
            $variationId = (int) $row->id;
            $entities[$variationId] = [
                'variation_id'    => $variationId,
                'product_id'      => (int) $row->post_id,
                'product_title'   => (string) $row->post_title,
                'variation_title' => (string) $row->variation_title,
            ];
            $totals[$variationId] = $empty;
        }

        foreach ($this->compareTotals($params) as $row) {
            $variationId = (int) $row->variation_id;
            if (!isset($entities[$variationId])) {
                $entities[$variationId] = $this->compareEntityFromRow($row);
            }
            $totals[$variationId] = $this->compareTotalsRow($row);
        }

        $previousTotals = null;
        if ($comparePeriod) {
            $previousTotals = array_fill_keys(array_keys($totals), $empty);
            $previousParams = array_merge($params, ['startDate' => $comparePeriod[0], 'endDate' => $comparePeriod[1]]);
            foreach ($this->compareTotals($previousParams) as $row) {
                $variationId = (int) $row->variation_id;
                // A pick gone from the catalog that sold only in the compare period still needs its row to show the drop
                if (!isset($entities[$variationId])) {
                    $entities[$variationId] = $this->compareEntityFromRow($row);
                    $totals[$variationId] = $empty;
                }
                $previousTotals[$variationId] = $this->compareTotalsRow($row);
            }
        }

        $ordered = [];
        foreach ($variationIds as $variationId) {
            if (isset($entities[$variationId])) {
                $ordered[] = $entities[$variationId];
            }
        }

        [$buckets, $series] = $this->compareSeries($params, $group, $buckets, array_column($ordered, 'variation_id'));

        return array_merge([
            'entities'        => $ordered,
            'buckets'         => $buckets,
            'series'          => $series,
            'totals'          => $totals,
            'previous_totals' => $previousTotals,
        ], $result);
    }

    private function compareTotals(array $params)
    {
        $netSale = self::NET_SALE;
        $discounts = self::DISCOUNTS;

        return $this->productLinesQuery($params)
            ->selectRaw("
                oi.object_id AS variation_id,
                MAX(oi.post_id) AS product_id,
                MAX(oi.post_title) AS product_title,
                MAX(oi.title) AS variation_title,
                SUM(oi.quantity) AS units,
                {$netSale} / 100 AS revenue,
                COUNT(DISTINCT o.id) AS orders,
                COUNT(DISTINCT o.customer_id) AS customers,
                ({$discounts}) / 100 AS discount,
                SUM(oi.refund_total) / 100 AS refunds
            ")
            ->groupBy('oi.object_id')
            ->get();
    }

    private function compareEntityFromRow($row): array
    {
        return [
            'variation_id'    => (int) $row->variation_id,
            'product_id'      => (int) $row->product_id,
            'product_title'   => (string) $row->product_title,
            'variation_title' => (string) $row->variation_title,
        ];
    }

    private function compareTotalsRow($row): array
    {
        return [
            'units'     => (int) $row->units,
            'revenue'   => (float) $row->revenue,
            'orders'    => (int) $row->orders,
            'customers' => (int) $row->customers,
            'discount'  => (float) $row->discount,
            'refunds'   => (float) $row->refunds,
        ];
    }

    private function compareGrouping(array $params): array
    {
        $startDate = $params['startDate'];
        $endDate = $params['endDate'];
        // The shared sanitizer also lets non-time keys through; Compare only buckets by time
        $groupKey = Arr::get($params, 'groupKey');
        if (!in_array($groupKey, ['daily', 'monthly', 'yearly'], true)) {
            $groupKey = 'default';
        }
        $group = ReportHelper::processGroup($startDate, $endDate, $groupKey);
        $buckets = $this->compareBuckets($startDate, $endDate, $group['key']);

        $coarser = ['daily' => 'monthly', 'monthly' => 'yearly'];
        while (count($buckets) > self::MAX_COMPARE_BUCKETS && isset($coarser[$group['key']])) {
            $group = ReportHelper::processGroup($startDate, $endDate, $coarser[$group['key']]);
            $buckets = $this->compareBuckets($startDate, $endDate, $group['key']);
        }

        if (count($buckets) > self::MAX_COMPARE_BUCKETS) {
            throw new HttpException(422, esc_html__('The date range is too long to compare products.', 'fluent-cart'), 'report_range_too_long');
        }

        return [$group, $buckets];
    }

    private function compareBuckets($startDate, $endDate, string $groupKey): array
    {
        // Stepping P1M/P1Y from a mid-period start skips the last period or overflows from day 31
        $start = $startDate->copy();
        if ($groupKey === 'monthly') {
            $start->startOfMonth();
        } elseif ($groupKey === 'yearly') {
            $start->startOfYear();
        }

        return array_map('strval', array_keys($this->getPeriodRange($start, $endDate, $groupKey)));
    }

    private function compareSeries(array $params, array $group, array $buckets, array $variationIds): array
    {
        $netSale = self::NET_SALE;
        $rows = $this->productLinesQuery($params)
            ->selectRaw("
                oi.object_id AS variation_id,
                {$group['by']} AS bucket,
                SUM(oi.quantity) AS units,
                {$netSale} / 100 AS revenue,
                COUNT(DISTINCT o.id) AS orders
            ")
            ->groupByRaw("oi.object_id, {$group['by']}")
            ->get();

        // Safety net: a sale bucketed by SQL at a range edge must not fall outside the PHP-built list
        foreach ($rows as $row) {
            $buckets[] = (string) $row->bucket;
        }
        $buckets = array_values(array_unique($buckets));
        sort($buckets, SORT_STRING);
        $index = array_flip($buckets);

        // Sparse: a pick without sales gets no entry and the page zero-fills it, so the payload grows with sales, not picks
        $picked = array_flip($variationIds);
        $series = [];
        foreach ($rows as $row) {
            $variationId = (int) $row->variation_id;
            if (!isset($picked[$variationId])) {
                continue;
            }
            if (!isset($series[$variationId])) {
                $series[$variationId] = [
                    'units'   => array_fill(0, count($buckets), 0),
                    'revenue' => array_fill(0, count($buckets), 0.0),
                    'orders'  => array_fill(0, count($buckets), 0),
                ];
            }
            $at = $index[(string) $row->bucket];
            $series[$variationId]['units'][$at] = (int) $row->units;
            $series[$variationId]['revenue'][$at] = (float) $row->revenue;
            $series[$variationId]['orders'][$at] = (int) $row->orders;
        }

        return [$buckets, $series];
    }

    private function productLinesQuery(array $params)
    {
        return $this->productItemsQuery($params)
            ->leftJoinSub($this->orderDiscountsQuery($params), 'od', 'od.order_id', '=', 'o.id');
    }

    private function productItemsQuery(array $params)
    {
        $query = ReportCurrency::joinOrderItems(ReportCurrency::ordersTable($params), $params)
            ->whereBetween('oi.created_at', [$params['startDate'], $params['endDate']])
            ->when(!empty($params['variationIds']), fn($q) =>
                $q->whereIn('oi.object_id', $params['variationIds'])
            );

        return $this->applyFilters($query, $params);
    }

    private function orderDiscountsQuery(array $params)
    {
        $converted = ReportCurrency::isConverted($params);
        // Native amounts are needed here: the order's net is rounded once, the Sales report way, not per column
        $lineTotal = $converted ? 'ROUND(pi.line_total / o.rate)' : 'pi.line_total';
        $subtotal = $converted ? 'ROUND(pi.subtotal / o.rate)' : 'pi.subtotal';
        $nativeNet = 'SUM(pi.line_total) - (o.manual_discount_total + o.coupon_discount_total - SUM(pi.discount_total))';
        $net = $converted ? "ROUND(({$nativeNet}) / o.rate)" : $nativeNet;
        $sub = $converted ? 'ROUND(SUM(pi.subtotal) / o.rate)' : 'SUM(pi.subtotal)';

        [$trusted, $bindings] = BaseAmountSql::trustedRate('o.');

        $orders = $this->applyFilters(App::db()->table('fct_orders as o'), $params)
            ->when($converted, fn($q) => $q->whereRaw("{$trusted} = 1", $bindings))
            ->join('fct_order_items as pi', 'pi.order_id', '=', 'o.id')
            ->selectRaw("
                o.id,
                o.rate,
                {$net} AS net,
                SUM({$lineTotal}) AS line_sum,
                {$sub} AS sub,
                SUM({$subtotal}) AS sub_sum
            ")
            ->groupBy('o.id', 'o.rate', 'o.manual_discount_total', 'o.coupon_discount_total')
            ->havingRaw('net <> line_sum OR sub <> sub_sum');

        return App::db()->table('fct_order_items as pi')
            ->joinSub($orders, 'o', 'o.id', '=', 'pi.order_id')
            ->selectRaw("
                o.id AS order_id,
                o.net,
                o.line_sum,
                o.sub,
                o.sub_sum,
                MIN(pi.id) AS first_item_id,
                SUM(COALESCE(FLOOR(o.net * {$lineTotal} / NULLIF(o.line_sum, 0)), 0)) AS floored,
                SUM(COALESCE(FLOOR(o.sub * {$subtotal} / NULLIF(o.sub_sum, 0)), 0)) AS floored_sub
            ")
            ->groupBy('o.id', 'o.net', 'o.line_sum', 'o.sub', 'o.sub_sum');
    }

    public function calculateFluctuations($currentMetrics, $previousMetrics)
    {
        $fluctuations = [];

        $indices = ['units_sold', 'gross_sale', 'average_selling_price', 'customer_count', 'total_refunds', 'total_discounts', 'net_sale'];

        foreach ($indices as $key) {
            $previousValue = $previousMetrics[$key] ?? 0;
            $currentValue = $currentMetrics[$key] ?? 0;

            if ($previousValue > 0) {
                $fluctuations[$key] = (($currentValue - $previousValue) / $previousValue) * 100;
            } else {
                $fluctuations[$key] = $currentValue > 0 ? 100 : 0;
            }
        }

        return $fluctuations;
    }
}
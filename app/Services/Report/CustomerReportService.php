<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;

class CustomerReportService extends ReportService
{

    /**
     * The Customers overview. Each customer who paid in the range is new (their first purchase is in it), returning
     * (bought again, not a renewal) or renewing (renewals only), in that order. Revenue is split by order the same
     * way. The repeat purchase rate and average lifetime value read each customer's history up to the range end.
     * With comparePeriod, the previous period's figures. Amounts in cents, net sales as the Revenue report counts
     * them.
     */
    public function getOverview(array $params): array
    {
        $chartParams = $this->clampParamsToToday($params);
        $group = ReportHelper::processGroup($chartParams['startDate'], $chartParams['endDate'], $chartParams['groupKey']);

        $summary = $this->overviewPeriod($params);
        $summary = array_merge($summary, $this->repeatAndValue($params));

        $keys = ['new_customers', 'returning_customers', 'renewing_customers', 'new_net', 'returning_net', 'renewal_net'];
        $chart = $this->getPeriodRange($chartParams['startDate'], $chartParams['endDate'], $group['key'], $keys);
        foreach ($this->overviewPeriod($params, $group) as $period => $point) {
            if (isset($chart[$period])) {
                $chart[$period] = array_merge($chart[$period], array_intersect_key($point, array_flip($keys)));
            }
        }

        $previous = !empty($params['comparePeriod'])
            ? $this->overviewPeriod(array_merge($params, ['startDate' => $params['comparePeriod'][0], 'endDate' => $params['comparePeriod'][1]]))
            : null;

        return [
            'summary'  => $summary,
            'previous' => $previous,
            'chart'    => array_values($chart),
        ];
    }

    /**
     * @param array|null $group with a period grouping, figures per period keyed by it; else one set of figures
     */
    private function overviewPeriod(array $params, $group = null): array
    {
        $net = ReportHelper::netSalesSql($params);
        $renewal = Status::ORDER_TYPE_RENEWAL;
        $first = ReportHelper::firstPurchaseSql($params);
        // One kind per order: a renewal, the customer's first purchase, or a purchase by a returning customer
        $kind = "(CASE WHEN o.type = '{$renewal}' THEN 'renewal' WHEN {$first} THEN 'new' ELSE 'returning' END)";
        $period = $group ? "{$group['by']} AS period, " : '';

        $orders = $this->applyFilters(ReportHelper::joinShippingRefund(ReportCurrency::ordersTable($params), $params), $params)
            ->where('o.total_paid', '>', 0)
            ->whereNotNull('o.customer_id')
            ->selectRaw("{$period}o.customer_id, {$kind} AS kind, {$net} AS net");

        // A customer's kind in the range (or period): new before returning before renewing
        $perCustomer = App::db()->query()->fromSub($orders, 'k')
            ->selectRaw(($group ? 'k.period, ' : '') . "k.customer_id,
                MAX(k.kind = 'new') AS is_new, MAX(k.kind = 'returning') AS is_returning,
                SUM(CASE WHEN k.kind = 'new' THEN k.net ELSE 0 END) AS new_net,
                SUM(CASE WHEN k.kind = 'returning' THEN k.net ELSE 0 END) AS returning_net,
                SUM(CASE WHEN k.kind = 'renewal' THEN k.net ELSE 0 END) AS renewal_net")
            ->when($group, fn($q) => $q->groupBy('k.period', 'k.customer_id'), fn($q) => $q->groupBy('k.customer_id'));

        $rows = App::db()->query()->fromSub($perCustomer, 'c')
            ->selectRaw(($group ? 'c.period, ' : '') . 'COUNT(*) AS customers, SUM(c.is_new) AS new_customers,
                SUM(c.is_new = 0 AND c.is_returning = 1) AS returning_customers,
                SUM(c.is_new = 0 AND c.is_returning = 0) AS renewing_customers,
                SUM(c.new_net) AS new_net, SUM(c.returning_net) AS returning_net, SUM(c.renewal_net) AS renewal_net')
            ->when($group, fn($q) => $q->groupBy('c.period'))
            ->get();

        $figures = function ($row) {
            $out = [];
            foreach (['customers', 'new_customers', 'returning_customers', 'renewing_customers', 'new_net', 'returning_net', 'renewal_net'] as $key) {
                $out[$key] = $row ? (int) $row->{$key} : 0;
            }
            $out['net'] = $out['new_net'] + $out['returning_net'] + $out['renewal_net'];

            return $out;
        };

        if (!$group) {
            return $figures($rows->first());
        }

        $byPeriod = [];
        foreach ($rows as $row) {
            $byPeriod[(string) $row->period] = $figures($row);
        }

        return $byPeriod;
    }

    /**
     * Of the customers who paid in the range: the share with two or more purchases (renewals are not purchases),
     * and their average lifetime net, both over their history up to the range end in the report currency
     */
    private function repeatAndValue(array $params): array
    {
        $history = $this->buyerHistory($params);

        $row = App::db()->query()->fromSub($history, 'h')
            ->selectRaw('COUNT(*) AS customers, SUM(h.purchases >= 2) AS repeaters, SUM(h.lifetime_net) AS lifetime_net')
            ->first();
        $customers = $row ? (int) $row->customers : 0;

        return [
            'repeat_rate' => $customers ? round((int) $row->repeaters / $customers * 100, 1) : 0.0,
            'average_ltv' => $customers ? (int) round((int) $row->lifetime_net / $customers) : 0,
        ];
    }

    /**
     * For each customer who paid in the range (in the report currency): purchases (renewals are not purchases),
     * all orders, and lifetime net, over their history up to the range end
     */
    protected function buyerHistory(array $params)
    {
        // The same customers the overview counts: paid in the range, in the report currency (trusted rates when converted)
        $buyers = $this->applyFilters(ReportCurrency::ordersTable($params), $params)
            ->where('o.total_paid', '>', 0)
            ->whereNotNull('o.customer_id')
            ->select('o.customer_id');

        $history = ReportHelper::joinShippingRefund(ReportCurrency::ordersTable($params), [], $buyers)
            ->where('o.total_paid', '>', 0)
            ->where('o.created_at', '<=', $params['endDate'])
            ->when(!empty($params['paymentStatus']), fn($q) => $q->whereIn('o.payment_status', $params['paymentStatus']))
            ->when(!empty($params['orderStatus']), fn($q) => $q->whereNotIn('o.status', $params['orderStatus']))
            ->when(!empty($params['currency']) && $params['currency'] !== ReportCurrency::ALL, fn($q) => $q->where('o.currency', $params['currency']))
            ->whereIn('o.customer_id', $buyers)
            ->selectRaw("o.customer_id, SUM(o.type <> '" . Status::ORDER_TYPE_RENEWAL . "') AS purchases, COUNT(*) AS orders,
                SUM(" . ReportHelper::netSalesSql($params) . ') AS lifetime_net')
            ->groupBy('o.customer_id');

        return $history;
    }

    public const TOP_CUSTOMERS = 100;

    /**
     * Customers with an order in the range: repeat rates, average lifetime value and the top customers.
     * A customer's history is every report-status order up to the range end, in the report currency.
     *
     * @param array $params startDate, endDate, currency
     */
    public function getInsights(array $params, bool $withCustomers = true): array
    {
        $netSql = ReportHelper::netSalesSql($params);
        $purchase = "o.type IN ('" . Status::ORDER_TYPE_PAYMENT . "', '" . Status::ORDER_TYPE_SUBSCRIPTION . "')";
        // A literal, not a binding: the select is nested with fromSub(), which reorders select bindings.
        // format() output holds only digits, '-', ':' and a space.
        $inRange = "o.created_at >= '" . $params['startDate']->format('Y-m-d H:i:s') . "'";

        $rangeCustomers = App::db()->table('fct_orders as ro')->distinct()->select('ro.customer_id')
            ->whereBetween('ro.created_at', [$params['startDate'], $params['endDate']])
            ->whereIn('ro.payment_status', Status::getReportStatuses());

        $perCustomer = ReportHelper::joinShippingRefund(ReportCurrency::ordersTable($params), [], clone $rangeCustomers)
            ->selectRaw("o.customer_id, COUNT(*) AS orders,
                SUM({$purchase}) AS purchases,
                SUM(o.type = '" . Status::ORDER_TYPE_RENEWAL . "') AS renewals,
                SUM({$netSql}) AS lifetime_net,
                SUM(CASE WHEN {$inRange} THEN {$netSql} ELSE 0 END) AS range_net,
                SUM({$inRange}) AS range_orders,
                MIN(o.created_at) AS first_order_at,
                MAX(o.created_at) AS last_order_at")
            ->whereIn('o.payment_status', Status::getReportStatuses())
            ->whereNotNull('o.customer_id')
            ->where('o.created_at', '<=', $params['endDate'])
            // Only customers with an order in the range, so a short range does not group the whole store
            ->joinSub($rangeCustomers, 'rc', 'rc.customer_id', '=', 'o.customer_id')
            ->when(!empty($params['currency']) && $params['currency'] !== ReportCurrency::ALL, fn($q) =>
                $q->where('o.currency', $params['currency'])
            )
            ->groupBy('o.customer_id')
            ->havingRaw('range_orders > 0');

        $summary = App::db()->query()->fromSub($perCustomer, 'pc')
            ->selectRaw('COUNT(*) AS customers, SUM(pc.orders >= 2) AS repeat_any, SUM(pc.purchases >= 2) AS repeat_new,
                SUM(pc.lifetime_net) AS lifetime_net, SUM(pc.range_net) AS range_net')
            ->first();

        $customers = (int) $summary->customers;
        $rate = function ($count) use ($customers) {
            return $customers ? round($count / $customers * 100, 2) : 0;
        };

        // Names and emails only for a user who can see customers
        $top = !$withCustomers ? [] : App::db()->query()->fromSub($perCustomer, 'pc')
            ->leftJoin('fct_customers as c', 'c.id', '=', 'pc.customer_id')
            ->select(['pc.*', 'c.first_name', 'c.last_name', 'c.email'])
            ->orderBy('pc.lifetime_net', 'desc')
            ->orderBy('pc.customer_id')
            ->limit(self::TOP_CUSTOMERS)
            ->get()
            ->map(function ($row) {
                return [
                    'customer_id'    => (int) $row->customer_id,
                    'name'           => trim($row->first_name . ' ' . $row->last_name),
                    'email'          => (string) $row->email,
                    'orders'         => (int) $row->orders,
                    'renewals'       => (int) $row->renewals,
                    'lifetime_net'   => round((float) $row->lifetime_net / 100, 2),
                    'range_net'      => round((float) $row->range_net / 100, 2),
                    'first_order_at' => (string) $row->first_order_at,
                    'last_order_at'  => (string) $row->last_order_at,
                ];
            })
            ->all();

        return [
            'summary' => [
                'customers'        => $customers,
                'repeat_any'       => (int) $summary->repeat_any,
                'repeat_any_rate'  => $rate((int) $summary->repeat_any),
                'repeat_new'       => (int) $summary->repeat_new,
                'repeat_new_rate'  => $rate((int) $summary->repeat_new),
                'lifetime_net'     => round((float) $summary->lifetime_net / 100, 2),
                'average_ltv'      => $customers ? round((float) $summary->lifetime_net / $customers / 100, 2) : 0,
                'range_net'        => round((float) $summary->range_net / 100, 2),
                'average_range_net' => $customers ? round((float) $summary->range_net / $customers / 100, 2) : 0,
            ],
            'top_customers'    => $top,
            'customers_hidden' => !$withCustomers,
        ];
    }

}

<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;

/**
 * Per coupon: orders, revenue, discount given, refunds and what the customers renewed later.
 */
class CouponReportService extends ReportService
{
    /**
     * @param array $params processed report params (startDate, endDate, currency, paymentStatus)
     * @return array{rows: array, totals: array, no_code: array}
     */
    public function getReport(array $params): array
    {
        $netSql = ReportHelper::netSalesSql($params);
        $firstOrder = "o.type IN ('" . Status::ORDER_TYPE_PAYMENT . "', '" . Status::ORDER_TYPE_SUBSCRIPTION . "')";

        // An order with several coupons shares its money by each coupon's discount; equal shares when all are 0.
        // Both sides in the order currency: the ALL mode converts ac.amount but not the order's sum
        $rawAmount = ReportCurrency::isConverted($params) ? 'ac.raw_amount' : 'ac.amount';
        $share = "(CASE WHEN os.amount_sum > 0 THEN {$rawAmount} / os.amount_sum ELSE 1 / os.coupons END)";
        // The shared new-customer rule (Customers, Products, Sources): the customer's first purchase
        // A free (100% off) order is no first purchase, as everywhere else
        $customerType = "(CASE WHEN o.customer_id IS NULL THEN 'guest' WHEN o.total_paid > 0 AND " . ReportHelper::firstPurchaseSql($params) . " THEN 'new' ELSE 'returning' END)";

        // The customers-won value and renewals need two joins; callers that do not show them skip both
        $withQuality = $params['withQuality'] ?? true;
        $lifetimeSql = $withQuality ? 'COALESCE(lt.lifetime, 0)' : '0';
        $laterSql = $withQuality ? 'COALESCE(lp.later_net, 0)' : '0';

        $metrics = "COUNT(DISTINCT o.id) AS orders,
            COUNT(DISTINCT o.customer_id) AS customers,
            COUNT(DISTINCT CASE WHEN {$customerType} = 'new' THEN o.id END) AS new_orders,
            COUNT(DISTINCT CASE WHEN {$customerType} = 'returning' THEN o.id END) AS returning_orders,
            COUNT(DISTINCT CASE WHEN o.customer_id IS NULL THEN o.id END) AS guest_orders,
            COUNT(DISTINCT CASE WHEN o.total_refund > 0 THEN o.id END) AS refunded_orders,
            SUM(CASE WHEN {$customerType} = 'new' THEN {$lifetimeSql} * {$share} ELSE 0 END) AS won_lifetime,
            SUM(CASE WHEN {$customerType} = 'new' THEN {$share} ELSE 0 END) AS won_weight,
            SUM(o.total_paid * {$share}) AS gross,
            SUM(ac.amount) AS discount,
            SUM(o.total_refund * {$share}) AS refunds,
            SUM({$netSql} * {$share}) AS net,
            SUM(CASE WHEN {$firstOrder} THEN {$netSql} * {$share} ELSE 0 END) AS first_net,
            SUM(CASE WHEN {$firstOrder} THEN {$laterSql} * {$share} ELSE 0 END) AS later_net";

        $rows = $this->couponOrders($params)
            ->leftJoin('fct_coupons as c', 'c.id', '=', 'ac.coupon_id')
            // A deleted coupon keeps its rows under its code; a renamed one stays one row under its current code
            ->selectRaw("COALESCE(CAST(ac.coupon_id AS CHAR), CONCAT('code:', ac.code)) AS coupon_key,
                MAX(ac.coupon_id) AS coupon_id, COALESCE(MAX(c.code), MAX(ac.code)) AS code, MAX(c.title) AS title,
                GROUP_CONCAT(DISTINCT ac.code ORDER BY ac.code SEPARATOR '\n') AS used_codes, {$metrics}")
            // The rollup row holds the totals; COUNT(DISTINCT) keeps an order with several coupons once
            ->groupByRaw('coupon_key WITH ROLLUP')
            ->get();

        $result = [];
        $totals = null;
        foreach ($rows as $row) {
            if ($row->coupon_key === null) {
                $totals = $this->finish($row);
                continue;
            }
            // A renamed coupon: owners remember the code they sent out
            $usedAs = array_values(array_filter(explode("\n", (string) $row->used_codes), function ($code) use ($row) {
                return $code !== '' && strcasecmp($code, (string) $row->code) !== 0;
            }));
            $result[] = array_merge([
                'coupon_key' => (string) $row->coupon_key,
                'coupon_id'  => $row->coupon_id ? (int) $row->coupon_id : null,
                'code'       => (string) $row->code,
                'used_as'    => $usedAs,
                'title'      => (string) $row->title,
            ], $this->finish($row));
        }
        usort($result, function ($a, $b) {
            return $b['orders'] <=> $a['orders'] ?: strcmp($a['coupon_key'], $b['coupon_key']);
        });

        if (!$totals) {
            $totals = $this->finish((object) array_fill_keys(['orders', 'customers', 'new_orders', 'returning_orders', 'guest_orders',
                'refunded_orders', 'won_lifetime', 'won_weight', 'gross', 'discount', 'refunds', 'net', 'first_net', 'later_net'], 0));
        }

        // Discount in the order header with no coupon row: for example renewal discounts imported from another platform
        $noCode = $this->applyFilters(ReportCurrency::ordersTable($params), $params)
            ->where('o.coupon_discount_total', '>', 0)
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('fct_applied_coupons as nac')->whereColumn('nac.order_id', 'o.id');
            })
            ->selectRaw('COUNT(*) AS orders, SUM(o.coupon_discount_total) AS discount')
            ->first();

        return [
            'rows'    => $result,
            'totals'  => $totals,
            'no_code' => [
                'orders'   => (int) $noCode->orders,
                'discount' => round((int) $noCode->discount / 100, 2),
            ],
        ];
    }

    /**
     * Applied coupon rows (`ac`) of the orders in the report scope (`o`), with each order's coupon sums (`os`),
     * its later renewals (`lp`) and the customer's first paid order date (`fp`).
     */
    private function couponOrders(array $params)
    {
        $netSql = ReportHelper::netSalesSql($params);
        $statuses = Status::getReportStatuses();

        $orderSums = ReportHelper::rangeOrderRows('fct_applied_coupons', 'rac', $params)
            ->selectRaw('rac.order_id, SUM(rac.amount) AS amount_sum, COUNT(*) AS coupons')
            ->groupBy('rac.order_id');

        // Renewals of a coupon order, any date, in the report's payment statuses
        $couponOrders = function ($q) use ($params) {
            $q->select('lac.order_id')->from('fct_applied_coupons as lac')
                ->join('fct_orders as lo', 'lo.id', '=', 'lac.order_id')
                ->whereBetween('lo.created_at', [$params['startDate'], $params['endDate']]);
        };
        $later = ReportHelper::joinShippingRefund(ReportCurrency::ordersTable($params), [], [], $couponOrders)
            ->selectRaw("o.parent_id, SUM({$netSql}) AS later_net")
            ->whereIn('o.payment_status', $statuses)
            ->whereIn('o.parent_id', $couponOrders)
            ->groupBy('o.parent_id');

        // Lifetime spend to date (report currency) of the customers who used a coupon in the range
        $lifetime = $this->customerLifetime($params, function ($q) use ($params) {
            $q->select('ro.customer_id')->from('fct_orders as ro')
                ->join('fct_applied_coupons as fac', 'fac.order_id', '=', 'ro.id')
                ->whereBetween('ro.created_at', [$params['startDate'], $params['endDate']]);
        });

        $query = $this->applyFilters(ReportHelper::joinShippingRefund(ReportCurrency::ordersTable($params), $params), $params)
            ->joinSub($orderSums, 'os', 'os.order_id', '=', 'o.id')
            ->when($params['withQuality'] ?? true, fn($q) => $q->leftJoinSub($later, 'lp', 'lp.parent_id', '=', 'o.id')
                ->leftJoinSub($lifetime, 'lt', 'lt.customer_id', '=', 'o.customer_id'));

        // Applied amounts are kept in the order currency; the ALL mode converts them at the order's rate
        if (ReportCurrency::isConverted($params)) {
            return $query->joinSub(
                App::db()->table('fct_applied_coupons as rawac')
                    ->join('fct_orders as ro', 'ro.id', '=', 'rawac.order_id')
                    ->selectRaw('rawac.order_id, rawac.coupon_id, rawac.code, ROUND(rawac.amount / NULLIF(ro.rate, 0)) AS amount, rawac.amount AS raw_amount'),
                'ac', 'ac.order_id', '=', 'o.id'
            );
        }

        return $query->join('fct_applied_coupons as ac', 'ac.order_id', '=', 'o.id');
    }

    /**
     * Each customer's paid net to date in the report currency, for the customers the callback selects
     */
    private function customerLifetime(array $params, \Closure $customers)
    {
        return ReportHelper::joinShippingRefund(ReportCurrency::ordersTable($params), [], $customers)
            ->where('o.total_paid', '>', 0)
            ->whereIn('o.payment_status', Status::getReportStatuses())
            ->when(!empty($params['currency']) && $params['currency'] !== ReportCurrency::ALL, fn($q) => $q->where('o.currency', $params['currency']))
            ->whereIn('o.customer_id', $customers)
            ->selectRaw('o.customer_id, SUM(' . ReportHelper::netSalesSql($params) . ") AS lifetime,
                SUM(o.type <> '" . Status::ORDER_TYPE_RENEWAL . "') AS purchases")
            ->groupBy('o.customer_id');
    }

    /**
     * The Coupons overview: discount given and how much of the store leans on coupons, the new customers won with
     * a coupon against those at full price (first order, lifetime value to date, repeat and refund rates), a chart
     * by period, and the previous period's figures. Amounts in cents.
     */
    public function getOverview(array $params): array
    {
        $chartParams = $this->clampParamsToToday($params);
        $group = ReportHelper::processGroup($chartParams['startDate'], $chartParams['endDate'], $chartParams['groupKey']);

        $summary = $this->overviewFigures($params);
        $chart = $this->getPeriodRange($chartParams['startDate'], $chartParams['endDate'], $group['key'], ['discount', 'orders', 'coupon_orders']);
        foreach ($this->orderDiscounts($params, $group['by'])
            ->selectRaw('d.period, COUNT(*) AS orders, SUM(d.discount > 0) AS coupon_orders, SUM(d.discount) AS discount')
            ->groupBy('d.period')
            ->get() as $row) {
            if (isset($chart[$row->period])) {
                $chart[$row->period] = array_merge($chart[$row->period], [
                    'discount' => (int) $row->discount, 'orders' => (int) $row->orders, 'coupon_orders' => (int) $row->coupon_orders,
                ]);
            }
        }

        return [
            'summary'    => $summary,
            'previous'   => !empty($params['comparePeriod'])
                ? $this->overviewFigures(array_merge($params, ['startDate' => $params['comparePeriod'][0], 'endDate' => $params['comparePeriod'][1]]))
                : null,
            'comparison' => ($params['withComparison'] ?? true) ? $this->couponVsFullPrice($params) : null,
            'chart'      => array_values($chart),
        ];
    }

    /**
     * One row per paid order in the range: its coupon discount (0 without a coupon), paid and period
     */
    /**
     * The coupon discount of each order in the range, read once and grouped: applied coupons have no order_id
     * index, so a per-order lookup would scan the table for every order
     */
    private function rangeDiscounts(array $params)
    {
        $amount = ReportCurrency::isConverted($params) ? 'ROUND(rawac.amount / NULLIF(ro.rate, 0))' : 'rawac.amount';

        return App::db()->table('fct_applied_coupons as rawac')
            ->join('fct_orders as ro', 'ro.id', '=', 'rawac.order_id')
            ->whereBetween('ro.created_at', [$params['startDate'], $params['endDate']])
            ->selectRaw("rawac.order_id, SUM({$amount}) AS discount")
            ->groupBy('rawac.order_id');
    }

    private function orderDiscounts(array $params, string $period = "''")
    {
        // Paid orders, and orders a coupon made free: their discount was given all the same
        $orders = $this->applyFilters(ReportCurrency::ordersTable($params), $params)
            ->leftJoinSub($this->rangeDiscounts($params), 'cd', 'cd.order_id', '=', 'o.id')
            ->where(function ($q) {
                $q->where('o.total_paid', '>', 0)->orWhere('cd.discount', '>', 0);
            })
            ->selectRaw("{$period} AS period, o.id, o.total_paid AS paid, COALESCE(cd.discount, 0) AS discount");

        return App::db()->query()->fromSub($orders, 'd');
    }

    private function overviewFigures(array $params): array
    {
        $row = $this->orderDiscounts($params)
            ->selectRaw('COUNT(*) AS orders, SUM(d.discount > 0) AS coupon_orders, SUM(d.discount) AS discount,
                SUM(CASE WHEN d.discount > 0 THEN d.paid ELSE 0 END) AS coupon_paid')
            ->first();
        $won = $this->firstPurchases($params)->selectRaw('COUNT(*) AS customers, SUM(f.has_coupon) AS with_coupon')->first();

        $orders = $row ? (int) $row->orders : 0;
        $couponOrders = $row ? (int) $row->coupon_orders : 0;
        $discount = $row ? (int) $row->discount : 0;
        $couponPaid = $row ? (int) $row->coupon_paid : 0;
        $newCustomers = $won ? (int) $won->customers : 0;
        $withCoupon = $won ? (int) $won->with_coupon : 0;

        return [
            'discount'             => $discount,
            'orders'               => $orders,
            'coupon_orders'        => $couponOrders,
            'coupon_share'         => $orders ? round($couponOrders / $orders * 100, 1) : 0.0,
            'new_customers'        => $newCustomers,
            'coupon_new_customers' => $withCoupon,
            'new_share'            => $newCustomers ? round($withCoupon / $newCustomers * 100, 1) : 0.0,
            // The discount as a share of what the coupon orders would have cost at full price
            'discount_rate'        => ($couponPaid + $discount) > 0 ? round($discount / ($couponPaid + $discount) * 100, 1) : 0.0,
        ];
    }

    /**
     * The range's new customers (the shared first-purchase rule): whether a coupon was on the first order, its net
     * and whether it was refunded
     */
    private function firstPurchases(array $params)
    {
        $firsts = $this->applyFilters(ReportHelper::joinShippingRefund(ReportCurrency::ordersTable($params), $params), $params)
            ->where('o.total_paid', '>', 0)
            ->whereNotNull('o.customer_id')
            ->whereRaw(ReportHelper::firstPurchaseSql($params))
            ->leftJoinSub($this->rangeDiscounts($params), 'cd', 'cd.order_id', '=', 'o.id')
            ->selectRaw('o.customer_id, ' . ReportHelper::netSalesSql($params) . ' AS net, o.total_refund > 0 AS refunded,
                cd.order_id IS NOT NULL AS has_coupon');

        return App::db()->query()->fromSub($firsts, 'f');
    }

    private function couponVsFullPrice(array $params): array
    {
        $lifetime = $this->customerLifetime($params, function ($q) use ($params) {
            $q->select('ro.customer_id')->from('fct_orders as ro')
                ->whereBetween('ro.created_at', [$params['startDate'], $params['endDate']])
                ->whereNotNull('ro.customer_id');
        });
        $rows = $this->firstPurchases($params)
            ->leftJoinSub($lifetime, 'lt', 'lt.customer_id', '=', 'f.customer_id')
            ->selectRaw('f.has_coupon, COUNT(DISTINCT f.customer_id) AS customers, COUNT(*) AS orders, SUM(f.net) AS first_net,
                SUM(COALESCE(lt.lifetime, 0)) AS lifetime, SUM(lt.purchases >= 2) AS repeaters, SUM(f.refunded) AS refunded')
            ->groupBy('f.has_coupon')
            ->get()
            ->keyBy('has_coupon');

        $figures = function ($row) {
            $customers = $row ? (int) $row->customers : 0;
            $orders = $row ? (int) $row->orders : 0;

            return [
                'customers'     => $customers,
                'average_first' => $customers ? (int) round((int) $row->first_net / $customers) : 0,
                'average_ltv'   => $customers ? (int) round((int) $row->lifetime / $customers) : 0,
                'repeat_rate'   => $customers ? round((int) $row->repeaters / $customers * 100, 1) : 0.0,
                'refund_rate'   => $orders ? round((int) $row->refunded / $orders * 100, 1) : 0.0,
            ];
        };

        return ['coupon' => $figures($rows->get(1)), 'full_price' => $figures($rows->get(0))];
    }

    private function finish($row): array
    {
        $orders = (int) $row->orders;
        $gross = (float) $row->gross;
        $discount = (float) $row->discount;

        return [
            'orders'           => $orders,
            'customers'        => (int) $row->customers,
            'new_orders'       => (int) $row->new_orders,
            'returning_orders' => (int) $row->returning_orders,
            'guest_orders'     => (int) $row->guest_orders,
            'gross_sales'      => round($gross / 100, 2),
            'discount'         => round($discount / 100, 2),
            'refunds'          => round((float) $row->refunds / 100, 2),
            'net_sales'        => round((float) $row->net / 100, 2),
            'first_order_net'  => round((float) $row->first_net / 100, 2),
            'later_net'        => round((float) $row->later_net / 100, 2),
            'lifetime_net'     => round(((float) $row->first_net + (float) $row->later_net) / 100, 2),
            'discount_rate'    => ($gross + $discount) > 0 ? round($discount / ($gross + $discount) * 100, 2) : 0,
            'refund_rate'      => $orders ? round((int) ($row->refunded_orders ?? 0) / $orders * 100, 2) : 0,
            // What the customers this coupon won have spent with the store to date, each
            // Weighted by the coupon's share of each order, so two coupons on one first order each show the customer's full value
            'won_ltv'          => (float) ($row->won_weight ?? 0) > 0 ? round((float) $row->won_lifetime / (float) $row->won_weight / 100, 2) : 0,
            'average_order'    => $orders ? round($gross / $orders / 100, 2) : 0,
        ];
    }
}

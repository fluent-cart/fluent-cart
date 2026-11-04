<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;
use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Foundation\Exceptions\HttpException;

class ProductReportService extends ReportService
{
    /**
     * The money-based line split of getVariationRevenue(): each order's total_paid, total_refund, kept tax and net sales
     * shared across its lines by line amount, floored per line, the leftover cents on its first weighted line.
     *
     * @return array{0: \Closure, 1: \FluentCart\Framework\Database\Query\Builder, 2: array<string, string>}
     *         [orders query factory (o + oi), per-order shares joined as `ol`, per-line share SQL by paid/refund/tax/kept/net]
     */
    public function variationShareParts(array $params): array
    {
        $orders = function () use ($params) {
            // A $0 order is still an order: it counts with its units and adds no money
            $query = ReportCurrency::joinOrderItems(ReportHelper::joinShippingRefund(ReportCurrency::ordersTable($params), $params), $params);

            return $this->applyFilters($query, $params);
        };

        $columns = [
            'paid'   => 'o.total_paid',
            'refund' => 'o.total_refund',
            'tax'    => ReportHelper::unrefundedTaxSql('o.'),
            // What the store kept besides net sales (tax, shipping, fees); net = paid - refund - kept on every line
            'kept'   => '(o.total_paid - o.total_refund - ' . ReportHelper::netSalesSql($params) . ')',
        ];

        // A trial plan line charges nothing on its first order (OrderItem::chargedTotal()), so it takes no share
        $trialDays = "CAST(JSON_UNQUOTE(JSON_EXTRACT(oi.other_info, '$.trial_days')) AS SIGNED)";
        $weight = "(CASE WHEN oi.payment_type = 'subscription' AND {$trialDays} > 0 AND o.type <> '" . Status::ORDER_TYPE_RENEWAL . "'
            THEN 0 ELSE oi.line_total END)";

        // Floor each line's share; the order's leftover cents go to its first line that carries weight
        $lineSums = $orders()
            ->selectRaw("o.id AS order_id, SUM({$weight}) AS line_sum,
                COALESCE(MIN(CASE WHEN {$weight} > 0 THEN oi.id END), MIN(oi.id)) AS first_item_id")
            ->groupBy('o.id');

        $floors = [];
        foreach ($columns as $key => $sql) {
            $floors[] = "COALESCE(SUM(FLOOR(CAST({$sql} AS DECIMAL(30, 0)) * {$weight} / NULLIF(s.line_sum, 0))), 0) AS {$key}_floored";
        }
        $orderShares = $orders()
            ->joinSub($lineSums, 's', 's.order_id', '=', 'o.id')
            ->selectRaw('o.id AS order_id, MAX(s.line_sum) AS line_sum, MAX(s.first_item_id) AS first_item_id, ' . implode(', ', $floors))
            ->groupBy('o.id');

        $share = [];
        foreach ($columns as $key => $sql) {
            $share[$key] = "(CASE
                WHEN ol.line_sum > 0 THEN FLOOR(CAST({$sql} AS DECIMAL(30, 0)) * {$weight} / ol.line_sum)
                    + CASE WHEN oi.id = ol.first_item_id THEN {$sql} - ol.{$key}_floored ELSE 0 END
                WHEN oi.id = ol.first_item_id THEN {$sql}
                ELSE 0
            END)";
        }
        $share['net'] = "({$share['paid']} - {$share['refund']} - {$share['kept']})";

        return [$orders, $orderShares, $share];
    }

    /**
     * Per order, the share of its money that the picked variations' lines carry, by the same split as
     * getVariationRevenue(), so totals built from it equal revenue by price ID for those variations.
     *
     * @param array $params report params with variationIds set
     * @return \FluentCart\Framework\Database\Query\Builder rows: order_id, paid, refund, tax, net (cents)
     */
    public function orderVariationShares(array $params)
    {
        [$orders, $orderShares, $share] = $this->variationShareParts($params);
        $ids = implode(', ', array_map('intval', (array) $params['variationIds']));
        $picked = function ($sql) use ($ids) {
            return "SUM(CASE WHEN oi.object_id IN ({$ids}) THEN {$sql} ELSE 0 END)";
        };

        return $orders()
            ->joinSub($orderShares, 'ol', 'ol.order_id', '=', 'o.id')
            ->selectRaw('o.id AS order_id, ' . $picked($share['paid']) . ' AS paid, ' . $picked($share['refund']) . ' AS refund, '
                . $picked($share['tax']) . ' AS tax, ' . $picked($share['net']) . ' AS net')
            ->groupBy('o.id');
    }

    /**
     * Every variation sold in the range: units, gross, refunds and net sales, split into
     * one-time lines and recurring (subscription, renewal, signup fee) lines.
     *
     * Money-based: each order's total_paid, total_refund and net sales are shared across its lines by
     * line amount, so the rows add up to the money taken even when stored discounts disagree with it.
     * A $0 order counts with its units. Amounts in cents.
     */
    public function getVariationRevenue(array $params): array
    {
        [$orders, $orderShares, $share] = $this->variationShareParts($params);

        // A renewal order is recurring whatever its line says: Pro writes early payoffs as 'onetime' lines on one
        $recurring = "(oi.payment_type IN ('subscription', 'signup_fee') OR o.type = '" . Status::ORDER_TYPE_RENEWAL . "')";
        // A signup fee line repeats its plan's variation and quantity; count the plan once
        $countsUnits = "oi.payment_type <> 'signup_fee'";

        $parts = [];
        foreach (['one_time' => "NOT {$recurring}", 'recurring' => $recurring] as $key => $when) {
            $parts[] = "SUM(CASE WHEN {$when} AND {$countsUnits} THEN oi.quantity ELSE 0 END) AS {$key}_units,
                COUNT(DISTINCT CASE WHEN {$when} THEN o.id END) AS {$key}_orders,
                SUM(CASE WHEN {$when} THEN {$share['paid']} ELSE 0 END) AS {$key}_gross,
                SUM(CASE WHEN {$when} THEN {$share['refund']} ELSE 0 END) AS {$key}_refunds,
                SUM(CASE WHEN {$when} THEN {$share['net']} ELSE 0 END) AS {$key}_net";
        }

        $rows = $orders()
            ->joinSub($orderShares, 'ol', 'ol.order_id', '=', 'o.id')
            ->when(!empty($params['variationIds']), fn($q) => $q->whereIn('oi.object_id', $params['variationIds']))
            ->selectRaw("
                oi.object_id AS variation_id,
                MAX(oi.post_id) AS product_id,
                MAX(oi.post_title) AS product_title,
                COALESCE(MAX(CASE WHEN {$countsUnits} THEN oi.title END), MAX(oi.title)) AS variation_title,
                COUNT(DISTINCT CASE WHEN {$share['refund']} > 0 THEN o.id END) AS refunded_orders,
                " . implode(",\n", $parts))
            ->groupBy('oi.object_id')
            ->get();

        $metrics = ['units', 'orders', 'gross', 'refunds', 'net'];
        $result = [];
        $totals = array_fill_keys(['units', 'gross', 'refunds', 'net'], 0);
        foreach (['one_time', 'recurring'] as $key) {
            foreach (['units', 'gross', 'refunds', 'net'] as $metric) {
                $totals[$key . '_' . $metric] = 0;
            }
        }

        // Lines with no variation (checkout fees, custom items) stay in the totals, outside the variation rows
        foreach (['gross', 'refunds', 'net'] as $metric) {
            $totals['fees_' . $metric] = 0;
        }

        foreach ($rows as $row) {
            if ((int) $row->variation_id === 0) {
                foreach (['gross', 'refunds', 'net'] as $metric) {
                    $amount = (int) $row->{'one_time_' . $metric} + (int) $row->{'recurring_' . $metric};
                    $totals['fees_' . $metric] += $amount;
                    $totals[$metric] += $amount;
                }
                continue;
            }

            $item = [
                'variation_id'    => (int) $row->variation_id,
                'product_id'      => (int) $row->product_id,
                'product_title'   => (string) $row->product_title,
                'variation_title' => (string) $row->variation_title,
                'refunded_orders' => (int) $row->refunded_orders,
            ];

            foreach (['one_time', 'recurring'] as $key) {
                foreach ($metrics as $metric) {
                    $item[$key . '_' . $metric] = (int) $row->{$key . '_' . $metric};
                }
            }

            foreach (['units', 'gross', 'refunds', 'net'] as $metric) {
                $item[$metric] = $item['one_time_' . $metric] + $item['recurring_' . $metric];
                $totals[$metric] += $item[$metric];
                $totals['one_time_' . $metric] += $item['one_time_' . $metric];
                $totals['recurring_' . $metric] += $item['recurring_' . $metric];
            }
            $item['orders'] = $item['one_time_orders'] + $item['recurring_orders'];

            $result[] = $item;
        }

        usort($result, function ($a, $b) {
            return $b['net'] <=> $a['net'] ?: $a['variation_id'] <=> $b['variation_id'];
        });

        return [
            'variations' => $result,
            'totals'     => $totals,
        ];
    }

    /**
     * The Products page: one row per product with its variants, totals and a chart by period. Money-based, as
     * getVariationRevenue(): each order's payment, refund and net sales shared across its lines. Net sales split by
     * sale type into new sales, renewals and installment payments. With comparePeriod, each product's previous net
     * and the previous totals. Amounts in cents.
     */
    public function getProductSales(array $params): array
    {
        $current = $this->productSalesPeriod($params, true);

        $previous = null;
        if (!empty($params['comparePeriod'])) {
            $previous = $this->productSalesPeriod(array_merge($params, [
                'startDate' => $params['comparePeriod'][0],
                'endDate'   => $params['comparePeriod'][1],
            ]), false);
        }

        $previousNet = $previous ? array_column($previous['products'], 'net', 'product_id') : [];
        foreach ($current['products'] as &$product) {
            $product['previous_net'] = $previous ? (int) Arr::get($previousNet, $product['product_id'], 0) : null;
        }
        unset($product);

        return [
            'summary'  => $current['summary'],
            'previous' => $previous ? $previous['summary'] : null,
            'products' => $current['products'],
            'chart'    => $current['chart'],
        ];
    }

    /**
     * @param bool $withDetail the chart and each row's customers; the previous period needs only net and totals
     */
    private function productSalesPeriod(array $params, bool $withDetail): array
    {
        [$orders, $orderShares, $share] = $this->variationShareParts($params);
        $net = $share['net'];
        // Only a renewal needs the plan lookup that tells an installment payment apart
        $type = ReportHelper::saleTypeSql();
        $renewal = Status::ORDER_TYPE_RENEWAL;
        $money = [
            'gross'           => "SUM({$share['paid']})",
            'refunds'         => "SUM({$share['refund']})",
            'net'             => "SUM({$net})",
            'new_net'         => "SUM(CASE WHEN o.type <> '{$renewal}' THEN {$net} ELSE 0 END)",
            'renewal_net'     => "SUM(CASE WHEN o.type <> '{$renewal}' THEN 0 WHEN {$type} = 'renewal' THEN {$net} ELSE 0 END)",
            'installment_net' => "SUM(CASE WHEN o.type <> '{$renewal}' THEN 0 WHEN {$type} = 'installment_payment' THEN {$net} ELSE 0 END)",
        ];
        // A signup fee line repeats its plan's variation and quantity, and a checkout fee is no product: neither is a unit
        $money['units'] = "SUM(CASE WHEN oi.payment_type NOT IN ('signup_fee', 'fee') THEN oi.quantity ELSE 0 END)";
        $selectMoney = implode(', ', array_map(function ($sql, $key) {
            return "{$sql} AS {$key}";
        }, $money, array_keys($money)));
        // A $0 order counts as an order, but customers are paid-only, as on the Customers report
        $counts = 'COUNT(DISTINCT o.id) AS orders, COUNT(DISTINCT CASE WHEN o.total_paid > 0 THEN o.customer_id END) AS customers,
            COUNT(DISTINCT CASE WHEN o.total_paid > 0 AND ' . ReportHelper::firstPurchaseSql($params) . ' THEN o.customer_id END) AS new_customers';

        // Counts need no money, so they skip the costly per-order shares
        $lines = function ($withShares) use ($orders, $orderShares, $params) {
            return $orders()
                ->when($withShares, fn($q) => $q->joinSub($orderShares, 'ol', 'ol.order_id', '=', 'o.id'))
                ->when(!empty($params['variationIds']), fn($q) => $q->whereIn('oi.object_id', $params['variationIds']));
        };

        // One money read, by period too when the chart is wanted: an order sits in one period, so its count adds up
        $chartParams = $this->clampParamsToToday($params);
        $group = ReportHelper::processGroup($chartParams['startDate'], $chartParams['endDate'], $chartParams['groupKey']);
        $rows = $lines(true)
            ->selectRaw(($withDetail ? "{$group['by']} AS period, " : '') . "oi.post_id AS product_id, oi.object_id AS variation_id,
                MAX(oi.post_title) AS product_title,
                COALESCE(MAX(CASE WHEN oi.payment_type <> 'signup_fee' THEN oi.title END), MAX(oi.title)) AS variation_title,
                COUNT(DISTINCT o.id) AS orders, {$selectMoney}")
            ->when($withDetail, fn($q) => $q->groupByRaw("{$group['by']}, oi.post_id, oi.object_id"),
                fn($q) => $q->groupBy('oi.post_id', 'oi.object_id'))
            ->get();

        // Customers once per variant and per product, not summed over periods or variants
        $variationCounts = $productCounts = null;
        if ($withDetail) {
            $variationCounts = [];
            foreach ($lines(false)->selectRaw("oi.object_id AS variation_id, oi.post_id AS product_id, {$counts}")
                ->groupBy('oi.post_id', 'oi.object_id')->get() as $row) {
                $variationCounts[$row->product_id . ':' . $row->variation_id] = $row;
            }
            $productCounts = $lines(false)->where('oi.object_id', '>', 0)
                ->selectRaw("oi.post_id AS product_id, {$counts}")->groupBy('oi.post_id')->get()->keyBy('product_id');
        }
        $totals = $lines(false)->selectRaw($counts)->first();

        $keys = array_keys($money);
        $chartKeys = ['units', 'new_net', 'renewal_net', 'installment_net'];
        $chart = $withDetail ? $this->getPeriodRange($chartParams['startDate'], $chartParams['endDate'], $group['key'], $chartKeys) : [];
        $summary = array_fill_keys($keys, 0);
        $variations = [];
        foreach ($rows as $row) {
            foreach ($keys as $key) {
                $summary[$key] += (int) $row->{$key};
            }
            if ($withDetail && isset($chart[$row->period])) {
                foreach ($chartKeys as $key) {
                    $chart[$row->period][$key] += (int) $row->{$key};
                }
            }
            // Lines with no variation (checkout fees, custom items) count in the totals only
            if ((int) $row->variation_id === 0) {
                continue;
            }
            $id = $row->product_id . ':' . $row->variation_id;
            if (!isset($variations[$id])) {
                $variations[$id] = array_merge([
                    'product_id'      => (int) $row->product_id,
                    'variation_id'    => (int) $row->variation_id,
                    'product_title'   => (string) $row->product_title,
                    'variation_title' => (string) $row->variation_title,
                    'orders'          => 0,
                ], array_fill_keys($keys, 0));
            }
            $variations[$id]['orders'] += (int) $row->orders;
            foreach ($keys as $key) {
                $variations[$id][$key] += (int) $row->{$key};
            }
        }

        [$productTitles, $variationTitles] = $this->currentTitles($variations);
        $products = [];
        foreach ($variations as $id => $variation) {
            $productId = $variation['product_id'];
            if (!isset($products[$productId])) {
                $counted = $productCounts ? $productCounts->get($productId) : null;
                $products[$productId] = array_merge([
                    'product_id'    => $productId,
                    'product_title' => (string) Arr::get($productTitles, $productId, $variation['product_title']),
                    'orders'        => $counted ? (int) $counted->orders : 0,
                    'customers'     => $counted ? (int) $counted->customers : 0,
                    'new_customers' => $counted ? (int) $counted->new_customers : 0,
                    'variations'    => [],
                ], array_fill_keys($keys, 0));
            }
            foreach ($keys as $key) {
                $products[$productId][$key] += $variation[$key];
            }
            $counted = $variationCounts ? Arr::get($variationCounts, $id) : null;
            unset($variation['product_id'], $variation['product_title']);
            $variation['variation_title'] = (string) Arr::get($variationTitles, $variation['variation_id'], $variation['variation_title']);
            $variation['customers'] = $counted ? (int) $counted->customers : 0;
            $variation['new_customers'] = $counted ? (int) $counted->new_customers : 0;
            $variation['refund_rate'] = self::refundRate($variation['refunds'], $variation['gross']);
            $products[$productId]['variations'][] = $variation;
        }

        $byNet = function ($a, $b) {
            return $b['net'] <=> $a['net'] ?: ($a['variation_id'] ?? $a['product_id']) <=> ($b['variation_id'] ?? $b['product_id']);
        };
        foreach ($products as &$product) {
            $product['refund_rate'] = self::refundRate($product['refunds'], $product['gross']);
            usort($product['variations'], $byNet);
        }
        unset($product);
        $products = array_values($products);
        usort($products, $byNet);

        $summary['orders'] = $totals ? (int) $totals->orders : 0;
        $summary['customers'] = $totals ? (int) $totals->customers : 0;
        $summary['new_customers'] = $totals ? (int) $totals->new_customers : 0;
        $summary['refund_rate'] = self::refundRate($summary['refunds'], $summary['gross']);
        $summary['average_order'] = $summary['orders'] ? (int) round($summary['net'] / $summary['orders']) : 0;

        return [
            'summary'  => $summary,
            'products' => $products,
            'chart'    => array_values($chart),
        ];
    }

    /**
     * Today's product and variant titles, so a renamed product shows one name; the title at sale stays the fallback
     */
    private function currentTitles(array $variations): array
    {
        $productIds = array_values(array_unique(array_column($variations, 'product_id')));
        $variationIds = array_values(array_unique(array_column($variations, 'variation_id')));

        $products = $productIds ? App::db()->table('posts')->whereIn('ID', $productIds)->pluck('post_title', 'ID')->toArray() : [];
        $variationTitles = $variationIds
            ? App::db()->table('fct_product_variations')->whereIn('id', $variationIds)->pluck('variation_title', 'id')->toArray()
            : [];

        return [$products, $variationTitles];
    }

    protected static function refundRate(int $refunds, int $gross): float
    {
        return $gross > 0 ? round($refunds / $gross * 100, 1) : 0.0;
    }

    // Header discounts are normally mirrored into item discount_total; admin and legacy orders can leave a remainder,
    // and in the ALL mode per-item conversion can disagree with converting the order once. Such orders get
    // their order-level net split in whole cents: floor per item, leftover cents to the first item.
    public static function lineTotalSql(string $item = 'oi', string $od = 'od'): string
    {
        return "(CASE WHEN {$od}.order_id IS NULL OR {$od}.net = {$od}.line_sum THEN {$item}.line_total ELSE
            COALESCE(FLOOR({$od}.net * {$item}.line_total / NULLIF({$od}.line_sum, 0)), 0)
            + CASE WHEN {$item}.id = {$od}.first_item_id THEN {$od}.net - {$od}.floored ELSE 0 END
        END)";
    }

    /**
     * An item refund comes out of OrderItem::chargedTotal(), tax included, so split it into line share and kept tax.
     *
     * @param string $lineTotal SQL for the line revenue after order-level credits, which is what the line charged;
     *                          must be lineTotalSql($item, $od) so the excess split matches orderDiscountSplitQuery()
     * @param int    $tolerance cents a renewal line may miss subtotal - discount + tax by and still count as inclusive;
     *                          2 for converted amounts, else 0, as orderDiscountSplitQuery() uses
     * @param string $item      order items table or alias
     * @param string $order     orders table or alias
     * @param string $od        alias of the orderDiscountSplitQuery() join
     * @return array{charged: string, refund: string, share: string, kept_tax: string} per-line SQL expressions
     */
    public static function itemRefundSplitSql(string $lineTotal, int $tolerance, string $item = 'oi', string $order = 'o', string $od = 'od'): array
    {
        // The refunded status alone can sit on a part-refunded order; only a refund that covers the payment is full.
        // Old refunds were stored without tax, so the payment less tax is enough
        $refunded = "({$order}.payment_status = '" . Status::PAYMENT_REFUNDED . "'
            AND {$order}.total_refund >= {$order}.total_paid - {$order}.tax_total - {$order}.shipping_tax)";
        [$inclusive, $charged] = self::chargedSql($lineTotal, $tolerance, $item, $order);

        // Refunds are spread by chargedTotal(), which ignores an order-level credit, so a line can hold more
        // than it paid. The excess moves to the order's other lines by what is still unrefunded on each,
        // floored per line with the leftover cents on the first line that has room.
        $room = self::roomSql($charged, $item);
        $refund = "(CASE
            WHEN {$refunded} THEN {$charged}
            ELSE LEAST({$item}.refund_total, {$charged}) + CASE WHEN {$od}.excess > 0 THEN LEAST({$room}, " . self::flooredExcessSql($room, $od) . "
                + CASE WHEN {$item}.id = {$od}.excess_item_id THEN {$od}.excess - {$od}.floored_excess ELSE 0 END) ELSE 0 END
        END)";

        $share = "(CASE
            WHEN {$refunded} THEN {$lineTotal}
            WHEN {$charged} = 0 THEN 0
            ELSE ROUND(CAST({$refund} AS DECIMAL(30, 0)) * {$lineTotal} / {$charged})
        END)";
        $keptTax = "(CASE
            WHEN {$refunded} OR NOT {$inclusive} THEN 0
            WHEN {$charged} = 0 THEN {$item}.tax_amount
            ELSE ROUND(CAST({$item}.tax_amount AS DECIMAL(30, 0)) * ({$charged} - {$refund}) / {$charged})
        END)";

        return ['charged' => $charged, 'refund' => $refund, 'share' => $share, 'kept_tax' => $keptTax];
    }

    /**
     * @return string[] [inclusive, charged] SQL: whether the line's tax sits inside it, and what the line charged
     */
    private static function chargedSql(string $lineTotal, int $tolerance, string $item, string $order): array
    {
        $flag = "JSON_UNQUOTE(JSON_EXTRACT({$item}.line_meta, '$.tax_config.inclusive'))";
        $inclusion = "JSON_UNQUOTE(JSON_EXTRACT({$item}.other_info, '$.tax_inclusion'))";
        $trialDays = "CAST(JSON_UNQUOTE(JSON_EXTRACT({$item}.other_info, '$.trial_days')) AS SIGNED)";
        $renewal = Status::ORDER_TYPE_RENEWAL;

        $inclusive = "(CASE
            WHEN {$flag} IS NOT NULL AND {$flag} <> 'null' THEN LOWER(TRIM({$flag})) IN ('1', 'true', 'on', 'yes')
            WHEN {$inclusion} IN ('included', 'excluded') THEN {$inclusion} = 'included'
            WHEN {$order}.type = '{$renewal}' AND {$item}.tax_amount > 0
                AND ABS({$item}.line_total - ({$item}.subtotal - {$item}.discount_total + {$item}.tax_amount)) <= {$tolerance} THEN 1
            ELSE {$order}.tax_behavior = 2
        END)";

        $charged = "(CASE
            WHEN {$item}.payment_type = 'subscription' AND {$trialDays} > 0 AND {$order}.type <> '{$renewal}' THEN 0
            ELSE {$lineTotal} + CASE WHEN {$inclusive} THEN 0 ELSE {$item}.tax_amount END
        END)";

        return [$inclusive, $charged];
    }

    /**
     * Orders whose net differs from their line sum, for lineTotalSql() to spread over the lines.
     *
     * @param \FluentCart\Framework\Database\Query\Builder $orders query on `fct_orders as o`, already scoped
     * @param bool $converted amounts are converted to the store currency by o.rate
     * @return \FluentCart\Framework\Database\Query\Builder one row per order: order_id, net, line_sum, sub, sub_sum,
     *         first_item_id, floored, floored_sub, and excess, room, excess_item_id, floored_excess for itemRefundSplitSql()
     */
    public static function orderDiscountSplitQuery($orders, bool $converted = false)
    {
        // Native amounts are needed here: the order's net is rounded once, the Sales report way, not per column
        $lineTotal = $converted ? 'ROUND(pi.line_total / o.rate)' : 'pi.line_total';
        $subtotal = $converted ? 'ROUND(pi.subtotal / o.rate)' : 'pi.subtotal';
        // A header discount counts only as far as the order total shows it was applied: a stale manual
        // discount (equal to the subtotal while the customer paid in full) must not zero the order
        $remainder = '(o.manual_discount_total + o.coupon_discount_total - SUM(pi.discount_total))';
        $exclusiveTax = '(CASE WHEN o.tax_behavior = 2 THEN 0 ELSE o.tax_total + o.shipping_tax END)';
        // A fee kept only in the order header (no fee line) is in the total but not in the lines
        $headerFee = "GREATEST(0, o.fee_total - SUM(CASE WHEN pi.payment_type = 'fee' THEN pi.line_total ELSE 0 END))";
        $applied = "GREATEST(0, SUM(pi.line_total) - (o.total_amount - o.shipping_total - {$exclusiveTax} - {$headerFee}))";
        $nativeNet = "SUM(pi.line_total) - (CASE WHEN {$remainder} > 0 THEN LEAST({$remainder}, {$applied}) ELSE {$remainder} END)";
        // Lines never earn more than was paid: a credited renewal or an order marked paid with nothing collected
        // keeps its line price. A loose bound: tax and shipping stay in, as some lines hold them
        $nativeNet = "LEAST({$nativeNet}, GREATEST(0, o.total_paid - {$headerFee}))";
        $net = $converted ? "ROUND(({$nativeNet}) / o.rate)" : $nativeNet;
        $sub = $converted ? 'ROUND(SUM(pi.subtotal) / o.rate)' : 'SUM(pi.subtotal)';

        $orders = $orders
            ->join('fct_order_items as pi', 'pi.order_id', '=', 'o.id')
            ->selectRaw("
                o.id,
                o.rate,
                {$net} AS net,
                SUM({$lineTotal}) AS line_sum,
                {$sub} AS sub,
                SUM({$subtotal}) AS sub_sum
            ")
            ->groupBy('o.id', 'o.rate', 'o.total_paid', 'o.manual_discount_total', 'o.coupon_discount_total',
                'o.tax_behavior', 'o.tax_total', 'o.shipping_tax', 'o.total_amount', 'o.shipping_total', 'o.fee_total')
            ->havingRaw('net <> line_sum OR sub <> sub_sum');

        $split = App::db()->table('fct_order_items as pi')
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

        $columns = ['sd.order_id', 'sd.net', 'sd.line_sum', 'sd.sub', 'sd.sub_sum', 'sd.first_item_id', 'sd.floored', 'sd.floored_sub'];

        [$items, $charged] = self::creditedLinesQuery($split, $converted);
        $room = self::roomSql($charged, 'ri');
        $sums = $items->select($columns)
            ->selectRaw("GREATEST(LEAST(SUM(ri.refund_total), SUM({$charged})) - SUM(LEAST(ri.refund_total, {$charged})), 0) AS excess")
            ->selectRaw("SUM({$room}) AS room")
            ->groupBy($columns);

        $columns = array_merge($columns, ['sd.excess', 'sd.room']);
        [$items, $charged] = self::creditedLinesQuery($sums, $converted);
        $room = self::roomSql($charged, 'ri');

        return $items->select($columns)
            ->selectRaw("MIN(CASE WHEN {$room} > 0 THEN ri.id END) AS excess_item_id")
            ->selectRaw('SUM(CASE WHEN sd.excess > 0 THEN ' . self::flooredExcessSql($room, 'sd') . ' ELSE 0 END) AS floored_excess')
            ->groupBy($columns);
    }

    /**
     * Every line of the credited orders in $split, with SQL for what each line charged.
     *
     * @return array{0: \FluentCart\Framework\Database\Query\Builder, 1: string} [query, charged]
     */
    private static function creditedLinesQuery($split, bool $converted): array
    {
        $items = $converted
            ? App::db()->query()->fromSub(ReportCurrency::convertedOrderItems(), 'ri')
            : App::db()->table('fct_order_items as ri');
        [, $charged] = self::chargedSql(self::lineTotalSql('ri', 'sd'), $converted ? 2 : 0, 'ri', 'ro');

        $query = $items
            ->joinSub($split, 'sd', 'sd.order_id', '=', 'ri.order_id')
            ->join('fct_orders as ro', 'ro.id', '=', 'ri.order_id');

        return [$query, $charged];
    }

    // What the line charged and has not had refunded yet
    private static function roomSql(string $charged, string $item): string
    {
        return "GREATEST({$charged} - {$item}.refund_total, 0)";
    }

    private static function flooredExcessSql(string $room, string $od): string
    {
        return "FLOOR({$od}.excess * {$room} / {$od}.room)";
    }

}
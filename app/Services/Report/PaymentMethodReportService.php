<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\MultiCurrency\Services\BaseAmountSql;
use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\Framework\Support\Arr;

/**
 * Per payment gateway: orders, revenue, refunds, disputes, failures, and subscriptions failing now. Also the previous
 * period's totals, net sales by gateway over time, and sales by card brand or payment method.
 */
class PaymentMethodReportService extends ReportService
{
    private const SAVED_CARD = 'fct_saved_card';

    // Card brands and payment method types the gateways record. A list, because some gateways store a
    // transaction id in the type field
    private const METHODS = [
        'visa', 'mastercard', 'amex', 'american_express', 'discover', 'jcb', 'diners', 'unionpay', 'cartes_bancaires',
        'interac', 'eftpos_au', 'maestro', 'elo', 'rupay', 'card', 'link', 'cashapp', 'apple_pay', 'google_pay',
        'amazon_pay', 'paypal', 'venmo', 'klarna', 'afterpay_clearpay', 'affirm', 'zip', 'us_bank_account', 'sepa_debit',
        'bacs_debit', 'au_becs_debit', 'acss_debit', 'ideal', 'bancontact', 'sofort', 'giropay', 'eps', 'p24', 'blik',
        'alipay', 'wechat_pay', 'boleto', 'oxxo', 'pix', 'revolut_pay', 'mobilepay', 'twint', 'multibanco', 'swish',
    ];

    /**
     * @param array $params processed report params (startDate, endDate, currency, paymentStatus, comparePeriod, groupKey)
     * @return array{rows: array, totals: array, previous: ?array, chart: array}
     */
    public function getReport(array $params): array
    {
        $report = $this->gateways($params, true);
        // Subscriptions failing now have no date, so the previous period skips them
        $report['previous'] = !empty($params['comparePeriod'])
            ? $this->gateways(array_merge($params, ['startDate' => $params['comparePeriod'][0], 'endDate' => $params['comparePeriod'][1]]), false)['totals']
            : null;
        $report['chart'] = $this->chart($params);

        // Disputes by gateway and card brands are FluentCart Pro reports; the store's dispute rate stays for the
        // Overview's Needs Attention alert
        $disputeRate = $report['totals']['dispute_rate'];
        $report['rows'] = array_map([$this, 'withoutDisputes'], $report['rows']);
        $report['totals'] = $this->withoutDisputes($report['totals']) + ['dispute_rate' => $disputeRate];
        $report['previous'] = $report['previous'] ? $this->withoutDisputes($report['previous']) : null;

        return $report;
    }

    protected function withoutDisputes(array $row): array
    {
        return array_diff_key($row, array_flip(['disputed_orders', 'dispute_rate', 'disputes_lost', 'dispute_amount']));
    }

    protected function gateways(array $params, bool $withNow): array
    {
        $netSql = ReportHelper::netSalesSql($params);
        $rows = [];
        $add = function ($key, array $values) use (&$rows) {
            $key = (string) $key !== '' ? (string) $key : 'unknown';
            $rows[$key] = array_merge($rows[$key] ?? [], $values);
        };

        foreach ($this->ordersByGateway($params)
            ->selectRaw("COUNT(*) AS orders, SUM(o.total_refund > 0) AS refunded_orders, SUM(o.total_paid) AS gross,
                SUM(o.total_refund) AS refunds, SUM({$netSql}) AS net,
                SUM(CASE WHEN o.type = '" . Status::ORDER_TYPE_RENEWAL . "' THEN {$netSql} ELSE 0 END) AS renewal_net")
            ->get() as $row) {
            $add($row->gateway_key, ['orders' => (int) $row->orders, 'refunded_orders' => (int) $row->refunded_orders,
                'gross' => (int) $row->gross, 'refunds' => (int) $row->refunds, 'net' => (int) $row->net,
                'renewal_net' => (int) $row->renewal_net]);
        }

        // A dispute lost on the full charge fails its order, so failed orders join the count and the rate's base
        $disputeParams = array_merge($params, ['paymentStatus' => array_merge((array) ($params['paymentStatus'] ?? []), [Status::PAYMENT_FAILED])]);
        foreach ($this->ordersByGateway($disputeParams)
            ->joinSub($this->disputedOrders($params), 'dp', 'dp.order_id', '=', 'o.id')
            ->selectRaw('COUNT(*) AS disputed_orders, SUM(o.payment_status = ?) AS failed_disputed', [Status::PAYMENT_FAILED])
            ->get() as $row) {
            $add($row->gateway_key, ['disputed_orders' => (int) $row->disputed_orders,
                'failed_disputed' => (int) $row->failed_disputed]);
        }

        // The order filters, but failed orders instead of the report statuses
        $failedParams = array_merge($params, ['paymentStatus' => [Status::PAYMENT_FAILED]]);
        foreach ($this->ordersByGateway($failedParams)->selectRaw('COUNT(*) AS failed_orders')->get() as $row) {
            $add($row->gateway_key, ['failed_orders' => (int) $row->failed_orders]);
        }

        $transactions = [
            // Only some gateways record a declined charge; others retry on their side and record nothing
            ['charge', Status::TRANSACTION_FAILED, 'failed_charges', 'failed_charge_amount'],
            ['dispute', 'dispute_lost', 'disputes_lost', 'dispute_amount'],
        ];
        foreach ($transactions as [$type, $status, $countKey, $amountKey]) {
            foreach ($this->transactionsByGateway($params, $type, $status) as $row) {
                $add($row->gateway, [$countKey => (int) $row->transactions, $amountKey => (int) $row->amount]);
            }
        }

        $atRiskX12 = 0;
        foreach ($withNow ? $this->failingNow($params) : [] as $row) {
            $atRiskX12 += (int) $row->mrr_x12;
            $add($row->gateway, ['failing_subscriptions' => (int) $row->subscriptions, 'mrr_at_risk_x12' => (int) $row->mrr_x12]);
        }

        $totals = $this->blank();
        $result = [];
        foreach ($rows as $key => $values) {
            $values = array_merge($this->blank(), $values);
            foreach ($totals as $metric => $value) {
                $totals[$metric] += $values[$metric];
            }
            $result[] = array_merge(['gateway' => $key, 'label' => $this->label($key)], $this->finish($values));
        }

        $netTotal = $totals['net'];
        foreach ($result as &$row) {
            $row['net_share'] = $netTotal ? round($row['net_sales'] * 100 / ($netTotal / 100), 2) : 0;
        }
        unset($row);
        usort($result, function ($a, $b) {
            return $b['net_sales'] <=> $a['net_sales'] ?: strcmp($a['gateway'], $b['gateway']);
        });

        // Rounded once from the summed cents, so it equals the subscription health card
        $totals = $this->finish($totals);
        $totals['mrr_at_risk'] = SubscriptionReportService::mrrFromTimes12($atRiskX12);
        $totals['net_share'] = $netTotal ? 100 : 0;

        return ['rows' => $result, 'totals' => $totals];
    }

    /**
     * Report orders grouped by gateway. A saved-card payment counts under the gateway that charged it.
     */
    private function ordersByGateway(array $params)
    {
        $savedCard = App::db()->table('fct_order_transactions as st')
            ->join('fct_orders as so', 'so.id', '=', 'st.order_id')
            ->selectRaw('st.order_id, MAX(st.payment_method) AS gateway')
            ->where('so.payment_method', self::SAVED_CARD)
            ->where('st.transaction_type', 'charge')
            ->groupBy('st.order_id');

        $gateway = "(CASE WHEN o.payment_method = '" . self::SAVED_CARD . "' THEN COALESCE(sc.gateway, o.payment_method)
            ELSE o.payment_method END)";

        return $this->applyFilters(ReportHelper::joinShippingRefund(ReportCurrency::ordersTable($params), $params), $params)
            ->leftJoinSub($savedCard, 'sc', 'sc.order_id', '=', 'o.id')
            // Not `gateway`: GROUP BY would read the joined sc.gateway column instead of this alias
            ->selectRaw("{$gateway} AS gateway_key")
            ->groupBy('gateway_key');
    }

    /**
     * Orders whose charge was disputed. A gateway turns the charge into a dispute and, if the store wins,
     * back into a charge; the dispute id stays in its meta.
     */
    private function disputedOrders(array $params)
    {
        return App::db()->table('fct_order_transactions as dt')
            ->join('fct_orders as do', 'do.id', '=', 'dt.order_id')
            ->whereBetween('do.created_at', [$params['startDate'], $params['endDate']])
            ->where(function ($q) {
                $q->where('dt.transaction_type', Status::TRANSACTION_TYPE_DISPUTE)->orWhere('dt.meta', 'LIKE', '%"dispute_id"%');
            })
            ->selectRaw('DISTINCT dt.order_id');
    }

    /**
     * Net sales by gateway per day, month or year
     */
    private function chart(array $params): array
    {
        $params = $this->clampParamsToToday($params);
        $group = ReportHelper::processGroup($params['startDate'], $params['endDate'], $params['groupKey']);
        $chart = $this->getPeriodRange($params['startDate'], $params['endDate'], $group['key']);
        foreach ($chart as &$point) {
            $point['gateways'] = [];
        }
        unset($point);

        foreach ($this->ordersByGateway($params)
            ->selectRaw("{$group['by']} AS period, SUM(" . ReportHelper::netSalesSql($params) . ') AS net')
            ->groupBy('period')
            ->get() as $row) {
            if (isset($chart[$row->period])) {
                $chart[$row->period]['gateways'][(string) $row->gateway_key ?: 'unknown'] = round($row->net / 100, 2);
            }
        }
        foreach ($chart as &$point) {
            ksort($point['gateways']);
        }
        unset($point);

        return array_values($chart);
    }

    /**
     * Report orders by the card brand or payment method their charge recorded. Orders with none are left out,
     * and coverage says how many orders had one.
     */
    protected function methods(array $params): array
    {
        $list = "'" . implode("', '", self::METHODS) . "'";
        $method = "COALESCE(CASE WHEN LOWER(mt.card_brand) IN ({$list}) THEN LOWER(mt.card_brand) END,"
            . " CASE WHEN LOWER(mt.payment_method_type) IN ({$list}) THEN LOWER(mt.payment_method_type) END)";
        $recorded = App::db()->table('fct_order_transactions as mt')
            ->join('fct_orders as mo', 'mo.id', '=', 'mt.order_id')
            ->whereBetween('mo.created_at', [$params['startDate'], $params['endDate']])
            ->whereIn('mt.transaction_type', ['charge', Status::TRANSACTION_TYPE_DISPUTE, 'subscription'])
            ->selectRaw("mt.order_id, MAX({$method}) AS method")
            ->groupBy('mt.order_id');

        $rows = [];
        $orders = 0;
        $recordedOrders = 0;
        $recordedNet = 0;
        foreach ($this->applyFilters(ReportHelper::joinShippingRefund(ReportCurrency::ordersTable($params), $params), $params)
            ->leftJoinSub($recorded, 'pm', 'pm.order_id', '=', 'o.id')
            ->selectRaw("COALESCE(pm.method, '') AS method_key, COUNT(*) AS orders, SUM(" . ReportHelper::netSalesSql($params) . ') AS net')
            ->groupBy('method_key')
            ->get() as $row) {
            $orders += (int) $row->orders;
            if ($row->method_key === '') {
                continue;
            }
            $recordedOrders += (int) $row->orders;
            $recordedNet += (int) $row->net;
            $rows[] = ['method' => $row->method_key, 'label' => $this->methodLabel($row->method_key),
                'orders' => (int) $row->orders, 'net' => (int) $row->net];
        }

        foreach ($rows as &$row) {
            $row['net_share'] = $recordedNet ? round($row['net'] * 100 / $recordedNet, 2) : 0;
            $row['net_sales'] = round($row['net'] / 100, 2);
            unset($row['net']);
        }
        unset($row);
        usort($rows, function ($a, $b) {
            return $b['net_sales'] <=> $a['net_sales'] ?: strcmp($a['method'], $b['method']);
        });

        return ['rows' => $rows, 'orders' => $recordedOrders, 'coverage' => $orders ? round($recordedOrders / $orders * 100, 2) : 0];
    }

    private function methodLabel(string $key): string
    {
        $labels = [
            'visa' => 'Visa', 'mastercard' => 'Mastercard', 'amex' => 'American Express', 'american_express' => 'American Express',
            'discover' => 'Discover', 'jcb' => 'JCB', 'diners' => 'Diners Club', 'unionpay' => 'UnionPay',
            'cartes_bancaires' => 'Cartes Bancaires', 'eftpos_au' => 'eftpos', 'rupay' => 'RuPay', 'link' => 'Link',
            'cashapp' => 'Cash App Pay', 'apple_pay' => 'Apple Pay', 'google_pay' => 'Google Pay', 'amazon_pay' => 'Amazon Pay',
            'paypal' => 'PayPal', 'afterpay_clearpay' => 'Afterpay / Clearpay', 'ideal' => 'iDEAL', 'p24' => 'Przelewy24',
            'blik' => 'BLIK', 'eps' => 'EPS', 'oxxo' => 'OXXO', 'pix' => 'Pix', 'wechat_pay' => 'WeChat Pay',
            'card'            => __('Card, brand not recorded', 'fluent-cart'),
            'us_bank_account' => __('US bank account', 'fluent-cart'),
            'sepa_debit'      => __('SEPA Direct Debit', 'fluent-cart'),
            'bacs_debit'      => __('Bacs Direct Debit', 'fluent-cart'),
            'au_becs_debit'   => __('BECS Direct Debit', 'fluent-cart'),
            'acss_debit'      => __('Pre-authorized debit', 'fluent-cart'),
        ];

        return $labels[$key] ?? ucwords(str_replace('_', ' ', $key));
    }

    private function transactionsByGateway(array $params, string $type, string $status)
    {
        $converted = ReportCurrency::isConverted($params);
        $amount = $converted ? 'ROUND(t.total / NULLIF(t.rate, 0))' : 't.total';
        [$trusted, $trustedBindings] = BaseAmountSql::trustedRate('o.');

        return App::db()->table('fct_order_transactions as t')
            ->join('fct_orders as o', 'o.id', '=', 't.order_id')
            // Converted: only orders with a trusted rate, as the order rows of this report
            ->when($converted, fn($q) => $q->whereRaw("{$trusted} = 1", $trustedBindings))
            ->selectRaw("t.payment_method AS gateway, COUNT(*) AS transactions, SUM({$amount}) AS amount")
            ->where('t.transaction_type', $type)
            ->where('t.status', $status)
            ->whereBetween('t.created_at', [$params['startDate'], $params['endDate']])
            ->when(!empty($params['currency']) && $params['currency'] !== ReportCurrency::ALL, fn($q) =>
                $q->where('o.currency', $params['currency'])
            )
            // The Order Type filter, as on the order rows
            ->when(!empty($params['orderTypes']), fn($q) => $q->whereRaw(ReportHelper::saleTypeSql() . ' IN (' . implode(', ', array_fill(0, count((array) $params['orderTypes']), '?')) . ')', array_values((array) $params['orderTypes'])))
            ->groupBy('t.payment_method')
            ->get();
    }

    /**
     * Open-ended subscriptions failing now, by the payment method they renew with: T6's at-risk rule.
     * Not dated: a subscription records no time it started failing.
     */
    private function failingNow(array $params)
    {
        $query = ReportCurrency::subscriptionsTable($params, 'fs')
            ->selectRaw('fs.current_payment_method AS gateway, COUNT(*) AS subscriptions,
                SUM(fs.recurring_total * ' . SubscriptionReportService::cyclesPerYearSql('fs.') . ') AS mrr_x12')
            ->where('fs.bill_times', '<=', 0)
            ->whereIn('fs.status', [Status::SUBSCRIPTION_FAILING, Status::SUBSCRIPTION_PAST_DUE])
            ->groupBy('fs.current_payment_method');

        return ReportCurrency::whereCurrencyColumn($query, 'fs.currency', ReportCurrency::resolve(Arr::get($params, 'currency'), true))
            ->get();
    }

    private function label(string $key): string
    {
        if ($key === self::SAVED_CARD) {
            return __('Saved card', 'fluent-cart');
        }
        if ($key === 'unknown') {
            return __('Unknown', 'fluent-cart');
        }
        $gateway = GatewayManager::gateway($key);
        if ($gateway) {
            $meta = $gateway->meta();

            return (string) (Arr::get($meta, 'admin_title') ?: Arr::get($meta, 'title') ?: $key);
        }

        // A gateway no longer registered (an imported or removed one): readable, not the raw key
        return ucwords(str_replace(['_', '-'], ' ', $key));
    }

    private function blank(): array
    {
        return array_fill_keys(['orders', 'refunded_orders', 'gross', 'refunds', 'net', 'renewal_net', 'disputed_orders', 'failed_disputed', 'failed_orders', 'failed_charges',
            'failed_charge_amount', 'disputes_lost', 'dispute_amount', 'failing_subscriptions', 'mrr_at_risk_x12'], 0);
    }

    private function finish(array $m): array
    {
        return [
            'orders'                => $m['orders'],
            'refunded_orders'       => $m['refunded_orders'],
            'gross_sales'           => round($m['gross'] / 100, 2),
            'refunds'               => round($m['refunds'] / 100, 2),
            'net_sales'             => round($m['net'] / 100, 2),
            'renewal_net'           => round($m['renewal_net'] / 100, 2),
            'refund_rate'           => $m['orders'] ? round($m['refunded_orders'] / $m['orders'] * 100, 2) : 0,
            'average_order'         => $m['orders'] ? round($m['gross'] / $m['orders'] / 100, 2) : 0,
            'failed_orders'         => $m['failed_orders'],
            // Paid orders against recorded declines, like a gateway's authorization rate: a declined checkout
            // leaves its order pending, so failed orders would miss most declines
            'success_rate'          => ($m['orders'] + $m['failed_charges']) ? round($m['orders'] / ($m['orders'] + $m['failed_charges']) * 100, 2) : 0,
            'disputed_orders'       => $m['disputed_orders'],
            'dispute_rate'          => ($m['orders'] + $m['failed_disputed'])
                ? round($m['disputed_orders'] / ($m['orders'] + $m['failed_disputed']) * 100, 2) : 0,
            'failed_charges'        => $m['failed_charges'],
            'failed_charge_amount'  => round($m['failed_charge_amount'] / 100, 2),
            'disputes_lost'         => $m['disputes_lost'],
            'dispute_amount'        => round($m['dispute_amount'] / 100, 2),
            'failing_subscriptions' => $m['failing_subscriptions'],
            'mrr_at_risk'           => SubscriptionReportService::mrrFromTimes12($m['mrr_at_risk_x12']),
        ];
    }
}

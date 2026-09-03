<?php

namespace FluentCart\App\Modules\MCP\Tools;

use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\MCP\Support\MCPHelper;
use FluentCart\App\Modules\MCP\Support\PermissionGate;

/**
 * The payment ledger — every charge, refund, dispute and signup fee across all
 * orders and subscriptions.
 *
 * This is the one surface the per-order / per-subscription views cannot answer:
 * questions that cut ACROSS records — "all refunds last week", "failed renewal
 * charges this month" (dunning triage), "this customer's payment history".
 * Before this tool an agent had to fetch every order and stitch transactions by
 * hand.
 *
 * Design rules (shared with ReportTools):
 *  - Date window resolution is delegated to ReportTools::resolveRange, so the
 *    range vocabulary (today … since_launch, date_from/date_to, since) and UTC
 *    semantics are identical to every report.
 *  - Money is NEVER summed across currencies: summary.amount_by_currency reports
 *    one total per currency, and each row carries its own currency.
 *  - Rows are compact (moneyCompact + explicit per-row currency); summary_only
 *    returns just the aggregates for a tiny payload.
 */
class TransactionTools
{
    // transaction_type values (Status::TRANSACTION_TYPE_*).
    const TYPES = ['charge', 'refund', 'dispute', 'signup_fee'];

    // status values (Status::TRANSACTION_*).
    const STATUSES = ['succeeded', 'authorized', 'pending', 'refunded', 'failed', 'dispute_lost'];

    const DEFAULT_PER_PAGE = 25;

    public static function definitions()
    {
        return [
            'fluent-cart/list-transactions' => [
                'label'       => __('List Transactions', 'fluent-cart'),
                'description' => __('The payment ledger across every order and subscription — charges, refunds, disputes, signup fees. Filter by type, status, order_id, subscription_id, customer_id, payment_method, currency, mode or a date window, then paginate. Answers cross-record questions the per-order and per-subscription views cannot: "all refunds last week", failed renewal charges for dunning, one customer\'s payment history. Money is summed PER currency in summary.amount_by_currency, never across. summary_only:true returns just the counts and totals with no rows.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'type'            => ['type' => 'string', 'enum' => self::TYPES, 'description' => 'charge = a payment taken; refund = money returned; dispute = chargeback; signup_fee = subscription signup fee.'],
                        'status'          => ['type' => 'string', 'enum' => self::STATUSES, 'description' => 'succeeded, failed = use for dunning / failed renewals, pending, authorized, refunded, dispute_lost.'],
                        'order_id'        => ['type' => 'integer', 'description' => 'Transactions for one order.'],
                        'subscription_id' => ['type' => 'integer', 'description' => 'Transactions for one subscription — its renewal charges, signup fee, refunds.'],
                        'customer_id'     => ['type' => 'integer', 'description' => 'All transactions for one customer, matched via the parent order.'],
                        'payment_method'  => ['type' => 'string', 'description' => 'Gateway slug, e.g. stripe, paypal, mollie.'],
                        'currency'        => ['type' => 'string', 'description' => 'ISO currency filter. Omit to include all currencies; totals stay split per currency.'],
                        'mode'            => ['type' => 'string', 'enum' => ['live', 'test', 'all'], 'default' => 'all', 'description' => 'Payment mode. all (default) includes both; pass live to exclude test transactions. Echoed as meta.mode.'],
                        'range'           => ['type' => 'string', 'enum' => ReportTools::RANGES, 'description' => 'Relative date window on created_at, resolved in UTC using the same range vocabulary as the reports. Defaults to last_30_days. Or pass date_from/date_to, or since.'],
                        'date_from'       => ['type' => 'string', 'description' => 'ISO 8601 datetime or YYYY-MM-DD, UTC. Window start; overrides range.'],
                        'date_to'         => ['type' => 'string', 'description' => 'ISO 8601 datetime or YYYY-MM-DD, UTC. Window end; overrides range.'],
                        'since'           => ['type' => 'string', 'description' => 'ISO 8601 datetime, UTC. Only transactions after this instant — "what settled since my last check".'],
                        'summary_only'    => ['type' => 'boolean', 'description' => 'Return ONLY the aggregate summary — matching_count, per-type and per-status counts, amount_by_currency — with no per-transaction rows. Answers "how much did we refund" cheaply.'],
                        'page'            => ['type' => 'integer', 'default' => 1],
                        'per_page'        => ['type' => 'integer', 'default' => 25, 'description' => 'Max 100.'],
                        'sort_by'         => ['type' => 'string', 'enum' => ['created_at', 'total', 'id'], 'default' => 'created_at', 'description' => 'created_at is newest-first by default; total ranks by amount.'],
                        'sort_type'       => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'default' => 'DESC'],
                    ],
                ],
                'output_schema' => MCPHelper::envelopeSchema([
                    'type'       => 'object',
                    'properties' => [
                        'transactions' => [
                            'type'        => 'array',
                            'description' => 'One page of matching transactions (absent when summary_only is set).',
                            'items'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'id'               => ['type' => 'integer'],
                                    'order_id'         => ['type' => 'integer'],
                                    'subscription_id'  => ['description' => 'Subscription id, or null for one-off charges.'],
                                    'type'             => ['type' => 'string', 'enum' => self::TYPES],
                                    'status'           => ['type' => 'string'],
                                    'payment_method'   => ['type' => 'string'],
                                    'mode'             => ['type' => 'string', 'description' => 'live or test.'],
                                    'amount'           => ['type' => 'number', 'description' => 'Compact decimal in the row currency.'],
                                    'currency'         => ['type' => 'string', 'description' => 'ISO 4217; per-row, since a result can span currencies.'],
                                    'card_brand'       => ['description' => 'Card brand, or null.'],
                                    'card_last_4'      => ['description' => 'Last 4 digits, or null.'],
                                    'vendor_charge_id' => ['type' => 'string'],
                                    'customer'         => ['description' => 'id, name, email resolved via the parent order; null if unresolved.'],
                                    'created_at'       => ['description' => 'ISO-8601 UTC, or null.'],
                                ],
                            ],
                        ],
                        'summary' => [
                            'type'       => 'object',
                            'properties' => [
                                'matching_count'     => ['type' => 'integer'],
                                'by_type'            => ['type' => 'object', 'description' => 'Count keyed by transaction type.'],
                                'by_status'          => ['type' => 'object', 'description' => 'Count keyed by status.'],
                                'amount_by_currency' => [
                                    'type'        => 'array',
                                    'description' => 'One total per currency — money is never summed across currencies.',
                                    'items'       => [
                                        'type'       => 'object',
                                        'properties' => [
                                            'currency' => ['type' => 'string'],
                                            'count'    => ['type' => 'integer'],
                                            'total'    => MCPHelper::moneyDef(),
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], ['date_basis' => ['type' => 'string'], 'mode' => ['type' => 'string'], 'page' => ['type' => 'object']]),
                'execute_callback'    => [self::class, 'listTransactions'],
                'permission_callback' => function () {
                    return PermissionGate::can('orders/view');
                },
                'annotations' => ['readonly' => true],
            ],
        ];
    }

    public static function listTransactions($params = [])
    {
        // Same UTC window + range vocabulary as every report.
        $range = ReportTools::resolveRange($params);
        $mode  = self::mode($params);

        $base = OrderTransaction::query()
            ->where('created_at', '>=', $range['start'])
            ->where('created_at', '<=', $range['end']);

        self::applyFilters($base, $params, $mode);

        // Summary first, off a clone, so it reflects the whole filtered set — not
        // just the current page. Money stays split per currency.
        $summary = self::summarize(clone $base);

        $meta = [
            'date_basis' => 'created_at',
            'mode'       => $mode,
            'range'      => [
                'start' => MCPHelper::toIso8601($range['start']),
                'end'   => MCPHelper::toIso8601($range['end']),
                'label' => $range['label'],
            ],
        ];

        if (!empty($params['summary_only'])) {
            return MCPHelper::envelope(self::summaryLine($summary), ['summary' => $summary], $meta);
        }

        $paging   = MCPHelper::pagination($params, self::DEFAULT_PER_PAGE);
        $sortBy   = in_array(isset($params['sort_by']) ? $params['sort_by'] : '', ['created_at', 'total', 'id'], true) ? $params['sort_by'] : 'created_at';
        $sortType = strtoupper(isset($params['sort_type']) ? $params['sort_type'] : 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        // Deterministic total order: tie-break on id so identical calls never
        // reshuffle rows across pages.
        $base->orderBy($sortBy, $sortType);
        if ($sortBy !== 'id') {
            $base->orderBy('id', 'DESC');
        }

        // Eager-load a trimmed parent order + its customer so each row can name
        // the customer without an N+1 per transaction.
        $base->with([
            'order' => function ($q) {
                $q->select(['id', 'customer_id', 'currency']);
            },
            'order.customer' => function ($q) {
                $q->select(['id', 'first_name', 'last_name', 'email']);
            },
        ]);

        $paginator = $base->paginate($paging['per_page'], ['*'], 'page', $paging['page']);

        $rows = [];
        foreach (MCPHelper::paginatorItems($paginator) as $txn) {
            $rows[] = self::formatRow($txn);
        }

        return MCPHelper::envelope(
            self::summaryLine($summary),
            ['transactions' => $rows, 'summary' => $summary],
            array_merge($meta, MCPHelper::pagingMeta($paginator))
        );
    }

    /**
     * Live/test/all — filters the payment_mode column. 'all' (default) is a no-op
     * so both are included, matching the report tools' mode convention.
     */
    private static function mode($params)
    {
        $m = isset($params['mode']) ? strtolower(sanitize_text_field((string) $params['mode'])) : 'all';
        return in_array($m, ['live', 'test'], true) ? $m : 'all';
    }

    private static function applyFilters($query, $params, $mode)
    {
        if (!empty($params['type']) && in_array($params['type'], self::TYPES, true)) {
            $query->where('transaction_type', $params['type']);
        }
        if (!empty($params['status']) && in_array($params['status'], self::STATUSES, true)) {
            $query->where('status', $params['status']);
        }
        if (!empty($params['order_id'])) {
            $query->where('order_id', (int) $params['order_id']);
        }
        if (!empty($params['subscription_id'])) {
            $query->where('subscription_id', (int) $params['subscription_id']);
        }
        if (!empty($params['payment_method'])) {
            $query->where('payment_method', sanitize_text_field($params['payment_method']));
        }
        if (!empty($params['currency'])) {
            $query->where('currency', strtoupper(sanitize_text_field($params['currency'])));
        }
        if ($mode !== 'all') {
            $query->where('payment_mode', $mode);
        }

        // customer_id lives on the parent order, not the transaction — scope via a
        // whereHas subquery (never a join, so the per-currency SUMs can't fan out).
        if (!empty($params['customer_id'])) {
            $cid = (int) $params['customer_id'];
            $query->whereHas('order', function ($q) use ($cid) {
                $q->where('customer_id', $cid);
            });
        }
    }

    /**
     * Aggregate the filtered set WITHOUT crossing currencies. Counts are
     * currency-agnostic (by_type, by_status); money is one total per currency.
     */
    private static function summarize($query)
    {
        $byCurrency = (clone $query)
            ->selectRaw('currency, COUNT(*) as cnt, COALESCE(SUM(total), 0) as total')
            ->groupBy('currency')
            ->get();

        $amountByCurrency = [];
        $count            = 0;
        foreach ($byCurrency as $row) {
            $cnt    = (int) $row->cnt;
            $count += $cnt;
            $code   = $row->currency ? strtoupper($row->currency) : MCPHelper::currencyCode();
            $amountByCurrency[] = [
                'currency' => $code,
                'count'    => $cnt,
                'total'    => MCPHelper::money((int) $row->total, $code),
            ];
        }

        return [
            'matching_count'     => $count,
            // Cast to object: the output_schema types these as 'object', but a
            // dynamically-built PHP map is an empty array [] when nothing matches,
            // which json_encodes to [] and fails structured-output validation.
            // (object) forces {} when empty and a JSON object otherwise.
            'by_type'            => (object) self::countBy((clone $query), 'transaction_type'),
            'by_status'          => (object) self::countBy((clone $query), 'status'),
            'amount_by_currency' => $amountByCurrency,
            'note'               => 'amount_by_currency sums transaction total within each currency; charges and refunds are both stored as positive amounts, so filter by type for a single-sided figure.',
        ];
    }

    /** COUNT(*) grouped by one column, as a { value: count } map. */
    private static function countBy($query, $column)
    {
        $rows = $query->selectRaw($column . ', COUNT(*) as cnt')->groupBy($column)->get();
        $out  = [];
        foreach ($rows as $row) {
            $key = $row->{$column};
            if ($key === null || $key === '') {
                $key = 'unknown';
            }
            $out[$key] = (int) $row->cnt;
        }
        return $out;
    }

    private static function formatRow($txn)
    {
        $currency = $txn->currency ? strtoupper($txn->currency) : MCPHelper::currencyCode();

        $customer = null;
        $order    = $txn->relationLoaded('order') ? $txn->order : null;
        if ($order && $order->relationLoaded('customer') && $order->customer) {
            $customer = [
                'id'    => (int) $order->customer->id,
                'name'  => MCPHelper::personName($order->customer),
                'email' => $order->customer->email,
            ];
        }

        return [
            'id'               => (int) $txn->id,
            'order_id'         => (int) $txn->order_id,
            'subscription_id'  => $txn->subscription_id ? (int) $txn->subscription_id : null,
            'type'             => $txn->transaction_type,
            'status'           => $txn->status,
            'payment_method'   => $txn->payment_method,
            'mode'             => $txn->payment_mode,
            'amount'           => MCPHelper::moneyCompact($txn->total),
            'currency'         => $currency,
            'card_brand'       => $txn->card_brand,
            'card_last_4'      => $txn->card_last_4,
            'vendor_charge_id' => $txn->vendor_charge_id,
            'customer'         => $customer,
            'created_at'       => MCPHelper::toIso8601($txn->created_at),
        ];
    }

    private static function summaryLine($summary)
    {
        $parts = [];
        foreach ($summary['amount_by_currency'] as $c) {
            $parts[] = $c['total']['display'];
        }
        $money = $parts ? implode(', ', $parts) : MCPHelper::displayAmount(0);

        return sprintf(
            /* translators: 1: transaction count, 2: total amount per currency */
            _n('%1$d transaction totaling %2$s.', '%1$d transactions totaling %2$s.', (int) $summary['matching_count'], 'fluent-cart'),
            (int) $summary['matching_count'],
            $money
        );
    }
}

<?php

namespace FluentCart\App\Modules\MCP\Tools;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\MCP\Support\AdvancedSearch;
use FluentCart\App\Modules\MCP\Support\MCPHelper;
use FluentCart\App\Modules\MCP\Support\PermissionGate;
use FluentCart\App\Modules\MCP\Support\WriteGuard;

/**
 * Subscription tools — find recurring plans, then load one fully.
 *
 * Parameter design:
 *  - list-subscriptions filters on what owners triage by: status, the next
 *    billing window (to find upcoming renewals), interval, customer/product.
 *  - next_billing_before is the key MRR/churn-prevention lever — "what renews
 *    in the next 7 days?" — so it's a first-class filter, not buried.
 *  - get-subscription is lean by default; renewal transactions and labels are
 *    opt-in via include[].
 *  - Money (recurring_total) uses the subscription's own currency.
 */
class SubscriptionTools
{
    public static function definitions()
    {
        $enums     = ContextTools::enums();
        $statuses  = $enums['subscription_statuses'];
        $intervals = $enums['billing_intervals'];

        return [
            'fluent-cart/list-subscriptions' => [
                'label'       => __('List Subscriptions', 'fluent-cart'),
                'description' => __('Find and filter subscriptions. Compact rows carry customer, plan, status, recurring_total, interval, next/created/canceled dates, and installment fields: is_installment, installments_paid, installments_remaining, total_contract_value = recurring_total x bill_times (the full committed price). Use plan_type to split fixed-term installment/split-pay from open-ended recurring plans; next_billing_before for upcoming renewals; created_*/canceled_* ranges for cohorts and churn — a completed installment is paid-in-full, not churn. summary_only=true returns just the aggregates (count by status, committed recurring total, remaining installments). min_recurring is in store currency, not cents. For conditions these flat filters cannot express (OR groups, vendor/transaction ids, payment method, license properties, labels) pass advanced_filters — call get-search-schema entity=subscriptions first (Pro).', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status'              => ['type' => 'string', 'enum' => $statuses],
                        'plan_type'           => ['type' => 'string', 'enum' => ['installment', 'recurring', 'all'], 'default' => 'all', 'description' => 'installment = fixed-term split-pay such as a lifetime license paid in N installments where bill_times > 0; recurring = open-ended subscription where bill_times = 0; all = both.'],
                        'customer_id'         => ['type' => 'integer'],
                        'product_id'          => ['type' => 'integer'],
                        'billing_interval'    => ['type' => 'string', 'enum' => $intervals],
                        'next_billing_after'  => ['type' => 'string', 'description' => 'YYYY-MM-DD or ISO 8601, UTC.'],
                        'next_billing_before' => ['type' => 'string', 'description' => 'YYYY-MM-DD or ISO 8601, UTC. Use to find upcoming renewals.'],
                        'created_after'       => ['type' => 'string', 'description' => 'YYYY-MM-DD or ISO 8601, UTC. Subscriptions started on or after this date.'],
                        'created_before'      => ['type' => 'string', 'description' => 'YYYY-MM-DD or ISO 8601, UTC. Subscriptions started on or before this date.'],
                        'canceled_after'      => ['type' => 'string', 'description' => 'YYYY-MM-DD or ISO 8601, UTC. Subscriptions canceled on or after this date. Pair with status=canceled to measure churn in a window.'],
                        'canceled_before'     => ['type' => 'string', 'description' => 'YYYY-MM-DD or ISO 8601, UTC. Subscriptions canceled on or before this date.'],
                        'min_recurring'       => ['type' => 'number', 'description' => 'Minimum recurring total in store currency.'],
                        'advanced_filters'    => ['type' => 'array', 'items' => ['type' => ['object', 'array']], 'description' => 'Pro: condition groups {property, operator, value} — outer array = OR groups, inner = AND. Call get-search-schema entity=subscriptions FIRST for properties/operators/format. AND-combines with the other filters here (summary_only included). An empty array means no advanced filter.'],
                        'sort_by'             => ['type' => 'string', 'enum' => ['id', 'next_billing_date', 'created_at', 'canceled_at', 'recurring_total'], 'default' => 'id'],
                        'sort_type'           => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'default' => 'DESC'],
                        'fields'              => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional: return only these row keys to shrink the payload (subscription_id is always kept). Available: status, item_name, customer, recurring_total, billing_interval, next_billing_date, created_at, canceled_at, bill_count, bill_times, plan_type, is_installment, installments_paid, installments_remaining, total_contract_value, currency, label. Omit for the full row.'],
                        'summary_only'        => ['type' => 'boolean', 'description' => 'When true, return ONLY aggregates across all matching subscriptions — count_by_status, summed recurring_total, and total remaining installments — with no per-record array. Answers "how many active subs and how much is committed" with a tiny payload. Honors all the filters above.'],
                        'page'                => ['type' => 'integer', 'default' => 1],
                        'per_page'            => ['type' => 'integer', 'default' => 15, 'description' => 'Max 200.'],
                    ],
                ],
                'execute_callback'    => [self::class, 'listSubscriptions'],
                'permission_callback' => function () {
                    return PermissionGate::can('subscriptions/view');
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/get-subscription' => [
                'label'       => __('Get Subscription', 'fluent-cart'),
                'description' => __('Full detail for one subscription: lifecycle dates, billing schedule, signup/trial, parent order, gateway ids. Add include[] for transactions (renewal history) and labels. Identify by subscription_id.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'subscription_id' => ['type' => 'integer'],
                        'include'         => [
                            'type'  => 'array',
                            'items' => ['type' => 'string', 'enum' => ['transactions', 'labels']],
                        ],
                    ],
                    'required' => ['subscription_id'],
                ],
                'execute_callback'    => [self::class, 'getSubscription'],
                'permission_callback' => function () {
                    return PermissionGate::can('subscriptions/view');
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/change-subscription-status' => [
                'label'       => __('Change Subscription Status', 'fluent-cart'),
                'description' => __('Cancel a subscription through its gateway — destructive. Call dry_run:true first to preview and get a confirm_token, then call again with that confirm_token plus an idempotency_key to execute. Cancellation is immediate. The preview reports payment_mode and live_gateway_action; a LIVE cancellation requires operator opt-in, and test-mode always works.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'subscription_id' => ['type' => 'integer'],
                        'action'          => ['type' => 'string', 'enum' => ['cancel'], 'description' => 'Only cancel is supported. Pause/resume are not available.'],
                        'when'            => ['type' => 'string', 'enum' => ['immediately'], 'default' => 'immediately', 'description' => 'Cancellation is immediate. Deferred (period-end) cancellation is not yet supported.'],
                        'reason'          => ['type' => 'string'],
                        'dry_run'         => ['type' => 'boolean', 'description' => 'Preview without cancelling. Returns a confirm_token. Do this first.'],
                        'confirm_token'   => ['type' => 'string'],
                        'idempotency_key' => ['type' => 'string'],
                    ],
                    'required' => ['subscription_id', 'action'],
                ],
                'execute_callback'    => [self::class, 'changeSubscriptionStatus'],
                'permission_callback' => function () {
                    return PermissionGate::can('subscriptions/manage');
                },
                // Cancels via the gateway — destructive. readonly:false is explicit
                // so a client never mistakes it for a preview-only tool.
                'annotations' => ['readonly' => false, 'destructive' => true],
            ],
        ];
    }

    public static function listSubscriptions($params = [])
    {
        // advanced_filters routes through the admin filter engine (validated
        // first — a bad condition errors, never silently drops); the named
        // filters below then AND onto the same query either way, and the
        // summary_only branch aggregates over the same filtered set.
        $advWarnings = [];
        if (!empty($params['advanced_filters'])) {
            $built = AdvancedSearch::buildQuery('subscriptions', $params['advanced_filters']);
            if (is_wp_error($built)) {
                return $built;
            }
            $query       = $built['query'];
            $advWarnings = $built['warnings'];
        } else {
            $query = Subscription::query();
        }

        foreach (['status', 'billing_interval'] as $col) {
            if (!empty($params[$col])) {
                $query->where($col, sanitize_text_field($params[$col]));
            }
        }
        // Reuse the model's plan-type definition (bill_times threshold) so the
        // filter and the per-row is_installment flag can never disagree.
        $planType = self::allowed($params, 'plan_type', ['installment', 'recurring', 'all'], 'all');
        if ($planType !== 'all') {
            $query->ofPlanType($planType);
        }
        if (!empty($params['customer_id'])) {
            $query->where('customer_id', (int) $params['customer_id']);
        }
        if (!empty($params['product_id'])) {
            $query->where('product_id', (int) $params['product_id']);
        }
        $dateFilters = [
            'next_billing_after'  => ['next_billing_date', '>='],
            'next_billing_before' => ['next_billing_date', '<='],
            'created_after'       => ['created_at', '>='],
            'created_before'      => ['created_at', '<='],
            'canceled_after'      => ['canceled_at', '>='],
            'canceled_before'     => ['canceled_at', '<='],
        ];
        foreach ($dateFilters as $field => $spec) {
            if (empty($params[$field])) {
                continue;
            }
            $date = self::toDbDate($params[$field]);
            if ($date === null) {
                return self::invalidDateError($field);
            }
            $query->where($spec[0], $spec[1], $date);
        }
        if (isset($params['min_recurring'])) {
            $query->where('recurring_total', '>=', Helper::toCent($params['min_recurring']));
        }

        // Bonus: aggregate-only mode — counts + sums across ALL matching
        // subscriptions (not just one page), no per-record array. Respects every
        // filter applied above.
        if (!empty($params['summary_only'])) {
            return self::summaryResponse($query, $advWarnings);
        }

        $paging = MCPHelper::pagination($params, 15, 200);
        $query->with('customer');

        $sortBy   = self::allowed($params, 'sort_by', ['id', 'next_billing_date', 'created_at', 'canceled_at', 'recurring_total'], 'id');
        $sortType = strtoupper(isset($params['sort_type']) ? $params['sort_type'] : 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $query->orderBy($sortBy, $sortType);
        if ($sortBy !== 'id') {
            $query->orderBy('id', 'DESC');
        }

        $paginator = $query->paginate($paging['per_page'], ['*'], 'page', $paging['page']);
        $total     = self::total($paginator);

        $fields = isset($params['fields']) ? $params['fields'] : null;
        $rows   = [];
        foreach (MCPHelper::paginatorItems($paginator) as $sub) {
            $rows[] = MCPHelper::pickFields(self::formatRow($sub), $fields, ['subscription_id']);
        }

        $meta = MCPHelper::pagingMeta($paginator);
        if ($advWarnings) {
            $meta['warnings'] = $advWarnings;
        }

        return MCPHelper::envelope(
            sprintf(
                /* translators: %d: number of matching subscriptions */
                _n('%d subscription found.', '%d subscriptions found.', $total, 'fluent-cart'),
                $total
            ),
            ['subscriptions' => $rows],
            $meta
        );
    }

    /**
     * Aggregate-only response for summary_only: status counts, summed
     * recurring_total and total remaining installments across the full filtered
     * set. Two lightweight GROUP BY / SUM scans, no row hydration. Money is in the
     * store currency (subscriptions are not currency-scoped), matching formatRow.
     */
    private static function summaryResponse($query, array $advWarnings = [])
    {
        $byStatusRows = (clone $query)
            ->selectRaw('status, COUNT(*) as cnt, COALESCE(SUM(recurring_total), 0) as recurring_sum')
            ->groupBy('status')
            ->get();

        $byStatus     = [];
        $totalCount   = 0;
        $recurringSum = 0;
        foreach ($byStatusRows as $row) {
            $count = (int) $row->cnt;
            $sum   = (int) $row->recurring_sum;
            $byStatus[(string) $row->status] = [
                'count'               => $count,
                'recurring_total_sum' => MCPHelper::moneyCompact($sum),
            ];
            $totalCount   += $count;
            $recurringSum += $sum;
        }

        // Remaining installments across finite (bill_times > 0) plans only.
        $remRow = (clone $query)
            ->selectRaw('COALESCE(SUM(CASE WHEN bill_times > 0 THEN GREATEST(bill_times - bill_count, 0) ELSE 0 END), 0) as rem')
            ->first();
        $remaining = $remRow ? (int) $remRow->rem : 0;

        $summary = sprintf(
            /* translators: 1: subscription count, 2: summed recurring total */
            __('%1$d subscriptions; committed recurring total %2$s.', 'fluent-cart'),
            $totalCount,
            MCPHelper::displayAmount($recurringSum, MCPHelper::currencyCode())
        );

        $meta = ['currency' => MCPHelper::currencyCode(), 'note' => 'Aggregates across all matching subscriptions; money is in the store currency, not currency-scoped.'];
        if ($advWarnings) {
            $meta['warnings'] = $advWarnings;
        }

        return MCPHelper::envelope(
            $summary,
            [
                'summary_only'                  => true,
                'total_count'                   => $totalCount,
                'recurring_total_sum'           => MCPHelper::moneyCompact($recurringSum),
                'remaining_installments_total'  => $remaining,
                'count_by_status'               => $byStatus,
            ],
            $meta
        );
    }

    private static function formatRow($sub)
    {
        $customer = ($sub->relationLoaded('customer') && $sub->customer) ? $sub->customer : null;
        $currency = strtoupper((string) $sub->currency);

        $isInstallment = $sub->isInstallment();

        return [
            'subscription_id'        => (int) $sub->id,
            'label'                  => self::label($sub, $customer),
            'status'                 => $sub->status,
            'item_name'              => $sub->item_name,
            'customer'               => $customer ? ['id' => (int) $customer->id, 'name' => MCPHelper::personName($customer), 'email' => $customer->email] : null,
            'recurring_total'        => MCPHelper::moneyCompact($sub->recurring_total),
            'billing_interval'       => $sub->billing_interval,
            'next_billing_date'      => MCPHelper::toIso8601($sub->next_billing_date),
            'created_at'             => MCPHelper::toIso8601($sub->created_at),
            'canceled_at'            => MCPHelper::toIso8601($sub->canceled_at),
            'bill_count'             => (int) $sub->bill_count,
            'bill_times'             => (int) $sub->bill_times,
            // Derived installment view (bill_times > 0). total_contract_value is
            // null for open-ended plans, which have no fixed committed total.
            // plan_type names the same distinction using the plan_types enum from
            // get-store-context, so the enum, the filter and the row all agree.
            'plan_type'              => $isInstallment ? 'installment' : 'recurring',
            'is_installment'         => $isInstallment,
            'installments_paid'      => (int) $sub->bill_count,
            'installments_remaining' => $sub->installmentsRemaining(),
            'total_contract_value'   => $isInstallment ? MCPHelper::money($sub->totalContractValue(), $currency) : null,
            'currency'               => $currency,
        ];
    }

    private static function label($sub, $customer)
    {
        $who = $customer ? MCPHelper::personName($customer) : __('Customer', 'fluent-cart');

        return sprintf(
            /* translators: 1: plan name, 2: customer name, 3: status */
            __('%1$s for %2$s — %3$s', 'fluent-cart'),
            $sub->item_name,
            $who,
            $sub->status
        );
    }

    public static function getSubscription($params = [])
    {
        if (empty($params['subscription_id'])) {
            return MCPHelper::error('missing_identifier', __('subscription_id is required.', 'fluent-cart'));
        }

        $sub = Subscription::query()
            ->where('id', (int) $params['subscription_id'])
            ->with('customer')
            ->first();

        if (!$sub) {
            return MCPHelper::error('subscription_not_found', __('No subscription found for the given subscription_id.', 'fluent-cart'));
        }

        $include  = isset($params['include']) ? (array) $params['include'] : [];
        $currency = strtoupper((string) $sub->currency);

        $data = [
            'subscription_id'      => (int) $sub->id,
            'uuid'                 => $sub->uuid,
            'status'               => $sub->status,
            'item_name'            => $sub->item_name,
            'customer'             => $sub->customer ? ['id' => (int) $sub->customer->id, 'name' => MCPHelper::personName($sub->customer), 'email' => $sub->customer->email] : null,
            'parent_order_id'      => $sub->parent_order_id ? (int) $sub->parent_order_id : null,
            'product_id'           => $sub->product_id ? (int) $sub->product_id : null,
            'variation_id'         => $sub->variation_id ? (int) $sub->variation_id : null,
            'quantity'             => (int) $sub->quantity,
            'billing'              => [
                'interval'         => $sub->billing_interval,
                'signup_fee'       => MCPHelper::money($sub->signup_fee, $currency),
                'recurring_amount' => MCPHelper::money($sub->recurring_amount, $currency),
                'recurring_total'  => MCPHelper::money($sub->recurring_total, $currency),
                'bill_times'       => (int) $sub->bill_times,
                'bill_count'       => (int) $sub->bill_count,
                'collection_method' => $sub->collection_method,
                'plan_type'              => $sub->isInstallment() ? 'installment' : 'recurring',
                'is_installment'         => $sub->isInstallment(),
                'installments_paid'      => (int) $sub->bill_count,
                'installments_remaining' => $sub->installmentsRemaining(),
                'total_contract_value'   => $sub->isInstallment() ? MCPHelper::money($sub->totalContractValue(), $currency) : null,
            ],
            'next_billing_date'    => MCPHelper::toIso8601($sub->next_billing_date),
            'trial_ends_at'        => MCPHelper::toIso8601($sub->trial_ends_at),
            'expire_at'            => MCPHelper::toIso8601($sub->expire_at),
            'canceled_at'          => MCPHelper::toIso8601($sub->canceled_at),
            'created_at'           => MCPHelper::toIso8601($sub->created_at),
            'currency'             => $currency,
        ];

        if (in_array('transactions', $include, true)) {
            $data['transactions'] = self::transactions($sub, $currency);
        }
        if (in_array('labels', $include, true)) {
            $data['labels'] = self::labels($sub);
        }

        return MCPHelper::envelope(self::label($sub, $sub->customer), $data);
    }

    private static function transactions($sub, $currency)
    {
        $sub->load('transactions');
        $out = [];
        if (!$sub->relationLoaded('transactions')) {
            return $out;
        }
        foreach ($sub->transactions as $txn) {
            $out[] = [
                'id'             => (int) $txn->id,
                'type'           => $txn->transaction_type,
                'status'         => $txn->status,
                'payment_method' => $txn->payment_method,
                'amount'         => MCPHelper::money($txn->total, $txn->currency ? $txn->currency : $currency),
                'created_at'     => MCPHelper::toIso8601($txn->created_at),
            ];
        }
        return $out;
    }

    private static function labels($sub)
    {
        $sub->load('labels');
        $out = [];
        if (!$sub->relationLoaded('labels')) {
            return $out;
        }
        foreach ($sub->labels as $label) {
            $val = $label->value;
            $out[] = ['id' => (int) $label->id, 'title' => is_array($val) ? (isset($val['title']) ? $val['title'] : null) : $val];
        }
        return $out;
    }

    // -----------------------------------------------------------------
    // change-subscription-status (write, destructive — dry_run + idempotency)
    // -----------------------------------------------------------------

    public static function changeSubscriptionStatus($params = [])
    {
        if (empty($params['subscription_id'])) {
            return MCPHelper::error('missing_identifier', __('subscription_id is required.', 'fluent-cart'));
        }
        $action = isset($params['action']) ? sanitize_text_field($params['action']) : '';
        if ($action !== 'cancel') {
            return MCPHelper::error('invalid_action', __('action must be cancel. Pause and resume are not supported.', 'fluent-cart'));
        }

        $sub = Subscription::query()->where('id', (int) $params['subscription_id'])->first();
        if (!$sub) {
            return MCPHelper::error('subscription_not_found', __('No subscription found for the given subscription_id.', 'fluent-cart'));
        }
        if (in_array($sub->status, ['canceled', 'cancelled', 'expired'], true)) {
            return MCPHelper::error(
                'already_ended',
                sprintf(
                    /* translators: %1$s: current subscription status */
                    __('Subscription is already in status %1$s.', 'fluent-cart'),
                    $sub->status
                )
            );
        }

        // Cancellation is always immediate: core marks the subscription canceled
        // on save regardless of effective_from, so we never advertise deferral.
        $when = 'immediately';

        // Gateway mode from the most recent transaction on this subscription.
        $modeTxn     = $sub->transactions()->orderBy('id', 'DESC')->first();
        $paymentMode = $modeTxn ? $modeTxn->payment_mode : '';

        $tool        = 'fluent-cart/change-subscription-status';
        $entityKey   = 'subscription:' . $sub->id;
        // Bind the previewed timing into the fingerprint so a token minted for
        // one `when` can't confirm a different one.
        $fingerprint = 'status:' . $sub->status . '|when:' . $when;

        if (!empty($params['dry_run'])) {
            return MCPHelper::envelope(
                sprintf(
                    /* translators: 1: subscription id, 2: plan name, 3: when */
                    __('Preview: cancel subscription #%1$d %2$s, effective %3$s.', 'fluent-cart'),
                    (int) $sub->id,
                    $sub->item_name,
                    $when
                ),
                WriteGuard::preview($tool, $entityKey, $fingerprint, [
                    'subscription_id'     => (int) $sub->id,
                    'current_status'      => $sub->status,
                    'action'              => 'cancel',
                    'effective'           => $when,
                    'payment_mode'        => $paymentMode,
                    'live_gateway_action' => WriteGuard::isLiveMode($paymentMode),
                ])
            );
        }

        $confirm = WriteGuard::confirm($tool, $entityKey, $fingerprint, isset($params['confirm_token']) ? $params['confirm_token'] : '');
        if (is_wp_error($confirm)) {
            return $confirm;
        }

        // Real-money guard: a live cancellation needs explicit opt-in (test always OK).
        $liveGate = WriteGuard::liveGatewayAllowed($paymentMode);
        if (is_wp_error($liveGate)) {
            return $liveGate;
        }

        $reason  = isset($params['reason']) ? sanitize_text_field($params['reason']) : 'Canceled via AI assistant';
        $idemKey = isset($params['idempotency_key']) ? (string) $params['idempotency_key'] : '';

        $result = WriteGuard::idempotent($tool, $entityKey, $idemKey, function () use ($sub, $reason, $when) {
            return $sub->cancelRemoteSubscription([
                'reason'         => $reason,
                'effective_from' => $when === 'immediately' ? 'immediately' : '',
            ]);
        });

        if (is_wp_error($result)) {
            return $result;
        }

        $sub = Subscription::query()->where('id', (int) $params['subscription_id'])->first();

        return MCPHelper::envelope(
            sprintf(
                /* translators: 1: subscription id, 2: when */
                __('Subscription #%1$d canceled, effective %2$s.', 'fluent-cart'),
                (int) $sub->id,
                $when
            ),
            ['subscription_id' => (int) $sub->id, 'status' => $sub->status, 'canceled_at' => MCPHelper::toIso8601($sub->canceled_at)]
        );
    }

    private static function allowed($params, $key, array $allowed, $default)
    {
        $val = isset($params[$key]) ? $params[$key] : $default;
        return in_array($val, $allowed, true) ? $val : $default;
    }

    private static function total($paginator)
    {
        return MCPHelper::paginatorTotal($paginator);
    }

    private static function toDbDate($value)
    {
        try {
            return (new \DateTime((string) $value, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            // Return null so callers reject the input. An epoch fallback would
            // silently turn a typo'd date bound into an unbounded "match all".
            return null;
        }
    }

    private static function invalidDateError($field)
    {
        return MCPHelper::error(
            'invalid_date',
            sprintf(
                /* translators: 1: field name */
                __('%1$s is not a valid date. Use YYYY-MM-DD or ISO 8601.', 'fluent-cart'),
                $field
            ),
            ['fields' => [$field]]
        );
    }
}

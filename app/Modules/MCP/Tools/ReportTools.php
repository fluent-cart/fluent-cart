<?php

namespace FluentCart\App\Modules\MCP\Tools;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\App;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\MCP\Support\MCPHelper;
use FluentCart\App\Modules\MCP\Support\PermissionGate;

/**
 * Reports & analytics — the headline research surface.
 *
 * Design rules baked in:
 *  - Every report is CURRENCY-SCOPED: it filters to one currency (the store
 *    default unless `currency` is passed) so totals are never silently summed
 *    across currencies. The chosen currency is echoed in meta.currency.
 *  - "Revenue" definitions are explicit and consistent across tools (see
 *    metricDefs): gross = sum(total_amount) of paid orders; net = paid −
 *    refunded; paid orders = payment_status in the paid set.
 *  - Date basis is created_at (echoed as meta.date_basis) for determinism.
 *  - Server-side aggregation only — no raw row dumps. query-orders caps at 200
 *    grouped rows; trend caps its bucket count.
 *  - Each report returns an NL summary the agent can quote verbatim.
 *
 * Parameter design: a shared `range` enum (today … last_year) resolves to a
 * UTC window server-side, with explicit start_date/end_date as an override —
 * the agent never has to compute "last month" itself.
 */
class ReportTools
{
    // Payment statuses for orders that CAPTURED PAYMENT at some point — including
    // one later fully refunded (payment_status 'refunded', order status 'canceled').
    // A fully refunded order captured payment then returned it, so it belongs in the
    // refund metrics (it is the whole point of a refund report) and in the paid
    // denominator of the refund rate, and it nets to zero in net_revenue
    // (total_paid - total_refund). Gating refund aggregation on the narrower paid set
    // silently undercounted every fully-refunded order. Including 'refunded' here
    // matches FluentCart's own admin reports, which compute refunds from
    // total_refund > 0 with no current-status gate (see RevenueReportService /
    // RefundReportService::applyFilters — the default has no payment_status filter).
    const PAID = ['paid', 'partially_paid', 'partially_refunded', 'refunded'];

    // all_time is a documented alias of since_launch (see resolveRange) so the
    // range vocabulary matches get-product-financials, which uses all_time.
    const RANGES = ['today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'mtd', 'qtd', 'ytd', 'last_quarter', 'last_year', 'since_launch', 'all_time'];

    const MAX_BUCKETS = 180;

    const MAX_ROWS = 200;

    public static function definitions()
    {
        $rangeProp = ['type' => 'string', 'enum' => self::RANGES, 'description' => 'Relative window, resolved in UTC to match the store reports. today = midnight UTC to now; since_launch (alias: all_time) = the store\'s first paid order to now. Or pass start_date + end_date (dates), date_from + date_to (ISO 8601 datetimes), or since (delta).'];

        // Shared custom-window params. All UTC — reports stay reconcilable with the
        // admin dashboard, which buckets on the GMT-stored created_at.
        $dateFrom = ['type' => 'string', 'description' => 'ISO 8601 datetime or YYYY-MM-DD, UTC. Time-precise custom window start; overrides range. A time of day is honored (e.g. launch hour).'];
        $dateTo   = ['type' => 'string', 'description' => 'ISO 8601 datetime or YYYY-MM-DD, UTC. Time-precise custom window end; overrides range.'];
        $since    = ['type' => 'string', 'description' => 'ISO 8601 datetime, UTC. Delta mode: only records after this instant, up to now — answers "what changed since my last check". Overrides range/date_from/date_to.'];

        // Live/test order scoping. Reports historically counted BOTH, so 'all' is
        // the non-breaking default; pass 'live' to exclude test-mode orders from
        // revenue. The effective mode is always echoed as meta.mode so a number
        // is never silently polluted by test orders.
        $modeProp = ['type' => 'string', 'enum' => ['live', 'test', 'all'], 'default' => 'all', 'description' => 'Order mode. all (default) counts both live and test orders; pass live to exclude test-mode orders. Echoed as meta.mode.'];

        // Pagination for the flexible query-* aggregates: when a grouping produces
        // more than per_page groups the response sets meta.page.has_more; raise
        // page to walk the rest instead of only being able to narrow the window.
        $pageProp    = ['type' => 'integer', 'default' => 1, 'description' => '1-based page over the grouped rows. Use with meta.page.has_more to page past the per_page cap.'];
        $perPageProp = ['type' => 'integer', 'default' => 200, 'description' => 'Grouped rows per page. Max 200.'];

        // The query-* aggregates share one response shape: metrics/dimensions echo
        // + a rows array whose keys are dynamic (one per requested dimension and
        // metric). Declared for the model up front so it needn't probe a call to
        // learn the envelope. Money in rows is a compact decimal in meta.currency.
        $queryOutputSchema = MCPHelper::envelopeSchema([
            'type'       => 'object',
            'properties' => [
                'metrics'    => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'The metrics that were computed.'],
                'dimensions' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'The group-by dimensions.'],
                'range'      => ['type' => 'object', 'description' => 'Resolved UTC window (absent on query-customers, which is not date-scoped).'],
                'rows'       => [
                    'type'        => 'array',
                    'description' => 'One object per group. Keys are the requested dimensions plus one key per metric; money metrics are compact decimals in meta.currency, counts are integers.',
                    'items'       => ['type' => 'object'],
                ],
            ],
        ], ['date_basis' => ['type' => 'string'], 'mode' => ['type' => 'string'], 'page' => ['type' => 'object'], 'truncated' => ['type' => 'boolean']]);

        $subStatuses = ContextTools::enums()['subscription_statuses'];

        $defs = [
            'fluent-cart/get-sales-report' => [
                'label'       => __('Get Sales Report', 'fluent-cart'),
                'description' => __('Revenue overview for a period with comparison to the prior equal period: gross, net, paid, refunded, tax, shipping, fees, order count, AOV, unique customers, and percent change. The refunded metric covers all refunds in the window including fully refunded orders (which net to zero in net_revenue). Scoped to one currency, the store default unless a currency is given.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'range'      => $rangeProp,
                        'start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, UTC. Overrides range.'],
                        'end_date'   => ['type' => 'string', 'description' => 'YYYY-MM-DD, UTC. Overrides range.'],
                        'date_from'  => $dateFrom,
                        'date_to'    => $dateTo,
                        'since'      => $since,
                        'currency'   => ['type' => 'string', 'description' => 'ISO currency. Defaults to the store currency.'],
                        'compare'    => ['type' => 'boolean', 'default' => true, 'description' => 'Include prior-period comparison.'],
                    ],
                ],
                'output_schema' => MCPHelper::envelopeSchema([
                    'type'       => 'object',
                    'properties' => [
                        'range'   => ['type' => 'object', 'description' => 'Resolved UTC window: start, end, label, currency.'],
                        'metrics' => [
                            'type'        => 'object',
                            'description' => 'order_count and unique_customers are integers; every other key (gross_revenue, net_revenue, paid, refunded, tax, shipping, fees, aov) is a money object.',
                            'properties'  => [
                                'order_count'      => ['type' => 'integer'],
                                'unique_customers' => ['type' => 'integer'],
                            ],
                            // Declare the money shape ONCE for all the money metrics
                            // rather than inlining it per key (10x is real tokens).
                            'additionalProperties' => MCPHelper::moneyDef(),
                        ],
                        'definitions' => ['type' => 'object', 'description' => 'Human-readable metric definitions.'],
                        'comparison'  => ['type' => 'object', 'description' => 'Prior-period metrics and percent change (present when compare=true).'],
                    ],
                ], ['date_basis' => ['type' => 'string'], 'mode' => ['type' => 'string']]),
                'execute_callback'    => [self::class, 'getSalesReport'],
                'permission_callback' => function () {
                    return PermissionGate::can('reports/view');
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/get-sales-trend' => [
                'label'       => __('Get Sales Trend', 'fluent-cart'),
                'description' => __('Time series of revenue and order count bucketed by day, week, or month over a period. Scoped to one currency. Use to see growth and seasonality.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'range'      => $rangeProp,
                        'start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, UTC.'],
                        'end_date'   => ['type' => 'string', 'description' => 'YYYY-MM-DD, UTC.'],
                        'date_from'  => $dateFrom,
                        'date_to'    => $dateTo,
                        'since'      => $since,
                        'interval'   => ['type' => 'string', 'enum' => ['hour', 'day', 'week', 'month'], 'default' => 'day', 'description' => 'Bucket size. hour is for intraday launch monitoring (capped at 180 buckets per call). Alias: granularity.'],
                        'granularity' => ['type' => 'string', 'enum' => ['hour', 'day', 'week', 'month'], 'description' => 'Alias for interval.'],
                        'currency'   => ['type' => 'string'],
                    ],
                ],
                'execute_callback'    => [self::class, 'getSalesTrend'],
                'permission_callback' => function () {
                    return PermissionGate::can('reports/view');
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/get-top-products' => [
                'label'       => __('Get Top Products', 'fluent-cart'),
                'description' => __('Best-selling products over a period, ranked by revenue or units sold. Scoped to one currency.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'range'      => $rangeProp,
                        'start_date' => ['type' => 'string'],
                        'end_date'   => ['type' => 'string'],
                        'date_from'  => $dateFrom,
                        'date_to'    => $dateTo,
                        'since'      => $since,
                        'metric'     => ['type' => 'string', 'enum' => ['revenue', 'units'], 'default' => 'revenue'],
                        'currency'   => ['type' => 'string'],
                        'limit'      => ['type' => 'integer', 'default' => 10, 'description' => 'Max 50.'],
                    ],
                ],
                'execute_callback'    => [self::class, 'getTopProducts'],
                'permission_callback' => function () {
                    return PermissionGate::can('reports/view');
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/get-refund-report' => [
                'label'       => __('Get Refund Report', 'fluent-cart'),
                'description' => __('Refund metrics for a period: refunded order count, refund rate as a share of paid orders, total and average refunded amount. Counts every order refunded in the window from its total_refund, including orders that were fully refunded and then canceled (not just partial refunds on still-paid orders). Scoped to one currency.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'range'      => $rangeProp,
                        'start_date' => ['type' => 'string'],
                        'end_date'   => ['type' => 'string'],
                        'date_from'  => $dateFrom,
                        'date_to'    => $dateTo,
                        'since'      => $since,
                        'currency'   => ['type' => 'string'],
                    ],
                ],
                'execute_callback'    => [self::class, 'getRefundReport'],
                'permission_callback' => function () {
                    return PermissionGate::can('reports/view');
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/query-sources' => [
                'label'       => __('Query Sources (UTM attribution)', 'fluent-cart'),
                'description' => __('Flexible UTM attribution: pick metrics and group by any UTM fields — source, medium, campaign, term, content, id — over a period, with optional source/medium/campaign filters to drill down. Pass product_id (or variation_id) to attribute only orders containing that product. Scoped to one currency, paid orders. Orders with no UTM fall under a none bucket. Returns up to 200 rows ranked by the first metric.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'metrics'      => ['type' => 'array', 'description' => 'Defaults to orders and gross_revenue.', 'items' => ['type' => 'string', 'enum' => ['orders', 'gross_revenue', 'net_revenue', 'aov', 'unique_customers', 'refunded_amount']]],
                        'dimensions'   => ['type' => 'array', 'description' => 'Group by these UTM fields. Defaults to utm_source, utm_medium, utm_campaign.', 'items' => ['type' => 'string', 'enum' => ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id']]],
                        'utm_source'   => ['type' => 'string', 'description' => 'Filter to one source, exact match.'],
                        'utm_medium'   => ['type' => 'string', 'description' => 'Filter to one medium, exact match.'],
                        'utm_campaign' => ['type' => 'string', 'description' => 'Filter to one campaign, exact match.'],
                        'product_id'   => ['type' => 'integer', 'description' => 'Limit attribution to orders containing this product (e.g. one product on a multi-product store).'],
                        'variation_id' => ['type' => 'integer', 'description' => 'Limit attribution to orders containing this variation.'],
                        'range'        => $rangeProp,
                        'start_date'   => ['type' => 'string'],
                        'end_date'     => ['type' => 'string'],
                        'date_from'    => $dateFrom,
                        'date_to'      => $dateTo,
                        'since'        => $since,
                        'currency'     => ['type' => 'string'],
                        'limit'        => ['type' => 'integer', 'default' => 50, 'description' => 'Max 200.'],
                    ],
                ],
                'execute_callback'    => [self::class, 'querySources'],
                'permission_callback' => function () {
                    return PermissionGate::can('reports/view');
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/query-orders' => [
                'label'       => __('Query Orders (flexible aggregate)', 'fluent-cart'),
                'description' => __('Flexible order analytics: pick metrics, group by dimensions with filters, when a fixed report does not fit — e.g. revenue by payment_status, orders by month, revenue by order_type (one-time payment vs new subscription vs renewal), or revenue by country. product_id or variation_id limits to orders containing that product. This is the tool for revenue BY GEOGRAPHY: group by country/state, which read each order\'s own billing address, falling back to the buyer\'s primary billing address. One currency; window filters on created_at, echoed as meta.date_basis.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'metrics'    => [
                            'type'        => 'array',
                            'description' => 'One or more. Defaults to order_count and gross_revenue.',
                            'items'       => ['type' => 'string', 'enum' => ['order_count', 'gross_revenue', 'paid_revenue', 'refunded_amount', 'aov', 'unique_customers']],
                        ],
                        'dimensions' => [
                            'type'        => 'array',
                            'description' => 'Group by these. Empty means a single total row. order_type splits sales by payment (one-time purchase), subscription (first subscription order) and renewal (recurring charge); combine with a time dimension for e.g. order_type x month. country/state come from each order\'s own billing address, falling back to the buyer\'s primary billing address when the order has no address row of its own (common for digital goods) — this is the right basis for revenue by geography. Orders neither source can place land in an explicit "unknown" bucket so the rows still sum to total revenue; see meta.geo_source.',
                            'items'       => ['type' => 'string', 'enum' => ['day', 'week', 'month', 'status', 'payment_status', 'order_type', 'country', 'state']],
                        ],
                        'country'      => ['type' => 'string', 'description' => 'ISO-2 code. Limit to orders billed to this country, resolved the same way as the country dimension (order address, then the buyer\'s primary billing address).'],
                        'product_id'   => ['type' => 'integer', 'description' => 'Limit to orders CONTAINING this product. Order-level metrics (revenue, count) reflect the whole order, not just this product\'s lines — for per-product line revenue use query-products.'],
                        'variation_id' => ['type' => 'integer', 'description' => 'Limit to orders containing this variation.'],
                        'range'      => $rangeProp,
                        'start_date' => ['type' => 'string'],
                        'end_date'   => ['type' => 'string'],
                        'date_from'  => $dateFrom,
                        'date_to'    => $dateTo,
                        'since'      => $since,
                        'currency'   => ['type' => 'string'],
                        'sort_desc'  => ['type' => 'boolean', 'default' => true, 'description' => 'Sort by the first metric descending. When grouping by a time dimension (day/week/month), rows default to chronological order unless you set this explicitly.'],
                    ],
                ],
                'execute_callback'    => [self::class, 'queryOrders'],
                'permission_callback' => function () {
                    return PermissionGate::can('reports/view');
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/query-products' => [
                'label'       => __('Query Products (flexible aggregate)', 'fluent-cart'),
                'description' => __('Flexible product-line analytics over sold items: pick metrics, group by product or variation, optionally split by order_type (one-time payment vs new subscription vs renewal), within a period and one currency. Rows self-describe: product_name always, plus variation_label when grouped by variation, so no follow-up lookup. For a time series use get-sales-trend. Window filters on the parent order created_at, echoed as meta.date_basis.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'metrics'    => ['type' => 'array', 'description' => 'Defaults to units_sold and line_revenue. list_price_sum = units x list price before any discount; discount_amount = list_price_sum minus line_revenue (coupon + manual discount) — pick both with line_revenue to see margin leakage directly; net_revenue = line_revenue minus refunds.', 'items' => ['type' => 'string', 'enum' => ['units_sold', 'line_revenue', 'list_price_sum', 'discount_amount', 'net_revenue', 'order_count', 'avg_unit_price', 'refund_amount']]],
                        'dimensions' => ['type' => 'array', 'description' => 'Group by these. order_type splits a product\'s sales by the parent order type: payment (one-time purchase), subscription (first subscription order) and renewal (recurring charge). Combine with product/variation, e.g. product x order_type.', 'items' => ['type' => 'string', 'enum' => ['product', 'variation', 'order_type']]],
                        'product_id'   => ['type' => 'integer', 'description' => 'Limit to one product (by product/post id). Combine with dimensions=[variation] to break that single product down by variation.'],
                        'variation_id' => ['type' => 'integer', 'description' => 'Limit to one variation of the product.'],
                        'range'      => $rangeProp,
                        'start_date' => ['type' => 'string'],
                        'end_date'   => ['type' => 'string'],
                        'date_from'  => $dateFrom,
                        'date_to'    => $dateTo,
                        'since'      => $since,
                        'currency'   => ['type' => 'string'],
                    ],
                ],
                'execute_callback'    => [self::class, 'queryProducts'],
                'permission_callback' => function () {
                    return PermissionGate::can('reports/view');
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/query-customers' => [
                'label'       => __('Query Customers (flexible aggregate)', 'fluent-cart'),
                'description' => __('Flexible customer analytics: pick metrics and group by country, state, status, or first/last purchase month, with optional filters. LTV is in store currency. Returns up to 200 rows. country/state resolve from the customer\'s primary billing address, falling back to their profile location, then "unknown" — this is where a customer is REGISTERED, captured at their first purchase and not refreshed since. To attribute revenue to where each order was actually billed, group query-orders by country instead.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'metrics'            => ['type' => 'array', 'description' => 'Defaults to customer_count and total_ltv.', 'items' => ['type' => 'string', 'enum' => ['customer_count', 'total_ltv', 'avg_ltv', 'avg_purchase_count', 'repeat_customers']]],
                        'dimensions'         => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['country', 'state', 'status', 'first_purchase_month', 'last_purchase_month']]],
                        'country'            => ['type' => 'string', 'description' => 'ISO-2 code, matched against the customer profile country (same caveat as the country dimension).'],
                        'status'             => ['type' => 'string', 'enum' => ['active', 'archived'], 'description' => 'Not every customer has one set — customers with no status appear under the status dimension as "unknown" and are excluded by this filter. Omit it to count all customers.'],
                        'min_ltv'            => ['type' => 'number', 'description' => 'Minimum LTV in store currency.'],
                        'min_purchase_count' => ['type' => 'integer'],
                    ],
                ],
                'execute_callback'    => [self::class, 'queryCustomers'],
                'permission_callback' => function () {
                    return PermissionGate::can('reports/view');
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/query-subscriptions' => [
                'label'       => __('Query Subscriptions (flexible aggregate)', 'fluent-cart'),
                'description' => __('Flexible subscription analytics: pick metrics, group by month/plan_type/status/billing_interval over a date window. plan_type splits fixed-term installment/split-pay from open-ended recurring plans. contract_value books installments at their full committed price, recurring_total x bill_times — a split-pay deal counts once at signup, not per charge. date_basis=created_at for booking cohorts, canceled_at for churn — a completed installment is paid-in-full, never churn. Not currency-scoped; money is store currency.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'metrics'    => ['type' => 'array', 'description' => 'Defaults to subscription_count and contract_value. contract_value = full committed price of installments, recurring_total x bill_times, and 0 for open-ended plans; recurring_value = one-cycle total.', 'items' => ['type' => 'string', 'enum' => ['subscription_count', 'contract_value', 'recurring_value']]],
                        'dimensions' => ['type' => 'array', 'description' => 'Group by these. Empty means a single total row. plan_type splits installment vs recurring; status keeps completed installments separate from canceled/expired churn.', 'items' => ['type' => 'string', 'enum' => ['month', 'plan_type', 'status', 'billing_interval']]],
                        'date_basis' => ['type' => 'string', 'enum' => ['created_at', 'canceled_at', 'next_billing_date'], 'default' => 'created_at', 'description' => 'Which date the range and the month dimension use: created_at for booking cohorts, canceled_at for churn, next_billing_date for upcoming renewals.'],
                        'plan_type'  => ['type' => 'string', 'enum' => ['installment', 'recurring', 'all'], 'default' => 'all', 'description' => 'Filter to installment (bill_times > 0) or recurring (bill_times = 0) plans.'],
                        'status'     => ['type' => 'string', 'enum' => $subStatuses],
                        'product_id' => ['type' => 'integer', 'description' => 'Limit to subscriptions for one product.'],
                        'range'      => $rangeProp,
                        'start_date' => ['type' => 'string'],
                        'end_date'   => ['type' => 'string'],
                        'date_from'  => $dateFrom,
                        'date_to'    => $dateTo,
                        'since'      => $since,
                    ],
                ],
                'execute_callback'    => [self::class, 'querySubscriptions'],
                'permission_callback' => function () {
                    return PermissionGate::can('reports/view');
                },
                'annotations' => ['readonly' => true],
            ],
        ];

        // The live/test mode filter applies only to order-based reports —
        // fct_orders has a mode column, but subscription/customer analytics do
        // not. Injected here so the shared $modeProp stays a single definition.
        foreach ([
            'fluent-cart/get-sales-report',
            'fluent-cart/get-sales-trend',
            'fluent-cart/get-top-products',
            'fluent-cart/get-refund-report',
            'fluent-cart/query-sources',
            'fluent-cart/query-orders',
            'fluent-cart/query-products',
        ] as $modeTool) {
            $defs[$modeTool]['input_schema']['properties']['mode'] = $modeProp;
        }

        // page/per_page belong on the flexible aggregates, whose grouped output can
        // exceed the 200-row cap. The fixed reports (sales/trend/top/refund) return
        // a bounded shape and don't paginate. query-sources is excluded on purpose:
        // it already exposes its own `limit` + peek + truncated, and adding a
        // second page-size param would be ambiguous.
        foreach ([
            'fluent-cart/query-orders',
            'fluent-cart/query-products',
            'fluent-cart/query-customers',
            'fluent-cart/query-subscriptions',
        ] as $pagedTool) {
            $defs[$pagedTool]['input_schema']['properties']['page']     = $pageProp;
            $defs[$pagedTool]['input_schema']['properties']['per_page'] = $perPageProp;
        }

        // All five query-* aggregates share the same response envelope.
        foreach ([
            'fluent-cart/query-orders',
            'fluent-cart/query-products',
            'fluent-cart/query-customers',
            'fluent-cart/query-subscriptions',
            'fluent-cart/query-sources',
        ] as $queryTool) {
            $defs[$queryTool]['output_schema'] = $queryOutputSchema;
        }

        return $defs;
    }

    // -----------------------------------------------------------------
    // get-sales-report
    // -----------------------------------------------------------------

    public static function getSalesReport($params = [])
    {
        $currency = self::currency($params);
        $range    = self::resolveRange($params);
        $mode     = self::orderMode($params);

        $current = self::salesMetrics($range['start'], $range['end'], $currency, $mode);

        $data = [
            'range'       => self::rangeBlock($range, $currency),
            'metrics'     => self::salesMetricsOut($current, $currency),
            'definitions' => self::metricDefs(),
        ];

        $compare = !isset($params['compare']) || !empty($params['compare']);
        if ($compare && $range['prev_start']) {
            $prior = self::salesMetrics($range['prev_start'], $range['prev_end'], $currency, $mode);
            $data['comparison'] = [
                'prior_metrics'  => self::salesMetricsOut($prior, $currency),
                'change_percent' => [
                    'gross_revenue' => self::pct($current['gross'], $prior['gross']),
                    'net_revenue'   => self::pct($current['net'], $prior['net']),
                    'order_count'   => self::pct($current['orders'], $prior['orders']),
                ],
            ];
        }

        $summary = sprintf(
            /* translators: 1: gross revenue, 2: order count, 3: average order value */
            __('Revenue %1$s across %2$d paid orders, AOV %3$s.', 'fluent-cart'),
            MCPHelper::displayAmount($current['gross'], $currency),
            $current['orders'],
            MCPHelper::displayAmount($current['aov'], $currency)
        );

        return MCPHelper::envelope($summary, $data, ['currency' => $currency, 'date_basis' => 'created_at', 'mode' => $mode]);
    }

    private static function salesMetrics($start, $end, $currency, $mode = 'all')
    {
        // One aggregate scan instead of eight (this is the headline report, and
        // it runs twice when compare=true). Same filtered set, same numbers.
        $q = Order::query()
            ->whereIn('payment_status', self::PAID)
            ->where('currency', $currency)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end);
        self::applyMode($q, $mode);
        $row = $q->selectRaw(
                'COUNT(*) as orders, '
                . 'COALESCE(SUM(total_amount), 0) as gross, '
                . 'COALESCE(SUM(total_paid), 0) as paid, '
                . 'COALESCE(SUM(total_refund), 0) as refund, '
                . 'COALESCE(SUM(tax_total), 0) as tax, '
                . 'COALESCE(SUM(shipping_total), 0) as ship, '
                . 'COALESCE(SUM(fee_total), 0) as fees, '
                . 'COUNT(DISTINCT customer_id) as uniq'
            )
            ->first();

        $orders = $row ? (int) $row->orders : 0;
        $gross  = $row ? (int) $row->gross : 0;
        $paid   = $row ? (int) $row->paid : 0;
        $refund = $row ? (int) $row->refund : 0;
        $tax    = $row ? (int) $row->tax : 0;
        $ship   = $row ? (int) $row->ship : 0;
        $fees   = $row ? (int) $row->fees : 0;
        $uniq   = $row ? (int) $row->uniq : 0;

        return [
            'orders'   => $orders,
            'gross'    => $gross,
            'paid'     => $paid,
            'refund'   => $refund,
            'net'      => $paid - $refund,
            'tax'      => $tax,
            'shipping' => $ship,
            'fees'     => $fees,
            'unique'   => $uniq,
            'aov'      => $orders > 0 ? (int) round($gross / $orders) : 0,
        ];
    }

    private static function salesMetricsOut($m, $currency)
    {
        return [
            'order_count'      => $m['orders'],
            'unique_customers' => $m['unique'],
            'gross_revenue'    => MCPHelper::money($m['gross'], $currency),
            'net_revenue'      => MCPHelper::money($m['net'], $currency),
            'paid'             => MCPHelper::money($m['paid'], $currency),
            'refunded'         => MCPHelper::money($m['refund'], $currency),
            'tax'              => MCPHelper::money($m['tax'], $currency),
            'shipping'         => MCPHelper::money($m['shipping'], $currency),
            'fees'             => MCPHelper::money($m['fees'], $currency),
            'aov'              => MCPHelper::money($m['aov'], $currency),
        ];
    }

    private static function metricDefs()
    {
        return [
            'paid_orders'   => 'Orders that captured payment at some point (payment_status in: ' . implode(', ', self::PAID) . '). A fully refunded order (payment_status "refunded") is included — it captured payment, so it counts toward gross/paid and the refunded total, and nets to zero in net_revenue.',
            'gross_revenue' => 'Sum of order total_amount for paid orders (gross sales, before refunds).',
            'net_revenue'   => 'Sum of total_paid minus total_refund; a fully refunded order nets to zero.',
            'refunded'      => 'Sum of total_refund over paid orders — includes fully refunded orders, independent of current order status.',
            'aov'           => 'gross_revenue divided by paid order count.',
            'date_basis'    => 'created_at, within the given range.',
        ];
    }

    // -----------------------------------------------------------------
    // get-sales-trend
    // -----------------------------------------------------------------

    public static function getSalesTrend($params = [])
    {
        $currency = self::currency($params);
        $range    = self::resolveRange($params);
        $mode     = self::orderMode($params);
        // `granularity` is an alias for `interval`; hour is for intraday launch
        // monitoring (MAX_BUCKETS caps it at 180 hours ~ 7.5 days per call).
        $intervalIn = isset($params['granularity']) ? $params['granularity'] : (isset($params['interval']) ? $params['interval'] : 'day');
        $interval   = in_array($intervalIn, ['hour', 'day', 'week', 'month'], true) ? $intervalIn : 'day';

        $format = $interval === 'month'
            ? '%Y-%m'
            : ($interval === 'week' ? '%x-W%v' : ($interval === 'hour' ? '%Y-%m-%d %H:00' : '%Y-%m-%d'));

        $q = Order::query()
            ->whereIn('payment_status', self::PAID)
            ->where('currency', $currency)
            ->where('created_at', '>=', $range['start'])
            ->where('created_at', '<=', $range['end']);
        self::applyMode($q, $mode);
        $rows = $q->selectRaw('DATE_FORMAT(created_at, ?) as bucket, COUNT(*) as order_count, SUM(total_amount) as gross', [$format])
            ->groupBy('bucket')
            ->orderBy('bucket', 'ASC')
            ->limit(self::MAX_BUCKETS)
            ->get();

        $trend = [];
        $sum   = 0;
        foreach ($rows as $row) {
            $gross = (int) $row->gross;
            $sum  += $gross;
            $trend[] = [
                'bucket'      => $row->bucket,
                'order_count' => (int) $row->order_count,
                'gross'       => MCPHelper::moneyCompact($gross),
            ];
        }

        $summary = sprintf(
            /* translators: 1: number of buckets, 2: interval, 3: total revenue */
            __('%1$d %2$s buckets, total revenue %3$s.', 'fluent-cart'),
            count($trend),
            $interval,
            MCPHelper::displayAmount($sum, $currency)
        );

        return MCPHelper::envelope(
            $summary,
            ['interval' => $interval, 'range' => self::rangeBlock($range, $currency), 'trend' => $trend],
            ['currency' => $currency, 'date_basis' => 'created_at', 'mode' => $mode, 'truncated' => count($rows) >= self::MAX_BUCKETS]
        );
    }

    // -----------------------------------------------------------------
    // get-top-products
    // -----------------------------------------------------------------

    public static function getTopProducts($params = [])
    {
        $currency = self::currency($params);
        $range    = self::resolveRange($params);
        $mode     = self::orderMode($params);
        $metric   = isset($params['metric']) && $params['metric'] === 'units' ? 'units' : 'revenue';
        $limit    = isset($params['limit']) ? min(max((int) $params['limit'], 1), 50) : 10;
        $orderCol = $metric === 'units' ? 'units' : 'revenue';

        $rows = OrderItem::query()
            ->whereHas('order', function ($q) use ($range, $currency, $mode) {
                $q->whereIn('payment_status', self::PAID)
                    ->where('currency', $currency)
                    ->where('created_at', '>=', $range['start'])
                    ->where('created_at', '<=', $range['end']);
                self::applyMode($q, $mode);
            })
            ->selectRaw('post_id, MAX(post_title) as title, SUM(quantity) as units, SUM(line_total - refund_total) as revenue, COUNT(DISTINCT order_id) as order_count')
            ->groupBy('post_id')
            ->orderBy($orderCol, 'DESC')
            ->limit($limit)
            ->get();

        $products = [];
        foreach ($rows as $row) {
            $products[] = [
                'product_id'  => (int) $row->post_id,
                'title'       => $row->title,
                'units_sold'  => (int) $row->units,
                'revenue'     => MCPHelper::moneyCompact((int) $row->revenue),
                'order_count' => (int) $row->order_count,
            ];
        }

        $summary = sprintf(
            /* translators: 1: number of products, 2: ranking metric */
            __('Top %1$d products by %2$s.', 'fluent-cart'),
            count($products),
            $metric
        );

        return MCPHelper::envelope($summary, ['metric' => $metric, 'range' => self::rangeBlock($range, $currency), 'products' => $products], ['currency' => $currency, 'date_basis' => 'created_at', 'mode' => $mode]);
    }

    // -----------------------------------------------------------------
    // get-refund-report
    // -----------------------------------------------------------------

    public static function getRefundReport($params = [])
    {
        $currency = self::currency($params);
        $range    = self::resolveRange($params);
        $mode     = self::orderMode($params);

        $paidBase = Order::query()
            ->whereIn('payment_status', self::PAID)
            ->where('currency', $currency)
            ->where('created_at', '>=', $range['start'])
            ->where('created_at', '<=', $range['end']);
        self::applyMode($paidBase, $mode);

        $paidCount = (clone $paidBase)->count();

        $refundedBase   = (clone $paidBase)->where('total_refund', '>', 0);
        $refundedCount  = (clone $refundedBase)->count();
        $refundedAmount = (int) (clone $refundedBase)->sum('total_refund');

        $rate = $paidCount > 0 ? round(($refundedCount / $paidCount) * 100, 2) : 0;
        $avg  = $refundedCount > 0 ? (int) round($refundedAmount / $refundedCount) : 0;

        $summary = sprintf(
            /* translators: 1: refunded order count, 2: refund rate percent, 3: total refunded */
            __('%1$d orders refunded, %2$s%% of paid, totaling %3$s.', 'fluent-cart'),
            $refundedCount,
            $rate,
            MCPHelper::displayAmount($refundedAmount, $currency)
        );

        return MCPHelper::envelope(
            $summary,
            [
                'range'                => self::rangeBlock($range, $currency),
                'paid_order_count'     => $paidCount,
                'refunded_order_count' => $refundedCount,
                'refund_rate_percent'  => $rate,
                'total_refunded'       => MCPHelper::money($refundedAmount, $currency),
                'average_refund'       => MCPHelper::money($avg, $currency),
                'definitions'          => [
                    'refunded_order_count' => 'Orders with total_refund > 0 in the window, regardless of current status — a fully refunded (canceled) order still counts. Matches the admin refund report.',
                    'paid_order_count'     => 'Orders that captured payment in the window, including those later fully refunded. This is the refund_rate denominator.',
                    'refund_rate_percent'  => 'refunded_order_count / paid_order_count * 100. A fully refunded order was paid before being refunded, so it is counted in BOTH the numerator and the denominator.',
                ],
            ],
            ['currency' => $currency, 'date_basis' => 'created_at', 'mode' => $mode]
        );
    }

    // -----------------------------------------------------------------
    // query-sources (flexible UTM attribution)
    // -----------------------------------------------------------------

    const UTM_DIMENSIONS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id'];

    public static function querySources($params = [])
    {
        $currency   = self::currency($params);
        $range      = self::resolveRange($params);
        $mode       = self::orderMode($params);
        $limit      = isset($params['limit']) ? min(max((int) $params['limit'], 1), self::MAX_ROWS) : 50;
        $metrics    = self::pickList($params, 'metrics', ['orders', 'gross_revenue', 'net_revenue', 'aov', 'unique_customers', 'refunded_amount'], ['orders', 'gross_revenue']);
        $dimensions = self::pickList($params, 'dimensions', self::UTM_DIMENSIONS, ['utm_source', 'utm_medium', 'utm_campaign']);

        // Build the aggregate directly (rather than via SourceReportService,
        // which hard-codes its grouping) so the agent picks the UTM dimensions.
        // Uses the raw query builder with the same aliases as the admin Source
        // report to avoid Order model global scopes. Paid + one currency to stay
        // consistent with the other reports.
        // Dedupe operations to one row per order before joining: fct_order_operations
        // has only an INDEX on order_id (not UNIQUE), so a raw leftJoin would fan out
        // and make every SUM(o.<money>) double-count any order with >1 ops row.
        // MAX() per UTM column is ONLY_FULL_GROUP_BY-safe and returns the single
        // row's value in the normal one-row-per-order case.
        $opSub = App::db()->table('fct_order_operations')
            ->select('order_id')
            ->selectRaw(
                'MAX(utm_source) as utm_source, MAX(utm_medium) as utm_medium, '
                . 'MAX(utm_campaign) as utm_campaign, MAX(utm_term) as utm_term, '
                . 'MAX(utm_content) as utm_content, MAX(utm_id) as utm_id'
            )
            ->groupBy('order_id');

        $query = App::db()->table('fct_orders as o')
            ->leftJoinSub($opSub, 'oo', 'o.id', '=', 'oo.order_id')
            ->whereIn('o.payment_status', self::PAID)
            ->where('o.currency', $currency)
            ->where('o.created_at', '>=', $range['start'])
            ->where('o.created_at', '<=', $range['end']);
        // Orders table is aliased 'o' here; qualify the mode column to match.
        self::applyMode($query, $mode, 'o.mode');

        // Optional drill-down filters on exact UTM values.
        foreach (['utm_source', 'utm_medium', 'utm_campaign'] as $f) {
            if (!empty($params[$f])) {
                $query->where('oo.' . $f, sanitize_text_field($params[$f]));
            }
        }

        // Optional entity filters: restrict attribution to orders containing a
        // product/variation. whereExists on fct_order_items (never a join, so the
        // per-order SUM()s don't fan out) — mirrors the admin SourceReport filter.
        foreach (['product_id' => 'post_id', 'variation_id' => 'object_id'] as $param => $col) {
            if (!empty($params[$param])) {
                $val = (int) $params[$param];
                $query->whereExists(function ($q) use ($col, $val) {
                    $q->selectRaw('1')
                        ->from('fct_order_items as oi')
                        ->whereRaw('oi.order_id = o.id')
                        ->where('oi.' . $col, $val);
                });
            }
        }

        $selects   = [];
        $groupExpr = [];
        foreach ($dimensions as $dim) {
            // Coalesce NULL/'' into a single 'none' bucket; group by the
            // expression so the split values collapse together.
            $expr        = "COALESCE(NULLIF(oo." . $dim . ", ''), 'none')";
            $selects[]   = $expr . ' as ' . $dim;
            $groupExpr[] = $expr;
        }

        // Definitions must match metricDefs() and the sales report so an agent's
        // "revenue by source" ties out with "revenue this month":
        //   gross_revenue = SUM(total_amount), net_revenue = SUM(total_paid - total_refund).
        $metricSql = [
            'orders'           => 'COUNT(DISTINCT o.id) as orders',
            'gross_revenue'    => 'SUM(o.total_amount) as gross_revenue',
            'net_revenue'      => 'SUM(o.total_paid - o.total_refund) as net_revenue',
            'unique_customers' => 'COUNT(DISTINCT o.customer_id) as unique_customers',
            'refunded_amount'  => 'SUM(o.total_refund) as refunded_amount',
        ];
        foreach ($metrics as $m) {
            if (isset($metricSql[$m])) {
                $selects[] = $metricSql[$m];
            }
        }
        if (in_array('aov', $metrics, true)) {
            if (!in_array('gross_revenue', $metrics, true)) {
                $selects[] = $metricSql['gross_revenue'];
            }
            if (!in_array('orders', $metrics, true)) {
                $selects[] = $metricSql['orders'];
            }
        }

        $query->selectRaw(implode(', ', $selects));
        if ($groupExpr) {
            $query->groupByRaw(implode(', ', $groupExpr));
        }

        $firstMetric = isset($metrics[0]) ? $metrics[0] : 'orders';
        if ($firstMetric === 'aov') {
            $firstMetric = 'gross_revenue';
        }
        if ($groupExpr && isset($metricSql[$firstMetric])) {
            $query->orderBy($firstMetric, 'DESC');
        }
        // Fetch one extra row so we can tell "more exist beyond your limit" from
        // "you hit the 200 hard cap". $limit is already clamped to <= MAX_ROWS.
        $query->limit($limit + 1);

        $rows         = $query->get();
        $moneyMetrics = ['gross_revenue', 'net_revenue', 'refunded_amount'];
        $truncated    = count($rows) > $limit;

        $out = [];
        foreach ($rows as $row) {
            if (count($out) >= $limit) {
                break;
            }
            $r = [];
            foreach ($dimensions as $dim) {
                $r[$dim] = $row->{$dim};
            }
            foreach ($metrics as $m) {
                if ($m === 'aov') {
                    $g = (int) $row->gross_revenue;
                    $c = (int) $row->orders;
                    $r['aov'] = MCPHelper::moneyCompact($c > 0 ? (int) round($g / $c) : 0);
                } elseif (in_array($m, $moneyMetrics, true)) {
                    $r[$m] = MCPHelper::moneyCompact((int) $row->{$m});
                } else {
                    $r[$m] = (int) $row->{$m};
                }
            }
            $out[] = $r;
        }

        $summary = sprintf(
            /* translators: 1: row count, 2: metric list, 3: dimension list */
            __('%1$d rows — metrics [%2$s] grouped by [%3$s].', 'fluent-cart'),
            count($out),
            implode(', ', $metrics),
            $dimensions ? implode(', ', $dimensions) : __('total', 'fluent-cart')
        );

        return MCPHelper::envelope(
            $summary,
            ['metrics' => $metrics, 'dimensions' => $dimensions, 'range' => self::rangeBlock($range, $currency), 'rows' => $out],
            [
                'currency'   => $currency,
                'date_basis' => 'created_at',
                'mode'       => $mode,
                'returned'   => count($out),
                'limit'      => $limit,
                'max_rows'   => self::MAX_ROWS,
                // true = more rows exist; raise `limit` (up to max_rows) to see them.
                'truncated'  => $truncated,
            ]
        );
    }

    // -----------------------------------------------------------------
    // query-orders (flexible aggregate)
    // -----------------------------------------------------------------

    public static function queryOrders($params = [])
    {
        $currency   = self::currency($params);
        $range      = self::resolveRange($params);
        $mode       = self::orderMode($params);
        $metrics    = self::pickList($params, 'metrics', ['order_count', 'gross_revenue', 'paid_revenue', 'refunded_amount', 'aov', 'unique_customers'], ['order_count', 'gross_revenue']);
        $dimensions = self::pickList($params, 'dimensions', ['day', 'week', 'month', 'status', 'payment_status', 'order_type', 'country', 'state'], []);

        $query = Order::query()
            ->whereIn('payment_status', self::PAID)
            ->where('currency', $currency)
            ->where('created_at', '>=', $range['start'])
            ->where('created_at', '<=', $range['end']);
        self::applyMode($query, $mode);

        // Optional entity filters: restrict to orders CONTAINING a product/variation.
        // whereHas keeps the aggregate order-level (metrics still reflect the whole
        // order); it never fans out rows the way a raw join would.
        self::applyOrderItemFilter($query, $params);

        // Geography comes from the order's OWN billing address first, so each
        // order's revenue is attributed to where that order was actually billed —
        // not to wherever the customer is registered now. Joined only when asked
        // for, so every other dimension keeps its current cost.
        //
        // Grouped subqueries, not raw joins: neither fct_order_addresses nor
        // fct_customer_addresses has a UNIQUE constraint on its (parent, type)
        // pair — fct_order_addresses actually holds 208 duplicate billing groups on
        // the reference store — and a duplicate would fan out and double-count
        // that order's revenue in every SUM.
        //
        // Within a duplicate group the LATEST row wins (highest id), picked as one
        // whole row. Taking MAX(country) and MAX(state) independently would let the
        // two columns come from DIFFERENT rows and synthesize a place that does not
        // exist: order 259 on the reference store has billing rows (BD, BD-60) and
        // (AT, BD-60), so independent MAX()es resolve it to Bangladesh even though
        // its latest address is Austrian. (That order is payment_status=pending and
        // so outside self::PAID — it demonstrates the mechanism, not a revenue error
        // this report was making.) Five duplicate groups there disagree on country or
        // state. latestRowPick() does the one-row pick inside the GROUP BY.
        //
        // The customer's primary billing address is the SECOND source, and the
        // choice between the two is made PER ORDER, not per column — see
        // dimensionExpr(). Most orders here carry no address row of their own
        // (digital goods skip the billing step), so order-address-only attribution
        // left 976 of 4,905 paid orders — 19.9% of orders and 9.0% of revenue — in
        // the 'unknown' bucket, which makes "revenue by country" unusable for the
        // question it exists to answer. Falling back to the buyer's own primary
        // billing address recovers 878 of those 976 and cuts 'unknown' to 2.0%;
        // 98 orders are then genuinely unattributable. (Figures over all four
        // self::PAID statuses, which is what this method actually queries.)
        // geo_source in meta names the precedence so an agent never has to guess.
        $geoDims  = array_values(array_intersect(['country', 'state'], $dimensions));
        $wantsGeo = $geoDims || !empty($params['country']);
        if ($wantsGeo) {
            // Columns aliased oaddr_*/caddr_* so raw select expressions stay
            // unambiguous without table prefixes (same note as queryCustomers).
            $addrSub = App::db()->table('fct_order_addresses')
                ->select('order_id')
                ->selectRaw(
                    self::latestRowPick('country') . ' as oaddr_country, '
                    . self::latestRowPick('state') . ' as oaddr_state'
                )
                ->where('type', 'billing')
                ->groupBy('order_id');

            $query->leftJoinSub($addrSub, 'fc_oaddr', 'fct_orders.id', '=', 'fc_oaddr.order_id');

            // LEFT JOIN on customer_id, so a guest order simply has no fallback and
            // still lands in 'unknown' rather than dropping: fct_orders.customer_id
            // is nullable and NULL never matches a join predicate. The customer_id > 0
            // guard covers the other shape of missing buyer — an order carrying 0
            // instead of NULL would otherwise join to an orphaned address row filed
            // under customer 0 and inherit a stranger's country.
            //
            // customer_id is aliased caddr_customer_id for the same reason the value
            // columns are aliased: fct_orders has a customer_id too, and exposing a
            // second one made the existing COUNT(DISTINCT customer_id) behind the
            // unique_customers metric ambiguous — a hard SQL error, not a wrong
            // number. Nothing joined here may share a name with an orders column.
            $custAddrSub = App::db()->table('fct_customer_addresses')
                ->selectRaw('customer_id as caddr_customer_id')
                ->selectRaw(
                    self::latestRowPick('country') . ' as caddr_country, '
                    . self::latestRowPick('state') . ' as caddr_state'
                )
                ->where('type', 'billing')
                ->where('is_primary', 1)
                ->where('customer_id', '>', 0)
                ->groupBy('customer_id');

            $query->leftJoinSub($custAddrSub, 'fc_caddr', 'fct_orders.customer_id', '=', 'fc_caddr.caddr_customer_id');

            if (!empty($params['country'])) {
                // Built from dimensionExpr() itself, so the filter and the country
                // dimension resolve a country identically BY CONSTRUCTION — including
                // the 'unknown' bucket, which a hand-written COALESCE here omitted,
                // making country=unknown return zero rows while the dimension
                // reported 98 such orders.
                $query->whereRaw(
                    self::dimensionExpr('country') . ' = ?',
                    [sanitize_text_field($params['country'])]
                );
            }
        }

        $selects   = [];
        $groupCols = [];
        $groupRaws = [];
        foreach ($dimensions as $dim) {
            $expr      = self::dimensionExpr($dim);
            $selects[] = $expr . ' as ' . $dim;
            if (in_array($dim, ['country', 'state'], true)) {
                // Group by the EXPRESSION, not the alias: the alias collides with
                // the real fc_oaddr.country column, and MySQL resolves a GROUP BY
                // name to the column first — which would split NULL from '' and
                // scatter the unknowns across two rows instead of one bucket.
                $groupRaws[] = $expr;
            } else {
                $groupCols[] = $dim;
            }
        }

        $metricSql = [
            'order_count'      => 'COUNT(*) as order_count',
            'gross_revenue'    => 'SUM(total_amount) as gross_revenue',
            'paid_revenue'     => 'SUM(total_paid) as paid_revenue',
            'refunded_amount'  => 'SUM(total_refund) as refunded_amount',
            'unique_customers' => 'COUNT(DISTINCT customer_id) as unique_customers',
        ];
        foreach ($metrics as $m) {
            if (isset($metricSql[$m])) {
                $selects[] = $metricSql[$m];
            }
        }
        if (in_array('aov', $metrics, true)) {
            if (!in_array('gross_revenue', $metrics, true)) {
                $selects[] = $metricSql['gross_revenue'];
            }
            if (!in_array('order_count', $metrics, true)) {
                $selects[] = $metricSql['order_count'];
            }
        }

        $query->selectRaw(implode(', ', $selects));
        foreach ($groupCols as $g) {
            $query->groupBy($g);
        }
        foreach ($groupRaws as $g) {
            $query->groupByRaw($g);
        }

        $sortDesc    = !isset($params['sort_desc']) || !empty($params['sort_desc']);
        $firstMetric = isset($metrics[0]) ? $metrics[0] : 'order_count';
        if ($firstMetric === 'aov') {
            $firstMetric = 'gross_revenue';
        }

        // Find the first time dimension, if any.
        $timeDim = null;
        foreach ($dimensions as $dim) {
            if (in_array($dim, ['day', 'week', 'month'], true)) {
                $timeDim = $dim;
                break;
            }
        }

        $paging = self::queryPaging($params);
        if ($groupCols || $groupRaws) {
            if ($timeDim !== null && !isset($params['sort_desc'])) {
                // A time series reads chronologically by default; ranking a
                // calendar by metric is rarely what's wanted. An explicit
                // sort_desc still overrides this.
                $query->orderBy($timeDim, 'ASC');
            } else {
                $query->orderBy($firstMetric, $sortDesc ? 'DESC' : 'ASC');
            }
            // Deterministic tie-break on the group key so offset paging never
            // reshuffles equal-metric rows across pages.
            foreach ($groupCols as $g) {
                $query->orderBy($g, 'ASC');
            }
            foreach ($groupRaws as $g) {
                // Raw, for the same alias/column collision reason as the GROUP BY.
                $query->orderByRaw($g . ' ASC');
            }
        }
        // One extra row peeks past the page boundary → meta.page.has_more.
        $query->limit($paging['per_page'] + 1)->offset($paging['offset']);

        $rows         = $query->get();
        $fetched      = count($rows);
        $moneyMetrics = ['gross_revenue', 'paid_revenue', 'refunded_amount'];

        $out = [];
        foreach ($rows as $row) {
            if (count($out) >= $paging['per_page']) {
                break;
            }
            $r = [];
            foreach ($dimensions as $dim) {
                $r[$dim] = $row->{$dim};
            }
            foreach ($metrics as $m) {
                if ($m === 'aov') {
                    $g = (int) $row->gross_revenue;
                    $c = (int) $row->order_count;
                    $r['aov'] = MCPHelper::moneyCompact($c > 0 ? (int) round($g / $c) : 0);
                } elseif (in_array($m, $moneyMetrics, true)) {
                    $r[$m] = MCPHelper::moneyCompact((int) $row->{$m});
                } else {
                    $r[$m] = (int) $row->{$m};
                }
            }
            $out[] = $r;
        }

        $summary = sprintf(
            /* translators: 1: row count, 2: metric list, 3: dimension list */
            __('%1$d rows — metrics [%2$s] grouped by [%3$s].', 'fluent-cart'),
            count($out),
            implode(', ', $metrics),
            $dimensions ? implode(', ', $dimensions) : __('total', 'fluent-cart')
        );

        $meta = ['currency' => $currency, 'date_basis' => 'created_at', 'mode' => $mode];
        // Only when geography was actually asked for — an agent grouping by month
        // should not have to read a note about addresses.
        if ($wantsGeo) {
            $meta['geo_source'] = 'the order\'s own billing address, falling back to the buyer\'s primary billing address, then "unknown" for orders neither source can place. Rows always sum to the period total, so the "unknown" bucket shows exactly how much revenue is unattributed.';
        }

        return MCPHelper::envelope(
            $summary,
            ['metrics' => $metrics, 'dimensions' => $dimensions, 'range' => self::rangeBlock($range, $currency), 'rows' => $out],
            array_merge($meta, self::pageMeta($paging, $fetched))
        );
    }

    // -----------------------------------------------------------------
    // query-products / query-customers (flexible aggregates)
    // -----------------------------------------------------------------

    public static function queryProducts($params = [])
    {
        $currency   = self::currency($params);
        $range      = self::resolveRange($params);
        $mode       = self::orderMode($params);
        $metrics    = self::pickList($params, 'metrics', ['units_sold', 'line_revenue', 'list_price_sum', 'discount_amount', 'net_revenue', 'order_count', 'avg_unit_price', 'refund_amount'], ['units_sold', 'line_revenue']);
        $dimensions = self::pickList($params, 'dimensions', ['product', 'variation', 'order_type'], ['product']);

        $query = OrderItem::query()->whereHas('order', function ($q) use ($range, $currency, $mode) {
            $q->whereIn('payment_status', self::PAID)
                ->where('currency', $currency)
                ->where('created_at', '>=', $range['start'])
                ->where('created_at', '<=', $range['end']);
            self::applyMode($q, $mode);
        });

        // Entity filters on the line item itself: post_id is the product, object_id
        // the variation. These live on fct_order_items (the base model here), so a
        // plain where — no join — scopes the whole aggregate to one product/variation.
        // Without this, dimensions=[variation] grouped across the ENTIRE catalog even
        // when a product_id was passed (the param was silently dropped).
        if (!empty($params['product_id'])) {
            $query->where('post_id', (int) $params['product_id']);
        }
        if (!empty($params['variation_id'])) {
            $query->where('object_id', (int) $params['variation_id']);
        }

        // order_type lives on the parent order (fct_orders.type), not the line
        // item. Join orders only when it's requested so existing calls are
        // unchanged. order_id -> orders.id is many-to-one, so the join never fans
        // out line rows and the SUM()s stay identical to the ungrouped query.
        $groupByOrderType = in_array('order_type', $dimensions, true);
        if ($groupByOrderType) {
            $query->join('fct_orders as fctord', 'fct_order_items.order_id', '=', 'fctord.id');
        }

        $hasProduct   = in_array('product', $dimensions, true);
        $hasVariation = in_array('variation', $dimensions, true);

        $selects   = [];
        $groupCols = [];
        if ($hasProduct) {
            $selects[]   = 'post_id';
            $selects[]   = 'MAX(post_title) as product_title';
            $groupCols[] = 'post_id';
        }
        if ($hasVariation) {
            $selects[]   = 'object_id';
            $groupCols[] = 'object_id';
            // Make variation rows self-describing so the agent needs no follow-up
            // lookup: the variation's own stored title, plus the parent product's
            // id + name when we aren't already grouping by product. A variation
            // belongs to exactly one product, so MAX(post_id)/MAX(post_title) is
            // that single product's value per group — the join never fans out.
            $selects[] = 'MAX(title) as variation_label';
            if (!$hasProduct) {
                $selects[] = 'MAX(post_id) as vproduct_id';
                $selects[] = 'MAX(post_title) as product_title';
            }
        }
        if ($groupByOrderType) {
            // Reuse the order_type -> column mapping from query-orders rather than
            // hard-coding it again; qualify it with the join alias.
            $selects[]   = 'fctord.' . self::dimensionExpr('order_type') . ' as order_type';
            $groupCols[] = 'order_type';
        }

        // line_total = subtotal - discount_total on every item (see DiscountService/
        // CheckoutProcessor), so list_price_sum - line_revenue == discount_amount by
        // construction: margin leakage is the gap between what was listed and what
        // was charged, before refunds.
        //
        // Every item column below is qualified with the real (prefixed) items table.
        // When order_type is grouped, fct_orders is joined and columns that exist on
        // BOTH tables — subtotal is one — make a bare SUM(subtotal) throw SQL 1052
        // ("column is ambiguous"). selectRaw bypasses the grammar's table-prefixing,
        // so the literal prefixed name (not the bare `fct_order_items`) is required.
        // Qualifying all of them, not just subtotal, keeps a future column collision
        // (or a new metric) from silently reintroducing the crash.
        $itemsTable = App::db()->getTableName('fct_order_items');
        $metricSql = [
            'units_sold'      => 'SUM(' . $itemsTable . '.quantity) as units_sold',
            'line_revenue'    => 'SUM(' . $itemsTable . '.line_total) as line_revenue',
            'list_price_sum'  => 'SUM(' . $itemsTable . '.subtotal) as list_price_sum',
            'discount_amount' => 'SUM(' . $itemsTable . '.discount_total) as discount_amount',
            'net_revenue'     => 'SUM(' . $itemsTable . '.line_total - ' . $itemsTable . '.refund_total) as net_revenue',
            'order_count'     => 'COUNT(DISTINCT ' . $itemsTable . '.order_id) as order_count',
            'refund_amount'   => 'SUM(' . $itemsTable . '.refund_total) as refund_amount',
        ];
        foreach ($metrics as $m) {
            if (isset($metricSql[$m])) {
                $selects[] = $metricSql[$m];
            }
        }
        if (in_array('avg_unit_price', $metrics, true)) {
            if (!in_array('line_revenue', $metrics, true)) {
                $selects[] = $metricSql['line_revenue'];
            }
            if (!in_array('units_sold', $metrics, true)) {
                $selects[] = $metricSql['units_sold'];
            }
        }

        $query->selectRaw(implode(', ', $selects));
        foreach ($groupCols as $g) {
            $query->groupBy($g);
        }

        $firstMetric = isset($metrics[0]) ? $metrics[0] : 'line_revenue';
        if ($firstMetric === 'avg_unit_price') {
            $firstMetric = 'line_revenue';
        }
        $paging = self::queryPaging($params);
        if ($groupCols && isset($metricSql[$firstMetric])) {
            $query->orderBy($firstMetric, 'DESC');
            // Deterministic tie-break on the group key for stable offset paging.
            foreach ($groupCols as $g) {
                $query->orderBy($g, 'ASC');
            }
        }
        $query->limit($paging['per_page'] + 1)->offset($paging['offset']);

        $rows         = $query->get();
        $fetched      = count($rows);
        $moneyMetrics = ['line_revenue', 'list_price_sum', 'discount_amount', 'net_revenue', 'refund_amount', 'avg_unit_price'];

        $out = [];
        foreach ($rows as $row) {
            if (count($out) >= $paging['per_page']) {
                break;
            }
            $r = [];
            if ($hasProduct) {
                $r['product_id']    = (int) $row->post_id;
                $r['product_name']  = $row->product_title;
                // product_title kept as a backward-compatible alias of product_name.
                $r['product_title'] = $row->product_title;
            } elseif ($hasVariation) {
                $r['product_id']   = (int) $row->vproduct_id;
                $r['product_name'] = $row->product_title;
            }
            if ($hasVariation) {
                $r['variation_id']    = (int) $row->object_id;
                $r['variation_label'] = $row->variation_label;
            }
            if ($groupByOrderType) {
                $r['order_type'] = $row->order_type;
            }
            foreach ($metrics as $m) {
                if ($m === 'avg_unit_price') {
                    $u   = (int) $row->units_sold;
                    $rev = (int) $row->line_revenue;
                    $r['avg_unit_price'] = MCPHelper::moneyCompact($u > 0 ? (int) round($rev / $u) : 0);
                } elseif (in_array($m, $moneyMetrics, true)) {
                    $r[$m] = MCPHelper::moneyCompact((int) $row->{$m});
                } else {
                    $r[$m] = (int) $row->{$m};
                }
            }
            $out[] = $r;
        }

        return MCPHelper::envelope(
            sprintf(
                /* translators: 1: row count, 2: metric list */
                __('%1$d rows — product metrics [%2$s].', 'fluent-cart'),
                count($out),
                implode(', ', $metrics)
            ),
            ['metrics' => $metrics, 'dimensions' => $dimensions, 'range' => self::rangeBlock($range, $currency), 'rows' => $out],
            array_merge(['currency' => $currency, 'date_basis' => 'created_at', 'mode' => $mode], self::pageMeta($paging, $fetched))
        );
    }

    public static function queryCustomers($params = [])
    {
        $metrics    = self::pickList($params, 'metrics', ['customer_count', 'total_ltv', 'avg_ltv', 'avg_purchase_count', 'repeat_customers'], ['customer_count', 'total_ltv']);
        $dimensions = self::pickList($params, 'dimensions', ['country', 'state', 'status', 'first_purchase_month', 'last_purchase_month'], []);

        $query = Customer::query();

        // Geography resolves through the customer's primary BILLING ADDRESS,
        // falling back to the fct_customers profile columns.
        //
        // Neither source is complete on its own, which is the whole reason for the
        // coalesce. Measured on the 2,320-customer reference store: 2,176 customers
        // have both and the two NEVER disagree (zero rows where both are set and
        // differ), 96 have only the profile column, 7 have only an address row, and
        // 41 have neither. So reading either source alone silently drops customers
        // the other could place — profile-only would lose 7, address-only would lose
        // 96 — while the coalesce leaves just the 41 genuinely unplaceable.
        //
        // Billing address is ordered first because it is the value the buyer actually
        // typed, whereas the profile column is written once when the customer row is
        // created and never refreshed (and at checkout the seeded value can be a
        // country the frontend guessed from the browser timezone). On this store that
        // ordering changes no result; it is the correct precedence for the case where
        // a profile snapshot has gone stale.
        //
        // LEFT JOIN, so a customer with no address row is still counted (on the
        // profile value, or 'unknown'); an INNER JOIN would silently drop them
        // and quietly shrink every total. The join rides the existing
        // (customer_id, is_primary) index — measured at no material cost.
        //
        // Grouped subquery rather than a raw leftJoin: fct_customer_addresses has
        // no UNIQUE constraint on (customer_id, type, is_primary), so a second
        // primary billing row would fan the join out and double-count that
        // customer in COUNT(*) and SUM(ltv). Same guard the source report applies
        // to fct_order_operations above. MAX() is ONLY_FULL_GROUP_BY-safe and
        // returns the row's own value in the normal one-row case.
        // The subquery's columns are aliased addr_* on purpose: raw SQL fragments
        // are not table-prefixed by the builder, so a bare `country` in a
        // selectRaw would be ambiguous across the two tables and a qualified
        // `fct_customers.country` would miss the wp_ prefix. Distinct names keep
        // every raw expression unambiguous with no prefix handling at all.
        // latestRowPick, not MAX() per column: with more than one primary billing row
        // independent MAX()es can take country from one row and state from another and
        // report a place that does not exist. See the note in queryOrders.
        $addrSub = App::db()->table('fct_customer_addresses')
            ->select('customer_id')
            ->selectRaw(
                self::latestRowPick('country') . ' as addr_country, '
                . self::latestRowPick('state') . ' as addr_state'
            )
            ->where('type', 'billing')
            ->where('is_primary', 1)
            ->groupBy('customer_id');

        $query->leftJoinSub($addrSub, 'fc_addr', 'fct_customers.id', '=', 'fc_addr.customer_id');

        if (!empty($params['country'])) {
            $country = sanitize_text_field($params['country']);
            // Match on the resolved value, not the raw column, so the filter and
            // the grouping can never disagree about which country a customer is in.
            $query->whereRaw(
                "COALESCE(NULLIF(fc_addr.addr_country, ''), NULLIF(country, '')) = ?",
                [$country]
            );
        }
        // Unambiguous without qualification: the joined subquery exposes only
        // customer_id / addr_country / addr_state.
        if (!empty($params['status'])) {
            $query->where('status', sanitize_text_field($params['status']));
        }
        if (isset($params['min_ltv'])) {
            $query->where('ltv', '>=', Helper::toCent($params['min_ltv']));
        }
        if (isset($params['min_purchase_count'])) {
            $query->where('purchase_count', '>=', (int) $params['min_purchase_count']);
        }

        $selects   = [];
        $groupCols = [];
        foreach ($dimensions as $dim) {
            if ($dim === 'country' || $dim === 'state') {
                // Billing address first, profile column second, 'unknown' last.
                // Coalesce NULL and '' into the single 'unknown' bucket. Group by
                // the expression (not the alias, which would resolve to the raw
                // column and keep null/'' split).
                $expr        = "COALESCE(NULLIF(fc_addr.addr_$dim, ''), NULLIF($dim, ''), 'unknown')";
                $selects[]   = "$expr as $dim";
                $groupCols[] = $expr;
            } elseif ($dim === 'status') {
                // Same 'unknown' coalescing as country/state, for the same reason.
                // fct_customers.status is nullable with no default and 77 of the
                // 2,320 customers on the reference store have NULL or '' — so a bare
                // GROUP BY status answered "how many customers per status" with two
                // buckets the agent was never told about (null AND '', split apart),
                // neither of them in the status enum. One labelled bucket keeps the
                // rows summing to the customer total and makes the gap legible.
                $expr        = "COALESCE(NULLIF(status, ''), 'unknown')";
                $selects[]   = "$expr as status";
                $groupCols[] = $expr;
            } elseif ($dim === 'first_purchase_month') {
                $selects[]   = "DATE_FORMAT(first_purchase_date, '%Y-%m') as first_purchase_month";
                $groupCols[] = "DATE_FORMAT(first_purchase_date, '%Y-%m')";
            } elseif ($dim === 'last_purchase_month') {
                $selects[]   = "DATE_FORMAT(last_purchase_date, '%Y-%m') as last_purchase_month";
                $groupCols[] = "DATE_FORMAT(last_purchase_date, '%Y-%m')";
            }
        }

        $metricSql = [
            'customer_count'     => 'COUNT(*) as customer_count',
            'total_ltv'          => 'SUM(ltv) as total_ltv',
            'avg_ltv'            => 'AVG(ltv) as avg_ltv',
            'avg_purchase_count' => 'AVG(purchase_count) as avg_purchase_count',
            'repeat_customers'   => 'SUM(CASE WHEN purchase_count > 1 THEN 1 ELSE 0 END) as repeat_customers',
        ];
        foreach ($metrics as $m) {
            if (isset($metricSql[$m])) {
                $selects[] = $metricSql[$m];
            }
        }

        $query->selectRaw(implode(', ', $selects));
        if ($groupCols) {
            $query->groupByRaw(implode(', ', $groupCols));
        }

        $paging      = self::queryPaging($params);
        $firstMetric = isset($metrics[0]) ? $metrics[0] : 'customer_count';
        if ($groupCols && isset($metricSql[$firstMetric])) {
            $query->orderBy($firstMetric, 'DESC');
            // Deterministic tie-break on the grouped dimensions (their aliases)
            // so offset paging is stable across pages.
            foreach ($dimensions as $d) {
                $query->orderBy($d, 'ASC');
            }
        }
        $query->limit($paging['per_page'] + 1)->offset($paging['offset']);

        $rows         = $query->get();
        $fetched      = count($rows);
        $moneyMetrics = ['total_ltv', 'avg_ltv'];

        $out = [];
        foreach ($rows as $row) {
            if (count($out) >= $paging['per_page']) {
                break;
            }
            $r = [];
            foreach ($dimensions as $dim) {
                $r[$dim] = $row->{$dim};
            }
            foreach ($metrics as $m) {
                if (in_array($m, $moneyMetrics, true)) {
                    $r[$m] = MCPHelper::moneyCompact((int) round((float) $row->{$m}));
                } elseif ($m === 'avg_purchase_count') {
                    $r[$m] = round((float) $row->{$m}, 2);
                } else {
                    $r[$m] = (int) $row->{$m};
                }
            }
            $out[] = $r;
        }

        return MCPHelper::envelope(
            sprintf(
                /* translators: 1: row count, 2: metric list */
                __('%1$d rows — customer metrics [%2$s].', 'fluent-cart'),
                count($out),
                implode(', ', $metrics)
            ),
            ['metrics' => $metrics, 'dimensions' => $dimensions, 'rows' => $out],
            array_merge(
                [
                    'currency'       => MCPHelper::currencyCode(),
                    'note'           => 'LTV is in store currency; customers are not currency-scoped.',
                    'country_source' => 'primary billing address, falling back to the customer profile location, then "unknown". Registration geography, captured at first purchase — for per-order billing geography use query-orders grouped by country.',
                ],
                self::pageMeta($paging, $fetched)
            )
        );
    }

    // -----------------------------------------------------------------
    // query-subscriptions (flexible aggregate)
    // -----------------------------------------------------------------

    public static function querySubscriptions($params = [])
    {
        $range      = self::resolveRange($params);
        $dateBasis  = self::subDateBasis($params);
        $metrics    = self::pickList($params, 'metrics', ['subscription_count', 'contract_value', 'recurring_value'], ['subscription_count', 'contract_value']);
        $dimensions = self::pickList($params, 'dimensions', ['month', 'plan_type', 'status', 'billing_interval'], []);

        // Same UTC window resolution as every other report; the chosen date_basis
        // is the only thing that varies (signup cohort vs churn vs upcoming).
        $query = Subscription::query()
            ->where($dateBasis, '>=', $range['start'])
            ->where($dateBasis, '<=', $range['end']);

        if (!empty($params['product_id'])) {
            $query->where('product_id', (int) $params['product_id']);
        }
        if (!empty($params['status'])) {
            $query->where('status', sanitize_text_field($params['status']));
        }
        $planType = isset($params['plan_type']) && in_array($params['plan_type'], ['installment', 'recurring'], true) ? $params['plan_type'] : 'all';
        if ($planType !== 'all') {
            $query->ofPlanType($planType);
        }

        // plan_type is derived from bill_times with the SAME threshold as
        // Subscription::isInstallment(), so the SQL and PHP definitions agree.
        $planExpr = "CASE WHEN bill_times > 0 THEN 'installment' ELSE 'recurring' END";

        $selects   = [];
        $groupExpr = [];
        foreach ($dimensions as $dim) {
            if ($dim === 'month') {
                $expr = "DATE_FORMAT($dateBasis, '%Y-%m')";
            } elseif ($dim === 'plan_type') {
                $expr = $planExpr;
            } else {
                // status / billing_interval — plain columns.
                $expr = $dim;
            }
            $selects[]   = $expr . ' as ' . $dim;
            $groupExpr[] = $expr;
        }

        // contract_value is the SUM form of Subscription::totalContractValue()
        // (recurring_total * bill_times) — installments booked at full committed
        // value, 0 for open-ended plans. No parallel money math.
        $metricSql = [
            'subscription_count' => 'COUNT(*) as subscription_count',
            'contract_value'     => 'SUM(recurring_total * bill_times) as contract_value',
            'recurring_value'    => 'SUM(recurring_total) as recurring_value',
        ];
        foreach ($metrics as $m) {
            if (isset($metricSql[$m])) {
                $selects[] = $metricSql[$m];
            }
        }

        $query->selectRaw(implode(', ', $selects));
        if ($groupExpr) {
            $query->groupByRaw(implode(', ', $groupExpr));
        }

        $paging      = self::queryPaging($params);
        $firstMetric = isset($metrics[0]) ? $metrics[0] : 'subscription_count';
        if ($groupExpr && isset($metricSql[$firstMetric])) {
            $query->orderBy($firstMetric, 'DESC');
            // Deterministic tie-break on the grouped dimensions (their aliases).
            foreach ($dimensions as $d) {
                $query->orderBy($d, 'ASC');
            }
        }
        $query->limit($paging['per_page'] + 1)->offset($paging['offset']);

        $rows         = $query->get();
        $fetched      = count($rows);
        $moneyMetrics = ['contract_value', 'recurring_value'];

        $out = [];
        foreach ($rows as $row) {
            if (count($out) >= $paging['per_page']) {
                break;
            }
            $r = [];
            foreach ($dimensions as $dim) {
                $r[$dim] = $row->{$dim};
            }
            foreach ($metrics as $m) {
                if (in_array($m, $moneyMetrics, true)) {
                    $r[$m] = MCPHelper::moneyCompact((int) $row->{$m});
                } else {
                    $r[$m] = (int) $row->{$m};
                }
            }
            $out[] = $r;
        }

        return MCPHelper::envelope(
            sprintf(
                /* translators: 1: row count, 2: metric list, 3: dimension list */
                __('%1$d rows — subscription metrics [%2$s] grouped by [%3$s].', 'fluent-cart'),
                count($out),
                implode(', ', $metrics),
                $dimensions ? implode(', ', $dimensions) : __('total', 'fluent-cart')
            ),
            ['metrics' => $metrics, 'dimensions' => $dimensions, 'date_basis' => $dateBasis, 'range' => self::rangeBlock($range, MCPHelper::currencyCode()), 'rows' => $out],
            array_merge([
                'currency'   => MCPHelper::currencyCode(),
                'date_basis' => $dateBasis,
                'note'       => 'Money is in the store currency; subscriptions are not currency-scoped. contract_value books installments at recurring_total x bill_times; completed installments are paid-in-full, not churn.',
            ], self::pageMeta($paging, $fetched))
        );
    }

    private static function subDateBasis($params)
    {
        $allowed = ['created_at', 'canceled_at', 'next_billing_date'];

        return isset($params['date_basis']) && in_array($params['date_basis'], $allowed, true) ? $params['date_basis'] : 'created_at';
    }

    /**
     * Restrict an Order-model aggregate to orders CONTAINING a given product /
     * variation. Uses whereHas on order_items (post_id = product, object_id =
     * variation) so the filter is a subquery, not a join — order-level metrics stay
     * per-order and never fan out. Both filters combine (AND) when both are given.
     */
    private static function applyOrderItemFilter($query, $params)
    {
        foreach (['product_id' => 'post_id', 'variation_id' => 'object_id'] as $param => $col) {
            if (!empty($params[$param])) {
                $val = (int) $params[$param];
                $query->whereHas('order_items', function ($q) use ($col, $val) {
                    $q->where($col, $val);
                });
            }
        }
    }

    /**
     * Aggregate expression returning $column from the HIGHEST-id row in the group.
     *
     * Address tables have no UNIQUE constraint on (parent, type), so a group can hold
     * several rows and the newest is the one the buyer last entered. Two columns each
     * aggregated with a bare MAX() can come from two different rows and describe a
     * place that never existed (a real case: country from one row, state from another).
     *
     * Prefixing each value with its zero-padded id makes lexicographic MAX() agree
     * with numeric id order, so every column built this way resolves to the SAME row;
     * SUBSTRING then drops the prefix. The width is 20 because that is exactly the
     * digit count of the largest BIGINT UNSIGNED (18446744073709551615) — no id can
     * overflow the padding, so all prefixes are equal-length and compare numerically.
     * Ids are unique within a group (id is the PK), so the prefix alone always decides
     * the winner and the collation of the value suffix can never influence it.
     *
     * COALESCE(...,'') matters — CONCAT with NULL is NULL and MAX() skips NULLs, which
     * would let a NULL column fall back to a different row and reintroduce the mixing
     * this exists to prevent.
     *
     * Portable to MySQL 5.6+ (no window functions) and needs no nested join, unlike
     * ORDER BY ... LIMIT 1 or ROW_NUMBER().
     *
     * @param string $column trusted column name — never interpolate caller input here
     * @return string
     */
    private static function latestRowPick($column)
    {
        return "SUBSTRING(MAX(CONCAT(LPAD(id, 20, '0'), COALESCE($column, ''))), 21)";
    }

    private static function dimensionExpr($dim)
    {
        if ($dim === 'day') {
            return "DATE_FORMAT(created_at, '%Y-%m-%d')";
        }
        if ($dim === 'week') {
            return "DATE_FORMAT(created_at, '%x-W%v')";
        }
        if ($dim === 'month') {
            return "DATE_FORMAT(created_at, '%Y-%m')";
        }
        if ($dim === 'order_type') {
            // The order-type values (payment | renewal | subscription) live on the
            // fct_orders.type column; expose it under the order_type alias so the
            // dimension name and response key read naturally and don't collide
            // with the unrelated payment_type on line items.
            return 'type';
        }
        if ($dim === 'country' || $dim === 'state') {
            // ONE source per order, decided by whether the order has a billing
            // address row at all (fc_oaddr.order_id IS NULL) — never per column.
            //
            // Coalescing each column independently mixes provenance inside a single
            // order and invents places: order 16 on the reference store has its own
            // billing row (BG, '') while its buyer's address says (BG, BG-22), so a
            // per-column COALESCE reported that order's state as BG-22 — a value
            // from a mutable customer record, for an order that carries its own
            // address. 38 paid orders were affected. It is the same row-mixing
            // latestRowPick() prevents inside a table, reappearing across tables.
            //
            // Consequence, deliberately: an order whose own address has a country
            // but a blank state reports state 'unknown' rather than borrowing one.
            // That is the honest answer — that order's address genuinely has no
            // state — and it keeps historical attribution stable when a customer
            // later edits their address.
            //
            // Orders neither source can place get the 'unknown' bucket rather than
            // being dropped, so rows still sum to the period's total revenue and the
            // size of the gap stays visible.
            $pick = "CASE WHEN fc_oaddr.order_id IS NULL THEN fc_caddr.caddr_$dim ELSE fc_oaddr.oaddr_$dim END";

            return "COALESCE(NULLIF($pick, ''), 'unknown')";
        }
        return $dim;
    }

    // -----------------------------------------------------------------
    // shared helpers
    // -----------------------------------------------------------------

    private static function currency($params)
    {
        if (!empty($params['currency'])) {
            return strtoupper(sanitize_text_field($params['currency']));
        }
        return MCPHelper::currencyCode();
    }

    /**
     * Effective order-mode filter: 'live', 'test', or 'all'. Reports have always
     * counted BOTH live and test orders, so 'all' (the default) keeps existing
     * numbers unchanged; an agent opts into 'live' for clean revenue. Applied to
     * the fct_orders.mode column.
     */
    private static function orderMode($params)
    {
        $m = isset($params['mode']) ? strtolower(sanitize_text_field((string) $params['mode'])) : 'all';
        return in_array($m, ['live', 'test'], true) ? $m : 'all';
    }

    /**
     * Apply the mode filter to an Order query (or an order-relation subquery /
     * whereHas closure). 'all' is a no-op so existing numbers are unchanged. The
     * column is fct_orders.mode; pass a qualified name via $column when the orders
     * table is aliased (e.g. query-sources uses 'o.mode').
     */
    private static function applyMode($query, $mode, $column = 'mode')
    {
        if ($mode !== 'all') {
            $query->where($column, $mode);
        }
        return $query;
    }

    /**
     * Resolve range/start/end into a UTC window plus the prior equal-length
     * window. Relative ranges are computed in store timezone, expressed in UTC.
     *
     * Public so the single source of truth for MCP date-window resolution is
     * shared (e.g. list-transactions) instead of duplicated — every tool then
     * accepts the identical range vocabulary and UTC semantics.
     */
    public static function resolveRange($params)
    {
        // Resolve windows in UTC to match FluentCart's own admin reports, which
        // bucket on the GMT-stored created_at (DATE_FORMAT(created_at, ...)) with
        // no timezone conversion. Using store-local boundaries here would make a
        // local day straddle two UTC dates and emit an extra trailing bucket.
        $tz = new \DateTimeZone('UTC');

        // Delta mode: everything strictly after an instant, up to now. Time-precise
        // (not snapped to a day) so "what changed since 14:05" works during a launch.
        if (!empty($params['since'])) {
            $start = self::instant($params['since'], $tz, false);
            if ($start !== null) {
                return self::withPrior($start, gmdate('Y-m-d H:i:s'), 'since');
            }
        }

        // Time-precise custom window (ISO 8601). A time of day is kept; a date-only
        // value snaps to the day edge. Overrides range and the legacy start/end_date.
        if (!empty($params['date_from']) || !empty($params['date_to'])) {
            $start = self::instant(!empty($params['date_from']) ? $params['date_from'] : '-30 days', $tz, false);
            $end   = self::instant(!empty($params['date_to']) ? $params['date_to'] : 'now', $tz, true);
            if ($start !== null && $end !== null) {
                return self::withPrior($start, $end, 'custom');
            }
        }

        if (!empty($params['start_date']) || !empty($params['end_date'])) {
            $start = self::dayStart(!empty($params['start_date']) ? $params['start_date'] : '-30 days', $tz);
            $end   = self::dayEnd(!empty($params['end_date']) ? $params['end_date'] : 'now', $tz);
            return self::withPrior($start, $end, !empty($params['start_date']) ? 'custom' : 'last_30_days');
        }

        $range = isset($params['range']) && in_array($params['range'], self::RANGES, true) ? $params['range'] : 'last_30_days';

        // since_launch (alias: all_time): the store's first paid order to now.
        // Needs a DB read, so it sits here rather than in the pure calendar math
        // below. Falls back to the last 30 days if the store has no paid orders
        // yet. Both names resolve identically — for a paid-order-scoped report the
        // first paid order IS the start of all data — so an agent that learned
        // all_time from get-product-financials succeeds here too. The label echoes
        // whichever name was requested.
        if ($range === 'since_launch' || $range === 'all_time') {
            $launch = self::storeLaunchDate();
            $start  = $launch ? $launch : self::dayStart('-30 days', $tz);
            return self::withPrior($start, gmdate('Y-m-d H:i:s'), $range);
        }

        $now     = new \DateTime('now', $tz);
        $startDt = clone $now;
        $endDt   = clone $now;
        // Set for calendar-bounded ranges to force a calendar-aligned prior period.
        $prevStartDt = null;
        $prevEndDt   = null;

        if ($range === 'yesterday') {
            $startDt->modify('-1 day');
            $endDt->modify('-1 day');
        } elseif ($range === 'last_7_days') {
            $startDt->modify('-6 days');
        } elseif ($range === 'last_30_days') {
            $startDt->modify('-29 days');
        } elseif ($range === 'this_month' || $range === 'mtd') {
            $startDt = new \DateTime($now->format('Y-m-01'), $tz);
        } elseif ($range === 'last_month') {
            $startDt = new \DateTime($now->format('Y-m-01'), $tz);
            $startDt->modify('-1 month');
            $endDt = (clone $startDt)->modify('last day of this month');
            // Prior = the full calendar month before last month.
            $prevStartDt = (clone $startDt)->modify('-1 month');
            $prevEndDt   = (clone $prevStartDt)->modify('last day of this month');
        } elseif ($range === 'qtd') {
            $startDt = self::quarterStart($now, $tz);
        } elseif ($range === 'last_quarter') {
            $qs      = self::quarterStart($now, $tz);
            $startDt = (clone $qs)->modify('-3 months');
            $endDt   = (clone $qs)->modify('-1 day');
            // Prior = the full calendar quarter before last quarter.
            $prevStartDt = (clone $startDt)->modify('-3 months');
            $prevEndDt   = (clone $startDt)->modify('-1 day');
        } elseif ($range === 'ytd') {
            $startDt = new \DateTime($now->format('Y-01-01'), $tz);
        } elseif ($range === 'last_year') {
            $year    = (int) $now->format('Y') - 1;
            $startDt = new \DateTime($year . '-01-01', $tz);
            $endDt   = new \DateTime($year . '-12-31', $tz);
            // Prior = the full calendar year before last year.
            $prevStartDt = new \DateTime(($year - 1) . '-01-01', $tz);
            $prevEndDt   = new \DateTime(($year - 1) . '-12-31', $tz);
        }

        $start = self::dayStart($startDt->format('Y-m-d'), $tz);
        $end   = self::dayEnd($endDt->format('Y-m-d'), $tz);

        if ($prevStartDt !== null && $prevEndDt !== null) {
            return self::withPrior(
                $start,
                $end,
                $range,
                self::dayStart($prevStartDt->format('Y-m-d'), $tz),
                self::dayEnd($prevEndDt->format('Y-m-d'), $tz)
            );
        }

        return self::withPrior($start, $end, $range);
    }

    private static function quarterStart($now, $tz)
    {
        $month       = (int) $now->format('n');
        $qStartMonth = (int) (floor(($month - 1) / 3) * 3 + 1);
        return new \DateTime($now->format('Y') . '-' . str_pad($qStartMonth, 2, '0', STR_PAD_LEFT) . '-01', $tz);
    }

    private static function withPrior($startUtc, $endUtc, $label, $prevStartUtc = null, $prevEndUtc = null)
    {
        // Calendar-bounded ranges (last_month/last_quarter/last_year) pass an
        // explicit prior *calendar* period so a 31-day month isn't compared to a
        // 28-day second-count window. Other ranges fall back to an equal-length
        // block ending 1s before start (exact for fixed-length, rolling, custom).
        if ($prevStartUtc !== null && $prevEndUtc !== null) {
            return [
                'start'      => $startUtc,
                'end'        => $endUtc,
                'prev_start' => $prevStartUtc,
                'prev_end'   => $prevEndUtc,
                'label'      => $label,
            ];
        }

        $s         = new \DateTime($startUtc, new \DateTimeZone('UTC'));
        $e         = new \DateTime($endUtc, new \DateTimeZone('UTC'));
        $lengthSec = $e->getTimestamp() - $s->getTimestamp();

        $prevEnd   = (clone $s)->modify('-1 second');
        $prevStart = (clone $prevEnd)->modify('-' . ($lengthSec + 1) . ' seconds');

        return [
            'start'      => $startUtc,
            'end'        => $endUtc,
            'prev_start' => $prevStart->format('Y-m-d H:i:s'),
            'prev_end'   => $prevEnd->format('Y-m-d H:i:s'),
            'label'      => $label,
        ];
    }

    /**
     * Parse an ISO-8601 / relative value to a UTC 'Y-m-d H:i:s'. A date-only input
     * (YYYY-MM-DD) is snapped to the day start (or end when $isEnd); an explicit
     * time is preserved. Returns null on an unparseable value so the caller can
     * fall back to the next window source rather than silently matching all rows.
     */
    private static function instant($value, $tz, $isEnd = false)
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

    /**
     * The store's first paid order timestamp (UTC); null if the store has no paid
     * orders yet. Only hit on the since_launch path (one indexed min per call), so
     * it is not memoized — a static cache would leak across calls in a long-lived
     * process (e.g. the test runner) for no real per-request gain.
     */
    private static function storeLaunchDate()
    {
        $min = Order::query()->whereIn('payment_status', self::PAID)->min('created_at');
        return ($min && strpos((string) $min, '0000-00-00') !== 0) ? (string) $min : null;
    }

    private static function dayStart($value, $tz)
    {
        try {
            $dt = new \DateTime((string) $value, $tz);
        } catch (\Exception $e) {
            $dt = new \DateTime('now', $tz);
        }
        $dt->setTime(0, 0, 0);
        $dt->setTimezone(new \DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    }

    private static function dayEnd($value, $tz)
    {
        try {
            $dt = new \DateTime((string) $value, $tz);
        } catch (\Exception $e) {
            $dt = new \DateTime('now', $tz);
        }
        $dt->setTime(23, 59, 59);
        $dt->setTimezone(new \DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    }

    private static function rangeBlock($range, $currency)
    {
        return [
            'start'    => MCPHelper::toIso8601($range['start']),
            'end'      => MCPHelper::toIso8601($range['end']),
            'label'    => $range['label'],
            'currency' => $currency,
        ];
    }

    private static function pickList($params, $key, array $allowed, array $default)
    {
        if (empty($params[$key]) || !is_array($params[$key])) {
            return $default;
        }
        $out = [];
        foreach ($params[$key] as $v) {
            if (in_array($v, $allowed, true) && !in_array($v, $out, true)) {
                $out[] = $v;
            }
        }
        return $out ? $out : $default;
    }

    /**
     * Page/offset for the query-* aggregates. per_page defaults to and is clamped
     * at MAX_ROWS — grouped rows are compact but a single page still can't exceed
     * the context guardrail. Returns page, per_page and the row offset.
     */
    private static function queryPaging($params)
    {
        $page    = isset($params['page']) ? max(1, (int) $params['page']) : 1;
        $perPage = isset($params['per_page']) ? (int) $params['per_page'] : self::MAX_ROWS;
        if ($perPage < 1 || $perPage > self::MAX_ROWS) {
            $perPage = self::MAX_ROWS;
        }
        return ['page' => $page, 'per_page' => $perPage, 'offset' => ($page - 1) * $perPage];
    }

    /**
     * meta.page block for a query-* aggregate. The query fetches per_page + 1 rows
     * to peek past the page boundary; $fetchedCount is that raw count. `truncated`
     * is kept (never removed — additive API rule) and now means "more rows exist
     * beyond this page"; raise `page` to fetch them.
     */
    private static function pageMeta($paging, $fetchedCount)
    {
        $hasMore = $fetchedCount > $paging['per_page'];
        return [
            'page'      => [
                'current'  => $paging['page'],
                'per_page' => $paging['per_page'],
                'has_more' => $hasMore,
            ],
            'truncated' => $hasMore,
        ];
    }

    private static function pct($current, $prior)
    {
        if ($prior == 0) {
            return $current == 0 ? 0 : null;
        }
        return round((($current - $prior) / abs($prior)) * 100, 2);
    }
}

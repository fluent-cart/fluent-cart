<?php

namespace FluentCart\App\Modules\MCP\Tools;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\App;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\MCP\Support\MCPHelper;
use FluentCart\App\Modules\MCP\Support\PermissionGate;
use FluentCart\App\Services\Report\ProductReportService;
use FluentCart\App\Services\Report\InstallmentReportService;
use FluentCart\App\Services\Report\ReportHelper;
use FluentCart\App\Services\Report\RevenueReportService;
use FluentCart\App\Services\Report\RefundReportService;
use FluentCart\App\Services\Report\SourceReportService;
use FluentCart\App\Services\Report\CustomerReportService;
use FluentCart\App\Services\Report\CouponReportService;
use FluentCart\App\Services\Report\PaymentMethodReportService;
use FluentCart\App\Services\Report\SubscriptionReportService;
use FluentCart\App\Modules\MultiCurrency\Services\BaseCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Helpers\Status;

/**
 * Reports & analytics — the headline research surface.
 *
 * Design rules baked in:
 *  - Every report is CURRENCY-SCOPED: it filters to one currency (the store
 *    default unless `currency` is passed) so totals are never silently summed
 *    across currencies. The chosen currency is echoed in meta.currency.
 *  - "Revenue" definitions are explicit and consistent across tools (see
 *    metricDefs), as the admin Revenue report counts them: gross sales, net
 *    sales; paid orders = payment_status in the paid set.
 *  - Date basis is created_at (echoed as meta.date_basis) for determinism.
 *  - Server-side aggregation only — no raw row dumps. query-orders caps at 200
 *    grouped rows; trend caps its bucket count.
 *  - Each report returns an NL summary the agent can quote verbatim.
 *
 * Parameter design: a shared `range` enum (today … last_year) resolves to a
 * store-timezone window server-side, with explicit start_date/end_date as an override —
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

    // Periods or cohorts a report keeps, the latest first: two years of months
    const MAX_PERIODS = 24;

    public static function definitions()
    {
        // Each schema says enough to call the action from tools/list alone: some MCP
        // clients never show the server instructions to the model.
        $rangeProp = ['type' => 'string', 'enum' => self::RANGES, 'description' => 'Preset window in store days. Default last_30_days.'];
        $dateFrom  = ['type' => 'string', 'description' => 'Window start: ISO 8601 or YYYY-MM-DD, store timezone unless it has an offset. With date_to; overrides range.'];
        $startDate = ['type' => 'string', 'description' => 'YYYY-MM-DD store day, inclusive, with end_date. Overrides range.'];
        $endDate   = ['type' => 'string', 'description' => 'YYYY-MM-DD store day, inclusive.'];
        $dateTo    = ['type' => 'string', 'description' => 'Window end, same format as date_from.'];
        $since     = ['type' => 'string', 'description' => 'ISO 8601 instant: only records after it, up to now. Overrides the other window parameters.'];

        // Live/test order scoping. Reports historically counted BOTH, so 'all' is
        // the non-breaking default; pass 'live' to exclude test-mode orders from
        // revenue. The effective mode is always echoed as meta.mode so a number
        // is never silently polluted by test orders.
        $modeProp = ['type' => 'string', 'enum' => ['live', 'test', 'all'], 'default' => 'all', 'description' => 'Live orders, test orders or both. Default all.'];

        // Pagination for the flexible query-* aggregates: when a grouping produces
        // more than per_page groups the response sets meta.page.has_more; raise
        // page to walk the rest instead of only being able to narrow the window.
        $pageProp    = ['type' => 'integer', 'default' => 1, 'description' => '1-based page over the grouped rows. Use with meta.page.has_more to page past the per_page cap.'];
        $perPageProp = ['type' => 'integer', 'default' => 200, 'description' => 'Grouped rows per page. Max 200.'];


        $subStatuses = ContextTools::enums()['subscription_statuses'];

        $defs = [
            'fluent-cart/get-sales-report' => [
                'label'       => 'Get Sales Report',
                'description' => 'The admin Revenue report for a period, compared with the prior period. Net and gross sales, discounts, refunds, tax, total collected, orders and AOV. Also shipping, fees and unique customers.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'range'      => $rangeProp,
                        'start_date' => $startDate,
                        'end_date'   => $endDate,
                        'date_from'  => $dateFrom,
                        'date_to'    => $dateTo,
                        'since'      => $since,
                        'currency'   => ['type' => 'string', 'description' => 'ISO 4217 code, default the store currency. __all__ converts every currency to it.'],
                        'compare'    => ['type' => 'boolean', 'default' => true, 'description' => 'Include prior-period comparison.'],
                        'needs_attention' => ['type' => 'boolean', 'description' => 'Adds data.needs_attention: failing subscriptions, high dispute rate, overdue installments, a refund rate jump. Slower.'],
                        'interval'   => ['type' => 'string', 'enum' => ['hour', 'day', 'week', 'month'], 'description' => 'Adds data.trend: paid orders and revenue per bucket (hour: at most 180 buckets).'],
                    ],
                ],
                'execute_callback'    => [self::class, 'getSalesReport'],
                'permission_callback' => function () {
                    return PermissionGate::can('reports/view');
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/get-refund-report' => [
                'label'       => 'Get Refund Report',
                'description' => 'The admin Refunds report for a period: amounts, rates, reasons and days to refund. Also full vs partial, by sale type and payment method. Refunds are those made in the window, on orders of any date; the refund rate is of the orders placed in the window.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'range'      => $rangeProp,
                        'start_date' => $startDate,
                        'end_date'   => $endDate,
                        'date_from'  => $dateFrom,
                        'date_to'    => $dateTo,
                        'since'      => $since,
                        'currency'   => ['type' => 'string', 'description' => 'ISO 4217 code, default the store currency. __all__ converts every currency to it.'],
                    ],
                ],
                'execute_callback'    => [self::class, 'getRefundReport'],
                'permission_callback' => function () {
                    return PermissionGate::can('reports/view');
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/query-sources' => [
                'label'       => 'Query Sources (UTM attribution)',
                'description' => 'The admin Sources report: sales and refunds per UTM grouping or referrer. Orders, gross and net sales, refunds and refund rate; groupings are campaign, source, medium and more. Also first-order vs later net; spellings are merged and no-UTM orders are in a (none) row. view=channels gives new customers won per channel with first-order net, LTV and repeat rate. Optional UTM and product filters.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'group_by'     => ['type' => 'string', 'enum' => array_merge(array_keys(SourceReportService::UTM_GROUPS), SourceReportService::OTHER_GROUPS), 'default' => SourceReportService::DEFAULT_GROUP, 'description' => 'product, variation, day, week, month and referrer need FluentCart Pro.'],
                        'view'         => ['type' => 'string', 'enum' => ['sources', 'channels'], 'default' => 'sources'],
                        'metrics'      => ['type' => 'array', 'description' => 'Ignored: rows carry every figure.', 'items' => ['type' => 'string']],
                        'dimensions'   => ['type' => 'array', 'description' => 'Older form of group_by: UTM fields that match a grouping.', 'items' => ['type' => 'string', 'enum' => self::UTM_DIMENSIONS]],
                        'utm_source'   => ['type' => 'string', 'description' => 'Filter to one source, exact match.'],
                        'utm_medium'   => ['type' => 'string', 'description' => 'Filter to one medium, exact match.'],
                        'utm_campaign' => ['type' => 'string', 'description' => 'Filter to one campaign, exact match.'],
                        'product_id'   => ['type' => 'integer', 'description' => 'Limit attribution to orders containing this product (e.g. one product on a multi-product store).'],
                        'variation_id' => ['type' => 'integer', 'description' => 'Limit attribution to orders containing this variation.'],
                        'range'        => $rangeProp,
                        'start_date'   => $startDate,
                        'end_date'     => $endDate,
                        'date_from'    => $dateFrom,
                        'date_to'      => $dateTo,
                        'since'        => $since,
                        'currency'     => ['type' => 'string', 'description' => 'ISO 4217 code, default the store currency. __all__ converts every currency to it.'],
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
                'label'       => 'Query Orders (flexible aggregate)',
                'description' => 'Order analytics: pick metrics and dimensions when a fixed report does not fit. Group by payment_status, month, order_type, country or state, with filters. order_type is one-time payment, new subscription or renewal. Counts orders that captured payment only (paid, partially_paid, partially_refunded, refunded; echoed as meta.payment_statuses): pending and failed orders are left out, and a payment_status grouping says how many in meta.warnings. product_id or variation_id limits to orders containing that product. This is the tool for revenue BY GEOGRAPHY: group by country/state, which read each order\'s own billing address, falling back to the buyer\'s primary billing address. Window on created_at.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'limit'      => ['type' => 'integer', 'description' => 'view=coupons: rows to return (default 25, max 200).'],
                        'view'       => ['type' => 'string', 'enum' => ['query', 'payment_methods', 'coupons'], 'default' => 'query', 'description' => 'payment_methods / coupons: the admin Payment Methods or Coupons report for the range (sales, success and decline rates per gateway; uses, discount and net per coupon). Coupon customer value needs Pro. coupons returns the top 25 unless limit is set. mode applies to coupons only.'],
                        'metrics'    => [
                            'type'        => 'array',
                            'description' => 'One or more. Defaults to order_count and gross_revenue. gross_revenue = item sales before discounts and refunds (gross_sales in get-sales-report); paid_revenue = payments captured, after discounts, with tax and shipping, before refunds; refunded_amount = refunds on these orders; aov = net sales / orders (aov in get-sales-report).',
                            'items'       => ['type' => 'string', 'enum' => ['order_count', 'gross_revenue', 'paid_revenue', 'refunded_amount', 'aov', 'unique_customers']],
                        ],
                        'dimensions' => [
                            'type'        => 'array',
                            'description' => 'Group by these. Empty means a single total row. order_type splits sales by payment (one-time purchase), subscription (first subscription order) and renewal (recurring charge); combine with a time dimension for e.g. order_type x month. country/state come from each order\'s own billing address, falling back to the buyer\'s primary billing address when the order has no address row of its own (common for digital goods) — this is the right basis for revenue by geography. Orders neither source can place land in an explicit "unknown" bucket so the rows still sum to total revenue; see meta.geo_source. state needs FluentCart Pro.',
                            'items'       => ['type' => 'string', 'enum' => ['day', 'week', 'month', 'status', 'payment_status', 'order_type', 'country', 'state']],
                        ],
                        'country'      => ['type' => 'string', 'description' => 'ISO-2 code. Limit to orders billed to this country, resolved the same way as the country dimension (order address, then the buyer\'s primary billing address).'],
                        'product_id'   => ['type' => 'integer', 'description' => 'Limit to orders CONTAINING this product. Order-level metrics (revenue, count) reflect the whole order, not just this product\'s lines — for per-product line revenue use query-products.'],
                        'variation_id' => ['type' => 'integer', 'description' => 'Limit to orders containing this variation.'],
                        'range'      => $rangeProp,
                        'start_date' => $startDate,
                        'end_date'   => $endDate,
                        'date_from'  => $dateFrom,
                        'date_to'    => $dateTo,
                        'since'      => $since,
                        'currency'   => ['type' => 'string', 'description' => 'ISO 4217 code, default the store currency.'],
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
                'label'       => 'Query Products (flexible aggregate)',
                'description' => 'Product analytics over sold items, grouped by product or variation. Pick metrics; an optional split by order_type. order_type is one-time payment, new subscription or renewal; one period and one currency. Rows self-describe: product_name always, plus variation_label when grouped by variation, so no follow-up lookup. For a time series use get-sales-report with interval. Window on the parent order created_at.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'metrics'    => ['type' => 'array', 'description' => 'Defaults to units_sold and line_revenue. Same figures as the admin Products page: net_revenue = each order\'s payment less refunds and kept tax, shared over its lines by line amount; refund_amount = the shared refunds, tax included. line_revenue = line price after discounts and order credits; list_price_sum = units x list price; discount_amount = list_price_sum - line_revenue. Units leave out signup-fee and fee lines.', 'items' => ['type' => 'string', 'enum' => ['units_sold', 'line_revenue', 'list_price_sum', 'discount_amount', 'net_revenue', 'order_count', 'avg_unit_price', 'refund_amount']]],
                        'dimensions' => ['type' => 'array', 'description' => 'Group by these. order_type splits a product\'s sales by the parent order type: payment (one-time purchase), subscription (first subscription order) and renewal (recurring charge). Combine with product/variation, e.g. product x order_type.', 'items' => ['type' => 'string', 'enum' => ['product', 'variation', 'order_type']]],
                        'product_id'   => ['type' => 'integer', 'description' => 'Limit to one product (by product/post id). Combine with dimensions=[variation] to break that single product down by variation.'],
                        'variation_id' => ['type' => 'integer', 'description' => 'Limit to one variation of the product.'],
                        'range'      => $rangeProp,
                        'start_date' => $startDate,
                        'end_date'   => $endDate,
                        'date_from'  => $dateFrom,
                        'date_to'    => $dateTo,
                        'since'      => $since,
                        'currency'   => ['type' => 'string', 'description' => 'ISO 4217 code, default the store currency.'],
                    ],
                ],
                'execute_callback'    => [self::class, 'queryProducts'],
                'permission_callback' => function () {
                    return PermissionGate::can('reports/view');
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/query-customers' => [
                'label'       => 'Query Customers (flexible aggregate)',
                'description' => 'Customer analytics: pick metrics and group by country, state, status or purchase month. Purchase month is the first or the last one; filters are optional. LTV is in store currency. Returns up to 200 rows. country/state resolve from the customer\'s primary billing address, falling back to their profile location, then "unknown" — this is where a customer is REGISTERED, captured at their first purchase and not refreshed since. To attribute revenue to where each order was actually billed, group query-orders by country instead.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'view'               => ['type' => 'string', 'enum' => ['query', 'overview'], 'default' => 'query', 'description' => 'overview: the admin Customers overview for a date range (new vs returning vs renewing, their net, repeat rate, average LTV); takes range/dates and currency.'],
                        'range'              => $rangeProp,
                        'start_date'         => ['type' => 'string', 'description' => 'overview only.'],
                        'end_date'           => ['type' => 'string', 'description' => 'overview only.'],
                        'currency'           => ['type' => 'string', 'description' => 'overview only. ISO 4217 code, default the store currency; __all__ converts every currency to it.'],
                        'metrics'            => ['type' => 'array', 'description' => 'Defaults to customer_count and total_ltv.', 'items' => ['type' => 'string', 'enum' => ['customer_count', 'total_ltv', 'avg_ltv', 'avg_purchase_count', 'repeat_customers']]],
                        'dimensions'         => ['type' => 'array', 'description' => 'state needs FluentCart Pro.', 'items' => ['type' => 'string', 'enum' => ['country', 'state', 'status', 'first_purchase_month', 'last_purchase_month']]],
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
                'label'       => 'Query Subscriptions (flexible aggregate)',
                'description' => 'Subscription analytics: MRR and churn (view=overview), or counts and contract value by group. view=overview is the admin Subscriptions page. Overview: MRR now, active/canceling/failing/paused, and the period\'s new, churned, churn rate, MRR change and renewals. Default view: grouped by month/plan_type/status/billing_interval; date_basis=next_billing_date lists upcoming renewals.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'view'       => ['type' => 'string', 'enum' => ['query', 'overview'], 'default' => 'query'],
                        'currency'   => ['type' => 'string', 'description' => 'overview only. ISO 4217 code, default the store currency; __all__ converts every currency to it.'],
                        'metrics'    => ['type' => 'array', 'description' => 'Defaults to subscription_count and contract_value. contract_value = full committed price of installments, recurring_total x bill_times, and 0 for open-ended plans; recurring_value = one-cycle total.', 'items' => ['type' => 'string', 'enum' => ['subscription_count', 'contract_value', 'recurring_value']]],
                        'dimensions' => ['type' => 'array', 'description' => 'Group by these. Empty means a single total row. plan_type splits installment vs recurring; status keeps completed installments separate from canceled/expired churn.', 'items' => ['type' => 'string', 'enum' => ['month', 'plan_type', 'status', 'billing_interval']]],
                        'date_basis' => ['type' => 'string', 'enum' => ['created_at', 'canceled_at', 'next_billing_date'], 'default' => 'created_at', 'description' => 'Which date the range and the month dimension use: created_at for signups, canceled_at for cancellations (upgrades included; churn is in view=overview), next_billing_date for upcoming renewals.'],
                        'plan_type'  => ['type' => 'string', 'enum' => ['installment', 'recurring', 'all'], 'default' => 'all', 'description' => 'Filter to installment (bill_times > 0) or recurring (bill_times = 0) plans.'],
                        'status'     => ['type' => 'string', 'enum' => $subStatuses],
                        'product_id' => ['type' => 'integer', 'description' => 'Limit to subscriptions for one product.'],
                        'range'      => $rangeProp,
                        'start_date' => $startDate,
                        'end_date'   => $endDate,
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

            'fluent-cart/get-report' => [
                'label'       => 'Get Report',
                'description' => 'Three fixed admin reports: installments, subscription retention and customer cohorts. report=installments: health of split-pay plans (collected, scheduled, overdue, lapsed, completion rate, at-risk plans). report=retention: subscription retention and churn per month or year over the range. report=cohorts: customer cohorts by signup year or month, read from the retention snapshots. Pro parts (installment schedule, MRR cohorts) need FluentCart Pro.',
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['report'],
                    'properties' => [
                        'report'           => ['type' => 'string', 'enum' => ['installments', 'retention', 'cohorts']],
                        'currency'         => ['type' => 'string', 'description' => 'ISO 4217 code, default the store currency. __all__ (installments, retention) converts every currency to it.'],
                        'range'            => $rangeProp,
                        'start_date'       => ['type' => 'string', 'description' => 'retention only.'],
                        'end_date'         => ['type' => 'string', 'description' => 'retention only.'],
                        'period'           => ['type' => 'string', 'enum' => ['month', 'year'], 'description' => 'retention: rows per month (default) or year; the latest 24 are kept.'],
                        'group_by'         => ['type' => 'string', 'enum' => ['year', 'month'], 'description' => 'cohorts: yearly (default) or monthly cohorts; the latest 24 are kept, a monthly cohort with its first 12 months.'],
                        'metric'           => ['type' => 'string', 'enum' => ['subscribers', 'mrr'], 'description' => 'cohorts: mrr needs Pro.'],
                        'billing_interval' => ['type' => 'string', 'description' => 'retention; cohorts with Pro: one billing interval.'],
                        'rebuy_adjusted'   => ['type' => 'boolean', 'description' => 'cohorts with Pro: count a rebuy as retained.'],
                        'variation_ids'    => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'installments: limit to these variations.'],
                        'as_of'            => ['type' => 'string', 'description' => 'installments: YYYY-MM-DD. The day the schedule counts from. Defaults to today.'],
                        'started_from'     => ['type' => 'string', 'description' => 'installments: plans first paid on or after this day.'],
                        'started_to'       => ['type' => 'string', 'description' => 'installments: plans first paid on or before this day.'],
                        'at_risk_limit'    => ['type' => 'integer', 'default' => 50, 'description' => 'installments: max at-risk rows. Max 500.'],
                    ],
                ],
                'execute_callback'    => [self::class, 'getReport'],
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

        return $defs;
    }

    // -----------------------------------------------------------------
    // get-sales-report
    // -----------------------------------------------------------------

    public static function getSalesReport($params = [])
    {
        // One currency, or every currency converted to the base one ("__all__"), as on the Revenue page
        $requested = self::convertibleCurrency($params);
        if (is_wp_error($requested)) {
            return $requested;
        }
        $currency  = ReportCurrency::isConverted(['currency' => $requested]) ? BaseCurrency::get() : $requested;
        $range     = self::resolveRange($params);
        if (is_wp_error($range)) {
            return $range;
        }
        $mode      = self::orderMode($params);

        $current = self::salesMetrics($range['start'], $range['end'], $requested, $mode);

        $data = [
            'range'       => self::rangeBlock($range, $currency),
            'metrics'     => self::salesMetricsOut($current, $currency),
            'definitions' => self::metricDefs(),
        ];

        $compare = !isset($params['compare']) || !empty($params['compare']);
        if ($compare && $range['prev_start']) {
            $prior = self::salesMetrics($range['prev_start'], $range['prev_end'], $requested, $mode);
            $data['comparison'] = [
                'prior_metrics'  => self::salesMetricsOut($prior, $currency),
                'change_percent' => [
                    'net_sales'     => self::pct($current['net_sales'], $prior['net_sales']),
                    'gross_revenue' => self::pct($current['gross_sales'], $prior['gross_sales']),
                    'net_revenue'   => self::pct($current['net_revenue'], $prior['net_revenue']),
                    'order_count'   => self::pct($current['orders'], $prior['orders']),
                ],
            ];
        }

        $summary = sprintf(
            'Net sales %1$s across %2$d paid orders, AOV %3$s.',
            MCPHelper::displayAmount($current['net_sales'], $currency),
            $current['orders'],
            MCPHelper::displayAmount($current['aov'], $currency)
        );

        if (!empty($params['interval']) && $requested !== ReportCurrency::ALL) {
            $trend = self::getSalesTrend($params);
            $data['trend'] = $trend['data']['trend'];
            // An hourly trend stops at MAX_BUCKETS; say so, as the standalone trend did
            $data['trend_truncated'] = $trend['meta']['truncated'];
        }
        if (!empty($params['needs_attention'])) {
            $data['needs_attention'] = self::needsAttention($range, $requested);
        }

        return MCPHelper::envelope($summary, $data, ['currency' => $currency, 'converted' => $requested === ReportCurrency::ALL, 'date_basis' => 'created_at', 'mode' => $mode]);
    }

    /**
     * The admin Overview's Needs Attention card, by its rules: failing subscriptions, a dispute rate above the card
     * networks' 0.75% monitoring line, installment plans with a failed payment, and a refund rate that rose at least
     * 2 points and half again against the prior period. Amounts in currency units.
     */
    private static function needsAttention(array $range, $currency)
    {
        $utc    = new \DateTimeZone('UTC');
        $window = function ($start, $end) use ($utc, $currency) {
            return ['startDate' => new DateTime($start, $utc), 'endDate' => new DateTime($end, $utc), 'currency' => $currency,
                'paymentStatus' => Status::getReportStatuses(), 'groupKey' => 'monthly'];
        };
        $filters = $window($range['start'], $range['end']);
        $items   = [];

        // The Overview card's short health cache, same key: health does not follow the date range
        $failing = ReportHelper::remember('health', [$currency, [], ''], function () use ($filters) {
            return SubscriptionReportService::make()->getHealth($filters);
        })['failing'];
        if ($failing['count']) {
            $items[] = ['key' => 'failing', 'count' => $failing['count'], 'mrr_at_risk' => $failing['mrr']];
        }

        $disputeRate = (float) Arr::get(PaymentMethodReportService::make()->getReport($filters), 'totals.dispute_rate', 0);
        if ($disputeRate > 0.75) {
            $items[] = ['key' => 'disputes', 'dispute_rate' => $disputeRate];
        }

        $installments = InstallmentReportService::make()->getReport(['currency' => $currency])['summary'];
        if (!empty($installments['overdue_count'])) {
            $items[] = ['key' => 'installments', 'count' => $installments['overdue_count'], 'owed' => MCPHelper::moneyCompact($installments['overdue'])];
        }

        $refunds = RefundReportService::make();
        $now     = round((float) $refunds->getOverview(['withProducts' => false] + $filters)['summary']['refund_rate_cohort'], 2);
        $before  = $refunds->getOverview(['withProducts' => false] + $window($range['prev_start'], $range['prev_end']))['summary']['refund_rate_cohort'];
        if ($before !== null && $now - round($before, 2) >= 2 && $now >= round($before, 2) * 1.5) {
            $items[] = ['key' => 'refunds', 'refund_rate' => $now, 'prior_refund_rate' => round($before, 2)];
        }

        return $items;
    }

    /**
     * The Revenue report's summary for a window, in cents.
     */
    private static function salesMetrics($start, $end, $currency, $mode = 'all')
    {
        $utc    = new \DateTimeZone('UTC');
        $params = [
            'startDate'     => new DateTime($start, $utc),
            'endDate'       => new DateTime($end, $utc),
            'currency'      => $currency,
            'paymentStatus' => Status::getReportStatuses(),
            'paymentMode'   => $mode === 'all' ? null : $mode,
            'groupKey'      => 'yearly',
        ];
        $s = RevenueReportService::make()->getRevenueData($params)['summary'];

        $unique = ReportCurrency::ordersTable($params)
            ->whereBetween('o.created_at', [$start, $end])
            ->whereIn('o.payment_status', $params['paymentStatus'])
            ->when($currency !== ReportCurrency::ALL, function ($q) use ($currency) {
                $q->where('o.currency', $currency);
            })
            ->when($mode !== 'all', function ($q) use ($mode) {
                $q->where('o.mode', $mode);
            })
            ->distinct()
            ->count('o.customer_id');

        $orders = (int) $s['order_count'];
        $paid   = Helper::toCent($s['gross_sale']);

        return [
            'orders'          => $orders,
            'unique'          => (int) $unique,
            'net_sales'       => Helper::toCent($s['net_sales']),
            'gross_sales'     => Helper::toCent($s['gross_sales']),
            'discounts'       => Helper::toCent($s['discounts']),
            'sales_refunded'  => Helper::toCent($s['refunds']),
            'net_revenue'     => Helper::toCent($s['net_revenue']),
            'total_collected' => Helper::toCent($s['total_collected']),
            'paid'            => $paid,
            'refund'          => Helper::toCent($s['total_refunded_amount']),
            'refunded_orders' => (int) $s['refunded_orders'],
            'tax'             => Helper::toCent($s['taxes_net']),
            'shipping'        => Helper::toCent($s['shipping_net']),
            'fees'            => Helper::toCent($s['fees']),
            // As the Orders report's Average Order Value (Net)
            'aov'             => $orders > 0 ? (int) round(Helper::toCent($s['net_sales']) / $orders) : 0,
        ];
    }

    private static function salesMetricsOut($m, $currency)
    {
        $out = [
            'order_count'      => $m['orders'],
            'unique_customers' => $m['unique'],
            'refunded_orders'  => $m['refunded_orders'],
            // gross_revenue stays as the old name of gross_sales
            'gross_revenue'    => $m['gross_sales'],
        ];
        foreach (['net_sales', 'gross_sales', 'discounts', 'sales_refunded', 'net_revenue', 'total_collected', 'paid', 'tax', 'shipping', 'fees', 'aov'] as $key) {
            $out[$key] = $m[$key];
        }
        $out['refunded'] = $m['refund'];

        foreach ($out as $key => $value) {
            if (!in_array($key, ['order_count', 'unique_customers', 'refunded_orders'], true)) {
                $out[$key] = MCPHelper::money($value, $currency);
            }
        }

        return $out;
    }

    private static function metricDefs()
    {
        return [
            'source'          => 'The admin Revenue report (Reports > Revenue), same window, currency and paid orders.',
            'net_sales'       => 'Item sales after discounts and refunds, without tax, shipping and fees.',
            'gross_sales'     => 'net_sales + discounts + sales_refunded: item sales before discounts and refunds. gross_revenue is the same value. Not the gross_sales of query-sources or query-orders view=payment_methods, which is payments captured (paid here).',
            'net_revenue'     => 'paid - refunded - tax kept.',
            'total_collected' => 'net_sales + tax + shipping + fees that were kept.',
            'paid'            => 'Sum of what the paid orders captured, after discounts, with tax, shipping and fees, before refunds.',
            'refunded'        => 'All refunds on these orders, by order date.',
            'aov'             => 'net_sales / order_count, as query-orders aov. average_order in query-sources and view=payment_methods is paid / orders, so it is higher.',
        ];
    }

    // -----------------------------------------------------------------
    // sales trend (get-sales-report interval)
    // -----------------------------------------------------------------

    public static function getSalesTrend($params = [])
    {
        $currency = self::currency($params);
        if (is_wp_error($currency)) {
            return $currency;
        }
        $range    = self::resolveRange($params);
        if (is_wp_error($range)) {
            return $range;
        }
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
        self::joinOrderSales($q, $range);
        $rows = $q->selectRaw('DATE_FORMAT(' . self::localSql('created_at', $range) . ', ?) as bucket, COUNT(*) as order_count, SUM(fc_sales.sales_gross) as gross, '
                . 'SUM(fc_sales.sales_net) as net_sales, SUM(total_paid) as paid, SUM(' . ReportHelper::netRevenueSql('') . ') as net_revenue', [$format])
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
                // paid and net_revenue add up to the sales report's figures of the same name
                'net_sales'   => MCPHelper::moneyCompact((int) $row->net_sales),
                'paid'        => MCPHelper::moneyCompact((int) $row->paid),
                'net_revenue' => MCPHelper::moneyCompact((int) $row->net_revenue),
            ];
        }

        $summary = sprintf(
            '%1$d %2$s buckets, total revenue %3$s.',
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

    /**
     * Joins each order's net and gross sales (cents), as the admin Revenue report counts them, as
     * fc_sales.sales_net and fc_sales.sales_gross. Gross sales = net sales + discounts + refunded sales.
     */
    private static function joinOrderSales($query, array $range)
    {
        $params = ['startDate' => $range['start'], 'endDate' => $range['end']];
        $split = ReportHelper::refundSplitSql($params);
        $sales = ReportHelper::joinShippingRefund(App::db()->table('fct_orders as o'), $params)
            ->whereBetween('o.created_at', [$range['start'], $range['end']])
            ->selectRaw("o.id AS sales_order_id, {$split['net_sales']} AS sales_net,
                {$split['net_sales']} + o.coupon_discount_total + o.manual_discount_total + {$split['sales_refunded']} AS sales_gross");

        return $query->leftJoinSub($sales, 'fc_sales', 'fct_orders.id', '=', 'fc_sales.sales_order_id');
    }

    // -----------------------------------------------------------------
    // top products (a query-products call)
    // -----------------------------------------------------------------

    public static function getTopProducts($params = [])
    {
        $metric = isset($params['metric']) && $params['metric'] === 'units' ? 'units' : 'revenue';
        $limit  = isset($params['limit']) ? min(max((int) $params['limit'], 1), 50) : 10;
        $first  = $metric === 'units' ? 'units_sold' : 'net_revenue';

        // Same rows as query-products, so both give the Products page's figures
        $res = self::queryProducts(array_merge($params, [
            'metrics'    => [$first, $first === 'units_sold' ? 'net_revenue' : 'units_sold', 'order_count'],
            'dimensions' => ['product'],
            'per_page'   => $limit,
            'page'       => 1,
        ]));
        if (is_wp_error($res)) {
            return $res;
        }

        $products = [];
        foreach ($res['data']['rows'] as $row) {
            $products[] = [
                'product_id'  => $row['product_id'],
                'title'       => $row['product_name'],
                'units_sold'  => $row['units_sold'],
                'revenue'     => $row['net_revenue'],
                'order_count' => $row['order_count'],
            ];
        }

        $summary = sprintf(
            'Top %1$d products by %2$s.',
            count($products),
            $metric
        );

        return MCPHelper::envelope($summary, ['metric' => $metric, 'range' => $res['data']['range'], 'products' => $products], ['currency' => $res['meta']['currency'], 'date_basis' => 'created_at', 'mode' => $res['meta']['mode']]);
    }

    // -----------------------------------------------------------------
    // get-refund-report
    // -----------------------------------------------------------------

    public static function getRefundReport($params = [])
    {
        $requested = self::convertibleCurrency($params);
        if (is_wp_error($requested)) {
            return $requested;
        }
        $currency  = $requested === ReportCurrency::ALL ? BaseCurrency::get() : $requested;
        $range     = self::resolveRange($params);
        if (is_wp_error($range)) {
            return $range;
        }
        $mode      = self::orderMode($params);
        $utc       = new \DateTimeZone('UTC');
        $filters   = [
            'startDate'     => new DateTime($range['start'], $utc),
            'endDate'       => new DateTime($range['end'], $utc),
            'currency'      => $requested,
            'paymentStatus' => Status::getReportStatuses(),
            'paymentMode'   => $mode === 'all' ? null : $mode,
        ];

        // The most refunded products are a FluentCart Pro report, as on the Refunds page
        $withProducts = (bool) apply_filters('fluent_cart/report/refund_products', false);
        $overview     = RefundReportService::make()->getOverview(['withProducts' => $withProducts] + $filters);
        $s            = $overview['summary'];

        $paidCount = ReportCurrency::ordersTable($filters)
            ->whereBetween('o.created_at', [$range['start'], $range['end']])
            ->whereIn('o.payment_status', $filters['paymentStatus'])
            ->when($requested !== ReportCurrency::ALL, function ($q) use ($requested) {
                $q->where('o.currency', $requested);
            })
            ->when($mode !== 'all', function ($q) use ($mode) {
                $q->where('o.mode', $mode);
            })
            ->count();

        $money = function ($decimal) use ($currency) {
            return MCPHelper::money(Helper::toCent($decimal), $currency);
        };
        $splits = function ($rows) use ($money) {
            return array_map(function ($row) use ($money) {
                return ['key' => $row['key'], 'label' => $row['label'], 'refunds' => $row['refunds'], 'amount' => $money($row['amount'])];
            }, $rows);
        };

        $data = [
            'range'                => self::rangeBlock($range, $currency),
            'total_refunded'       => $money($s['refunded_amount']),
            'refund_count'         => $s['refunds'],
            'refunded_order_count' => $s['refunded_orders'],
            'average_refund'       => $money($s['average_refund']),
            'full'                 => ['count' => $s['full']['count'], 'amount' => $money($s['full']['amount'])],
            'partial'              => ['count' => $s['partial']['count'], 'amount' => $money($s['partial']['amount'])],
            'paid_order_count'     => $paidCount,
            'refund_rate_percent'  => $s['refund_rate_cohort'] === null ? 0 : round($s['refund_rate_cohort'], 2),
            'refund_rate_amount'   => $s['refund_rate_amount'] === null ? null : round($s['refund_rate_amount'], 2),
            'median_days'          => $s['median_days'],
            'by_sale_type'         => $splits($overview['by_sale_type']),
            'by_payment_method'    => $splits($overview['by_payment_method']),
            'reasons'              => $overview['reasons'],
            'definitions'          => [
                'source'              => 'The admin Refunds report (Reports > Refunds), same window and currency.',
                'total_refunded'      => 'Refunds made in the window, on orders of any date.',
                'average_refund'      => 'total_refunded / refund_count.',
                'refund_rate_percent' => 'Share of the orders placed in the window that were refunded in part or full, at any time.',
                'refund_rate_amount'  => 'total_refunded / what the orders placed in the window paid * 100.',
                'median_days'         => 'Median store days from order to its first refund.',
            ],
        ];
        if ($withProducts && !empty($overview['by_product'])) {
            $data['by_product'] = array_slice($overview['by_product'], 0, 10);
        }

        $summary = sprintf(
            '%1$d refunds totaling %2$s; %3$s%% of the orders placed in the period were refunded.',
            $s['refunds'],
            MCPHelper::displayAmount(Helper::toCent($s['refunded_amount']), $currency),
            $data['refund_rate_percent']
        );

        return MCPHelper::envelope($summary, $data, ['currency' => $currency, 'converted' => $requested === ReportCurrency::ALL, 'date_basis' => 'refund created_at', 'mode' => $mode]);
    }

    // -----------------------------------------------------------------
    // query-sources (flexible UTM attribution)
    // -----------------------------------------------------------------

    const UTM_DIMENSIONS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id'];

    public static function querySources($params = [])
    {
        $requested = self::convertibleCurrency($params);
        if (is_wp_error($requested)) {
            return $requested;
        }
        $currency  = $requested === ReportCurrency::ALL ? BaseCurrency::get() : $requested;
        $range     = self::resolveRange($params);
        if (is_wp_error($range)) {
            return $range;
        }
        $mode      = self::orderMode($params);
        $limit     = isset($params['limit']) ? min(max((int) $params['limit'], 1), self::MAX_ROWS) : 50;
        $utc       = new \DateTimeZone('UTC');
        $filters   = [
            'startDate'     => new DateTime($range['start'], $utc),
            'endDate'       => new DateTime($range['end'], $utc),
            'currency'      => $requested,
            'paymentStatus' => Status::getReportStatuses(),
            'paymentMode'   => $mode === 'all' ? null : $mode,
            'groupKey'      => 'monthly',
        ];

        $groupBy = self::sourceGrouping($params);
        if ($groupBy === null) {
            return MCPHelper::error('unsupported_grouping', 'Not a Sources report grouping.', [
                'fields' => ['group_by', 'dimensions'],
                'hint'   => 'Use group_by: ' . implode(', ', array_keys(SourceReportService::UTM_GROUPS)) . '.',
            ]);
        }
        // Product, variation, time and referrer groupings are FluentCart Pro reports, as on REST
        if (in_array($groupBy, SourceReportService::OTHER_GROUPS, true) && !apply_filters('fluent_cart/report/source_groupings', false)) {
            return MCPHelper::error('pro_required', 'This grouping needs FluentCart Pro. Use a UTM grouping or view=channels.', ['fields' => ['group_by']]);
        }

        // The UTM and product filters narrow the report's own order query for this call
        $narrow = function ($query) use ($params) {
            foreach (['utm_source', 'utm_medium', 'utm_campaign'] as $field) {
                if (!empty($params[$field])) {
                    $query->where('oo.' . $field, sanitize_text_field($params[$field]));
                }
            }
            foreach (['product_id' => 'post_id', 'variation_id' => 'object_id'] as $param => $column) {
                if (!empty($params[$param])) {
                    $id = (int) $params[$param];
                    $query->whereExists(function ($q) use ($column, $id) {
                        $q->selectRaw('1')->from('fct_order_items as oi')->whereRaw('oi.order_id = o.id')->where('oi.' . $column, $id);
                    });
                }
            }

            return $query;
        };
        $report = [];
        add_filter('fluent_cart/report/sources_query', $narrow, 99);
        try {
            $service = SourceReportService::make();
            if (Arr::get($params, 'view') === 'channels') {
                $channels = $service->getChannels($filters);
            } else {
                $report = $service->getReport($filters + ['group_by' => $groupBy, 'include_none' => true]);
            }
        } finally {
            remove_filter('fluent_cart/report/sources_query', $narrow, 99);
        }

        $meta = ['currency' => $currency, 'converted' => $requested === ReportCurrency::ALL, 'date_basis' => 'created_at', 'mode' => $mode];

        if (isset($channels)) {
            foreach ($channels['channels'] as &$channel) {
                $channel['first_net']   = MCPHelper::moneyCompact($channel['first_net']);
                $channel['average_ltv'] = MCPHelper::moneyCompact($channel['average_ltv']);
            }
            unset($channel);
            unset($channels['chart']);

            $summary = sprintf(
                '%1$d new customers; %2$s%% came through a tracked channel.',
                $channels['coverage']['customers'],
                $channels['coverage']['share']
            );

            return MCPHelper::envelope($summary, ['view' => 'channels', 'range' => self::rangeBlock($range, $currency)] + $channels, $meta);
        }

        $money = ['gross_sales', 'refunds', 'net_sales', 'average_order', 'average_net_order', 'first_order_net', 'later_net', 'lifetime_net'];
        $rows  = [];
        foreach (array_slice($report['rows'], 0, $limit) as $row) {
            unset($row['group_key'], $row['utm_key']);
            foreach ($money as $key) {
                if (isset($row[$key])) {
                    $row[$key] = MCPHelper::moneyCompact(Helper::toCent($row[$key]));
                }
            }
            // The tool's earlier metric names
            $row['gross_revenue']   = $row['gross_sales'];
            $row['net_revenue']     = $row['net_sales'];
            $row['aov']             = $row['average_order'];
            $row['refunded_amount'] = $row['refunds'];
            $rows[] = $row;
        }

        $summary = sprintf(
            '%1$d rows grouped by %2$s.',
            count($rows),
            $groupBy
        );

        return MCPHelper::envelope(
            $summary,
            ['group_by' => $report['group_by'], 'range' => self::rangeBlock($range, $currency), 'rows' => $rows, 'definitions' => [
                'gross_sales' => 'Payments captured on the paid orders: after discounts, with tax and shipping, before refunds (paid in get-sales-report). gross_revenue is the same value.',
                'net_sales'   => 'Net sales as in get-sales-report. net_revenue is the same value.',
                'aov'         => 'average_order = gross_sales / orders. average_net_order = net_sales / orders, which is the aov of get-sales-report.',
            ]],
            $meta + ['returned' => count($rows), 'limit' => $limit, 'max_rows' => self::MAX_ROWS, 'truncated' => count($report['rows']) > $limit]
        );
    }

    /**
     * The Sources report grouping: group_by as given, or the grouping whose UTM fields equal the older `dimensions`.
     * Null when those fields match no grouping.
     */
    private static function sourceGrouping($params)
    {
        if (!empty($params['group_by'])) {
            $groupBy = SourceReportService::sanitizeGroupBy($params['group_by']);

            return $groupBy === $params['group_by'] ? $groupBy : null;
        }
        if (empty($params['dimensions']) || !is_array($params['dimensions'])) {
            return SourceReportService::DEFAULT_GROUP;
        }
        $fields = array_values(array_unique(array_map('strval', $params['dimensions'])));
        sort($fields);
        foreach (SourceReportService::UTM_GROUPS as $group => $groupFields) {
            sort($groupFields);
            if ($groupFields === $fields) {
                return $group;
            }
        }

        return null;
    }

    public static function queryOrders($params = [])
    {
        if (in_array(Arr::get($params, 'view'), ['payment_methods', 'coupons'], true)) {
            return self::orderReportView($params);
        }

        $currency   = self::currency($params);
        if (is_wp_error($currency)) {
            return $currency;
        }
        $range      = self::resolveRange($params);
        if (is_wp_error($range)) {
            return $range;
        }
        $mode       = self::orderMode($params);
        $metrics    = self::pickList($params, 'metrics', ['order_count', 'gross_revenue', 'paid_revenue', 'refunded_amount', 'aov', 'unique_customers'], ['order_count', 'gross_revenue']);
        $dimensions = self::pickList($params, 'dimensions', ['day', 'week', 'month', 'status', 'payment_status', 'order_type', 'country', 'state'], []);
        $proError = self::stateNeedsPro($dimensions);
        if ($proError) {
            return $proError;
        }

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
        self::joinOrderSales($query, $range);

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
            $expr      = self::dimensionExpr($dim, $range);
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
            'gross_revenue'    => 'SUM(fc_sales.sales_gross) as gross_revenue',
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
            // As the Orders report's Average Order Value (Net): net sales per order
            $selects[] = 'SUM(fc_sales.sales_net) as aov_net';
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
            $firstMetric = 'aov_net';
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
                    $g = (int) $row->aov_net;
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
            '%1$d rows — metrics [%2$s] grouped by [%3$s].',
            count($out),
            implode(', ', $metrics),
            $dimensions ? implode(', ', $dimensions) : 'total'
        );

        $meta = ['currency' => $currency, 'date_basis' => 'created_at', 'mode' => $mode, 'payment_statuses' => self::PAID];
        // A payment_status grouping looks complete without the unpaid orders: say how many are left out
        if (in_array('payment_status', $dimensions, true)) {
            $unpaid = Order::query()
                ->whereNotIn('payment_status', self::PAID)
                ->where('currency', $currency)
                ->where('created_at', '>=', $range['start'])
                ->where('created_at', '<=', $range['end']);
            self::applyMode($unpaid, $mode);
            self::applyOrderItemFilter($unpaid, $params);
            $unpaidCount = (int) $unpaid->count();
            if ($unpaidCount) {
                $meta['warnings'] = [sprintf(
                    '%1$d orders in this window are not counted: query-orders reads orders that captured payment only (payment_status %2$s). Use list-orders with payment_status to see pending or failed orders.',
                    $unpaidCount,
                    implode(', ', self::PAID)
                )];
            }
        }
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
        if (is_wp_error($currency)) {
            return $currency;
        }
        $range      = self::resolveRange($params);
        if (is_wp_error($range)) {
            return $range;
        }
        $mode       = self::orderMode($params);
        $metrics    = self::pickList($params, 'metrics', ['units_sold', 'line_revenue', 'list_price_sum', 'discount_amount', 'net_revenue', 'order_count', 'avg_unit_price', 'refund_amount'], ['units_sold', 'line_revenue']);
        $dimensions = self::pickList($params, 'dimensions', ['product', 'variation', 'order_type'], ['product']);

        // The admin Products page's lines ($0 orders included): each order's payment, refund and net sales
        // shared over its lines by line amount
        $utc = new \DateTimeZone('UTC');
        list($orders, $orderShares, $share) = ProductReportService::make()->variationShareParts([
            'startDate'     => new DateTime($range['start'], $utc),
            'endDate'       => new DateTime($range['end'], $utc),
            'currency'      => $currency,
            'paymentStatus' => Status::getReportStatuses(),
            'paymentMode'   => $mode === 'all' ? null : $mode,
        ]);
        $query = $orders()->joinSub($orderShares, 'ol', 'ol.order_id', '=', 'o.id');

        if (!empty($params['product_id'])) {
            $query->where('oi.post_id', (int) $params['product_id']);
        }
        if (!empty($params['variation_id'])) {
            $query->where('oi.object_id', (int) $params['variation_id']);
        }
        // One row per order, so this join does not fan out
        if (array_intersect(['line_revenue', 'discount_amount', 'avg_unit_price'], $metrics)) {
            $query->leftJoinSub(self::orderDiscountSplit($currency, $range, $mode), 'fctod', 'fctod.order_id', '=', 'o.id');
        }

        $hasProduct       = in_array('product', $dimensions, true);
        $hasVariation     = in_array('variation', $dimensions, true);
        $groupByOrderType = in_array('order_type', $dimensions, true);

        $selects   = [];
        $groupCols = [];
        if ($hasProduct) {
            $selects[]   = 'oi.post_id';
            $selects[]   = 'MAX(oi.post_title) as product_title';
            $groupCols[] = 'oi.post_id';
        }
        if ($hasVariation) {
            $selects[]   = 'oi.object_id';
            $groupCols[] = 'oi.object_id';
            // Variation rows name their product too, so the agent needs no follow-up lookup
            $selects[] = 'MAX(oi.title) as variation_label';
            if (!$hasProduct) {
                $selects[] = 'MAX(oi.post_id) as vproduct_id';
                $selects[] = 'MAX(oi.post_title) as product_title';
            }
        }
        if ($groupByOrderType) {
            $selects[]   = 'o.type as order_type';
            $groupCols[] = 'o.type';
        }

        // line_revenue is the line price after discounts and order-level credits, before refunds and tax
        $line      = ProductReportService::lineTotalSql('oi', 'fctod');
        $metricSql = [
            // A signup fee line repeats its plan's quantity and a checkout fee is no product: neither is a unit
            'units_sold'      => "SUM(CASE WHEN oi.payment_type NOT IN ('signup_fee', 'fee') THEN oi.quantity ELSE 0 END) as units_sold",
            'line_revenue'    => 'SUM(' . $line . ') as line_revenue',
            'list_price_sum'  => 'SUM(oi.subtotal) as list_price_sum',
            'discount_amount' => 'SUM(oi.discount_total + oi.line_total - ' . $line . ') as discount_amount',
            'net_revenue'     => "SUM({$share['net']}) as net_revenue",
            'order_count'     => 'COUNT(DISTINCT o.id) as order_count',
            'refund_amount'   => "SUM({$share['refund']}) as refund_amount",
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
                '%1$d rows — product metrics [%2$s].',
                count($out),
                implode(', ', $metrics)
            ),
            ['metrics' => $metrics, 'dimensions' => $dimensions, 'range' => self::rangeBlock($range, $currency), 'rows' => $out],
            array_merge(['currency' => $currency, 'date_basis' => 'created_at', 'mode' => $mode], self::pageMeta($paging, $fetched))
        );
    }

    public static function queryCustomers($params = [])
    {
        if (Arr::get($params, 'view') === 'overview') {
            return self::customerOverview($params);
        }

        $metrics    = self::pickList($params, 'metrics', ['customer_count', 'total_ltv', 'avg_ltv', 'avg_purchase_count', 'repeat_customers'], ['customer_count', 'total_ltv']);
        $dimensions = self::pickList($params, 'dimensions', ['country', 'state', 'status', 'first_purchase_month', 'last_purchase_month'], []);
        $proError = self::stateNeedsPro($dimensions);
        if ($proError) {
            return $proError;
        }

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
                '%1$d rows — customer metrics [%2$s].',
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
        if (Arr::get($params, 'view') === 'overview') {
            return self::subscriptionOverview($params);
        }

        $range      = self::resolveRange($params);
        if (is_wp_error($range)) {
            return $range;
        }
        $dateBasis  = self::subDateBasis($params);
        $metrics    = self::pickList($params, 'metrics', ['subscription_count', 'contract_value', 'recurring_value'], ['subscription_count', 'contract_value']);
        $dimensions = self::pickList($params, 'dimensions', ['month', 'plan_type', 'status', 'billing_interval'], []);

        // Same store-day window as every other report; the chosen date_basis
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
                $expr = 'DATE_FORMAT(' . self::localSql($dateBasis, $range) . ", '%Y-%m')";
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
                '%1$d rows — subscription metrics [%2$s] grouped by [%3$s].',
                count($out),
                implode(', ', $metrics),
                $dimensions ? implode(', ', $dimensions) : 'total'
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

    private static function dimensionExpr($dim, $range = null)
    {
        $formats = ['day' => '%Y-%m-%d', 'week' => '%x-W%v', 'month' => '%Y-%m'];
        if (isset($formats[$dim])) {
            return 'DATE_FORMAT(' . self::localSql('created_at', $range) . ", '" . $formats[$dim] . "')";
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
    // installments (get-report) / revenue by variation
    // -----------------------------------------------------------------

    /**
     * The fixed-shape report pages: installments, subscription retention by period, and cohorts.
     */
    public static function getReport($params = [])
    {
        $report = Arr::get($params, 'report');
        if ($report === 'installments') {
            return self::getInstallmentReport($params);
        }
        if ($report === 'cohorts') {
            // The retention snapshots are kept per currency: no converted mode
            $currency = self::currency($params);
            if (is_wp_error($currency)) {
                return $currency;
            }
            // Every cohort from the snapshots; MRR cohorts, billing interval and rebuys need Pro, as on REST
            $cohorts = SubscriptionReportService::make()->cohortReport([
                'groupBy'         => Arr::get($params, 'group_by') === 'month' ? 'month' : 'year',
                'metric'          => Arr::get($params, 'metric', 'subscribers'),
                'billingInterval' => Arr::get($params, 'billing_interval', ''),
                'rebuyAdjusted'   => !empty($params['rebuy_adjusted']),
                'currency'        => $currency,
            ], (bool) apply_filters('fluent_cart/report/cohort_options', false));
            $cohorts['cohorts_total'] = count($cohorts['cohorts']);
            // A list, not keyed: a sliced list with kept keys would encode as a JSON object
            $cohorts['cohorts'] = array_slice($cohorts['cohorts'], -self::MAX_PERIODS);
            // Smaller for an assistant: each period keeps what the others derive from; a monthly cohort keeps a year
            $keep = array_flip(['offset', 'retained_mrr', 'retained_count', 'retention_rate_mrr', 'retention_rate_count', 'partial']);
            $periods = $cohorts['group_by'] === 'month' ? 12 : null;
            foreach ($cohorts['cohorts'] as &$cohort) {
                $cohort['periods'] = array_map(function ($period) use ($keep) {
                    return array_intersect_key($period, $keep);
                }, array_slice($cohort['periods'], 0, $periods));
            }
            unset($cohort);

            $summary = sprintf('Cohorts by %s.', $cohorts['metric']);
            $meta = ['currency' => $currency];
            if (!empty($cohorts['rebuild_needed'])) {
                $stale = sprintf(
                    'Stale data: these cohorts come from snapshots built on %s, before an update changed the snapshot rules (rebuild_needed is true). Do not rely on them. Open Reports > Subscriptions > Cohorts in the admin to rebuild the snapshots. A period with null values has no snapshot.',
                    !empty($cohorts['generated_at']) ? MCPHelper::toIso8601($cohorts['generated_at']) : 'an earlier date'
                );
                $summary .= ' ' . $stale;
                $meta['warnings'] = [$stale];
            }

            return MCPHelper::envelope($summary, ['report' => 'cohorts'] + $cohorts, $meta);
        }
        if ($report === 'retention') {
            $requested = self::convertibleCurrency($params);
            if (is_wp_error($requested)) {
                return $requested;
            }
            $range     = self::resolveRange($params);
            if (is_wp_error($range)) {
                return $range;
            }
            $utc       = new \DateTimeZone('UTC');
            $filters   = [
                'startDate'       => new DateTime($range['start'], $utc),
                'endDate'         => new DateTime($range['end'], $utc),
                'currency'        => $requested,
                'billingInterval' => sanitize_key((string) Arr::get($params, 'billing_interval', '')),
                'period'          => Arr::get($params, 'period') === 'year' ? 'year' : 'month',
            ];
            $service = SubscriptionReportService::make();
            $currency = $requested === ReportCurrency::ALL ? BaseCurrency::get() : $requested;

            // The latest periods only: a long monthly range is too big for an assistant's context
            $rows = array_values($service->getRetentionData($filters));
            // The admin report keeps some counts and amounts as strings; a tool result gives numbers
            foreach ($rows as &$row) {
                foreach (['active_subscriptions', 'active_paid_subscriptions', 'active_free_subscriptions'] as $key) {
                    $row[$key] = (int) $row[$key];
                }
                foreach (['retained_mrr', 'mrr', 'start_mrr'] as $key) {
                    $row[$key] = (float) $row[$key];
                }
            }
            unset($row);

            return MCPHelper::envelope(
                'Subscription retention by period.',
                ['report' => 'retention', 'range' => self::rangeBlock($range, $currency), 'retention_data' => array_slice($rows, -self::MAX_PERIODS),
                    'periods_total' => count($rows), 'intervals' => $service->retentionIntervals($filters)],
                ['currency' => $currency, 'converted' => $requested === ReportCurrency::ALL]
            );
        }

        return MCPHelper::error('invalid_param', 'report must be installments, retention or cohorts.', ['fields' => ['report']]);
    }

    public static function getInstallmentReport($params = [])
    {
        $currency = self::convertibleCurrency($params);
        if (is_wp_error($currency)) {
            return $currency;
        }
        $badDate = MCPHelper::unreadableDateError((array) $params, ['as_of', 'started_from', 'started_to'])
            ?: MCPHelper::reversedBoundsError((array) $params, ['started_from' => 'started_to']);
        if ($badDate) {
            return $badDate;
        }
        // The monthly schedule, plans started by month, collection rate and plans by variation are Pro reports
        $withPro = (bool) apply_filters('fluent_cart/report/installment_forecast', false);
        $report = InstallmentReportService::make()->getReport([
            'currency'     => $currency,
            'variationIds' => array_map('intval', (array) Arr::get($params, 'variation_ids', [])),
            'asOf'         => Arr::get($params, 'as_of'),
            'startedFrom'  => Arr::get($params, 'started_from'),
            'startedTo'    => Arr::get($params, 'started_to'),
            'atRiskLimit'  => (int) Arr::get($params, 'at_risk_limit', 50),
            'withVariations' => $withPro,
            'showCustomers'  => ReportHelper::canSeeCustomers(),
        ]);
        if (!$withPro) {
            unset($report['schedule'], $report['starts'], $report['summary']['collection_rate']);
        }

        $sum = $report['summary'];
        $currency = $report['currency'] === ReportCurrency::ALL ? MCPHelper::currencyCode() : $report['currency'];

        $summary = sprintf(
            '%1$d installment plans started; collected %2$s, scheduled %3$s, overdue %4$s, lapsed unpaid %5$s; %6$s%% completed.',
            $sum['plans_started'],
            MCPHelper::displayAmount($sum['collected'], $currency),
            MCPHelper::displayAmount($sum['scheduled'], $currency),
            MCPHelper::displayAmount($sum['overdue'], $currency),
            MCPHelper::displayAmount($sum['lapsed_unpaid'], $currency),
            $sum['completion_rate']
        );

        $report['definitions'] = [
            'started'   => 'At least one order of the plan has payment_status paid or partially_refunded, total_paid > 0, and a plan share of that order above 0. The start date is that first paid order (GMT); started_from/started_to filter on it.',
            'collected' => 'Sum of total_paid - total_refund over the plan orders that took money. On a parent order that also holds other items, only the plan lines count: the payment is shared by line amount (a trial line on its first order counts 0) and refunds come from the plan lines when lines record them, so the plan share of tax and shipping is an estimate.',
            'scheduled' => 'Payments left x recurring_total on running plans (active, trialing).',
            'overdue'   => 'Payments left x recurring_total on expiring, past_due and failing plans.',
            'lapsed'    => 'Ended with payments left and no refund on any plan order; lapsed_unpaid is what they still owed.',
            'refunded'  => 'Ended with payments left and a refund on at least one plan order.',
            'paused'    => 'Paused with payments left. It can resume, so it is neither lapsed nor at risk, and it stays out of the completion rate.',
            'upgraded'  => 'Replaced by a new plan through an upgrade (config.upgraded_to_sub_id). Its collected money still counts; it stays out of the completion rate and the at-risk list, unless it still bills (then it is running or overdue).',
            'running'   => 'Active or trialing with payments left, or paid and still waiting for the gateway to activate (pending, created, authenticated).',
            'completion_rate' => 'completed / (completed + lapsed + refunded) x 100: plans that have ended. Running and overdue plans can still complete, so they are left out.',
            'schedule'  => 'Payments left on running and overdue plans, per store-timezone month, from next_billing_date by the billing interval. A payment already past due is in the as_of month. Same months as finite_count in get-upcoming-payments with bucket=month, which reads active, past_due and trialing plans only.',
        ];

        // The admin report is in cents; a tool result is in currency units like the other tools
        $report = self::centsToDecimal($report, ['collected', 'scheduled', 'overdue', 'lapsed_unpaid', 'amount', 'left_amount',
            'at_risk_amount', 'min_price', 'max_price', 'overdue_amount', 'lapsed_amount', 'total']);

        return MCPHelper::envelope($summary, $report, ['currency' => $currency, 'amounts' => 'decimal', 'date_basis' => 'as_of']);
    }

    /**
     * The named keys of a report array from cents to currency units, at any depth. A key that holds an array
     * (due_soon.overdue) is walked, not converted.
     */
    private static function centsToDecimal(array $data, array $keys)
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::centsToDecimal($value, $keys);
            } elseif (is_numeric($value) && in_array($key, $keys, true)) {
                $data[$key] = MCPHelper::moneyCompact($value);
            }
        }

        return $data;
    }

    public static function getVariationRevenue($params = [])
    {
        $range = self::resolveRange($params);
        if (is_wp_error($range)) {
            return $range;
        }
        $currency = ReportCurrency::resolve(Arr::get($params, 'currency'), true);

        $report = ProductReportService::make()->getVariationRevenue([
            'startDate'     => new DateTime($range['start']),
            'endDate'       => new DateTime($range['end']),
            'currency'      => $currency,
            'paymentStatus' => Status::getReportStatuses(),
            'variationIds'  => array_map('intval', (array) Arr::get($params, 'variation_ids', [])),
        ]);

        $metaCurrency = $currency === ReportCurrency::ALL ? MCPHelper::currencyCode() : $currency;
        $totals = $report['totals'];

        $summary = sprintf(
            '%1$d variations sold: gross %2$s, refunds %3$s, net %4$s.',
            count($report['variations']),
            MCPHelper::displayAmount($totals['gross'], $metaCurrency),
            MCPHelper::displayAmount($totals['refunds'], $metaCurrency),
            MCPHelper::displayAmount($totals['net'], $metaCurrency)
        );

        $report['range'] = self::rangeBlock($range, $metaCurrency);

        return MCPHelper::envelope($summary, $report, ['currency' => $metaCurrency, 'amounts' => 'cents', 'date_basis' => 'created_at']);
    }

    // -----------------------------------------------------------------
    // shared helpers
    // -----------------------------------------------------------------

    /**
     * The one currency a tool reports in: the given ISO code, else the store currency. A value that is no ISO code,
     * or __all__ on a tool that does not convert, is an error rather than an empty report.
     *
     * @return string|\WP_Error
     */
    public static function currency($params)
    {
        if (empty($params['currency'])) {
            return MCPHelper::currencyCode();
        }
        $code = strtoupper(trim(sanitize_text_field((string) $params['currency'])));
        if (preg_match('/^[A-Z]{3}$/', $code)) {
            return $code;
        }

        return MCPHelper::error('invalid_param', 'currency must be a 3-letter ISO code. Converted totals (__all__) are in get-sales-report, get-refund-report and query-sources.', ['fields' => ['currency']]);
    }

    /**
     * currency() for tools that also convert every currency to the store one (__all__), as their admin page does.
     *
     * @return string|\WP_Error
     */
    private static function convertibleCurrency($params)
    {
        return Arr::get($params, 'currency') === ReportCurrency::ALL ? ReportCurrency::ALL : self::currency($params);
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

    private static function orderDiscountSplit($currency, $range, $mode)
    {
        $orders = App::db()->table('fct_orders as o')
            ->whereIn('o.payment_status', self::PAID)
            ->where('o.currency', $currency)
            ->where('o.created_at', '>=', Arr::get($range, 'start'))
            ->where('o.created_at', '<=', Arr::get($range, 'end'));
        self::applyMode($orders, $mode, 'o.mode');

        return ProductReportService::orderDiscountSplitQuery($orders);
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
     * accepts the identical range vocabulary and store-day semantics.
     *
     * @return array|\WP_Error an error when a custom window starts after it ends
     */
    public static function resolveRange($params)
    {
        // Days are store days (WordPress timezone), as in the admin reports; the bounds come back in UTC.
        $tz = wp_timezone();

        // An unreadable date must not fall through to the default window.
        $unreadable = MCPHelper::unreadableDateError((array) $params, ['since', 'date_from', 'date_to', 'start_date', 'end_date']);
        if ($unreadable) {
            return $unreadable;
        }

        // Delta mode: everything strictly after an instant, up to now. Time-precise
        // (not snapped to a day) so "what changed since 14:05" works during a launch.
        if (!empty($params['since'])) {
            $start = self::instant($params['since'], $tz, false);
            if ($start !== null) {
                $end = gmdate('Y-m-d H:i:s');
                return $start > $end ? self::reversedRangeError($start, $end, ['since']) : self::withPrior($start, $end, 'since');
            }
        }

        // Time-precise custom window (ISO 8601). A time of day is kept; a date-only
        // value snaps to the day edge. Overrides range and the legacy start/end_date.
        if (!empty($params['date_from']) || !empty($params['date_to'])) {
            $start = self::instant(!empty($params['date_from']) ? $params['date_from'] : '-30 days', $tz, false);
            $end   = self::instant(!empty($params['date_to']) ? $params['date_to'] : 'now', $tz, true);
            if ($start !== null && $end !== null) {
                return $start > $end ? self::reversedRangeError($start, $end, ['date_from', 'date_to']) : self::withPrior($start, $end, 'custom');
            }
        }

        if (!empty($params['start_date']) || !empty($params['end_date'])) {
            $start = self::dayStart(!empty($params['start_date']) ? $params['start_date'] : '-30 days', $tz);
            $end   = self::dayEnd(!empty($params['end_date']) ? $params['end_date'] : 'now', $tz);
            if ($start > $end) {
                return self::reversedRangeError($start, $end, ['start_date', 'end_date']);
            }
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

    /**
     * A window that starts after it ends matches nothing: an error, not a report of zeros.
     * Public for the tools that resolve their own window.
     */
    public static function reversedRangeError($startUtc, $endUtc, array $fields)
    {
        return MCPHelper::error(
            'invalid_param',
            sprintf(
                'The date range is reversed: it starts at %1$s, after its end at %2$s. Swap the two dates and retry.',
                MCPHelper::toIso8601($startUtc),
                MCPHelper::toIso8601($endUtc)
            ),
            ['fields' => $fields]
        );
    }

    /**
     * The admin Payment Methods or Coupons report for a date range, as REST serves it to this store.
     */
    private static function orderReportView($params)
    {
        $requested = self::convertibleCurrency($params);
        if (is_wp_error($requested)) {
            return $requested;
        }
        $currency  = $requested === ReportCurrency::ALL ? BaseCurrency::get() : $requested;
        $range     = self::resolveRange($params);
        if (is_wp_error($range)) {
            return $range;
        }
        $utc       = new \DateTimeZone('UTC');
        $filters   = [
            'startDate'     => new DateTime($range['start'], $utc),
            'endDate'       => new DateTime($range['end'], $utc),
            'currency'      => $requested,
            'paymentStatus' => Status::getReportStatuses(),
            'groupKey'      => 'monthly',
        ];
        $view = $params['view'];
        // Coupons read orders only. Payment methods also read declined charges and failing subscriptions, which
        // carry no order mode, so that report counts both modes
        $mode = $view === 'coupons' ? self::orderMode($params) : 'all';
        $filters['paymentMode'] = $mode === 'all' ? null : $mode;

        if ($view === 'payment_methods') {
            // Disputes per gateway and card brands are Pro reports with their own routes; this report has neither
            $report = PaymentMethodReportService::make()->getReport($filters);
            unset($report['chart'], $report['previous']);
            $report['definitions'] = [
                'source'        => 'The admin Payment Methods report (Reports > Payment Methods), same window and currency.',
                'gross_sales'   => 'Payments the gateway captured on the paid orders: after discounts, with tax, shipping and fees, before refunds. Equals paid in get-sales-report, not its gross_sales (item sales before discounts).',
                'net_sales'     => 'Net sales as in get-sales-report: item sales after discounts and refunds, without tax, shipping and fees.',
                'average_order' => 'gross_sales / orders: the average payment. aov in get-sales-report and query-orders is net_sales / orders.',
            ];
        } else {
            // The value of the customers a coupon won and its refund rate are a FluentCart Pro report
            $withQuality = (bool) apply_filters('fluent_cart/report/coupon_quality', false);
            $report = CouponReportService::make()->getReport(['withQuality' => $withQuality] + $filters);
            if (!$withQuality) {
                $quality = array_flip(['won_ltv', 'first_order_net', 'later_net', 'lifetime_net', 'refund_rate']);
                $report['rows'] = array_map(function ($row) use ($quality) {
                    return array_diff_key($row, $quality);
                }, $report['rows']);
                $report['totals'] = array_diff_key($report['totals'], $quality);
            }
            $report['rows_total'] = count($report['rows']);
            $report['rows'] = array_slice($report['rows'], 0, isset($params['limit']) ? min(max((int) $params['limit'], 1), self::MAX_ROWS) : 25);
        }

        $summary = sprintf(
            '%1$d rows in the %2$s report.',
            count($report['rows']),
            $view
        );

        return MCPHelper::envelope($summary, ['view' => $view, 'range' => self::rangeBlock($range, $currency)] + $report, ['currency' => $currency, 'converted' => $requested === ReportCurrency::ALL, 'mode' => $mode]);
    }

    /**
     * The admin Customers overview for a date range: new, returning and renewing customers and their net, repeat
     * purchase rate and average lifetime value. Amounts in currency units.
     */
    private static function customerOverview($params)
    {
        $requested = self::convertibleCurrency($params);
        if (is_wp_error($requested)) {
            return $requested;
        }
        $currency  = $requested === ReportCurrency::ALL ? BaseCurrency::get() : $requested;
        $range     = self::resolveRange($params);
        if (is_wp_error($range)) {
            return $range;
        }
        $utc       = new \DateTimeZone('UTC');
        $overview  = CustomerReportService::make()->getOverview([
            'startDate'     => new DateTime($range['start'], $utc),
            'endDate'       => new DateTime($range['end'], $utc),
            'currency'      => $requested,
            'paymentStatus' => Status::getReportStatuses(),
            'groupKey'      => 'monthly',
        ]);
        $s = self::centsToDecimal($overview['summary'], ['new_net', 'returning_net', 'renewal_net', 'net', 'average_ltv']);

        $summary = sprintf(
            '%1$d new and %2$d returning customers in the period.',
            (int) Arr::get($s, 'new_customers', 0),
            (int) Arr::get($s, 'returning_customers', 0)
        );

        return MCPHelper::envelope($summary, ['view' => 'overview', 'range' => self::rangeBlock($range, $currency), 'summary' => $s], ['currency' => $currency, 'converted' => $requested === ReportCurrency::ALL, 'amounts' => 'decimal']);
    }

    /**
     * The admin Subscriptions page: health now (MRR, active, canceling, failing, paused, trials) and the period's
     * movement (new, churned, churn rate, MRR change, renewals). Amounts in currency units, as on that page.
     */
    private static function subscriptionOverview($params)
    {
        $requested = self::convertibleCurrency($params);
        if (is_wp_error($requested)) {
            return $requested;
        }
        $currency  = $requested === ReportCurrency::ALL ? BaseCurrency::get() : $requested;
        $range     = self::resolveRange($params);
        if (is_wp_error($range)) {
            return $range;
        }
        $utc       = new \DateTimeZone('UTC');
        $filters   = [
            'startDate' => new DateTime($range['start'], $utc),
            'endDate'   => new DateTime($range['end'], $utc),
            'currency'  => $requested,
            'groupKey'  => 'monthly',
        ];
        if (!empty($params['product_id'])) {
            $filters['variationIds'] = array_map('intval', App::db()->table('fct_product_variations')
                ->where('post_id', (int) $params['product_id'])->pluck('id')->toArray()) ?: [0];
        }

        $service = SubscriptionReportService::make();
        // MRR by plan and interval and trial conversion are a FluentCart Pro report, as on REST
        $overview = $service->getOverview($filters, (bool) apply_filters('fluent_cart/report/mrr_breakdown', false));
        unset($overview['chart']);
        $data = ['view' => 'overview', 'range' => self::rangeBlock($range, $currency), 'health' => $service->getHealth($filters)] + $overview;

        $summary = sprintf(
            'MRR %1$s across %2$d subscriptions; %3$d new and %4$d churned in the period.',
            MCPHelper::displayAmount(Helper::toCent($data['health']['mrr']), $currency),
            $data['health']['subscriptions'],
            $overview['period']['new'],
            $overview['period']['churned']
        );

        return MCPHelper::envelope($summary, $data, ['currency' => $currency, 'converted' => $requested === ReportCurrency::ALL, 'amounts' => 'decimal']);
    }

    /**
     * Locations below country are a FluentCart Pro report: Pro answers fluent_cart/report/locations, as for REST.
     */
    private static function stateNeedsPro(array $dimensions)
    {
        if (!in_array('state', $dimensions, true) || has_filter('fluent_cart/report/locations')) {
            return null;
        }

        return MCPHelper::error('pro_required', 'Grouping by state needs FluentCart Pro. Group by country instead.', ['fields' => ['dimensions']]);
    }

    /**
     * SQL for a GMT column as store-local time, for day/week/month buckets inside $range.
     */
    private static function localSql($column, $range)
    {
        $utc = new \DateTimeZone('UTC');
        return ReportHelper::storeTimeSql(
            $column,
            new \DateTime(Arr::get($range, 'start', 'now'), $utc),
            new \DateTime(Arr::get($range, 'end', 'now'), $utc)
        );
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

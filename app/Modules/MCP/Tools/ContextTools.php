<?php

namespace FluentCart\App\Modules\MCP\Tools;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\MCP\AbilitiesRegistrar;
use FluentCart\App\Modules\MCP\Support\MCPHelper;
use FluentCart\App\Modules\MCP\Support\PermissionGate;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Permission\PermissionManager;

/**
 * Discovery tools — the agent's entry point into a FluentCart store.
 *
 * `get-store-context` is the documented "call this first" tool. One call tells
 * the agent who it is, what it's allowed to do, the store's money/time
 * conventions, headline numbers, and every valid enum value — so it never has
 * to guess a status string or invent a currency format. It's cached (60s) and
 * invalidated when reference data changes, because it's called every session.
 *
 * `list-reference-data` is the on-demand lookup for the heavier reference lists
 * (coupons, labels, tax/shipping config) the agent only sometimes needs — kept
 * OUT of the context payload so the first call stays lean.
 *
 * Parameter philosophy: get-store-context takes nothing (zero friction, it's
 * discovery). list-reference-data takes only `kinds[]` — the agent asks for
 * exactly the lists it needs, and we return only the kinds its role can see.
 */
class ContextTools
{
    const CACHE_TTL = 60;

    const CACHE_PREFIX = 'fluent_cart_mcp_context_';

    // Baseline domain enums. The status families are re-read from the canonical
    // Status helper at runtime by enums() — this literal is only the fallback for
    // a family Status cannot answer for. Do not hand-maintain the status lists
    // here: an enum the agent trusts but the column can never hold turns every
    // filter built from it into a silent zero-row result.
    const ENUMS = [
        // Status::getOrderStatuses() plus PERSISTED_ONLY_ORDER_STATUSES — see that
        // constant for why the canonical helper is not the whole set.
        // 'partial-refund' is deliberately absent: unlike the others below, no code
        // path writes it (the column COMMENT lists it, but nothing persists it).
        'order_statuses'        => ['draft', 'pending', 'processing', 'completed', 'on-hold', 'canceled', 'failed', 'refunded'],
        // Kept in sync with Status::getPaymentStatuses(); 'authorized' (card
        // authorized, not yet captured) and 'payment_scheduled' are valid
        // persisted statuses and must be listed so clients can filter them.
        'payment_statuses'      => ['pending', 'paid', 'partially_paid', 'failed', 'refunded', 'partially_refunded', 'authorized', 'payment_scheduled'],
        // 'none' = no shipping required (e.g. digital orders); reported when the
        // stored value is empty. It is read-only — change-order-status won't set it.
        'shipping_statuses'     => ['none', 'unshipped', 'shipped', 'delivered', 'unshippable'],
        'order_types'           => ['payment', 'renewal', 'subscription'],
        'subscription_statuses' => ['pending', 'active', 'failing', 'paused', 'expired', 'expiring', 'canceled', 'trialing', 'intended', 'past_due', 'completed'],
        // installment = fixed-term split-pay plan (a lifetime license paid off in
        // a finite number of charges, bill_times > 0); recurring = open-ended
        // subscription (bill_times = 0). Derived from bill_times, never the title.
        'plan_types'            => ['installment', 'recurring'],
        'billing_intervals'     => ['daily', 'weekly', 'monthly', 'quarterly', 'half_yearly', 'yearly'],
        'fulfillment_types'     => ['physical', 'digital'],
        'coupon_types'          => ['fixed', 'percentage'],
        'order_modes'           => ['live', 'test'],
    ];

    /**
     * Order statuses fct_orders.status genuinely holds that Status::getOrderStatuses()
     * does NOT list, because that helper answers "what may an admin SET an order to",
     * not "what can this column contain".
     *
     * An enum is wrong in two directions, and only one of them is loud. Listing a
     * value the column can never hold gives the agent a filter that silently returns
     * zero rows. OMITTING a value the column does hold is worse: those rows become
     * unreachable, and because the value is missing from the input_schema enum the
     * call is rejected outright, so the agent cannot even discover the rows exist.
     *
     * Each of these is written by a core path, verified in source:
     *  - draft:    the column DEFAULT (database/Migrations/OrdersMigrator.php).
     *  - pending:  every store-managed renewal invoice
     *              (StoreManagedRenewal/Services/RenewalService.php:113).
     *  - refunded: the WooCommerce migrator maps wc-refunded to it
     *              (WooCommerceMigrator/Services/OrderMigrationService.php).
     *
     * So a store using store-managed renewals, or migrated from WooCommerce, has rows
     * the five-value helper cannot describe. Keep this list in step with the writers,
     * not with the admin dropdown.
     *
     * Note COD is NOT one of them: a COD checkout creates the order as 'on-hold' and
     * only its payment_status is pending. Cod::maybeUpdatePayments() looks like an
     * order-status writer but has no callers.
     */
    const PERSISTED_ONLY_ORDER_STATUSES = ['draft', 'pending', 'refunded'];

    /**
     * The enums the agent is told to trust, with every status family re-read from
     * the canonical Status helper so this payload can never drift from what the
     * columns actually hold (a drifted enum is worse than a missing one — the
     * agent builds a valid-looking filter that always returns zero rows).
     *
     * Status::get*Statuses() are themselves filtered, so a Pro/add-on status
     * registered through those hooks shows up here automatically.
     *
     * @return array
     */
    public static function enums()
    {
        $enums = self::ENUMS;

        $live = [
            'order_statuses'        => [Status::class, 'getOrderStatuses'],
            'payment_statuses'      => [Status::class, 'getPaymentStatuses'],
            'shipping_statuses'     => [Status::class, 'getShippingStatuses'],
            'subscription_statuses' => [Status::class, 'getSubscriptionStatuses'],
        ];

        foreach ($live as $key => $callable) {
            try {
                $values = array_values(array_map('strval', array_keys((array) call_user_func($callable))));
            } catch (\Throwable $e) {
                // Keep the baseline rather than shipping an empty enum: an empty
                // list reads as "no valid values" and blocks every filter.
                continue;
            }
            if (!$values) {
                continue;
            }
            // 'none' is an MCP-only reported value (empty stored shipping status)
            // that Status has no constant for — re-add it after the live overlay.
            if ($key === 'shipping_statuses') {
                array_unshift($values, 'none');
            }
            // Statuses the column holds that the helper does not list. Unioned, not
            // overwritten: getOrderStatuses() is the settable list, so overwriting
            // would drop 'pending'/'draft'/'refunded' and make those real rows
            // unfilterable. See PERSISTED_ONLY_ORDER_STATUSES.
            if ($key === 'order_statuses') {
                $values = array_merge($values, self::PERSISTED_ONLY_ORDER_STATUSES);
            }
            $enums[$key] = array_values(array_unique($values));
        }

        return $enums;
    }

    // Payment statuses that count as realized revenue. Centralized so every
    // tool (context, reports, aggregates) agrees on what "paid" means.
    const PAID_STATUSES = ['paid', 'partially_paid', 'partially_refunded'];

    /**
     * Ability definitions for this domain. The registrar merges every tool
     * class's definitions(), so a tool's schema lives next to its code.
     */
    public static function definitions()
    {
        return [
            'fluent-cart/get-store-context' => [
                'label'       => __('Get Store Context', 'fluent-cart'),
                'description' => __('START HERE — call once per session. Returns who you are and your permissions, the store currency/timezone conventions, headline stats, every valid enum value (order/payment/shipping/subscription statuses, intervals, types), and usage guidelines. Use this before any other tool so you never guess a status string or money format.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => new \stdClass(),
                ],
                'execute_callback'    => [self::class, 'getContext'],
                'permission_callback' => function () {
                    return PermissionGate::can('dashboard_stats/view') || PermissionGate::canAny(PermissionGate::readRoleCaps());
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/list-reference-data' => [
                'label'       => __('List Reference Data', 'fluent-cart'),
                'description' => __('On-demand lookup lists kept out of get-store-context to keep it lean: coupons, labels, gateways, tax_classes, shipping_zones, product_categories. Pass kinds[] with only what you need. Kinds your role cannot see are reported in meta.warnings, not dropped silently. The coupons kind is a capped snapshot (newest 200, each with times_used) — to filter by status/code, paginate, or find usable-now coupons, use list-coupons instead.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'kinds' => [
                            'type'        => 'array',
                            'description' => 'Which reference lists to return.',
                            'items'       => ['type' => 'string', 'enum' => ['coupons', 'labels', 'gateways', 'tax_classes', 'shipping_zones', 'product_categories']],
                        ],
                    ],
                    'required' => ['kinds'],
                ],
                'execute_callback'    => [self::class, 'listReferenceData'],
                'permission_callback' => function () {
                    return PermissionGate::canAny(PermissionGate::readRoleCaps());
                },
                'annotations' => ['readonly' => true],
            ],
        ];
    }

    public static function getContext($params = [])
    {
        $userId   = get_current_user_id();
        $cacheKey = self::CACHE_PREFIX . $userId;

        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $context = self::buildContext($userId);
        set_transient($cacheKey, $context, self::CACHE_TTL);

        return $context;
    }

    private static function buildContext($userId)
    {
        $user    = get_user_by('ID', $userId);
        $isAdmin = $user && user_can($user, 'manage_options');

        $you = [
            'wp_user_id'  => (int) $userId,
            'name'        => $user ? $user->display_name : null,
            'email'       => $user ? $user->user_email : null,
            'is_admin'    => (bool) $isAdmin,
            'permissions' => array_values((array) PermissionManager::getUserPermissions()),
        ];

        $store = [
            'name'         => get_bloginfo('name'),
            'url'          => site_url(),
            'version'      => defined('FLUENTCART_VERSION') ? FLUENTCART_VERSION : null,
            // Must agree with the App::isProActive() check the Pro-gated paths
            // (advanced_filters, get-search-schema) actually run — a false here on
            // a Pro store makes an agent skip the whole advanced-search surface.
            'pro_active'   => App::isProActive(),
            'currency'     => MCPHelper::currencyContext(),
            'timezone'     => wp_timezone_string(),
            'current_time' => MCPHelper::toIso8601(DateTime::gmtNow()),
            // Named for the capability rather than the licence, so an agent does
            // not have to infer what pro_active buys it before spending a call on
            // get-search-schema (which rejects outright without Pro).
            'advanced_search' => App::isProActive() ? 'available' : 'unavailable',
        ];

        // Headline stats are dashboard data: gate them on dashboard_stats/view so
        // a narrow read role can still get context (enums, currency, permissions)
        // without seeing store-wide revenue/order/customer numbers.
        $canStats = PermissionGate::can('dashboard_stats/view');
        $stats    = $canStats ? self::buildStats() : null;

        return MCPHelper::envelope(
            $canStats ? self::summary($stats) : __('Store context loaded.', 'fluent-cart'),
            [
                'you'              => $you,
                'store'            => $store,
                'stats'            => $stats,
                'enums'            => apply_filters('fluent_cart/mcp_enums', self::enums()),
                'reference_kinds'  => self::referenceKinds(),
                'tool_index'       => self::toolIndex(),
                'guidelines'       => self::guidelines(),
            ]
        );
    }

    /**
     * Headline numbers. Each metric is isolated in safeCount/safeSum so one
     * failing query (e.g. a model that doesn't exist on a given install) yields
     * null for that stat instead of breaking the whole discovery call.
     */
    private static function buildStats()
    {
        $since30 = DateTime::gmtNow()->modify('-30 days')->format('Y-m-d H:i:s');

        return [
            'orders_total'         => self::safeCount(function () {
                return Order::query()->count();
            }),
            'orders_last_30d'      => self::safeCount(function () use ($since30) {
                return Order::query()->where('created_at', '>=', $since30)->count();
            }),
            'revenue_last_30d'     => self::safeMoney(function () use ($since30) {
                return (int) Order::query()
                    ->whereIn('payment_status', self::PAID_STATUSES)
                    ->where('created_at', '>=', $since30)
                    ->sum('total_paid');
            }),
            'customers_total'      => self::safeCount(function () {
                return Customer::query()->count();
            }),
            'active_subscriptions' => self::safeCount(function () {
                return Subscription::query()->where('status', 'active')->count();
            }),
            'products_published'   => self::safeCount(function () {
                if (!class_exists('\FluentCart\App\Models\Product')) {
                    return null;
                }
                // post_type is pinned by the model's global scope; a literal
                // here (and the wrong singular one) would match nothing.
                return \FluentCart\App\Models\Product::query()
                    ->where('post_status', 'publish')
                    ->count();
            }),
        ];
    }

    private static function safeCount(callable $fn)
    {
        try {
            $val = $fn();
            return $val === null ? null : (int) $val;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function safeMoney(callable $fn)
    {
        try {
            return MCPHelper::money((int) $fn());
        } catch (\Throwable $e) {
            return null;
        }
    }

    /* translators: %1$s: revenue amount, %2$d: orders in 30 days, %3$d: total customers */
    private static function summary($stats)
    {
        $rev30   = isset($stats['revenue_last_30d']['display']) ? $stats['revenue_last_30d']['display'] : '—';
        $orders30 = isset($stats['orders_last_30d']) ? (int) $stats['orders_last_30d'] : 0;
        $customers = isset($stats['customers_total']) ? (int) $stats['customers_total'] : 0;

        return sprintf(
            /* translators: %1$s: 30-day revenue, %2$d: 30-day order count, %3$d: total customers */
            __('Store snapshot — last 30 days: %1$s across %2$d orders; %3$d customers total.', 'fluent-cart'),
            $rev30,
            $orders30,
            $customers
        );
    }

    /** Tells the agent what `kinds` it can pass to list-reference-data. */
    private static function referenceKinds()
    {
        return ['coupons', 'labels', 'gateways', 'tax_classes', 'shipping_zones', 'product_categories'];
    }

    /**
     * Task → tool routing table so an agent picks the right ability among ~30
     * without trial and error, grouped by intent (discovery / find / load /
     * analytics / write).
     *
     * Derived from the LIVE registry so a newly registered tool can never
     * silently go missing — each is annotated with a curated "reach for this
     * when…" hint, and any tool without one still appears under its label.
     * Filterable so pro / add-on tools can slot themselves in.
     */
    private static function toolIndex()
    {
        // [category, one-line "use this when…"], keyed by ability name.
        $hints = [
            'fluent-cart/get-store-context'          => ['discovery', 'Call first — identity, permissions, currency, enums, headline stats, and this index.'],
            'fluent-cart/list-reference-data'        => ['discovery', 'Resolve names to ids: coupons, labels, gateways, tax classes, shipping zones, product categories.'],
            'fluent-cart/get-search-schema'          => ['discovery', 'The advanced_filters reference for one entity — every filterable property, operators, value formats. Call before building an advanced search.'],
            'fluent-cart/list-orders'                => ['find', 'Find orders by status / payment / customer / product / date.'],
            'fluent-cart/list-customers'             => ['find', 'Find customers by name / email / location / LTV.'],
            'fluent-cart/list-products'              => ['find', 'Find products by title / category / price.'],
            'fluent-cart/list-subscriptions'         => ['find', 'Find subscriptions by status / plan / product; summary_only for a fast aggregate.'],
            'fluent-cart/list-coupons'               => ['find', 'Find coupons by status / code, with usage counts.'],
            'fluent-cart/list-transactions'          => ['find', 'The payment ledger across records — refunds last week, failed charges for dunning, one customer\'s payment history.'],
            'fluent-cart/get-inventory'              => ['find', 'Products at or below their stock threshold, or out of stock.'],
            'fluent-cart/get-order'                  => ['load', 'One order in full; include[] transactions / refunds / addresses / coupons / subscriptions.'],
            'fluent-cart/get-order-activity'         => ['load', 'The audit timeline for one order.'],
            'fluent-cart/get-customer'               => ['load', 'One customer profile; include[] orders / subscriptions.'],
            'fluent-cart/get-product'                => ['load', 'One product with variations; include[] sales / downloads.'],
            'fluent-cart/get-subscription'           => ['load', 'One subscription; include[] transactions / labels.'],
            'fluent-cart/get-product-financials'     => ['load', 'One product\'s money: one-time + installment + recurring, MRR / ARR, payment schedule.'],
            'fluent-cart/get-sales-report'           => ['analytics', 'The headline revenue number for a period, against the prior period.'],
            'fluent-cart/get-sales-trend'            => ['analytics', 'Revenue / order time series by hour / day / week / month.'],
            'fluent-cart/get-top-products'           => ['analytics', 'Best sellers by revenue or units.'],
            'fluent-cart/get-refund-report'          => ['analytics', 'Refund count, rate and amount for a period.'],
            'fluent-cart/get-upcoming-payments'      => ['analytics', 'Forward renewal cohort and at-risk revenue.'],
            'fluent-cart/query-orders'               => ['analytics', 'Flexible order metrics by dimension — revenue by payment_status / order_type / month.'],
            'fluent-cart/query-products'             => ['analytics', 'Product-line analytics — discount / margin leakage, by product / variation / order_type.'],
            'fluent-cart/query-customers'            => ['analytics', 'Customer analytics by country / state / status / cohort.'],
            'fluent-cart/query-subscriptions'        => ['analytics', 'Subscription analytics — contract vs recurring value, churn basis.'],
            'fluent-cart/query-sources'              => ['analytics', 'UTM attribution — revenue by source / medium / campaign.'],
            'fluent-cart/change-order-status'        => ['write', 'Set an order or shipping status.'],
            'fluent-cart/add-order-note'             => ['write', 'Add an internal note to an order.'],
            'fluent-cart/refund-order'               => ['write', 'Refund via the gateway — call dry_run first.'],
            'fluent-cart/upsert-customer'            => ['write', 'Create or update a customer.'],
            'fluent-cart/change-subscription-status' => ['write', 'Cancel a subscription — call dry_run first.'],
            'fluent-cart/manage-coupon'              => ['write', 'Create, update or deactivate a coupon.'],
            'fluent-cart/apply-labels'               => ['write', 'Add or remove labels on an order / customer / subscription.'],
        ];

        // Preserve intent order; empty groups are dropped below.
        $index = ['discovery' => [], 'find' => [], 'load' => [], 'analytics' => [], 'write' => [], 'other' => []];

        foreach (AbilitiesRegistrar::getDefinitions() as $name => $def) {
            $category = isset($hints[$name]) ? $hints[$name][0] : 'other';
            $hint     = isset($hints[$name]) ? $hints[$name][1] : (isset($def['label']) ? $def['label'] : $name);
            $short    = strpos($name, 'fluent-cart/') === 0 ? substr($name, strlen('fluent-cart/')) : $name;

            $index[$category][$short] = $hint;
        }

        $index = array_filter($index, function ($group) {
            return !empty($group);
        });

        return apply_filters('fluent_cart/mcp_tool_index', $index);
    }

    private static function guidelines()
    {
        $default = 'Call get-store-context once per session. Consult the tool_index in this payload to pick the right tool for a task, then use list-* and query-* tools to find and aggregate records and get-* tools to load one record fully. '
            . 'Money is returned as both a number (amount) and a formatted string (display) — quote display, compare amount. '
            . 'Dates are ISO-8601 UTC; pass a relative range (e.g. last_30_days) or explicit start_date/end_date to report tools. '
            . 'Use the exact enum values from this payload — never invent a status. '
            . 'Reports never sum across currencies; filter by one currency if the store has several. '
            // Stated as a fact about THIS store, not a generic "requires Pro":
            // an agent that reads the generic form still builds the filter and
            // only discovers the gate when the call is rejected.
            . (App::isProActive()
                ? 'When a list tool\'s named filters cannot express a segmentation (OR groups, relative dates, per-property operators, relation properties like transactions/UTM/labels), call get-search-schema for the entity and pass advanced_filters to its list tool. '
                : 'Advanced search is UNAVAILABLE on this store (store.advanced_search = unavailable): FluentCart Pro is not active, so get-search-schema and the advanced_filters parameter will be rejected. Do not build advanced_filters — use the named filters on the list-* tools and the query-* tools for aggregation. ')
            . 'Writes (refund-order, change-subscription-status:cancel) require a dry_run preview first.';

        return apply_filters('fluent_cart/mcp_guidelines', $default);
    }

    /**
     * `list-reference-data` — heavier lookup lists, fetched on demand.
     *
     * @param array $params { kinds: string[] } — which lists to return. Each
     *                      kind is gated by its own capability; kinds the
     *                      caller can't see are reported in `skipped`, not
     *                      silently dropped, so the agent knows why.
     */
    public static function listReferenceData($params = [])
    {
        $kinds = isset($params['kinds']) ? (array) $params['kinds'] : [];
        if (!$kinds) {
            return MCPHelper::error(
                'missing_kinds',
                __('Provide one or more kinds. Valid: coupons, labels, gateways, tax_classes, shipping_zones, product_categories.', 'fluent-cart'),
                ['valid_kinds' => self::referenceKinds()]
            );
        }

        $gate = [
            'coupons'            => 'coupons/view',
            'labels'             => 'labels/view',
            'gateways'           => 'dashboard_stats/view',
            'tax_classes'        => 'store/settings',
            'shipping_zones'     => 'store/settings',
            'product_categories' => 'products/view',
        ];

        $data    = [];
        $skipped = [];

        foreach ($kinds as $kind) {
            if (!isset($gate[$kind])) {
                $skipped[$kind] = 'unknown_kind';
                continue;
            }
            if (!PermissionGate::can($gate[$kind])) {
                $skipped[$kind] = 'forbidden: requires ' . $gate[$kind];
                continue;
            }
            $data[$kind] = self::fetchReferenceKind($kind);
        }

        $meta = $skipped ? ['warnings' => self::skipWarnings($skipped)] : [];

        return MCPHelper::envelope(
            sprintf(
                /* translators: %d: number of reference lists returned */
                _n('Returned %d reference list.', 'Returned %d reference lists.', count($data), 'fluent-cart'),
                count($data)
            ),
            $data,
            $meta
        );
    }

    private static function skipWarnings($skipped)
    {
        $out = [];
        foreach ($skipped as $kind => $reason) {
            $out[] = $kind . ': ' . $reason;
        }
        return $out;
    }

    /**
     * Each kind is fetched behind a class_exists guard so a model that isn't
     * present on a given install returns [] rather than fataling.
     */
    private static function fetchReferenceKind($kind)
    {
        try {
            if ($kind === 'coupons' && class_exists('\FluentCart\App\Models\Coupon')) {
                $coupons = \FluentCart\App\Models\Coupon::query()
                    ->select(['id', 'code', 'title', 'type', 'amount', 'status', 'use_count'])
                    ->orderBy('id', 'DESC')
                    ->limit(200)
                    ->get();
                $out = [];
                foreach ($coupons as $c) {
                    // Match list-coupons: numeric amount; fixed coupons stored in
                    // cents are reported in store currency, percentage as-is.
                    $amount = ($c->type === 'fixed')
                        ? 0 + \FluentCart\App\Helpers\Helper::toDecimalWithoutComma((int) $c->amount)
                        : (is_numeric($c->amount) ? 0 + $c->amount : $c->amount);
                    $out[] = [
                        'id'         => (int) $c->id,
                        'code'       => $c->code,
                        'title'      => $c->title,
                        'type'       => $c->type,
                        'amount'     => $amount,
                        'status'     => $c->status,
                        // Usage count so "how many times was code X used" is
                        // answerable without a second call. Alias times_used matches
                        // list-coupons.
                        'use_count'  => (int) $c->use_count,
                        'times_used' => (int) $c->use_count,
                    ];
                }
                return $out;
            }

            if ($kind === 'labels' && class_exists('\FluentCart\App\Models\Label')) {
                // fct_label stores a single (maybe-serialized) `value` column —
                // it may hold a plain title string or an array {title,color,…}.
                // Labels are user-created and can grow large; cap like coupons
                // so kinds[]=labels can't trigger an unbounded read/response.
                $labels = \FluentCart\App\Models\Label::query()->orderBy('id', 'ASC')->limit(200)->get();
                $out = [];
                foreach ($labels as $label) {
                    $val   = $label->value;
                    $entry = ['id' => (int) $label->id];
                    if (is_array($val)) {
                        $entry['title'] = isset($val['title']) ? $val['title'] : (isset($val['value']) ? $val['value'] : null);
                        if (isset($val['color'])) {
                            $entry['color'] = $val['color'];
                        }
                    } else {
                        $entry['title'] = $val;
                    }
                    $out[] = $entry;
                }
                return $out;
            }

            if ($kind === 'tax_classes' && class_exists('\FluentCart\App\Models\TaxClass')) {
                // fct_tax_classes labels its name column `title`, not `name`.
                return \FluentCart\App\Models\TaxClass::query()
                    ->select(['id', 'title'])
                    ->get()
                    ->toArray();
            }

            if ($kind === 'shipping_zones' && class_exists('\FluentCart\App\Models\ShippingZone')) {
                // fct_shipping_zones labels its name column `name`, not `title`.
                return \FluentCart\App\Models\ShippingZone::query()
                    ->select(['id', 'name', 'region'])
                    ->get()
                    ->toArray();
            }

            if ($kind === 'gateways') {
                return self::enabledGateways();
            }

            if ($kind === 'product_categories') {
                return self::productCategories();
            }
        } catch (\Throwable $e) {
            return [];
        }

        return [];
    }

    /**
     * Active payment gateways. Each gateway stores its own settings (there is no
     * single payment_settings option), so we read the registered gateway
     * instances from the GatewayManager and keep the ones with is_active=yes.
     */
    private static function enabledGateways()
    {
        $managerClass = '\FluentCart\App\Modules\PaymentMethods\Core\GatewayManager';
        if (!class_exists($managerClass) || !method_exists($managerClass, 'getInstance')) {
            return [];
        }

        try {
            $gateways = $managerClass::getInstance()->all();
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ((array) $gateways as $gateway) {
            if (!is_object($gateway) || !method_exists($gateway, 'getMeta')) {
                continue;
            }

            $settings = (isset($gateway->settings) && is_object($gateway->settings) && method_exists($gateway->settings, 'get'))
                ? (array) $gateway->settings->get()
                : [];

            $isActive = isset($settings['is_active'])
                ? ($settings['is_active'] === 'yes')
                : !empty($gateway->getMeta('status'));
            if (!$isActive) {
                continue;
            }

            $meta  = (array) $gateway->getMeta();
            $route = isset($meta['route']) ? $meta['route'] : null;
            $out[] = [
                'key'   => $route,
                'title' => isset($meta['title']) ? $meta['title'] : $route,
                'mode'  => isset($settings['payment_mode'])
                    ? $settings['payment_mode']
                    : (isset($settings['checkout_mode']) ? $settings['checkout_mode'] : null),
            ];
        }
        return $out;
    }

    /** Product categories from the WP taxonomy (best-effort across naming). */
    private static function productCategories()
    {
        foreach (['fluent-cart-category', 'product_cat', 'fluent_cart_category'] as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 200]);
            if (is_wp_error($terms)) {
                continue;
            }
            $out = [];
            foreach ($terms as $term) {
                $out[] = ['id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'count' => (int) $term->count];
            }
            return $out;
        }
        return [];
    }

    /**
     * Clear the cached context for all users. Hooked from MCPInit onto the
     * events that change anything the context payload reports.
     */
    public static function invalidateCache()
    {
        global $wpdb;

        $like = $wpdb->esc_like('_transient_' . self::CACHE_PREFIX) . '%';
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));

        $like = $wpdb->esc_like('_transient_timeout_' . self::CACHE_PREFIX) . '%';
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
    }
}

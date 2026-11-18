<?php

namespace FluentCart\App\Modules\MCP\Tools;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\MCP\Support\AdvancedSearch;
use FluentCart\App\Modules\MCP\Support\MCPHelper;
use FluentCart\App\Modules\MCP\Support\PermissionGate;
use FluentCart\App\Modules\MCP\Support\WriteGuard;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Payments\Refund;
use FluentCart\Api\Resource\OrderResource;
use FluentCart\Framework\Support\Arr;

/**
 * Order tools — find orders, then load one fully.
 *
 * Read surface (this file): list-orders (compact, filterable), get-order
 * (one order, include[]-driven), get-order-activity (the audit timeline).
 *
 * Parameter design notes for the agent's sake:
 *  - list-orders takes FLAT, enum-constrained filters (status, payment_status,
 *    …) rather than a freeform query object — the model negotiates against the
 *    schema at selection time, so flat + enum means fewer wrong calls.
 *  - Every filter is optional; omitting all returns the latest orders. Money
 *    filters (min_total/max_total) are in store-currency decimals, not cents —
 *    the agent thinks in dollars, we convert.
 *  - get-order accepts a numeric order_id (what list-orders returns) OR a
 *    uuid / invoice_no, so the agent never has to translate identifiers.
 *  - get-order is lean by default (items + customer); heavier sections
 *    (transactions, refunds, coupons, subscriptions, addresses) are opt-in via
 *    include[] so one order can't silently flood the context window.
 */
class OrderTools
{
    const UNRECORDED_REFUND_META = '_mcp_unrecorded_refund';

    public static function definitions()
    {
        $enums            = ContextTools::enums();
        $orderStatuses    = $enums['order_statuses'];
        $paymentStatuses  = $enums['payment_statuses'];
        $shippingStatuses = $enums['shipping_statuses'];
        // change-order-status cannot set an order back to "no shipping required".
        $shippingWritable = array_values(array_diff($shippingStatuses, ['none']));
        // Only the statuses core accepts for a manual change — a subset of the
        // order_statuses enum used for filtering ('failed' is reached through a
        // payment flow, not a manual set).
        $orderWritable    = array_keys(Status::getEditableOrderStatuses());
        $orderTypes       = $enums['order_types'];

        return [
            'fluent-cart/list-orders' => [
                'label'       => 'List Orders',
                'description' => 'Find and filter orders. Returns compact rows (id, number, customer, total, statuses, date, plus an items list: each line item\'s product, title and quantity) — call get-order for the full money/refund breakdown. All filters optional; combine freely. For one customer\'s orders, pass customer_email or customer_id here. Money filters are in store currency (e.g. 49.99), not cents. For conditions these flat filters cannot express (OR groups, relative dates, transaction/UTM/label/license properties) pass advanced_filters — call get-search-schema entity=orders first (Pro).',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status'          => ['type' => 'string', 'enum' => $orderStatuses, 'description' => 'Order fulfillment/lifecycle status. To find refunded orders use payment_status (refunded / partially_refunded), NOT this field: refunds are recorded as payment state, and status=refunded only ever appears on stores migrated from WooCommerce. pending here means an unpaid store-managed renewal invoice, which is not the same as payment_status=pending; a COD order sits at status=on-hold with payment_status=pending.'],
                        'payment_status'  => ['type' => 'string', 'enum' => $paymentStatuses, 'description' => 'Money state of the order — this is where refunded, partially_refunded, authorized and payment_scheduled live.'],
                        'shipping_status' => ['type' => 'string', 'enum' => $shippingStatuses],
                        'type'            => ['type' => 'string', 'enum' => $orderTypes, 'description' => 'payment = first purchase, renewal = subscription renewal.'],
                        'customer_id'     => ['type' => 'integer'],
                        'customer_email'  => ['type' => 'string', 'description' => 'Exact email — the most reliable customer filter.'],
                        'product_id'      => ['type' => 'integer', 'description' => 'Orders containing this product.'],
                        'coupon_code'     => ['type' => 'string'],
                        'country'         => ['type' => 'string', 'description' => 'ISO-2 country code on the billing address.'],
                        'currency'        => ['type' => 'string', 'description' => 'ISO currency code.'],
                        'min_total'       => ['type' => 'number', 'description' => 'Minimum order total in store currency.'],
                        'max_total'       => ['type' => 'number', 'description' => 'Maximum order total in store currency.'],
                        'created_after'   => ['type' => 'string', 'description' => 'YYYY-MM-DD or ISO 8601; store time unless it has an offset.'],
                        'created_before'  => ['type' => 'string', 'description' => 'YYYY-MM-DD or ISO 8601; store time unless it has an offset.'],
                        'mode'            => ['type' => 'string', 'enum' => ['live', 'test'], 'description' => 'Defaults to all modes.'],
                        'search'          => ['type' => 'string', 'description' => 'Matches invoice/receipt number, order uuid, and customer name/email.'],
                        'advanced_filters' => ['type' => 'array', 'items' => ['type' => ['object', 'array']], 'description' => 'Pro: condition groups {property, operator, value} — outer array = OR groups, inner = AND. Call get-search-schema entity=orders FIRST for properties/operators/format. AND-combines with the other filters here. An empty array means no advanced filter.'],
                        'sort_by'         => ['type' => 'string', 'enum' => ['id', 'created_at', 'completed_at', 'total_amount'], 'default' => 'id'],
                        'sort_type'       => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'default' => 'DESC'],
                        'fields'          => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional: return only these row keys to shrink the payload (order_id is always kept). Available: number, label, status, payment_status, shipping_status, type, total, customer, items, created_at. Omit for the full row.'],
                        'page'            => ['type' => 'integer', 'default' => 1],
                        'per_page'        => ['type' => 'integer', 'default' => 15, 'description' => 'Max 100.'],
                    ],
                ],
                'execute_callback'    => [self::class, 'listOrders'],
                'permission_callback' => function () {
                    return PermissionGate::can('orders/view');
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/get-order' => [
                'label'       => 'Get Order',
                'description' => sprintf(
                    'Full detail for one order: money breakdown, line items, and customer by default. Add include[] for any of: %1$s. Identify the order by order_id (numeric, from list-orders) OR uuid OR invoice_no.',
                    implode(', ', self::includeSections())
                ),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'order_id'   => ['type' => 'integer', 'description' => 'Numeric order id as returned by list-orders.'],
                        'uuid'       => ['type' => 'string'],
                        'invoice_no' => ['type' => 'string'],
                        'include'    => [
                            'type'        => 'array',
                            'description' => 'Optional heavier sections. items + customer are always included.',
                            'items'       => ['type' => 'string', 'enum' => self::includeSections()],
                        ],
                        'fields'     => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional: return only these top-level keys to shrink the payload (order_id is always kept). e.g. status, payment_status, totals, items, customer. Applies after include[]. Omit for the full record.'],
                    ],
                ],
                'execute_callback'    => [self::class, 'getOrder'],
                'permission_callback' => function () {
                    return PermissionGate::can('orders/view');
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/get-order-activity' => [
                'label'       => 'Get Order Activity',
                'description' => 'Audit timeline for one order: who did what and when. Covers status changes, payments, refunds, notes and emails sent. Refund and payment rows carry the amount (backfilled onto activity rows from the matching transaction), so you need not cross-reference. Use after get-order when you need history, not just current state.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'order_id' => ['type' => 'integer'],
                        'limit'    => ['type' => 'integer', 'default' => 30, 'description' => 'Max 100.'],
                    ],
                    'required' => ['order_id'],
                ],
                'execute_callback'    => [self::class, 'getOrderActivity'],
                'permission_callback' => function () {
                    return PermissionGate::can('orders/view');
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/change-order-status' => [
                'label'       => 'Change Order Status',
                'description' => 'Change an order status or shipping status. Pass order_id and at least one of order_status or shipping_status. A no-op is returned if the order is already in that status. To refund, use refund-order instead.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'order_id'        => ['type' => 'integer'],
                        'order_status'    => ['type' => 'string', 'enum' => $orderWritable, 'description' => 'New order lifecycle status. Only these are manually settable; refunded/partial-refund come from the refund flow, draft/pending/failed from payment.'],
                        'shipping_status' => ['type' => 'string', 'enum' => $shippingWritable, 'description' => 'New shipping status. Setting shipped/delivered marks items fulfilled.'],
                    ],
                    'required' => ['order_id'],
                ],
                'execute_callback'    => [self::class, 'changeOrderStatus'],
                'permission_callback' => function () {
                    return PermissionGate::can('orders/manage_statuses');
                },
                // Mutates, but reversible (a status can be set back) and no-op
                // aware, so not destructive. Setting the same status twice is a
                // no-op — idempotent.
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            ],

            'fluent-cart/add-order-note' => [
                'label'       => 'Add Order Note',
                'description' => 'Add an internal note to an order activity log. Visible to staff, not the customer.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'order_id' => ['type' => 'integer'],
                        'note'     => ['type' => 'string', 'description' => 'Note text. Plain text or simple HTML.'],
                    ],
                    'required' => ['order_id', 'note'],
                ],
                'execute_callback'    => [self::class, 'addOrderNote'],
                'permission_callback' => function () {
                    return PermissionGate::can('orders/manage');
                },
                // Appends a note (mutating, not destructive). Each call adds a
                // new note, so it is NOT idempotent.
                'annotations' => ['readonly' => false, 'destructive' => false],
            ],

            'fluent-cart/refund-order' => [
                'label'       => 'Refund Order',
                'description' => 'Refund an order through its gateway. ALWAYS call dry_run:true first to preview the refundable amount and get a confirm_token, then call again with that confirm_token plus an idempotency_key to execute — without the key a repeated execute could double-refund. amount is in the order\'s currency (e.g. 49.99), not cents. Omit to refund what the selected charge can still refund. The preview reports payment_mode and live_gateway_action; a LIVE refund requires operator opt-in, and test-mode always works.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'order_id'        => ['type' => 'integer'],
                        'amount'          => ['type' => 'number', 'description' => 'Amount to refund in the order\'s currency (e.g. 49.99), not cents. Omit to refund what the selected charge can still refund.'],
                        'transaction_id'  => ['type' => 'integer', 'description' => 'Charge transaction to refund against. Omit to use the latest successful charge.'],
                        'reason'          => ['type' => 'string'],
                        'dry_run'         => ['type' => 'boolean', 'description' => 'Preview without refunding. Returns a confirm_token. Do this first.'],
                        'confirm_token'   => ['type' => 'string', 'description' => 'From a prior dry_run. Required to execute.'],
                        'idempotency_key' => ['type' => 'string', 'description' => 'A unique string for this refund. Prevents double-refund on retry.'],
                    ],
                    'required' => ['order_id'],
                ],
                'execute_callback'    => [self::class, 'refundOrder'],
                'permission_callback' => function () {
                    return PermissionGate::can('orders/can_refund');
                },
                // Moves money via the gateway — the destructive write. readonly:false
                // is explicit so a client never mistakes it for a preview-only tool.
                'annotations' => ['readonly' => false, 'destructive' => true],
            ],
        ];
    }

    // -----------------------------------------------------------------
    // list-orders
    // -----------------------------------------------------------------

    public static function listOrders($params = [])
    {
        $paging = MCPHelper::pagination($params);

        // advanced_filters routes through the admin filter engine (validated
        // first — a bad condition errors, never silently drops); the named
        // filters below then AND onto the same query either way.
        $advWarnings = [];
        if (!empty($params['advanced_filters'])) {
            $built = AdvancedSearch::buildQuery('orders', $params['advanced_filters']);
            if (is_wp_error($built)) {
                return $built;
            }
            $query       = $built['query'];
            $advWarnings = $built['warnings'];
        } else {
            $query = Order::query();
        }

        // Eager-load customer plus a TRIMMED order_items relation — only the
        // columns needed for a "what's in this order" preview, never the full
        // money/refund/fulfillment row (that's get-order's job). formatRow caps
        // the preview, so even a large multi-item order can't flood the payload.
        // The product_id filter uses whereHas (a join), independent of this load.
        $query->with([
            'customer',
            'order_items' => function ($q) {
                $q->select(['id', 'order_id', 'post_id', 'post_title', 'title', 'quantity']);
            },
        ]);

        $filterError = self::applyFilters($query, $params);
        if (is_wp_error($filterError)) {
            return $filterError;
        }

        $sortBy   = self::allowed($params, 'sort_by', ['id', 'created_at', 'completed_at', 'total_amount'], 'id');
        $sortType = strtoupper(isset($params['sort_type']) ? $params['sort_type'] : 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        // Deterministic total order: tie-break on id so identical calls and
        // cursor paging never reshuffle rows.
        $query->orderBy($sortBy, $sortType);
        if ($sortBy !== 'id') {
            $query->orderBy('id', 'DESC');
        }

        $paginator = $query->paginate($paging['per_page'], ['*'], 'page', $paging['page']);
        $total     = self::total($paginator);

        $fields = isset($params['fields']) ? $params['fields'] : null;
        $rows   = [];
        foreach (MCPHelper::paginatorItems($paginator) as $order) {
            $rows[] = MCPHelper::pickFields(self::formatRow($order), $fields, ['order_id']);
        }

        $meta = MCPHelper::pagingMeta($paginator);
        if ($advWarnings) {
            $meta['warnings'] = $advWarnings;
        }

        return MCPHelper::envelope(
            sprintf(
                $total === 1 ? '%d order found.' : '%d orders found.',
                $total
            ),
            ['orders' => $rows],
            $meta
        );
    }

    private static function applyFilters($query, $params)
    {
        foreach (['status', 'payment_status', 'type', 'currency', 'mode'] as $col) {
            if (!empty($params[$col])) {
                $query->where($col, sanitize_text_field($params[$col]));
            }
        }

        // shipping_status: the reported 'none' maps to the empty/NULL stored value.
        if (!empty($params['shipping_status'])) {
            $shipping = sanitize_text_field($params['shipping_status']);
            if ($shipping === 'none') {
                $query->where(function ($q) {
                    $q->whereNull('shipping_status')->orWhere('shipping_status', '');
                });
            } else {
                $query->where('shipping_status', $shipping);
            }
        }

        if (!empty($params['customer_id'])) {
            $query->where('customer_id', (int) $params['customer_id']);
        }

        if (!empty($params['customer_email'])) {
            $email = sanitize_email($params['customer_email']);
            $query->whereHas('customer', function ($q) use ($email) {
                $q->where('email', $email);
            });
        }

        if (!empty($params['product_id'])) {
            $productId = (int) $params['product_id'];
            $query->whereHas('order_items', function ($q) use ($productId) {
                $q->where('post_id', $productId);
            });
        }

        if (!empty($params['coupon_code'])) {
            $code = sanitize_text_field($params['coupon_code']);
            $query->whereHas('appliedCoupons', function ($q) use ($code) {
                $q->where('code', $code);
            });
        }

        if (!empty($params['country'])) {
            $country = sanitize_text_field($params['country']);
            $query->whereHas('billing_address', function ($q) use ($country) {
                $q->where('country', $country);
            });
        }

        if (isset($params['min_total'])) {
            $query->where('total_amount', '>=', Helper::toCent($params['min_total']));
        }
        if (isset($params['max_total'])) {
            $query->where('total_amount', '<=', Helper::toCent($params['max_total']));
        }

        $reversed = MCPHelper::reversedBoundsError((array) $params, ['created_after' => 'created_before']);
        if ($reversed) {
            return $reversed;
        }
        foreach (['created_after' => '>=', 'created_before' => '<='] as $field => $op) {
            if (empty($params[$field])) {
                continue;
            }
            $date = MCPHelper::storeDateBound($params[$field], $op === '<=');
            if ($date === null) {
                return self::invalidDateError($field);
            }
            $query->where('created_at', $op, $date);
        }

        if (!empty($params['search'])) {
            $term = sanitize_text_field($params['search']);
            $like = '%' . $term . '%';
            $query->where(function ($q) use ($like) {
                $q->where('invoice_no', 'LIKE', $like)
                    ->orWhere('receipt_number', 'LIKE', $like)
                    ->orWhere('uuid', 'LIKE', $like)
                    ->orWhereHas('customer', function ($cq) use ($like) {
                        $cq->where('email', 'LIKE', $like)
                            ->orWhere('first_name', 'LIKE', $like)
                            ->orWhere('last_name', 'LIKE', $like);
                    });
            });
        }
    }

    /**
     * Refund timestamp. Falls back to the latest refund transaction's date when
     * the order's own refunded_at column is empty but money was refunded — some
     * refund paths don't stamp the column.
     */
    private static function refundedAt($order)
    {
        if ($order->refunded_at) {
            return MCPHelper::toIso8601($order->refunded_at);
        }
        if ((int) $order->total_refund > 0) {
            $txn = OrderTransaction::query()
                ->where('order_id', $order->id)
                ->where('transaction_type', 'refund')
                ->orderBy('id', 'DESC')
                ->first();
            if ($txn && $txn->created_at) {
                return MCPHelper::toIso8601($txn->created_at);
            }
        }
        return null;
    }

    /**
     * Report the shipping status, mapping an empty/NULL stored value to 'none'
     * (no shipping required — e.g. digital orders) so the value is always a
     * member of the advertised enum.
     */
    private static function shippingStatusOut($order)
    {
        return ($order->shipping_status !== null && $order->shipping_status !== '') ? $order->shipping_status : 'none';
    }

    /** Compact list row — only what's needed to scan and decide which to open. */
    private static function formatRow($order)
    {
        $customer = ($order->relationLoaded('customer') && $order->customer) ? $order->customer : null;

        return [
            'order_id'        => (int) $order->id,
            // null, not the raw id: an invoice number is only assigned once the
            // order is paid, and echoing the id here made unpaid orders look like
            // they had a number in a different format from every other row (and
            // disagreed with get-order, which already returns null). order_id is
            // right above it for referencing the record.
            'number'          => $order->invoice_no ? $order->invoice_no : null,
            'label'           => self::label($order, $customer),
            'status'          => $order->status,
            'payment_status'  => $order->payment_status,
            'shipping_status' => self::shippingStatusOut($order),
            'type'            => $order->type,
            'total'           => MCPHelper::moneyCompact($order->total_amount),
            'customer'        => $customer ? [
                'id'    => (int) $customer->id,
                'name'  => MCPHelper::personName($customer),
                'email' => $customer->email,
            ] : null,
            'items'           => self::itemsSummary($order),
            'created_at'      => MCPHelper::toIso8601($order->created_at),
        ];
    }

    /**
     * Compact "what was ordered" list for list rows: every line item as
     * product_id, display title (incl. variation), and quantity — enough for the
     * agent to recognize an order's contents without a get-order round-trip.
     * Prices and refund/fulfillment detail stay in get-order. Uncapped: a single
     * order won't realistically carry enough lines to bloat the payload.
     */
    private static function itemsSummary($order)
    {
        if (!$order->relationLoaded('order_items')) {
            return [];
        }

        $items = [];
        foreach ($order->order_items as $item) {
            $items[] = [
                'product_id' => (int) $item->post_id,
                'title'      => $item->getDisplayTitle(),
                'quantity'   => (int) $item->quantity,
            ];
        }

        return $items;
    }

    /** Human-readable one-liner: "Order INV-1042 — Jane Doe — $89.00 — paid". */
    private static function label($order, $customer)
    {
        $number = $order->invoice_no ? $order->invoice_no : ('#' . $order->id);
        $name   = $customer ? MCPHelper::personName($customer) : 'Guest';
        $total  = MCPHelper::displayAmount((int) $order->total_amount, $order->currency);

        return sprintf(
            'Order %1$s — %2$s — %3$s — %4$s',
            $number,
            $name,
            $total,
            $order->payment_status
        );
    }

    // -----------------------------------------------------------------
    // get-order
    // -----------------------------------------------------------------

    public static function getOrder($params = [])
    {
        $order = self::resolveOrder($params);
        if (is_wp_error($order)) {
            return $order;
        }

        $include = isset($params['include']) ? (array) $params['include'] : [];

        $order->load('customer', 'order_items');

        $data = [
            'order_id'        => (int) $order->id,
            'uuid'            => $order->uuid,
            // Normalized to null when unassigned — the column stores '' for an
            // order that has not been invoiced yet, and an empty string reads as
            // "the number is blank" rather than "there is no number".
            'number'          => $order->invoice_no ? $order->invoice_no : null,
            'receipt_number'  => $order->receipt_number ? $order->receipt_number : null,
            'status'          => $order->status,
            'payment_status'  => $order->payment_status,
            'shipping_status' => self::shippingStatusOut($order),
            'type'            => $order->type,
            'mode'            => $order->mode,
            'currency'        => $order->currency,
            'totals'          => self::totals($order),
            'customer'        => self::customerBlock($order),
            'items'           => self::itemsBlock($order),
            'created_at'      => MCPHelper::toIso8601($order->created_at),
            'completed_at'    => MCPHelper::toIso8601($order->completed_at),
            'refunded_at'     => self::refundedAt($order),
        ];

        if (in_array('addresses', $include, true)) {
            $data['addresses'] = self::addressesBlock($order);
        }
        if (in_array('transactions', $include, true)) {
            $data['transactions'] = self::transactionsBlock($order, false);
        }
        if (in_array('refunds', $include, true)) {
            $data['refunds'] = self::transactionsBlock($order, true);
        }
        if (in_array('coupons', $include, true)) {
            $data['coupons'] = self::couponsBlock($order);
        }
        if (in_array('subscriptions', $include, true)) {
            $data['subscriptions'] = self::subscriptionsBlock($order);
        }

        /**
         * The assembled get-order payload, for add-on sections registered through
         * fluent_cart/mcp_order_include_sections. Listeners should add their key
         * only when it is present in $context['include'].
         *
         * @since 1.0.0
         *
         * @param array $data    the order payload
         * @param array $context { order: Order, include: string[] }
         */
        $data = apply_filters('fluent_cart/mcp_order_data', $data, [
            'order'   => $order,
            'include' => $include,
        ]);

        // fields projection runs last, so it can trim both the base record and any
        // include[] sections; order_id is always kept.
        $fields = isset($params['fields']) ? $params['fields'] : null;

        return MCPHelper::envelope(self::label($order, $order->customer), MCPHelper::pickFields($data, $fields, ['order_id']));
    }

    /**
     * The sections get-order's include[] accepts. Filterable so an integration
     * that owns order-adjacent context (the CRM contact behind the buyer, for
     * one) can offer it as an include rather than leaving the agent to guess
     * which other tool holds it.
     *
     * A section added here MUST be populated by a listener on
     * fluent_cart/mcp_order_data — an include the schema advertises but nothing
     * fills is worse than no include at all.
     *
     * @return array
     */
    private static function includeSections()
    {
        $sections = ['transactions', 'refunds', 'addresses', 'coupons', 'subscriptions'];

        /**
         * Extra include[] section names for get-order.
         *
         * @since 1.0.0
         *
         * @param array $sections section names offered in the include[] enum
         */
        $sections = apply_filters('fluent_cart/mcp_order_include_sections', $sections);

        return array_values(array_unique(array_map('strval', (array) $sections)));
    }

    private static function resolveOrder($params)
    {
        if (!empty($params['order_id'])) {
            $order = Order::query()->where('id', (int) $params['order_id'])->first();
        } elseif (!empty($params['uuid'])) {
            $order = Order::query()->where('uuid', sanitize_text_field($params['uuid']))->first();
        } elseif (!empty($params['invoice_no'])) {
            $order = Order::query()->where('invoice_no', sanitize_text_field($params['invoice_no']))->first();
        } else {
            return MCPHelper::error(
                'invalid_param',
                'Provide order_id, uuid, or invoice_no.',
                ['fields' => ['order_id', 'uuid', 'invoice_no'], 'hint' => 'Use list-orders to find an order_id.']
            );
        }

        if (!$order) {
            return MCPHelper::error('order_not_found', 'No order found for the given identifier.');
        }

        return $order;
    }

    /** Full money breakdown — every line a money object (decimal + cents + display). */
    private static function totals($order)
    {
        $currency = $order->currency;
        return [
            'subtotal'              => MCPHelper::money($order->subtotal, $currency),
            'manual_discount_total' => MCPHelper::money($order->manual_discount_total, $currency),
            'coupon_discount_total' => MCPHelper::money($order->coupon_discount_total, $currency),
            'tax_total'             => MCPHelper::money($order->tax_total, $currency),
            'shipping_total'        => MCPHelper::money($order->shipping_total, $currency),
            'fee_total'             => MCPHelper::money($order->fee_total, $currency),
            'total_amount'          => MCPHelper::money($order->total_amount, $currency),
            'total_paid'            => MCPHelper::money($order->total_paid, $currency),
            'total_refund'          => MCPHelper::money($order->total_refund, $currency),
        ];
    }

    private static function customerBlock($order)
    {
        if (!$order->customer) {
            return null;
        }
        $c = $order->customer;
        return [
            'id'    => (int) $c->id,
            'name'  => MCPHelper::personName($c),
            'email' => $c->email,
        ];
    }

    private static function itemsBlock($order)
    {
        $items = [];
        if (!$order->relationLoaded('order_items')) {
            return $items;
        }
        foreach ($order->order_items as $item) {
            $items[] = [
                'id'            => (int) $item->id,
                'product_id'    => (int) $item->post_id,
                'variation_id'  => (int) $item->object_id,
                'title'         => $item->post_title ? $item->post_title : $item->title,
                'quantity'      => (int) $item->quantity,
                'fulfilled_qty' => (int) $item->fulfilled_quantity,
                'unit_price'    => MCPHelper::money($item->unit_price, $order->currency),
                'line_total'    => MCPHelper::money($item->line_total, $order->currency),
                'refund_total'  => MCPHelper::money($item->refund_total, $order->currency),
            ];
        }
        return $items;
    }

    private static function addressesBlock($order)
    {
        $order->load('order_addresses');
        $out = ['billing' => null, 'shipping' => null];
        if (!$order->relationLoaded('order_addresses')) {
            return $out;
        }
        foreach ($order->order_addresses as $addr) {
            $block = [
                'name'      => $addr->name,
                'address_1' => $addr->address_1,
                'address_2' => $addr->address_2,
                'city'      => $addr->city,
                'state'     => $addr->state,
                'postcode'  => $addr->postcode,
                'country'   => $addr->country,
                'phone'     => $addr->phone,
                'email'     => $addr->email,
            ];
            if ($addr->type === 'shipping') {
                $out['shipping'] = $block;
            } else {
                $out['billing'] = $block;
            }
        }
        return $out;
    }

    private static function transactionsBlock($order, $refundsOnly)
    {
        $order->load('transactions');
        $out = [];
        if (!$order->relationLoaded('transactions')) {
            return $out;
        }
        foreach ($order->transactions as $txn) {
            $isRefund = $txn->transaction_type === 'refund';
            if ($refundsOnly !== $isRefund) {
                continue;
            }
            $currency = $txn->currency ? $txn->currency : $order->currency;
            $out[] = [
                'id'               => (int) $txn->id,
                'type'             => $txn->transaction_type,
                'status'           => $txn->status,
                'payment_method'   => $txn->payment_method,
                'amount'           => MCPHelper::money($txn->total, $currency),
                'card_last_4'      => $txn->card_last_4,
                'card_brand'       => $txn->card_brand,
                'vendor_charge_id' => $txn->vendor_charge_id,
                'created_at'       => MCPHelper::toIso8601($txn->created_at),
            ];
        }
        return $out;
    }

    private static function couponsBlock($order)
    {
        $order->load('appliedCoupons');
        $out = [];
        if (!$order->relationLoaded('appliedCoupons')) {
            return $out;
        }
        foreach ($order->appliedCoupons as $coupon) {
            $out[] = [
                'code'   => $coupon->code,
                'amount' => MCPHelper::money($coupon->amount, $order->currency),
            ];
        }
        return $out;
    }

    private static function subscriptionsBlock($order)
    {
        $order->load('subscriptions');
        $out = [];
        if (!$order->relationLoaded('subscriptions')) {
            return $out;
        }
        foreach ($order->subscriptions as $sub) {
            $out[] = [
                'id'                => (int) $sub->id,
                'status'            => $sub->status,
                'item_name'         => $sub->item_name,
                'recurring_total'   => MCPHelper::money($sub->recurring_total, $order->currency),
                'billing_interval'  => $sub->billing_interval,
                'next_billing_date' => MCPHelper::toIso8601($sub->next_billing_date),
            ];
        }
        return $out;
    }

    // -----------------------------------------------------------------
    // get-order-activity
    // -----------------------------------------------------------------

    public static function getOrderActivity($params = [])
    {
        if (empty($params['order_id'])) {
            return MCPHelper::error('invalid_param', 'order_id is required.');
        }

        $orderId = (int) $params['order_id'];
        $limit   = isset($params['limit']) ? min(max((int) $params['limit'], 1), 100) : 30;

        $order = Order::query()->where('id', $orderId)->first();
        if (!$order) {
            return MCPHelper::error('order_not_found', 'No order found for the given order_id.');
        }

        $events = [];

        // Logged activity: status changes, notes, emails — all written to
        // fct_activity. Fetch up to $limit; the merge below trims to $limit total.
        if (class_exists('\FluentCart\App\Models\Activity')) {
            $rows = \FluentCart\App\Models\Activity::query()
                ->where('module_id', $orderId)
                ->where(function ($q) {
                    $q->where('module_type', Order::class)->orWhere('module_name', 'order');
                })
                ->orderBy('id', 'DESC')
                ->limit($limit)
                ->get();

            foreach ($rows as $row) {
                $events[] = [
                    '_sort'          => (string) $row->created_at,
                    '_ts'            => self::toTs($row->created_at),
                    'event'          => self::activityEvent($row),
                    'source'         => 'activity',
                    'title'          => $row->title,
                    'status'         => $row->status,
                    'content'        => MCPHelper::htmlToText($row->content),
                    'by'             => $row->created_by,
                    'amount'         => null,
                    'payment_method' => null,
                    'reference'      => null,
                    'created_at'     => MCPHelper::toIso8601($row->created_at),
                ];
            }
        }

        // Money events: charges and refunds from the transactions ledger. These
        // are the payment/refund timeline entries the activity log doesn't carry.
        $order->load('transactions');
        $refundTxns = [];
        $chargeTxns = [];
        if ($order->relationLoaded('transactions')) {
            foreach ($order->transactions as $txn) {
                $type   = $txn->transaction_type ? $txn->transaction_type : 'charge';
                $event  = ($type === 'refund') ? 'refund' : (($type === 'charge') ? 'payment' : $type);
                $amount = MCPHelper::money($txn->total, $txn->currency ? $txn->currency : null);
                $ts     = self::toTs($txn->created_at);
                $events[] = [
                    '_sort'          => (string) $txn->created_at,
                    '_ts'            => $ts,
                    'event'          => $event,
                    'source'         => 'transaction',
                    'title'          => self::txnTitle($type, $txn),
                    'status'         => $txn->status,
                    'content'        => null,
                    'by'             => null,
                    'amount'         => $amount,
                    'payment_method' => $txn->payment_method ? $txn->payment_method : null,
                    'reference'      => $txn->vendor_charge_id ? $txn->vendor_charge_id : null,
                    'created_at'     => MCPHelper::toIso8601($txn->created_at),
                ];
                if ($type === 'refund') {
                    $refundTxns[] = ['ts' => $ts, 'amount' => $amount];
                } elseif ($type === 'charge') {
                    $chargeTxns[] = ['ts' => $ts, 'amount' => $amount];
                }
            }
        }

        // Activity rows about a refund/payment don't store the amount (the Activity
        // model has no amount column), so a consumer previously had to cross-
        // reference the transaction rows. Backfill each such row from the money
        // event it mirrors — the closest refund/charge transaction on this order by
        // time — since the activity log is written seconds after its transaction in
        // the same request, so the amount is known and no cross-reference is needed.
        foreach ($events as &$moneyRow) {
            if ($moneyRow['source'] !== 'activity' || $moneyRow['amount'] !== null) {
                continue;
            }
            $kind = self::activityMoneyKind($moneyRow['title']);
            if ($kind === 'refund') {
                $moneyRow['amount'] = self::nearestTxnAmount($moneyRow['_ts'], $refundTxns);
            } elseif ($kind === 'payment') {
                $moneyRow['amount'] = self::nearestTxnAmount($moneyRow['_ts'], $chargeTxns);
            }
        }
        unset($moneyRow);

        // Merge both streams most-recent-first, then cap at $limit.
        usort($events, function ($a, $b) {
            return strcmp($b['_sort'], $a['_sort']);
        });
        $events = array_slice($events, 0, $limit);
        foreach ($events as &$event) {
            unset($event['_sort'], $event['_ts']);
        }
        unset($event);

        return MCPHelper::envelope(
            sprintf(
                count($events) === 1 ? '%1$d timeline entry for order #%2$d.' : '%1$d timeline entries for order #%2$d.',
                count($events),
                $orderId
            ),
            ['timeline' => $events]
        );
    }

    /** Classify an activity-log row into a coarse timeline event kind. */
    private static function activityEvent($row)
    {
        $title = strtolower((string) $row->title);
        if (strpos($title, 'email') !== false) {
            return 'email';
        }
        if (strpos($title, 'status') !== false || strpos($title, 'refund') !== false) {
            return 'status';
        }
        if ($row->log_type === 'api') {
            return 'api';
        }
        return 'note';
    }

    /**
     * Classify an activity row's money kind from its title so its amount can be
     * backfilled from the matching transaction. Title-only (not content) to avoid
     * false positives like a note that merely mentions "refund".
     */
    private static function activityMoneyKind($title)
    {
        $t = strtolower((string) $title);
        if (strpos($t, 'refund') !== false) {
            return 'refund';
        }
        if (strpos($t, 'payment') !== false || strpos($t, 'charge') !== false || strpos($t, 'captured') !== false) {
            return 'payment';
        }
        return null;
    }

    /**
     * Amount of the transaction closest in time to $ts, from a pool of
     * ['ts' => int|null, 'amount' => money] entries. Returns null if $ts is unknown
     * or the pool is empty. Refund activity rows match only refund transactions and
     * payment rows only charges, so the nearest by time is the right money event.
     */
    private static function nearestTxnAmount($ts, array $pool)
    {
        if ($ts === null || !$pool) {
            return null;
        }
        $best     = null;
        $bestDiff = null;
        foreach ($pool as $entry) {
            if ($entry['ts'] === null) {
                continue;
            }
            $diff = abs($entry['ts'] - $ts);
            if ($bestDiff === null || $diff < $bestDiff) {
                $bestDiff = $diff;
                $best     = $entry['amount'];
            }
        }
        return $best;
    }

    /** Parse a stored GMT datetime to a UTC unix timestamp; null on empty/zero-date. */
    private static function toTs($value)
    {
        if (!$value || strpos((string) $value, '0000-00-00') === 0) {
            return null;
        }
        try {
            return (new \DateTime((string) $value, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Exception $e) {
            return null;
        }
    }

    /** Human-readable title for a transaction timeline entry. */
    private static function txnTitle($type, $txn)
    {
        $method = $txn->payment_method ? $txn->payment_method : 'gateway';
        if ($type === 'refund') {
            return sprintf('Refund via %1$s — %2$s', $method, $txn->status);
        }
        if ($type === 'charge') {
            return sprintf('Payment via %1$s — %2$s', $method, $txn->status);
        }
        return sprintf('%1$s via %2$s — %3$s', $type, $method, $txn->status);
    }

    // -----------------------------------------------------------------
    // change-order-status (write)
    // -----------------------------------------------------------------

    public static function changeOrderStatus($params = [])
    {
        if (empty($params['order_id'])) {
            return MCPHelper::error('invalid_param', 'order_id is required.');
        }
        $orderId = (int) $params['order_id'];
        $order   = Order::query()->where('id', $orderId)->first();
        if (!$order) {
            return MCPHelper::error('order_not_found', 'No order found for the given order_id.');
        }

        $targetOrderStatus = isset($params['order_status']) ? sanitize_text_field($params['order_status']) : null;
        $targetShipStatus  = isset($params['shipping_status']) ? sanitize_text_field($params['shipping_status']) : null;

        if ($targetOrderStatus === null && $targetShipStatus === null) {
            return MCPHelper::error('invalid_param', 'Provide order_status and/or shipping_status.', ['fields' => ['order_status', 'shipping_status']]);
        }

        // Validate server-side against the statuses core actually accepts, so a
        // client that ignores the advertised enum gets a precise error rather
        // than a generic core rejection or a silent no-op.
        $editableOrder = array_keys(Status::getEditableOrderStatuses());
        if ($targetOrderStatus !== null && !in_array($targetOrderStatus, $editableOrder, true)) {
            return MCPHelper::error(
                'invalid_param',
                sprintf(
                    'order_status "%1$s" cannot be set manually. Allowed: %2$s.',
                    $targetOrderStatus,
                    implode(', ', $editableOrder)
                ),
                ['fields' => ['order_status'], 'allowed' => $editableOrder]
            );
        }
        $editableShip = array_keys(Status::getEditableShippingStatuses());
        if ($targetShipStatus !== null && !in_array($targetShipStatus, $editableShip, true)) {
            return MCPHelper::error(
                'invalid_param',
                sprintf(
                    'shipping_status "%1$s" is not settable. Allowed: %2$s.',
                    $targetShipStatus,
                    implode(', ', $editableShip)
                ),
                ['fields' => ['shipping_status'], 'allowed' => $editableShip]
            );
        }

        $changed   = [];
        $noChange  = [];
        $notApplied = [];

        if ($targetOrderStatus !== null) {
            if ($order->status === $targetOrderStatus) {
                $noChange[] = 'order_status';
            } else {
                $res = OrderResource::updateStatuses([
                    'order'    => $order,
                    'action'   => 'change_order_status',
                    'statuses' => ['order_status' => $targetOrderStatus],
                ]);
                if (is_wp_error($res)) {
                    return $res;
                }
                // Confirm the change actually took: core can no-op without error.
                $order = Order::query()->where('id', $orderId)->first();
                if ($order->status === $targetOrderStatus) {
                    $changed[] = 'order_status';
                } else {
                    $notApplied[] = 'order_status';
                }
            }
        }

        if ($targetShipStatus !== null) {
            $order = Order::query()->where('id', $orderId)->first();
            if ($order->shipping_status === $targetShipStatus) {
                $noChange[] = 'shipping_status';
            } else {
                $res = OrderResource::updateStatuses([
                    'order'    => $order,
                    'action'   => 'change_shipping_status',
                    'statuses' => ['shipping_status' => $targetShipStatus],
                ]);
                if (is_wp_error($res)) {
                    // Partial failure: report what already changed so the agent
                    // doesn't blindly re-apply the whole call (side effects fired).
                    if ($changed) {
                        $order = Order::query()->where('id', $orderId)->first();
                        return MCPHelper::error(
                            'partial_failure',
                            sprintf(
                                'Applied %1$s, but the shipping status change failed: %2$s. Do not re-run the whole call — retry only shipping_status.',
                                implode(', ', $changed),
                                $res->get_error_message()
                            ),
                            [
                                'order_id'        => $orderId,
                                'changed'         => $changed,
                                'failed'          => ['field' => 'shipping_status', 'error' => $res->get_error_message()],
                                'status'          => $order->status,
                                'shipping_status' => self::shippingStatusOut($order),
                            ]
                        );
                    }
                    return $res;
                }
                $order = Order::query()->where('id', $orderId)->first();
                if (self::shippingStatusOut($order) === $targetShipStatus) {
                    $changed[] = 'shipping_status';
                } else {
                    $notApplied[] = 'shipping_status';
                }
            }
        }

        $order = Order::query()->where('id', $orderId)->first();

        $summary = $changed
            ? sprintf(
                'Updated %1$s on order #%2$d.',
                implode(', ', $changed),
                $orderId
            )
            : 'No change — the order is already in the requested status.';

        return MCPHelper::envelope($summary, [
            'order_id'        => $orderId,
            'status'          => $order->status,
            'shipping_status' => self::shippingStatusOut($order),
            'changed'         => $changed,
            'no_change'       => $noChange,
            'not_applied'     => $notApplied,
        ]);
    }

    // -----------------------------------------------------------------
    // add-order-note (write)
    // -----------------------------------------------------------------

    public static function addOrderNote($params = [])
    {
        if (empty($params['order_id']) || empty($params['note'])) {
            return MCPHelper::error('invalid_param', 'order_id and note are required.', ['fields' => ['order_id', 'note']]);
        }
        $orderId = (int) $params['order_id'];
        $order   = Order::query()->where('id', $orderId)->first();
        if (!$order) {
            return MCPHelper::error('order_not_found', 'No order found for the given order_id.');
        }

        $note = wp_kses_post($params['note']);

        $log = fluent_cart_add_log(
            // Stored in the order activity log, which staff read in the admin: translated
            __('Note added via AI assistant', 'fluent-cart'),
            $note,
            'info',
            [
                'module_name' => 'order',
                'module_id'   => $orderId,
                'module_type' => Order::class,
                'log_type'    => 'activity',
            ]
        );

        // Confirm the activity row was actually written before claiming success.
        if (is_wp_error($log) || !is_object($log) || empty($log->id)) {
            return MCPHelper::error(
                'note_not_added',
                'The note could not be saved to the order activity log.',
                ['order_id' => $orderId, 'retryable' => true]
            );
        }

        return MCPHelper::envelope(
            sprintf(
                'Note added to order #%d.',
                $orderId
            ),
            ['order_id' => $orderId, 'note_id' => (int) $log->id, 'note' => MCPHelper::htmlToText($note)]
        );
    }

    // -----------------------------------------------------------------
    // refund-order (write, destructive — dry_run + idempotency)
    // -----------------------------------------------------------------

    public static function refundOrder($params = [])
    {
        if (empty($params['order_id'])) {
            return MCPHelper::error('invalid_param', 'order_id is required.');
        }

        if (!empty($params['dry_run'])) {
            $refund = self::prepareRefund($params);
            if (is_wp_error($refund)) {
                return $refund;
            }

            list($order, $txn, $amountCents, $remaining, $fingerprint) = $refund;

            return MCPHelper::envelope(
                sprintf(
                    'Preview: refund %1$s of %2$s remaining on order #%3$d.',
                    MCPHelper::displayAmount($amountCents, $order->currency),
                    MCPHelper::displayAmount($remaining, $order->currency),
                    (int) $order->id
                ),
                WriteGuard::preview('fluent-cart/refund-order', 'order:' . $order->id, $fingerprint, [
                    'order_id'            => (int) $order->id,
                    'refundable'          => MCPHelper::money($remaining, $order->currency),
                    'amount'              => MCPHelper::money($amountCents, $order->currency),
                    'transaction'         => ['id' => (int) $txn->id, 'payment_method' => $txn->payment_method, 'payment_mode' => $txn->payment_mode],
                    'live_gateway_action' => WriteGuard::isLiveMode($txn->payment_mode),
                ])
            );
        }

        // Same per-order lock as OrderController::refundOrder(). The order is read inside it, so the
        // confirm compares the fingerprint against totals no other refund can change mid-request.
        $result = Refund::withOrderRefundLock((int) $params['order_id'], 0, function () use ($params) {
            return self::executeRefund($params);
        });

        if (is_wp_error($result) && $result->get_error_code() === 'refund_lock_timeout') {
            return new \WP_Error('refund_in_progress', 'Another refund is currently being processed for this order. Please try again in a moment.');
        }

        if (is_wp_error($result)) {
            return $result;
        }

        $order = Order::query()->where('id', (int) $params['order_id'])->first();

        return MCPHelper::envelope(
            sprintf(
                'Refunded %1$s on order #%2$d.',
                MCPHelper::displayAmount($result['amount_cents'], $order->currency),
                (int) $order->id
            ),
            [
                'order_id'       => (int) $order->id,
                'refunded'       => MCPHelper::money($result['amount_cents'], $order->currency),
                'payment_status' => $order->payment_status,
                'total_refund'   => MCPHelper::money($order->total_refund, $order->currency),
                'gateway_result' => is_array($result['gateway_result']) ? array_intersect_key($result['gateway_result'], array_flip(['vendor_refund_id', 'manual_refund'])) : null,
            ]
        );
    }

    /**
     * @return array|\WP_Error [order, charge transaction, amount cents, remaining cents, fingerprint]
     */
    private static function prepareRefund($params)
    {
        $order = Order::query()->where('id', (int) $params['order_id'])->first();
        if (!$order) {
            return MCPHelper::error('order_not_found', 'No order found for the given order_id.');
        }
        if (!$order->canBeRefunded()) {
            return MCPHelper::error('not_refundable', 'This order cannot be refunded in its current state.', ['current_state' => ['payment_status' => $order->payment_status]]);
        }

        $remaining = Refund::netPaidAmount($order);
        if ($remaining <= 0) {
            return MCPHelper::error('nothing_to_refund', 'There is no remaining refundable balance on this order.');
        }

        if (!empty($params['transaction_id'])) {
            // Same constraints as the auto-select branch: an explicit id must
            // still be a succeeded charge on this order, never a failed/pending/
            // refund transaction.
            $txn = OrderTransaction::query()
                ->where('order_id', $order->id)
                ->where('id', (int) $params['transaction_id'])
                ->where('transaction_type', 'charge')
                ->where('status', 'succeeded')
                ->first();
        } else {
            $txn = OrderTransaction::query()
                ->where('order_id', $order->id)
                ->where('transaction_type', 'charge')
                ->where('status', 'succeeded')
                ->orderBy('id', 'DESC')
                ->first();
        }
        if (!$txn) {
            return MCPHelper::error('transaction_not_found', 'No refundable charge transaction was found on this order.');
        }

        $txnRefundable = $txn->getMaxRefundableAmount();
        $amountCents = isset($params['amount']) ? Helper::toCent($params['amount']) : ($txnRefundable > 0 ? min($remaining, $txnRefundable) : $remaining);
        if ($amountCents <= 0) {
            return MCPHelper::error('invalid_param', 'Refund amount must be greater than zero.');
        }
        if ($amountCents > $remaining) {
            return MCPHelper::error(
                'refund_exceeds_remaining',
                sprintf(
                    'Refund %1$s exceeds the remaining refundable balance %2$s.',
                    MCPHelper::displayAmount($amountCents, $order->currency),
                    MCPHelper::displayAmount($remaining, $order->currency)
                ),
                ['current_state' => ['refundable_cents' => $remaining]]
            );
        }

        $invalid = (new Refund())->validateRefundAmount($txn, $order, $amountCents);
        if ($invalid) {
            return MCPHelper::error(
                $invalid->get_error_code(),
                $invalid->get_error_message(),
                ['current_state' => ['transaction_refundable_cents' => $txn->getMaxRefundableAmount()]]
            );
        }

        $unrecorded = $order->getMeta(self::UNRECORDED_REFUND_META);
        if (is_array($unrecorded) && !self::unrecordedRefundIsRecorded($order, $unrecorded)) {
            return MCPHelper::error(
                'unrecorded_refund_pending',
                'An earlier refund on this order went through the payment gateway but was not recorded in FluentCart. A store admin must record or reconcile it before another refund is made.',
                ['current_state' => ['unrecorded_refund' => $unrecorded], 'hint' => 'Ask a person to check the order in the FluentCart admin.']
            );
        }

        // Bind the exact previewed mutation (amount + transaction) into the
        // fingerprint so a token minted for one amount can't confirm another.
        $fingerprint = 'paid:' . (int) $order->total_paid
            . '|refund:' . (int) $order->total_refund
            . '|net:' . (int) $remaining
            . '|amount:' . (int) $amountCents
            . '|txn:' . (int) $txn->id;

        return [$order, $txn, $amountCents, $remaining, $fingerprint];
    }

    /**
     * Runs under the per-order refund lock.
     *
     * @return array|\WP_Error amount_cents, gateway_result
     */
    private static function executeRefund($params)
    {
        $refund = self::prepareRefund($params);
        if (is_wp_error($refund)) {
            return $refund;
        }

        list($order, $txn, $amountCents, , $fingerprint) = $refund;

        $tool = 'fluent-cart/refund-order';
        $entityKey = 'order:' . $order->id;

        $confirm = WriteGuard::confirm($tool, $entityKey, $fingerprint, isset($params['confirm_token']) ? $params['confirm_token'] : '');
        if (is_wp_error($confirm)) {
            return $confirm;
        }

        // Real-money guard: a live refund needs explicit opt-in (test always OK).
        $liveGate = WriteGuard::liveGatewayAllowed($txn->payment_mode);
        if (is_wp_error($liveGate)) {
            return $liveGate;
        }

        $reason  = isset($params['reason']) ? sanitize_text_field($params['reason']) : '';
        $idemKey = isset($params['idempotency_key']) ? (string) $params['idempotency_key'] : '';

        // The marker is written inside the callback so an idempotent replay never rewrites it.
        $result = WriteGuard::idempotent($tool, $entityKey, $idemKey, function () use ($order, $txn, $amountCents, $reason) {
            $result = (new Refund())->processRefund($txn, $amountCents, ['reason' => $reason]);

            if (is_wp_error($result) && $result->get_error_code() === 'refund_not_recorded') {
                try {
                    $order->updateMeta(self::UNRECORDED_REFUND_META, [
                        'vendor_refund_id' => Arr::get($result->get_error_data(), 'vendor_refund_id'),
                        'amount'           => $amountCents,
                        'at'               => DateTime::gmtNow()->format('Y-m-d H:i:s'),
                    ]);
                } catch (\Throwable $e) {
                    // The database that lost the refund row can lose the marker too; the error must still reach the caller.
                }
            }

            return $result;
        });

        if (is_wp_error($result)) {
            return $result;
        }

        return ['amount_cents' => $amountCents, 'gateway_result' => $result];
    }

    /**
     * The refund is recorded once a refund row carries its vendor refund id. Without a vendor refund id,
     * a refund row of the same amount recorded after the failure counts.
     */
    private static function unrecordedRefundIsRecorded($order, array $unrecorded)
    {
        $refunds = OrderTransaction::query()
            ->where('order_id', $order->id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->where('status', '!=', Status::TRANSACTION_FAILED);

        $vendorRefundId = Arr::get($unrecorded, 'vendor_refund_id');
        if ($vendorRefundId) {
            return $refunds->where('vendor_charge_id', $vendorRefundId)->exists();
        }

        return $refunds
            ->where('total', (int) Arr::get($unrecorded, 'amount'))
            ->where('created_at', '>', Arr::get($unrecorded, 'at', ''))
            ->exists();
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    private static function allowed($params, $key, array $allowed, $default)
    {
        $val = isset($params[$key]) ? $params[$key] : $default;
        return in_array($val, $allowed, true) ? $val : $default;
    }

    private static function total($paginator)
    {
        return MCPHelper::paginatorTotal($paginator);
    }

    private static function invalidDateError($field)
    {
        return MCPHelper::error(
            'invalid_param',
            sprintf(
                '%1$s is not a valid date. Use YYYY-MM-DD or ISO 8601.',
                $field
            ),
            ['fields' => [$field]]
        );
    }
}

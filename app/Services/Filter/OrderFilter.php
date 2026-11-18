<?php

namespace FluentCart\App\Services\Filter;

use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;
use Closure;
use FluentCart\Api\ModuleSettings;
use FluentCart\App\App;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Models\SubscriptionMeta;
use FluentCart\Framework\Pagination\LengthAwarePaginator;
use FluentCart\App\Services\Report\ReportHelper;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;
use FluentCartPro\App\Modules\Licensing\Models\License;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;


class OrderFilter extends BaseFilter
{
    protected static function statusFilterKeys(): array
    {
        return ['payment_statuses', 'order_statuses', 'shipping_statuses'];
    }

    public function applySimpleFilter(?string $search = null): void
    {
        $search = $search ?? $this->search;
        $isApplied = $this->applySimpleOperatorFilter($search);
        if ($isApplied) {
            return;
        }

        foreach (static::statusFilterKeys() as $statusKey) {
            $this->query->when(Arr::get($this->args, $statusKey), function ($query, $values) use ($statusKey) {
                return $query->whereIn($statusKey, Arr::wrap($values));
            });
        }

        if (!empty($search)) {
            $search = trim($search);
            $searchLike = addcslashes($search, '\\%_');

            $this->query->where(function ($query) use ($search, $searchLike) {
                $query->where('invoice_no', 'LIKE', "%{$search}%")
                    ->orWhereHas('customer', function ($customerQuery) use ($search, $searchLike) {
                        $customerQuery
                            ->where('email', 'LIKE', "%{$search}%")
                            ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$searchLike}%"]);
                    })
                    ->orWhereHas('order_items', function ($query) use ($searchLike) {
                        $query->where('title', 'LIKE', "%{$searchLike}%");
                        $query->orWhere('post_title', 'LIKE', "%{$searchLike}%");
                    });
            });
        }
    }

    public function paginate($perPage = null): LengthAwarePaginator
    {
        $orders = parent::paginate($perPage);

        if (in_array('admin_order_list', Arr::wrap($this->with), true)) {
            static::attachInstallmentNumbers($orders->items());
        }

        return $orders;
    }

    /**
     * Gives the page's installment orders installment_number / installment_total ("Installment 3/6"), numbered as
     * Subscription::calculateBillCount() counts the plan's payments: its succeeded charges up to this order's charge,
     * plus billed_cycles_offset, less billed_cycles_deduction (a real trial's signup fee). A failed or voided invoice
     * has no succeeded charge, so it gets no number. Batched by order and by subscription, after the page query.
     *
     * @param Order[] $orders
     */
    protected static function attachInstallmentNumbers(array $orders): void
    {
        $ids = [];
        foreach ($orders as $order) {
            if (in_array($order->getAttribute('sale_type'), ['installment', 'installment_payment'], true)) {
                $ids[] = (int) $order->id;
            }
        }
        if (!$ids) {
            return;
        }

        $charges = function ($query) {
            return $query->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
                ->where('status', Status::TRANSACTION_SUCCEEDED)
                ->where('total', '>', 0);
        };

        // Each order's latest charge, then the plans it paid into
        $ownCharge = [];
        foreach ($charges(OrderTransaction::query())->whereIn('order_id', $ids)->orderBy('id')->get(['id', 'order_id', 'subscription_id']) as $charge) {
            $ownCharge[(int) $charge->order_id] = $charge;
        }
        $plans = Subscription::query()
            ->whereIn('id', array_unique(array_map(function ($charge) {
                return (int) $charge->subscription_id;
            }, $ownCharge)))
            ->where('bill_times', '>', 0)
            ->get(['id', 'bill_times'])
            ->keyBy('id');
        if ($plans->isEmpty()) {
            return;
        }
        $planIds = $plans->keys()->all();

        // A charge's place among its plan's payments
        $position = [];
        $counted = [];
        foreach ($charges(OrderTransaction::query())->whereIn('subscription_id', $planIds)->orderBy('id')->get(['id', 'subscription_id']) as $charge) {
            $planId = (int) $charge->subscription_id;
            $counted[$planId] = Arr::get($counted, $planId, 0) + 1;
            $position[(int) $charge->id] = $counted[$planId];
        }

        $shift = [];
        foreach (SubscriptionMeta::query()->whereIn('subscription_id', $planIds)->whereIn('meta_key', ['billed_cycles_offset', 'billed_cycles_deduction'])->get(['subscription_id', 'meta_key', 'meta_value']) as $meta) {
            $sign = $meta->meta_key === 'billed_cycles_offset' ? 1 : -1;
            $shift[(int) $meta->subscription_id] = Arr::get($shift, (int) $meta->subscription_id, 0) + $sign * (int) $meta->meta_value;
        }

        foreach ($orders as $order) {
            $charge = Arr::get($ownCharge, (int) $order->id);
            $plan = $charge ? $plans->get((int) $charge->subscription_id) : null;
            if (!$plan) {
                continue;
            }
            $number = (int) Arr::get($position, (int) $charge->id, 0) + (int) Arr::get($shift, (int) $plan->id, 0);
            $order->setAttribute('installment_number', $number > 0 ? $number : null);
            $order->setAttribute('installment_total', (int) $plan->bill_times);
        }
    }

    /**
     * An Order Type filter saved before the sale types has no filter_type and would compare the raw type column, so
     * every Order Type item goes through the sale-type callback.
     */
    protected function parseSearchGroups(?string $json = null): array
    {
        $groups = parent::parseSearchGroups($json);
        foreach ($groups as &$group) {
            foreach (Arr::get($group, 'order', []) as $index => $item) {
                if (Arr::get($item, 'property') === 'type') {
                    $group['order'][$index]['filter_type'] = 'custom';
                }
            }
        }
        unset($group);

        return $groups;
    }

    /**
     * ReportHelper::saleTypeSql() for the orders table of the list query.
     */
    protected static function saleTypeSql(): string
    {
        global $wpdb;

        return ReportHelper::saleTypeSql($wpdb->prefix . 'fct_orders.');
    }

    /**
     * @param string[] $types sale types from ReportHelper::saleTypeLabels()
     */
    protected static function whereSaleType($query, array $types, bool $exclude = false)
    {
        $placeholders = implode(', ', array_fill(0, count($types), '?'));

        if (!$exclude) {
            // Each sale type comes from one stored type: narrowing on the indexed column first spares a full-table CASE
            $stored = ['installment' => 'subscription', 'installment_payment' => 'renewal'];
            $query->whereIn('type', array_values(array_unique(array_map(function ($type) use ($stored) {
                return Arr::get($stored, $type, $type);
            }, $types))));
        }

        return $query->whereRaw(self::saleTypeSql() . ($exclude ? ' NOT IN ' : ' IN ') . "({$placeholders})", array_values($types));
    }

    public function tabsMap(): array
    {
        return [
            'on-hold'            => 'status',
            'paid'               => 'payment_status',
            //'unpaid'       => 'payment_status',
            'completed'          => 'status',
            'processing'         => 'status',
            // Sale types: an installment plan's orders are not subscription sign-ups or renewals
            'renewal'            => 'sale_type',
            'subscription'       => 'sale_type',
            'installment'        => 'sale_type',
            'onetime'            => 'sale_type',
            'refunded'           => 'payment_status',
            'partially_refunded' => 'payment_status',
            'upgraded_to'        => 'upgraded_to',
            'upgraded_from'      => 'upgraded_from',
            'b2b_purchase'       => 'b2b_purchase',
            'reverse_charge'     => 'reverse_charge',
        ];
    }

    public function getModel(): string
    {
        return Order::class;
    }

    public static function getFilterName(): string
    {
        return 'orders';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected static function sortableColumns(): array
    {
        return [
            'id'             => ['label' => __('Order ID', 'fluent-cart'), 'column' => 'id'],
            'total_amount'   => ['label' => __('Total', 'fluent-cart'), 'column' => 'total_amount'],
            'payment_status' => ['label' => __('Payment Status', 'fluent-cart'), 'column' => 'payment_status'],
            'status'         => ['label' => __('Order Status', 'fluent-cart'), 'column' => 'status'],
        ];
    }

    /**
     * Two tiers of key, and they answer two different questions.
     *
     * SCREEN keys — one per calling screen, loading exactly what that screen
     * renders. This filter serves TWO routes with different permission
     * baselines: `GET /orders` (`orders/view`) and `GET /customers/{id}/orders`
     * (`customers/view`). A screen key can be re-scoped for its own screen
     * without widening anybody else's payload, and each relation is gated
     * INSIDE the callback rather than inheriting the route's bar.
     *
     * PUBLIC keys — the plain relation names an external consumer can
     * reasonably ask an order endpoint for. `GET /orders` is a REST route with
     * consumers outside this repo, and `with[]=customer` has always meant "give
     * me the customer". These are first-class entry points, not aliases of the
     * screen keys: renaming them would have silently narrowed a published
     * response shape for everyone who was already sending them.
     *
     * Deliberately NOT allowlisted, at any depth: `transactions` (gateway ids,
     * card metadata), `licenses` (`license_key` serializes verbatim),
     * `customer.wpUser` (reaches the WordPress users row).
     *
     * @return array<string, callable>
     */
    protected function allowedWiths(): array
    {
        return [
            'admin_order_list'      => [$this, 'adminOrderList'],
            'admin_customer_orders' => [$this, 'adminCustomerOrders'],

            'order_items'                      => [$this, 'publicOrderItems'],
            'customer'                         => [$this, 'publicCustomer'],
            'customer.primary_billing_address' => [$this, 'publicCustomer'],
        ];
    }

    /**
     * `GET /orders`, sent by OrderTable.js — the line items under each row plus
     * the customer popover.
     *
     * No select on any segment: OrderItem::$appends is `payment_info`,
     * `setup_info`, `is_custom` and `variation_display_title`, which read
     * `other_info` and walk the row's subscription data; Customer::$appends
     * (`full_name`, `photo`, `country_name`, `formatted_address`, `user_link`)
     * reads first_name, last_name, country, state, city, postcode and user_id;
     * and CustomerInfoPopover.vue renders `purchase_count` plus
     * `primary_billing_address.phone`. Narrowing the columns blanks all of that.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return \FluentCart\Framework\Database\Orm\Builder
     */
    protected function adminOrderList($query)
    {
        // One-time, new subscription, renewal, installment sign-up or installment payment, as the reports read it.
        // select() adds the table prefix itself; the raw SQL names the prefixed table
        $query->select('fct_orders.*')->selectRaw(self::saleTypeSql() . ' AS sale_type');


        // Line items belong to the order the route already authorised on
        // `orders/view`, so they need no second bar of their own.
        $query->with(['order_items']);

        // A customer row is customer data even when it is reached from an
        // order, so this half carries the customers bar rather than the orders
        // one. The billing address rides in the SAME `with()` call as its
        // parent: each call array_merges into $eagerLoad, so a second call
        // naming `customer.x` would inject an empty closure over `customer`.
        if ($this->userCanAny('customers/view')) {
            $query->with([
                'customer',
                'customer.primary_billing_address',
            ]);
        }

        return $query;
    }

    /**
     * `GET /customers/{id}/orders`, sent by SingleCustomer.vue — the order
     * history on a customer's detail screen. The screen renders the line items
     * and nothing else; the customer is already the page.
     *
     * No extra permission check, and that is the decision rather than an
     * oversight: the route is gated on `customers/view` alone and the line
     * items carry no bar of their own today. Adding `orders/view` here would
     * silently strip the history for a role that holds `customers/view` and
     * nothing else. If the line items ever need `orders/view`, that is a
     * deliberate behaviour change and this screen has to be re-tested with a
     * customers-only role.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return \FluentCart\Framework\Database\Orm\Builder
     */
    protected function adminCustomerOrders($query)
    {
        return $query->with(['order_items']);
    }

    /**
     * `with[]=order_items` — a public entry point.
     *
     * UNGATED, deliberately. It was ungated before this allow-list existed, and
     * the line items are the order the route already authorised. Adding a bar
     * now would be a silent tightening hiding under a name consumers already
     * send, which is exactly the failure mode the public tier exists to avoid.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return \FluentCart\Framework\Database\Orm\Builder
     */
    protected function publicOrderItems($query)
    {
        return $query->with(['order_items']);
    }

    /**
     * `with[]=customer` and `with[]=customer.primary_billing_address` — both
     * public entry points, both served here.
     *
     * One callback for the two keys because the nested path can never be
     * allowed to reach the parent row without the parent's gate: `with('a.b')`
     * auto-injects `a`, so a separate ungated entry for the dotted path would
     * be a way around the check below. Loading the whole subtree from one
     * `with()` call closes that and the clobbering problem at once.
     *
     * Gated: the orders route's `orders/view` says nothing about customers.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return bool|\FluentCart\Framework\Database\Orm\Builder
     */
    protected function publicCustomer($query)
    {
        if (!$this->userCanAny('customers/view')) {
            return false;
        }

        return $query->with([
            'customer',
            'customer.primary_billing_address',
        ]);
    }

    public static function parseableKeys(): array
    {
        return array_merge(
            parent::parseableKeys(),
            static::statusFilterKeys()
        );
    }


    public static function b2bMetaCondition(): Closure
    {
        return function ($q) {
            $q->where('meta_key', 'business_info')
              ->whereRaw("(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta_value, '$.company_name')), '') IS NOT NULL OR NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta_value, '$.legal_registration_id')), '') IS NOT NULL OR NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta_value, '$.tax_number')), '') IS NOT NULL)");
        };
    }

    public function applyActiveViewFilter(?string $activeView = null): void
    {
        $activeView = $activeView ?? $this->activeView;
        $tabsMap = $this->tabsMap();

        //Apply Active Tab view
        $this->query = $this->query->when($activeView, function (Builder $query, $activeView) use ($tabsMap) {

            if ($activeView === 'upgraded_to') {
                return $query
                    ->whereRaw("JSON_EXTRACT(config, '$.upgraded_to') IS NOT NULL")
                    ->whereRaw("JSON_EXTRACT(config, '$.upgraded_to') != 0");
            } else if ($activeView === 'upgraded_from') {
                return $query
                    ->whereRaw("JSON_EXTRACT(config, '$.upgraded_from') IS NOT NULL")
                    ->whereRaw("JSON_EXTRACT(config, '$.upgraded_from') != 0");
            } else if ($activeView === 'b2b_purchase') {
                return $query->whereHas('orderMeta', static::b2bMetaCondition());
            } else if ($activeView === 'reverse_charge') {
                return $query->whereHas('orderTaxRates', function ($q) {
                    $q->whereRaw("JSON_EXTRACT(meta, '$.reverse_charge_applied') = true");
                });
            } else if ($tabsMap[$activeView] === 'sale_type') {
                $saleTypes = ['installment' => ['installment', 'installment_payment'], 'onetime' => ['payment']];

                return static::whereSaleType($query, Arr::get($saleTypes, $activeView, [$activeView]));
            } else {
                return $query->where(
                    $tabsMap[$activeView],
                    $activeView
                );
            }


        });
    }

    public static function getSearchableFields(): array
    {
        $fields = [
            'id'      => [
                'column'      => 'id',
                'description' => __('Order Id', 'fluent-cart'),
                'type'        => 'numeric',
                'examples'    => [
                    'id = 1',
                    'id > 5',
                    'id :: 1-10'
                ]
            ],
            'status'  => [
                'column'      => 'status',
                'description' => __('Search by order status e.g., completed, processing, on-hold, canceled, failed', 'fluent-cart'),
                'type'        => 'string',
                'examples'    => [
                    'status = completed',
                ]
            ],
            'invoice' => [
                'column'      => 'status',
                'description' => __('Invoice Number', 'fluent-cart'),
                'type'        => 'string'
            ],

            'payment'    => [
                'column'      => 'payment_status',
                'description' => __('Search by payment status e.g., paid, pending, partially_paid, refunded, partially_refunded', 'fluent-cart'),
                'type'        => 'string',
                'examples'    => [
                    'payment = paid',
                    'payment = partially_paid',
                    'payment = partially_refunded',
                ]
            ],
            'payment_by' => [
                'column'      => 'payment_method',
                'description' => __('Search by payment method e.g., stripe, PayPal, offline_payment', 'fluent-cart'),
                'type'        => 'string',
                'examples'    => [
                    'payment_by = stripe',
                    'payment_by = paypal',
                ]
            ],

            'customer' => [
                'description' => __('Search by customer name or email', 'fluent-cart'),
                'note'        => __("only supports '=' operator", 'fluent-cart'),
                'type'        => 'custom',
                'callback'    => function ($query, $search) {
                    $query->whereHas('customer', function ($query) use ($search) {
                        $query->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"])
                            ->orWhere('email', 'like', '%' . $search . '%');
                    });
                },
                'examples'    => [
                    'customer = jhon',
                ]
            ],
        ];


        if (class_exists(License::class)) {
            $fields['license'] = [
                'description' => __('Search by license key', 'fluent-cart'),
                'note'        => __("only supports '=' operator", 'fluent-cart'),
                'type'        => 'custom',
                'callback'    => function ($query, $search) {
                    $query->whereHas('licenses', function ($query) use ($search) {
                        $query->where('license_key', 'like', '%' . $search . '%');
                    });
                },
                'examples'    => [
                    'license = ff-78d47b3fed89bda25cdc5b60d0298d60',
                ]
            ];
        }

        return $fields;
    }

    /**
     * Currencies that have orders — code => code.
     */
    protected static function currencyOptions(): array
    {
        $codes = array_column(ReportCurrency::orderCurrencies(), 'currency');

        return $codes ? array_combine($codes, $codes) : [];
    }

    public static function advanceFilterOptions(): array
    {
        $manager = GatewayManager::getInstance();
        $payment_routes = $manager->getRoutes();
        $availablePaymentMethods = [];
        
        foreach ($payment_routes as $route) {
            $availablePaymentMethods[$route['name']] = Arr::get($route, 'meta.title', $route['name']);
        }

     
        $filters = [
            'order'        => [
                'label'    => __('Order Property', 'fluent-cart'),
                'value'    => 'order',
                'children' => [
                    [
                        'label'           => __('By Order Items', 'fluent-cart'),
                        'value'           => 'order_items',
                        'column'          => 'object_id',
                        'filter_type'     => 'relation',
                        'relation'        => 'order_items',
                        'remote_data_key' => 'product_variations',
                        'type'            => 'remote_tree_select',
                        'limit'           => 10,
                    ],
                    [
                        'label'       => __('Order Status', 'fluent-cart'),
                        'value'       => 'status',
                        'type'        => 'selections',
                        'options'     => [
                            'completed'  => _x('Completed', 'Order status', 'fluent-cart'),
                            'processing' => __('Processing', 'fluent-cart'),
                            'on-hold'    => __('On Hold', 'fluent-cart'),
                            'canceled'   => _x('Canceled', 'Order status', 'fluent-cart')
                        ],
                        'is_multiple' => true,
                        'is_only_in'  => true
                    ],
                    [
                        'label'       => __('Payment Status', 'fluent-cart'),
                        'value'       => 'payment_status',
                        'type'        => 'selections',
                        'options'     => [
                            'paid'               => _x('Paid', 'Payment status', 'fluent-cart'),
                            'pending'            => _x('Pending', 'Payment status', 'fluent-cart'),
                            'partially_paid'     => __('Partially Paid', 'fluent-cart'),
                            'refunded'           => _x('Refunded', 'Payment status', 'fluent-cart'),
                            'partially_refunded' => __('Partially Refunded', 'fluent-cart'),
                            //'authorized'         => __('Authorized', 'fluent-cart')
                        ],
                        'is_multiple' => true,
                        'is_only_in'  => true
                    ],
//                    [
//                        'label'       => __('Shipping Status', 'fluent-cart'),
//                        'value'       => 'shipping_status',
//                        'type'        => 'selections',
//                        'options'     => [
//                            'fulfilled'   => __('Fulfilled', 'fluent-cart'),
//                            'unfulfilled' => __('Unfulfilled', 'fluent-cart'),
//                            'on_hold'     => __('On Hold', 'fluent-cart')
//                        ],
//                        'is_multiple' => true,
//                        'is_only_in'  => true
//                    ],
                    [
                        'label'       => __('Order Type', 'fluent-cart'),
                        'value'       => 'type',
                        'type'        => 'selections',
                        'filter_type' => 'custom',
                        // The sale types the reports use, so installment orders are not counted as renewals
                        'options'     => ReportHelper::saleTypeLabels(),
                        'is_multiple' => true,
                        'is_only_in'  => true,
                        'callback'    => function ($query, $data) {
                            $types = array_values(array_intersect((array) Arr::get($data, 'value', []), array_keys(ReportHelper::saleTypeLabels())));
                            if (!$types) {
                                return;
                            }
                            $operator = Arr::get($data, 'operator');
                            // As BaseFilter reads a single-value column: in_all needs every value at once
                            if ($operator === 'in_all' && count($types) > 1) {
                                $query->whereRaw('1 = 0');
                                return;
                            }
                            static::whereSaleType($query, $types, in_array($operator, ['not_in', 'not_contains', 'not_in_all'], true));
                        },
                    ],
                    [
                        // Filter to one currency, then sort by amount — mixed
                        // currencies do not sort meaningfully
                        'label'       => __('Currency', 'fluent-cart'),
                        'value'       => 'currency',
                        'type'        => 'selections',
                        'options'     => static::currencyOptions(),
                        'is_multiple' => true,
                        'is_only_in'  => true
                    ],
                    [
                        'label'       => __('Payment Method', 'fluent-cart'),
                        'value'       => 'payment_method',
                        'type'        => 'selections',
                        'column'      => 'payment_method',
                        'relation'    => 'transactions',
                        'filter_type' => 'relation',
                        'options'     => $availablePaymentMethods,
                        'is_multiple' => true,
                        'is_only_in'  => true
                    ],
                    [
                        'label' => __('Order Amount', 'fluent-cart'),
                        'value' => 'total_amount',
                        'type'  => 'numeric',
                    ],
                    [
                        'label' => __('Order Hash (UUID)', 'fluent-cart'),
                        'value' => 'uuid',
                        'type'  => 'text',
                        'column' => 'uuid',
                        'operators'   => [
                            'equals'      => __('Equals', 'fluent-cart'),
                            'not_equals'  => __('Not Equals', 'fluent-cart'),
                        ]
                    ],
                    [
                        'label'       => __('Order Date', 'fluent-cart'),
                        'value'       => 'created_at',
                        'type'        => 'dates',
                        'filter_type' => 'date',
                    ],
                ],
            ],
            'customer'     => [
                'label' => __('Customer Property', 'fluent-cart'),
                'value' => 'customer',

                'children' => [
                    [
                        'label'       => __('Customer Name', 'fluent-cart'),
                        'value'       => 'customer_full_name',
                        'type'        => 'text',
                        'filter_type' => 'custom',
                        'operators'   => [
                            'like_all'    => __('Contains', 'fluent-cart'),
                            'starts_with' => __('Starts With', 'fluent-cart'),
                            'ends_with'   => __('Ends With', 'fluent-cart'),
                            'not_like'    => __('Not Contains', 'fluent-cart'),
                        ],
                        'callback'    => function ($query, $data) {
                            $query->whereHas('customer', function ($query) use ($data) {
                                $query->searchByFullName($data);
                            });
                        }
                    ],

                    [
                        'label'       => __('Customer Email', 'fluent-cart'),
                        'value'       => 'customer_email',
                        'type'        => 'text',
                        'filter_type' => 'relation',
                        'column'      => 'email',
                        'relation'    => 'customer',
                    ]
                ],
            ],
            'transactions' => [
                'label' => __('Transactions Property', 'fluent-cart'),
                'value' => 'transactions',

                'children' => [
                    [
                        'label'       => __('Transaction Id', 'fluent-cart'),
                        'value'       => 'transaction_id',
                        'type'        => 'text',
                        'filter_type' => 'relation',
                        'column'      => 'vendor_charge_id',
                        'relation'    => 'transactions',
                    ],
                    [
                        'label'       => __('Transaction Status', 'fluent-cart'),
                        'value'       => 'transaction_status',
                        'type'        => 'selections',
                        'filter_type' => 'relation',
                        'column'      => 'status',
                        'relation'    => 'transactions',
                        'options'     => [
                            Status::TRANSACTION_SUCCEEDED => _x('Succeeded', 'Transaction status', 'fluent-cart'),
                            Status::TRANSACTION_PENDING   => _x('Pending', 'Transaction status', 'fluent-cart'),
                            Status::TRANSACTION_REFUNDED  => _x('Refunded', 'Transaction status', 'fluent-cart'),
                            Status::TRANSACTION_FAILED    => _x('Failed', 'Transaction status', 'fluent-cart'),
                        ],
                        'is_multiple' => true,
                        'is_only_in'  => true
                    ],
                    [
                        'label'       => __('Transaction Type', 'fluent-cart'),
                        'value'       => 'transaction_type',
                        'type'        => 'selections',
                        'filter_type' => 'relation',
                        'column'      => 'transaction_type',
                        'relation'    => 'transactions',
                        'options'     => [
                            Status::TRANSACTION_TYPE_CHARGE  => __('Charge', 'fluent-cart'),
                            Status::TRANSACTION_TYPE_REFUND  => _x('Refunded', 'Transaction type', 'fluent-cart'),
                            Status::TRANSACTION_TYPE_DISPUTE => __('Dispute', 'fluent-cart'),
                        ],
                        'is_multiple' => true,
                        'is_only_in'  => true
                    ],
                    [
                        'label'       => __('Card last 4', 'fluent-cart'),
                        'value'       => 'transaction_card_last',
                        'type'        => 'text',
                        'filter_type' => 'relation',
                        'column'      => 'card_last_4',
                        'relation'    => 'transactions',
                    ],
                    [
                        'label'       => __('Card Brand', 'fluent-cart'),
                        'value'       => 'transaction_card_brand',
                        'type'        => 'text',
                        'filter_type' => 'relation',
                        'column'      => 'card_brand',
                        'relation'    => 'transactions',
                    ],
                    [
                        'label'       => __('Payer email', 'fluent-cart'),
                        'value'       => 'payer_email',
                        'type'        => 'text',
                        'filter_type' => 'custom',
                        'operators'   => [
                            'equals'      => __('Equals', 'fluent-cart'),
                            'contains'    => __('Contains', 'fluent-cart'),
                            'starts_with' => __('Starts With', 'fluent-cart'),
                            'ends_with'   => __('Ends With', 'fluent-cart'),
                            'not_like'    => __('Not Contains', 'fluent-cart')
                        ],
                        'callback'    => function ($query, $data) {
                            $query->whereHas('transactions', function ($query) use ($data) {
                                $query->searchByPayerEmail($data);
                            });
                        },
                        'examples'    => [
                            'payer_email = jhon@example.com',
                        ]
                    ]
                ]
            ]
        ];

        if (ModuleSettings::isActive('license') && App::isProActive()) {
            $filters['license'] = [
                'label'    => __('License Property', 'fluent-cart'),
                'value'    => 'license',
                'children' => [
                    [
                        'label'       => __('License key', 'fluent-cart'),
                        'value'       => 'license_key',
                        'type'        => 'text',
                        'filter_type' => 'relation',
                        'column'      => 'license_key',
                        'relation'    => 'licenses',
                    ],
                    [
                        'label'       => __('License Status', 'fluent-cart'),
                        'value'       => 'license_status',
                        'type'        => 'selections',
                        'filter_type' => 'relation',
                        'column'      => 'status',
                        'relation'    => 'licenses',
                        'options'     => [
                            Status::LICENSE_ACTIVE   => _x('Active', 'License status', 'fluent-cart'),
                            Status::LICENSE_DISABLED => _x('Disabled', 'License status', 'fluent-cart'),
                            Status::LICENSE_EXPIRED  => _x('Expired', 'License status', 'fluent-cart'),
                        ],
                        'is_multiple' => true,
                        'is_only_in'  => true
                    ]
                ],
            ];
        }

        $utmFilters = [
            'utm_campaign' => __('Utm Campaign', 'fluent-cart'),
            'utm_term'     => __('Utm Term', 'fluent-cart'),
            'utm_source'   => __('Utm Source', 'fluent-cart'),
            'utm_medium'   => __('Utm Medium', 'fluent-cart'),
            'utm_content'  => __('Utm Content', 'fluent-cart'),
            'utm_id'       => __('Utm Id', 'fluent-cart'),
            'refer_url'    => __('Refer Url', 'fluent-cart'),
        ];

        $utmChildren = [];
        foreach ($utmFilters as $key => $label) {
            $utmChildren[] = [
                'label'       => $label,
                'value'       => $key,
                'type'        => 'text',
                'filter_type' => 'relation',
                'column'      => $key,
                'relation'    => 'orderOperation',
            ];
        }

        $filters['utm'] = [
            'label' => __('Utm Property', 'fluent-cart'),
            'value' => 'utm',

            'children' => $utmChildren
        ];

        $filters['tax'] = [
            'label'    => __('Tax Property', 'fluent-cart'),
            'value'    => 'tax',
            'children' => [
                [
                    'label'       => __('B2B Purchase', 'fluent-cart'),
                    'value'       => 'b2b_purchase',
                    'filter_type' => 'custom',
                    'type'        => 'selections',
                    'options'     => [
                        'yes' => __('Yes', 'fluent-cart'),
                        'no'  => __('No', 'fluent-cart'),
                    ],
                    'is_multiple' => false,
                    'is_only_in'  => true,
                    'callback'    => static function ($query, $item) {
                        $value = Arr::get($item, 'value');
                        $selected = is_array($value) ? Arr::get($value, 0, '') : $value;
                        if ($selected === 'yes') {
                            $query->whereHas('orderMeta', static::b2bMetaCondition());
                        } else {
                            $query->whereDoesntHave('orderMeta', static::b2bMetaCondition());
                        }
                    },
                ],
                [
                    'label'       => __('Reverse Charge', 'fluent-cart'),
                    'value'       => 'reverse_charge',
                    'filter_type' => 'custom',
                    'type'        => 'selections',
                    'options'     => [
                        'yes' => __('Yes', 'fluent-cart'),
                        'no'  => __('No', 'fluent-cart'),
                    ],
                    'is_multiple' => false,
                    'is_only_in'  => true,
                    'callback'    => static function ($query, $item) {
                        $value = Arr::get($item, 'value');
                        $selected = is_array($value) ? Arr::get($value, 0, '') : $value;
                        if ($selected === 'yes') {
                            $query->whereHas('orderTaxRates', function ($q) {
                                $q->whereRaw("JSON_EXTRACT(meta, '$.reverse_charge_applied') = true");
                            });
                        } else {
                            $query->whereDoesntHave('orderTaxRates', function ($q) {
                                $q->whereRaw("JSON_EXTRACT(meta, '$.reverse_charge_applied') = true");
                            });
                        }
                    },
                ],
            ],
        ];

        return LabelFilter::advanceFilterOptionsForOther($filters);
    }


    public function centColumns(): array
    {
        return ['subtotal', 'shipping_total', 'total_amount', 'total_paid', 'total_refund'];
    }
}

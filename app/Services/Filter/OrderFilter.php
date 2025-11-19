<?php

namespace FluentCart\App\Services\Filter;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\App;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;
use FluentCartPro\App\Modules\Licensing\Models\License;

class OrderFilter extends BaseFilter
{

    public function applySimpleFilter()
    {
        $isApplied = $this->applySimpleOperatorFilter();
        if ($isApplied) {
            return;
        }

        foreach (['payment_statuses', 'order_statuses', 'shipping_statuses'] as $key => $status) {
            $this->query->when(Arr::get($this->args, $status), function ($query) use ($status) {
                return $query->whereIn($status, $status);
            });
        }


        if (!empty($this->search)) {
            $search = $this->search;

            $this->query->orWhere('invoice_no', 'LIKE', "%{$search}%")
                ->orWhereHas('customer', function ($customerQuery) use ($search) {
                    $customerQuery
                        ->where('email', 'LIKE', "%{$search}%")
                        ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
                });
        }
    }

    public function tabsMap(): array
    {
        return [
            'on-hold'            => 'status',
            'paid'               => 'payment_status',
            //'unpaid'       => 'payment_status',
            'completed'          => 'status',
            'processing'         => 'status',
            'renewal'            => 'type',
            'subscription'       => 'type',
            'onetime'            => 'type',
            'refunded'           => 'payment_status',
            'partially_refunded' => 'payment_status',
            'upgraded_to'        => 'upgraded_to',
            'upgraded_from'      => 'upgraded_from'
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

    public static function parseableKeys(): array
    {
        return array_merge(
            parent::parseableKeys(),
            ['payment_statuses', 'order_statuses', 'shipping_statuses']
        );
    }


    public function applyActiveViewFilter()
    {

        $tabsMap = $this->tabsMap();

        //Apply Active Tab view
        $this->query = $this->query->when($this->activeView, function (Builder $query, $activeView) use ($tabsMap) {

            if ($activeView === 'upgraded_to') {
                return $query
                    ->whereRaw("JSON_EXTRACT(config, '$.upgraded_to') IS NOT NULL")
                    ->whereRaw("JSON_EXTRACT(config, '$.upgraded_to') != 0");
            } else if ($activeView === 'upgraded_from') {
                return $query
                    ->whereRaw("JSON_EXTRACT(config, '$.upgraded_from') IS NOT NULL")
                    ->whereRaw("JSON_EXTRACT(config, '$.upgraded_from') != 0");
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

    public static function advanceFilterOptions(): array
    {
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
                            'completed'  => __('Completed', 'fluent-cart'),
                            'processing' => __('Processing', 'fluent-cart'),
                            'on-hold'    => __('On Hold', 'fluent-cart'),
                            'canceled'   => __('Canceled', 'fluent-cart')
                        ],
                        'is_multiple' => true,
                        'is_only_in'  => true
                    ],
                    [
                        'label'       => __('Payment Status', 'fluent-cart'),
                        'value'       => 'payment_status',
                        'type'        => 'selections',
                        'options'     => [
                            'paid'               => __('Paid', 'fluent-cart'),
                            'pending'            => __('Pending', 'fluent-cart'),
                            'partially_paid'     => __('Partially Paid', 'fluent-cart'),
                            'refunded'           => __('Refunded', 'fluent-cart'),
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
                        'options'     => [
                            'payment'      => __('Single Payment', 'fluent-cart'),
                            'subscription' => __('Subscription', 'fluent-cart'),
                            'renewal'      => __('Renewal', 'fluent-cart'),
                        ],
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
                        'options'     => [
                            'stripe'          => __('Stripe', 'fluent-cart'),
                            'paypal'          => __('PayPal', 'fluent-cart'),
                            'offline_payment' => __('Cash on Delivery', 'fluent-cart'),
                        ],
                        'is_multiple' => true,
                        'is_only_in'  => true
                    ],
                    [
                        'label' => __('Order Amount', 'fluent-cart'),
                        'value' => 'total_amount',
                        'type'  => 'numeric',
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
                            Status::TRANSACTION_SUCCEEDED => __('Succeeded', 'fluent-cart'),
                            Status::TRANSACTION_PENDING   => __('Pending', 'fluent-cart'),
                            Status::TRANSACTION_REFUNDED  => __('Refunded', 'fluent-cart'),
                            Status::TRANSACTION_FAILED    => __('Failed', 'fluent-cart'),
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
                            Status::TRANSACTION_TYPE_REFUND  => __('Refunded', 'fluent-cart'),
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
                            Status::LICENSE_ACTIVE   => __('Active', 'fluent-cart'),
                            Status::LICENSE_DISABLED => __('Disabled', 'fluent-cart'),
                            Status::LICENSE_EXPIRED  => __('Expired', 'fluent-cart'),
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
        return LabelFilter::advanceFilterOptionsForOther($filters);
    }


    public function centColumns(): array
    {
        return ['subtotal', 'shipping_total', 'total_amount', 'total_paid', 'total_refund'];
    }
}

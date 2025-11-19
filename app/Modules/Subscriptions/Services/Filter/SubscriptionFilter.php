<?php

namespace FluentCart\App\Modules\Subscriptions\Services\Filter;

use FluentCart\Api\ModuleSettings;
use FluentCart\Api\PaymentMethods;
use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\Filter\BaseFilter;
use FluentCart\App\Services\Filter\LabelFilter;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Collection;
use FluentCart\Framework\Support\Str;

class SubscriptionFilter extends BaseFilter
{
    public function applySimpleFilter()
    {
        $isApplied = $this->applySimpleOperatorFilter();
        if ($isApplied) {
            return;
        }

        $this->query->when($this->search, function ($query, $search) {
            return $query->where(function ($query) use ($search) {
                if (in_array(strtolower($search), ['canceled', 'cancelled'])) {
                    $query->orWhere('status', 'canceled');
                } else {
                    $query->orWhere('status', $search);
                }

                if (Str::of($search)->contains('#')) {
                    $search = Str::of($search)->remove('#')->toString();
                    $query->orWhere('id', 'like', '%' . $search . '%');
                } else if (Str::of($search)->contains('@')) {
                    $query->orWhereHas('customer', function ($query) use ($search) {
                        $query->where('email', 'like', '%' . $search . '%');
                    });
                } else {
                    // Search in other columns
                    $columns = ['parent_order_id', 'item_name', 'vendor_subscription_id',
                        'vendor_customer_id', 'vendor_plan_id', 'current_payment_method',
                        'billing_interval', 'bill_count'];

                    foreach ($columns as $column) {
                        $query->orWhere($column, 'like', '%' . $search . '%');
                    }
                }
            });
        });
    }

    public function tabsMap(): array
    {
        return [
            Status::SUBSCRIPTION_ACTIVE   => 'status',
            Status::SUBSCRIPTION_PENDING  => 'status',
            Status::SUBSCRIPTION_INTENDED => 'status',
            Status::SUBSCRIPTION_PAUSED   => 'status',
            Status::SUBSCRIPTION_TRIALING => 'status',
            Status::SUBSCRIPTION_CANCELED => 'status',
            Status::SUBSCRIPTION_FAILING  => 'status',
            Status::SUBSCRIPTION_EXPIRING => 'status',
            Status::SUBSCRIPTION_EXPIRED  => 'status',
        ];
    }

    public function getModel(): string
    {
        return Subscription::class;
    }

    public static function getFilterName(): string
    {
        return 'subscriptions';
    }

    public function applyActiveViewFilter()
    {
        $tabsMap = $this->tabsMap();
        //Apply Active Tab view
        $this->query = $this->query->when($this->activeView, function (Builder $query, $activeView) use ($tabsMap) {
            $query->where(function ($query) use ($tabsMap, $activeView) {
                if ('canceled' === $activeView && $tabsMap[$activeView] === 'status') {
                    // active(Collection paused) is also considered as canceled
                    $query->whereIn('status', [Status::SUBSCRIPTION_CANCELED]);
                } else {
                    $query->where($tabsMap[$activeView], $activeView);
                }
            });
        });
    }

    public static function getSearchableFields(): array
    {
        return [
            'id' => [
                'column'      => 'ID',
                'description' => __('Subscription ID', 'fluent-cart'),
                'type'        => 'numeric'
            ]
        ];
    }

    public static function advanceFilterOptions(): array
    {
        $activePaymentMethods = PaymentMethods::getActiveMeta();
        $paymentMethodOptions = Collection::make($activePaymentMethods)->pluck('title', 'slug')->toArray();
        $paymentMethodOptions = array_merge(
            $paymentMethodOptions,
            [
                'stripe' => __('Stripe', 'fluent-cart'),
                'paypal' => __('PayPal', 'fluent-cart')
            ]
        );
        $filters = [
            'subscription' => [
                'label'    => __('Subscription', 'fluent-cart'),
                'value'    => 'subscription',
                'children' => [
                    [
                        'label'       => __('Subscription ID', 'fluent-cart'),
                        'value'       => 'vendor_subscription_id',
                        'type'        => 'text',
                        'filter_type' => 'column',
                        'column'      => 'vendor_subscription_id',
                    ],
                    [
                        'label'       => __('Status', 'fluent-cart'),
                        'value'       => 'status',
                        'type'        => 'selections',
                        'options'     => Status::getSubscriptionStatuses(),
                        'is_multiple' => true,
                    ],
                    [
                        'label'           => __('Order Items', 'fluent-cart'),
                        'value'           => 'variation',
                        'type'            => 'remote_tree_select',
                        'column'          => 'id',
                        'filter_type'     => 'relation',
                        'relation'        => 'variation',
                        'remote_data_key' => 'product_variations',
                        'limit'           => 10,
                    ],
                    [
                        'label'       => __('Billing Interval', 'fluent-cart'),
                        'value'       => 'billing_interval',
                        'type'        => 'selections',
                        'options'     => [
                            'yearly'      => __('Yearly', 'fluent-cart'),
                            'half_yearly' => __('Half Yearly', 'fluent-cart'),
                            'quarterly'   => __('Quarterly', 'fluent-cart'),
                            'monthly'     => __('Monthly', 'fluent-cart'),
                            'weekly'      => __('Weekly', 'fluent-cart'),
                            'daily'       => __('Daily', 'fluent-cart'),
                        ],
                        'is_multiple' => true,
                    ],
                    [
                        'label'       => __('Created At', 'fluent-cart'),
                        'value'       => 'created_at',
                        'type'        => 'dates',
                        'filter_type' => 'date',
                    ],
                    [
                        'label'       => __('Next Billing Date', 'fluent-cart'),
                        'value'       => 'next_billing_date',
                        'type'        => 'dates',
                        'filter_type' => 'date',
                    ],
                    [
                        'label' => __('Bill Count', 'fluent-cart'),
                        'value' => 'bill_count',
                        'type'  => 'numeric',
                    ],
                    [
                        'label'       => __('Status', 'fluent-cart'),
                        'value'       => 'status',
                        'type'        => 'selections',
                        'options'     => Status::getSubscriptionStatuses(),
                        'is_multiple' => true,
                    ],
                ],
            ],
            'transaction'  => [
                'label'    => __('Transaction Property', 'fluent-cart'),
                'value'    => 'transaction',
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
                        'label'       => __('Payment method', 'fluent-cart'),
                        'value'       => 'current_payment_method',
                        'type'        => 'selections',
                        'options'     => $paymentMethodOptions,
                        'is_multiple' => true,
                    ],
                ],
            ],
            'product'      => [
                'label'    => __('Products', 'fluent-cart'),
                'value'    => 'product',
                'children' => [
                    [
                        'label'           => __('By Products', 'fluent-cart'),
                        'value'           => 'product',
                        'type'            => 'remote_tree_select',
                        'column'          => 'variation_id',
                        'filter_type'     => 'relation',
                        'relation'        => 'variation',
                        'remote_data_key' => 'product_variations',
                        'limit'           => 10,
                    ],
                ],
            ],
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
                        'relation'    => 'license',
                    ],

                    [
                        'label'       => __('License Status', 'fluent-cart'),
                        'value'       => 'license_status',
                        'type'        => 'selections',
                        'filter_type' => 'relation',
                        'column'      => 'status',
                        'relation'    => 'license',
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
        return LabelFilter::advanceFilterOptionsForOther($filters);
    }
}

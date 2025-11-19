<?php

namespace FluentCart\App\Services\Filter;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCartPro\App\Modules\Licensing\Models\License;
use FluentCartPro\App\Modules\Promotional\Models\OrderPromotion;


class OrderBumpFilter extends BaseFilter
{

    public function applySimpleFilter()
    {

        $isApplied = $this->applySimpleOperatorFilter();
        if ($isApplied) {
            return;
        }

        $this->query->when($this->search, function ($query, $search) {
            // If search is an array, implode it
            $search = is_array($search) ? implode(' ', $search) : $search;

            // If search is empty or null, return the query
            if (empty($search)) {
                return $query;
            }

            $query->where(function ($query) use ($search) {
                $query->where('title', 'like', '%' . $search . '%')
                    ->orWhere('id', 'like', '%' . $search . '%');
            });

            return $query;
        });
    }

    public function tabsMap(): array
    {
        return [
            'active'   => __('Active', 'fluent-cart'),
            'inactive' => __('Inactive', 'fluent-cart'),
        ];
    }

    public function getModel(): string
    {
        return OrderPromotion::class;
    }

    public static function getFilterName(): string
    {
        return 'order_bump';
    }


    public function applyActiveViewFilter()
    {

        $this->query->when($this->activeView, function ($query, $activeView) {
            $validStatuses = [
                'active',
                'draft'
            ];

            if (in_array($activeView, $validStatuses)) {
                if ($activeView == 'expired') {
                    $query->where('expiration_date', '<', DateTime::gmtNow());
                } else if ($activeView == 'active') {
                    $query->where(function ($query) {
                        $query->where('expiration_date', '>', DateTime::gmtNow())
                            ->orWhereNull('expiration_date');
                    })
                        ->where('status', 'active');
                } else {
                    $query->where('status', $activeView);
                }
            } else if ($activeView == 'inactive') {
                $query->where('status', 'active')
                    ->whereDoesntHave('activations');
            }

            return $query;
        });
    }

    public static function getSearchableFields(): array
    {
        return [
            'key' => [
                'column'      => 'title',
                'description' => 'title',
                'type'        => 'string'
            ]
        ];
    }

    public static function advanceFilterOptions(): array
    {
        $filters = [
            'product'  => [
                'label'    => __('Products', 'fluent-cart'),
                'value'    => 'product',
                'children' => [
                    [
                        'label'           => __('By Products', 'fluent-cart'),
                        'value'           => 'product',
                        'column'          => 'variation_id',
                        'filter_type'     => 'relation',
                        'relation'        => 'productVariant',
                        'remote_data_key' => 'product_variations',
                        'type'            => 'remote_tree_select',
                        'limit'           => 10,
                    ],
                ],
            ],
            'customer' => [
                'label'    => __('Customer Property', 'fluent-cart'),
                'value'    => 'customer',
                'children' => [
                    [
                        'label'       => __('Customer first name', 'fluent-cart'),
                        'value'       => 'customer_first_name',
                        'type'        => 'text',
                        'filter_type' => 'relation',
                        'column'      => 'first_name',
                        'relation'    => 'customer',
                    ],
                    [
                        'label'       => __('Customer last name', 'fluent-cart'),
                        'value'       => 'customer_last_name',
                        'type'        => 'text',
                        'filter_type' => 'relation',
                        'column'      => 'last_name',
                        'relation'    => 'customer',
                    ]
                ],
            ],
            'license'  => [
                'label'    => __('License Property', 'fluent-cart'),
                'value'    => 'license',
                'children' => [
                    [
                        'label'       => __('License key', 'fluent-cart'),
                        'value'       => 'license_key',
                        'type'        => 'text',
                        'filter_type' => 'column',
                        'column'      => 'license_key',
                    ],
                    [
                        'label'       => __('Status', 'fluent-cart'),
                        'value'       => 'status',
                        'type'        => 'selections',
                        'filter_type' => 'column',
                        'column'      => 'status',
                        'options'     => [
                            Status::LICENSE_ACTIVE   => __('Active', 'fluent-cart'),
                            Status::LICENSE_DISABLED => __('Disabled', 'fluent-cart'),
                            Status::LICENSE_EXPIRED  => __('Expired', 'fluent-cart'),
                        ],
                        'is_multiple' => true,
                        'is_only_in'  => true
                    ],
                    [
                        'label'       => __('Activation Count', 'fluent-cart'),
                        'value'       => 'activation_count',
                        'type'        => 'numeric',
                        'filter_type' => 'column',
                        'column'      => 'activation_count',
                    ],
                    [
                        'label'       => __('Expiration Date', 'fluent-cart'),
                        'value'       => 'expiration_date',
                        'type'        => 'dates',
                        'filter_type' => 'date',
                        'column'      => 'expiration_date',
                    ]
                ],
            ],
        ];
        return LabelFilter::advanceFilterOptionsForOther($filters);
    }

}

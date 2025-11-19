<?php

namespace FluentCart\App\Modules\Integrations\FluentPlugins;

use FluentAffiliate\App\Models\Affiliate;
use FluentCart\Api\ModuleSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Product;
use FluentCart\App\Services\URL;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Services\Helper;
use FluentCrm\App\Services\Libs\ConditionAssessor;
use FluentCrm\Framework\Support\Arr;

class FluentCRMDeepIntegration
{
    private $importKey = 'fluent_cart';

    public function init()
    {
        // Advanced Filters
        add_filter('fluentcrm_contacts_filter_fluent_cart', array($this, 'applyAdvancedFilters'), 10, 2);
        add_filter('fluentcrm_advanced_filter_options', array($this, 'addAdvancedFilterOptions'), 10, 1);

        add_filter('fluentcrm_ajax_options_product_selector_fluent_cart', [$this, 'handleProductSelectorAjax'], 10, 3);
        add_filter('fluent_crm/cascade_selection_options_fct_variations', [$this, 'handleProductVariationsAjax'], 1, 2);

        add_filter('fluent_crm/subscriber_info_widgets', [$this, 'pushInfoWidgetToContact'], 1, 2);

    }

    /**
     * @param \FluentCrm\Framework\Database\Orm\Builder|\FluentCrm\Framework\Database\Query\Builder $query
     * @param array $filters
     * @return \FluentCrm\Framework\Database\Orm\Builder|\FluentCrm\Framework\Database\Query\Builder
     */
    public function applyAdvancedFilters($query, $filters)
    {
        foreach ($filters as $filter) {
            $query = $this->applyFilter($query, $filter);
        }

        return $query;
    }

    public function addAdvancedFilterOptions($groups)
    {
        $conditionItems = [
            [
                'value'             => 'commerce_exist',
                'label'             => __('Is a customer?', 'fluent-cart'),
                'type'              => 'selections',
                'is_multiple'       => false,
                'disable_values'    => true,
                'value_description' => __('This filter will check if a contact has at least one shop order or not', 'fluent-cart'),
                'custom_operators'  => [
                    'exist'     => __('Yes', 'fluent-cart'),
                    'not_exist' => __('No', 'fluent-cart'),
                ]
            ],
            [
                'value' => 'ltv',
                'label' => __('Lifetime Value', 'fluent-cart'),
                'type'  => 'numeric'
            ],
            [
                'value' => 'aov',
                'label' => __('Average Order Value', 'fluent-cart'),
                'type'  => 'numeric',
            ],
            [
                'value' => 'first_purchase_date',
                'label' => __('First Order Date', 'fluent-cart'),
                'type'  => 'dates'
            ],
            [
                'value' => 'last_purchase_date',
                'label' => __('Last Order Date', 'fluent-cart'),
                'type'  => 'dates'
            ],
            [
                'value'            => 'purchased_items',
                'label'            => __('Products', 'fluent-cart'),
                'type'             => 'selections',
                'component'        => 'product_selector',
                'is_multiple'      => true,
                'custom_operators' => [
                    'exist'     => __('purchased', 'fluent-cart'),
                    'not_exist' => __('not purchased', 'fluent-cart'),
                ],
                'help'             => __('Will filter the contacts who have at least one order', 'fluent-cart')
            ],
            [
                'value'             => 'variation_purchased',
                'label'             => __('Product Variations', 'fluent-cart'),
                'type'              => 'cascade_selections',
                'provider'          => 'fct_variations',
                'is_multiple'       => true,
                'value_description' => __('This filter will check if a contact has purchased at least one specific product variation or not', 'fluent-cart'),
                'custom_operators'  => [
                    'exist'     => __('purchased', 'fluent-cart'),
                    'not_exist' => __('not purchased', 'fluent-cart'),
                ]
            ],
            [
                'value'            => 'purchased_categories',
                'label'            => __('Product Categories', 'fluent-cart'),
                'type'             => 'selections',
                'component'        => 'tax_selector',
                'taxonomy'         => 'product-categories',
                'is_multiple'      => true,
                'disabled'         => true,
                'help'             => __('Will filter the contacts who have at least one order', 'fluent-cart'),
                'custom_operators' => [
                    'exist'     => __('purchased', 'fluent-cart'),
                    'not_exist' => __('not purchased', 'fluent-cart'),
                ]
            ],
            [
                'value'            => 'commerce_coupons',
                'label'            => __('Used Coupons', 'fluent-cart'),
                'type'             => 'selections',
                'component'        => 'ajax_selector',
                'option_key'       => 'fct_coupons',
                'is_multiple'      => true,
                'disabled'         => true,
                'custom_operators' => [
                    'exist'     => __('in', 'fluent-cart'),
                    'not_exist' => __('not in', 'fluent-cart'),
                ],
                'help'             => __('Will filter the contacts who have at least one order', 'fluent-cart')
            ]
        ];

        if (ModuleSettings::isActive('license')) {
            $conditionItems[] = [
                'value'            => 'active_licenses',
                'label'            => __('Active Licenses', 'fluent-cart'),
                'type'             => 'selections',
                'component'        => 'product_selector',
                'is_multiple'      => true,
                'custom_operators' => [
                    'exist'     => __('have', 'fluent-cart'),
                    'not_exist' => __('do not have', 'fluent-cart'),
                ],
                'help'             => __('Will filter the contacts who have at least one active licenses or not', 'fluent-cart')
            ];
            $conditionItems[] = [
                'value'             => 'active_variation_licenses',
                'label'             => __('Active Variation Licenses', 'fluent-cart'),
                'type'              => 'cascade_selections',
                'provider'          => 'fct_variations',
                'is_multiple'       => true,
                'value_description' => __('This filter will check if a contact has at least one specific variation license or not', 'fluent-cart'),
                'custom_operators'  => [
                    'exist'     => __('have', 'fluent-cart'),
                    'not_exist' => __('do not have', 'fluent-cart'),
                ]
            ];
            $conditionItems[] = [
                'value'            => 'expired_licenses',
                'label'            => __('Expired Licenses', 'fluent-cart'),
                'type'             => 'selections',
                'component'        => 'product_selector',
                'is_multiple'      => true,
                'custom_operators' => [
                    'exist'     => __('have', 'fluent-cart'),
                    'not_exist' => __('do not have', 'fluent-cart'),
                ],
                'help'             => __('Will filter the contacts who have at least one expired licenses or not', 'fluent-cart')
            ];
            $conditionItems[] = [
                'value'             => 'expired_variation_licenses',
                'label'             => __('Expired Variation Licenses', 'fluent-cart'),
                'type'              => 'cascade_selections',
                'provider'          => 'fct_variations',
                'is_multiple'       => true,
                'value_description' => __('This filter will check if a contact has at least one specific variation expired license or not', 'fluent-cart'),
                'custom_operators'  => [
                    'exist'     => __('have', 'fluent-cart'),
                    'not_exist' => __('do not have', 'fluent-cart'),
                ]
            ];
            $conditionItems[] = [
                'value'             => 'license_exist',
                'label'             => __('Has any active license?', 'fluent-cart'),
                'type'              => 'selections',
                'is_multiple'       => false,
                'disable_values'    => true,
                'value_description' => __('Check if contacts has any active license from any products', 'fluent-cart'),
                'custom_operators'  => [
                    'exist'     => __('Yes', 'fluent-cart'),
                    'not_exist' => __('No', 'fluent-cart'),
                ]
            ];
        }

        $groups['fluent_cart'] = [
            'label'    => __('FluentCart', 'fluent-cart'),
            'value'    => 'fluent_cart',
            'children' => $conditionItems
        ];

        return $groups;
    }

    private function applyFilter($query, $filter)
    {
        $key = Arr::get($filter, 'property', '');
        $value = Arr::get($filter, 'value', '');
        $operator = Arr::get($filter, 'operator', '');

        if (!$key || !$operator) {
            return $query;
        }

        if ($key == 'commerce_exist') {
            if ($operator === 'exist') {
                return $query->whereExists(function ($q) {
                    $q->select(fluentCrmDb()->raw(1))
                        ->from('fct_customers')
                        ->whereColumn('fct_customers.email', 'fc_subscribers.email');
                });
            }

            return $query->whereNotExists(function ($q) {
                $q->select(fluentCrmDb()->raw(1))
                    ->from('fct_customers')
                    ->whereColumn('fct_customers.email', 'fc_subscribers.email');
            });
        }

        if ($key === 'license_exist') {
            if ($operator === 'exist') {
                return $query->whereExists(function ($q) {
                    $q->select(fluentCrmDb()->raw(1))
                        ->from('fct_customers')
                        ->whereColumn('fct_customers.email', 'fc_subscribers.email')
                        ->join('fct_licenses', function ($join) {
                            $join->on('fct_licenses.customer_id', '=', 'fct_customers.id')
                                ->whereIn('fct_licenses.status', ['active', 'inactive']);
                        });
                });
            }

            return $query->whereNotExists(function ($q) {
                $q->select(fluentCrmDb()->raw(1))
                    ->from('fct_customers')
                    ->whereColumn('fct_customers.email', 'fc_subscribers.email')
                    ->join('fct_licenses', function ($join) {
                        $join->on('fct_licenses.customer_id', '=', 'fct_customers.id')
                            ->whereIn('fct_licenses.status', ['active', 'inactive']);
                    });
            });
        }

        if ($value === '') {
            return $query;
        }

        $customerProperties = ['ltv', 'aov'];

        if (in_array($key, $customerProperties)) {
            $value = \FluentCart\App\Helpers\Helper::toCent($value);
            return $query->whereExists(function ($q) use ($key, $value, $operator) {
                $q->select(fluentCrmDb()->raw(1))
                    ->from('fct_customers')
                    ->whereColumn('fct_customers.email', 'fc_subscribers.email')
                    ->where('fct_customers.' . $key, $operator, $value);
            });
        }

        if ($key == 'first_purchase_date' || $key == 'last_purchase_date') {
            $filter = Subscriber::filterParser($filter);
            return $query->whereExists(function ($q) use ($filter, $key) {
                $q->select(fluentCrmDb()->raw(1))
                    ->from('fct_customers')
                    ->whereColumn('fct_customers.email', 'fc_subscribers.email');
                if ($filter['operator'] == 'BETWEEN') {
                    return $q->whereBetween('fct_customers.' . $key, $filter['value']);
                } else {
                    return $q->where('fct_customers.' . $key, $filter['operator'], $filter['value']);
                }
            });
        }

        if ($key == 'last_payout_date') {
            $filter = Subscriber::filterParser($filter);
            return $query->whereExists(function ($q) use ($filter) {
                $q->select(fluentCrmDb()->raw(1))
                    ->from('fa_affiliates')
                    ->whereColumn('fa_affiliates.user_id', 'fc_subscribers.user_id')
                    ->join('fa_payout_transactions', 'fa_payout_transactions.affiliate_id', '=', 'fa_affiliates.id');
                if ($filter['operator'] == 'BETWEEN') {
                    return $q->whereBetween('fa_payout_transactions.created_at', $filter['value']);
                }

                return $q->where('fa_payout_transactions.created_at', $filter['operator'], $filter['value']);
            });
        }

        if ($key === 'purchased_items' || $key === 'variation_purchased') {
            if ($key === 'variation_purchased') {
                $value = array_map(function ($item) {
                    $parts = explode('||', $item);
                    return isset($parts[1]) ? (int)$parts[1] : 0;
                }, is_array($value) ? $value : [$value]);

                $value = array_values(array_filter($value, 'is_numeric'));
            }

            $itemColumn = $key === 'purchased_items' ? 'post_id' : 'object_id';
            $value = is_array($value) ? $value : [$value];

            if ($operator === 'exist') {
                return $query->whereExists(function ($q) use ($itemColumn, $value) {
                    $q->select(fluentCrmDb()->raw(1))
                        ->from('fct_customers')
                        ->whereColumn('fct_customers.email', 'fc_subscribers.email')
                        ->join('fct_orders', function ($join) {
                            $join->on('fct_orders.customer_id', '=', 'fct_customers.id')
                                ->whereIn('fct_orders.payment_status', Status::getOrderPaymentSuccessStatuses());
                        })
                        ->join('fct_order_items', 'fct_order_items.order_id', '=', 'fct_orders.id')
                        ->whereIn('fct_order_items.' . $itemColumn, $value);
                });
            }

            return $query->whereNotExists(function ($q) use ($itemColumn, $value) {
                $q->select(fluentCrmDb()->raw(1))
                    ->from('fct_customers')
                    ->whereColumn('fct_customers.email', 'fc_subscribers.email')
                    ->join('fct_orders', function ($join) {
                        $join->on('fct_orders.customer_id', '=', 'fct_customers.id')
                            ->whereIn('fct_orders.payment_status', Status::getOrderPaymentSuccessStatuses());
                    })
                    ->join('fct_order_items', 'fct_order_items.order_id', '=', 'fct_orders.id')
                    ->whereIn('fct_order_items.' . $itemColumn, $value);
            });
        }

        if ($key === 'active_licenses' || $key === 'active_variation_licenses') {
            if ($key === 'active_variation_licenses') {
                $value = array_map(function ($item) {
                    $parts = explode('||', $item);
                    return isset($parts[1]) ? (int)$parts[1] : 0;
                }, is_array($value) ? $value : [$value]);
                $value = array_values(array_filter($value, 'is_numeric'));
            }

            $itemColumn = $key === 'active_licenses' ? 'product_id' : 'variation_id';
            $value = is_array($value) ? $value : [$value];

            if ($operator === 'exist') {
                return $query->whereExists(function ($q) use ($itemColumn, $value) {
                    $q->select(fluentCrmDb()->raw(1))
                        ->from('fct_customers')
                        ->whereColumn('fct_customers.email', 'fc_subscribers.email')
                        ->join('fct_licenses', function ($join) {
                            $join->on('fct_licenses.customer_id', '=', 'fct_customers.id')
                                ->whereIn('fct_licenses.status', ['active', 'inactive']);
                        })
                        ->whereIn('fct_licenses.' . $itemColumn, $value);
                });
            }

            return $query->whereNotExists(function ($q) use ($itemColumn, $value) {
                $q->select(fluentCrmDb()->raw(1))
                    ->from('fct_customers')
                    ->whereColumn('fct_customers.email', 'fc_subscribers.email')
                    ->join('fct_licenses', function ($join) {
                        $join->on('fct_licenses.customer_id', '=', 'fct_customers.id')
                            ->whereIn('fct_licenses.status', ['active', 'inactive']);
                    })
                    ->whereIn('fct_licenses.' . $itemColumn, $value);
            });
        }

        if ($key === 'expired_licenses' || $key === 'expired_variation_licenses') {
            if ($key === 'expired_variation_licenses') {
                $value = array_map(function ($item) {
                    $parts = explode('||', $item);
                    return isset($parts[1]) ? (int)$parts[1] : 0;
                }, is_array($value) ? $value : [$value]);
                $value = array_values(array_filter($value, 'is_numeric'));
            }

            $itemColumn = $key === 'expired_licenses' ? 'product_id' : 'variation_id';
            $value = is_array($value) ? $value : [$value];

            if ($operator === 'exist') {
                return $query->whereExists(function ($q) use ($itemColumn, $value) {
                    $q->select(fluentCrmDb()->raw(1))
                        ->from('fct_customers')
                        ->whereColumn('fct_customers.email', 'fc_subscribers.email')
                        ->join('fct_licenses', function ($join) {
                            $join->on('fct_licenses.customer_id', '=', 'fct_customers.id');
                        })
                        ->where('fct_licenses.status', 'expired')
                        ->whereNotIn('fct_licenses.status', ['active', 'inactive'])
                        ->whereIn('fct_licenses.' . $itemColumn, $value);
                });
            }

            return $query->whereNotExists(function ($q) use ($itemColumn, $value) {
                $q->select(fluentCrmDb()->raw(1))
                    ->from('fct_customers')
                    ->whereColumn('fct_customers.email', 'fc_subscribers.email')
                    ->join('fct_licenses', function ($join) {
                        $join->on('fct_licenses.customer_id', '=', 'fct_customers.id');
                    })
                    ->where('fct_licenses.status', 'expired')
                    ->whereNotIn('fct_licenses.status', ['active', 'inactive'])
                    ->whereIn('fct_licenses.' . $itemColumn, $value);
            });
        }

        return $query;
    }

    public function handleProductSelectorAjax($options, $searchTerm, $includedIds = [])
    {
        if (!$includedIds || !is_array($includedIds)) {
            $includedIds = [];
        }

        $products = Product::query()->whereLike('post_title', $searchTerm)->limit(50)->get();

        $formattedProducts = [];

        $pushedIds = [];

        foreach ($products as $product) {
            $pushedIds[] = (string)$product->ID;
            $formattedProducts[] = [
                'id'    => (string)$product->ID,
                'title' => $product->post_title
            ];
        }

        $leftoverIds = array_diff($includedIds, $pushedIds);

        if ($leftoverIds) {
            $leftoverProducts = Product::query()->whereIn('ID', $leftoverIds)->get();
            foreach ($leftoverProducts as $product) {
                $formattedProducts[] = [
                    'id'    => (string)$product->ID,
                    'title' => $product->post_title
                ];
            }
        }

        return $formattedProducts;
    }

    public function handleProductVariationsAjax($response, $reqestData = [])
    {
        $prevValues = Arr::get($reqestData, 'values', []);
        $search = Arr::get($reqestData, 'search', '');
        $prevItemIds = [];

        if ($prevValues) {
            $prevValues = (array)$prevValues;
            foreach ($prevValues as $prevValue) {
                $prevItemIds[] = explode('||', $prevValue)[0];
            }
            $prevItemIds = array_values(array_unique($prevItemIds));
        }

        // get wc variable products
        $variableProducts = Product::query()->whereLike('post_title', $search)
            ->with(['variants'])
            ->limit(50)
            ->get();

        $formattedProducts = [];

        $includedProductIds = [];

        foreach ($variableProducts as $product) {
            $item = [
                'value' => (string)$product->ID,
                'label' => $product->post_title
            ];

            $formattedVariations = [];
            foreach ($product->variants as $variant) {
                $formattedVariations[] = [
                    'value' => $item['value'] . '||' . $variant->id,
                    'label' => $variant->variation_title
                ];
            }
            $item['children'] = $formattedVariations;
            $formattedProducts[] = $item;

            $includedProductIds[] = $item['value'];
        }

        if ($prevItemIds) {
            $includedProductIds = array_diff($prevItemIds, $includedProductIds);
            if ($includedProductIds) {
                $variableProducts = Product::query()->whereIn('ID', $includedProductIds)
                    ->with(['variants'])
                    ->get();

                foreach ($variableProducts as $product) {
                    $item = [
                        'value' => (string)$product->id,
                        'label' => $product->post_title
                    ];

                    $variations = $product->get_children();
                    $formattedVariations = [];
                    foreach ($product->variants as $variant) {
                        $formattedVariations[] = [
                            'value' => $item['value'] . '||' . $variant->id,
                            'label' => $variant->variation_title
                        ];
                    }
                    $item['children'] = $formattedVariations;
                    $formattedProducts[] = $item;
                }
            }
        }

        $formattedProducts = array_filter($formattedProducts, function ($item) {
            return !empty($item['children']) && count($item['children']) > 1;
        });


        return [
            'options'  => $formattedProducts,
            'has_more' => true
        ];
    }

    public function pushInfoWidgetToContact($widgets, $subscriber)
    {
        $userId = $subscriber->user_id;
        $customer = Customer::query();
        if ($userId) {
            $customer = $customer->where('user_id', $userId)
                ->orWhere('email', $subscriber->email);
        } else {
            $customer = $customer->where('email', $subscriber->email);
        }

        $customer = $customer->first();

        if (!$customer) {
            return $widgets;
        }


        $widgets['fluent_cart'] = [
            'title'   => __('Commerce Info', 'fluent-cart'),
            'content' => $this->getStatsHtml($customer)
        ];

        return $widgets;
    }

    public function getStatsHtml($customer)
    {
        $stats = [
            [
                'title' => __('Lifetime Value', 'fluent-cart'),
                'value' => '<a href="' . URL::getDashboardUrl('customers/' . $customer->id . '/view') . '" target="_blank" class="fc_view_more">' . \FluentCart\App\Helpers\Helper::toDecimal($customer->ltv) . '</a>'
            ],
            [
                'title' => __('Purchases', 'fluent-cart'),
                'value' => $customer->purchase_count
            ],
            [
                'title' => __('First Purchased: ', 'fluent-cart'),
                'value' => $customer->first_purchase_date ? gmdate('F j, Y', strtotime($customer->first_purchase_date)) : 'N/A'
            ],
            [
                'title' => __('Last Purchased: ', 'fluent-cart'),
                'value' => $customer->last_purchase_date ? gmdate('F j, Y', strtotime($customer->last_purchase_date)) : 'N/A'
            ],
        ];

        $html = '<ul class="fc_full_listed fcrm_fluentcart_customer_commerce_info">';
        foreach ($stats as $stat) {
            $html .= '<li><span class="fc_list_sub">' . $stat['title'] . '</span> <span class="fc_list_value">' . $stat['value'] . '</span></li>';
        }

        $orderedItems = $customer->success_order_items()->orderBy('id', 'DESC')->get();

        $formattedItems = [];

        foreach ($orderedItems as $orderedItem) {
            $count = isset($formattedItems[$orderedItem->object_id]) ? $formattedItems[$orderedItem->object_id]['count'] + 1 : 1;
            $formattedItems[$orderedItem->object_id] = [
                'title'      => $orderedItem->title,
                'post_title' => $orderedItem->post_title,
                'count'      => $count,
                'created_at' => $orderedItem->created_at
            ];
        }

        foreach ($formattedItems as $formattedItem) {
            $countHtml = '';

            if ($formattedItem['count'] > 1) {
                $countHtml = ' <span style="background: #673AB7;color: #fffef1;font-size: 10px;padding: 3px 5px;border-radius: 50%;display: inline-block;line-height: 100%;">' . $formattedItem['count'] . '</span> ';
            }

            $html .= '<li>' . $countHtml . $formattedItem['post_title'] . ' - <span style="font-style: italic;">' . $formattedItem['title'] . '</span> (' . gmdate('F j, Y', strtotime($formattedItem['created_at'])) . ')</li>';
        }

        $html .= '</ul>';

        return $html;
    }

}

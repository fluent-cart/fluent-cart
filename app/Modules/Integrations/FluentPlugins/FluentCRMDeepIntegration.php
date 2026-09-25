<?php

namespace FluentCart\App\Modules\Integrations\FluentPlugins;

use FluentAffiliate\App\Models\Affiliate;
use FluentCart\Api\ModuleSettings;
use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\Product;
use FluentCart\App\Services\DateTime\DateFormatter;
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


        // temp
        // @todo: Remove after FluentCRM version 3 release
        add_filter('fluent_crm/purchase_history_fluent_cart', function ($data) {

            if (!$data || empty($data['data'])) {
                return $data;
            }

            $dataItems = $data['data'];

            foreach ($dataItems as $index => $item) {
                $action = $item['action'];
                $action = str_replace('fluent-crm#/orders/', 'fluent-cart#/orders/', $action);
                $dataItems[$index]['action'] = $action;
            }

            $data['data'] = $dataItems;

            return $data;
        }, 99, 1);

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
            'content' => $this->getStatsHtml($customer, [$this, 'formatDateForFluentCrm'])
        ];

        return $widgets;
    }

    /**
     * The shared FluentCRM-context date formatter for every FluentCart date
     * rendered inside a FluentCRM contact profile's Purchases page: the
     * Commerce Info widget (via pushInfoWidgetToContact() below) AND the
     * Purchase History tab's order table, Order Summary, and Purchased
     * Products list, which the fluent-crm plugin renders in
     * FluentCrm\App\Services\ExternalIntegrations\FluentCart\FluentCart and
     * calls through this public method (cross-plugin, same as that class
     * already importing FluentCart\App\Models\Customer).
     *
     * FluentCRM contacts can render every native date either as a relative
     * difference ("2 hours ago") or, with its "classic" date/time preference
     * enabled, as an absolute WordPress-formatted date -- both are
     * FluentCRM's own display modes, produced by Helper::formatDateTime()
     * itself. Product decision: on this FluentCRM-owned page, FluentCRM's
     * preference always wins over FluentCart's own date format setting
     * (DateFormatter / StoreSettings::date_time_format_source), in EITHER
     * mode -- so both branches defer to Helper::formatDateTime() and neither
     * falls through to DateFormatter for a reachable Helper.
     *
     * DateFormatter::format($datetime, false, wp_timezone()) remains only as
     * the defensive fallback for when FluentCrm\App\Services\Helper can't be
     * resolved at all (this plugin's own test suite doesn't load the
     * FluentCRM plugin; see FluentCRMDeepIntegrationTest). Pinned to
     * wp_timezone() so a store on FluentCart's 'fluent_cart' timezone source
     * (no order context here) doesn't fall back to UTC.
     *
     * Scoped to this page: DateFormatter's own default behavior is unchanged
     * everywhere else in FluentCart, including the FluentSupport customer
     * widget, which renders this same getStatsHtml() markup but must NOT
     * pick up FluentCRM's display preference -- see getStatsHtml()'s
     * $dateFormatter parameter.
     */
    public function formatDateForFluentCrm($datetime): string
    {
        if (empty($datetime)) {
            return '';
        }

        if (class_exists(Helper::class)) {
            return Helper::formatDateTime($datetime);
        }

        return DateFormatter::format($datetime, false, wp_timezone());
    }

    /**
     * @param \FluentCart\App\Models\Customer $customer
     * @param callable|null $dateFormatter Formats a single GMT datetime string
     *        for display. Defaults to DateFormatter::format() unchanged, which
     *        is what FluentSupportWidget::getPurchaseWidgets() relies on when
     *        it calls this method directly -- FluentCRM's own date/time
     *        preference must stay opt-in via formatDateForFluentCrm(),
     *        passed explicitly by pushInfoWidgetToContact() below, or it would
     *        leak into the unrelated FluentSupport widget.
     */
    public function getStatsHtml($customer, ?callable $dateFormatter = null)
    {
        $dateFormatter = $dateFormatter ?: [DateFormatter::class, 'format'];

        $viewUrl = URL::getDashboardUrl('customers/' . $customer->id . '/view');
        $naLabel = __('N/A', 'fluent-cart');

        // This widget's count/dates come from the customer's aggregate columns,
        // which recountStat() only ever populates from orders with a
        // payment-success status (see Customer::recountStat()). The FluentCRM
        // "Purchase History" tab's own order table/summary, by contrast, lists
        // every FluentCart order regardless of payment status. Those two counts
        // can legitimately disagree (e.g. a customer with only pending orders),
        // so the labels here are explicit about being paid-only rather than
        // reusing the ambiguous "Purchases"/"First Order"/"Last Order" captions.
        $hasPaidPurchases = (int) $customer->purchase_count > 0;

        // Compact, consistent labels (no trailing colons) so they read well as
        // stat-card captions on the FluentCRM contact profile.
        $stats = [
            [
                'label' => __('Lifetime Value', 'fluent-cart'),
                // toDecimal() returns the amount prefixed with an HTML-entity
                // currency sign (e.g. &#36;), so it is rendered raw here; the
                // value is a controlled currency string, never user input.
                'value' => '<a href="' . esc_url($viewUrl) . '" target="_blank" rel="noopener" class="fc_view_more">' . \FluentCart\App\Helpers\Helper::toDecimal($customer->ltv) . '</a>'
            ],
            [
                'label' => __('Paid Purchases', 'fluent-cart'),
                'value' => esc_html($customer->purchase_count)
            ],
            [
                'label' => __('First Paid Order', 'fluent-cart'),
                // Guarded on purchase_count, not just the date column: a
                // customer whose only paid order was later refunded/canceled
                // can keep a stale first/last_purchase_date until the next
                // recountStat() run, and this must read N/A regardless.
                'value' => ($hasPaidPurchases && $customer->first_purchase_date) ? esc_html($dateFormatter($customer->first_purchase_date)) : esc_html($naLabel)
            ],
            [
                'label' => __('Last Paid Order', 'fluent-cart'),
                'value' => ($hasPaidPurchases && $customer->last_purchase_date) ? esc_html($dateFormatter($customer->last_purchase_date)) : esc_html($naLabel)
            ],
        ];

        // FluentCRM styles this markup via .fcrm_fluentcart_customer_commerce_info
        $html = '<div class="fcrm_fluentcart_customer_commerce_info">';

        $html .= '<ul class="fcrm_fc_stat_grid">';
        foreach ($stats as $stat) {
            $html .= '<li class="fcrm_fc_stat">'
                . '<span class="fcrm_fc_stat_label">' . $stat['label'] . '</span>'
                . '<span class="fcrm_fc_stat_value">' . $stat['value'] . '</span>'
                . '</li>';
        }
        $html .= '</ul>';

        // Aggregated in SQL and capped to the most recently purchased products,
        // rather than pulling every paid order item the customer has ever had
        // into PHP: a long-time customer's full order-item history can run into
        // the thousands, and this widget only ever shows a short "Recent
        // purchases" list. purchase_count/earliest/latest item ids are grouped
        // per product server-side; only the (at most $recentProductsLimit)
        // earliest-occurrence rows are then fetched to supply title/date/link.
        $recentProductsLimit = 20;

        // selectRaw bypasses the query grammar's table-prefixing, so the real
        // (prefixed) items table name is required here -- a bare
        // "fct_order_items" throws "Unknown column" once WordPress's table
        // prefix isn't literally "fct_" (every wp-browser test run, and any
        // site sharing tables with another install).
        $itemsTable = App::db()->getTableName('fct_order_items');

        $productAggregates = $customer->success_order_items()
            ->selectRaw($itemsTable . '.object_id, COUNT(*) as purchase_count, MIN(' . $itemsTable . '.id) as earliest_item_id, MAX(' . $itemsTable . '.id) as latest_item_id')
            // groupBy/orderBy go through the query grammar, which prefixes
            // bare column names itself -- unlike selectRaw above, so this one
            // must stay unqualified (and unambiguous: only the items table has
            // an object_id column).
            ->groupBy('object_id')
            ->orderBy('latest_item_id', 'DESC')
            ->limit($recentProductsLimit)
            ->get();

        $earliestItemIds = array_values(array_filter($productAggregates->pluck('earliest_item_id')->all()));

        $earliestItemsById = [];
        if ($earliestItemIds) {
            foreach (OrderItem::query()->whereIn('id', $earliestItemIds)->get() as $earliestItem) {
                $earliestItemsById[$earliestItem->id] = $earliestItem;
            }
        }

        // Group identical products and count repeat purchases
        $formattedItems = [];
        foreach ($productAggregates as $productAggregate) {
            $earliestItem = $earliestItemsById[$productAggregate->earliest_item_id] ?? null;
            if (!$earliestItem) {
                continue;
            }

            $formattedItems[$productAggregate->object_id] = [
                'title'      => $earliestItem->title,
                'post_title' => $earliestItem->post_title,
                'count'      => (int) $productAggregate->purchase_count,
                // earliest_item_id is the oldest item id per product: the
                // customer's first order for it. Both the shown date and the
                // link point at that order.
                'created_at' => $earliestItem->created_at,
                'order_id'   => $earliestItem->order_id
            ];
        }

        if ($formattedItems) {
            $html .= '<div class="fcrm_fc_products">';
            $html .= '<div class="fcrm_fc_products_title">' . esc_html__('Recent purchases', 'fluent-cart') . '</div>';
            $html .= '<ul class="fcrm_fc_product_list">';

            foreach ($formattedItems as $formattedItem) {
                $qtyHtml = '';
                if ($formattedItem['count'] > 1) {
                    $qtyHtml = '<span class="fcrm_fc_product_qty">' . esc_html($formattedItem['count']) . '</span>';
                }

                // Only show the variant when it differs from the product name,
                // so single-variant products don't read as "Name - Name"
                $variantHtml = '';
                if ($formattedItem['title'] && $formattedItem['title'] !== $formattedItem['post_title']) {
                    $variantHtml = '<span class="fcrm_fc_product_variant">' . esc_html($formattedItem['title']) . '</span>';
                }

                // Link the date straight to the (first) order for this product
                $dateText = esc_html($dateFormatter($formattedItem['created_at']));
                if (!empty($formattedItem['order_id'])) {
                    $orderUrl = URL::getDashboardUrl('orders/' . $formattedItem['order_id'] . '/view');
                    $dateHtml = '<a class="fcrm_fc_product_date" href="' . esc_url($orderUrl) . '" target="_blank" rel="noopener">' . $dateText . '</a>';
                } else {
                    $dateHtml = '<span class="fcrm_fc_product_date">' . $dateText . '</span>';
                }

                $html .= '<li class="fcrm_fc_product">'
                    . '<span class="fcrm_fc_product_main">'
                    . '<span class="fcrm_fc_product_name">' . esc_html($formattedItem['post_title']) . '</span>'
                    . $variantHtml
                    . '</span>'
                    . '<span class="fcrm_fc_product_meta">'
                    . $qtyHtml
                    . $dateHtml
                    . '</span>'
                    . '</li>';
            }

            $html .= '</ul></div>';
        }

        $html .= '</div>';

        return $html;
    }

}

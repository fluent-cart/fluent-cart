<?php

namespace FluentCart\App\Services\Filter;

use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Models\Activity;
use FluentCart\App\Models\Customer;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;

class CustomerFilter extends BaseFilter
{

    public function applySimpleFilter(?string $search = null): void
    {
        $isApplied = $this->applySimpleOperatorFilter($search);
        if ($isApplied) {
            return;
        }
        $this->query->when($search ?? $this->search, function ($query, $search) {

            return $query
                ->where(function ($query) use ($search) {
                    $search = trim($search);
                    $searchLike = addcslashes($search, '\\%_');
                    $query
                        ->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$searchLike}%"])
                        ->when(is_numeric($search), function ($query) use ($search) {
                            $query->orWhere('id', 'LIKE', "%{$search}%");
                        })
                        ->when(!str_contains($search, ' '), function ($query) use ($search) {
                            $query->orWhere('email', 'LIKE', "%{$search}%");
                        });
                });
        });
    }


    public function tabsMap(): array
    {
        return [

        ];
    }

    public function getModel(): string
    {
        return Customer::class;
    }

    public static function getFilterName(): string
    {
        return 'customers';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected static function sortableColumns(): array
    {
        return [
            'id'                 => ['label' => __('Customer ID', 'fluent-cart'), 'column' => 'id'],
            'first_name'         => ['label' => __('Name', 'fluent-cart'), 'column' => 'first_name'],
            'purchase_count'     => ['label' => __('Purchases', 'fluent-cart'), 'column' => 'purchase_count'],
            'ltv'                => ['label' => __('Lifetime Value (LTV)', 'fluent-cart'), 'column' => 'ltv'],
            'last_purchase_date' => ['label' => __('Last Purchase Date', 'fluent-cart'), 'column' => 'last_purchase_date'],
            'created_at'         => ['label' => __('Customer Since', 'fluent-cart'), 'column' => 'created_at'],
        ];
    }

    /**
     * SCREEN key — `admin_customer_search` is the one screen on `GET /customers`
     * that sends a `with` at all. CustomerTable.js and ChangeOrderCustomer.vue
     * send none.
     *
     * PUBLIC keys — the address sets and the labels are relation names an
     * external consumer can reasonably ask a customer endpoint for, and they
     * were accepted values before this allow-list existed. They are restored as
     * first-class entry points rather than dropped: "no consumer in our repos"
     * is not the same as "no consumer", and silently narrowing a published
     * response is the exact regression this tier exists to prevent.
     *
     * `GET /customers/{id}` is a different mechanism entirely —
     * CustomerController::allowedEagerLoads() — and keeps its own bars. This
     * map governs the list route only.
     *
     * Deliberately NOT allowlisted: `wpUser`, which is a BelongsTo onto the
     * WordPress users table. It must stay out of this map on its own merit —
     * the `User::$hidden` fix is a second line of defence, not a licence to
     * expose the relation. `orders` and `subscriptions` stay off too: they are
     * billing rows behind other permissions.
     *
     * @return array<string, callable>
     */
    protected function allowedWiths(): array
    {
        return [
            'admin_customer_search' => [$this, 'adminCustomerSearch'],

            'shipping_address'         => [$this, 'publicShippingAddress'],
            'billing_address'          => [$this, 'publicBillingAddress'],
            'primary_shipping_address' => [$this, 'publicPrimaryShippingAddress'],
            'primary_billing_address'  => [$this, 'publicPrimaryBillingAddress'],
            'labels'                   => [$this, 'publicLabels'],
        ];
    }

    /**
     * `GET /customers`, sent by OrderCustomerInformation.vue when an admin
     * searches for a customer to attach to an order. The screen prefills both
     * address blocks, so both sets are loaded in ONE `with()` call — a second
     * entry issuing its own `with()` would array_merge over this one.
     *
     * No further permission bar: the route already requires `customers/view`
     * and these are the customer's own addresses, which is precisely what that
     * permission grants.
     *
     * No select: CustomerAddresses::$appends is `formatted_address`,
     * `company_name`, `vat_number` and `legal_registration_id`, and the last
     * three read stored meta off the loaded row.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return \FluentCart\Framework\Database\Orm\Builder
     */
    protected function adminCustomerSearch($query)
    {
        return $query->with([
            'shipping_address',
            'billing_address',
        ]);
    }

    /**
     * `with[]=shipping_address` — a public entry point. No bar beyond the
     * route's own `customers/view`: a customer's addresses are customer data.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return \FluentCart\Framework\Database\Orm\Builder
     */
    protected function publicShippingAddress($query)
    {
        return $query->with(['shipping_address']);
    }

    /**
     * `with[]=billing_address` — a public entry point, covered by the route's
     * own `customers/view` for the same reason.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return \FluentCart\Framework\Database\Orm\Builder
     */
    protected function publicBillingAddress($query)
    {
        return $query->with(['billing_address']);
    }

    /**
     * `with[]=primary_shipping_address` — a public entry point. The primary row
     * is a HasOne narrowing of the same table the route already grants.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return \FluentCart\Framework\Database\Orm\Builder
     */
    protected function publicPrimaryShippingAddress($query)
    {
        return $query->with(['primary_shipping_address']);
    }

    /**
     * `with[]=primary_billing_address` — a public entry point, covered by the
     * route's own `customers/view` for the same reason.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return \FluentCart\Framework\Database\Orm\Builder
     */
    protected function publicPrimaryBillingAddress($query)
    {
        return $query->with(['primary_billing_address']);
    }

    /**
     * `with[]=labels` — a public entry point, and the one on this map that is
     * NOT covered by the route's own permission. Labels are their own resource
     * with their own `labels/view` bar, which is what
     * CustomerController::allowedEagerLoads() applies on the detail route; the
     * list route states the same bar here so the two doors agree.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return bool|\FluentCart\Framework\Database\Orm\Builder
     */
    protected function publicLabels($query)
    {
        if (!$this->userCanAny('labels/view')) {
            return false;
        }

        return $query->with(['labels']);
    }

    public function applyActiveViewFilter(?string $activeView = null): void
    {

    }

    public static function getSearchableFields(): array
    {
        return [
            'id' => [
                'column'      => 'ID',
                'description' => 'Customer ID',
                'type'        => 'numeric'
            ]
        ];
    }

    public static function advanceFilterOptions(): array
    {
        $filters = [
            'order'    => [
                'label'    => __('Order Property', 'fluent-cart'),
                'value'    => 'order',
                'children' => [
                    [
                        'label'           => __('By Order Items', 'fluent-cart'),
                        'value'           => 'order_items',
                        'column'          => 'object_id',
                        'filter_type'     => 'relation',
                        'relation'        => 'orders.order_items',
                        'remote_data_key' => 'product_variations',
                        'type'            => 'remote_tree_select',
                        'limit'           => 10,
                    ],
                    [
                        'label' => __('Purchases', 'fluent-cart'),
                        'value' => 'purchase_count',
                        'type'  => 'numeric',
                    ],
                    [
                        'label'       => __('First Purchase Date', 'fluent-cart'),
                        'value'       => 'first_purchase_date',
                        'type'        => 'dates',
                        'filter_type' => 'date',
                    ],
                    [
                        'label'       => __('Last Purchase Date', 'fluent-cart'),
                        'value'       => 'last_purchase_date',
                        'type'        => 'dates',
                        'filter_type' => 'date',
                    ]
                ],
            ],
            'customer' => [
                'label'    => __('Customer Property', 'fluent-cart'),
                'value'    => 'customer',
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
                            $query->searchByFullName($data);
                        }
                    ],
                    [
                        'label'       => __('Customer Email', 'fluent-cart'),
                        'value'       => 'email',
                        'type'        => 'text',
                        'filter_type' => 'column',
                        'column'      => 'email',
                    ],
                    [
                        'label'       => __('Customer LTV', 'fluent-cart'),
                        'value'       => 'ltv',
                        'type'        => 'numeric',
                        'filter_type' => 'column',
                        'column'      => 'ltv'
                    ]
                ],
            ]
        ];
        return LabelFilter::advanceFilterOptionsForOther($filters);
    }

    public function dateColumns(): array
    {
        return array_merge(parent::dateColumns(), ['first_purchase_date', 'last_purchase_date']);
    }

    public function centColumns(): array
    {
        return ['ltv'];
    }
}

<?php

namespace FluentCart\App\Services\Filter;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCartPro\App\Modules\Licensing\Models\License;


class LicenseFilter extends BaseFilter
{

    public function applySimpleFilter(?string $search = null): void
    {
        $isApplied = $this->applySimpleOperatorFilter($search);
        if ($isApplied) {
            return;
        }

        $this->query->when($search ?? $this->search, function ($query, $search) {
            // If search is an array, implode it
            $search = is_array($search) ? implode(' ', $search) : $search;

            // If search is empty or null, return the query
            if (empty($search)) {
                return $query;
            }

            $query->where(function ($query) use ($search) {
                $query->where('license_key', 'like', '%' . $search . '%')
                    ->orWhere('order_id', 'like', '%' . $search . '%')
                    ->orWhereHas('customer', function ($query) use ($search) {
                        $query
                            ->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"])
                            ->orWhere('email', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('activations', function ($query) use ($search) {
                        $query->whereHas('site', function ($query) use ($search) {
                            $query->where('site_url', 'like', '%' . $search . '%');
                        });
                    })
                ;
            });

            return $query;
        });
    }

    public function tabsMap(): array
    {
        return [
            'inactive' => __('Inactive', 'fluent-cart'),
            'active'   => __('Active', 'fluent-cart'),
            'expired'  => __('Expired', 'fluent-cart'),
            'disabled' => __('disabled', 'fluent-cart')
        ];
    }

    public function getModel(): string
    {
        return License::class;
    }

    public static function getFilterName(): string
    {
        return 'licenses';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected static function sortableColumns(): array
    {
        return [
            'order_id'         => ['label' => __('Order ID', 'fluent-cart'), 'column' => 'order_id'],
            'activation_count' => ['label' => __('Activation Count', 'fluent-cart'), 'column' => 'activation_count'],
            'expiration_date'  => ['label' => __('Expiration Date', 'fluent-cart'), 'column' => 'expiration_date'],
            'created_at'       => ['label' => __('Date', 'fluent-cart'), 'column' => 'created_at'],
            'status'           => ['label' => __('Status', 'fluent-cart'), 'column' => 'status'],
        ];
    }

    /**
     * `GET /licensing/licenses` requires `licenses/view`. That says nothing
     * about customers or products, so those relations carry their own bar
     * through every door below.
     *
     * SCREEN key — `admin_license_list` loads the three columns LicenseTable.js
     * renders, with the product and variant selects narrowed to the titles the
     * table actually prints.
     *
     * PUBLIC keys — `customer`, `product` and `productVariant` are the plain
     * relation names a consumer can reasonably ask a licence endpoint for, and
     * they were accepted values before this allow-list existed. They give the
     * unnarrowed relation: the screen's two-column select is a property of that
     * screen, not of the endpoint's published shape.
     *
     * `License::variation()` is a byte-identical duplicate of
     * `productVariant()` and stays refused, so the same data has exactly one
     * door.
     *
     * Deliberately NOT allowlisted: `activations` (site URLs and activation
     * secrets), `order` and `subscription` (billing rows behind other
     * permissions).
     *
     * @return array<string, callable>
     */
    protected function allowedWiths(): array
    {
        return [
            'admin_license_list' => [$this, 'adminLicenseList'],

            'customer'       => [$this, 'publicCustomer'],
            'product'        => [$this, 'publicProduct'],
            'productVariant' => [$this, 'publicProductVariant'],
        ];
    }

    /**
     * `GET /licensing/licenses`, sent by LicenseTable.js — the customer column
     * plus the product and variant titles.
     *
     * No select on the customer: Customer::$appends (`full_name`, `photo`,
     * `country_name`, `formatted_address`, `user_link`) reads first_name,
     * last_name, country, state, city, postcode and user_id. The product and
     * variant selects the client used to send as `product:ID,post_title` and
     * `productVariant:id,variation_title` live here now — same columns, no
     * longer client-controlled, and applied INSIDE the relation closures so
     * they narrow those relations rather than the licence query.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return \FluentCart\Framework\Database\Orm\Builder
     */
    protected function adminLicenseList($query)
    {
        // `licenses/view` says nothing about customers.
        if ($this->userCanAny('customers/view')) {
            $query->with(['customer']);
        }

        // …nor about the catalogue.
        if ($this->userCanAny('products/view')) {
            $query->with([
                'product'        => function ($productQuery) {
                    $productQuery->select(['ID', 'post_title']);
                },
                'productVariant' => function ($variantQuery) {
                    $variantQuery->select(['id', 'variation_title']);
                },
            ]);
        }

        return $query;
    }

    /**
     * `with[]=customer` — a public entry point, carrying the same
     * `customers/view` bar as the screen key.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return bool|\FluentCart\Framework\Database\Orm\Builder
     */
    protected function publicCustomer($query)
    {
        if (!$this->userCanAny('customers/view')) {
            return false;
        }

        return $query->with(['customer']);
    }

    /**
     * `with[]=product` — a public entry point, carrying the same
     * `products/view` bar as the screen key.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return bool|\FluentCart\Framework\Database\Orm\Builder
     */
    protected function publicProduct($query)
    {
        if (!$this->userCanAny('products/view')) {
            return false;
        }

        return $query->with(['product']);
    }

    /**
     * `with[]=productVariant` — a public entry point, carrying the same
     * `products/view` bar as the screen key.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return bool|\FluentCart\Framework\Database\Orm\Builder
     */
    protected function publicProductVariant($query)
    {
        if (!$this->userCanAny('products/view')) {
            return false;
        }

        return $query->with(['productVariant']);
    }


    public function applyActiveViewFilter(?string $activeView = null): void
    {
        $activeView = $activeView ?? $this->activeView;
        $tabsMap = $this->tabsMap();

        $this->query->when($activeView, function ($query, $activeView) use ($tabsMap) {

            $validStatuses = [
                'active',
                'expired',
                'disabled',
                'inactive'
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
                'column'      => 'license_key',
                'description' => 'license_key',
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

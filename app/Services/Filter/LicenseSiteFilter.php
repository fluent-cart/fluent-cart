<?php

namespace FluentCart\App\Services\Filter;

use FluentCartPro\App\Modules\Licensing\Models\LicenseSite;

class LicenseSiteFilter extends BaseFilter
{
    public function getModel(): string
    {
        return LicenseSite::class;
    }

    public static function getFilterName(): string
    {
        return 'license_sites';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected static function sortableColumns(): array
    {
        return [
            'site_url'   => ['label' => __('Site URL', 'fluent-cart'), 'column' => 'site_url'],
            'created_at' => ['label' => __('Created At', 'fluent-cart'), 'column' => 'created_at'],
        ];
    }

    /**
     * Intentionally empty, and verified so: LicenseSiteTable.js sends no `with`
     * at all. In particular `LicenseSite::activations()` must stay denied — it
     * walks back to the licence keys this list deliberately does not show.
     *
     * @return array<string, callable>
     */
    protected function allowedWiths(): array
    {
        return [];
    }

    public function tabsMap(): array
    {
        return [];
    }

    public function applySimpleFilter(?string $search = null): void
    {
        $isApplied = $this->applySimpleOperatorFilter($search);
        if ($isApplied) {
            return;
        }

        $this->query->when($search ?? $this->search, function ($query, $search) {
            $search = is_array($search) ? implode(' ', $search) : $search;

            if (empty($search)) {
                return $query;
            }

            $query->where('site_url', 'like', '%' . $search . '%');

            return $query;
        });
    }

    public function applyActiveViewFilter(?string $activeView = null): void
    {
        // No tab-based filtering for sites
    }

    public function customQuery()
    {
        return $this->query
            ->withCount(['activations as active_licenses_count' => function ($q) {
                $q->where('status', 'active');
            }])
            ->whereHas('activations');
    }

    public static function getSearchableFields(): array
    {
        return [
            'url' => [
                'column'      => 'site_url',
                'description' => 'site_url',
                'type'        => 'string'
            ]
        ];
    }

    public static function advanceFilterOptions(): array
    {
        return [
            'site'     => [
                'label'    => __('Site Property', 'fluent-cart'),
                'value'    => 'site',
                'children' => [
                    [
                        'label'       => __('Site URL', 'fluent-cart'),
                        'value'       => 'site_url',
                        'type'        => 'text',
                        'filter_type' => 'column',
                        'column'      => 'site_url',
                    ],
                    [
                        'label'       => __('Platform Version', 'fluent-cart'),
                        'value'       => 'platform_version',
                        'type'        => 'text',
                        'filter_type' => 'column',
                        'column'      => 'platform_version',
                    ],
                    [
                        'label'       => __('Server Version', 'fluent-cart'),
                        'value'       => 'server_version',
                        'type'        => 'text',
                        'filter_type' => 'column',
                        'column'      => 'server_version',
                    ],
                    [
                        'label'       => __('Registered Date', 'fluent-cart'),
                        'value'       => 'created_at',
                        'type'        => 'dates',
                        'filter_type' => 'date',
                        'column'      => 'created_at',
                    ],
                ],
            ],
            'product'  => [
                'label'    => __('Product', 'fluent-cart'),
                'value'    => 'product',
                'children' => [
                    [
                        'label'           => __('By Products', 'fluent-cart'),
                        'value'           => 'product',
                        'type'            => 'remote_tree_select',
                        'filter_type'     => 'custom',
                        'remote_data_key' => 'product_variations',
                        'limit'           => 10,
                        'callback'        => function ($query, $data) {
                            $variationIds = (array)$data['value'];
                            $query->whereHas('activations', function ($q) use ($variationIds) {
                                $q->whereHas('license', function ($lq) use ($variationIds) {
                                    $lq->whereIn('variation_id', $variationIds);
                                });
                            });
                        }
                    ],
                ],
            ],
            'license'  => [
                'label'    => __('License Property', 'fluent-cart'),
                'value'    => 'license',
                'children' => [
                    [
                        'label'       => __('Activation Status', 'fluent-cart'),
                        'value'       => 'activation_status',
                        'type'        => 'selections',
                        'filter_type' => 'custom',
                        'options'     => [
                            'active'   => __('Active', 'fluent-cart'),
                            'inactive' => __('Inactive', 'fluent-cart'),
                        ],
                        'is_multiple' => true,
                        'is_only_in'  => true,
                        'callback'    => function ($query, $data) {
                            $query->whereHas('activations', function ($q) use ($data) {
                                $q->whereIn('status', (array)$data['value']);
                            });
                        }
                    ],
                    [
                        'label'       => __('Active License Count', 'fluent-cart'),
                        'value'       => 'active_license_count',
                        'type'        => 'numeric',
                        'filter_type' => 'custom',
                        'callback'    => function ($query, $data) {
                            $operator = isset($data['operator']) ? $data['operator'] : '=';
                            $value = intval($data['value']);
                            $query->whereHas('activations', function ($q) {
                                $q->where('status', 'active');
                            }, $operator, $value);
                        }
                    ],
                ],
            ],
        ];
    }
}

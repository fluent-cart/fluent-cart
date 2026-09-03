<?php

namespace FluentCart\App\Http\Controllers\AdvanceFilter;

use FluentCart\Api\Helper;
use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Models\Label;
use FluentCart\App\Services\Filter\LabelFilter;
use FluentCart\App\Services\Filter\OrderFilter;
use FluentCart\App\Services\Filter\ProductFilter;
use FluentCart\App\Services\Filter\VariationFilter;
use FluentCart\App\Services\Permission\PermissionManager;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;

class AdvanceFilterController extends Controller
{
    public function getFilterOption(Request $request): \WP_REST_Response
    {

        $includeIds = $request->get('include_ids');
        if (is_array($includeIds)) {
            $includeIds = array_filter($includeIds);
            $includeIds = array_map('intval', $includeIds);
        } else if (is_string($includeIds)) {
            $includeIds = sanitize_text_field($includeIds);
        } else {
            $includeIds = '';
        }
        $args = [
            'remote_data_key' => sanitize_text_field($request->get('remote_data_key')),
            'search'          => sanitize_text_field($request->get('search')),
            'include_ids'     => $includeIds,
            'limit'           => intval($request->get('limit')),
            'parent_id'       => intval($request->get('parent_id')),
        ];


        $dataKey = Arr::get($args, 'remote_data_key');

        $options = [];

        /*
         * Returned as an empty result rather than a 403: the caller is a select
         * input inside a filter panel, and a rejected request there surfaces as
         * an error toast on a control the user may not even have touched.
         */
        if (!$this->resolvePermission($dataKey)) {
            return $this->sendSuccess([
                'options' => $options,
            ]);
        }

        if ($dataKey == 'product_variations') {
            $options = VariationFilter::getTreeFilterOptions($args);
        } else if ($dataKey == 'labels') {
            $options = LabelFilter::getSelectFilterOptions($args);
        } else {
            $options = apply_filters('fluent_cart/advanced_filter_options_' . $dataKey, $options, $args);
        }

        return $this->sendSuccess([
            'options' => $options,
        ]);

    }

    /**
     * Can the current user read the data behind this key?
     *
     * One URL serves several unrelated lists, so a permission on the route
     * would let whoever holds the weakest one read the rest. It belongs to the
     * key instead, and a key with no entry below is denied.
     *
     * Add-ons answer for their own keys through the filter, which is named
     * after the key so a plugin can only speak for the one it added.
     *
     * @param string $dataKey
     * @return bool
     */
    protected function resolvePermission($dataKey): bool
    {
        $default = [
            'product_variations' => [
                'products/view',
                'orders/view',
            ],
            'labels' => [
                'orders/view',
                'customers/view',
                'products/view',
                'labels/view'
            ],
            // Not named in getFilterOption() — these arrive via the options filter.
            'attr_groups' => [
                'products/view',
            ],
            'attr_terms' => [
                'products/view',
            ],
        ];

        // Empty for an unlisted key, which leaves $hasPermission false below.
        $permissions = Arr::get($default, $dataKey, []);

        $hasPermission = false;

        // Any one of them is enough, so stop at the first match.
        foreach ($permissions as $permission) {
            if (PermissionManager::hasPermission($permission)) {
                $hasPermission = true;
                break;
            }
        }

        return apply_filters('fluent_cart/advanced_filter_options_permission_' . $dataKey, $hasPermission, $dataKey);
    }

    public function getSearchOptions(Request $request)
    {
        return $this->sendSuccess([
            'options' => Helper::getSearchOptions($request->all())
        ]);
    }
}

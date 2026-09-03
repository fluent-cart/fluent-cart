<?php
/**
 * Phase 10 source inventory for read-only input-validation regressions.
 *
 * Request wiring is limited to the safe admin CRUD surface established in
 * Phase 9. Read probes cover the shared list-filter stack plus the two
 * non-gateway pagination bypasses exercised by the installed application.
 * Customer-profile subscription/payment endpoints remain outside this round.
 */

return [
    'request_wiring' => [
        [
            'surface'        => 'Product create',
            'source_file'    => 'app/Http/Controllers/ProductController.php',
            'method_line'    => 305,
            'method_needle'  => 'function create(ProductCreateRequest $request)',
            'sanitize_line'  => 308,
            'sanitize_needle'=> '$request->getSafe($request->sanitize())',
            'coverage'       => 'validation-request-guard-wiring',
        ],
        [
            'surface'        => 'Product update',
            'source_file'    => 'app/Http/Controllers/ProductController.php',
            'method_line'    => 509,
            'method_needle'  => 'function update(ProductUpdateRequest $request',
            'sanitize_line'  => 511,
            'sanitize_needle'=> '$request->getSafe($request->sanitize())',
            'coverage'       => 'validation-request-guard-wiring',
        ],
        [
            'surface'        => 'Variant create',
            'source_file'    => 'app/Http/Controllers/ProductVariationController.php',
            'method_line'    => 36,
            'method_needle'  => 'function create(ProductVariationRequest $request)',
            'sanitize_line'  => 39,
            'sanitize_needle'=> '$request->getSafe($request->sanitize())',
            'coverage'       => 'validation-request-guard-wiring',
        ],
        [
            'surface'        => 'Variant update',
            'source_file'    => 'app/Http/Controllers/ProductVariationController.php',
            'method_line'    => 59,
            'method_needle'  => 'function update(ProductVariationRequest $request',
            'sanitize_line'  => 62,
            'sanitize_needle'=> '$request->getSafe($request->sanitize())',
            'coverage'       => 'validation-request-guard-wiring',
        ],
        [
            'surface'        => 'Order create',
            'source_file'    => 'app/Http/Controllers/OrderController.php',
            'method_line'    => 74,
            'method_needle'  => 'function store(OrderRequest $request)',
            'sanitize_line'  => 76,
            'sanitize_needle'=> '$request->getSafe($request->sanitize())',
            'coverage'       => 'validation-request-guard-wiring',
        ],
        [
            'surface'        => 'Order update',
            'source_file'    => 'app/Http/Controllers/OrderController.php',
            // 125/136 → 204/215: PR #2531 (193cb5590) added hasSubscription()
            // and getPaymentTypeConflict() above updateOrder(). Wiring
            // re-verified unchanged: OrderRequest guard + getSafe(sanitize()).
            'method_line'    => 204,
            'method_needle'  => 'function updateOrder(OrderRequest $request',
            'sanitize_line'  => 215,
            'sanitize_needle'=> '$request->getSafe($request->sanitize())',
            'coverage'       => 'validation-request-guard-wiring',
        ],
        [
            'surface'        => 'Customer create',
            'source_file'    => 'app/Http/Controllers/CustomerController.php',
            'method_line'    => 31,
            'method_needle'  => 'function store(CustomerRequest $request)',
            'sanitize_line'  => 33,
            'sanitize_needle'=> '$request->getSafe($request->sanitize())',
            'coverage'       => 'validation-request-guard-wiring',
        ],
        [
            'surface'        => 'Customer update',
            'source_file'    => 'app/Http/Controllers/CustomerController.php',
            'method_line'    => 42,
            'method_needle'  => 'function update(CustomerRequest $request',
            'sanitize_line'  => 44,
            'sanitize_needle'=> '$request->getSafe($request->sanitize())',
            'coverage'       => 'validation-request-guard-wiring',
        ],
        [
            'surface'        => 'Coupon create',
            'source_file'    => 'app/Http/Controllers/CouponsController.php',
            'method_line'    => 48,
            'method_needle'  => 'function create(CouponRequest $request)',
            'sanitize_line'  => 51,
            'sanitize_needle'=> '$request->getSafe($request->sanitize())',
            'coverage'       => 'validation-request-guard-wiring',
        ],
        [
            'surface'        => 'Coupon update',
            'source_file'    => 'app/Http/Controllers/CouponsController.php',
            'method_line'    => 91,
            'method_needle'  => 'function update(CouponRequest $request',
            'sanitize_line'  => 94,
            'sanitize_needle'=> '$request->getSafe($request->sanitize())',
            'coverage'       => 'validation-request-guard-wiring',
        ],
        [
            'surface'        => 'Label create',
            'source_file'    => 'app/Http/Controllers/LabelController.php',
            'method_line'    => 23,
            'method_needle'  => 'function create(LabelRequest $request)',
            'sanitize_line'  => 25,
            'sanitize_needle'=> '$request->getSafe($request->sanitize())',
            'coverage'       => 'validation-request-guard-wiring',
        ],
        [
            'surface'        => 'Label relationship update',
            'source_file'    => 'app/Http/Controllers/LabelController.php',
            'method_line'    => 47,
            'method_needle'  => 'function updateSelections(Request $request)',
            'sanitize_line'  => 49,
            'sanitize_needle'=> '$data = $request->getSafe(',
            'coverage'       => 'validation-request-guard-wiring',
        ],
    ],

    'read_probes' => [
        [
            'surface'     => 'GET products',
            'source_file' => 'app/Http/Controllers/ProductController.php',
            'source_line' => 48,
            'needle'      => 'ProductFilter::fromRequest($request)->paginate()',
            'coverage'    => [
                'validation-list-ordering-select-shaped-fallback',
                'validation-list-pagination-boundaries',
                'validation-search-text-sanitizer',
                'validation-like-wildcards-are-literal',
            ],
        ],
        [
            'surface'     => 'GET orders',
            'source_file' => 'app/Http/Controllers/OrderController.php',
            'source_line' => 60,
            'needle'      => 'OrderFilter::fromRequest($request)->paginate()',
            'coverage'    => [
                'validation-list-ordering-select-shaped-fallback',
                'validation-list-pagination-boundaries',
                'validation-search-text-sanitizer',
                'validation-like-wildcards-are-literal',
            ],
        ],
        [
            'surface'     => 'GET customers',
            'source_file' => 'app/Http/Controllers/CustomerController.php',
            'source_line' => 26,
            'needle'      => 'CustomerFilter::fromRequest($request)->paginate()',
            'coverage'    => [
                'validation-list-ordering-select-shaped-fallback',
                'validation-list-pagination-boundaries',
                'validation-search-text-sanitizer',
                'validation-like-wildcards-are-literal',
            ],
        ],
        [
            'surface'     => 'GET coupons',
            'source_file' => 'app/Http/Controllers/CouponsController.php',
            'source_line' => 24,
            'needle'      => 'CouponFilter::fromRequest($request)->paginate()',
            'coverage'    => [],
            'skip_reason' => 'CouponsController::index() calls '
                . 'CouponHelper::updateCouponStatus() for every returned row at '
                . 'lines 26-28, so a GET probe is not read-only against pre-existing data.',
        ],
        [
            'surface'     => 'GET activity',
            'source_file' => 'app/Http/Controllers/ActivityController.php',
            'source_line' => 14,
            'needle'      => 'LogFilter::fromRequest($request)',
            'coverage'    => [
                'validation-list-ordering-select-shaped-fallback',
                'validation-list-pagination-boundaries',
                'validation-search-text-sanitizer',
                'validation-like-wildcards-are-literal',
                'validation-stored-activity-markup-is-escaped',
            ],
        ],
        [
            'surface'     => 'GET public/products',
            'source_file' => 'app/Http/Controllers/ShopController.php',
            'source_line' => 23,
            'needle'      => 'function getProducts(Request $request)',
            'source_checks' => [
                [
                    'source_file' => 'app/Http/Controllers/ShopController.php',
                    'source_line' => 72,
                    'needle'      => "getSafe('per_page', Sanitizer::SANITIZE_TEXT_FIELD)",
                ],
                [
                    'source_file' => 'api/Resource/ShopResource.php',
                    'source_line' => 260,
                    'needle'      => "cursorPaginate(Arr::get(\$params, 'per_page', 10)",
                ],
                [
                    'source_file' => 'api/Resource/ShopResource.php',
                    'source_line' => 262,
                    'needle'      => "simplePaginate(Arr::get(\$params, 'per_page', 10)",
                ],
            ],
            'coverage'    => [
                'validation-bypass-pagination-boundaries',
            ],
        ],
        [
            'surface'     => 'GET customer-profile/orders',
            'source_file' => 'app/Http/Controllers/FrontendControllers/CustomerOrderController.php',
            'source_line' => 45,
            'needle'      => 'function getOrders(Request $request)',
            'source_checks' => [
                [
                    'source_file' => 'app/Http/Controllers/FrontendControllers/CustomerOrderController.php',
                    'source_line' => 62,
                    'needle'      => "\$perPage = (int)\$request->get('per_page', 10)",
                ],
                [
                    'source_file' => 'app/Http/Controllers/FrontendControllers/CustomerOrderController.php',
                    'source_line' => 82,
                    'needle'      => '->paginate($perPage',
                ],
            ],
            'coverage'    => [
                'validation-cross-customer-order-is-refused',
            ],
            'skip_reason' => 'The installed pre-existing customer Order payload raises '
                . 'an unrelated Order.php:356 null-trim deprecation while serializing the '
                . 'list. Phase 10 source-checks the raw per_page flow but does not suppress '
                . 'that diagnostic or mutate real Orders to manufacture a clean probe.',
        ],
    ],

    'v_html_sinks' => [
        [
            'source_file' => 'resources/admin/Pages/Dashboard/Components/RecentActivity.vue',
            'source_line' => 168,
            'needle'      => 'v-html="activity.content"',
        ],
        [
            'source_file' => 'resources/admin/Modules/Orders/Activity.vue',
            'source_line' => 18,
            'needle'      => 'v-html="activity.content"',
        ],
        [
            'source_file' => 'resources/licensing/components/ViewLicense.vue',
            'source_line' => 297,
            'needle'      => 'v-html="log.meta_value"',
        ],
        [
            'source_file' => 'resources/admin/Modules/Tickets/AllTickets.vue',
            'source_line' => 102,
            'needle'      => 'v-html="scope.row.content"',
        ],
        [
            'source_file' => 'resources/admin/Modules/Products/parts/ProductsTable.vue',
            'source_line' => 303,
            'needle'      => 'v-html="Arr.get(scope.row, column.accessor)"',
        ],
        [
            'source_file' => 'resources/admin/Modules/Products/parts/ProductsTable.vue',
            'source_line' => 306,
            'needle'      => 'v-html="Arr.get(scope.row, column.accessor)"',
        ],
        [
            'source_file' => 'resources/admin/Modules/Products/parts/ProductsTableMobile.vue',
            'source_line' => 223,
            'needle'      => 'v-html="Arr.get(row, column.accessor)"',
        ],
        [
            'source_file' => 'resources/admin/Modules/Products/parts/ProductsTableMobile.vue',
            'source_line' => 226,
            'needle'      => 'v-html="Arr.get(row, column.accessor)"',
        ],
        [
            'source_file' => 'resources/admin/Modules/Orders/Components/OrdersTable.vue',
            'source_line' => 312,
            'needle'      => 'v-html="Arr.get(scope.row, column.accessor)"',
        ],
        [
            'source_file' => 'resources/admin/Modules/Orders/Components/OrdersTable.vue',
            'source_line' => 315,
            'needle'      => 'v-html="Arr.get(scope.row, column.accessor)"',
        ],
        [
            'source_file' => 'resources/admin/Modules/Orders/Components/OrdersTableMobile.vue',
            'source_line' => 243,
            'needle'      => 'v-html="Arr.get(row, column.accessor)"',
        ],
        [
            'source_file' => 'resources/admin/Modules/Orders/Components/OrdersTableMobile.vue',
            'source_line' => 245,
            'needle'      => 'v-html="Arr.get(row, column.accessor)"',
        ],
        [
            'source_file' => 'resources/public/customer-profile/Vue/SingleOrder.vue',
            'source_line' => 448,
            'needle'      => 'v-html="order.billing_address_text"',
        ],
        [
            'source_file' => 'resources/public/customer-profile/Vue/SingleOrder.vue',
            'source_line' => 454,
            'needle'      => 'v-html="order.shipping_address_text"',
        ],
        [
            'source_file' => 'resources/admin/Bits/Components/Inputs/TermInput.vue',
            'source_line' => 242,
            'needle'      => 'v-html="data.label"',
        ],
    ],
];

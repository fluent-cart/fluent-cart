<?php
/**
 * Phase 10 source inventory for read-only input-validation regressions.
 *
 * Request wiring is limited to the safe admin CRUD surface established in
 * Phase 9. Each read probe names the integration cases that cover it.
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
            'method_line'    => 510,
            'method_needle'  => 'function update(ProductUpdateRequest $request',
            'sanitize_line'  => 512,
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
            'method_line'    => 82,
            'method_needle'  => 'function store(OrderRequest $request)',
            'sanitize_line'  => 84,
            'sanitize_needle'=> '$request->getSafe($request->sanitize())',
            'coverage'       => 'validation-request-guard-wiring',
        ],
        [
            'surface'        => 'Order update',
            'source_file'    => 'app/Http/Controllers/OrderController.php',
            // 125/136 → 204/215: PR #2531 (193cb5590) added hasSubscription()
            // and getPaymentTypeConflict() above updateOrder(). Wiring
            // re-verified unchanged: OrderRequest guard + getSafe(sanitize()).
            // 215 → 216: getSafe() result now keyed to the sent fields via
            // array_intersect_key(), so unsent columns are left unchanged.
            // +1: Shipment import added (orphaned-shipment fix).
            'method_line'    => 245,
            'method_needle'  => 'function updateOrder(OrderRequest $request',
            'sanitize_line'  => 257,
            'sanitize_needle'=> '$request->getSafe($request->sanitize())',
            'coverage'       => 'validation-request-guard-wiring',
        ],
        [
            'surface'        => 'Customer create',
            'source_file'    => 'app/Http/Controllers/CustomerController.php',
            'method_line'    => 33,
            'method_needle'  => 'function store(CustomerRequest $request)',
            'sanitize_line'  => 35,
            'sanitize_needle'=> '$request->getSafe($request->sanitize())',
            'coverage'       => 'validation-request-guard-wiring',
        ],
        [
            'surface'        => 'Customer update',
            'source_file'    => 'app/Http/Controllers/CustomerController.php',
            'method_line'    => 44,
            'method_needle'  => 'function update(CustomerRequest $request',
            'sanitize_line'  => 46,
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
            'surface'     => 'GET customer-profile/orders',
            'source_file' => 'app/Http/Controllers/FrontendControllers/CustomerOrderController.php',
            'source_line' => 46,
            'needle'      => 'function getOrders(Request $request)',
            'source_checks' => [
                [
                    'source_file' => 'app/Http/Controllers/FrontendControllers/CustomerOrderController.php',
                    'source_line' => 63,
                    'needle'      => "\$perPage = ShopResource::clampPerPage(\$request->get('per_page', 10))",
                ],
                [
                    'source_file' => 'app/Http/Controllers/FrontendControllers/CustomerOrderController.php',
                    'source_line' => 85,
                    'needle'      => '->paginate($perPage',
                ],
            ],
            'coverage'    => [
                'validation-cross-customer-order-is-refused',
            ],
        ],
    ],
];

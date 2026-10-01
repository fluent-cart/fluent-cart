<?php
/**
 * Phase 9 source-derived admin CRUD inventory.
 *
 * The executable scope is entity CRUD for Orders, Customers, Products and
 * Variants, Coupons, and Labels. Sub-resource/settings operations are listed
 * only where they form the selected safe update leg or cross a payment,
 * gateway, scheduler, or unsupported-API boundary.
 */

$route = static function (
    $domain,
    $step,
    $verb,
    $path,
    $line,
    $classification,
    $coverage,
    $reason = ''
) {
    return [
        'domain'         => $domain,
        'step'           => $step,
        'verb'           => $verb,
        'path'           => $path,
        'declared_path'  => preg_replace('#^[^/]+#', '', $path),
        'source_file'    => 'app/Http/Routes/api.php',
        'source_line'    => $line,
        'classification' => $classification,
        'coverage'       => $coverage,
        'reason'         => $reason,
    ];
};

return [
    'routes' => [
        $route(
            'orders',
            'create',
            'POST',
            'orders/',
            553,
            'safety_exclusion',
            '',
            'OrderResource::updatedPlaceOrder() resolves the selected gateway and starts '
            . 'a payment instance; Phase 9 seeds an inert pending-payment Order by exact ID.'
        ),
        $route(
            'orders',
            'read',
            'GET',
            'orders/{order_id}',
            569,
            'round_trip',
            'crud-order-safe-route-round-trip'
        ),
        $route(
            'orders',
            'update',
            'POST',
            'orders/{order_id}',
            573,
            'round_trip',
            'crud-order-safe-route-round-trip'
        ),
        $route(
            'orders',
            'delete',
            'DELETE',
            'orders/{order_id}',
            599,
            'round_trip',
            'crud-order-safe-route-round-trip'
        ),
        $route(
            'orders',
            'payment',
            'POST',
            'orders/{order}/mark-as-paid',
            561,
            'safety_exclusion',
            '',
            'Creates/changes payment state and is expressly outside Phase 9.'
        ),
        $route(
            'orders',
            'license_lifecycle',
            'POST',
            'orders/{order}/generate-missing-licenses',
            565,
            'safety_exclusion',
            '',
            'License lifecycle mutation is covered by Phase 8, not entity CRUD.'
        ),
        $route(
            'orders',
            'refund',
            'POST',
            'orders/{order_id}/refund',
            585,
            'safety_exclusion',
            '',
            'Resolves a gateway and creates a refund; invocation is prohibited.'
        ),
        $route(
            'orders',
            'dispute',
            'POST',
            'orders/{order}/transactions/{transaction_id}/accept-dispute/',
            611,
            'safety_exclusion',
            '',
            'Mutates a payment dispute/transaction rather than the Order CRUD row.'
        ),
        $route(
            'orders',
            'transaction_status',
            'PUT',
            'orders/{order}/transactions/{transaction}/status',
            625,
            'safety_exclusion',
            '',
            'Writes payment transaction state and can trigger payment lifecycle behavior.'
        ),
        $route(
            'orders',
            'gateway_sync',
            'POST',
            'orders/{order}/transactions/{transaction}/sync',
            629,
            'safety_exclusion',
            '',
            'Explicitly resolves a gateway to synchronize a live transaction.'
        ),
        $route(
            'orders',
            'payment_status_sync',
            'PUT',
            'orders/{order}/sync-statuses',
            641,
            'safety_exclusion',
            '',
            'Synchronizes payment/order lifecycle state and is not a one-field CRUD update.'
        ),

        $route(
            'customers',
            'create',
            'POST',
            'customers/',
            682,
            'round_trip',
            'crud-customer-route-round-trip'
        ),
        $route(
            'customers',
            'read',
            'GET',
            'customers/{customerId}',
            702,
            'round_trip',
            'crud-customer-route-round-trip'
        ),
        $route(
            'customers',
            'update',
            'PUT',
            'customers/{customerId}',
            710,
            'round_trip',
            'crud-customer-route-round-trip'
        ),
        $route(
            'customers',
            'delete',
            'POST',
            'customers/do-bulk-action',
            718,
            'round_trip',
            'crud-customer-route-round-trip',
            'The API exposes exact selected-customer deletion through its bulk-action route.'
        ),

        $route(
            'products',
            'create',
            'POST',
            'products/',
            118,
            'safety_exclusion',
            '',
            'wp_insert_post() reaches WordPress future-post cron mutation hooks on this install; '
            . 'Phase 9 seeds the exact Product/ProductDetail/Variation through model fixtures.'
        ),
        $route(
            'products',
            'read',
            'GET',
            'products/{productId}/pricing',
            139,
            'round_trip',
            'crud-product-safe-route-round-trip'
        ),
        $route(
            'products',
            'update',
            'POST',
            'products/{postId}/update-long-desc-editor-mode',
            167,
            'round_trip',
            'crud-product-safe-route-round-trip',
            'This safe sub-resource route changes one ProductDetail JSON column without wp_update_post().'
        ),
        $route(
            'products',
            'post_update',
            'POST',
            'products/{postId}/pricing',
            163,
            'safety_exclusion',
            '',
            'The post-field path calls wp_update_post(), which can mutate future-post cron state.'
        ),
        $route(
            'products',
            'delete',
            'DELETE',
            'products/{product}',
            219,
            'round_trip',
            'crud-product-safe-route-round-trip'
        ),

        $route(
            'variants',
            'create',
            'POST',
            'products/variants',
            242,
            'round_trip',
            'crud-variant-route-round-trip'
        ),
        $route(
            'variants',
            'read',
            'GET',
            'products/variants',
            67,
            'round_trip',
            'crud-variant-route-round-trip'
        ),
        $route(
            'variants',
            'update',
            'POST',
            'products/variants/{variantId}',
            259,
            'round_trip',
            'crud-variant-route-round-trip'
        ),
        $route(
            'variants',
            'delete',
            'DELETE',
            'products/variants/{variantId}',
            266,
            'round_trip',
            'crud-variant-route-round-trip'
        ),

        $route(
            'coupons',
            'create',
            'POST',
            'coupons/',
            799,
            'round_trip',
            'crud-coupon-route-round-trip'
        ),
        $route(
            'coupons',
            'read',
            'GET',
            'coupons/{id}',
            796,
            'round_trip',
            'crud-coupon-route-round-trip'
        ),
        $route(
            'coupons',
            'update',
            'PUT',
            'coupons/{id}',
            802,
            'round_trip',
            'crud-coupon-route-round-trip'
        ),
        $route(
            'coupons',
            'delete',
            'DELETE',
            'coupons/{id}',
            805,
            'round_trip',
            'crud-coupon-route-round-trip'
        ),
        $route(
            'coupons',
            'apply_to_order',
            'POST',
            'coupons/apply',
            808,
            'safety_exclusion',
            '',
            'Order calculation/application is not Coupon entity CRUD and can bind to an Order.'
        ),
        $route(
            'coupons',
            'cancel_on_order',
            'POST',
            'coupons/cancel',
            812,
            'safety_exclusion',
            '',
            'Can delete an applied Coupon and decrement use_count on Order state.'
        ),
        $route(
            'coupons',
            'reapply_to_order',
            'POST',
            'coupons/re-apply',
            816,
            'safety_exclusion',
            '',
            'Can rewrite applied-Coupon Order relationships; not entity CRUD.'
        ),

        $route(
            'labels',
            'create',
            'POST',
            'labels/',
            669,
            'round_trip',
            'crud-label-supported-routes'
        ),
        $route(
            'labels',
            'read',
            'GET',
            'labels/',
            666,
            'round_trip',
            'crud-label-supported-routes'
        ),
        $route(
            'labels',
            'update_relationship',
            'POST',
            'labels/update-label-selections',
            672,
            'round_trip',
            'crud-label-supported-routes',
            'The only Label mutation after create updates relationships, not the Label row.'
        ),
    ],
    'missing' => [
        [
            'domain'         => 'labels',
            'step'           => 'entity_update',
            'classification' => 'known_failure',
            'coverage'       => 'crud-label-entity-routes-exist',
            'reason'         => 'No Label entity update route is declared; LabelResource::update() is empty.',
            'production'     => 'api/Resource/LabelResource.php:122-125',
        ],
        [
            'domain'         => 'labels',
            'step'           => 'entity_delete',
            'classification' => 'known_failure',
            'coverage'       => 'crud-label-entity-routes-exist',
            'reason'         => 'No Label entity delete route is declared; LabelResource::delete() is empty.',
            'production'     => 'api/Resource/LabelResource.php:137-140',
        ],
    ],
];

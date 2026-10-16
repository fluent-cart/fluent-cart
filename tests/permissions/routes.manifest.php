<?php
/**
 * Phase 3 permission classifications keyed by exact source declaration ID.
 *
 * Route shape, verb, group prefix, policy, permissions, transport, and handler
 * are independently derived from source by tests/lib/permission-route-source.php.
 * Keeping this map explicit makes every newly added mutating declaration fail
 * the inventory lint until its permission expectation is reviewed.
 */

$classifications = [];

$protect = static function ($file, array $sourceIds) use (&$classifications) {
    foreach ($sourceIds as $sourceId) {
        $id = $file . ':' . $sourceId;
        $classifications[$id] = [
            'classification'      => 'protected_executable',
            'expected_anonymous'  => 401,
            'expected_subscriber' => 403,
            'params'              => [
                '_fc_permission_probe' => 'invalid-noop',
            ],
            'reason'              => 'Protected FluentCart admin declaration ' . $id
                . '; denial-only dispatch is guarded by a post-permission controller fuse.',
        ];
    }
};

$exempt = static function ($sourceId, $reason) use (&$classifications) {
    $id = 'app/Http/Routes/frontend_routes.php:' . $sourceId;
    $classifications[$id] = [
        'classification' => 'public_exempt',
        'reason'         => $reason,
    ];
};

/*
 * All active admin REST mutations. The source IDs are deliberately explicit:
 * adding or moving a declaration forces a fresh permission review.
 */
$protect('app/Http/Routes/api.php', [
    '57:POST',
    '82:POST',
    '115:POST',
    '118:POST',
    '121:POST',
    '127:POST',
    '142:POST',
    '149:POST',
    '152:POST',
    '155:DELETE',
    '163:POST',
    '167:POST',
    '170:POST',
    '173:POST',
    '176:POST',
    '180:POST',
    '183:POST',
    '188:PUT',
    '191:PUT',
    '197:POST',
    '200:PUT',
    '203:DELETE',
    '213:POST',
    '216:POST',
    '219:DELETE',
    '223:POST',
    '227:POST',
    '231:POST',
    '234:POST',
    '238:POST',
    '242:POST',
    '249:POST',
    '255:POST',
    '259:POST',
    '262:POST',
    '266:DELETE',
    '270:POST',
    '274:PUT',
    '299:POST',
    '302:POST',
    '308:PUT',
    '311:DELETE',
    '317:POST',
    '320:POST',
    '323:DELETE',
    '326:POST',
    '342:POST',
    '350:POST',
    '354:DELETE',
    '364:POST',
    '377:POST',
    '381:POST',
    '389:POST',
    '393:POST',
    '408:POST',
    '415:POST',
    '422:POST',
    '426:POST',
    '430:POST',
    '434:POST',
    '445:POST',
    '454:POST',
    '466:POST',
    '469:POST',
    '472:POST',
    '478:POST',
    '486:POST',
    '489:POST',
    '497:POST',
    '509:POST',
    '518:POST',
    '521:POST',
    '524:POST',
    '527:POST',
    '530:POST',
    '542:POST',
    '548:POST',
    '553:POST',
    '557:POST',
    '565:POST',
    '569:POST',
    '573:POST',
    '581:POST',
    '587:POST',
    '593:POST',
    '599:POST',
    '603:POST',
    '607:DELETE',
    '611:PUT',
    '619:POST',
    '629:PUT',
    '633:PUT',
    '637:POST',
    '641:POST',
    '649:PUT',
    '664:POST',
    '668:POST',
    '677:POST',
    '680:POST',
    '690:POST',
    '702:POST',
    '706:POST',
    '718:PUT',
    '722:PUT',
    '726:POST',
    '738:PUT',
    '742:POST',
    '746:DELETE',
    '750:POST',
    '754:POST',
    '764:POST',
    '765:POST',
    '766:POST',
    '767:POST',
    '773:POST',
    '775:POST',
    '777:POST',
    '778:POST',
    '779:POST',
    '780:POST',
    '781:POST',
    '783:PUT',
    '789:PUT',
    '807:POST',
    '810:PUT',
    '813:DELETE',
    '816:POST',
    '820:POST',
    '824:POST',
    '828:POST',
    '832:POST',
    '839:POST',
    '841:DELETE',
    '844:POST',
    '847:POST',
    '853:POST',
    '858:DELETE',
    '859:PUT',
    '864:POST',
    '879:POST',
    '883:DELETE',
    '887:POST',
    '904:POST',
    '907:DELETE',
    '918:POST',
    '921:DELETE',
    '924:PUT',
    '927:POST',
    '930:DELETE',
    '933:POST',
    '939:POST',
    '947:POST',
    '950:DELETE',
    '960:POST',
    '966:POST',
    '969:POST',
    '972:POST',
    '981:POST',
    '988:POST',
    '1002:PUT',
    '1005:DELETE',
    '1008:POST',
    '1011:POST',
    '1014:DELETE',
    '1017:POST',
    '1020:POST',
]);

$protect('app/Http/Routes/reports.php', [
    '88:POST',
]);

/*
 * WPFluent resolves type-hinted route models before it calls the policy. These
 * exact declarations therefore need read-only existing IDs to reach the policy
 * callback at all. Controllers remain fused off after permission evaluation.
 */
$classifications['app/Http/Routes/api.php:219:DELETE']['bindings'] = [
    'product' => 'existing_product_id',
];
foreach (['569:POST', '573:POST', '611:PUT', '641:POST', '649:PUT', '664:POST', '668:POST'] as $sourceId) {
    $classifications['app/Http/Routes/api.php:' . $sourceId]['bindings'] = [
        'order' => 'existing_order_id',
    ];
}
foreach (['633:PUT', '637:POST'] as $sourceId) {
    $classifications['app/Http/Routes/api.php:' . $sourceId]['bindings'] = [
        'transaction' => 'existing_transaction_id',
    ];
}

/*
 * Frontend/public mutations are intentionally not dispatched by this tier.
 * These are not FluentCart-admin authorization contracts, and several can
 * create customer/order/payment state even under deliberately invalid input.
 */
$exempt(
    '37:POST',
    'Public checkout order-creation contract; anonymous acceptance is intentional, '
        . 'and dispatch could create an order or initialize payment handling.'
);
$exempt(
    '47:POST',
    'Public login contract; anonymous acceptance is intentional, and dispatch could authenticate a user.'
);

// The frontend PUT/DELETE customers/{customerId}(/address) duplicates were
// removed with their routes — they shadowed the admin customers group at
// identical paths and were never dispatchable. Only the checkout add-address
// mutation remains in the frontend customers group.
$exempt(
    '60:POST',
    'Customer-owned checkout add-address mutation at frontend_routes.php:59:POST'
        . ' uses PublicPolicy plus controller ownership (createAddress resolves the current customer and'
        . ' refuses guests itself), not the FluentCart admin role contract; dispatch could mutate customer data.'
);
foreach (['69:POST', '71:POST', '72:POST', '73:POST', '75:POST'] as $sourceId) {
    $exempt(
        $sourceId,
        'Logged-in customer profile mutation at frontend_routes.php:' . $sourceId
            . ' is a customer ownership surface, not a FluentCart admin role surface; '
            . 'dispatch could mutate profile or address data.'
    );
}
$exempt(
    '85:PUT',
    'Logged-in customer order billing-address mutation; controller ownership is outside the '
        . 'FluentCart admin permission contract, and dispatch could alter order address data.'
);
foreach (['92:POST', '93:POST', '94:POST', '95:POST', '97:POST'] as $sourceId) {
    $exempt(
        $sourceId,
        'Customer subscription billing/payment mutation at frontend_routes.php:' . $sourceId
            . ' is not an admin-role denial contract and could initialize or alter payment state; never invoked.'
    );
}
foreach (['96:POST', '98:POST', '99:POST'] as $sourceId) {
    $exempt(
        $sourceId,
        'Customer subscription lifecycle mutation at frontend_routes.php:' . $sourceId
            . ' is governed by logged-in ownership rather than FluentCart admin permissions; '
            . 'dispatch could change subscription state.'
    );
}
foreach (['111:POST', '112:PUT'] as $sourceId) {
    $exempt(
        $sourceId,
        'Public product-review mutation at frontend_routes.php:' . $sourceId
            . ' is intentionally anonymous-capable (guest reviews) and self-guarded in the controller '
            . '(nonce where applicable, permission-mode eligibility, ownership pinning, rate limits); '
            . 'it is not a FluentCart admin role contract, and dispatch could create review content.'
    );
}

return [
    'route_files' => [
        'app/Http/Routes/api.php',
        'app/Http/Routes/routes.php',
        'app/Http/Routes/reports.php',
        'app/Http/Routes/index.php',
        'app/Http/Routes/advance_filter_routes.php',
        'app/Http/Routes/frontend_routes.php',
        'app/Http/Routes/WebRoutes.php',
        'app/Http/Routes/FakerRoutes.php',
    ],
    'classifications' => $classifications,
];

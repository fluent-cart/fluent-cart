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
    '56:POST',
    '81:POST',
    '114:POST',
    '117:POST',
    '120:POST',
    '126:POST',
    '141:POST',
    '148:POST',
    '151:POST',
    '154:DELETE',
    '162:POST',
    '166:POST',
    '169:POST',
    '172:POST',
    '175:POST',
    '179:POST',
    '182:POST',
    '187:PUT',
    '190:PUT',
    '196:POST',
    '199:PUT',
    '202:DELETE',
    '212:POST',
    '215:POST',
    '218:DELETE',
    '222:POST',
    '226:POST',
    '230:POST',
    '233:POST',
    '237:POST',
    '241:POST',
    '248:POST',
    '254:POST',
    '258:POST',
    '261:POST',
    '265:DELETE',
    '269:POST',
    '273:PUT',
    '298:POST',
    '301:POST',
    '307:PUT',
    '310:DELETE',
    '316:POST',
    '319:POST',
    '322:DELETE',
    '325:POST',
    '341:POST',
    '349:POST',
    '353:DELETE',
    '363:POST',
    '376:POST',
    '380:POST',
    '388:POST',
    '392:POST',
    '407:POST',
    '414:POST',
    '421:POST',
    '425:POST',
    '429:POST',
    '433:POST',
    '444:POST',
    '453:POST',
    '465:POST',
    '468:POST',
    '471:POST',
    '477:POST',
    '485:POST',
    '488:POST',
    '496:POST',
    '508:POST',
    '517:POST',
    '520:POST',
    '523:POST',
    '526:POST',
    '529:POST',
    '541:POST',
    '547:POST',
    '552:POST',
    '556:POST',
    '560:POST',
    '564:POST',
    '572:POST',
    '578:POST',
    '584:POST',
    '590:POST',
    '594:POST',
    '598:DELETE',
    '602:PUT',
    '610:POST',
    '620:PUT',
    '624:PUT',
    '628:POST',
    '632:POST',
    '640:PUT',
    '655:POST',
    '659:POST',
    '668:POST',
    '671:POST',
    '681:POST',
    '693:POST',
    '697:POST',
    '709:PUT',
    '713:PUT',
    '717:POST',
    '729:PUT',
    '733:POST',
    '737:DELETE',
    '741:POST',
    '745:POST',
    '755:POST',
    '756:POST',
    '757:POST',
    '758:POST',
    '764:POST',
    '766:POST',
    '768:POST',
    '769:POST',
    '770:POST',
    '771:POST',
    '772:POST',
    '774:PUT',
    '780:PUT',
    '798:POST',
    '801:PUT',
    '804:DELETE',
    '807:POST',
    '811:POST',
    '815:POST',
    '819:POST',
    '823:POST',
    '830:POST',
    '832:DELETE',
    '835:POST',
    '838:POST',
    '844:POST',
    '849:DELETE',
    '850:PUT',
    '855:POST',
    '870:POST',
    '874:DELETE',
    '878:POST',
    '895:POST',
    '898:DELETE',
    '909:POST',
    '912:DELETE',
    '915:PUT',
    '918:POST',
    '921:DELETE',
    '924:POST',
    '930:POST',
    '938:POST',
    '941:DELETE',
    '951:POST',
    '957:POST',
    '960:POST',
    '963:POST',
    '972:POST',
    '979:POST',
]);

$protect('app/Http/Routes/reports.php', [
    '88:POST',
]);

/*
 * WPFluent resolves type-hinted route models before it calls the policy. These
 * exact declarations therefore need read-only existing IDs to reach the policy
 * callback at all. Controllers remain fused off after permission evaluation.
 */
$classifications['app/Http/Routes/api.php:218:DELETE']['bindings'] = [
    'product' => 'existing_product_id',
];
foreach (['560:POST', '564:POST', '602:PUT', '632:POST', '640:PUT', '655:POST', '659:POST'] as $sourceId) {
    $classifications['app/Http/Routes/api.php:' . $sourceId]['bindings'] = [
        'order' => 'existing_order_id',
    ];
}
foreach (['624:PUT', '628:POST'] as $sourceId) {
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
    '36:POST',
    'Public checkout order-creation contract; anonymous acceptance is intentional, '
        . 'and dispatch could create an order or initialize payment handling.'
);
$exempt(
    '46:POST',
    'Public login contract; anonymous acceptance is intentional, and dispatch could authenticate a user.'
);

// The frontend PUT/DELETE customers/{customerId}(/address) duplicates were
// removed with their routes — they shadowed the admin customers group at
// identical paths and were never dispatchable. Only the checkout add-address
// mutation remains in the frontend customers group.
$exempt(
    '59:POST',
    'Customer-owned checkout add-address mutation at frontend_routes.php:59:POST'
        . ' uses PublicPolicy plus controller ownership (createAddress resolves the current customer and'
        . ' refuses guests itself), not the FluentCart admin role contract; dispatch could mutate customer data.'
);
foreach (['68:POST', '70:POST', '71:POST', '72:POST', '74:POST'] as $sourceId) {
    $exempt(
        $sourceId,
        'Logged-in customer profile mutation at frontend_routes.php:' . $sourceId
            . ' is a customer ownership surface, not a FluentCart admin role surface; '
            . 'dispatch could mutate profile or address data.'
    );
}
$exempt(
    '84:PUT',
    'Logged-in customer order billing-address mutation; controller ownership is outside the '
        . 'FluentCart admin permission contract, and dispatch could alter order address data.'
);
foreach (['91:POST', '92:POST', '93:POST', '94:POST', '96:POST'] as $sourceId) {
    $exempt(
        $sourceId,
        'Customer subscription billing/payment mutation at frontend_routes.php:' . $sourceId
            . ' is not an admin-role denial contract and could initialize or alter payment state; never invoked.'
    );
}
foreach (['95:POST', '97:POST', '98:POST'] as $sourceId) {
    $exempt(
        $sourceId,
        'Customer subscription lifecycle mutation at frontend_routes.php:' . $sourceId
            . ' is governed by logged-in ownership rather than FluentCart admin permissions; '
            . 'dispatch could change subscription state.'
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

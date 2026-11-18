<?php
/**
 * Phase 3 permission classifications keyed by exact source declaration ID.
 *
 * Route shape, verb, group prefix, policy, permissions, transport, and handler
 * are independently derived from source by tests/wp-cli/lib/permission-route-source.php.
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
    '425:POST',
    '429:POST',
    '433:POST',
    '437:POST',
    '448:POST',
    '457:POST',
    '469:POST',
    '472:POST',
    '475:POST',
    '481:POST',
    '489:POST',
    '492:POST',
    '498:POST',
    '500:POST',
    '512:POST',
    '521:POST',
    '524:POST',
    '527:POST',
    '530:POST',
    '533:POST',
    '545:POST',
    '551:POST',
    '556:POST',
    '560:POST',
    '568:POST',
    '572:POST',
    '576:POST',
    '584:POST',
    '590:POST',
    '596:POST',
    '602:POST',
    '609:POST',
    '613:POST',
    '617:DELETE',
    '621:PUT',
    '629:POST',
    '639:PUT',
    '643:PUT',
    '647:POST',
    '651:POST',
    '659:PUT',
    '671:POST',
    '682:POST',
    '689:POST',
    '695:POST',
    '706:PUT',
    '716:DELETE',
    '725:POST',
    '751:POST',
    '755:POST',
    '764:POST',
    '767:POST',
    '777:POST',
    '789:POST',
    '793:POST',
    '805:PUT',
    '809:PUT',
    '813:POST',
    '825:PUT',
    '829:POST',
    '833:DELETE',
    '837:POST',
    '841:POST',
    '845:POST',
    '855:POST',
    '856:POST',
    '857:POST',
    '858:POST',
    '864:POST',
    '866:POST',
    '868:POST',
    '869:POST',
    '870:POST',
    '871:POST',
    '872:POST',
    '874:PUT',
    '880:PUT',
    '898:POST',
    '901:PUT',
    '904:DELETE',
    '907:POST',
    '911:POST',
    '915:POST',
    '919:POST',
    '923:POST',
    '930:POST',
    '932:DELETE',
    '935:POST',
    '938:POST',
    '944:POST',
    '949:DELETE',
    '950:PUT',
    '955:POST',
    '970:POST',
    '974:DELETE',
    '978:POST',
    '995:POST',
    '998:DELETE',
    '1009:POST',
    '1012:DELETE',
    '1015:PUT',
    '1018:POST',
    '1021:DELETE',
    '1024:POST',
    '1030:POST',
    '1038:POST',
    '1041:DELETE',
    '1051:POST',
    '1057:POST',
    '1060:POST',
    '1063:POST',
    '1072:POST',
    '1079:POST',
    '1080:POST',
    '1094:PUT',
    '1097:DELETE',
    '1100:POST',
    '1103:POST',
    '1106:DELETE',
    '1109:POST',
    '1112:POST',
]);

$protect('app/Http/Routes/reports.php', [
    '62:POST',
    '63:POST',
]);

/*
 * WPFluent resolves type-hinted route models before it calls the policy. These
 * exact declarations therefore need read-only existing IDs to reach the policy
 * callback at all. Controllers remain fused off after permission evaluation.
 */
$classifications['app/Http/Routes/api.php:219:DELETE']['bindings'] = [
    'product' => 'existing_product_id',
];
foreach (['572:POST', '576:POST', '621:PUT', '651:POST', '659:PUT', '751:POST', '755:POST'] as $sourceId) {
    $classifications['app/Http/Routes/api.php:' . $sourceId]['bindings'] = [
        'order' => 'existing_order_id',
    ];
}
foreach (['643:PUT', '647:POST'] as $sourceId) {
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

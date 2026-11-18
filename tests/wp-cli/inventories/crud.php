<?php
/**
 * Phase 9 source-derived inventory of admin CRUD routes that a round-trip case covers.
 *
 * A sub-resource route is listed only where it forms the selected safe update leg.
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
            'customers',
            'create',
            'POST',
            'customers/',
            777,
            'round_trip',
            'crud-customer-route-round-trip'
        ),
        $route(
            'customers',
            'read',
            'GET',
            'customers/{customerId}',
            797,
            'round_trip',
            'crud-customer-route-round-trip'
        ),
        $route(
            'customers',
            'update',
            'PUT',
            'customers/{customerId}',
            805,
            'round_trip',
            'crud-customer-route-round-trip'
        ),
        $route(
            'customers',
            'delete',
            'POST',
            'customers/do-bulk-action',
            813,
            'round_trip',
            'crud-customer-route-round-trip',
            'The API exposes exact selected-customer deletion through its bulk-action route.'
        ),

        $route(
            'labels',
            'create',
            'POST',
            'labels/',
            764,
            'round_trip',
            'crud-label-supported-routes'
        ),
        $route(
            'labels',
            'read',
            'GET',
            'labels/',
            761,
            'round_trip',
            'crud-label-supported-routes'
        ),
        $route(
            'labels',
            'update_relationship',
            'POST',
            'labels/update-label-selections',
            767,
            'round_trip',
            'crud-label-supported-routes',
            'The only Label mutation after create updates relationships, not the Label row.'
        ),
    ],
];

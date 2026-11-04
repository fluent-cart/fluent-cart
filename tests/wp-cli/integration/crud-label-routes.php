<?php
/**
 * Phase 9 Label supported-route coverage plus the missing entity CRUD finding.
 */

return [
    [
        'id'            => 'crud-label-supported-routes',
        'name'          => 'Label create/read and relationship update preserve the full Label row',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            $value = FcCrudFixture::marker('label');

            try {
                $customer = FcFixture::customer([
                    'notes' => FcCrudFixture::marker('label-customer'),
                ]);
                $customerId = (int) $customer->id;

                $create = FcTest::rest('POST', '/labels/', [
                    'value' => $value,
                    'bind_to_type' => 'Customer',
                    'bind_to_id' => $customerId,
                ]);
                FcCrudFixture::requireHealthy($create, 'POST /labels/');
                $labelId = FcCrudFixture::createdId($create, 'POST /labels/');
                FcCrudFixture::capture('label', $labelId, ['value' => $value]);
                $relationship = FcCrudFixture::captureLabelRelationship(
                    $labelId,
                    $customerId,
                    'FluentCart\\App\\Models\\Customer'
                );
                $relationshipId = (int) $relationship->id;

                $before = FcCrudFixture::snapshot('label', $labelId);
                if ($before === null) {
                    throw new RuntimeException('Label disappeared after route create.');
                }

                $read = FcTest::rest('GET', '/labels/');
                FcCrudFixture::requireHealthy($read, 'GET /labels/');
                $labels = $read['data']['labels'] ?? [];
                if (is_object($labels) && method_exists($labels, 'toArray')) {
                    $labels = $labels->toArray();
                }
                $labels = is_array($labels) ? array_values($labels) : [];
                $readIds = array_map(function ($label) {
                    return (int) ($label['id'] ?? 0);
                }, $labels);
                FcTest::assert(
                    in_array($labelId, $readIds, true),
                    'Label read includes the exact route-created ID'
                );

                $update = FcTest::rest('POST', '/labels/update-label-selections', [
                    'bind_to_type' => 'Customer',
                    'bind_to_id' => $customerId,
                    'selectedLabels' => [],
                ]);
                FcCrudFixture::requireHealthy(
                    $update,
                    'POST /labels/update-label-selections'
                );
                $after = FcCrudFixture::snapshot('label', $labelId);
                if ($after === null) {
                    throw new RuntimeException('Label row was deleted by relationship update.');
                }
                FcTest::assertSame(
                    $before,
                    $after,
                    'Label relationship update preserves every full Label-row value'
                );
                FcTest::assertSame(
                    null,
                    FcCrudFixture::snapshot('label_relationship', $relationshipId),
                    'Label relationship update removes only the selected exact relationship'
                );
            } finally {
                FcCrudFixture::cleanupAll();
                FcFixture::cleanupAll();
            }

            FcTest::assertSame(
                0,
                FcCrudFixture::markerResidueCounts()['label'],
                'Label route marker is absent after finally cleanup'
            );
        },
    ],
];

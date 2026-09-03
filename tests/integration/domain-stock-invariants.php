<?php
/**
 * Phase 8 stock reservation and restock conservation invariants.
 */

use FluentCart\App\Modules\StockManagement\StockManagement;

return [
    [
        'id'            => 'domain-stock-never-reserves-more-than-total',
        'name'          => 'Stock decrement never reserves more units than total stock',
        'kind'          => 'behavior',
        'known_failure' => true,
        'run'           => function () {
            try {
                $product = FcDomainFixture::product(['total_stock' => 3]);
                $variationId = (int) $product['variation']->id;
                $order = FcDomainFixture::orderWithItem(
                    (int) $product['post']->ID,
                    $variationId,
                    5
                );

                (new StockManagement())->manageStockOnOrderCreated([
                    'order'      => $order,
                    'prev_order' => null,
                ]);

                $stored = FcDomainFixture::reloadVariation($variationId);
                $actual = [
                    'total_stock' => (int) $stored->total_stock,
                    'available'   => (int) $stored->available,
                    'on_hold'     => (int) $stored->on_hold,
                    'committed'   => (int) $stored->committed,
                    'ledger'      => (int) $stored->available
                        + (int) $stored->on_hold
                        + (int) $stored->committed,
                ];
                $expected = [
                    'total_stock' => 3,
                    'available'   => 0,
                    'on_hold'     => 3,
                    'committed'   => 0,
                    'ledger'      => 3,
                ];

                if ($actual === $expected) {
                    FcTest::fail(
                        'KNOWN-FAILURE unexpectedly passed; reclassify the stock oversell invariant.'
                    );
                } elseif ($actual === [
                    'total_stock' => 3,
                    'available'   => 0,
                    'on_hold'     => 5,
                    'committed'   => 0,
                    'ledger'      => 5,
                ]) {
                    FcTest::skip(
                        'KNOWN-FAILURE — StockManagement.php:168-181 clamps available '
                        . 'but reserves the full oversold quantity; observed '
                        . wp_json_encode($actual)
                    );
                } else {
                    FcTest::fail(
                        'KNOWN-FAILURE behavior drifted from the documented oversell defect.'
                        . "\n  expected defect: "
                        . wp_json_encode([
                            'total_stock' => 3,
                            'available'   => 0,
                            'on_hold'     => 5,
                            'committed'   => 0,
                            'ledger'      => 5,
                        ])
                        . "\n  actual: " . wp_json_encode($actual)
                    );
                }
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'domain-stock-restock-is-idempotent',
        'name'          => 'Canceled Order restock restores exactly once',
        'kind'          => 'behavior',
        'known_failure' => true,
        'run'           => function () {
            try {
                $product = FcDomainFixture::product(['total_stock' => 10]);
                $variationId = (int) $product['variation']->id;
                $order = FcDomainFixture::orderWithItem(
                    (int) $product['post']->ID,
                    $variationId,
                    2
                );
                $manager = new StockManagement();

                $manager->manageStockOnOrderCreated([
                    'order'      => $order,
                    'prev_order' => null,
                ]);
                $afterReserve = FcDomainFixture::reloadVariation($variationId);
                FcTest::assertSame(
                    [10, 8, 2, 0],
                    [
                        (int) $afterReserve->total_stock,
                        (int) $afterReserve->available,
                        (int) $afterReserve->on_hold,
                        (int) $afterReserve->committed,
                    ],
                    'initial reservation conserves the stock ledger'
                );

                $event = ['order' => $order, 'new_status' => 'canceled'];
                $manager->manageStockOnOrderStatusChanged($event);
                $afterFirst = FcDomainFixture::reloadVariation($variationId);
                FcTest::assertSame(
                    [10, 10, 0, 0],
                    [
                        (int) $afterFirst->total_stock,
                        (int) $afterFirst->available,
                        (int) $afterFirst->on_hold,
                        (int) $afterFirst->committed,
                    ],
                    'first cancellation restores the exact reserved quantity'
                );

                $manager->manageStockOnOrderStatusChanged($event);
                $afterSecond = FcDomainFixture::reloadVariation($variationId);
                $actual = [
                    (int) $afterSecond->total_stock,
                    (int) $afterSecond->available,
                    (int) $afterSecond->on_hold,
                    (int) $afterSecond->committed,
                ];

                if ($actual === [10, 10, 0, 0]) {
                    FcTest::fail(
                        'KNOWN-FAILURE unexpectedly passed; reclassify the restock invariant.'
                    );
                } elseif ($actual === [10, 12, 0, 0]) {
                    FcTest::skip(
                        'KNOWN-FAILURE — StockManagement.php:430-513 drains movement '
                        . 'but always adds the full quantity; repeated cancel observed '
                        . wp_json_encode($actual)
                    );
                } else {
                    FcTest::fail(
                        'KNOWN-FAILURE behavior drifted from the documented repeated-restock defect.'
                        . "\n  expected defect: [10,12,0,0]"
                        . "\n  actual: " . wp_json_encode($actual)
                    );
                }
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];

<?php
/**
 * Phase 16 query-count and batch-size guards at five and twenty-five rows.
 *
 * Query profiles deliberately materialize the relations consumed by each list
 * so removing the production eager-load path exposes N+1 behavior. Elapsed
 * values are printed for diagnostics and are never asserted.
 */

$assertStableProfile = function (array $small, array $large, $label) {
    FcTest::assert(
        $small['query_count'] > 0,
        $label . ' five-row profile executes a real database query'
    );
    FcTest::assertSame(
        $small['query_count'],
        $large['query_count'],
        $label . ' query count does not grow from 5 to 25 rows'
    );
};

$logProfiles = function ($label, array $small, array $large) {
    WP_CLI::log(sprintf(
        '  throughput %s: rows=5 queries=%d elapsed=%.3fms; rows=25 queries=%d elapsed=%.3fms',
        $label,
        $small['query_count'],
        $small['elapsed_ms'],
        $large['query_count'],
        $large['elapsed_ms']
    ));
};

return [
    [
        'id'            => 'throughput-order-list',
        'name'          => 'Order list eager loads customer and items without query growth at 25 rows',
        'phase'         => 16,
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($assertStableProfile, $logProfiles) {
            try {
                $customer = FcFixture::customer([
                    'first_name' => 'Phase Sixteen',
                    'last_name'  => 'Orders',
                ]);
                $createOrders = function ($count) {
                    for ($index = 0; $index < $count; $index++) {
                        $order = FcFixture::order([
                            'config' => ['fixture_case' => 'phase16-order-list'],
                        ]);
                        FcFixture::reportOrderItem((int) $order->id, [
                            'post_title'  => FcFixture::reportMarker('phase16-product'),
                            'title'       => FcFixture::reportMarker('phase16-variant'),
                            'quantity'    => 1,
                            'unit_price'  => 1000,
                            'subtotal'    => 1000,
                            'line_total'  => 1000,
                        ]);
                    }
                };
                $profile = function ($size) {
                    return FcTest::profileQueries(function () use ($size) {
                        $paginator = \FluentCart\App\Services\Filter\OrderFilter::make([
                            'search'    => FcFixture::identity(),
                            'per_page'  => $size,
                            'page'      => 1,
                            'sort_by'   => 'id',
                            'sort_type' => 'ASC',
                            // A screen key, not a relation name — OrderFilter's
                            // allow-list resolves it to the whole order-list
                            // eager load.
                            'with'      => [
                                'admin_order_list',
                            ],
                        ])->paginate();

                        $loaded = 0;
                        $relatedItems = 0;
                        $customerIds = [];
                        foreach ($paginator->getCollection() as $order) {
                            if (
                                $order->relationLoaded('customer')
                                && $order->relationLoaded('order_items')
                            ) {
                                $loaded++;
                            }
                            $customerIds[] = (int) $order->customer->id;
                            $relatedItems += $order->order_items->count();
                        }

                        return [
                            'returned'      => $paginator->count(),
                            'total'         => $paginator->total(),
                            'loaded'        => $loaded,
                            'related_items' => $relatedItems,
                            'customer_ids'  => array_values(array_unique($customerIds)),
                        ];
                    });
                };

                $createOrders(5);
                $small = $profile(5);
                $createOrders(20);
                $large = $profile(25);

                foreach ([5 => $small, 25 => $large] as $size => $measured) {
                    FcTest::assertSame(
                        $size,
                        $measured['result']['returned'],
                        'Order list returns the exact requested batch of ' . $size
                    );
                    FcTest::assertSame(
                        $size,
                        $measured['result']['total'],
                        'Order list isolates exactly ' . $size . ' owned rows'
                    );
                    FcTest::assertSame(
                        $size,
                        $measured['result']['loaded'],
                        'Order list eager loads both relations for all ' . $size . ' rows'
                    );
                    FcTest::assertSame(
                        $size,
                        $measured['result']['related_items'],
                        'Order list materializes exactly one owned item per Order at ' . $size
                    );
                    FcTest::assertSame(
                        [(int) $customer->id],
                        $measured['result']['customer_ids'],
                        'Order list materializes only the exact owned Customer at ' . $size
                    );
                }

                $assertStableProfile($small, $large, 'Order list');
                $logProfiles('orders', $small, $large);
            } finally {
                FcFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'throughput-customer-list',
        'name'          => 'Customer list eager loads both address sets without query growth at 25 rows',
        'phase'         => 16,
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($assertStableProfile, $logProfiles) {
            try {
                $profile = function ($size) {
                    return FcTest::profileQueries(function () use ($size) {
                        $paginator = \FluentCart\App\Services\Filter\CustomerFilter::make([
                            'search'    => FcThroughputFixture::marker('customer'),
                            'per_page'  => $size,
                            'page'      => 1,
                            'sort_by'   => 'id',
                            'sort_type' => 'ASC',
                            // One screen key carries BOTH address sets in a
                            // single with() call, which is what stops a second
                            // entry array_merging an empty closure over the first.
                            'with'      => ['admin_customer_search'],
                        ])->paginate();

                        $loaded = 0;
                        $addressRows = 0;
                        foreach ($paginator->getCollection() as $customer) {
                            if (
                                $customer->relationLoaded('billing_address')
                                && $customer->relationLoaded('shipping_address')
                            ) {
                                $loaded++;
                            }
                            $addressRows += $customer->billing_address->count();
                            $addressRows += $customer->shipping_address->count();
                        }

                        return [
                            'returned'     => $paginator->count(),
                            'total'        => $paginator->total(),
                            'loaded'       => $loaded,
                            'address_rows' => $addressRows,
                        ];
                    });
                };

                FcThroughputFixture::customers(5);
                $small = $profile(5);
                FcThroughputFixture::customers(20);
                $large = $profile(25);

                foreach ([5 => $small, 25 => $large] as $size => $measured) {
                    FcTest::assertSame(
                        $size,
                        $measured['result']['returned'],
                        'Customer list returns the exact requested batch of ' . $size
                    );
                    FcTest::assertSame(
                        $size,
                        $measured['result']['total'],
                        'Customer list isolates exactly ' . $size . ' owned rows'
                    );
                    FcTest::assertSame(
                        $size,
                        $measured['result']['loaded'],
                        'Customer list eager loads both address relations for all ' . $size . ' rows'
                    );
                    FcTest::assertSame(
                        $size * 2,
                        $measured['result']['address_rows'],
                        'Customer list materializes two exact addresses per row at ' . $size
                    );
                }

                $assertStableProfile($small, $large, 'Customer list');
                $logProfiles('customers', $small, $large);
            } finally {
                FcThroughputFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'throughput-product-variant-list',
        'name'          => 'Product list batches details, variants, and display titles without query growth at 25 rows',
        'phase'         => 16,
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($assertStableProfile, $logProfiles) {
            try {
                $profile = function ($size) {
                    return FcTest::profileQueries(function () use ($size) {
                        $paginator = \FluentCart\App\Services\Filter\ProductFilter::make([
                            'search'      => FcThroughputFixture::marker('product'),
                            'per_page'    => $size,
                            'page'        => 1,
                            'sort_by'     => 'ID',
                            'sort_type'   => 'ASC',
                            'active_view' => 'publish',
                            // A screen key, not a relation name. This is the
                            // add-product-modal payload — the only one that
                            // loads the detail.variants subtree that
                            // attachVariationDisplayTitles() walks.
                            'with'        => ['admin_order_add_product'],
                        ])->paginate();

                        $products = $paginator->getCollection();
                        \FluentCart\App\Helpers\AttributeHelper::attachVariationDisplayTitles(
                            $products
                        );

                        $loaded = 0;
                        $variantRows = 0;
                        $displayTitles = 0;
                        foreach ($products as $product) {
                            $detail = $product->detail;
                            if (
                                $product->relationLoaded('detail')
                                && $detail
                                && $detail->relationLoaded('variants')
                            ) {
                                $loaded++;
                            }
                            if (!$detail) {
                                continue;
                            }
                            foreach ($detail->variants as $variant) {
                                $variantRows++;
                                if (
                                    isset($variant->variation_display_title)
                                    && (string) $variant->variation_display_title
                                        === (string) $variant->variation_title
                                ) {
                                    $displayTitles++;
                                }
                            }
                        }

                        return [
                            'returned'       => $paginator->count(),
                            'total'          => $paginator->total(),
                            'loaded'         => $loaded,
                            'variant_rows'   => $variantRows,
                            'display_titles' => $displayTitles,
                        ];
                    });
                };

                FcThroughputFixture::products(5);
                $small = $profile(5);
                FcThroughputFixture::products(20);
                $large = $profile(25);

                foreach ([5 => $small, 25 => $large] as $size => $measured) {
                    FcTest::assertSame(
                        $size,
                        $measured['result']['returned'],
                        'Product list returns the exact requested batch of ' . $size
                    );
                    FcTest::assertSame(
                        $size,
                        $measured['result']['total'],
                        'Product list isolates exactly ' . $size . ' owned rows'
                    );
                    FcTest::assertSame(
                        $size,
                        $measured['result']['loaded'],
                        'Product list eager loads detail.variants for all ' . $size . ' rows'
                    );
                    FcTest::assertSame(
                        $size,
                        $measured['result']['variant_rows'],
                        'Product list materializes exactly one owned Variant per Product at ' . $size
                    );
                    FcTest::assertSame(
                        $size,
                        $measured['result']['display_titles'],
                        'Product list batched display-title resolver covers all ' . $size . ' Variants'
                    );
                }

                $assertStableProfile($small, $large, 'Product/Variant list');
                $logProfiles('products/variants', $small, $large);
            } finally {
                FcThroughputFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'throughput-activity-feed',
        'name'          => 'Activity feed eager loads actors without query growth at 25 rows',
        'phase'         => 16,
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($assertStableProfile, $logProfiles) {
            try {
                $userId = get_current_user_id();
                FcTest::assert(
                    $userId > 0 && get_user_by('ID', $userId),
                    'Activity throughput fixture reuses the authenticated existing admin read-only'
                );

                $profile = function ($size) {
                    return FcTest::profileQueries(function () use ($size) {
                        $paginator = \FluentCart\App\Services\Filter\LogFilter::make([
                            'search'    => FcThroughputFixture::marker('activity'),
                            'per_page'  => $size,
                            'page'      => 1,
                            'sort_by'   => 'id',
                            'sort_type' => 'ASC',
                            // The public entry point, and this guard is the
                            // reason LogFilter still declares the actor entry at
                            // all — no admin screen sends a `with` to the feed.
                            'with'      => ['user'],
                        ])->paginate();

                        $loaded = 0;
                        $actorIds = [];
                        foreach ($paginator->getCollection() as $activity) {
                            if ($activity->relationLoaded('user')) {
                                $loaded++;
                            }
                            $actorIds[] = (int) $activity->user->ID;
                        }

                        return [
                            'returned'  => $paginator->count(),
                            'total'     => $paginator->total(),
                            'loaded'    => $loaded,
                            'actor_ids' => array_values(array_unique($actorIds)),
                        ];
                    });
                };

                FcThroughputFixture::activities(5, $userId);
                $small = $profile(5);
                FcThroughputFixture::activities(20, $userId);
                $large = $profile(25);

                foreach ([5 => $small, 25 => $large] as $size => $measured) {
                    FcTest::assertSame(
                        $size,
                        $measured['result']['returned'],
                        'Activity feed returns the exact requested batch of ' . $size
                    );
                    FcTest::assertSame(
                        $size,
                        $measured['result']['total'],
                        'Activity feed isolates exactly ' . $size . ' owned rows'
                    );
                    FcTest::assertSame(
                        $size,
                        $measured['result']['loaded'],
                        'Activity feed eager loads the actor for all ' . $size . ' rows'
                    );
                    FcTest::assertSame(
                        [$userId],
                        $measured['result']['actor_ids'],
                        'Activity feed materializes only the authenticated existing admin at ' . $size
                    );
                }

                $assertStableProfile($small, $large, 'Activity feed');
                $logProfiles('activities', $small, $large);
            } finally {
                FcThroughputFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'throughput-report-aggregates',
        'name'          => 'Order report aggregates keep one query and exact totals at 25 rows',
        'phase'         => 16,
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($assertStableProfile, $logProfiles) {
            try {
                FcFixture::assertReportRangesEmpty();
                FcFixture::customer([
                    'first_name' => 'Phase Sixteen',
                    'last_name'  => 'Reports',
                ]);

                $nextSequence = 0;
                $createRows = function ($count) use (&$nextSequence) {
                    for ($index = 1; $index <= $count; $index++) {
                        $nextSequence++;
                        $createdAt = '2001-02-04 12:00:' . sprintf('%02d', $nextSequence);
                        $order = FcFixture::reportOrder([
                            'status'               => 'completed',
                            'payment_status'       => 'paid',
                            'payment_method'       => 'phase16-report',
                            'payment_method_title' => 'Phase 16 Report',
                            'currency'             => 'USD',
                            'subtotal'             => 1000,
                            'shipping_tax'         => 0,
                            'shipping_total'       => 0,
                            'tax_total'            => 0,
                            'total_amount'         => 1000,
                            'total_paid'           => 1000,
                            'total_refund'         => 0,
                            'completed_at'         => $createdAt,
                            'created_at'           => $createdAt,
                            'updated_at'           => $createdAt,
                            'type'                 => 'payment',
                            'mode'                 => 'test',
                        ]);
                        FcFixture::reportOrderItem((int) $order->id, [
                            'quantity'   => 1,
                            'unit_price' => 1000,
                            'subtotal'   => 1000,
                            'line_total' => 1000,
                            'created_at' => $createdAt,
                        ]);
                    }
                };
                $profile = function () {
                    return FcTest::profileQueries(function () {
                        $params = FcReportFixture::params('daily');
                        return \FluentCart\App\Services\Report\OrderReportService::make()
                            ->getOrderLineChart($params);
                    });
                };

                $createRows(5);
                $small = $profile();
                $createRows(20);
                $large = $profile();

                foreach ([5 => $small, 25 => $large] as $size => $measured) {
                    $summary = $measured['result']['summary'];
                    FcTest::assertSame(
                        $size,
                        (int) $summary['order_count'],
                        'Order report aggregate counts exactly ' . $size . ' owned Orders'
                    );
                    FcTest::assertSame(
                        $size,
                        (int) $summary['total_item_count'],
                        'Order report aggregate counts exactly ' . $size . ' owned items'
                    );
                    FcTest::assertSame(
                        (float) ($size * 10),
                        (float) $summary['gross_sale'],
                        'Order report aggregate returns exact gross value at ' . $size . ' rows'
                    );
                    FcTest::assertSame(
                        (float) ($size * 10),
                        (float) $summary['net_revenue'],
                        'Order report aggregate returns exact net value at ' . $size . ' rows'
                    );
                }

                $assertStableProfile($small, $large, 'Order report aggregate');
                $logProfiles('report aggregate', $small, $large);
            } finally {
                FcFixture::cleanupAll();
            }
        },
    ],
];

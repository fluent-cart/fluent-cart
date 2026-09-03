<?php
/**
 * Source-pinned Phase 16 throughput targets.
 *
 * Each list profile materializes the named relation(s) inside the query-count
 * boundary. The report profile asserts aggregate values and query count only.
 */

return [
    'small_size' => 5,
    'large_size' => 25,
    'profiles'   => [
        [
            'id'        => 'orders',
            'kind'      => 'list',
            'coverage'  => 'throughput-order-list',
            'relations' => ['customer', 'order_items'],
            'source_checks' => [
                [
                    'file'   => 'app/Http/Controllers/OrderController.php',
                    'line'   => 60,
                    'needle' => 'OrderFilter::fromRequest($request)->paginate()',
                ],
                [
                    'file'   => 'app/Models/Order.php',
                    'line'   => 210,
                    'needle' => 'function order_items()',
                ],
            ],
        ],
        [
            'id'        => 'customers',
            'kind'      => 'list',
            'coverage'  => 'throughput-customer-list',
            'relations' => ['billing_address', 'shipping_address'],
            'source_checks' => [
                [
                    'file'   => 'app/Http/Controllers/CustomerController.php',
                    'line'   => 26,
                    'needle' => 'CustomerFilter::fromRequest($request)->paginate()',
                ],
                [
                    'file'   => 'app/Models/Customer.php',
                    'line'   => 119,
                    'needle' => 'function shipping_address()',
                ],
                [
                    'file'   => 'app/Models/Customer.php',
                    'line'   => 124,
                    'needle' => 'function billing_address()',
                ],
            ],
        ],
        [
            'id'        => 'products_variants',
            'kind'      => 'list',
            'coverage'  => 'throughput-product-variant-list',
            'relations' => ['detail.variants'],
            'source_checks' => [
                [
                    'file'   => 'app/Http/Controllers/ProductController.php',
                    'line'   => 48,
                    'needle' => 'ProductFilter::fromRequest($request)->paginate()',
                ],
                [
                    'file'   => 'app/Http/Controllers/ProductController.php',
                    'line'   => 52,
                    'needle' => 'AttributeHelper::attachVariationDisplayTitles',
                ],
                [
                    'file'   => 'app/Helpers/AttributeHelper.php',
                    'line'   => 237,
                    'needle' => 'self::getProductItemsAttributes',
                ],
            ],
        ],
        [
            'id'        => 'activity',
            'kind'      => 'list',
            'coverage'  => 'throughput-activity-feed',
            'relations' => ['user'],
            'source_checks' => [
                [
                    'file'   => 'app/Http/Controllers/ActivityController.php',
                    'line'   => 14,
                    'needle' => 'LogFilter::fromRequest($request)',
                ],
                [
                    'file'   => 'app/Models/Activity.php',
                    'line'   => 44,
                    'needle' => 'function user()',
                ],
            ],
        ],
        [
            'id'        => 'order_report',
            'kind'      => 'aggregate',
            'coverage'  => 'throughput-report-aggregates',
            'relations' => [],
            'source_checks' => [
                [
                    'file'   => 'app/Services/Report/OrderReportService.php',
                    'line'   => 96,
                    'needle' => '$orderQuery->selectRaw',
                ],
                [
                    'file'   => 'app/Services/Report/OrderReportService.php',
                    'line'   => 107,
                    'needle' => 'COUNT(o.id) AS order_count',
                ],
                [
                    'file'   => 'app/Services/Report/OrderReportService.php',
                    'line'   => 130,
                    'needle' => 'SUM(COALESCE(oi_sum.total_items, 0))',
                ],
            ],
        ],
    ],
];

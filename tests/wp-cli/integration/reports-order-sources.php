<?php
/**
 * Order Sources report: group-by options, totals, the "(none)" row, lifetime value,
 * merged spellings, product shares, referrer domains and the advanced-filter hook.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\SourceReportService;

// No store row uses the ISO test currency, so the currency-scoped report reads only these fixtures.
$fcSrcCurrency = 'XTS';

$fcSrcGuard = function () use ($fcSrcCurrency) {
    $foreign = (int) App::db()->table('fct_orders')->where('currency', $fcSrcCurrency)->count();
    if ($foreign !== 0) {
        throw new RuntimeException('Refusing to seed: ' . $foreign . ' orders already use currency ' . $fcSrcCurrency);
    }
    FcFixture::customer();
};

$fcSrcOrder = function (array $attributes = [], $utm = null) use ($fcSrcCurrency) {
    $window = FcFixture::reportWindow();
    $order = FcFixture::reportOrder(array_merge([
        'status'         => Status::ORDER_COMPLETED,
        'payment_status' => Status::PAYMENT_PAID,
        'currency'       => $fcSrcCurrency,
        'type'           => Status::ORDER_TYPE_PAYMENT,
        'subtotal'       => 1000,
        'tax_total'      => 0,
        'shipping_tax'   => 0,
        'total_amount'   => 1000,
        'total_paid'     => 1000,
        'total_refund'   => 0,
        'created_at'     => $window['start'],
        'updated_at'     => $window['start'],
    ], $attributes));

    if ($utm !== null) {
        FcFixture::reportOrderOperation($order->id, array_merge(
            ['utm_campaign' => '', 'utm_source' => '', 'utm_medium' => '', 'utm_term' => '', 'utm_content' => '', 'utm_id' => ''],
            $utm
        ));
    }

    return $order;
};

$fcSrcReport = function (array $extra = []) use ($fcSrcCurrency) {
    $window = FcFixture::reportWindow();

    return SourceReportService::make()->getReport(array_merge([
        'startDate'     => new DateTime($window['start']),
        'endDate'       => new DateTime($window['end']),
        'currency'      => $fcSrcCurrency,
        'paymentStatus' => Status::getReportStatuses(),
        'orderStatus'   => [Status::ORDER_ON_HOLD, Status::ORDER_FAILED],
        'orderTypes'    => null,
        'variationIds'  => [],
        'comparePeriod' => null,
    ], $extra));
};

$fcSrcPick = function (array $rows, array $keys) {
    return array_map(function ($row) use ($keys) {
        $picked = [];
        foreach ($keys as $key) {
            $picked[] = $row[$key];
        }

        return $picked;
    }, $rows);
};

return [
    [
        'id'            => 'reports-order-sources-groupings',
        'name'          => 'Order Sources groups by campaign or term with merged spellings, totals and a (none) row',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcSrcGuard, $fcSrcOrder, $fcSrcReport, $fcSrcPick) {
            try {
                FcFixture::assertReportRangesEmpty();
                $fcSrcGuard();
                // One campaign written three ways; two terms
                $fcSrcOrder([], ['utm_campaign' => 'EA_Reminder', 'utm_source' => 'newsletter', 'utm_term' => 'reminder']);
                $fcSrcOrder([], ['utm_campaign' => 'EA_Reminder', 'utm_source' => 'newsletter', 'utm_term' => 'reminder']);
                $fcSrcOrder(['total_refund' => 400, 'payment_status' => Status::PAYMENT_PARTIALLY_REFUNDED],
                    ['utm_campaign' => 'EA Reminder', 'utm_source' => 'newsletter', 'utm_term' => 'Feature reminder']);
                $fcSrcOrder([], ['utm_campaign' => 'ea-reminder', 'utm_source' => 'Newsletter', 'utm_term' => '']);
                // No UTM data at all
                $fcSrcOrder(['total_amount' => 500, 'total_paid' => 500]);

                $campaign = $fcSrcReport(['group_by' => 'campaign']);
                FcTest::assertSame(
                    [['EA_Reminder', 4, 40.0, 4.0, 36.0, 25.0]],
                    $fcSrcPick($campaign['rows'], ['utm_campaign', 'orders', 'gross_sales', 'refunds', 'net_sales', 'refund_rate']),
                    'Three spellings merge into one campaign, shown as the most common spelling; terms are not in the row'
                );
                FcTest::assertSame(false, array_key_exists('utm_term', $campaign['rows'][0]), 'Only the grouped field is returned');
                FcTest::assertSame(
                    [4, 40.0, 4.0, 36.0, 10.0, 25.0],
                    [$campaign['totals']['orders'], $campaign['totals']['gross_sales'], $campaign['totals']['refunds'],
                        $campaign['totals']['net_sales'], $campaign['totals']['average_order'], $campaign['totals']['refund_rate']],
                    'Totals cover the filtered set'
                );

                $term = $fcSrcReport(['group_by' => 'term']);
                FcTest::assertSame(
                    // Equal gross sorts by key, so the empty term comes before "feature reminder"
                    [['reminder', 2], ['', 1], ['Feature reminder', 1]],
                    $fcSrcPick($term['rows'], ['utm_term', 'orders']),
                    'Grouping by term gives one row per term; an empty term is its own (not set) row'
                );

                $withNone = $fcSrcReport(['group_by' => 'campaign', 'include_none' => true]);
                $none = array_values(array_filter($withNone['rows'], function ($row) {
                    return !empty($row['is_none']);
                }));
                FcTest::assertSame(
                    [1, 5.0, 5, 41.0],
                    [$none[0]['orders'], $none[0]['gross_sales'], $withNone['totals']['orders'], $withNone['totals']['net_sales']],
                    'The (none) row holds the order without UTM data, and the totals add up to all orders'
                );

                $default = $fcSrcReport([]);
                FcTest::assertSame(
                    ['utm_campaign', 'utm_source', 'utm_medium'],
                    array_values(array_intersect(array_keys($default['rows'][0]), ['utm_campaign', 'utm_source', 'utm_medium', 'utm_term'])),
                    'Without group_by the API keeps campaign / source / medium'
                );
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-order-sources-lifetime',
        'name'          => 'Order Sources credits a campaign with the later payments of its first orders',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcSrcGuard, $fcSrcOrder, $fcSrcReport) {
            try {
                FcFixture::assertReportRangesEmpty();
                $fcSrcGuard();
                $first = $fcSrcOrder(['type' => Status::ORDER_TYPE_SUBSCRIPTION], ['utm_campaign' => 'halloween', 'utm_source' => 'newsletter']);
                // Two renewals with no UTM data, one partly refunded; one unpaid that must not count
                $fcSrcOrder(['type' => Status::ORDER_TYPE_RENEWAL, 'parent_id' => (int) $first->id]);
                $fcSrcOrder(['type' => Status::ORDER_TYPE_RENEWAL, 'parent_id' => (int) $first->id, 'total_refund' => 300, 'payment_status' => Status::PAYMENT_PARTIALLY_REFUNDED]);
                $fcSrcOrder(['type' => Status::ORDER_TYPE_RENEWAL, 'parent_id' => (int) $first->id, 'payment_status' => 'pending', 'total_paid' => 0]);

                $row = $fcSrcReport(['group_by' => 'campaign'])['rows'][0];
                FcTest::assertSame(
                    [1, 10.0, 10.0, 17.0, 27.0],
                    [$row['orders'], $row['net_sales'], $row['first_order_net'], $row['later_net'], $row['lifetime_net']],
                    'Lifetime = first order 10.00 + renewals 10.00 + 7.00'
                );
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-order-sources-product-month-referrer',
        'name'          => 'Order Sources by variation shares an order across its lines; by month and by referrer domain',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcSrcGuard, $fcSrcOrder, $fcSrcReport, $fcSrcPick) {
            try {
                FcFixture::assertReportRangesEmpty();
                $fcSrcGuard();
                $base = 1920000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 10;
                $window = FcFixture::reportWindow();
                // Two months: a wider range than the shared three-day window; XTS keeps it to these fixtures
                $later = '2001-03-15 10:00:00';
                $range = ['startDate' => new DateTime('2001-01-01 00:00:00'), 'endDate' => new DateTime('2001-04-30 23:59:59')];

                $twoLines = $fcSrcOrder(['subtotal' => 4000, 'total_amount' => 4000, 'total_paid' => 4000],
                    ['utm_source' => 'google', 'refer_url' => 'https://www.google.com/search?q=cart']);
                FcFixture::reportOrderItem($twoLines->id, ['post_id' => $base, 'object_id' => $base + 1, 'unit_price' => 3000, 'subtotal' => 3000, 'line_total' => 3000]);
                FcFixture::reportOrderItem($twoLines->id, ['post_id' => $base, 'object_id' => $base + 2, 'unit_price' => 1000, 'subtotal' => 1000, 'line_total' => 1000]);
                $oneLine = $fcSrcOrder(['created_at' => $later], ['utm_source' => 'partner', 'refer_url' => 'http://www.example.com/blog/post?ref=1']);
                FcFixture::reportOrderItem($oneLine->id, ['post_id' => $base, 'object_id' => $base + 2, 'unit_price' => 1000, 'subtotal' => 1000, 'line_total' => 1000]);
                $fcSrcOrder(['created_at' => $later], ['utm_source' => 'google', 'refer_url' => 'https://accounts.google.com/o/oauth2']);

                $variation = $fcSrcReport(['group_by' => 'variation'] + $range);
                $byId = [];
                foreach ($variation['rows'] as $row) {
                    $byId[$row['group_id']] = [$row['orders'], $row['gross_sales']];
                }
                FcTest::assertSame(
                    [[1, 30.0], [2, 20.0]],
                    [$byId[$base + 1], $byId[$base + 2]],
                    'The 40.00 order is shared 30.00 / 10.00 by line amount'
                );
                FcTest::assertSame([3, 60.0], [$variation['totals']['orders'], $variation['totals']['gross_sales']], 'Totals count each order once');

                $month = $fcSrcReport(['group_by' => 'month'] + $range);
                FcTest::assertSame(
                    [[substr($window['start'], 0, 7), 1], [substr($later, 0, 7), 2]],
                    $fcSrcPick($month['rows'], ['group_key', 'orders']),
                    'Months in time order'
                );

                $referrer = $fcSrcReport(['group_by' => 'referrer'] + $range);
                $labels = $fcSrcPick($referrer['rows'], ['label', 'orders']);
                sort($labels);
                FcTest::assertSame(
                    [['(not set)', 1], ['Organic search', 1], ['example.com', 1]],
                    $labels,
                    'Search engines group as Organic search, www. is dropped, Google sign-in pages are ignored'
                );
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-order-sources-filter-hook',
        'name'          => 'The advanced-filter hook narrows the rows, the totals and the (none) row alike',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcSrcGuard, $fcSrcOrder, $fcSrcReport) {
            $callback = null;
            try {
                FcFixture::assertReportRangesEmpty();
                $fcSrcGuard();
                $kept = $fcSrcOrder([], ['utm_campaign' => 'bfcm25', 'utm_source' => 'newsletter']);
                $fcSrcOrder([], ['utm_campaign' => 'halloween', 'utm_source' => 'newsletter']);
                $fcSrcOrder(['total_amount' => 500, 'total_paid' => 500]);

                // What Pro does: constrain the order ids
                $callback = function ($query) use ($kept) {
                    return $query->whereIn('o.id', [(int) $kept->id]);
                };
                add_filter('fluent_cart/report/sources_query', $callback, 10, 2);

                $report = $fcSrcReport(['group_by' => 'campaign', 'include_none' => true]);
                FcTest::assertSame(
                    [['bfcm25', 1]],
                    array_map(function ($row) {
                        return [$row['utm_campaign'], $row['orders']];
                    }, $report['rows']),
                    'Only the kept order remains, and no (none) row appears'
                );
                FcTest::assertSame(1, $report['totals']['orders'], 'Totals follow the filter');
            } finally {
                if ($callback) {
                    remove_filter('fluent_cart/report/sources_query', $callback, 10);
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];

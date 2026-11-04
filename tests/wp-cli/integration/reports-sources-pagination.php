<?php
/**
 * Order Sources pages on the server: a page of rows sorted and searched over the whole list, the total count, the
 * server totals for the whole set, and comparison values for that page only. Without a page, every row comes back.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcSourcePagesCurrency = 'XTS';

return [
    [
        'id'            => 'reports-sources-pagination',
        'name'          => 'Order Sources pages, sorts and searches on the server; without a page it returns every row',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC',
        'run'           => function () use ($fcSourcePagesCurrency) {
            $db = App::db();
            try {
                $foreign = (int) $db->table('fct_orders')->where('currency', $fcSourcePagesCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' rows already use currency ' . $fcSourcePagesCurrency);
                }
                FcFixture::customer();
                // Twelve campaigns: campaign N has one order of N.00, so their order by sales is known
                foreach (range(1, 12) as $n) {
                    $at = '2001-03-' . sprintf('%02d', $n) . ' 10:00:00';
                    $row = FcFixture::reportOrder([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcSourcePagesCurrency,
                        'type' => Status::ORDER_TYPE_PAYMENT, 'subtotal' => $n * 100, 'tax_total' => 0, 'total_amount' => $n * 100,
                        'total_paid' => $n * 100, 'created_at' => $at, 'updated_at' => $at,
                    ]);
                    FcFixture::reportOrderOperation($row->id, ['utm_campaign' => 'camp' . sprintf('%02d', $n), 'utm_source' => 'news',
                        'utm_medium' => 'email', 'utm_term' => '', 'utm_content' => '', 'utm_id' => '', 'refer_url' => '']);
                }

                $request = function (array $extra) use ($fcSourcePagesCurrency) {
                    $response = FcTest::rest('GET', 'reports/sources', ['params' => array_merge([
                        'startDate' => '2001-03-01 00:00:00', 'endDate' => '2001-03-31 23:59:59', 'currency' => $fcSourcePagesCurrency,
                        'group_by' => 'utm_campaign', 'compareType' => 'previous_period', 'compareDate' => '2001-03-31 23:59:59',
                    ], $extra)]);

                    return $response['data'] ?? [];
                };
                $campaigns = function ($data) {
                    return array_column($data['sourceReportData'] ?? [], 'utm_campaign');
                };

                $first = $request(['page' => 1, 'per_page' => 10]);
                FcTest::assertSame([10, 12, 1, 10, 78.0], [count($first['sourceReportData']), $first['pagination']['total'],
                    $first['pagination']['current_page'], $first['pagination']['per_page'], (float) $first['totals']['gross_sales']],
                    'Page 1 holds 10 of 12 rows; the totals cover all 12');
                FcTest::assertSame(array_column($first['sourceReportData'], 'utm_key'), array_keys($first['fluctuations']),
                    'Comparison values only for the rows on the page');

                $second = $request(['page' => 2, 'per_page' => 10, 'sort_by' => 'gross_sales', 'sort_order' => 'asc']);
                FcTest::assertSame(['camp11', 'camp12'], $campaigns($second), 'Sorted over the whole list before paging');

                $search = $request(['page' => 1, 'per_page' => 10, 'search' => 'CAMP1']);
                FcTest::assertSame([['camp12', 'camp11', 'camp10'], 3], [$campaigns($search), $search['pagination']['total']],
                    'Search runs on the server, over every row, in any letter case');

                $clamped = $request(['page' => 1, 'per_page' => 5000]);
                FcTest::assertSame(150, $clamped['pagination']['per_page'], 'At most 150 rows a page');

                // The Products filter: only orders that hold the picked variation
                $variation = 1990000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000);
                $picked = (int) $db->table('fct_orders')->where('currency', $fcSourcePagesCurrency)->orderBy('id')->value('id');
                FcFixture::reportOrderItem($picked, ['post_id' => $variation, 'object_id' => $variation, 'unit_price' => 100, 'subtotal' => 100, 'line_total' => 100]);
                $filtered = $request(['page' => 1, 'per_page' => 10, 'variation_ids' => [$variation]]);
                FcTest::assertSame([['camp01'], 1], [$campaigns($filtered), (int) $filtered['totals']['orders']], 'The Products filter narrows the rows and the totals');

                $all = $request([]);
                FcTest::assertSame([12, false], [count($all['sourceReportData']), isset($all['pagination'])],
                    'Without a page, every row comes back as before');

                // The export asks without a page: the table search still applies
                $exported = $request(['search' => 'CAMP1']);
                FcTest::assertSame([['camp12', 'camp11', 'camp10'], false], [$campaigns($exported), isset($exported['pagination'])],
                    'An export after a search holds only the matching rows');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];

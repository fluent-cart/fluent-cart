<?php
/**
 * Order Sources: the UTM groupings are free; grouping by product, variation, day, week, month or referrer is a
 * FluentCart Pro report. Without Pro the route serves the default grouping and says so.
 */

return [
    [
        'id'            => 'reports-sources-pro-groupings',
        'name'          => 'Order Sources serves the product, time and referrer groupings with Pro only',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            $pro = (bool) has_filter('fluent_cart/report/source_groupings');
            $served = function ($groupBy) {
                // No store row uses the ISO test currency: an empty report is enough
                $route = FcTest::rest('GET', 'reports/sources', ['params' => ['currency' => 'XTS', 'group_by' => $groupBy]]);

                return [$route['status'], $route['data']['group_by'] ?? null];
            };

            FcTest::assertSame([200, 'source_medium'], $served('source_medium'), 'A UTM grouping is free');
            foreach (['product', 'variation', 'day', 'referrer'] as $groupBy) {
                FcTest::assertSame([200, $pro ? $groupBy : \FluentCart\App\Services\Report\SourceReportService::DEFAULT_GROUP], $served($groupBy),
                    $groupBy . ': served with Pro, the default grouping without');
            }
        },
    ],
];

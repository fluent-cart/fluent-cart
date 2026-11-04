<?php
/**
 * The Overview's lighter requests: the subscriptions overview in summary mode gives the same period figures without
 * the plan, interval and trial breakdowns; subscription health and the 12-month chart are cached for a few minutes
 * only when the request asks for it, so the Subscriptions page stays live.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcOverviewLoadingCurrency = 'XTS';

return [
    [
        'id'            => 'reports-overview-loading',
        'name'          => 'Overview loading: summary-only churn request, opt-in cache for subscription health and the 12-month chart',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC',
        'run'           => function () use ($fcOverviewLoadingCurrency) {
            global $wpdb;
            $clearCache = function () use ($wpdb) {
                $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_fct\\_report\\_%' OR option_name LIKE '\\_transient\\_timeout\\_fct\\_report\\_%'");
                wp_cache_flush();
            };
            $clearCache();
            try {
                $foreign = (int) App::db()->table('fct_orders')->where('currency', $fcOverviewLoadingCurrency)->count()
                    + (int) App::db()->table('fct_subscriptions')->where('currency', $fcOverviewLoadingCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' rows already use currency ' . $fcOverviewLoadingCurrency);
                }
                $range = ['startDate' => gmdate('Y-m-d 00:00:00', strtotime('-30 days')), 'endDate' => gmdate('Y-m-d 23:59:59'),
                    'currency' => $fcOverviewLoadingCurrency, 'compareType' => 'previous_period', 'compareDate' => gmdate('Y-m-d 23:59:59')];

                $full = FcTest::rest('GET', 'reports/subscriptions-overview', ['params' => $range])['data'] ?? [];
                $summary = FcTest::rest('GET', 'reports/subscriptions-overview', ['params' => $range + ['summary_only' => 1]])['data'] ?? [];
                FcTest::assertSame([$full['period'] ?? 'missing', $full['previousPeriod'] ?? 'missing', false, false],
                    [$summary['period'] ?? null, $summary['previousPeriod'] ?? null, isset($summary['mrr_by_plan']), isset($summary['trials'])],
                    'Summary mode: the same period figures and compared period, without the breakdowns');

                $health = function ($cached) use ($range) {
                    $params = $range + ($cached ? ['cached' => 1] : []);

                    return (int) (FcTest::rest('GET', 'reports/subscription-health', ['params' => $params])['data']['health']['failing']['count'] ?? -1);
                };
                $before = $health(true);

                // A failing subscription appears after the cached read
                FcFixture::customer();
                $parent = FcFixture::reportOrder([
                    'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcOverviewLoadingCurrency,
                    'type' => Status::ORDER_TYPE_SUBSCRIPTION, 'subtotal' => 1200, 'tax_total' => 0, 'total_amount' => 1200, 'total_paid' => 1200,
                    'created_at' => gmdate('Y-m-d H:i:s', strtotime('-10 days')), 'updated_at' => gmdate('Y-m-d H:i:s', strtotime('-10 days')),
                ]);
                $productId = 1910000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 2;
                FcDomainFixture::subscription($parent, $productId, $productId + 1, Status::SUBSCRIPTION_FAILING, 'overview-loading', [
                    'currency' => $fcOverviewLoadingCurrency, 'billing_interval' => 'monthly', 'recurring_amount' => 1200,
                    'recurring_total' => 1200, 'bill_times' => 0, 'current_payment_method' => 'stripe',
                ]);

                FcTest::assertSame([$before, $before + 1], [$health(true), $health(false)],
                    'Cached on request: the Overview\'s read stays put for a few minutes; a plain read is live');

                $yearly = FcTest::rest('GET', 'reports/overview', ['params' => ['currency' => $fcOverviewLoadingCurrency, 'cached' => 1, 'net_sales_only' => 1]]);
                FcTest::assertSame([200, 24, false], [$yearly['status'], count($yearly['data']['data']['net_sales_months'] ?? []),
                    isset($yearly['data']['data']['gross_revenue'])],
                    'The 12-month chart: only its net sales, and from a short cache when asked');
            } finally {
                $clearCache();
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];

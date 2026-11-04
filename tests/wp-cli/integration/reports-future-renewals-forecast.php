<?php
/**
 * Future Renewals forecast: every live recurring subscription counts (a stale expire_at does not drop it), the product
 * filter applies, scheduled amounts split per billing interval with the store's renewal rate per interval from the
 * last 12 closed months, a free trial's first charge apart, and overdue, retrying and won't-renew figures.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\Report\SubscriptionReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcForecastCurrency = 'XTS';

return [
    [
        'id'            => 'reports-future-renewals-forecast',
        'name'          => 'Future Renewals forecast: all live plans, product filter, rates per interval, trial, overdue, retry, won\'t renew',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC',
        'run'           => function () use ($fcForecastCurrency) {
            try {
                $db = App::db();
                $foreign = (int) $db->table('fct_orders')->where('currency', $fcForecastCurrency)->count()
                    + (int) $db->table('fct_subscriptions')->where('currency', $fcForecastCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' rows already use currency ' . $fcForecastCurrency);
                }
                FcFixture::customer();
                $base = 1690000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 20;
                $n = 0;
                // From the 1st of this month (the as-of day): the horizon is then three whole months, whatever today is
                $asOf = gmdate('Y-m-01');
                $day = function ($days) use ($asOf) {
                    return gmdate('Y-m-d H:i:s', strtotime($asOf . ' 12:00:00') + $days * DAY_IN_SECONDS);
                };
                $order = function (array $attributes) use ($fcForecastCurrency) {
                    return FcFixture::reportOrder(array_merge([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcForecastCurrency,
                        'type' => Status::ORDER_TYPE_SUBSCRIPTION, 'subtotal' => 1000, 'total_amount' => 1000, 'total_paid' => 1000,
                    ], $attributes));
                };
                $sub = function ($status, $createdAt, $interval, $cents, array $extra = []) use (&$n, $base, $fcForecastCurrency, $db, $order) {
                    $n++;
                    $parent = $order(['created_at' => $createdAt, 'updated_at' => $createdAt]);
                    $row = FcDomainFixture::subscription($parent, $base + $n * 2, $base + $n * 2 + 1, $status, 'forecast-' . $n,
                        array_merge(['currency' => $fcForecastCurrency, 'canceled_at' => null, 'billing_interval' => $interval,
                            'recurring_amount' => $cents, 'recurring_total' => $cents, 'expire_at' => null], $extra));
                    $db->table('fct_subscriptions')->where('id', (int) $row->id)->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);

                    return [$parent, $base + $n * 2 + 1];
                };

                // A yearly plan whose expire_at is two years stale: still live, still renews
                $sub(Status::SUBSCRIPTION_ACTIVE, $day(-200), 'yearly', 12000, ['next_billing_date' => $day(30), 'expire_at' => $day(-730)]);
                [, $monthlyVariation] = $sub(Status::SUBSCRIPTION_ACTIVE, $day(-20), 'monthly', 1000, ['next_billing_date' => $day(10)]);
                // Behind on its renewal: overdue, and its later renewals still forecast
                $sub(Status::SUBSCRIPTION_ACTIVE, $day(-35), 'monthly', 500, ['next_billing_date' => $day(-5)]);
                // In payment retry
                $sub(Status::SUBSCRIPTION_PAST_DUE, $day(-40), 'monthly', 700, ['next_billing_date' => $day(-8)]);
                // Canceled, paid until 20 days from now: a renewal that will not happen
                $sub(Status::SUBSCRIPTION_CANCELED, $day(-10), 'monthly', 900, ['canceled_at' => $day(-2), 'next_billing_date' => $day(20),
                    'config' => ['cancellation_reason' => 'cancelled_by_customer']]);
                // A free trial ending in 7 days: its first charge is a conversion, not a renewal
                $sub(Status::SUBSCRIPTION_TRIALING, $day(-7), 'monthly', 2000, ['next_billing_date' => $day(7), 'trial_days' => 14]);
                // Trial history, dated by outcome: one paid in the window, one ended unpaid in the window, one started in
                // the window but paid after it (this month): only the first two count
                [$paidTrial] = $sub(Status::SUBSCRIPTION_ACTIVE, $day(-100), 'monthly', 1000, ['next_billing_date' => $day(20), 'trial_days' => 14]);
                $order(['type' => Status::ORDER_TYPE_RENEWAL, 'parent_id' => (int) $paidTrial->id, 'created_at' => $day(-86), 'updated_at' => $day(-86), 'completed_at' => $day(-86)]);
                $sub(Status::SUBSCRIPTION_CANCELED, $day(-60), 'monthly', 1000, ['next_billing_date' => null, 'trial_days' => 14, 'canceled_at' => $day(-50),
                    'config' => ['cancellation_reason' => 'cancelled_by_customer']]);
                [$lateTrial] = $sub(Status::SUBSCRIPTION_ACTIVE, $day(-5), 'monthly', 1000, ['next_billing_date' => $day(25), 'trial_days' => 7]);
                $order(['type' => Status::ORDER_TYPE_RENEWAL, 'parent_id' => (int) $lateTrial->id, 'created_at' => $day(2), 'updated_at' => $day(2), 'completed_at' => $day(2)]);
                // History: a monthly plan renewed twice in the window, one lost (canceled, its month ended 5 months ago)
                [$renewing] = $sub(Status::SUBSCRIPTION_ACTIVE, $day(-150), 'monthly', 1000, ['next_billing_date' => $day(15)]);
                foreach ([-120, -90] as $ago) {
                    $order(['type' => Status::ORDER_TYPE_RENEWAL, 'parent_id' => (int) $renewing->id, 'created_at' => $day($ago),
                        'updated_at' => $day($ago), 'completed_at' => $day($ago)]);
                }
                $sub(Status::SUBSCRIPTION_CANCELED, $day(-180), 'monthly', 1000, ['canceled_at' => $day(-170), 'next_billing_date' => null,
                    'config' => ['cancellation_reason' => 'cancelled_by_customer']]);

                $service = SubscriptionReportService::make();
                $r = $service->getFutureRenewals(['currency' => $fcForecastCurrency, 'variationIds' => [], 'horizon' => '3', 'asOf' => $asOf]);

                FcTest::assertSame([19, 31500], [(int) $r['totalRenewals'], (int) $r['totalProjected']],
                    'Next 3 months: yearly 1 x 120 (stale expire_at kept); monthly 3 x 10, 3 x 5, 3 x 20 (trial), 3 x 10, and 3 x 10 for each paid trial plan');
                $cents = function ($byPosition) {
                    return array_map(function ($row) {
                        return array_map('intval', (array) $row);
                    }, (array) $byPosition);
                };
                // By renewal position: the k-th renewal of a subscription in the horizon weighs rate^k
                FcTest::assertSame(['yearly' => [1 => 12000], 'monthly' => [1 => 4500, 2 => 4500, 3 => 4500]], $cents($r['by_interval']),
                    'Scheduled per interval and renewal position; every charge of the unconverted trial apart');
                FcTest::assertSame([['count' => 1, 'amount' => 2000], ['monthly' => [0 => 2000, 1 => 2000, 2 => 2000]]], [$r['trial_first'], $cents($r['trial'])],
                    'The trial converts in 7 days (position 0); its two later charges come only if it converts');
                FcTest::assertSame([75.0, false, 50.0], [$r['rates']['monthly'] ?? null, array_key_exists('yearly', $r['rates']), $r['trial_rate']],
                    'Monthly renewal rate 3 paid of 4 due in the last 12 closed months (an unpaid trial is no lost renewal); no yearly renewal was due; '
                    . 'trials: one paid, one ended unpaid in the window');
                FcTest::assertSame([['count' => 1, 'amount' => 500], ['count' => 1, 'amount' => 700], 1, 900],
                    [$r['overdue'], $r['retrying'], $r['wont_renew']['count'], $r['wont_renew']['amount']],
                    'Overdue 1 x 5, retrying 1 x 7, won\'t renew 1 x 9');

                $filtered = $service->getFutureRenewals(['currency' => $fcForecastCurrency, 'variationIds' => [$monthlyVariation], 'horizon' => '3', 'asOf' => $asOf]);
                FcTest::assertSame([3, 3000, 0], [(int) $filtered['totalRenewals'], (int) $filtered['totalProjected'], $filtered['retrying']['count']],
                    'The product filter keeps that plan only');

                // Renewals by variant is a Pro report: the route sends it only when Pro asks for it
                $route = FcTest::rest('GET', 'reports/future-renewals', ['params' => ['currency' => $fcForecastCurrency, 'horizon' => '3', 'as_of' => $asOf]]);
                FcTest::assertSame([200, has_filter('fluent_cart/report/future_renewals_by_variation') ? true : false],
                    [$route['status'], array_key_exists('by_variation', (array) ($route['data'] ?? []))],
                    'by_variation comes with Pro only');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];

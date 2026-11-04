<?php
/**
 * Cancel and rebuy: of the subscriptions whose paid term ended in the range (canceled or expired), those whose customer
 * bought the same product again within the window around that end; the timing and the discount against the renewal
 * price. A second license bought long before the end is no rebuy.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCartPro\App\Modules\Reports\Services\SubscriptionInsightsService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcRebuyCurrency = 'XTS';

return [
    [
        'id'            => 'reports-subscription-rebuys',
        'name'          => 'Cancel-rebuy: rate, timing and discount within the window around the paid term end',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC', // fixtures are UTC days; reports-site-timezone covers other zones
        'run'           => function () use ($fcRebuyCurrency) {
            // A Pro report: without Pro the route is gone and the Cohorts page shows a locked preview
            if (!class_exists(SubscriptionInsightsService::class)) {
                $route = FcTest::rest('GET', 'reports/subscription-rebuys', ['params' => ['currency' => $fcRebuyCurrency]]);
                FcTest::assertSame(404, $route['status'], 'Pro absent: core no longer serves reports/subscription-rebuys');

                return;
            }

            try {
                $db = App::db();
                $foreign = (int) $db->table('fct_orders')->whereIn('currency', [$fcRebuyCurrency, 'XXX'])->count()
                    + (int) $db->table('fct_subscriptions')->whereIn('currency', [$fcRebuyCurrency, 'XXX'])->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' rows already use currency ' . $fcRebuyCurrency);
                }
                FcFixture::customer();
                $base = 1660000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 20;
                $n = 0;
                $sub = function ($product, $status, $interval, $createdAt, $renewalCents, $firstPaidCents, array $extra = [], $otherItemCents = 0) use (&$n, $base, $fcRebuyCurrency, $db) {
                    $n++;
                    $currency = $extra['currency'] ?? $fcRebuyCurrency;
                    $total = $firstPaidCents + $otherItemCents;
                    $parent = FcFixture::reportOrder([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $currency,
                        'type' => Status::ORDER_TYPE_SUBSCRIPTION, 'subtotal' => $total, 'total_amount' => $total,
                        'total_paid' => $total, 'created_at' => $createdAt, 'updated_at' => $createdAt,
                    ]);
                    FcFixture::reportOrderItem((int) $parent->id, ['post_id' => $base + $product, 'object_id' => $base + $product + 1000,
                        'payment_type' => 'subscription', 'unit_price' => $firstPaidCents, 'subtotal' => $firstPaidCents, 'line_total' => $firstPaidCents]);
                    if (!empty($extra['signup_fee'])) {
                        // A signup fee line shares the plan's object id
                        FcFixture::reportOrderItem((int) $parent->id, ['post_id' => $base + $product, 'object_id' => $base + $product + 1000, 'cart_index' => 2,
                            'payment_type' => 'signup_fee', 'unit_price' => $extra['signup_fee'], 'subtotal' => $extra['signup_fee'], 'line_total' => $extra['signup_fee']]);
                    }
                    unset($extra['signup_fee']);
                    if ($otherItemCents) {
                        FcFixture::reportOrderItem((int) $parent->id, ['post_id' => $base + 99, 'object_id' => $base + 1099, 'cart_index' => 1,
                            'unit_price' => $otherItemCents, 'subtotal' => $otherItemCents, 'line_total' => $otherItemCents]);
                    }
                    $row = FcDomainFixture::subscription($parent, $base + $product, $base + $product + 1000, $status, 'rebuy-' . $n,
                        // Recurring tax on top: the renewal price that compares with an order line is the amount before tax
                        array_merge(['currency' => $currency, 'canceled_at' => null, 'billing_interval' => $interval, 'recurring_amount' => $renewalCents,
                            'recurring_tax_total' => intdiv($renewalCents, 10), 'recurring_total' => $renewalCents + intdiv($renewalCents, 10)], $extra));
                    $db->table('fct_subscriptions')->where('id', (int) $row->id)->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);

                    return $parent;
                };

                // A yearly plan canceled right after purchase; its year ends 2026-01-10 and it is bought again 10 days later at a discount
                $sub(1, Status::SUBSCRIPTION_CANCELED, 'yearly', '2025-01-10 10:00:00', 10000, 10000, ['canceled_at' => '2025-01-12 10:00:00']);
                // Bought in one order with an unrelated product: the rebuy's own line is what it paid
                $sub(1, Status::SUBSCRIPTION_ACTIVE, 'yearly', '2026-01-20 10:00:00', 10000, 6000, ['signup_fee' => 1000], 5000);
                // Expired at its year end with no new purchase
                $sub(2, Status::SUBSCRIPTION_EXPIRED, 'yearly', '2025-02-01 10:00:00', 10000, 10000);
                // A second license bought eleven months before the first one's year ended: no rebuy
                $sub(3, Status::SUBSCRIPTION_CANCELED, 'yearly', '2025-03-01 10:00:00', 10000, 10000, ['canceled_at' => '2026-02-20 10:00:00']);
                $sub(3, Status::SUBSCRIPTION_ACTIVE, 'yearly', '2025-04-01 10:00:00', 10000, 10000);
                // A monthly plan bought again 4 days before its month ended, at full price
                $sub(4, Status::SUBSCRIPTION_CANCELED, 'monthly', '2026-01-05 10:00:00', 1000, 1000, ['canceled_at' => '2026-01-30 10:00:00']);
                $sub(4, Status::SUBSCRIPTION_ACTIVE, 'monthly', '2026-02-01 10:00:00', 1000, 1000);
                // Ended in February; bought again 5 days later, but in another currency: prices do not compare, no rebuy
                $sub(5, Status::SUBSCRIPTION_EXPIRED, 'yearly', '2025-02-15 10:00:00', 10000, 10000);
                $sub(5, Status::SUBSCRIPTION_ACTIVE, 'yearly', '2026-02-20 10:00:00', 8000, 8000, ['currency' => 'XXX']);
                // A free trial that never paid had no paid term; a second free trial is no purchase either
                $sub(6, Status::SUBSCRIPTION_CANCELED, 'monthly', '2026-01-01 10:00:00', 1000, 0, ['trial_days' => 14, 'canceled_at' => '2026-01-05 10:00:00']);
                $sub(6, Status::SUBSCRIPTION_TRIALING, 'monthly', '2026-01-20 10:00:00', 1000, 0, ['trial_days' => 14]);
                // Ended 10 March; bought again 2 days later as a free trial that paid two weeks on: a rebuy
                $sub(7, Status::SUBSCRIPTION_EXPIRED, 'yearly', '2025-03-10 10:00:00', 10000, 10000);
                $trial = $sub(7, Status::SUBSCRIPTION_ACTIVE, 'yearly', '2026-03-12 10:00:00', 10000, 0, ['trial_days' => 14]);
                FcFixture::reportOrder(['status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcRebuyCurrency,
                    'type' => Status::ORDER_TYPE_RENEWAL, 'parent_id' => (int) $trial->id, 'subtotal' => 10000, 'total_amount' => 10000, 'total_paid' => 10000,
                    'created_at' => '2026-03-26 10:00:00', 'updated_at' => '2026-03-26 10:00:00']);
                // Ended 20 March; bought again 2 days later with a 100% coupon on the first year (a simulated trial): a full discount
                $sub(8, Status::SUBSCRIPTION_EXPIRED, 'yearly', '2025-03-20 10:00:00', 10000, 10000);
                $sub(8, Status::SUBSCRIPTION_ACTIVE, 'yearly', '2026-03-22 10:00:00', 10000, 0, ['trial_days' => 365, 'config' => ['is_trial_days_simulated' => 'yes']]);
                // Ended 25 March; bought again 1 day later as a monthly plan: a rebuy, but a monthly price is no yearly discount
                $sub(9, Status::SUBSCRIPTION_EXPIRED, 'yearly', '2025-03-25 10:00:00', 10000, 10000);
                $sub(9, Status::SUBSCRIPTION_ACTIVE, 'monthly', '2026-03-26 10:00:00', 1000, 1000);

                $params = function (array $extra = []) use ($fcRebuyCurrency) {
                    return array_merge([
                        'startDate' => new DateTime('2026-01-01 00:00:00'), 'endDate' => new DateTime('2026-03-31 23:59:59'),
                        'currency' => $fcRebuyCurrency, 'variationIds' => [], 'groupKey' => 'monthly', 'windowDays' => 30,
                    ], $extra);
                };
                $service = SubscriptionInsightsService::make();

                $r = $service->getRebuys($params());
                FcTest::assertSame([8, 5, 62.5], [$r['ended'], $r['rebought'], $r['rebuy_rate']],
                    'Eight terms ended (Jan, Feb x3, Mar x4); five were bought again (one through a trial that paid, one with a free first year, one as a monthly plan); '
                    . 'the second license, the other currency and the unpaid trials are no rebuy');
                FcTest::assertSame(['before' => 1, 'within_7' => 3, 'later' => 1], $r['timing'], 'One 4 days before the end, three within 2 days after, one 10 days after');
                FcTest::assertSame([2, 140.0, 70.0], [$r['discounted'], (float) $r['discount_total'], (float) $r['discount_average']],
                    'The yearly rebuy paid 60 against a renewal of 100 (fee, other item and tax aside); the free first year saved 100; '
                    . 'the monthly one paid full price; the trial that paid later and the yearly-to-monthly switch have no price to compare');
                FcTest::assertSame([[1, 1], [3, 1], [4, 3]], array_map(function ($row) {
                    return [$row['ended'], $row['rebought']];
                }, $r['chart']), 'By the month the term ended');

                $tight = $service->getRebuys($params(['windowDays' => 7]));
                FcTest::assertSame([8, 4], [$tight['ended'], $tight['rebought']], 'A 7-day window drops the rebuy 10 days after the end');

                $monthly = $service->getRebuys($params(['billingInterval' => 'monthly']));
                FcTest::assertSame([1, 1], [$monthly['ended'], $monthly['rebought']], 'Monthly plans only');

                $route = FcTest::rest('GET', 'reports/subscription-rebuys', ['params' => [
                    'startDate' => '2026-01-01', 'endDate' => '2026-03-31', 'currency' => $fcRebuyCurrency, 'windowDays' => 7,
                ]]);
                FcTest::assertSame([200, 4], [$route['status'], (int) ($route['data']['rebought'] ?? -1)], 'GET /reports/subscription-rebuys takes the window');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];

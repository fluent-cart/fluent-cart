<?php
/**
 * Products report detail panel: a product's live subscriptions and MRR, its renewal rate in the range (renewals paid
 * of those due), and the products bought in the same orders (renewals left out).
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCartPro\App\Modules\Reports\Services\ProductInsightsService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcDetailCurrency = 'XTS';

return [
    [
        'id'            => 'reports-product-detail',
        'name'          => 'Products report detail: live subscriptions, MRR, renewal rate and products bought together',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC',
        'run'           => function () use ($fcDetailCurrency) {
            // A Pro report: without Pro the route is gone and the free page shows a locked preview
            if (!class_exists(ProductInsightsService::class)) {
                $route = FcTest::rest('GET', 'reports/product-detail', ['params' => ['currency' => $fcDetailCurrency]]);
                FcTest::assertSame(404, $route['status'], 'Pro absent: core no longer serves reports/product-detail');

                return;
            }

            try {
                $db = App::db();
                $foreign = (int) $db->table('fct_orders')->where('currency', $fcDetailCurrency)->count()
                    + (int) $db->table('fct_subscriptions')->where('currency', $fcDetailCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' rows already use currency ' . $fcDetailCurrency);
                }
                FcFixture::customer();
                $base = 1630000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 10;
                [$product, $variation, $q, $qv, $r, $rv] = [$base, $base + 1, $base + 2, $base + 3, $base + 4, $base + 5];

                $order = function ($type, $createdAt, array $lines, $parent = 0) use ($fcDetailCurrency) {
                    $total = array_sum(array_column($lines, 2));
                    $row = FcFixture::reportOrder([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcDetailCurrency,
                        'type' => $type, 'parent_id' => $parent ?: null, 'subtotal' => $total, 'tax_total' => 0, 'total_amount' => $total,
                        'total_paid' => $total, 'created_at' => $createdAt, 'updated_at' => $createdAt, 'completed_at' => $createdAt,
                    ]);
                    foreach ($lines as $line) {
                        FcFixture::reportOrderItem($row->id, ['post_id' => $line[0], 'object_id' => $line[1], 'unit_price' => $line[2],
                            'subtotal' => $line[2], 'line_total' => $line[2], 'created_at' => $createdAt]);
                    }

                    return $row;
                };

                // A live monthly plan that renewed in February, and one canceled whose paid month ended in February
                $live = $order(Status::ORDER_TYPE_SUBSCRIPTION, '2001-01-05 10:00:00', []);
                FcDomainFixture::subscription($live, $product, $variation, Status::SUBSCRIPTION_ACTIVE, 'detail-live', [
                    'currency' => $fcDetailCurrency, 'canceled_at' => null, 'next_billing_date' => '2001-03-05 10:00:00']);
                $db->table('fct_subscriptions')->where('parent_order_id', (int) $live->id)->update(['created_at' => '2001-01-05 10:00:00']);
                $order(Status::ORDER_TYPE_RENEWAL, '2001-02-05 10:00:00', [[$product, $variation, 1000]], (int) $live->id);
                $gone = $order(Status::ORDER_TYPE_SUBSCRIPTION, '2001-01-10 10:00:00', []);
                FcDomainFixture::subscription($gone, $product, $variation, Status::SUBSCRIPTION_CANCELED, 'detail-gone', [
                    'currency' => $fcDetailCurrency, 'canceled_at' => '2001-01-20 10:00:00', 'next_billing_date' => null,
                    'config' => ['cancellation_reason' => 'cancelled_by_customer']]);
                $db->table('fct_subscriptions')->where('parent_order_id', (int) $gone->id)->update(['created_at' => '2001-01-10 10:00:00']);

                // A yearly plan on another variant of the product with no sale in February: still live, still in MRR
                $yearly = $order(Status::ORDER_TYPE_SUBSCRIPTION, '2000-06-01 10:00:00', []);
                FcDomainFixture::subscription($yearly, $product, $base + 6, Status::SUBSCRIPTION_ACTIVE, 'detail-yearly', [
                    'currency' => $fcDetailCurrency, 'canceled_at' => null, 'billing_interval' => 'yearly', 'recurring_amount' => 12000,
                    'recurring_total' => 12000, 'next_billing_date' => '2001-06-01 10:00:00']);
                $db->table('fct_subscriptions')->where('parent_order_id', (int) $yearly->id)->update(['created_at' => '2000-06-01 10:00:00']);

                // Upgraded in February from the first variant to another of the product: the product keeps the customer
                $moved = $order(Status::ORDER_TYPE_SUBSCRIPTION, '2001-01-12 10:00:00', []);
                $from = FcDomainFixture::subscription($moved, $product, $variation, Status::SUBSCRIPTION_CANCELED, 'detail-from', [
                    'currency' => $fcDetailCurrency, 'canceled_at' => '2001-02-01 10:00:00', 'next_billing_date' => null]);
                $to = FcDomainFixture::subscription($moved, $product, $base + 6, Status::SUBSCRIPTION_ACTIVE, 'detail-to', [
                    'currency' => $fcDetailCurrency, 'canceled_at' => null, 'next_billing_date' => '2001-03-01 10:00:00']);
                $db->table('fct_subscriptions')->where('id', (int) $from->id)->update(['created_at' => '2001-01-12 10:00:00',
                    'config' => json_encode(['upgraded_to_sub_id' => (int) $to->id, 'cancellation_reason' => 'upgraded_to_new_plan'])]);
                $db->table('fct_subscriptions')->where('id', (int) $to->id)->update(['created_at' => '2001-02-01 10:00:00',
                    'config' => json_encode(['upgraded_from_sub_id' => (int) $from->id])]);
                // Canceled lately, paid for 20 more days: its renewal is not lost yet
                $recent = $order(Status::ORDER_TYPE_SUBSCRIPTION, gmdate('Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS), []);
                FcDomainFixture::subscription($recent, $product, $variation, Status::SUBSCRIPTION_CANCELED, 'detail-recent', [
                    'currency' => $fcDetailCurrency, 'canceled_at' => gmdate('Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS), 'next_billing_date' => null,
                    'config' => ['cancellation_reason' => 'cancelled_by_customer']]);
                $db->table('fct_subscriptions')->where('parent_order_id', (int) $recent->id)->update(['created_at' => gmdate('Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS)]);

                // February orders: the product with Q twice, with R once; the renewal above holds the product alone
                $order(Status::ORDER_TYPE_PAYMENT, '2001-02-10 10:00:00', [[$product, $variation, 500], [$q, $qv, 300]]);
                $order(Status::ORDER_TYPE_PAYMENT, '2001-02-11 10:00:00', [[$product, $variation, 500], [$r, $rv, 300]]);
                $order(Status::ORDER_TYPE_PAYMENT, '2001-02-12 10:00:00', [[$product, $variation, 500], [$q, $qv, 300]]);
                // Q bought without the product: not "together"
                $order(Status::ORDER_TYPE_PAYMENT, '2001-02-13 10:00:00', [[$q, $qv, 300]]);

                $detail = ProductInsightsService::make()->getProductDetail([
                    'startDate' => new DateTime('2001-02-01 00:00:00'), 'endDate' => new DateTime('2001-02-28 23:59:59'),
                    'currency' => $fcDetailCurrency, 'paymentStatus' => Status::getReportStatuses(), 'variationIds' => [$variation],
                ], $product);

                $subscriptions = $detail['subscriptions'];
                FcTest::assertSame([4, 40.0, 1, 1, 50.0], [$subscriptions['subscriptions'], (float) $subscriptions['mrr'],
                    $subscriptions['renewals_paid'], $subscriptions['renewals_lost'], $subscriptions['renewal_rate']],
                    'Live: the monthly plan, the yearly one (no sale in February), the plan upgraded to, and the one canceled lately '
                    . 'still paid up, 10 a month each; February: one renewal paid, one lost; the upgrade within the product is no loss');

                // A range running past today: a term that ends after today is not lost yet
                $ahead = ProductInsightsService::make()->getProductDetail([
                    'startDate' => new DateTime('2001-02-01 00:00:00'), 'endDate' => new DateTime(gmdate('Y-m-d 23:59:59', time() + 60 * DAY_IN_SECONDS)),
                    'currency' => $fcDetailCurrency, 'paymentStatus' => Status::getReportStatuses(), 'variationIds' => [$variation],
                ], $product);
                FcTest::assertSame(1, $ahead['subscriptions']['renewals_lost'], 'Only the February loss; the term still running is not counted');

                FcTest::assertSame([3, [[$q, 2, 66.7], [$r, 1, 33.3]]], [$detail['orders'], array_map(function ($row) {
                    return [$row['product_id'], $row['orders'], $row['share']];
                }, $detail['bought_together'])], 'Of the 3 orders with the product (not its renewal), 2 also held Q and 1 held R');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];

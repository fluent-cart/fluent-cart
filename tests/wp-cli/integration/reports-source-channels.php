<?php
/**
 * Sources channels: each new customer's first purchase falls in one channel by its UTM tags and referrer
 * (email, paid, social, search, referral, campaign, direct; a referrer from the store itself is direct), with
 * tracking coverage, first-order net, lifetime value to date, repeat rate, refund rate and a chart by period.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\SourceReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcChannelCurrency = 'XTS';

return [
    [
        'id'            => 'reports-source-channels',
        'name'          => 'Sources channels: new customers by channel, coverage, lifetime value, repeat and refund rates',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC',
        'run'           => function () use ($fcChannelCurrency) {
            $db = App::db();
            $owner = null;
            $orderIds = [];
            try {
                $foreign = (int) $db->table('fct_orders')->where('currency', $fcChannelCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' rows already use currency ' . $fcChannelCurrency);
                }
                $owner = (int) FcFixture::customer()->id;
                $base = 1570000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 20;

                $order = function ($customer, $createdAt, $cents, $operation = null, array $extra = []) use ($fcChannelCurrency, &$orderIds) {
                    $row = FcFixture::reportOrder(array_merge([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcChannelCurrency,
                        'customer_id' => $customer, 'type' => Status::ORDER_TYPE_PAYMENT, 'subtotal' => $cents, 'tax_total' => 0,
                        'total_amount' => $cents, 'total_paid' => $cents, 'created_at' => $createdAt, 'updated_at' => $createdAt,
                    ], $extra));
                    $orderIds[] = (int) $row->id;
                    if ($operation !== null) {
                        FcFixture::reportOrderOperation($row->id, array_merge(
                            ['utm_campaign' => '', 'utm_source' => '', 'utm_medium' => '', 'utm_term' => '', 'utm_content' => '', 'utm_id' => '', 'refer_url' => ''],
                            $operation
                        ));
                    }

                    return $row;
                };

                // March: one new customer per channel
                $order($base + 1, '2001-03-02 10:00:00', 1000, ['utm_source' => 'FluentCRM', 'utm_medium' => 'email']);
                $order($base + 1, '2001-04-02 10:00:00', 500); // a later purchase: lifetime value and repeat
                $order($base + 2, '2001-03-03 10:00:00', 2000, ['utm_source' => 'google', 'utm_medium' => 'cpc']);
                $order($base + 3, '2001-03-04 10:00:00', 300, ['refer_url' => 'https://l.facebook.com/l.php?u=x']);
                $order($base + 4, '2001-03-05 10:00:00', 400, ['refer_url' => 'https://www.google.com/search?q=cart']);
                // Referral, refunded in full
                $order($base + 5, '2001-03-06 10:00:00', 600, ['refer_url' => 'https://blog.example.org/post'], [
                    'payment_status' => Status::PAYMENT_REFUNDED, 'total_refund' => 600]);
                $order($base + 6, '2001-03-07 10:00:00', 700, ['utm_source' => 'partner', 'utm_medium' => 'banner']);
                // An upgrade link inside a free plugin, and a visit from an AI assistant (ChatGPT tags its links)
                // No built-in guess for links inside a product: a tagged link without a known medium is a campaign
                $order($base + 10, '2001-03-11 10:00:00', 1100, ['utm_source' => 'fluent-forms', 'utm_medium' => 'free_plugin']);
                $order($base + 11, '2001-03-12 10:00:00', 1200, ['utm_source' => 'chatgpt.com']);
                $order($base + 12, '2001-03-13 10:00:00', 1300, ['refer_url' => 'https://www.perplexity.ai/search/x']);
                // Direct: no source at all, and a referrer from the store itself
                $order($base + 7, '2001-03-08 10:00:00', 800);
                $order($base + 8, '2001-03-09 10:00:00', 900, ['refer_url' => home_url('/pricing/')]);
                // Back from the Google sign-in page: no source, so direct (not search)
                $order($base + 13, '2001-03-14 10:00:00', 150, ['refer_url' => 'https://accounts.google.com/o/oauth2/auth']);
                // A Google Business Profile tag (medium organic) is search; a webmail click is email;
                // a medium as common as "app" is no in-product link, so a tagged campaign
                $order($base + 14, '2001-03-15 10:00:00', 160, ['utm_source' => 'google', 'utm_medium' => 'organic']);
                $order($base + 15, '2001-03-16 10:00:00', 170, ['refer_url' => 'https://mail.google.com/mail/u/0/']);
                $order($base + 16, '2001-03-17 10:00:00', 180, ['utm_source' => 'homepage', 'utm_medium' => 'app']);
                // A returning customer is no customer won
                $order($base + 9, '2001-01-10 10:00:00', 100);
                $order($base + 9, '2001-03-10 10:00:00', 100, ['utm_source' => 'FluentCRM', 'utm_medium' => 'email']);

                $report = SourceReportService::make()->getChannels([
                    'startDate' => new DateTime('2001-03-01 00:00:00'), 'endDate' => new DateTime('2001-03-31 23:59:59'),
                    'currency' => $fcChannelCurrency, 'paymentStatus' => Status::getReportStatuses(), 'groupKey' => 'monthly',
                ]);

                $channels = array_column($report['channels'], null, 'key');
                FcTest::assertSame(
                    ['email' => 2, 'paid' => 1, 'social' => 1, 'ai' => 2, 'search' => 2, 'referral' => 1, 'campaign' => 3, 'direct' => 3],
                    array_map(function ($channel) {
                        return $channel['customers'];
                    }, $channels),
                    'One new customer per channel; the self-referral counts as direct; the returning customer is left out'
                );
                FcTest::assertSame([1170, 835, 50.0], [$channels['email']['first_net'], $channels['email']['average_ltv'], $channels['email']['repeat_rate']],
                    'Email: 10.00 (bought again in April, 15.00 to date) and the webmail click, 1.70; 16.70 over two customers');
                FcTest::assertSame([0, 100.0], [$channels['referral']['first_net'], $channels['referral']['refund_rate']], 'Referral: refunded in full');
                FcTest::assertSame([1850, 0.0], [$channels['direct']['first_net'], $channels['direct']['repeat_rate']], 'Direct: 8.00, 9.00 and the sign-in return, 1.50');
                FcTest::assertSame([15, 12, 80.0], [$report['coverage']['customers'], $report['coverage']['tracked'], $report['coverage']['share']],
                    'Twelve of the fifteen new customers have a known source');

                $march = array_values(array_filter($report['chart'], function ($point) {
                    return $point['group'] === '2001-03';
                }))[0];
                FcTest::assertSame([2, 3], [$march['email'], $march['direct']], 'The chart counts March\'s new customers by channel');
            } finally {
                if ($owner && $orderIds) {
                    $db->table('fct_orders')->whereIn('id', $orderIds)->update(['customer_id' => $owner]);
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];

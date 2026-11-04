<?php
/**
 * Custom channel rules on the Sources report: a store's rules on UTM tags and referrer domain run before the
 * built-in channels, first match wins, its own domains count as direct, values match literally and safely, saving
 * cleans the input, and only a user who can manage store settings may save.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\ChannelRules;
use FluentCart\App\Services\Report\SourceReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcChannelRulesCurrency = 'XTS';

return [
    [
        'id'            => 'reports-source-channel-rules',
        'name'          => 'Custom channel rules: run first, first match wins, own domains are direct, literal and safe values, store-settings only',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC',
        'run'           => function () use ($fcChannelRulesCurrency) {
            $db = App::db();
            $owner = null;
            $orderIds = [];
            $saved = get_option(ChannelRules::OPTION, null);
            $accountant = 0;
            $admin = get_current_user_id();

            // Custom rules are a Pro feature: without Pro a saved rule set is ignored and the routes are gone
            if (!has_filter('fluent_cart/report/channel_rules')) {
                try {
                    update_option(ChannelRules::OPTION, ['rules' => [['id' => 'r1', 'name' => 'Everything',
                        'groups' => [[['field' => 'source', 'operator' => 'contains', 'value' => 'partner']]]]], 'own_domains' => []], false);
                    $report = SourceReportService::make()->getChannels([
                        'startDate' => new DateTime('2001-03-01 00:00:00'), 'endDate' => new DateTime('2001-03-31 23:59:59'),
                        'currency' => $fcChannelRulesCurrency, 'paymentStatus' => Status::getReportStatuses(), 'groupKey' => 'monthly', 'variationIds' => [],
                    ]);
                    FcTest::assertSame([], array_values(array_filter(array_column($report['channels'], 'label'))),
                        'Pro absent: only the built-in channels, a saved custom rule is not applied');
                    FcTest::assertSame([404, 404], [FcTest::rest('GET', 'reports/channel-rules')['status'],
                        FcTest::rest('POST', 'reports/channel-rules', ['rules' => [], 'own_domains' => []])['status']],
                        'Pro absent: core no longer serves reports/channel-rules');
                } finally {
                    $saved === null ? delete_option(ChannelRules::OPTION) : update_option(ChannelRules::OPTION, $saved, false);
                }

                return;
            }

            try {
                $foreign = (int) $db->table('fct_orders')->where('currency', $fcChannelRulesCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' rows already use currency ' . $fcChannelRulesCurrency);
                }
                $owner = (int) FcFixture::customer()->id;
                $base = 1580000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 20;
                $next = 0;
                $order = function (array $operation) use ($fcChannelRulesCurrency, &$orderIds, $base, &$next) {
                    $at = '2001-03-0' . (1 + $next % 9) . ' 10:00:00';
                    $row = FcFixture::reportOrder([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcChannelRulesCurrency,
                        'customer_id' => $base + $next++, 'type' => Status::ORDER_TYPE_PAYMENT, 'subtotal' => 1000, 'tax_total' => 0,
                        'total_amount' => 1000, 'total_paid' => 1000, 'created_at' => $at, 'updated_at' => $at,
                    ]);
                    $orderIds[] = (int) $row->id;
                    FcFixture::reportOrderOperation($row->id, array_merge(
                        ['utm_campaign' => '', 'utm_source' => '', 'utm_medium' => '', 'utm_term' => '', 'utm_content' => '', 'utm_id' => '', 'refer_url' => ''],
                        $operation
                    ));
                };

                // Saving cleans the input: a bad field, a nameless rule and a pasted URL as a domain
                $response = FcTest::rest('POST', 'reports/channel-rules', [
                    'rules'       => [
                        // Two OR groups: a source starting with "partner", or a campaign "affiliate" from any source
                        ['name' => 'Partners', 'groups' => [[['field' => 'source', 'operator' => 'starts_with', 'value' => ' Partner '],
                            ['field' => 'nonsense', 'operator' => 'is', 'value' => 'x']],
                            [['field' => 'campaign', 'operator' => 'is', 'value' => 'affiliate']]]],
                        ['name' => 'Partner banners', 'conditions' => [['field' => 'medium', 'operator' => 'is', 'value' => 'banner']]],
                        ['name' => 'Sale', 'conditions' => [['field' => 'campaign', 'operator' => 'contains', 'value' => "It's 50%_off"]]],
                        ['name' => 'Quote', 'conditions' => [['field' => 'source', 'operator' => 'is', 'value' => "x' or 1=1 --"]]],
                        // The query layer rewrites ? and " in SQL text, so values must travel as bindings
                        ['name' => 'Odd', 'conditions' => [['field' => 'campaign', 'operator' => 'contains', 'value' => 'what?"now']]],
                        ['name' => '', 'conditions' => [['field' => 'source', 'operator' => 'is', 'value' => 'ignored']]],
                    ],
                    'own_domains' => ['https://www.MyShop.test/guide', 'not a domain'],
                ]);
                $data = $response['data'] ?? [];
                FcTest::assertSame([200, ['Partners', 'Partner banners', 'Sale', 'Quote', 'Odd'], [1, 2, 1], 'partner', ['myshop.test'], true], [
                    $response['status'], array_column($data['rules'] ?? [], 'name'), [count($data['rules'][0]['groups'][0] ?? []), count($data['rules'][0]['groups'] ?? []), count($data['rules'][1]['groups'] ?? [])],
                    $data['rules'][0]['groups'][0][0]['value'] ?? null, $data['own_domains'] ?? null, $data['can_edit'] ?? null,
                ], 'Saved: five named rules in OR groups (an old single list reads as one group), the bad condition dropped, values trimmed and lower-cased, the URL reduced to its host');

                $order(['utm_source' => 'partner_x', 'utm_medium' => 'cpc']);             // Partners, before the built-in paid rule
                $order(['utm_source' => 'Partner_Y', 'utm_medium' => 'banner']);          // both rules match: the first wins
                $order(['utm_source' => 'other', 'utm_medium' => 'banner']);              // Partner banners
                $order(['utm_source' => 'promo', 'utm_campaign' => "Spring IT'S 50%_OFF"]);  // Sale, matched literally
                $order(['utm_source' => 'promo', 'utm_campaign' => "it's 50x off"]);     // % and _ are not wildcards: a campaign
                $order(['utm_source' => "x' or 1=1 --"]);                                 // Quote
                $order(['utm_source' => 'fluentcrm', 'utm_medium' => 'email']);           // built-in email, untouched
                $order(['refer_url' => 'https://docs.myshop.test/guide']);                // an own subdomain: direct
                $order(['utm_source' => 'promo', 'utm_campaign' => 'So What?"Now']);    // Odd
                $order(['utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'Affiliate']); // Partners, by its second group

                $report = SourceReportService::make()->getChannels([
                    'startDate' => new DateTime('2001-03-01 00:00:00'), 'endDate' => new DateTime('2001-03-31 23:59:59'),
                    'currency' => $fcChannelRulesCurrency, 'paymentStatus' => Status::getReportStatuses(), 'groupKey' => 'monthly', 'variationIds' => [],
                ]);
                $customers = [];
                $labels = [];
                foreach ($report['channels'] as $channel) {
                    $customers[$channel['label'] ?: $channel['key']] = $channel['customers'];
                    $labels[$channel['key']] = $channel['label'];
                }
                $counts = array_intersect_key($customers, array_flip(['Partners', 'Partner banners', 'Sale', 'Quote', 'Odd', 'email', 'paid', 'social', 'campaign', 'direct', 'referral']));
                ksort($counts);
                $expected = ['Partners' => 3, 'Partner banners' => 1, 'Sale' => 1, 'Quote' => 1, 'Odd' => 1, 'email' => 1, 'paid' => 0, 'social' => 0, 'campaign' => 1, 'direct' => 1, 'referral' => 0];
                ksort($expected);
                FcTest::assertSame(
                    $expected, $counts,
                    'Custom channels first, first match wins, literal values, built-ins untouched, an own domain direct'
                );
                FcTest::assertSame([null, 'Partners'], [$labels['email'], $labels[ChannelRules::key($data['rules'][0])]],
                    'A custom channel carries its name; a built-in one is named by the admin app');
                $march = array_column($report['chart'], null, 'group')['2001-03'];
                FcTest::assertSame(3, $march[ChannelRules::key($data['rules'][0])] ?? null, 'The chart has the custom channel too');

                // Report access alone cannot change the rules
                $accountant = wp_insert_user(['user_login' => 'fct_rules_' . substr(md5(FcFixture::identity()), 0, 8),
                    'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
                update_user_meta($accountant, '_fluent_cart_admin_role', 'accountant');
                wp_set_current_user($accountant);
                $denied = FcTest::rest('POST', 'reports/channel-rules', ['rules' => [], 'own_domains' => []]);
                wp_set_current_user($admin);
                FcTest::assertSame([403, 5], [$denied['status'], count(ChannelRules::get()['rules'])], 'An accountant gets 403 and the rules stay');
            } finally {
                wp_set_current_user($admin);
                if ($accountant && !is_wp_error($accountant)) {
                    require_once ABSPATH . 'wp-admin/includes/user.php';
                    wp_delete_user($accountant);
                }
                $saved === null ? delete_option(ChannelRules::OPTION) : update_option(ChannelRules::OPTION, $saved, false);
                if ($owner && $orderIds) {
                    $db->table('fct_orders')->whereIn('id', $orderIds)->update(['customer_id' => $owner]);
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];

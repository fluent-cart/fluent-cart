<?php
/**
 * Phase 14 renewal progression and overdue dunning.
 */

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\StoreManagedRenewal\Services\RenewalService;

return [
    [
        'id'            => 'automation-renewal-paid-progression',
        'name'          => 'Paid renewal advances its owned subscription and dispatches once',
        'kind'          => 'behavior',
        'known_failure' => false,
        'phase'         => 14,
        'run'           => function () {
            $disableEmail = '__return_false';
            $disableIntegrations = '__return_empty_array';
            $disableNotifications = '__return_empty_array';
            $renewedCount = 0;
            $captureRenewed = function ($data) use (&$renewedCount) {
                $renewedCount++;
            };
            add_filter(
                'fluent_cart/should_send_email_notification',
                $disableEmail,
                PHP_INT_MAX,
                2
            );
            add_filter(
                'fluent_cart/integration/order_integrations',
                $disableIntegrations,
                PHP_INT_MAX,
                1
            );
            add_filter(
                'fluent_cart/email_notifications',
                $disableNotifications,
                PHP_INT_MAX,
                1
            );
            add_action(
                'fluent_cart/subscription_renewed',
                $captureRenewed,
                PHP_INT_MAX,
                1
            );

            try {
                $product = FcDomainFixture::product([
                    'payment_type' => 'subscription',
                    'other_info'   => ['repeat_interval' => 'monthly'],
                ]);
                $parent = FcDomainFixture::orderWithItem(
                    (int) $product['post']->ID,
                    (int) $product['variation']->id,
                    1,
                    ['type' => 'subscription']
                );
                $subscription = FcDomainFixture::subscription(
                    $parent,
                    (int) $product['post']->ID,
                    (int) $product['variation']->id,
                    Status::SUBSCRIPTION_PAST_DUE,
                    'paid-progression',
                    [
                        'collection_method' => 'manual',
                        'next_billing_date' => '2001-02-03 04:05:06',
                    ]
                );
                $renewal = FcFixture::reportOrder([
                    'parent_id'      => (int) $parent->id,
                    'type'           => Status::ORDER_TYPE_RENEWAL,
                    'status'         => Status::ORDER_COMPLETED,
                    'payment_status' => Status::PAYMENT_PAID,
                    'payment_method' => 'manual',
                    'total_paid'     => 10123,
                    'completed_at'   => gmdate('Y-m-d H:i:s'),
                ]);
                $renewal->updateMeta('due_date', '2001-02-03 04:05:06');
                $before = time();

                RenewalService::handleRenewalPaid(['order' => $renewal]);
                FcDomainFixture::captureSubscriptionMeta();

                $stored = Subscription::query()->find((int) $subscription->id);
                FcTest::assertSame(
                    Status::SUBSCRIPTION_ACTIVE,
                    $stored ? (string) $stored->status : null,
                    'paid renewal reactivates the owned subscription'
                );
                FcTest::assert(
                    $stored && strtotime((string) $stored->next_billing_date) > $before,
                    'overdue paid renewal advances next billing into the future'
                );
                $firstBillingDate = $stored ? (string) $stored->next_billing_date : null;
                FcTest::assertSame(
                    1,
                    (int) $renewal->getMeta('renewal_processed'),
                    'paid renewal records its idempotency marker'
                );
                FcTest::assertSame(1, $renewedCount, 'renewal event dispatches exactly once');

                RenewalService::handleRenewalPaid([
                    'order' => FcFixture::reloadOrder((int) $renewal->id),
                ]);
                $second = Subscription::query()->find((int) $subscription->id);
                FcTest::assertSame(
                    $firstBillingDate,
                    $second ? (string) $second->next_billing_date : null,
                    'second paid callback does not advance another period'
                );
                FcTest::assertSame(1, $renewedCount, 'second paid callback does not redispatch');
                FcTest::assertSame([], FcTest::sentMails(), 'renewal progression sends no mail');
                FcTest::assertSame([], FcTest::externalCalls(), 'renewal progression makes no loopback');
            } finally {
                remove_action(
                    'fluent_cart/subscription_renewed',
                    $captureRenewed,
                    PHP_INT_MAX
                );
                remove_filter(
                    'fluent_cart/email_notifications',
                    $disableNotifications,
                    PHP_INT_MAX
                );
                remove_filter(
                    'fluent_cart/integration/order_integrations',
                    $disableIntegrations,
                    PHP_INT_MAX
                );
                remove_filter(
                    'fluent_cart/should_send_email_notification',
                    $disableEmail,
                    PHP_INT_MAX
                );
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'automation-overdue-renewal-dunning',
        'name'          => 'Overdue renewal progresses through past-due and terminal expiry once',
        'kind'          => 'behavior',
        'known_failure' => false,
        'phase'         => 14,
        'run'           => function () {
            $disableEmail = '__return_false';
            $disableIntegrations = '__return_empty_array';
            $disableNotifications = '__return_empty_array';
            $pastDueCount = 0;
            $capturePastDue = function ($data) use (&$pastDueCount) {
                $pastDueCount++;
            };
            add_filter(
                'fluent_cart/should_send_email_notification',
                $disableEmail,
                PHP_INT_MAX,
                2
            );
            add_filter(
                'fluent_cart/integration/order_integrations',
                $disableIntegrations,
                PHP_INT_MAX,
                1
            );
            add_filter(
                'fluent_cart/email_notifications',
                $disableNotifications,
                PHP_INT_MAX,
                1
            );
            add_action(
                'fluent_cart/subscription_past_due',
                $capturePastDue,
                PHP_INT_MAX,
                1
            );

            try {
                $foreign = Order::query()
                    ->where('type', Status::ORDER_TYPE_RENEWAL)
                    ->whereIn('payment_status', [
                        Status::PAYMENT_PENDING,
                        Status::PAYMENT_SCHEDULED,
                        Status::PAYMENT_AUTHORIZED,
                        Status::PAYMENT_PARTIALLY_PAID,
                    ])
                    ->whereHas('parentOrder.subscriptions', function ($query) {
                        $query->whereIn('collection_method', ['manual', 'system'])
                            ->whereNotIn('status', [
                                Status::SUBSCRIPTION_COMPLETED,
                                Status::SUBSCRIPTION_CANCELED,
                                Status::SUBSCRIPTION_EXPIRED,
                            ]);
                    })
                    ->pluck('id')
                    ->toArray();
                if ($foreign !== []) {
                    throw new RuntimeException(
                        'Refusing unfiltered overdue scan; foreign renewal IDs: '
                        . wp_json_encode(array_map('intval', $foreign))
                    );
                }

                $product = FcDomainFixture::product([
                    'payment_type' => 'subscription',
                    'other_info'   => ['repeat_interval' => 'monthly'],
                ]);
                $parent = FcDomainFixture::orderWithItem(
                    (int) $product['post']->ID,
                    (int) $product['variation']->id,
                    1,
                    ['type' => 'subscription']
                );
                $subscription = FcDomainFixture::subscription(
                    $parent,
                    (int) $product['post']->ID,
                    (int) $product['variation']->id,
                    Status::SUBSCRIPTION_ACTIVE,
                    'overdue-dunning',
                    [
                        'collection_method' => 'manual',
                        'next_billing_date' => '2001-02-03 04:05:06',
                    ]
                );
                $renewal = FcFixture::reportOrder([
                    'parent_id'      => (int) $parent->id,
                    'type'           => Status::ORDER_TYPE_RENEWAL,
                    'status'         => Status::ORDER_PROCESSING,
                    'payment_status' => Status::PAYMENT_PENDING,
                    'created_at'     => gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS),
                ]);
                $renewal->updateMeta(
                    'due_date',
                    gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)
                );

                $first = RenewalService::processOverdueRenewals();
                $stored = Subscription::query()->find((int) $subscription->id);
                FcTest::assertSame(1, (int) $first['past_due'], 'first scan counts past due');
                FcTest::assertSame(0, (int) $first['expired'], 'first scan is not terminal');
                FcTest::assertSame(
                    Status::SUBSCRIPTION_PAST_DUE,
                    $stored ? (string) $stored->status : null,
                    'due-date branch progresses active to past due'
                );
                FcTest::assertSame(1, $pastDueCount, 'past-due hook dispatches once');

                $renewal->updateMeta('due_date', '2001-02-03 04:05:06');
                $second = RenewalService::processOverdueRenewals();
                FcDomainFixture::captureSubscriptionMeta();
                $expired = Subscription::query()->find((int) $subscription->id);
                FcTest::assertSame(0, (int) $second['past_due'], 'terminal scan adds no past due');
                FcTest::assertSame(1, (int) $second['expired'], 'terminal scan counts expiry');
                FcTest::assertSame(
                    Status::SUBSCRIPTION_EXPIRED,
                    $expired ? (string) $expired->status : null,
                    'grace branch progresses past due to expired'
                );
                FcTest::assertSame(
                    null,
                    $expired ? $expired->next_billing_date : 'missing',
                    'terminal expiry clears next billing'
                );

                $third = RenewalService::processOverdueRenewals();
                FcTest::assertSame(
                    ['past_due' => 0, 'expired' => 0, 'errors' => []],
                    $third,
                    'third overdue scan is a no-op'
                );
                FcTest::assertSame(1, $pastDueCount, 'terminal and repeat scans do not redispatch past due');
                FcTest::assertSame([], FcTest::sentMails(), 'dunning progression sends no mail');
                FcTest::assertSame([], FcTest::externalCalls(), 'dunning progression makes no loopback');
            } finally {
                remove_action(
                    'fluent_cart/subscription_past_due',
                    $capturePastDue,
                    PHP_INT_MAX
                );
                remove_filter(
                    'fluent_cart/email_notifications',
                    $disableNotifications,
                    PHP_INT_MAX
                );
                remove_filter(
                    'fluent_cart/integration/order_integrations',
                    $disableIntegrations,
                    PHP_INT_MAX
                );
                remove_filter(
                    'fluent_cart/should_send_email_notification',
                    $disableEmail,
                    PHP_INT_MAX
                );
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];

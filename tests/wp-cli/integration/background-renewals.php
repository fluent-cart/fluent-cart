<?php
/**
 * Phase 14 renewal progression.
 */

use FluentCart\App\Helpers\Status;
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
];

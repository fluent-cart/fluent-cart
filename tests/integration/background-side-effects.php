<?php
/**
 * Phase 14 side-effect chains, license expiry, and reminder queueing.
 */

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\Reminders\SubscriptionReminderService;

return [
    [
        'id'            => 'automation-order-status-side-effect-chain',
        'name'          => 'Completed-order side-effect chain dispatches and audits exactly once',
        'kind'          => 'behavior',
        'known_failure' => false,
        'phase'         => 14,
        'run'           => function () {
            $completedEvents = 0;
            $captureCompleted = function ($data) use (&$completedEvents) {
                $completedEvents++;
            };

            try {
                FcFixture::customer();
                $order = FcFixture::order([
                    'status' => Status::ORDER_PROCESSING,
                    'config' => ['fixture_case' => 'phase14-order-chain'],
                ]);
                FcFixture::assertOrderStatusHooksSafe(Status::ORDER_COMPLETED);
                add_action(
                    'fluent_cart/order_status_changed_to_completed',
                    $captureCompleted,
                    PHP_INT_MAX,
                    1
                );

                $first = FcFixture::updateOrderStatus($order, Status::ORDER_COMPLETED);
                FcTest::assert(is_array($first), 'first completed transition succeeds');
                FcTest::assertSame(1, $completedEvents, 'completed hook dispatches exactly once');
                FcFixture::assertNoForbiddenOrderSideEffects((int) $order->id, 1);

                $second = FcFixture::updateOrderStatus(
                    FcFixture::reloadOrder((int) $order->id),
                    Status::ORDER_COMPLETED
                );
                FcTest::assert(is_wp_error($second), 'repeat completed transition is rejected');
                FcTest::assertSame(
                    'Order already has the same status',
                    $second->get_error_message(),
                    'repeat transition returns the idempotence branch'
                );
                FcTest::assertSame(1, $completedEvents, 'repeat transition does not redispatch');
                FcFixture::assertNoForbiddenOrderSideEffects((int) $order->id, 1);
                FcTest::assertSame([], FcTest::sentMails(), 'order status chain sends no mail');
                FcTest::assertSame([], FcTest::externalCalls(), 'order status chain makes no loopback');
            } finally {
                remove_action(
                    'fluent_cart/order_status_changed_to_completed',
                    $captureCompleted,
                    PHP_INT_MAX
                );
                FcFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'automation-license-expiry',
        'name'          => 'License scheduler expires one exact owned license and repeats as a no-op',
        'kind'          => 'behavior',
        'known_failure' => false,
        'phase'         => 14,
        'run'           => function () {
            $expiredEvents = 0;
            $captureExpired = function ($data) use (&$expiredEvents) {
                $expiredEvents++;
            };

            try {
                $generationClass = FcDomainFixture::licenseHandlerClass();
                $schedulerClass = 'FluentCartPro\\App\\Modules\\Licensing\\Hooks\\Handlers\\LicenseSchedulerHandler';
                $licenseClass = 'FluentCartPro\\App\\Modules\\Licensing\\Models\\License';
                if (
                    $generationClass === null
                    || !class_exists($schedulerClass)
                    || !class_exists($licenseClass)
                ) {
                    FcTest::skip('FluentCart Pro licensing scheduler is inactive on this install.');
                    return;
                }

                $cutoff = date(
                    'Y-m-d H:i:s',
                    time() - DAY_IN_SECONDS
                        * FluentCartPro\App\Modules\Licensing\Services\LicenseHelper::getLicenseGracePeriodDays()
                );
                $foreign = $licenseClass::query()
                    ->whereIn('status', ['active', 'inactive'])
                    ->whereNotNull('expiration_date')
                    ->where('expiration_date', '!=', '0000-00-00 00:00:00')
                    ->where('expiration_date', '<=', $cutoff)
                    ->pluck('id')
                    ->toArray();
                if ($foreign !== []) {
                    throw new RuntimeException(
                        'Refusing license expiry; foreign candidate IDs: '
                        . wp_json_encode(array_map('intval', $foreign))
                    );
                }

                $product = FcDomainFixture::product([
                    'payment_type' => 'onetime',
                    'total_stock'  => 10,
                ]);
                $productId = (int) $product['post']->ID;
                $variationId = (int) $product['variation']->id;
                $order = FcDomainFixture::orderWithItem($productId, $variationId, 1);
                FcFixture::productMeta(
                    $productId,
                    'product',
                    'license_settings',
                    [
                        'enabled'    => 'yes',
                        'prefix'     => 'P14-',
                        'variations' => [[
                            'variation_id'     => $variationId,
                            'activation_limit' => 1,
                            'validity'         => ['unit' => 'lifetime', 'value' => 0],
                        ]],
                    ]
                );
                $generator = new $generationClass();
                $generator->maybeGenerateLicensesOnPurchaseSuccess(['order' => $order]);
                $licenses = FcDomainFixture::captureLicensesForOrder((int) $order->id);
                if (count($licenses) !== 1) {
                    throw new RuntimeException('Expected exactly one owned license before expiry.');
                }
                $license = $licenses[0];
                $license->status = 'active';
                $license->expiration_date = '2001-02-03 04:05:06';
                $license->save();

                $candidates = $licenseClass::query()
                    ->whereIn('status', ['active', 'inactive'])
                    ->whereNotNull('expiration_date')
                    ->where('expiration_date', '!=', '0000-00-00 00:00:00')
                    ->where('expiration_date', '<=', $cutoff)
                    ->pluck('id')
                    ->toArray();
                FcTest::assertSame(
                    [(int) $license->id],
                    array_map('intval', $candidates),
                    'license expiry predicate selects only the exact owned license'
                );

                add_action(
                    'fluent_cart/licensing/license_expired',
                    $captureExpired,
                    PHP_INT_MAX,
                    1
                );
                $scheduler = new $schedulerClass();
                $first = $scheduler->expireOldLicenses();
                $stored = $licenseClass::query()->find((int) $license->id);
                FcTest::assertSame(true, $first, 'first license expiry reports work');
                FcTest::assertSame(
                    'expired',
                    $stored ? (string) $stored->status : null,
                    'license scheduler persists terminal expired status'
                );
                FcTest::assertSame(1, $expiredEvents, 'license expiry hook dispatches once');

                $second = $scheduler->expireOldLicenses();
                FcTest::assertSame(false, $second, 'second license expiry pass is a no-op');
                FcTest::assertSame(1, $expiredEvents, 'second pass does not redispatch expiry');
                FcTest::assertSame([], FcTest::sentMails(), 'license expiry sends no mail');
                FcTest::assertSame([], FcTest::externalCalls(), 'license expiry makes no loopback');
            } finally {
                remove_action(
                    'fluent_cart/licensing/license_expired',
                    $captureExpired,
                    PHP_INT_MAX
                );
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'automation-subscription-reminder-queue',
        'name'          => 'Subscription reminder queues one owned action without executing email',
        'kind'          => 'behavior',
        'known_failure' => false,
        'phase'         => 14,
        'run'           => function () {
            $ownedSubscriptionId = 0;
            $cycleFilter = function ($cycle, $subscription) use (&$ownedSubscriptionId) {
                return (int) $subscription->id === $ownedSubscriptionId
                    ? 'yearly'
                    : 'unsupported';
            };
            $reminderDays = function () {
                return [30];
            };
            add_filter(
                'fluent_cart/reminders/billing_cycle',
                $cycleFilter,
                PHP_INT_MAX,
                2
            );
            add_filter(
                'fluent_cart/reminders/yearly_before_days',
                $reminderDays,
                PHP_INT_MAX,
                1
            );

            try {
                $foreignTrials = Subscription::query()
                    ->where('status', Status::SUBSCRIPTION_TRIALING)
                    ->whereNotNull('next_billing_date')
                    ->pluck('id')
                    ->toArray();
                if ($foreignTrials !== []) {
                    throw new RuntimeException(
                        'Refusing reminder scan; foreign trial IDs bypass cycle filter: '
                        . wp_json_encode(array_map('intval', $foreignTrials))
                    );
                }

                $product = FcDomainFixture::product([
                    'payment_type' => 'subscription',
                    'other_info'   => ['repeat_interval' => 'yearly'],
                ]);
                $order = FcDomainFixture::orderWithItem(
                    (int) $product['post']->ID,
                    (int) $product['variation']->id,
                    1,
                    ['type' => 'subscription']
                );
                $subscription = FcDomainFixture::subscription(
                    $order,
                    (int) $product['post']->ID,
                    (int) $product['variation']->id,
                    Status::SUBSCRIPTION_ACTIVE,
                    'reminder-queue',
                    [
                        'billing_interval'  => 'yearly',
                        'collection_method' => 'automatic',
                        'next_billing_date' => gmdate(
                            'Y-m-d H:i:s',
                            time() + (29 * DAY_IN_SECONDS)
                        ),
                    ]
                );
                $ownedSubscriptionId = (int) $subscription->id;
                $service = new SubscriptionReminderService();
                FcTest::assert($service->isEnabled(), 'yearly reminder queue is enabled');

                FcTest::beginExpectedActionSchedulerCapture();
                $first = $service->queueActions(time(), 20);
                $attempts = FcTest::consumeExpectedActionSchedulerAttempts();
                FcDomainFixture::captureSubscriptionMeta();
                FcTest::assertSame(
                    ['renewal' => 1, 'trial' => 0],
                    $first,
                    'first scan queues one renewal and no trial reminder'
                );
                FcTest::assertSame(
                    [[
                        'operation' => 'enqueue_async',
                        'hook'      => SubscriptionReminderService::RENEWAL_ASYNC_HOOK,
                    ]],
                    $attempts,
                    'reminder is captured at the async queue boundary'
                );
                $state = Subscription::query()
                    ->find($ownedSubscriptionId)
                    ->getMeta(SubscriptionReminderService::RENEWAL_META_KEY, []);
                FcTest::assert(!empty($state['cycles']), 'queued reminder persists cycle state');

                $second = (new SubscriptionReminderService())->queueActions(time(), 20);
                FcTest::assertSame(
                    ['renewal' => 0, 'trial' => 0],
                    $second,
                    'second reminder scan is a queueing no-op'
                );
                FcTest::assertSame([], FcTest::actionSchedulerAttempts(), 'second scan enqueues nothing');
                FcTest::assertSame([], FcTest::sentMails(), 'queue-only reminder path sends no mail');
                FcTest::assertSame([], FcTest::externalCalls(), 'reminder queue makes no loopback');
            } finally {
                remove_filter(
                    'fluent_cart/reminders/yearly_before_days',
                    $reminderDays,
                    PHP_INT_MAX
                );
                remove_filter(
                    'fluent_cart/reminders/billing_cycle',
                    $cycleFilter,
                    PHP_INT_MAX
                );
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];

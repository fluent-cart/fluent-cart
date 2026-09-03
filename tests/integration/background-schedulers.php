<?php
/**
 * Phase 14 JobRunner/automatic scheduler progression and Phase 24 cutoff kill.
 */

require_once dirname(__DIR__) . '/lib/daily-scheduler-clock.php';

use FluentCart\App\Helpers\Status;
use FluentCart\App\Hooks\Scheduler\AutoSchedules\FcDailySchedulerClock;
use FluentCart\App\Hooks\Scheduler\AutoSchedules\DailyScheduler;
use FluentCart\App\Hooks\Scheduler\AutoSchedules\FiveMinuteScheduler;
use FluentCart\App\Hooks\Scheduler\AutoSchedules\HourlyScheduler;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\ScheduledAction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\Email\StoreDigestService;

return [
    [
        'id'            => 'automation-job-runner-five-minute',
        'name'          => 'Five-minute scheduler enrolls and completes one due integration job once',
        'kind'          => 'behavior',
        'known_failure' => false,
        'phase'         => 14,
        'run'           => function () {
            try {
                $foreign = ScheduledAction::query()
                    ->where('status', Status::SCHEDULE_PENDING)
                    ->where('retry_count', '<', 5)
                    ->where('scheduled_at', '<=', current_time('mysql', true))
                    ->pluck('id')
                    ->toArray();
                if ($foreign !== []) {
                    throw new RuntimeException(
                        'Refusing unfiltered five-minute scheduler; foreign due IDs: '
                        . wp_json_encode(array_map('intval', $foreign))
                    );
                }

                $job = FcDomainFixture::jobRunnerQueue();
                FcTest::assertSame(
                    Status::SCHEDULE_PENDING,
                    (string) $job->status,
                    'JobRunner supplies the pending enrollment status'
                );
                FcTest::assertSame(0, (int) $job->retry_count, 'JobRunner supplies retry zero');
                FcTest::assertSame('integration', (string) $job->group, 'owned job uses runnable group');

                (new FiveMinuteScheduler())->handle();
                $stored = ScheduledAction::query()->find((int) $job->id);
                FcTest::assert(
                    $stored !== null,
                    'completed JobRunner row remains readable before hourly retention'
                );
                FcTest::assertSame(
                    Status::SCHEDULE_COMPLETED,
                    $stored ? (string) $stored->status : null,
                    'five-minute entry point completes the due job'
                );

                (new FiveMinuteScheduler())->handle();
                $second = ScheduledAction::query()->find((int) $job->id);
                FcTest::assertSame(
                    Status::SCHEDULE_COMPLETED,
                    $second ? (string) $second->status : null,
                    'second five-minute pass is a stable no-op'
                );
                FcTest::assertSame([], FcTest::sentMails(), 'job runner sends no mail');
                FcTest::assertSame([], FcTest::externalCalls(), 'job runner makes no loopback');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'automation-hourly-retention-and-expiry',
        'name'          => 'Hourly scheduler removes completed jobs and expires eligible subscriptions once',
        'kind'          => 'behavior',
        'known_failure' => false,
        'phase'         => 14,
        'run'           => function () {
            $disableIntegrations = '__return_empty_array';
            add_filter(
                'fluent_cart/integration/order_integrations',
                $disableIntegrations,
                PHP_INT_MAX,
                1
            );
            $cacheProperty = new ReflectionProperty(StoreDigestService::class, 'settingsCache');
            $cacheProperty->setAccessible(true);
            $cacheProperty->setValue(null, ['enabled' => 'no']);

            try {
                $completed = ScheduledAction::query()
                    ->where('status', Status::SCHEDULE_COMPLETED)
                    ->pluck('id')
                    ->toArray();
                if ($completed !== []) {
                    throw new RuntimeException(
                        'Refusing hourly retention; foreign completed IDs: '
                        . wp_json_encode(array_map('intval', $completed))
                    );
                }
                $expiryCandidates = FcDomainFixture::expiryCandidateIds();
                if ($expiryCandidates !== []) {
                    throw new RuntimeException(
                        'Refusing hourly expiry; foreign subscription IDs: '
                        . wp_json_encode($expiryCandidates)
                    );
                }

                $completedJob = FcDomainFixture::scheduledAction([
                    'action_suffix' => 'hourly-completed',
                    'status'        => Status::SCHEDULE_COMPLETED,
                    'completed_at'  => '2001-02-03 04:06:07',
                ]);
                $product = FcDomainFixture::product([
                    'payment_type' => 'subscription',
                    'other_info'   => ['repeat_interval' => 'monthly'],
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
                    'hourly-expiry'
                );
                FcTest::assertSame(
                    [(int) $subscription->id],
                    FcDomainFixture::expiryCandidateIds(),
                    'hourly production predicate selects only the owned subscription'
                );

                FcDomainFixture::beginActivityCapture();
                (new HourlyScheduler())->handle();
                FcDomainFixture::captureExpiryActivities();
                FcDomainFixture::captureSubscriptionMeta();

                FcTest::assertSame(
                    null,
                    ScheduledAction::query()->find((int) $completedJob->id),
                    'hourly retention removes the completed owned job'
                );
                $stored = Subscription::query()->find((int) $subscription->id);
                FcTest::assertSame(
                    Status::SUBSCRIPTION_EXPIRED,
                    $stored ? (string) $stored->status : null,
                    'hourly scheduler progresses eligible subscription to expired'
                );
                $marker = $stored ? $stored->getMeta('validity_expired_at') : null;
                FcTest::assert(is_string($marker) && $marker !== '', 'hourly expiry writes marker');

                (new HourlyScheduler())->handle();
                $second = Subscription::query()->find((int) $subscription->id);
                FcTest::assertSame(
                    $marker,
                    $second ? $second->getMeta('validity_expired_at') : null,
                    'second hourly pass preserves the first expiry marker'
                );
                FcTest::assertSame([], FcTest::sentMails(), 'hourly scheduler sends no mail');
                FcTest::assertSame([], FcTest::externalCalls(), 'hourly scheduler makes no loopback');
            } finally {
                $cacheProperty->setValue(null, null);
                remove_filter(
                    'fluent_cart/integration/order_integrations',
                    $disableIntegrations,
                    PHP_INT_MAX
                );
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'phase24-daily-cart-cutoff-equality',
        'name'          => 'Daily scheduler deletes a cart at the exact cutoff and retains one second newer',
        'kind'          => 'behavior',
        'known_failure' => false,
        'phase'         => 24,
        'run'           => function () {
            $days = 30000;
            $fixedNow = strtotime('2026-07-31 12:34:56 UTC');
            $cutoffTimestamp = strtotime('-' . $days . ' days', $fixedNow);
            $cutoff = date('Y-m-d H:i:s', $cutoffTimestamp);
            $oneSecondNewer = date('Y-m-d H:i:s', $cutoffTimestamp + 1);
            $oldDays = function () use ($days) {
                return $days;
            };

            FcDailySchedulerClock::freeze($fixedNow);
            add_filter('fluent_cart/cleanup/old_carts_days', $oldDays, PHP_INT_MAX, 1);

            try {
                $foreign = Cart::query()
                    ->where('updated_at', '<=', $cutoff)
                    ->pluck('cart_hash')
                    ->toArray();
                if ($foreign !== []) {
                    throw new RuntimeException(
                        'Refusing exact-cutoff cart cleanup; foreign stale hashes: '
                        . wp_json_encode(array_values($foreign))
                    );
                }

                $atCutoff = FcAutomationFixture::cart('phase24-cutoff', $cutoff);
                $newer = FcAutomationFixture::cart('phase24-newer', $oneSecondNewer);
                (new DailyScheduler())->handle();

                FcTest::assertSame(
                    null,
                    FcAutomationFixture::findCart((string) $atCutoff->cart_hash),
                    'cart equal to the computed cutoff is deleted'
                );
                FcTest::assert(
                    FcAutomationFixture::findCart((string) $newer->cart_hash) !== null,
                    'cart one second newer than the cutoff is retained'
                );

                (new DailyScheduler())->handle();
                FcTest::assert(
                    FcAutomationFixture::findCart((string) $newer->cart_hash) !== null,
                    'repeat exact-cutoff pass remains a no-op for the newer cart'
                );
                FcTest::assertSame([], FcTest::sentMails(), 'cutoff boundary sends no mail');
                FcTest::assertSame([], FcTest::externalCalls(), 'cutoff boundary makes no loopback');
            } finally {
                remove_filter('fluent_cart/cleanup/old_carts_days', $oldDays, PHP_INT_MAX);
                FcDailySchedulerClock::reset();
                FcAutomationFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'automation-daily-old-cart-cleanup',
        'name'          => 'Daily scheduler deletes only the owned stale cart and repeats as a no-op',
        'kind'          => 'behavior',
        'known_failure' => false,
        'phase'         => 14,
        'run'           => function () {
            $days = 30000;
            $oldDays = function () use ($days) {
                return $days;
            };
            add_filter('fluent_cart/cleanup/old_carts_days', $oldDays, PHP_INT_MAX, 1);

            try {
                $cutoff = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
                $foreign = Cart::query()
                    ->where('updated_at', '<=', $cutoff)
                    ->pluck('cart_hash')
                    ->toArray();
                if ($foreign !== []) {
                    throw new RuntimeException(
                        'Refusing daily cart cleanup; foreign stale hashes: '
                        . wp_json_encode(array_values($foreign))
                    );
                }

                $stale = FcAutomationFixture::cart('stale', '1901-02-03 04:05:06');
                $future = FcAutomationFixture::cart('future', '2099-02-03 04:05:06');
                (new DailyScheduler())->handle();

                FcTest::assertSame(
                    null,
                    FcAutomationFixture::findCart((string) $stale->cart_hash),
                    'daily scheduler deletes the exact owned stale cart'
                );
                FcTest::assert(
                    FcAutomationFixture::findCart((string) $future->cart_hash) !== null,
                    'daily scheduler retains the exact owned future cart'
                );

                (new DailyScheduler())->handle();
                FcTest::assert(
                    FcAutomationFixture::findCart((string) $future->cart_hash) !== null,
                    'second daily pass is a no-op for the retained cart'
                );
                FcTest::assertSame([], FcTest::sentMails(), 'daily scheduler sends no mail');
                FcTest::assertSame([], FcTest::externalCalls(), 'daily scheduler makes no loopback');
            } finally {
                remove_filter('fluent_cart/cleanup/old_carts_days', $oldDays, PHP_INT_MAX);
                FcAutomationFixture::cleanupAll();
            }
        },
    ],
];

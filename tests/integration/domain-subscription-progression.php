<?php
/**
 * Phase 8 automatic subscription validity progression invariants.
 */

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Subscription;

return [
    [
        'id'            => 'domain-subscription-expiry-progression',
        'name'          => 'Eligible subscriptions expire in bounded batches exactly once',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            $disableIntegrations = '__return_empty_array';
            add_filter(
                'fluent_cart/integration/order_integrations',
                $disableIntegrations,
                PHP_INT_MAX,
                1
            );

            try {
                $preexistingCandidates = FcDomainFixture::expiryCandidateIds();
                if ($preexistingCandidates !== []) {
                    throw new RuntimeException(
                        'Refusing subscription expiry because the production predicate '
                        . 'selected pre-existing IDs: '
                        . wp_json_encode($preexistingCandidates)
                    );
                }
                FcTest::assertSame(
                    [],
                    $preexistingCandidates,
                    'read-only safety preflight finds no foreign expiry candidates'
                );

                $product = FcDomainFixture::product([
                    'payment_type' => 'subscription',
                    'other_info'   => [
                        'repeat_interval' => 'monthly',
                        'trial_days'      => 0,
                    ],
                ]);
                $order = FcDomainFixture::orderWithItem(
                    (int) $product['post']->ID,
                    (int) $product['variation']->id,
                    1,
                    ['type' => 'subscription']
                );
                foreach ([
                    [Status::SUBSCRIPTION_ACTIVE, 'active'],
                    [Status::SUBSCRIPTION_TRIALING, 'trialing'],
                    [Status::SUBSCRIPTION_PAST_DUE, 'past-due'],
                ] as $definition) {
                    FcDomainFixture::subscription(
                        $order,
                        (int) $product['post']->ID,
                        (int) $product['variation']->id,
                        $definition[0],
                        $definition[1]
                    );
                }

                $ownedIds = FcDomainFixture::ownedSubscriptionIds();
                $candidateIds = FcDomainFixture::expiryCandidateIds();
                if ($candidateIds !== $ownedIds) {
                    throw new RuntimeException(
                        'Refusing subscription expiry because candidate ownership drifted: '
                        . 'owned=' . wp_json_encode($ownedIds)
                        . ' candidates=' . wp_json_encode($candidateIds)
                    );
                }
                FcTest::assertSame(
                    $ownedIds,
                    $candidateIds,
                    'production expiry predicate selects only exact owned subscriptions'
                );

                FcDomainFixture::beginActivityCapture();
                $stats = Subscription::checkAndExpireSubscriptions(2);
                $activities = FcDomainFixture::captureExpiryActivities();
                FcDomainFixture::captureSubscriptionMeta();

                FcTest::assertSame(3, (int) $stats['checked'], 'expiry checks all owned rows');
                FcTest::assertSame(
                    3,
                    (int) $stats['validity_expired'],
                    'expiry progresses every owned row'
                );
                FcTest::assertSame(2, (int) $stats['batches'], 'batch size two produces two batches');
                FcTest::assertSame(
                    $ownedIds,
                    array_map('intval', $stats['expired_ids']),
                    'expiry IDs preserve ascending cursor order'
                );
                FcTest::assertSame(
                    4,
                    count($activities),
                    'three lifecycle activities plus one summary are captured'
                );

                $markers = [];
                foreach ($ownedIds as $id) {
                    $stored = Subscription::query()->find($id);
                    FcTest::assert($stored !== null, 'expired Subscription remains readable: ' . $id);
                    FcTest::assertSame(
                        Status::SUBSCRIPTION_EXPIRED,
                        (string) $stored->status,
                        'eligible Subscription status progresses to expired: ' . $id
                    );
                    FcTest::assertSame(
                        null,
                        $stored->next_billing_date,
                        'expired Subscription cannot be selected again: ' . $id
                    );
                    $markers[$id] = $stored->getMeta('validity_expired_at');
                    FcTest::assert(
                        is_string($markers[$id]) && $markers[$id] !== '',
                        'expiry writes an idempotency marker: ' . $id
                    );
                }

                $second = Subscription::checkAndExpireSubscriptions(2);
                FcTest::assertSame(
                    [
                        'checked'          => 0,
                        'validity_expired' => 0,
                        'batches'          => 0,
                        'expired_ids'      => [],
                    ],
                    $second,
                    'second progression pass is a no-op'
                );
                foreach ($ownedIds as $id) {
                    $stored = Subscription::query()->find($id);
                    FcTest::assertSame(
                        $markers[$id],
                        $stored->getMeta('validity_expired_at'),
                        'second pass preserves the first expiry marker: ' . $id
                    );
                }
            } finally {
                remove_filter(
                    'fluent_cart/integration/order_integrations',
                    $disableIntegrations,
                    PHP_INT_MAX
                );
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];

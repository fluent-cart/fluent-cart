<?php
/**
 * Phase 14 system-charge retry bookkeeping without a payment gateway call.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Activity;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\SystemChargeService;

$makeSystemChargeFixture = function ($suffix) {
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
        $suffix,
        [
            'collection_method'      => 'system',
            'current_payment_method' => 'phase14-gateway-does-not-exist',
            'next_billing_date'      => '2001-02-03 04:05:06',
        ]
    );
    $renewal = FcFixture::reportOrder([
        'parent_id'      => (int) $parent->id,
        'type'           => Status::ORDER_TYPE_RENEWAL,
        'status'         => Status::ORDER_PROCESSING,
        'payment_status' => Status::PAYMENT_SCHEDULED,
        'created_at'     => '2001-02-03 04:05:06',
    ]);
    $renewal->updateMeta('due_date', '2001-02-03 04:05:06');

    return [$subscription, $renewal];
};

return [
    [
        'id'            => 'automation-system-charge-retry-ladder',
        'name'          => 'Unavailable system gateway records retry and terminal exhaustion safely',
        'kind'          => 'behavior',
        'known_failure' => false,
        'phase'         => 14,
        'run'           => function () use ($makeSystemChargeFixture) {
            $disableNotify = '__return_false';
            $failedEvents = 0;
            $captureFailure = function ($data) use (&$failedEvents) {
                $failedEvents++;
            };
            add_filter(
                'fluent_cart/subscriptions/system_charge_failure_notify',
                $disableNotify,
                PHP_INT_MAX,
                2
            );
            add_action(
                'fluent_cart/subscriptions/system_charge_failed',
                $captureFailure,
                PHP_INT_MAX,
                1
            );

            try {
                [$subscription, $renewal] = $makeSystemChargeFixture('charge-retry');
                FcTest::assertSame(
                    null,
                    App::gateway('phase14-gateway-does-not-exist'),
                    'fixture payment-method slug resolves to no gateway'
                );

                FcTest::beginExpectedActionSchedulerCapture();
                (new SystemChargeService())->executeCharge((int) $renewal->id, 1);
                $scheduled = FcTest::consumeExpectedActionSchedulerAttempts();
                FcTest::assertSame(
                    [[
                        'operation' => 'schedule_single',
                        'hook'      => SystemChargeService::HOOK,
                    ]],
                    $scheduled,
                    'first failed attempt queues only its next retry'
                );

                $stored = Subscription::query()->find((int) $subscription->id);
                $state = $stored ? $stored->getMeta('system_charge_state', []) : [];
                FcTest::assertSame(1, (int) ($state['attempts'] ?? 0), 'retry state records attempt one');
                FcTest::assertSame(4, (int) ($state['max_attempts'] ?? 0), 'retry state records ladder size');
                FcTest::assert(
                    !empty($state['next_retry_at']) && empty($state['exhausted']),
                    'nonterminal failure records a future retry without exhaustion'
                );
                FcTest::assertSame(
                    Status::PAYMENT_PENDING,
                    (string) FcFixture::reloadOrder((int) $renewal->id)->payment_status,
                    'failed scheduled charge returns invoice to pending dunning'
                );
                FcTest::assertSame(1, $failedEvents, 'nonterminal failure hook dispatches once');

                (new SystemChargeService())->executeCharge((int) $renewal->id, 4);
                FcDomainFixture::captureSubscriptionMeta();
                $terminal = Subscription::query()->find((int) $subscription->id);
                $terminalState = $terminal
                    ? $terminal->getMeta('system_charge_state', [])
                    : [];
                FcTest::assertSame(4, (int) ($terminalState['attempts'] ?? 0), 'terminal state records final attempt');
                FcTest::assertSame('yes', $terminalState['exhausted'] ?? null, 'terminal state records exhaustion');
                FcTest::assert(
                    !isset($terminalState['next_retry_at']),
                    'terminal state contains no next retry'
                );
                FcTest::assertSame(2, $failedEvents, 'terminal failure dispatches one additional hook');
                FcTest::assertSame([], FcTest::sentMails(), 'system failure path sends no mail');
                FcTest::assertSame([], FcTest::externalCalls(), 'system failure path makes no loopback');
            } finally {
                remove_action(
                    'fluent_cart/subscriptions/system_charge_failed',
                    $captureFailure,
                    PHP_INT_MAX
                );
                remove_filter(
                    'fluent_cart/subscriptions/system_charge_failure_notify',
                    $disableNotify,
                    PHP_INT_MAX
                );
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];

<?php
/**
 * The product selector of the subscription report pages lists the variants that have subscriptions, grouped by
 * product; a variant whose only subscription never started, or is an installment plan, is left out.
 */

use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\Report\SubscriptionReportService;

return [
    [
        'id'            => 'reports-subscription-variations',
        'name'          => 'The subscription product selector lists variants that have started subscriptions',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            try {
                FcFixture::customer();
                $base = 1660000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 4;
                $order = FcFixture::reportOrder(['type' => Status::ORDER_TYPE_SUBSCRIPTION, 'status' => Status::ORDER_COMPLETED,
                    'payment_status' => Status::PAYMENT_PAID]);
                FcDomainFixture::subscription($order, $base, $base + 1, Status::SUBSCRIPTION_ACTIVE, 'variations-live');
                FcDomainFixture::subscription($order, $base + 2, $base + 3, Status::SUBSCRIPTION_INTENDED, 'variations-intended');
                // The subscription pages leave installment plans out; Installments has its own selector
                FcDomainFixture::subscription($order, $base + 4, $base + 5, Status::SUBSCRIPTION_ACTIVE, 'variations-installment', ['bill_times' => 4]);

                $ids = [];
                foreach (SubscriptionReportService::make()->subscriptionVariations() as $product) {
                    foreach ($product['variations'] as $variation) {
                        $ids[] = $variation['id'];
                    }
                }
                FcTest::assertSame([true, false, false], [in_array($base + 1, $ids, true), in_array($base + 3, $ids, true), in_array($base + 5, $ids, true)],
                    'The live subscription\'s variant is listed; the one that never started and the installment plan are not');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];

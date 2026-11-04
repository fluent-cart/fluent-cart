<?php
/**
 * A customer has one kind in a range: new when the range holds their first purchase, else returning. A first
 * purchase and a second one in the same range make a new customer only, on Locations as on Customers.
 */

use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\CustomerReportService;
use FluentCart\App\Services\Report\LocationReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcOnceCurrency = 'XTS';

return [
    [
        'id'            => 'reports-new-returning-once',
        'name'          => 'Locations counts a customer once, as new or returning, as the Customers report does',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC',
        'run'           => function () use ($fcOnceCurrency) {
            try {
                $foreign = (int) \FluentCart\App\App::db()->table('fct_orders')->where('currency', $fcOnceCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' orders already use currency ' . $fcOnceCurrency);
                }
                FcFixture::customer();
                // The first purchase and a second one, both in June
                foreach (['2025-06-03 10:00:00', '2025-06-10 10:00:00'] as $at) {
                    FcFixture::reportOrder([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcOnceCurrency,
                        'type' => Status::ORDER_TYPE_PAYMENT, 'subtotal' => 1000, 'tax_total' => 0, 'total_amount' => 1000,
                        'total_paid' => 1000, 'total_refund' => 0, 'created_at' => $at, 'updated_at' => $at,
                    ]);
                }

                $params = [
                    'startDate' => new DateTime('2025-06-01 00:00:00'), 'endDate' => new DateTime('2025-06-30 23:59:59'),
                    'currency' => $fcOnceCurrency, 'paymentStatus' => Status::getReportStatuses(), 'variationIds' => [],
                    'groupKey' => 'monthly', 'comparePeriod' => null,
                ];
                $customers = CustomerReportService::make()->getOverview($params)['summary'];
                $locations = LocationReportService::make()->getReport($params + ['level' => 'country'])['totals'];

                FcTest::assertSame(
                    [[1, 0], [1, 0]],
                    [
                        [$customers['new_customers'], $customers['returning_customers']],
                        [$locations['new_customers'] ?? -1, $locations['returning_customers'] ?? -1],
                    ],
                    'One new customer and no returning one on both reports'
                );
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];

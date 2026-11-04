<?php

namespace Tests\Support\Traits;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use Tests\Support\Factory\OrderFactory;

/**
 * Paid orders at known wall-clock times, for report date/time tests.
 *
 * Reports bucket by hour and anchor ranges to the first paid order, so the
 * fixtures have to sit at exact timestamps rather than "now". The dataset is one
 * launch order plus five on a single day at known hours and minutes, which is
 * what makes the hourly buckets and the since-launch anchor checkable.
 *
 * Builds through the factory, returns nothing, asserts nothing.
 */
trait SeedsDatedPaidOrders
{
    protected function createPaidOrderAt(int $customerId, string $createdAt): void
    {
        (new OrderFactory())->create([
            'customer_id'    => $customerId,
            'type'           => Status::ORDER_TYPE_PAYMENT,
            'payment_status' => Status::PAYMENT_PAID,
            'currency'       => 'USD',
            'total_amount'   => Helper::toCent(100),
            'total_paid'     => Helper::toCent(100),
            'created_at'     => $createdAt,
        ]);
    }

    /** The launch order plus five orders on 2025-04-10 at known hours/minutes. */
    protected function seedDatedPaidOrders(int $customerId): void
    {
        $this->createPaidOrderAt($customerId, '2025-04-01 00:00:00'); // launch (earliest)
        $this->createPaidOrderAt($customerId, '2025-04-10 08:00:00');
        $this->createPaidOrderAt($customerId, '2025-04-10 09:15:00');
        $this->createPaidOrderAt($customerId, '2025-04-10 09:45:00');
        $this->createPaidOrderAt($customerId, '2025-04-10 11:05:00');
        $this->createPaidOrderAt($customerId, '2025-04-10 12:00:00');
    }
}

<?php

namespace Tests\Support\Traits;

use FluentCart\App\Models\Customer;
use FluentCart\App\Services\CustomerIdentity\CustomerRecoveryService;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

trait RunsCustomerRecovery
{
    protected function createRecoverySources(Customer $target, int $count = 6): array
    {
        $sources = [];
        for ($i = 0; $i < $count; $i++) {
            $source = (new CustomerFactory())->create(['email' => $target->email, 'user_id' => null]);
            (new OrderFactory())->create(['customer_id' => $source->id, 'payment_status' => 'paid', 'total_paid' => 100]);
            $sources[] = $source;
        }
        return $sources;
    }

    protected function runNextRecoveryBatch(int $userId): array
    {
        $state = CustomerRecoveryService::progress($userId);
        $payload = ['user_id' => $userId, 'id' => $state['id']];
        // WP-Cron removes the due event before dispatching its hook.
        wp_clear_scheduled_hook(CustomerRecoveryService::HOOK, [$payload]);
        do_action(CustomerRecoveryService::HOOK, $payload);
        wp_cache_delete($userId, 'user_meta');
        return CustomerRecoveryService::progress($userId);
    }
}

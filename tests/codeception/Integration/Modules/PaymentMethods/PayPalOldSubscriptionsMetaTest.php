<?php

namespace Tests\Integration\Modules\PaymentMethods;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\SubscriptionMeta;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\SubscriptionManager;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Traits\BuildsSubscriptions;

class PayPalOldSubscriptionsMetaTest extends DatabaseTestCase
{
    use BuildsSubscriptions;

    private function buildPayPalSubscription()
    {
        [, , , $subscription] = $this->buildSubscriptionChain([
            'status'                 => Status::SUBSCRIPTION_ACTIVE,
            'current_payment_method' => 'paypal',
        ]);

        return $subscription;
    }

    private function storedOldSubscriptions($subscriptionId): array
    {
        $meta = SubscriptionMeta::query()
            ->where('subscription_id', $subscriptionId)
            ->where('meta_key', 'old_subscriptions')
            ->first();

        return (array)$meta->meta_value;
    }

    public function test_each_switch_appends_one_flat_entry()
    {
        $subscription = $this->buildPayPalSubscription();

        SubscriptionManager::addOldSubscriptionMeta($subscription, ['vendor_subscription_id' => 'I-OLD1']);
        SubscriptionManager::addOldSubscriptionMeta($subscription, ['vendor_subscription_id' => 'I-OLD2']);

        $stored = $this->storedOldSubscriptions($subscription->id);

        $this->assertSame([0, 1], array_keys($stored));
        $this->assertSame('I-OLD1', $stored[0]['vendor_subscription_id']);
        $this->assertSame('I-OLD2', $stored[1]['vendor_subscription_id']);
    }

    public function test_an_already_wrapped_row_keeps_only_its_list_entries()
    {
        $subscription = $this->buildPayPalSubscription();

        SubscriptionMeta::query()->create([
            'subscription_id' => $subscription->id,
            'meta_key'        => 'old_subscriptions',
            'meta_value'      => [
                "\0*\0attributes" => ['id' => 1, 'meta_value' => '[{"vendor_subscription_id":"I-BURIED"}]'],
                "\0*\0exists"     => true,
                0                 => ['vendor_subscription_id' => 'I-OLD1'],
            ],
        ]);

        SubscriptionManager::addOldSubscriptionMeta($subscription, ['vendor_subscription_id' => 'I-OLD2']);

        $stored = $this->storedOldSubscriptions($subscription->id);

        $this->assertSame([0, 1], array_keys($stored));
        $this->assertSame('I-OLD1', $stored[0]['vendor_subscription_id']);
        $this->assertSame('I-OLD2', $stored[1]['vendor_subscription_id']);
    }
}

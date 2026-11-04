<?php

namespace Tests\Support\Factory;

use FluentCart\App\Models\SubscriptionMeta;

class SubscriptionMetaFactory
{
    public function create(int $subscriptionId, string $metaKey, $metaValue): SubscriptionMeta
    {
        return SubscriptionMeta::query()->create([
            'subscription_id' => $subscriptionId,
            'meta_key'        => $metaKey,
            'meta_value'      => $metaValue,
        ]);
    }
}

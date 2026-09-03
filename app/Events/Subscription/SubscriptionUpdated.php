<?php

namespace FluentCart\App\Events\Subscription;

use FluentCart\App\Events\EventDispatcher;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;

class SubscriptionUpdated extends EventDispatcher
{
    public string $hook = 'fluent_cart/subscription_updated';
    protected array $listeners = [];

    public Subscription $subscription;
    public ?Order $order;
    public ?Customer $customer;
    public array $updates;
    public array $changes;

    public function __construct(Subscription $subscription, ?Order $order = null, ?Customer $customer = null, array $updates = [], array $changes = [])
    {
        $this->subscription = $subscription;
        $this->order = $order;
        $this->customer = $customer;
        $this->updates = $updates;
        $this->changes = $changes;
    }

    public function toArray(): array
    {
        // Superset payload — keeps the original `subscription`/`updates`/`changes` keys the bare
        // do_action('fluent_cart/subscription_updated') used, adds order/customer.
        return [
            'subscription' => $this->subscription,
            'updates'      => $this->updates,
            'changes'      => $this->changes,
            'order'        => $this->order,
            'customer'     => $this->customer ?? [],
        ];
    }

    public function getActivityEventModel(): Subscription
    {
        return $this->subscription;
    }

    public function shouldCreateActivity(): bool
    {
        // updateSubscription() already writes a detailed addLog() entry listing the
        // changed fields. Skipping the generic Activity row keeps the timeline unchanged.
        return false;
    }
}

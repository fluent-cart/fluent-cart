<?php

namespace FluentCart\App\Events\Subscription;

use FluentCart\App\Events\EventDispatcher;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;

class SubscriptionPaused extends EventDispatcher
{
    public string $hook = 'fluent_cart/subscription_paused';
    protected array $listeners = [];

    public Subscription $subscription;
    public ?Order $order;
    public ?Customer $customer;
    public ?string $oldStatus;
    public string $reason;

    public function __construct(Subscription $subscription, ?Order $order = null, ?Customer $customer = null, ?string $oldStatus = null, string $reason = '')
    {
        $this->subscription = $subscription;
        $this->order = $order;
        $this->customer = $customer;
        $this->oldStatus = $oldStatus;
        $this->reason = $reason;
    }

    public function toArray(): array
    {
        // Superset payload — keeps the original `subscription`/`reason` keys the bare
        // do_action('fluent_cart/subscription_paused') used, adds order/customer/old_status.
        return [
            'subscription' => $this->subscription,
            'reason'       => $this->reason,
            'order'        => $this->order,
            'customer'     => $this->customer ?? [],
            'old_status'   => $this->oldStatus,
        ];
    }

    public function getActivityEventModel(): Subscription
    {
        return $this->subscription;
    }

    public function shouldCreateActivity(): bool
    {
        // The transition sites already write a detailed addLog() entry (with the pause
        // reason). Skipping the generic Activity row keeps the timeline unchanged.
        return false;
    }
}

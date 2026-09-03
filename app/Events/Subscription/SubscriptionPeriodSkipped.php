<?php

namespace FluentCart\App\Events\Subscription;

use FluentCart\App\Events\EventDispatcher;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;

class SubscriptionPeriodSkipped extends EventDispatcher
{
    public string $hook = 'fluent_cart/subscription_period_skipped';
    protected array $listeners = [];

    public Subscription $subscription;
    public ?Order $order;
    public ?Customer $customer;
    public ?string $oldNextBillingDate;
    public ?string $newNextBillingDate;

    public function __construct(Subscription $subscription, ?Order $order = null, ?Customer $customer = null, ?string $oldNextBillingDate = null, ?string $newNextBillingDate = null)
    {
        $this->subscription = $subscription;
        $this->order = $order;
        $this->customer = $customer;
        $this->oldNextBillingDate = $oldNextBillingDate;
        $this->newNextBillingDate = $newNextBillingDate;
    }

    public function toArray(): array
    {
        // Brand-new tag — no prior contract to preserve.
        return [
            'subscription'          => $this->subscription,
            'order'                 => $this->order,
            'customer'              => $this->customer ?? [],
            'old_next_billing_date' => $this->oldNextBillingDate,
            'new_next_billing_date' => $this->newNextBillingDate,
        ];
    }

    public function getActivityEventModel(): Subscription
    {
        return $this->subscription;
    }

    public function shouldCreateActivity(): bool
    {
        // skipNextPeriod() already writes a detailed addLog() entry with the old and
        // new billing dates. Skipping the generic Activity row keeps the timeline unchanged.
        return false;
    }
}

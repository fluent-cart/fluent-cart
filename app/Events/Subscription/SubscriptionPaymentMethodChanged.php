<?php

namespace FluentCart\App\Events\Subscription;

use FluentCart\App\Events\EventDispatcher;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\Framework\Support\Arr;

class SubscriptionPaymentMethodChanged extends EventDispatcher
{
    public string $hook = 'fluent_cart/subscription_payment_method_changed';
    protected array $listeners = [];

    public Subscription $subscription;
    public ?Order $order;
    public ?Customer $customer;
    public array $oldPaymentMethod;
    public array $newPaymentMethod;
    public string $change;

    /**
     * @param array  $oldPaymentMethod billing info as stored in active_payment_method; at least ['method' => slug]
     * @param array  $newPaymentMethod billing info from PaymentHelper::parsePaymentMethodDetails()
     * @param string $change 'update' (same gateway) or 'switch' (another gateway)
     */
    public function __construct(Subscription $subscription, array $oldPaymentMethod, array $newPaymentMethod, string $change)
    {
        $this->subscription = $subscription;
        $this->order = $subscription->order;
        $this->customer = $this->order ? $this->order->customer : null;
        $this->oldPaymentMethod = $oldPaymentMethod;
        $this->newPaymentMethod = $newPaymentMethod;
        $this->change = $change;
    }

    public static function fire(Subscription $subscription, $oldPaymentMethod, array $newPaymentMethod, string $oldGateway)
    {
        $oldPaymentMethod = is_string($oldPaymentMethod) ? json_decode($oldPaymentMethod, true) : $oldPaymentMethod;
        if (!is_array($oldPaymentMethod) || !Arr::get($oldPaymentMethod, 'method')) {
            $oldPaymentMethod = ['method' => $oldGateway];
        }

        $change = ($oldGateway === Arr::get($newPaymentMethod, 'method')) ? 'update' : 'switch';

        (new static($subscription, $oldPaymentMethod, $newPaymentMethod, $change))->dispatch();
    }

    public function toArray(): array
    {
        return [
            'subscription'       => $this->subscription,
            'order'              => $this->order,
            'customer'           => $this->customer ?? [],
            'old_payment_method' => $this->oldPaymentMethod,
            'new_payment_method' => $this->newPaymentMethod,
            'change'             => $this->change,
        ];
    }

    public function getActivityEventModel(): Subscription
    {
        return $this->subscription;
    }

    public function shouldCreateActivity(): bool
    {
        return false;
    }
}

<?php

namespace FluentCart\App\Events\License;

use FluentCart\App\Events\EventDispatcher;
use FluentCart\App\Models\Order;
use FluentCartPro\App\Modules\Licensing\Models\License;

class LicenseRenewed extends EventDispatcher
{

    public string $hook = 'fluent_cart/license_renewed';
    protected array $listeners = [];

    /**
     * @var License
     */
    public License $license;

    /**
     * @var Order
     */
    public Order $order;

    public function __construct(Order $order, License $license)
    {
        $this->order = $order;
        $this->order->load('customer', 'subscriptions');
        $this->license = $license;
    }


    public function toArray(): array
    {
        return [
            'order'         => $this->order,
            'license'       => $this->license,
            'customer'      => $this->order->customer ?? [],
            'subscriptions' => $this->order->subscriptions ?? [],
        ];
    }

    public function getActivityEventModel()
    {
        return $this->license;
    }

    public function shouldCreateActivity(): bool
    {
        return true;

    }

}
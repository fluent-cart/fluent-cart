<?php

namespace FluentCart\App\Services;

use FluentCart\App\Helpers\CartHelper;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Collection;

class CheckoutService
{
    public array $onetime = [];
    public array $physicalItems = [];
    public array $digitalItems = [];
    public array $subscriptions = [];


    public int $count = 0;

    public function __construct($items = [])
    {
        if ((is_array($items) || $items instanceof Collection)) {
            $this->count = is_array($items) ? count($items) : $items->count();
            foreach ($items as $key => $item) {
                $paymentType = Arr::get($item, 'other_info.payment_type');
                if ($paymentType === 'subscription') {
                    $this->subscriptions[$key] = $item;
                } else {
                    $this->onetime[$key] = $item;
                }

                $isPhysical = Arr::get($item, 'fulfillment_type') === 'physical';
                if ($isPhysical) {
                    $this->physicalItems[$key] = $item;
                } else {
                    $this->digitalItems[$key] = $item;
                }
            }
        }
    }

    public function isAllPhysical(): bool
    {
        return $this->count === count($this->physicalItems);
    }

    public function isAllDigital(): bool
    {
        return $this->count === count($this->digitalItems);
    }

    public function hasDigitalProduct(): bool
    {
        return count($this->digitalItems) > 0;
    }

    public function hasPhysicalProduct(): bool
    {
        return count($this->physicalItems) > 0;
    }
}

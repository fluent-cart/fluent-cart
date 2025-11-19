<?php

namespace FluentCart\App\Services\CustomPayment;

use FluentCart\Framework\Support\Collection;

class PaymentIntent
{
    private $lineItems = [];

    private $customerEmail;


    /**
     * @return mixed
     */
    public function getCustomerEmail()
    {
        return $this->customerEmail;
    }

    /**
     * @param mixed $customerEmail
     */
    public function setCustomerEmail($customerEmail)
    {
        $this->customerEmail = $customerEmail;
        return $this;
    }


    public function setLineItems($lineItems)
    {
        $this->lineItems = $lineItems;
        return $this;
    }

    public function getLineItems()
    {
        return $this->lineItems;
    }


    public function toArray() : array{
        $lineItems = Collection::make($this->lineItems)->map(function($item){
            return $item->toArray();
        })->toArray();

        return [
            'line_items' => $lineItems,
            'customer_email' => $this->getCustomerEmail()
        ];
    }


}
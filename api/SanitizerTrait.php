<?php

namespace FluentCart\Api;

trait SanitizerTrait
{
    protected $rules = [];

    protected function sanitize($orderItem)
    {

        foreach ($this->rules as $key => $value) {
            if (isset($orderItem[$key])) {
                $orderItem[$key] = call_user_func($value, $orderItem[$key]);
            }
        }

        return $orderItem;
    }
}

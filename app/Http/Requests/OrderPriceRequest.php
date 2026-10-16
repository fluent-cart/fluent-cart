<?php

namespace FluentCart\App\Http\Requests;

class OrderPriceRequest extends OrderRequest
{
    /**
     * The Create Order screen prices lines before a customer is picked, and
     * applies a new coupon by posting its code with no id yet.
     *
     * @return array
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['customer_id'] = 'nullable|numeric';
        $rules['applied_coupon'] = 'nullable|array';
        $rules['applied_coupon.*.discount'] = 'nullable|numeric|min:0|max:9000000000000000';
        unset($rules['applied_coupon.*.id']);

        return $rules;
    }
}

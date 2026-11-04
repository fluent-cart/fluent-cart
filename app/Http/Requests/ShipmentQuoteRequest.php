<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\Framework\Foundation\RequestGuard;

class ShipmentQuoteRequest extends RequestGuard
{
    public function rules(): array
    {
        return [
            'carrier_slug'          => 'nullable|sanitizeText',
            'items'                 => 'required|array',
            'items.*'               => 'required|array',
            'items.*.order_item_id' => 'required|numeric|min:1',
            'items.*.qty'           => 'required|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'items.required'   => __('At least one item must be included.', 'fluent-cart'),
            'items.*.required' => __('At least one item must be included.', 'fluent-cart'),
        ];
    }

    /**
     * Sanitizes and whitelists keys for getSafe().
     */
    public function sanitize(): array
    {
        return [
            'carrier_slug'          => 'sanitize_key',
            'items.*.order_item_id' => 'intval',
            'items.*.qty'           => 'intval',
        ];
    }
}

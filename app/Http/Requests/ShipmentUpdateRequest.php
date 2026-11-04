<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\App\Models\Shipment;
use FluentCart\Framework\Foundation\RequestGuard;

class ShipmentUpdateRequest extends RequestGuard
{
    public function rules(): array
    {
        return [
            // Validated against the (filterable) lifecycle list rather than a
            // hardcoded set, so a carrier integration that registers an extra
            // shipment status stays valid here too.
            'status' => [
                'required',
                'knownStatus' => function ($attribute, $value) {
                    if (!array_key_exists(sanitize_key((string) $value), Shipment::getStatuses())) {
                        return __('The selected shipment status is not valid.', 'fluent-cart');
                    }
                    return null;
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => __('A shipment status is required.', 'fluent-cart'),
        ];
    }

    /**
     * Field => sanitizer map consumed by `$request->getSafe($request->sanitize())`,
     * which both sanitizes and whitelists keys — anything not listed here never
     * reaches the service.
     *
     * @return array
     */
    public function sanitize(): array
    {
        return [
            'status' => 'sanitize_key',
        ];
    }
}

<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\App\Services\ShipmentService;
use FluentCart\Framework\Foundation\RequestGuard;

class ShipmentCreateRequest extends RequestGuard
{
    public function rules(): array
    {
        return [
            'carrier_slug'          => 'nullable|sanitizeText',
            'carrier_name'          => 'nullable|sanitizeText',
            // Optional: a label carrier assigns it at dispatch, a manual shipment may have none.
            'tracking_number'       => 'nullable|sanitizeText',
            // No `sanitizeText` here: that rule REJECTS any value that
            // sanitize_text_field() would alter, and that function strips %XX
            // sequences — which real carrier tracking links routinely carry.
            // The validUrl closure below is the actual guard (it also rejects
            // javascript: and other non-http schemes).
            'tracking_url'          => [
                'nullable',
                'validUrl' => function ($attribute, $value) {
                    if (empty($value)) {
                        return null;
                    }
                    if (!filter_var($value, FILTER_VALIDATE_URL)) {
                        return __('Tracking URL must be a valid URL (e.g. https://example.com/track/ABC123).', 'fluent-cart');
                    }
                    $scheme = parse_url($value, PHP_URL_SCHEME);
                    if (!in_array($scheme, ['http', 'https'], true)) {
                        return __('Tracking URL must start with http:// or https://.', 'fluent-cart');
                    }
                    return null;
                },
            ],
            'items'                 => 'required|array',
            // Wildcard rules are data-driven: `items.*.order_item_id => required`
            // generates no rule at all for an element that omits the key, so the
            // element shape has to be asserted on its own.
            'items.*'               => 'required|array',
            'items.*.order_item_id' => 'required|numeric|min:1',
            // sanitize() runs intval, which would silently turn 1.5 into 1.
            'items.*.qty'           => [
                'required',
                'numeric',
                'min:0',
                'wholeNumber' => function ($attribute, $value) {
                    if (is_numeric($value) && floor((float) $value) != (float) $value) {
                        return __('Quantity must be a whole number.', 'fluent-cart');
                    }
                    return null;
                },
            ],
            // No `sanitizeTextArea`: it refuses any note that sanitizing would
            // change, such as "qty < 5". sanitize() cleans the note instead.
            'notes'                 => 'nullable|string|maxLength:5000',
            'notify_customer'       => 'nullable',
            'shipped_at'            => 'nullable|sanitizeText',
            'cost'                  => 'nullable|numeric|min:0',
            'cost_currency'         => 'nullable|sanitizeText',
            // The carrier rate the admin picked from the quote; opaque to us.
            'selected_rate_id'      => 'nullable|sanitizeText',
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
     * Field => sanitizer map consumed by `$request->getSafe($request->sanitize())`,
     * which both sanitizes and whitelists keys — anything not listed here never
     * reaches the service.
     *
     * @return array
     */
    public function sanitize(): array
    {
        return [
            'carrier_slug'          => 'sanitize_key',
            'carrier_name'          => 'sanitize_text_field',
            'tracking_number'       => 'sanitize_text_field',
            'tracking_url'          => 'esc_url_raw',
            'items.*.order_item_id' => 'intval',
            'items.*.qty'           => 'intval',
            // A closure: getSafe() reads an array as a list of sanitizers.
            'notes'                 => function ($value) {
                return ShipmentService::sanitizeNotes($value);
            },
            'notify_customer'       => function ($value) {
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            },
            'shipped_at'            => 'sanitize_text_field',
            'cost'                  => function ($value) {
                return is_numeric($value) ? $value : '';
            },
            'cost_currency'         => 'sanitize_text_field',
            'selected_rate_id'      => 'sanitize_text_field',
        ];
    }
}

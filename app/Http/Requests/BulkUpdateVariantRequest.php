<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\Framework\Foundation\RequestGuard;

class BulkUpdateVariantRequest extends RequestGuard
{
    /**
     * Hard cap on the number of variant rows a single bulk-update call may
     * modify. Matches AdvancedVariationService::DEFAULT_MAX_COMBINATIONS so a
     * caller cannot use the bulk endpoint to write more rows than the editor
     * can generate in the first place. Without this, an unauthenticated-yet-
     * capability-holding attacker could POST a 100K-element array and burn
     * memory + CPU even when every row eventually fails per-row sanitization.
     */
    const MAX_UPDATES_PER_REQUEST = 500;

    public function rules()
    {
        return [
            'updates' => 'required|array',
        ];
    }

    public function messages()
    {
        return [
            'updates.required' => esc_html__('At least one variant update is required.', 'fluent-cart'),
            'updates.array'    => esc_html__('Updates payload must be an array.', 'fluent-cart'),
        ];
    }

    public function sanitize()
    {
        return [
            // Per-row sanitization (id cast, price->cents, allowlist statuses) lives
            // in ProductVariationController::bulkUpdate where the conditional shape
            // logic belongs. This sanitize() only caps the outer array size — the
            // hard guard against the attack surface where a caller floods the
            // endpoint with millions of rows before any per-row check fires.
            'updates' => function ($value) {
                if (!is_array($value)) {
                    return [];
                }
                return array_slice($value, 0, self::MAX_UPDATES_PER_REQUEST);
            },
        ];
    }
}

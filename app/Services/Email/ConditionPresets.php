<?php

namespace FluentCart\App\Services\Email;

use FluentCart\Framework\Support\Arr;

class ConditionPresets
{
    /**
     * Get all condition presets.
     *
     * Each preset has: id, label, shortcode, condition, compareValue
     *
     * @return array
     */
    public static function all()
    {
        $presets = [
            // Order Type
            [
                'id'           => 'is_digital_order',
                'label'        => __('Is Digital Order', 'fluent-cart'),
                'hint'         => __('Show when the order contains only digital products.', 'fluent-cart'),
                'shortcode'    => '{{order.is_digital}}',
                'condition'    => 'equal',
                'compareValue' => 'yes',
            ],
            [
                'id'           => 'is_physical_order',
                'label'        => __('Is Physical Order', 'fluent-cart'),
                'hint'         => __('Show when the order requires physical fulfillment.', 'fluent-cart'),
                'shortcode'    => '{{order.is_digital}}',
                'condition'    => 'equal',
                'compareValue' => 'no',
            ],

            // Payment Status
            [
                'id'           => 'is_order_paid',
                'label'        => __('Is Order Paid', 'fluent-cart'),
                'hint'         => __('Show when payment has been received.', 'fluent-cart'),
                'shortcode'    => '{{order.payment_status}}',
                'condition'    => 'equal',
                'compareValue' => 'paid',
            ],
            [
                'id'           => 'is_order_pending',
                'label'        => __('Is Order Pending', 'fluent-cart'),
                'hint'         => __('Show when payment is still pending.', 'fluent-cart'),
                'shortcode'    => '{{order.payment_status}}',
                'condition'    => 'equal',
                'compareValue' => 'pending',
            ],
            [
                'id'           => 'is_order_failed',
                'label'        => __('Is Order Failed', 'fluent-cart'),
                'hint'         => __('Show when payment has failed.', 'fluent-cart'),
                'shortcode'    => '{{order.payment_status}}',
                'condition'    => 'equal',
                'compareValue' => 'failed',
            ],

            // Financials
            [
                'id'           => 'has_discount',
                'label'        => __('Has Discount', 'fluent-cart'),
                'hint'         => __('Show when a coupon or manual discount was applied.', 'fluent-cart'),
                'shortcode'    => '{{order.discount_total}}',
                'condition'    => 'greater_than',
                'compareValue' => '0',
            ],
            [
                'id'           => 'has_shipping',
                'label'        => __('Has Shipping', 'fluent-cart'),
                'hint'         => __('Show when shipping charges are present.', 'fluent-cart'),
                'shortcode'    => '{{order.shipping_total}}',
                'condition'    => 'greater_than',
                'compareValue' => '0',
            ],
            [
                'id'           => 'has_tax',
                'label'        => __('Has Tax', 'fluent-cart'),
                'hint'         => __('Show when the order includes tax.', 'fluent-cart'),
                'shortcode'    => '{{order.tax_total}}',
                'condition'    => 'greater_than',
                'compareValue' => '0',
            ],
            [
                'id'           => 'has_refund',
                'label'        => __('Has Refund', 'fluent-cart'),
                'hint'         => __('Show when a refund has been issued.', 'fluent-cart'),
                'shortcode'    => '{{order.total_refund}}',
                'condition'    => 'greater_than',
                'compareValue' => '0',
            ],
            [
                'id'           => 'has_shipping_tax',
                'label'        => __('Has Shipping Tax', 'fluent-cart'),
                'hint'         => __('Show when shipping tax is charged.', 'fluent-cart'),
                'shortcode'    => '{{order.shipping_tax}}',
                'condition'    => 'greater_than',
                'compareValue' => '0',
            ],

            // Content
            [
                'id'           => 'has_order_note',
                'label'        => __('Has Order Note', 'fluent-cart'),
                'hint'         => __('Show when the customer left an order note.', 'fluent-cart'),
                'shortcode'    => '{{order.note}}',
                'condition'    => 'not_empty',
                'compareValue' => '',
            ],
            [
                'id'           => 'has_downloads',
                'label'        => __('Has Downloads', 'fluent-cart'),
                'hint'         => __('Show when downloadable files are attached.', 'fluent-cart'),
                'shortcode'    => '{{order.downloads}}',
                'condition'    => 'not_empty',
                'compareValue' => '',
            ],
        ];

        return apply_filters('fluent_cart/condition_presets', $presets);
    }

    /**
     * Get presets formatted for JS localization (id + label only).
     *
     * @return array
     */
    public static function forJs()
    {
        return array_map(function ($preset) {
            return [
                'id'    => Arr::get($preset, 'id'),
                'label' => Arr::get($preset, 'label'),
                'hint'  => Arr::get($preset, 'hint', ''),
            ];
        }, static::all());
    }

    /**
     * Find a preset by its identifier.
     *
     * @param string $id
     * @return array|null
     */
    public static function find($id)
    {
        foreach (static::all() as $preset) {
            if (Arr::get($preset, 'id') === $id) {
                return $preset;
            }
        }

        return null;
    }

    /**
     * Resolve a preset ID to its evaluation parameters.
     *
     * Returns one of three shapes:
     *  - callback present:  ['type' => 'callback', 'callback' => callable, 'presetId' => string]
     *  - shortcode present: ['type' => 'shortcode', 'shortcode' => string, 'condition' => string, 'compareValue' => string]
     *  - neither:           ['type' => 'filter', 'presetId' => string]
     *
     * @param string $presetId
     * @return array|null
     */
    public static function resolve($presetId)
    {
        $preset = static::find($presetId);

        if (!$preset) {
            return null;
        }

        $callback = Arr::get($preset, 'callback');

        if ($callback && is_callable($callback)) {
            return [
                'callback' => $callback,
            ];
        }

        return [
            'shortcode'    => Arr::get($preset, 'shortcode', ''),
            'condition'    => Arr::get($preset, 'condition', 'not_empty'),
            'compareValue' => Arr::get($preset, 'compareValue', ''),
        ];
    }
}

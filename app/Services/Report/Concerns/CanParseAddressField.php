<?php

namespace FluentCart\App\Services\Report\Concerns;

use FluentCart\App\Services\Report\ReportService;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\DateTime;

trait CanParseAddressField
{

    /**
     * Parse Address value from given data
     *
     * @param mixed $item
     * @param string $groupKey The name of the group
     * @return string
     */
    protected function parseAddressField($item, string $groupKey): string
    {
        $allowedKeys = [
            'shipping_country',
            'billing_country',
            'shipping_city',
            'billing_city',
            'shipping_state',
            'billing_state'
        ];

        if (!in_array($groupKey, $allowedKeys)) {
            return __('Unknown', 'fluent-cart');
        }

        list($addressType, $field) = explode('_', $groupKey);
        // Check if order_addresses exist
        if (Arr::exists($item, 'order_addresses')) {
            foreach ($item['order_addresses'] as $address) {
                // Check if the address type matches (billing or shipping)
                if (Arr::get($address, 'type') === $addressType) {
                    // Return the requested field if it exists, otherwise return 'Unknown'
                    return Arr::get($address, $field, __('Unknown', 'fluent-cart'));
                }
            }
        }

        return __('Unknown', 'fluent-cart'); // Fallback if no matching address or field found
    }
}
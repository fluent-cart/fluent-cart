<?php

namespace FluentCart\App\Modules\Shipping\Carriers\Concerns;

/**
 * Building blocks for getSetupNotices(). Runs on every carriers-list load, so
 * everything here reads stored settings only and never calls the carrier.
 *
 * Notice shape: ['type' => 'warning'|'error'|'info', 'message' => string,
 * 'action' => ['label' => string, 'route' => admin SPA route]|null].
 */
trait ProvidesSetupNotices
{
    abstract protected function buildOrigin(): array;

    /**
     * @return string[] Plain-word names of the store address fields rates and labels need.
     */
    protected function missingOriginFields(): array
    {
        $origin = $this->buildOrigin();
        $labels = [
            'address1' => __('street address', 'fluent-cart'),
            'city'     => __('city', 'fluent-cart'),
            'postcode' => __('postal code', 'fluent-cart'),
            'country'  => __('country', 'fluent-cart'),
        ];

        $missing = [];
        foreach ($labels as $key => $label) {
            if (trim((string) (isset($origin[$key]) ? $origin[$key] : '')) === '') {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    protected function originSetupNotice(string $carrierName): ?array
    {
        $missing = $this->missingOriginFields();
        if (!$missing) {
            return null;
        }

        return [
            'type'    => 'warning',
            'message' => sprintf(
                /* translators: %1$s: carrier name e.g. Shippo, %2$s: comma-separated list of missing address fields */
                __('%1$s ships from your store address, which is incomplete. Rates and labels will fail until you add: %2$s.', 'fluent-cart'),
                $carrierName,
                implode(', ', $missing)
            ),
            'action'  => [
                'label' => __('Open Store Settings', 'fluent-cart'),
                'route' => 'settings/store-settings',
            ],
        ];
    }

    protected function setupNotice(string $type, string $message): array
    {
        return ['type' => $type, 'message' => $message, 'action' => null];
    }
}

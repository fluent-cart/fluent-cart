<?php

namespace FluentCart\App\Modules\Shipping\Carriers\Core;

use FluentCart\App\Models\Shipment;
use FluentCart\App\Modules\Shipping\Carriers\Contracts\CarrierContract;
use FluentCart\App\Services\Shipments\ShipmentInstance;

abstract class AbstractShippingCarrier implements CarrierContract
{
    protected BaseCarrierSettings $settings;

    public function __construct(BaseCarrierSettings $settings)
    {
        $this->settings = $settings;
    }

    public function getSettings(): BaseCarrierSettings
    {
        return $this->settings;
    }

    public function isEnabled(): bool
    {
        return $this->settings->get('is_active') === 'yes';
    }

    public function getRates(array $args): array
    {
        return [];
    }

    /**
     * @return array|\WP_Error Label data on success, WP_Error when unsupported or failed.
     */
    public function createLabel(array $shipment)
    {
        return new \WP_Error('not_supported', __('Label generation is not supported by this carrier.', 'fluent-cart'));
    }

    public function getTrackingUrl(string $trackingNumber): string
    {
        return '';
    }

    public function getTrackingInfo(string $trackingNumber): array
    {
        return [];
    }

    /**
     * @return array|\WP_Error Normalized settings, or WP_Error to reject the save.
     */
    public function validateSettings(array $settings)
    {
        return $settings;
    }

    public function boot(): void
    {
    }

    /**
     * Runs a settings-page action (a status button) right away, without a save.
     * Not on CarrierContract, so direct implementers keep working.
     *
     * @return array|\WP_Error ['message' => string, 'ok' => bool]; the page reloads the carrier after.
     */
    public function runAction(string $action, array $data)
    {
        return new \WP_Error('unknown_action', __('This carrier has no such action.', 'fluent-cart'));
    }

    public function getWebhookUrl(): string
    {
        return '';
    }

    public function handleWebhook(): void
    {
    }

    public function supportsTrackingSync(): bool
    {
        return false;
    }

    /**
     * Whether tracking can be pulled for a shipment without a label this carrier
     * bought, e.g. by tracking number alone. Off by default.
     */
    public function canTrackWithoutLabel(Shipment $shipment): bool
    {
        return false;
    }

    /**
     * @return bool|\WP_Error|null Null means the carrier cannot sync.
     */
    public function syncTrackingFromShipmentInstance(ShipmentInstance $instance)
    {
        return null;
    }

    /**
     * Null means "does not dispatch": the admin's own tracking data is used.
     *
     * @return array|\WP_Error|null
     */
    public function makeShipmentFromShipmentInstance(ShipmentInstance $instance)
    {
        return null;
    }

    /**
     * @return \WP_Error|bool|null Null means there was nothing to void.
     */
    public function cancelShipmentFromShipmentInstance(ShipmentInstance $instance)
    {
        return null;
    }

    /**
     * @return array|\WP_Error|null Null means the carrier does not quote.
     */
    public function getShipmentQuote(ShipmentInstance $instance)
    {
        return null;
    }

    /**
     * Whether this carrier bought a label for the shipment that getLabelDownloadUrl()
     * can fetch. Read on every shipment list, so it must not call the carrier.
     * Not on CarrierContract, so direct implementers keep working.
     */
    public function hasLabel(Shipment $shipment): bool
    {
        return false;
    }

    /**
     * A current link to the shipment's label PDF. Carrier links expire, so core
     * asks for one each time the label is printed and stores no PDF.
     *
     * @return string|\WP_Error '' when the carrier has no label for the shipment.
     */
    public function getLabelDownloadUrl(Shipment $shipment)
    {
        return '';
    }

    /**
     * Problems the merchant must fix before this carrier can work, shown on the
     * carrier settings pages. Not on CarrierContract, so direct implementers keep working.
     *
     * @return array[] Each: type ('warning'|'error'|'info'), message (plain text),
     *                 action (['label' => string, 'route' => admin hash route]|null).
     */
    public function getSetupNotices(): array
    {
        return [];
    }

    /**
     * Whether checkout rates can come back in $currency (ISO code). Null =
     * undeclared: core converts whatever currency the rates come back in.
     * Declaring opts into core's carrier currency policy (05-live-rates.md).
     * Not on CarrierContract, so direct implementers keep working.
     */
    public function canQuoteInCurrency(string $currency): ?bool
    {
        return null;
    }

    /**
     * Minutes this carrier's quoted rates may be reused, at checkout and at
     * Place Order. Whole minutes, 1 to 1440. Not on CarrierContract, so direct
     * implementers keep working.
     */
    public function getRateCacheMinutes(): int
    {
        $default = $this->defaultRateCacheMinutes();
        $meta    = $this->meta();

        /**
         * How long a carrier's quoted shipping rates may be reused, in minutes.
         *
         * @param int   $minutes The carrier's default (10 unless it overrides it).
         * @param array $context ['carrier' => slug, 'carrier_instance' => AbstractShippingCarrier]
         */
        $minutes = apply_filters('fluent_cart/shipping/carrier_rate_cache_minutes', $default, [
            'carrier'          => isset($meta['slug']) ? (string) $meta['slug'] : '',
            'carrier_instance' => $this,
        ]);

        if (!is_numeric($minutes)) {
            $minutes = $default;
        }

        return max(1, min(1440, (int) $minutes));
    }

    /**
     * Override to change this carrier's default; getRateCacheMinutes() still
     * runs it through the filter.
     */
    protected function defaultRateCacheMinutes(): int
    {
        return 10;
    }

    public function supports(string $feature): bool
    {
        $meta = $this->meta();
        $supports = isset($meta['supports']) ? $meta['supports'] : [];
        return in_array($feature, $supports, true);
    }
}

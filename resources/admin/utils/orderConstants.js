/**
 * Mirror of the PHP constants — keep in sync:
 *   FULFILLMENT_TYPE_PHYSICAL   ↔ FluentCart\App\Helpers\Status::FULFILLMENT_TYPE_PHYSICAL
 *   SHIPPING_UNSHIPPABLE        ↔ FluentCart\App\Helpers\Status::SHIPPING_UNSHIPPABLE
 *   CARRIER_MANUAL              ↔ FluentCart\App\Models\Shipment::CARRIER_MANUAL
 *   SHIPMENT_DISPATCHED         ↔ FluentCart\App\Models\Shipment::STATUS_DISPATCHED
 *   SHIPMENT_IN_TRANSIT         ↔ FluentCart\App\Models\Shipment::STATUS_IN_TRANSIT
 *   SHIPMENT_DELIVERED          ↔ FluentCart\App\Models\Shipment::STATUS_DELIVERED
 *   SHIPMENT_RETURNED           ↔ FluentCart\App\Models\Shipment::STATUS_RETURNED
 *   SHIPMENT_FAILED             ↔ FluentCart\App\Models\Shipment::STATUS_FAILED
 */
export const FULFILLMENT_TYPE_PHYSICAL = 'physical';
export const SHIPPING_UNSHIPPABLE = 'unshippable';
export const SHIPPING_DELIVERED = 'delivered';
export const CARRIER_MANUAL = 'manual';
export const SHIPMENT_DISPATCHED = 'dispatched';
export const SHIPMENT_IN_TRANSIT = 'in_transit';
export const SHIPMENT_DELIVERED = 'delivered';
export const SHIPMENT_RETURNED = 'returned';
export const SHIPMENT_FAILED = 'failed';

/**
 * Every status a single shipment can hold, in lifecycle order.
 *
 * The labelled map lives on `appVars.shipment_statuses` (localized, translated by
 * PHP) and is the source the UI renders from. This list is only the fallback for
 * an admin bundle running against a build that predates that localization — the
 * slugs are still the same, so the dropdown keeps working with headline-cased
 * labels instead of silently offering nothing.
 */
export const SHIPMENT_STATUSES = [SHIPMENT_DISPATCHED, SHIPMENT_IN_TRANSIT, SHIPMENT_DELIVERED, SHIPMENT_RETURNED, SHIPMENT_FAILED];

/**
 * Statuses an admin set deliberately, which fulfilment must never derive over.
 * Mirrors ShipmentService::isTerminalShippingStatus() — the API rejects shipment
 * create/delete against these, so the UI must not offer the affordance either.
 */
export const TERMINAL_SHIPPING_STATUSES = [SHIPPING_UNSHIPPABLE, SHIPPING_DELIVERED];

<?php

namespace FluentCart\App\Modules\Shipping\Carriers\Contracts;

use FluentCart\App\Modules\Shipping\Carriers\Core\BaseCarrierSettings;
use FluentCart\App\Services\Shipments\ShipmentInstance;

interface CarrierContract
{
    public function meta(): array;

    /**
     * Declares the carrier's settings form.
     *
     * Each field is ['key' => , 'type' => , 'label' => , 'hint' => ]. Only
     * declared keys are ever accepted on save or returned by the API — a carrier
     * may persist undeclared keys (OAuth tokens, webhook secrets) and they will
     * simply never appear in a response.
     *
     * A 'password' field is returned masked as ''; an empty value on save means
     * "unchanged". Mark a secret carried under any other type with
     * 'is_secret' => true to get the same treatment.
     *
     * A 'mode' field stores one of its options' 'value's and renders them as
     * radio cards: 'options' => [['value' =>, 'label' =>, 'hint' =>, 'fields' => [...]]].
     * Each option's fields show only while it is selected and are saved and
     * masked like top-level fields. One level only; keys must be unique overall.
     */
    public function fields(): array;

    public function isEnabled(): bool;

    public function getSettings(): BaseCarrierSettings;

    /**
     * This carrier's rates for a destination. Every carrier returns the same shape:
     *
     *   [
     *     'id'               => string|int, // unique; non-numeric for API carriers, e.g. 'shippo:usps_priority'
     *     'carrier'          => string,     // carrier slug (core sets it)
     *     'title'            => string,
     *     'cost'             => int,        // cents in 'currency'
     *     'currency'         => string,     // any ISO code; core converts to the checkout currency
     *     'states'           => array,      // state codes the rate is limited to; [] = all
     *     'description'      => string,     // optional
     *     'estimated_days'   => int,        // optional; 0 when unknown
     *     'provider_rate_id' => string,     // optional; the carrier's own rate id, kept on the order
     *   ]
     *
     * Core converts each rate once into the checkout currency and drops a rate whose
     * currency it has no exchange rate for, or that has no currency. A carrier
     * extending AbstractShippingCarrier may declare canQuoteInCurrency() to have
     * core show only rates in a currency it quotes. A live carrier should cache
     * its own API responses.
     *
     * @param array $args ['country' => code, 'state' => code|null, 'postcode' => string,
     *                    'city' => string, 'name' => string, 'address_1' => string,
     *                    'phone' => string (all '' until the checkout contact is complete,
     *                    see AddressHelper::quoteContact()), 'items' => cart line items,
     *                    'form_data' => the checkout form, scalar fields as plain text
     *                    (AddressHelper::quoteFormData(); [] when none, since 2.4.0),
     *                    'cart' => the visitor's Cart, or null (since 2.4.0): read it for
     *                    any detail; 'items' and the address are what is priced, and a
     *                    saved field may lag the form until checkout saves it,
     *                    'currency' => ISO to quote in, 'base_currency' => store ISO,
     *                    'reuse_quote' => true at Place Order: answer from the last quote
     *                    for these args when one is still fresh, else quote live,
     *                    'defer_sources' => true at checkout, 'fetch_source' => source id:
     *                    see ProvidesLiveRates::liveRateSources() (progressive rates)]
     * @return array[]
     */
    public function getRates(array $args): array;

    /**
     * @return array|\WP_Error Label data on success, WP_Error when unsupported or failed.
     */
    public function createLabel(array $shipment);

    public function getTrackingUrl(string $trackingNumber): string;

    public function getTrackingInfo(string $trackingNumber): array;

    /**
     * Validates (and may normalize) settings before they are persisted.
     *
     * @return array|\WP_Error Normalized settings, or WP_Error to reject the
     *                         save (surfaced to the admin as a 422).
     */
    public function validateSettings(array $settings);

    public function boot(): void;

    /**
     * The URL the merchant registers with the carrier, secret included, or ''
     * when the carrier takes no webhooks. A carrier may add meta()['webhook_hint']
     * to tell the merchant where to paste it.
     */
    public function getWebhookUrl(): string;

    /**
     * Handles an inbound carrier webhook. The carrier authenticates the request,
     * reads the raw payload and responds itself; a throw becomes a 500.
     */
    public function handleWebhook(): void;

    /**
     * Whether the carrier can pull a shipment's tracking status on demand.
     */
    public function supportsTrackingSync(): bool;

    /**
     * Pulls the shipment's ($instance->shipment) tracking status and applies it
     * through ShipmentService::applyTrackingUpdate().
     *
     * @return bool|\WP_Error|null True when a new status was applied, false when
     *                             there was nothing to apply, WP_Error on API
     *                             failure, null when the carrier cannot sync.
     */
    public function syncTrackingFromShipmentInstance(ShipmentInstance $instance);

    /**
     * Buys the label for a shipment. Called before the shipment row is written
     * and outside any transaction, so a refusal leaves nothing behind.
     *
     * The array keys core keeps: tracking_number, tracking_url, service_name,
     * label_url, transaction_id (the label handle used to void it and fetch it
     * again), cost (int cents) and currency (the label cost's own ISO code).
     *
     * @return array|\WP_Error|null Array when a label was bought, WP_Error to abort
     *                              the create, null when the carrier does not dispatch.
     */
    public function makeShipmentFromShipmentInstance(ShipmentInstance $instance);

    /**
     * Voids a bought label. The label handle is always on
     * $instance->data['transaction_id']; on delete $instance->shipment is the
     * already-deleted row as it was last stored.
     *
     * @return \WP_Error|bool|null True when voided, WP_Error when the carrier refused,
     *                             null when there was nothing to void.
     */
    public function cancelShipmentFromShipmentInstance(ShipmentInstance $instance);

    /**
     * Prices a shipment without buying anything:
     *   ['rate_object_id' => preselected id, 'rates' => [[
     *       'rate_object_id' => string, 'service_name' => string,
     *       'cost_cents' => int, 'currency' => ISO code, 'estimated_days' => int,
     *   ], ...]]
     *
     * @return array|\WP_Error|null Null when the carrier does not quote.
     */
    public function getShipmentQuote(ShipmentInstance $instance);
}

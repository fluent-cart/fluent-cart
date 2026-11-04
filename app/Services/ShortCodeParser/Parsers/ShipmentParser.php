<?php

namespace FluentCart\App\Services\ShortCodeParser\Parsers;

use FluentCart\App\App;
use FluentCart\App\Models\Shipment;
use FluentCart\App\Services\DateTime\DateFormatter;
use FluentCart\Framework\Support\Arr;

/**
 * {{shipment.*}} — the shipment an order_shipment_created email is about.
 * Every code is empty when no shipment is in the payload (preview, test send).
 */
class ShipmentParser extends BaseParser
{
    /** @var Shipment|null */
    private $shipment;

    /** Timezone context for DateFormatter. */
    private $order;

    public function __construct($data)
    {
        $this->shipment = $this->toModel(Arr::get($data, 'shipment'));
        $this->order    = Arr::get($data, 'order');
        parent::__construct($data);
    }

    public function parse($accessor = null, $code = null, $transformer = null): ?string
    {
        $shipment = $this->shipment;

        if (!$shipment) {
            return '';
        }

        switch ($accessor) {
            case 'id':
                return (string) $shipment->id;
            case 'carrier_name':
                return esc_html((string) $shipment->carrier_name);
            case 'service_name':
                return esc_html((string) Arr::get((array) $shipment->meta, 'service_name', ''));
            case 'tracking_number':
                return esc_html((string) $shipment->tracking_number);
            case 'tracking_url':
                return $shipment->tracking_url ? esc_url((string) $shipment->tracking_url) : '';
            case 'tracking_link':
                return $this->getTrackingLink();
            case 'status':
                return $this->getStatusLabel();
            case 'shipped_at':
                return esc_html(DateFormatter::format($shipment->shipped_at, false, $this->order));
            case 'notes':
                return $shipment->notes ? nl2br(esc_html((string) $shipment->notes)) : '';
            case 'item_count':
                return (string) count($this->getItems());
            case 'items':
                return $this->getItemsHtml();
            default:
                return '';
        }
    }

    /**
     * The tracking number linked to the tracking URL; the bare number or URL
     * when only one of them exists.
     */
    private function getTrackingLink(): string
    {
        $number = trim((string) $this->shipment->tracking_number);
        $url    = esc_url(trim((string) $this->shipment->tracking_url));

        if ($url === '') {
            return esc_html($number);
        }

        $label = $number !== '' ? $number : $url;

        return '<a href="' . $url . '" style="color:#017EF3;text-decoration:none;" target="_blank">'
            . esc_html($label)
            . '</a>';
    }

    private function getStatusLabel(): string
    {
        $status   = (string) $this->shipment->status;
        $statuses = Shipment::getStatuses();

        return esc_html(isset($statuses[$status]) ? $statuses[$status] : $status);
    }

    private function getItems(): array
    {
        $items = $this->shipment->items;

        return is_array($items) ? $items : [];
    }

    private function getItemsHtml(): string
    {
        $items = $this->getItems();
        if (empty($items)) {
            return '';
        }

        return (string) App::make('view')->make('emails.parts.shipment_items', [
            'items' => $items,
        ]);
    }

    /**
     * The mailer passes the model; a caller may pass its array form instead.
     */
    private function toModel($shipment)
    {
        if ($shipment instanceof Shipment) {
            return $shipment;
        }

        if (!is_array($shipment) || !$shipment) {
            return null;
        }

        // Only what the codes read: serialized timestamps do not round-trip.
        $model = new Shipment();
        $model->forceFill(Arr::only($shipment, [
            'id', 'order_id', 'carrier_slug', 'carrier_name', 'tracking_number', 'tracking_url',
            'items', 'status', 'notes', 'meta', 'shipped_at',
        ]));

        return $model;
    }
}

<?php

namespace FluentCart\App\Models;

use FluentCart\App\Services\ShipmentService;

/**
 * @property int $order_id
 * @property string $status
 * @property array|null $meta
 */
class Shipment extends Model
{
    const STATUS_DISPATCHED = 'dispatched';
    const STATUS_IN_TRANSIT = 'in_transit';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_RETURNED = 'returned';
    const STATUS_FAILED = 'failed';

    const CARRIER_MANUAL = 'manual';

    protected $table = 'fct_shipments';

    /**
     * `order_id`, `status` and `meta` are deliberately excluded — they are trust
     * fields (ownership, state machine, server-built payload) and must be assigned
     * explicitly by the service, never mass-assigned from request input.
     */
    protected $fillable = [
        'carrier_slug',
        'carrier_name',
        'tracking_number',
        'tracking_url',
        'items',
        'notes',
        'notify_customer',
        'shipped_at',
    ];

    protected $casts = [
        'items'           => 'array',
        'notify_customer' => 'boolean',
        'meta'            => 'array',
    ];

    /**
     * The shipment lifecycle, slug => label. `delivered` is the only status the
     * order's own `delivered` shipping status is derived from — see
     * ShipmentService::deriveFulfillmentStatus(). `returned` and `failed` come from
     * carrier tracking or the admin dropdown.
     *
     * @return array
     */
    public static function getStatuses()
    {
        return apply_filters('fluent_cart/shipment_statuses', [
            self::STATUS_DISPATCHED => __('Dispatched', 'fluent-cart'),
            self::STATUS_IN_TRANSIT => __('In Transit', 'fluent-cart'),
            self::STATUS_DELIVERED  => _x('Delivered', 'Shipment status', 'fluent-cart'),
            self::STATUS_RETURNED   => __('Returned', 'fluent-cart'),
            self::STATUS_FAILED     => _x('Failed', 'Shipment status', 'fluent-cart'),
        ], []);
    }

    /**
     * Statuses carrier tracking can never move a shipment out of. An admin can
     * still correct them by hand.
     *
     * @return array
     */
    public static function terminalStatuses()
    {
        return [
            self::STATUS_DELIVERED,
            self::STATUS_RETURNED,
        ];
    }

    /**
     * Opt in per query with addAppends(['can_refresh_tracking']).
     *
     * @return bool
     */
    public function getCanRefreshTrackingAttribute()
    {
        return ShipmentService::canRefreshTracking($this);
    }

    /**
     * has_label says the label route can fetch this shipment's label from its carrier.
     */
    public function toArray()
    {
        $data = parent::toArray();

        $data['has_label'] = ShipmentService::hasLabel($this);
        // Dev databases from before labels were fetched on demand may still hold a PDF.
        if (isset($data['meta']) && is_array($data['meta'])) {
            unset($data['meta']['label_pdf_base64']);
        }

        return $data;
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }
}

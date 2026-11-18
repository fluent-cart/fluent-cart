<?php

namespace FluentCart\App\Services;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\App;
use FluentCart\App\Events\Order\OrderStatusUpdated;
use FluentCart\App\Helpers\CurrenciesHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\Shipment;
use FluentCart\App\Modules\MultiCurrency\Services\BaseCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySnapshot;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use FluentCart\App\Modules\MultiCurrency\Services\PriceConverter;
use FluentCart\App\Modules\MultiCurrency\Services\RoundingRules;
use FluentCart\App\Modules\Shipping\Carriers\Contracts\CarrierContract;
use FluentCart\App\Modules\Shipping\Carriers\Core\AbstractShippingCarrier;
use FluentCart\App\Modules\Shipping\Carriers\Core\CarrierManager;
use FluentCart\App\Modules\Shipping\Carriers\Manual\ManualCarrier;
use FluentCart\App\Modules\StockManagement\StockManagement;
use FluentCart\App\Services\Shipments\ShipmentInstance;
use FluentCart\Framework\Support\Arr;

class ShipmentService
{
    /** Shipment meta order_currency_rate_source: the order's frozen exchange rate. */
    const ESTIMATE_SOURCE_ORDER = 'order_snapshot';

    /** Shipment meta order_currency_rate_source: a store rate current at the time. */
    const ESTIMATE_SOURCE_STORE = 'store_rate';

    /** Order meta: [attempt id => create request + id, carrier, at] for label purchases the carrier has not confirmed. */
    const UNCONFIRMED_REQUESTS_META = '_fct_unconfirmed_shipment_requests';

    /** Seconds a purchase (with its label download) may still be running; Mark resolved waits this long. */
    const UNCONFIRMED_SETTLE_SECONDS = 300;

    /** Seconds one Check again holds its attempt; a crashed check frees it after this. */
    const UNCONFIRMED_CHECK_SECONDS = 90;

    /**
     * Creates a shipment record and increments fulfilled_quantity for each item.
     * Does NOT touch stock — stock is managed at order creation time.
     *
     * @param Order $order
     * @param array $data  Validated shipment data from ShipmentCreateRequest
     * @return Shipment|\WP_Error
     */
    /**
     * `delivered` and `unshippable` are deliberate admin decisions, not derived state.
     * Fulfilment derivation only ever produces unshipped / partially_shipped / shipped,
     * so it must never overwrite them — and a shipment must never be added to or removed
     * from an order sitting in one, because that would move fulfilled_quantity while the
     * status stayed frozen, leaving the two permanently out of step.
     *
     * This is the single definition of "terminal". The admin UI hides the create-shipment
     * affordance for exactly these statuses; keep the two in step.
     *
     * @param string|null $status
     * @return bool
     */
    public static function isTerminalShippingStatus($status)
    {
        return in_array($status, [Status::SHIPPING_DELIVERED, Status::SHIPPING_UNSHIPPABLE], true);
    }

    /**
     * Aggregate fulfilment totals across an order's physical items.
     * Shared with OrderResource so the manual status dropdown and the shipment
     * service agree on whether an order is fully shipped.
     *
     * @param int $orderId
     * @return array ['total' => int, 'fulfilled' => int]
     */
    public static function getFulfillmentTotals($orderId)
    {
        $unitsLeft = self::unitsLeftSql();
        $shippedKept = self::shippedUnitsKeptSql();
        $row = App::db()->table('fct_order_items')
            ->where('order_id', (int) $orderId)
            ->where('fulfillment_type', Status::FULFILLMENT_TYPE_PHYSICAL)
            ->selectRaw("SUM({$unitsLeft}) AS total_qty, SUM({$shippedKept}) AS fulfilled_qty")
            ->first();

        return [
            'total'     => $row ? (int) $row->total_qty : 0,
            'fulfilled' => $row ? (int) $row->fulfilled_qty : 0,
        ];
    }

    /**
     * SQL twin of OrderItem::unitsLeft(): the units not refunded.
     *
     * @return string
     */
    private static function unitsLeftSql()
    {
        return "GREATEST(0, CAST(quantity AS SIGNED) - COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(line_meta, '$.refunded_quantity')) AS SIGNED), 0))";
    }

    /**
     * SQL twin of OrderItem::shippedUnitsKept().
     *
     * @return string
     */
    private static function shippedUnitsKeptSql()
    {
        return "LEAST(GREATEST(0, CAST(fulfilled_quantity AS SIGNED) - COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(line_meta, '$.refunded_shipped_quantity')) AS SIGNED), 0)), "
            . self::unitsLeftSql() . ')';
    }

    /**
     * Human label for a shipping status slug, for user-facing error messages.
     *
     * @param string|null $status
     * @return string
     */
    private static function shippingStatusLabel($status)
    {
        return (string) Arr::get(Status::getShippingStatuses(), $status, $status);
    }

    private static function terminalStatusError($status)
    {
        return new \WP_Error('terminal_status', sprintf(
            /* translators: %1$s: current shipping status label, e.g. Delivered */
            __('This order is marked "%1$s". Change its shipping status before adding shipments.', 'fluent-cart'),
            self::shippingStatusLabel($status)
        ));
    }

    /**
     * Locks the order row until the current transaction ends. Every shipment
     * mutation and the manual shipping-status change take this lock first, so
     * status derivation always reads the totals the previous change committed.
     * Call inside a transaction.
     *
     * @param int $orderId
     * @return Order|null
     */
    public static function lockOrderForShipping($orderId)
    {
        return Order::query()->where('id', (int) $orderId)->lockForUpdate()->first();
    }

    /**
     * Rejects an order edit that would orphan shipped units: deleting an item that
     * shipped or sits on a shipment, or setting a physical item's quantity below its
     * fulfilled_quantity. Call under lockOrderForShipping().
     *
     * @param int   $orderId
     * @param array $deletedItemIds
     * @param array $submittedItems  order_items from the edit payload
     * @return \WP_Error|null
     */
    public static function validateOrderItemEdits($orderId, array $deletedItemIds, array $submittedItems)
    {
        $physicalItems = OrderItem::where('order_id', (int) $orderId)
            ->where('fulfillment_type', Status::FULFILLMENT_TYPE_PHYSICAL)
            ->get()
            ->keyBy('id');

        if ($deletedItemIds) {
            $shippedItemIds = [];
            $shipments = Shipment::where('order_id', (int) $orderId)->get(['id', 'items']);
            foreach ($shipments as $shipment) {
                foreach ((array) $shipment->items as $shipmentItem) {
                    $shippedItemIds[] = (int) Arr::get($shipmentItem, 'order_item_id', 0);
                }
            }

            foreach ($deletedItemIds as $deletedItemId) {
                $deletedItemId = (int) $deletedItemId;
                $orderItem = isset($physicalItems[$deletedItemId]) ? $physicalItems[$deletedItemId] : null;

                if (in_array($deletedItemId, $shippedItemIds, true) || ($orderItem && (int) $orderItem->fulfilled_quantity > 0)) {
                    return new \WP_Error('shipped_item_delete', sprintf(
                        /* translators: %1$s: product name */
                        __('"%1$s" has shipped units and cannot be removed. Delete its shipments first.', 'fluent-cart'),
                        self::resolveItemName($orderItem)
                    ));
                }
            }
        }

        foreach ($submittedItems as $submittedItem) {
            if (!is_array($submittedItem) || !array_key_exists('quantity', $submittedItem)) {
                continue;
            }

            $itemId = (int) Arr::get($submittedItem, 'id', 0);
            if (!isset($physicalItems[$itemId])) {
                continue;
            }

            $orderItem = $physicalItems[$itemId];
            $fulfilled = (int) $orderItem->fulfilled_quantity;

            if ((int) $submittedItem['quantity'] < $fulfilled) {
                return new \WP_Error('qty_below_fulfilled', sprintf(
                    /* translators: %1$s: product name, %2$d: shipped quantity */
                    __('"%1$s" already has %2$d units shipped. Its quantity cannot be lower than that.', 'fluent-cart'),
                    self::resolveItemName($orderItem),
                    $fulfilled
                ));
            }
        }

        return null;
    }

    /**
     * Re-derives shipping_status after an order's items were edited. Call inside the
     * edit's transaction, after the item writes, on the row from lockOrderForShipping();
     * pass the result to dispatchFulfillmentTransition() after commit.
     *
     * Only orders with shipments or fulfilled units are derived. Without either, the
     * manual status dropdown owns the status. `delivered` is reopened only when the
     * edit left units unfulfilled; `unshippable` is never touched.
     *
     * @param Order $order  The locked order row.
     * @return array|null  ['old' => string, 'new' => string] when the status changed.
     */
    public static function reconcileAfterItemEdit(Order $order)
    {
        $totals = static::getFulfillmentTotals($order->id);

        if ($totals['fulfilled'] <= 0 && !Shipment::where('order_id', $order->id)->exists()) {
            return null;
        }

        $reopenDelivered = $order->shipping_status === Status::SHIPPING_DELIVERED
            && $totals['fulfilled'] < $totals['total'];

        return self::deriveFulfillmentStatus($order, $reopenDelivered);
    }

    /**
     * Caps each item's refunded shipped units at its fulfilled_quantity, after units were
     * taken off a shipment. Call under lockOrderForShipping().
     *
     * @param int   $orderId
     * @param int[] $orderItemIds
     * @return array<int, int> Refunded units per order item id that turn out never to have shipped
     */
    public static function clampShippedRefunds($orderId, array $orderItemIds)
    {
        $unshipped = [];
        $orderItems = OrderItem::query()
            ->where('order_id', (int) $orderId)
            ->whereIn('id', $orderItemIds)
            ->get();

        foreach ($orderItems as $orderItem) {
            $lineMeta = $orderItem->line_meta;
            $fulfilled = (int) $orderItem->fulfilled_quantity;
            $refundedShipped = (int) Arr::get($lineMeta, 'refunded_shipped_quantity', 0);
            if ($refundedShipped <= $fulfilled) {
                continue;
            }

            $unshipped[$orderItem->id] = $refundedShipped - $fulfilled;
            $lineMeta['refunded_shipped_quantity'] = $fulfilled;
            $orderItem->line_meta = $lineMeta;
            $orderItem->save();
        }

        return $unshipped;
    }

    public static function createShipment(Order $order, array $data)
    {
        // A label already paid for must be found even after the order changed; a
        // lookup is checked under the lock instead.
        $lookupOnly = (bool) Arr::get($data, 'lookup_only');
        $checkToken = (string) Arr::get($data, 'check_token', '');
        unset($data['check_token']);

        // Guarded at the API boundary, not just in the UI: without this, a shipment
        // against a delivered order would bump fulfilled_quantity while
        // deriveFulfillmentStatus() declined to touch the frozen status — no
        // status change, no event, no activity entry, but a tracking email to the
        // customer regardless.
        if (!$lookupOnly && static::isTerminalShippingStatus($order->shipping_status)) {
            return self::terminalStatusError($order->shipping_status);
        }

        $items = Arr::get($data, 'items', []);
        if (!is_array($items)) {
            $items = [];
        }

        // Loaded once and reused for validation and for the item_name snapshot —
        // the same SELECT used to run twice here plus once in the recalculation.
        $orderItems = self::getPhysicalOrderItems($order);

        $validationError = $lookupOnly ? true : self::validateItems($orderItems, $items);
        if (is_wp_error($validationError)) {
            return $validationError;
        }

        $dispatchedItems = array_filter($items, function ($item) {
            return (int) Arr::get($item, 'qty', 0) > 0;
        });

        if (empty($dispatchedItems)) {
            return new \WP_Error('no_items', __('At least one item must have a quantity greater than zero.', 'fluent-cart'));
        }

        $carrierSlug = sanitize_key((string) Arr::get($data, 'carrier_slug', ''));
        if (!$carrierSlug) {
            $carrierSlug = Shipment::CARRIER_MANUAL;
        }

        // The slug must name a real carrier — an unregistered one would resolve to
        // null everywhere downstream (tracking links, label lookups, cancellation).
        //
        // A *disabled* carrier is still allowed here: the enable toggle governs what
        // checkout OFFERS, not what an admin may record. Refusing disabled carriers
        // would block recording a manual shipment the moment manual rates are turned
        // off, and would strand orders whose carrier was disabled after they shipped.
        $carrier = CarrierManager::carrier($carrierSlug);
        if (!$carrier) {
            return new \WP_Error(
                'invalid_carrier',
                __('The selected shipping carrier is not available.', 'fluent-cart')
            );
        }

        $carrierName    = sanitize_text_field((string) Arr::get($data, 'carrier_name', ''));
        $trackingNumber = sanitize_text_field((string) Arr::get($data, 'tracking_number', ''));
        $trackingUrl    = esc_url_raw((string) Arr::get($data, 'tracking_url', ''), ['http', 'https']);

        // Anything but manual ships under its own name — the free-text field only
        // applies to manual, and the UI enforces that client-side, which a crafted
        // request can simply ignore.
        if ($carrierSlug !== Shipment::CARRIER_MANUAL) {
            $carrierName = sanitize_text_field((string) Arr::get($carrier->meta(), 'name', ''));
        }

        // The admin's own values are checked before any label is bought.
        $lengthError = self::validateFieldLengths([
            'carrier_name'    => $carrierName,
            'tracking_number' => $trackingNumber,
            'tracking_url'    => $trackingUrl,
        ]);
        if (is_wp_error($lengthError)) {
            return $lengthError;
        }

        $dispatchList = array_values($dispatchedItems);

        // Saved before the call, so a request killed or a carrier throwing mid-purchase
        // still leaves it on the order. Manual and zone shipments buy nothing.
        if (!$lookupOnly && $carrierSlug !== Shipment::CARRIER_MANUAL) {
            $data['unconfirmed_id'] = static::addUnconfirmedRequest($order, $carrierSlug, $data);
        }
        $attemptId = (string) Arr::get($data, 'unconfirmed_id', '');

        // Before the transaction, never under the order row lock: a label purchase
        // is an external HTTP call and a charge that a rollback cannot undo.
        $carrierResult = static::dispatchThroughCarrier($carrier, $order, $dispatchList, $data);

        // A lookup answers found, not found or unknown; anything else is unknown, never a shipment without a label.
        if ($lookupOnly && !is_array($carrierResult) && !static::isLabelNotFound($carrierResult) && !(is_wp_error($carrierResult) && static::isPurchaseUnconfirmed($carrierResult))) {
            $carrierResult = is_wp_error($carrierResult)
                ? new \WP_Error($carrierResult->get_error_code(), $carrierResult->get_error_message(), ['purchase_unconfirmed' => true])
                : new \WP_Error('unconfirmed_label_unknown', __('The carrier did not answer the label lookup. Try again in a minute.', 'fluent-cart'), ['purchase_unconfirmed' => true]);
        }

        if (is_wp_error($carrierResult)) {
            if (!$lookupOnly) {
                $kept = static::isPurchaseUnconfirmed($carrierResult) && Arr::get((array) $carrierResult->get_error_data(), 'purchase_sent') !== false;
                if ($attemptId !== '' && !$kept && static::removeUnconfirmedRequest($order, $attemptId)) {
                    static::unconfirmedResolved($attemptId, $carrierSlug, $order, 'refused');
                }
            }
            return $carrierResult;
        }

        $serviceName = '';
        if (is_array($carrierResult)) {
            // The carrier is authoritative for the tracking it assigned.
            $assignedTracking = sanitize_text_field((string) Arr::get($carrierResult, 'tracking_number', ''));
            if ($assignedTracking !== '') {
                $trackingNumber = $assignedTracking;
            }
            $assignedTrackingUrl = esc_url_raw((string) Arr::get($carrierResult, 'tracking_url', ''), ['http', 'https']);
            if ($assignedTrackingUrl) {
                $trackingUrl = $assignedTrackingUrl;
            }
        } else {
            $serviceName = static::resolveSelectedServiceName($carrier, $order, $dispatchList, $data);
        }

        // Derive from the carrier's own tracking pattern (for manual, the picked
        // zone method's pattern) when the admin didn't supply a URL.
        // The carrier owns this mapping, so deriving it here keeps one source
        // of truth and stops a client submitting an arbitrary URL under a carrier's
        // name. An explicitly entered URL always wins.
        //
        // A third-party carrier from the providers filter is not trusted: its scheme
        // is whitelisted like any other input, and it is wrapped because a carrier
        // that breaks the contract's `: string` return type throws inside ITS OWN
        // return — before anything here could coerce it — which would otherwise 500
        // the whole endpoint.
        if (!$trackingUrl && $trackingNumber) {
            try {
                $derivedUrl = $carrier instanceof ManualCarrier
                    ? $carrier->getTrackingUrl($trackingNumber, (int) Arr::get($data, 'selected_rate_id', 0))
                    : $carrier->getTrackingUrl($trackingNumber);
                $trackingUrl = esc_url_raw($derivedUrl, ['http', 'https']);
            } catch (\Throwable $e) {
                $trackingUrl = '';
            }
        }

        // Carrier-returned values would be silently truncated by the columns: void
        // the label rather than record tracking that leads nowhere.
        $lengthError = self::validateFieldLengths([
            'tracking_number' => $trackingNumber,
            'tracking_url'    => $trackingUrl,
        ]);
        if (is_wp_error($lengthError)) {
            static::voidCarrierDispatch($carrier, $order, $dispatchList, $data, $carrierResult);
            return $lengthError;
        }

        $meta = [];
        $rawCost = Arr::get($data, 'cost', '');
        if ($rawCost !== '' && is_numeric($rawCost)) {
            $meta['cost'] = Helper::toCent($rawCost);
            $costCurrency = strtoupper(sanitize_text_field((string) Arr::get($data, 'cost_currency', '')));
            // Unknown codes are dropped so the UI falls back to the store
            // currency sign instead of persisting arbitrary client strings.
            if ($costCurrency && CurrenciesHelper::getCurrencies($costCurrency)) {
                $meta['cost_currency'] = $costCurrency;
            }
        }

        if (is_array($carrierResult)) {
            $meta = array_merge($meta, static::carrierResultMeta($carrierResult));
        } elseif ($serviceName !== '') {
            $meta['service_name'] = $serviceName;
        }
        $meta = array_merge($meta, static::orderCurrencyEstimateMeta($order, $meta));

        $shippedAt = self::resolveShippedAt(Arr::get($data, 'shipped_at', ''));

        $notifyCustomer = Arr::get($data, 'notify_customer');
        if ($notifyCustomer === null || $notifyCustomer === '') {
            $notifyCustomer = true;
        }
        $notifyCustomer = filter_var($notifyCustomer, FILTER_VALIDATE_BOOLEAN);

        // Build explicit, typed stored item rows with an item_name snapshot so
        // the persisted payload never carries arbitrary client keys. The
        // frontend depends on the item_name key being present.
        $storedItems = [];
        foreach ($dispatchedItems as $dispatchedItem) {
            $orderItemId = (int) Arr::get($dispatchedItem, 'order_item_id', 0);
            $qty         = (int) Arr::get($dispatchedItem, 'qty', 0);

            $orderItem = isset($orderItems[$orderItemId]) ? $orderItems[$orderItemId] : null;

            $storedItems[] = [
                'order_item_id' => $orderItemId,
                'qty'           => $qty,
                'item_name'     => self::resolveItemName($orderItem),
            ];
        }

        $db = App::db();

        try {
            $db->beginTransaction();

            $lockedOrder = static::lockOrderForShipping($order->id);

            if (!$lockedOrder) {
                $db->rollBack();
                static::voidCarrierDispatch($carrier, $order, $dispatchList, $data, $carrierResult);
                return new \WP_Error('order_not_found', __('Associated order not found.', 'fluent-cart'));
            }

            // A Check again or Mark resolved settled this attempt meanwhile, maybe recording
            // this very label; not voided, as a recorded shipment may use it.
            $pendingRequests = $attemptId !== '' ? static::unconfirmedRequests($lockedOrder) : [];
            if ($attemptId !== '' && !isset($pendingRequests[$attemptId])) {
                $db->rollBack();
                if ($lookupOnly || !is_array($carrierResult)) {
                    return static::noUnconfirmedRequestError();
                }
                return new \WP_Error('unconfirmed_label_settled', sprintf(
                    /* translators: %s: tracking number of the label the carrier returned */
                    __('The carrier returned label %s, but this purchase was already settled. Check the order\'s shipments before buying again.', 'fluent-cart'),
                    $trackingNumber
                ));
            }

            // A lookup that outlived its claim leaves the label to the check that took over.
            if ($lookupOnly && Arr::get($pendingRequests[$attemptId] ?? [], 'check_token') !== $checkToken) {
                $db->rollBack();
                return static::checkRunningError();
            }

            // Re-checked under the lock: the status may have moved since $order was loaded.
            if (static::isTerminalShippingStatus($lockedOrder->shipping_status)) {
                $db->rollBack();
                static::voidCarrierDispatch($carrier, $order, $dispatchList, $data, $carrierResult);
                return $lookupOnly ? static::labelNotRecordedError($trackingNumber) : self::terminalStatusError($lockedOrder->shipping_status);
            }

            // order_id / status / meta are NOT mass-assignable (they are trust
            // fields), so they are set explicitly after instantiation.
            $shipment = new Shipment([
                'carrier_slug'    => $carrierSlug,
                'carrier_name'    => $carrierName,
                'tracking_number' => $trackingNumber,
                'tracking_url'    => $trackingUrl,
                'items'           => $storedItems,
                'notes'           => static::sanitizeNotes(Arr::get($data, 'notes', '')),
                'notify_customer' => $notifyCustomer,
                'shipped_at'      => $shippedAt,
            ]);

            $shipment->order_id = $order->id;
            $shipment->status   = Shipment::STATUS_DISPATCHED;
            $shipment->meta     = !empty($meta) ? $meta : null;

            $shipment->save();

            foreach ($storedItems as $storedItem) {
                $orderItemId = (int) $storedItem['order_item_id'];
                $qty         = (int) $storedItem['qty'];

                // Atomic, race-safe bump: the WHERE guard makes the
                // check-and-increment a single statement, closing the TOCTOU
                // window that the pre-validation alone leaves open. If another
                // request consumed the remaining quantity first, no row matches
                // and we roll back with a user-facing error.
                //
                // The WHERE side is parameter-bound. The SET side cannot be —
                // Builder::raw() takes no bindings and cleanBindings() strips
                // Expression objects out of the update bindings — so the (int)
                // cast above is the guard there.
                $affected = (int) App::db()->table('fct_order_items')
                    ->where('id', $orderItemId)
                    ->where('order_id', $order->id)
                    ->where('fulfillment_type', Status::FULFILLMENT_TYPE_PHYSICAL)
                    ->whereRaw('(' . self::unitsLeftSql() . ' - ' . self::shippedUnitsKeptSql() . ') >= ?', [$qty])
                    ->update([
                        'fulfilled_quantity' => App::db()->raw('fulfilled_quantity + ' . $qty),
                    ]);

                if ($affected !== 1) {
                    $db->rollBack();
                    // Also the double-submit loser with a label carrier: the UI
                    // guards the button, and the losing request voids its label here.
                    static::voidCarrierDispatch($carrier, $order, $dispatchList, $data, $carrierResult);
                    if ($lookupOnly) {
                        return static::labelNotRecordedError($trackingNumber);
                    }
                    return new \WP_Error(
                        'qty_exceeded',
                        __('The requested quantity exceeds the remaining unfulfilled quantity. Please refresh and try again.', 'fluent-cart')
                    );
                }
            }

            $transition = self::deriveFulfillmentStatus($lockedOrder);

            // Only this attempt: another one may still be waiting on the carrier.
            $settled = static::dropUnconfirmedEntry($lockedOrder, $attemptId);

            $db->commit();

        } catch (\Throwable $e) {
            $db->rollBack();
            static::voidCarrierDispatch($carrier, $order, $dispatchList, $data, $carrierResult);
            return new \WP_Error('db_error', __('Failed to create shipment. Please try again.', 'fluent-cart'));
        }

        // The controller reads the new status off the instance it passed in.
        $order->setRawAttributes($lockedOrder->getAttributes(), true);

        static::dispatchFulfillmentTransition($order, $transition);

        do_action('fluent_cart/order_shipment_created', [
            'shipment' => $shipment,
            'order'    => $order,
        ]);

        if ($settled) {
            static::unconfirmedResolved($attemptId, $carrierSlug, $order, 'recorded');
        }

        return $shipment;
    }

    /**
     * Replays one saved attempt as a lookup: the carrier reads `lookup_only` and
     * `unconfirmed_id` on the instance data and never buys. A found label is
     * recorded like any create; `*_label_not_found` drops the attempt; anything
     * else keeps it.
     *
     * @return Shipment|\WP_Error
     */
    public static function recheckUnconfirmedShipment(Order $order, $attemptId)
    {
        $attemptId = (string) $attemptId;
        $requests  = static::unconfirmedRequests($order);

        if (!isset($requests[$attemptId])) {
            return static::noUnconfirmedRequestError();
        }

        $carrierSlug = (string) $requests[$attemptId]['carrier'];

        // Replaying to a carrier that cannot look up would buy a second label.
        if (!static::supportsUnconfirmedRecheck(CarrierManager::carrier($carrierSlug))) {
            return new \WP_Error('recheck_unsupported', __('This carrier cannot check an unconfirmed label. Check the carrier dashboard instead.', 'fluent-cart'));
        }

        // One check per attempt: two lookups could answer found and not-found, and the
        // not-found drop would then strand the found label.
        $token = static::claimUnconfirmedCheck($order, $attemptId);
        if (is_wp_error($token)) {
            return $token;
        }

        $data = Arr::except($requests[$attemptId], ['id', 'carrier', 'at', 'checking_until', 'check_token']);
        $data['carrier_slug']   = $carrierSlug;
        $data['lookup_only']    = true;
        $data['unconfirmed_id'] = $attemptId;
        $data['check_token']    = $token;

        $result = static::createShipment($order, $data);

        // The add-on holds the purchase only after its first calls, so a running Buy is not findable yet.
        if (static::isLabelNotFound($result) && time() - (int) Arr::get($requests[$attemptId], 'at', 0) < static::UNCONFIRMED_SETTLE_SECONDS) {
            $result = new \WP_Error('unconfirmed_label_unknown', __('The carrier has not confirmed this label yet. Try again in a minute.', 'fluent-cart'), ['purchase_unconfirmed' => true]);
        }

        // A found label was recorded, and announced, by createShipment().
        if (static::isLabelNotFound($result)) {
            // Only while this check still holds the claim: a newer one may be recording the label.
            if (!static::removeUnconfirmedRequest($order, $attemptId, $token)) {
                return static::checkRunningError();
            }
            static::unconfirmedResolved($attemptId, $carrierSlug, $order, 'not_found');
        } elseif (!($result instanceof Shipment)) {
            static::releaseUnconfirmedCheck($order, $attemptId, $token);
        }

        return $result;
    }

    /**
     * @return string|\WP_Error The claim token, or why the attempt cannot be checked now.
     */
    protected static function claimUnconfirmedCheck(Order $order, $attemptId)
    {
        return App::db()->transaction(function () use ($order, $attemptId) {
            static::lockOrderForShipping($order->id);
            $requests = static::unconfirmedRequests($order);

            if (!isset($requests[$attemptId])) {
                return static::noUnconfirmedRequestError();
            }
            if ((int) Arr::get($requests[$attemptId], 'checking_until', 0) > time()) {
                return static::checkRunningError();
            }

            $token = wp_generate_uuid4();
            $requests[$attemptId]['checking_until'] = time() + static::UNCONFIRMED_CHECK_SECONDS;
            $requests[$attemptId]['check_token']    = $token;
            $order->updateMeta(static::UNCONFIRMED_REQUESTS_META, $requests);

            return $token;
        });
    }

    /**
     * Frees the attempt for the next check, unless the claim expired and another took it.
     */
    protected static function releaseUnconfirmedCheck(Order $order, $attemptId, $token)
    {
        App::db()->transaction(function () use ($order, $attemptId, $token) {
            static::lockOrderForShipping($order->id);
            $requests = static::unconfirmedRequests($order);

            if (!isset($requests[$attemptId]) || Arr::get($requests[$attemptId], 'check_token') !== $token) {
                return;
            }
            unset($requests[$attemptId]['checking_until'], $requests[$attemptId]['check_token']);
            $order->updateMeta(static::UNCONFIRMED_REQUESTS_META, $requests);
        });
    }

    protected static function checkRunningError(): \WP_Error
    {
        return new \WP_Error('unconfirmed_check_running', __('A check for this label is already running. Try again in a minute.', 'fluent-cart'), ['purchase_unconfirmed' => true]);
    }

    /**
     * Mark resolved: the admin settled the purchase at the carrier by hand.
     *
     * @return true|\WP_Error
     */
    public static function dismissUnconfirmedShipment(Order $order, $attemptId)
    {
        $attemptId = (string) $attemptId;

        // Checked under the lock, so a check cannot claim between the read and the drop.
        $entry = App::db()->transaction(function () use ($order, $attemptId) {
            static::lockOrderForShipping($order->id);
            $requests = static::unconfirmedRequests($order);
            if (!isset($requests[$attemptId])) {
                return static::noUnconfirmedRequestError();
            }
            $pending = $requests[$attemptId];
            if ((int) Arr::get($pending, 'checking_until', 0) > time()) {
                return static::checkRunningError();
            }
            // Dropped while the Buy still waits, a paid label would be neither recorded nor voided.
            if (time() - (int) Arr::get($pending, 'at', 0) < static::UNCONFIRMED_SETTLE_SECONDS) {
                return new \WP_Error('unconfirmed_purchase_running', __('This purchase may still be running. Try again in a few minutes.', 'fluent-cart'));
            }

            return static::dropUnconfirmedEntry($order, $attemptId);
        });
        if (is_wp_error($entry)) {
            return $entry;
        }

        $user = wp_get_current_user();
        fluent_cart_add_log(
            __('Unconfirmed label purchase marked resolved', 'fluent-cart'),
            sprintf(
                /* translators: %1$s: carrier name, %2$s: the admin's display name */
                __('Unconfirmed %1$s label purchase marked resolved by %2$s.', 'fluent-cart'),
                static::carrierName((string) $entry['carrier']),
                $user->exists() ? $user->display_name : __('the system', 'fluent-cart')
            ),
            'info',
            ['module_name' => 'order', 'module_id' => (int) $order->id]
        );

        static::unconfirmedResolved((string) $attemptId, (string) $entry['carrier'], $order, 'dismissed');

        return true;
    }

    /**
     * The pending purchases for the order page: [{id, carrier, carrier_name, at, can_recheck}].
     */
    public static function unconfirmedShipments(Order $order): array
    {
        $list = [];
        foreach (static::unconfirmedRequests($order) as $attemptId => $request) {
            $carrierSlug = (string) $request['carrier'];
            $list[]      = [
                'id'           => (string) $attemptId,
                'carrier'      => $carrierSlug,
                'carrier_name' => static::carrierName($carrierSlug),
                'at'           => (int) Arr::get($request, 'at', 0),
                'can_recheck'  => static::supportsUnconfirmedRecheck(CarrierManager::carrier($carrierSlug)),
            ];
        }

        return $list;
    }

    /**
     * Opt-in only: an add-on without the method would treat a replay as a new purchase.
     */
    public static function supportsUnconfirmedRecheck($carrier): bool
    {
        return $carrier && method_exists($carrier, 'supportsUnconfirmedRecheck') && $carrier->supportsUnconfirmedRecheck() === true;
    }

    /**
     * For add-ons: whether core still holds this attempt as unconfirmed.
     */
    public static function isUnconfirmedAttemptPending(Order $order, $attemptId): bool
    {
        return isset(static::unconfirmedRequests($order)[(string) $attemptId]);
    }

    /**
     * The code suffixes cover add-ons that send no flag.
     */
    public static function isPurchaseUnconfirmed(\WP_Error $error): bool
    {
        return (bool) Arr::get((array) $error->get_error_data(), 'purchase_unconfirmed')
            || (bool) preg_match('/_(purchase_unconfirmed|label_pending)$/', (string) $error->get_error_code());
    }

    protected static function isLabelNotFound($result): bool
    {
        return is_wp_error($result) && !static::isPurchaseUnconfirmed($result) && preg_match('/_label_not_found$/', (string) $result->get_error_code());
    }

    protected static function carrierName($carrierSlug): string
    {
        $carrier = CarrierManager::carrier($carrierSlug);

        return $carrier ? (string) Arr::get($carrier->meta(), 'name', $carrierSlug) : (string) $carrierSlug;
    }

    protected static function noUnconfirmedRequestError(): \WP_Error
    {
        return new \WP_Error('no_unconfirmed_request', __('This label purchase was already settled. The order is up to date.', 'fluent-cart'));
    }

    protected static function labelNotRecordedError($trackingNumber): \WP_Error
    {
        if ($trackingNumber === '') {
            return new \WP_Error('unconfirmed_label_not_recorded', __('The carrier found the label, but the order changed, so it was not recorded. Record it by hand, then click Mark resolved.', 'fluent-cart'));
        }

        return new \WP_Error('unconfirmed_label_not_recorded', sprintf(
            /* translators: %s: tracking number of the label the carrier found */
            __('The carrier found label %s, but the order changed, so it was not recorded. Record it by hand, then click Mark resolved.', 'fluent-cart'),
            $trackingNumber
        ));
    }

    protected static function unconfirmedResolved($attemptId, $carrierSlug, Order $order, $outcome)
    {
        /**
         * Core dropped an unconfirmed purchase attempt; add-ons release their hold for it.
         *
         * @param string $attemptId
         * @param string $carrierSlug
         * @param Order  $order
         * @param string $outcome recorded|not_found|dismissed|refused|voided
         */
        do_action('fluent_cart/shipping/unconfirmed_purchase_resolved', $attemptId, $carrierSlug, $order, $outcome);
    }

    /**
     * Keyed by attempt id. Entries saved before ids existed were keyed by
     * carrier and are read as one attempt each.
     */
    protected static function unconfirmedRequests(Order $order): array
    {
        $saved    = $order->getMeta(static::UNCONFIRMED_REQUESTS_META, []);
        $requests = [];

        foreach (is_array($saved) ? $saved : [] as $key => $request) {
            if (!is_array($request)) {
                continue;
            }
            if (!isset($request['id'], $request['carrier'])) {
                $request['carrier'] = (string) $key;
                $request['id']      = (string) Arr::get($request, 'unconfirmed_id', '') ?: 'legacy-' . $key;
                unset($request['unconfirmed_id']);
            }
            $requests[(string) $request['id']] = $request;
        }

        return $requests;
    }

    /**
     * @return string The new attempt id.
     */
    protected static function addUnconfirmedRequest(Order $order, $carrierSlug, array $data): string
    {
        $request = Arr::only($data, ['carrier_name', 'tracking_number', 'tracking_url', 'cost', 'cost_currency', 'selected_rate_id', 'notes']);

        $request['items'] = array_values(array_map(function ($item) {
            return ['order_item_id' => (int) Arr::get($item, 'order_item_id', 0), 'qty' => (int) Arr::get($item, 'qty', 0)];
        }, (array) Arr::get($data, 'items', [])));

        // Resolved now, so the recorded shipment keeps the time of the original attempt.
        $request['shipped_at']      = self::resolveShippedAt(Arr::get($data, 'shipped_at', ''));
        $notify                     = Arr::get($data, 'notify_customer');
        $request['notify_customer'] = ($notify === null || $notify === '') ? true : filter_var($notify, FILTER_VALIDATE_BOOLEAN);
        $request['id']              = wp_generate_uuid4();
        $request['carrier']         = (string) $carrierSlug;
        $request['at']              = time();

        // Read under the order lock so a concurrent write keeps its entry.
        App::db()->transaction(function () use ($order, $request) {
            static::lockOrderForShipping($order->id);
            $requests                 = static::unconfirmedRequests($order);
            $requests[$request['id']] = $request;
            $order->updateMeta(static::UNCONFIRMED_REQUESTS_META, $requests);
        });

        return $request['id'];
    }

    /**
     * @return array|null The removed entry; null when it was already gone or another check claimed it.
     */
    protected static function removeUnconfirmedRequest(Order $order, $attemptId, $checkToken = null)
    {
        return App::db()->transaction(function () use ($order, $attemptId, $checkToken) {
            static::lockOrderForShipping($order->id);

            if ($checkToken !== null && Arr::get(static::unconfirmedRequests($order)[$attemptId] ?? [], 'check_token') !== $checkToken) {
                return null;
            }

            return static::dropUnconfirmedEntry($order, $attemptId);
        });
    }

    /**
     * Call under lockOrderForShipping().
     *
     * @return array|null The removed entry.
     */
    protected static function dropUnconfirmedEntry(Order $order, $attemptId)
    {
        $requests = static::unconfirmedRequests($order);
        if ($attemptId === '' || !isset($requests[$attemptId])) {
            return null;
        }
        $entry = $requests[$attemptId];
        unset($requests[$attemptId]);

        if ($requests) {
            $order->updateMeta(static::UNCONFIRMED_REQUESTS_META, $requests);
        } else {
            $order->deleteMeta(static::UNCONFIRMED_REQUESTS_META);
        }

        return $entry;
    }

    /**
     * Prices a prospective shipment through its carrier without creating
     * anything. Only the order's own physical lines reach the carrier, once each,
     * whatever the client sent.
     *
     * Rates are in the currency each carrier priced them in: Manual's are the
     * order's (customer-facing), a label carrier's are its own (merchant cost).
     * Each rate also carries an approximate order-currency figure for display.
     *
     * @param array $data carrier_slug, items [{order_item_id, qty}]
     * @return array|\WP_Error ['rate_object_id' => preselected, 'rates' => [...]]
     */
    public static function quoteShipment(Order $order, array $data)
    {
        $carrierSlug = sanitize_key((string) Arr::get($data, 'carrier_slug', '')) ?: Shipment::CARRIER_MANUAL;
        $carrier     = CarrierManager::carrier($carrierSlug);
        if (!$carrier) {
            return new \WP_Error('invalid_carrier', __('The selected shipping carrier is not available.', 'fluent-cart'));
        }

        $orderItems = self::getPhysicalOrderItems($order);
        $items      = Arr::get($data, 'items', []);

        $dispatched = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $itemId = (int) Arr::get($item, 'order_item_id', 0);
            $qty    = (int) Arr::get($item, 'qty', 0);
            if ($qty < 1 || isset($dispatched[$itemId]) || !isset($orderItems[$itemId])) {
                continue;
            }
            $dispatched[$itemId] = ['order_item_id' => $itemId, 'qty' => $qty];
        }

        if (!$dispatched) {
            return new \WP_Error('no_items', __('At least one item must have a quantity greater than zero.', 'fluent-cart'));
        }

        // A third-party carrier throwing in its own quote must not 500 the endpoint.
        try {
            $quote = $carrier->getShipmentQuote(new ShipmentInstance($order, array_values($dispatched), ['carrier_slug' => $carrierSlug]));
        } catch (\Throwable $e) {
            fluent_cart_error_log('Carrier Quote Error: ' . $carrierSlug, $e->getMessage());
            return new \WP_Error('carrier_quote_failed', __('Could not fetch a rate from this carrier. Please try again.', 'fluent-cart'));
        }

        if (is_wp_error($quote)) {
            return $quote;
        }

        if (!is_array($quote)) {
            return new \WP_Error('quote_not_supported', __('This carrier does not provide rate quotes.', 'fluent-cart'));
        }

        // Only the documented keys reach the browser, never a raw API response.
        $rates = [];
        foreach ((array) Arr::get($quote, 'rates', []) as $rate) {
            $rateId = sanitize_text_field((string) Arr::get($rate, 'rate_object_id', ''));
            if ($rateId === '' || !is_numeric(Arr::get($rate, 'cost_cents'))) {
                continue;
            }
            $costCents = Helper::roundCent(Arr::get($rate, 'cost_cents'));
            $currency  = strtoupper(sanitize_text_field((string) Arr::get($rate, 'currency', '')));
            $normalized = [
                'rate_object_id'          => $rateId,
                'service_name'            => sanitize_text_field((string) Arr::get($rate, 'service_name', '')),
                'cost_cents'              => $costCents,
                'currency'                => $currency,
                'estimated_days'          => max(0, (int) Arr::get($rate, 'estimated_days', 0)),
                'order_currency_estimate' => static::orderCurrencyEstimate($order, $costCents, $currency),
                'order_currency'          => strtoupper((string) $order->currency),
            ];
            // Lets the drawer prefill the URL; only Manual's patterns are store-validated.
            if ($carrier instanceof ManualCarrier) {
                $normalized['tracking_url_pattern'] = sanitize_text_field((string) Arr::get($rate, 'tracking_url_pattern', ''));
            }
            $rates[] = $normalized;
        }

        return [
            'rate_object_id' => sanitize_text_field((string) Arr::get($quote, 'rate_object_id', '')),
            'rates'          => $rates,
        ];
    }

    /**
     * An amount billed in another currency (a carrier label) as an approximate
     * figure in the order's currency, for display only, at the rate
     * orderCurrencyRate() picks.
     *
     * @param int|float|null $cents    Amount in $currency, in cents.
     * @param string         $currency ISO code the amount is billed in.
     * @return int|null Cents in the order's currency, or null when the
     *                  currencies match or no rate converts between them.
     */
    public static function orderCurrencyEstimate(Order $order, $cents, $currency)
    {
        if (!is_numeric($cents) || $cents <= 0) {
            return null;
        }

        $rate = static::orderCurrencyRate($order, $currency);

        return $rate ? self::estimateAtRate($cents, $rate['rate'], $order->currency) : null;
    }

    /**
     * The rate behind an order-currency estimate: order-currency units per one
     * unit of $currency, as a 12-decimal string. The order's frozen rate first,
     * so the estimate matches the order's other figures; the current store rate
     * only when the order has no trusted one.
     *
     * @return array|null ['rate' => string, 'source' => 'order_snapshot'|'store_rate'],
     *                    null when the currencies match or no rate converts.
     */
    public static function orderCurrencyRate(Order $order, $currency)
    {
        $currency      = strtoupper(trim((string) $currency));
        $orderCurrency = strtoupper(trim((string) $order->currency));

        if ($currency === '' || $orderCurrency === '' || $currency === $orderCurrency) {
            return null;
        }

        $snapshot = CurrencySnapshot::fromOrder($order);
        $to       = self::estimateQuote($orderCurrency, strcasecmp((string) $snapshot['currency'], $orderCurrency) === 0 ? $snapshot : []);
        $from     = $to ? self::estimateQuote($currency) : null;

        if (!$to || !$from) {
            return null;
        }

        $rate = CurrencySnapshot::storableRate((float) $to['rate'] / (float) $from['rate']);
        if ($rate === null) {
            return null;
        }

        return [
            'rate'   => $rate,
            'source' => ($to['live'] || $from['live']) ? self::ESTIMATE_SOURCE_STORE : self::ESTIMATE_SOURCE_ORDER,
        ];
    }

    /**
     * An order's shipments for the customer portal. Fields are whitelisted:
     * meta holds costs, labels and carrier ids the customer must not see.
     * Never send label_url, label_pdf_base64, cost, transaction_id or carrier object ids.
     */
    public static function customerShipments(Order $order): array
    {
        $statuses = Shipment::getStatuses();

        // A list page loads every order's shipments in one query, in this order.
        $shipments = $order->relationLoaded('shipments') ? $order->shipments : $order->shipments()
            ->orderBy('shipped_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();

        return $shipments
            ->map(function (Shipment $shipment) use ($statuses) {
                $meta  = is_array($shipment->meta) ? $shipment->meta : [];
                $items = is_array($shipment->items) ? $shipment->items : [];
                $parcels = array_filter((array) Arr::get($meta, 'parcels', []), 'is_array');
                // Carriers send their own time zones; GMT matches shipped_at.
                $statusTime = strtotime((string) Arr::get($meta, 'tracking_status_date', ''));
                $etaTime = strtotime((string) Arr::get($meta, 'tracking_eta', ''));

                return [
                    'id'                      => $shipment->id,
                    'status'                  => $shipment->status,
                    'status_label'            => Arr::get($statuses, $shipment->status, $shipment->status),
                    'carrier_name'            => (string) $shipment->carrier_name,
                    'service_name'            => (string) Arr::get($meta, 'service_name', ''),
                    'tracking_number'         => (string) $shipment->tracking_number,
                    'tracking_url'            => esc_url_raw((string) $shipment->tracking_url),
                    'tracking_status_details' => (string) Arr::get($meta, 'tracking_status_details', ''),
                    'tracking_status_date'    => $statusTime ? gmdate('Y-m-d H:i:s', $statusTime) : '',
                    'tracking_eta'            => $etaTime ? gmdate('Y-m-d H:i:s', $etaTime) : '',
                    'tracking_substatus'      => sanitize_key((string) Arr::get($meta, 'tracking_substatus', '')),
                    'shipped_at'              => $shipment->shipped_at,
                    'notes'                   => (string) $shipment->notes,
                    'parcels'                 => count($parcels) > 1 ? array_values(array_map(function ($parcel) {
                        return [
                            'tracking_number' => (string) Arr::get($parcel, 'tracking_number', ''),
                            'tracking_url'    => esc_url_raw((string) Arr::get($parcel, 'tracking_url', '')),
                        ];
                    }, $parcels)) : [],
                    'items'                   => array_values(array_map(function ($item) {
                        return [
                            'order_item_id' => (int) Arr::get($item, 'order_item_id', 0),
                            'item_name'     => (string) Arr::get($item, 'item_name', ''),
                            'qty'           => (int) Arr::get($item, 'qty', 0),
                        ];
                    }, $items)),
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Physical units the customer is still waiting for, for the customer portal.
     * Empty once the order is canceled or its shipping is final.
     */
    public static function customerUnshippedItems(Order $order): array
    {
        if ($order->status === Status::ORDER_CANCELED || self::isTerminalShippingStatus($order->shipping_status)) {
            return [];
        }

        $unshipped = [];
        $items = $order->relationLoaded('order_items') ? $order->order_items->sortBy('id') : OrderItem::query()
            ->where('order_id', $order->id)
            ->where('fulfillment_type', Status::FULFILLMENT_TYPE_PHYSICAL)
            ->orderBy('id', 'ASC')
            ->get();

        foreach ($items as $item) {
            // A loaded order_items relation also holds digital lines.
            if ($item->fulfillment_type !== Status::FULFILLMENT_TYPE_PHYSICAL) {
                continue;
            }
            $qty = $item->unitsLeft() - $item->shippedUnitsKept();
            if ($qty > 0) {
                $unshipped[] = [
                    'order_item_id' => (int) $item->id,
                    'post_title'    => (string) $item->post_title,
                    'title'         => (string) $item->title,
                    'item_name'     => self::resolveItemName($item),
                    'qty'           => $qty,
                ];
            }
        }

        return $unshipped;
    }

    /**
     * Adds `order_currency_estimate` (cents or null) and `order_currency` to
     * serialized shipments. A shipment's frozen estimate wins; only shipments
     * recorded before estimates were frozen get one at today's rate.
     *
     * @param array $shipments Shipments as arrays (Shipment::toArray()).
     * @return array
     */
    public static function withOrderCurrencyEstimates(Order $order, array $shipments)
    {
        $orderCurrency = strtoupper((string) $order->currency);

        foreach ($shipments as $index => $shipment) {
            if (!is_array($shipment)) {
                continue;
            }
            $meta   = is_array(Arr::get($shipment, 'meta')) ? $shipment['meta'] : [];
            $frozen = Arr::get($meta, 'order_currency_estimate');

            if (is_numeric($frozen) && strcasecmp((string) Arr::get($meta, 'order_currency', ''), $orderCurrency) === 0) {
                $estimate = (int) $frozen;
            } else {
                // A cost without a currency was typed in the order's currency.
                $estimate = static::orderCurrencyEstimate($order, Arr::get($meta, 'cost'), Arr::get($meta, 'cost_currency', ''));
            }

            $shipments[$index]['order_currency_estimate'] = $estimate;
            $shipments[$index]['order_currency']          = $orderCurrency;
        }

        return $shipments;
    }

    /**
     * Shipment meta that freezes the order-currency estimate of a foreign cost
     * when the shipment is recorded, so a later rate change never moves it.
     * Empty when the cost is in the order's currency or no rate converts it.
     */
    protected static function orderCurrencyEstimateMeta(Order $order, array $meta): array
    {
        $cents = Arr::get($meta, 'cost');
        if (!is_numeric($cents) || $cents <= 0) {
            return [];
        }

        $rate = static::orderCurrencyRate($order, Arr::get($meta, 'cost_currency', ''));
        if (!$rate) {
            return [];
        }

        return [
            'order_currency'             => strtoupper((string) $order->currency),
            'order_currency_rate'        => $rate['rate'],
            'order_currency_rate_source' => $rate['source'],
            'order_currency_estimate'    => self::estimateAtRate($cents, $rate['rate'], $order->currency),
        ];
    }

    /**
     * The one conversion behind every estimate, so the quote shown before
     * buying and the figure frozen on the shipment round alike.
     *
     * @param string $rate Order-currency units per one unit of the cost's currency.
     */
    private static function estimateAtRate($cents, $rate, $orderCurrency): int
    {
        return PriceConverter::toQuoteUnit(Helper::roundCent($cents) * (float) $rate, [
            'currency' => strtoupper((string) $orderCurrency),
            'rate'     => $rate,
            'rounding' => RoundingRules::NONE,
        ]);
    }

    /**
     * A conversion quote for $currency: the base needs no rate; otherwise the
     * given snapshot's rate when trusted, else the current rate while
     * multi-currency is on (`live`). Null when there is no real rate (never 1:1).
     *
     * @return array|null
     */
    private static function estimateQuote($currency, array $snapshot = [])
    {
        if (BaseCurrency::is($currency)) {
            return array_merge(PriceConverter::baseQuote(), ['live' => false]);
        }

        $rate = null;
        $live = false;
        if ($snapshot
            && CurrencySnapshot::hasTrustedRate($snapshot)
            && CurrencySnapshot::isValidRate(Arr::get($snapshot, 'rate'))
            && BaseCurrency::is(Arr::get($snapshot, 'base_currency', BaseCurrency::get()))
        ) {
            $rate = ['rate' => $snapshot['rate'], 'source' => $snapshot['source']];
        } elseif (MultiCurrencySettings::isEnabled()) {
            $rate = ExchangeRateService::current($currency, ['context' => 'shipment_cost_estimate']);
            $live = true;
        }

        if (!$rate) {
            return null;
        }

        return [
            'currency' => strtoupper((string) $currency),
            'rate'     => $rate['rate'],
            'source'   => (string) Arr::get($rate, 'source', ''),
            'rounding' => RoundingRules::NONE,
            'live'     => $live,
        ];
    }

    /**
     * Hands the shipment to the carrier: an array means it bought a label, a
     * WP_Error aborts the create, null means it does not dispatch.
     *
     * @param array $items Dispatched items, as a list.
     * @return array|\WP_Error|null
     */
    protected static function dispatchThroughCarrier(CarrierContract $carrier, Order $order, array $items, array $data)
    {
        $carrierSlug = sanitize_key((string) Arr::get($data, 'carrier_slug', ''));

        /**
         * Adjust the instance before a label is bought, or return anything that is
         * not a ShipmentInstance to skip carrier dispatch and record by hand.
         *
         * @param ShipmentInstance $instance
         * @param array            $context ['carrier_slug' => string]
         */
        $instance = apply_filters('fluent_cart/before_carrier_dispatch', new ShipmentInstance($order, $items, $data), [
            'carrier_slug' => $carrierSlug,
        ]);

        if (!($instance instanceof ShipmentInstance)) {
            return null;
        }

        // A third-party carrier throwing must not 500 the endpoint.
        try {
            $result = $carrier->makeShipmentFromShipmentInstance($instance);
        } catch (\Throwable $e) {
            fluent_cart_error_log('Carrier Dispatch Error: ' . $carrierSlug, $e->getMessage());

            // A label may have been bought before the throw.
            return new \WP_Error(
                'carrier_dispatch_failed',
                __('The carrier could not dispatch this shipment. Please try again, or record it manually.', 'fluent-cart'),
                Arr::get($data, 'unconfirmed_id') ? ['purchase_unconfirmed' => true] : null
            );
        }

        return (is_wp_error($result) || is_array($result)) ? $result : null;
    }

    /**
     * The title of the rate the admin picked, looked up in the carrier's own
     * quote by its id, so a crafted request cannot name a service it did not pick.
     *
     * @param array $items Dispatched items, as a list.
     * @return string Empty when nothing was picked or the id no longer resolves.
     */
    protected static function resolveSelectedServiceName(CarrierContract $carrier, Order $order, array $items, array $data)
    {
        $selectedRateId = sanitize_text_field((string) Arr::get($data, 'selected_rate_id', ''));
        if ($selectedRateId === '') {
            return '';
        }

        try {
            $quote = $carrier->getShipmentQuote(new ShipmentInstance($order, $items, $data));
        } catch (\Throwable $e) {
            return '';
        }

        foreach (is_array($quote) ? (array) Arr::get($quote, 'rates', []) : [] as $rate) {
            if ((string) Arr::get($rate, 'rate_object_id', '') === $selectedRateId) {
                return sanitize_text_field((string) Arr::get($rate, 'service_name', ''));
            }
        }

        return '';
    }

    /**
     * The parts of a dispatch result worth keeping, key by key. A label's cost is
     * the merchant's, kept in the carrier's own currency and never in the order's.
     */
    protected static function carrierResultMeta(array $carrierResult): array
    {
        $meta = [];

        $labelUrl = esc_url_raw((string) Arr::get($carrierResult, 'label_url', ''), ['http', 'https']);
        if ($labelUrl) {
            $meta['label_url'] = $labelUrl;
        }

        foreach (['service_name', 'transaction_id'] as $key) {
            $value = sanitize_text_field((string) Arr::get($carrierResult, $key, ''));
            if ($value !== '') {
                $meta[$key] = $value;
            }
        }

        $cost     = Arr::get($carrierResult, 'cost');
        $currency = strtoupper(sanitize_text_field((string) Arr::get($carrierResult, 'currency', '')));
        if (is_numeric($cost) && $cost >= 0 && $currency && CurrenciesHelper::getCurrencies($currency)) {
            $meta['cost']          = Helper::roundCent($cost);
            $meta['cost_currency'] = $currency;
        }

        return $meta;
    }

    /**
     * Voids a label bought for a shipment that was then not recorded. Best effort
     * and never throws: it runs on an error path. Always leaves an order activity
     * entry, so a label the carrier could not void can be reconciled by hand.
     *
     * @param array $items         Dispatched items, as a list.
     * @param mixed $carrierResult Only an array means a label was bought.
     */
    protected static function voidCarrierDispatch(CarrierContract $carrier, Order $order, array $items, array $data, $carrierResult)
    {
        // A looked-up label was not bought here; a concurrent recheck may already have recorded it.
        if (Arr::get($data, 'lookup_only')) {
            return;
        }

        // The purchase is no longer unknown: voided below, or logged for a manual void.
        $attemptId = (string) Arr::get($data, 'unconfirmed_id', '');
        if ($attemptId !== '') {
            // Settled elsewhere, maybe recorded with this label: leave the label alone.
            if (!static::removeUnconfirmedRequest($order, $attemptId)) {
                return;
            }
            static::unconfirmedResolved($attemptId, sanitize_key((string) Arr::get($data, 'carrier_slug', '')), $order, 'voided');
        }

        if (!is_array($carrierResult)) {
            return;
        }

        try {
            // No shipment row exists, so the label handle travels on the data.
            $voidData = array_merge($data, [
                'transaction_id' => (string) Arr::get($carrierResult, 'transaction_id', ''),
            ]);
            $result = $carrier->cancelShipmentFromShipmentInstance(new ShipmentInstance($order, $items, $voidData));
        } catch (\Throwable $e) {
            $result = new \WP_Error('carrier_void_failed', $e->getMessage());
        }

        $logContext = ['module_name' => 'order', 'module_id' => (int) $order->id];

        if ($result && !is_wp_error($result)) {
            fluent_cart_add_log(
                __('Shipping label voided', 'fluent-cart'),
                __('A label was bought but the shipment could not be recorded, so the carrier voided it.', 'fluent-cart'),
                'info',
                $logContext
            );
            return;
        }

        fluent_cart_warning_log(
            __('Shipping label not voided', 'fluent-cart'),
            sprintf(
                /* translators: %s: the carrier's error message */
                __('A label was bought but the shipment could not be recorded. Void it in the carrier portal. Carrier said: %s', 'fluent-cart'),
                is_wp_error($result) ? $result->get_error_message() : __('no void reported', 'fluent-cart')
            ),
            $logContext
        );
    }

    /**
     * Plain-text note: removes script/style blocks and well-formed tags only, so
     * a stray "<" (as in "qty<5") is kept. Every output escapes it.
     */
    public static function sanitizeNotes($notes): string
    {
        $notes = wp_check_invalid_utf8((string) $notes);
        $notes = (string) preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $notes);

        return trim((string) preg_replace('#</?[a-zA-Z][^<>]*>#', '', $notes));
    }

    /**
     * Voids the label of a deleted shipment. Best effort and never throws. Both a
     * void and a refused void go on the order's activity, so the latter can be
     * reconciled by hand.
     *
     * @param Shipment $deleted The row as it was locked and deleted.
     * @return string|null Admin-facing warning when the carrier could not void.
     */
    protected static function voidCarrierLabel(Shipment $deleted, Order $order)
    {
        $meta          = is_array($deleted->meta) ? $deleted->meta : [];
        $transactionId = (string) Arr::get($meta, 'transaction_id', '');
        $carrier       = CarrierManager::carrier(sanitize_key((string) $deleted->carrier_slug));

        // No handle means no bought label. A disabled carrier can still void.
        // A delivered label is used, so the carrier would only refuse the void.
        if (!$transactionId || !$carrier || $deleted->status === Shipment::STATUS_DELIVERED) {
            return null;
        }

        try {
            $result = $carrier->cancelShipmentFromShipmentInstance(new ShipmentInstance(
                $order,
                is_array($deleted->items) ? array_values($deleted->items) : [],
                [
                    'carrier_slug'   => (string) $deleted->carrier_slug,
                    'transaction_id' => $transactionId,
                ],
                $deleted
            ));
        } catch (\Throwable $e) {
            $result = new \WP_Error('carrier_void_failed', $e->getMessage());
        }

        $logContext  = ['module_name' => 'order', 'module_id' => (int) $order->id];
        $shipmentRef = (string) $deleted->tracking_number ?: '#' . (int) $deleted->id;

        if (!is_wp_error($result)) {
            if ($result) {
                fluent_cart_add_log(
                    __('Shipping label voided', 'fluent-cart'),
                    sprintf(
                        /* translators: %s: shipment tracking number */
                        __('The label of deleted shipment %s was voided with the carrier.', 'fluent-cart'),
                        $shipmentRef
                    ),
                    'info',
                    $logContext
                );
            }
            return null;
        }

        $detail = $result->get_error_message();

        fluent_cart_warning_log(
            __('Shipping label not voided', 'fluent-cart'),
            sprintf(
                /* translators: %1$s: shipment tracking number, %2$s: the carrier's error message */
                __('Shipment %1$s was deleted but its label could not be voided. Void it in the carrier portal. Carrier said: %2$s', 'fluent-cart'),
                $shipmentRef,
                $detail
            ),
            $logContext
        );

        return sprintf(
            /* translators: %s: the carrier's error message */
            __('The shipment was deleted, but its carrier label could not be voided (%s). Please void it in the carrier portal.', 'fluent-cart'),
            $detail
        );
    }

    /**
     * Applies a carrier tracking event (webhook or refresh) to a shipment.
     *
     * Carriers replay and reorder events, so the guards run on the locked row:
     * an event no newer than the stored one is ignored (by date, else by
     * lifecycle stage), delivered/returned never move, and a repeated status
     * stores the fresher detail without announcing a change.
     *
     * On a real change fires fluent_cart/shipment_tracking_updated and the same
     * fluent_cart/order_shipment_status_changed[_to_{status}] pair as
     * updateShipmentStatus(), each with ['shipment', 'order', 'old_status', 'new_status'].
     *
     * @param Shipment $shipment
     * @param string   $newStatus One of Shipment::getStatuses()
     * @param array    $meta      tracking_status, tracking_status_date, tracking_status_details
     * @return bool|\WP_Error True when the status changed, WP_Error when the write failed.
     */
    public static function applyTrackingUpdate(Shipment $shipment, $newStatus, array $meta = [])
    {
        // Also makes the value safe for the dynamic hook name below.
        $newStatus = sanitize_key((string) $newStatus);

        if (!array_key_exists($newStatus, Shipment::getStatuses())) {
            return false;
        }

        $db = App::db();

        try {
            $db->beginTransaction();

            $order = static::lockOrderForShipping($shipment->order_id);

            $lockedShipment = $order
                ? Shipment::query()->where('id', $shipment->id)->lockForUpdate()->first()
                : null;

            if (!$lockedShipment) {
                $db->rollBack();
                return false;
            }

            $oldStatus    = $lockedShipment->status;
            $existingMeta = is_array($lockedShipment->meta) ? $lockedShipment->meta : [];

            if (!self::isTrackingEventNewer($existingMeta, $meta, $oldStatus, $newStatus)) {
                $db->rollBack();
                return false;
            }

            // Terminal: keep the status, still record the newer detail.
            if (in_array($oldStatus, Shipment::terminalStatuses(), true)) {
                $newStatus = $oldStatus;
            }

            $statusChanged = ($newStatus !== $oldStatus);
            $mergedMeta    = array_merge($existingMeta, $meta);

            if (!$statusChanged && $mergedMeta === $existingMeta) {
                $db->rollBack();
                return false;
            }

            $lockedShipment->meta = $mergedMeta ?: null;
            if ($statusChanged) {
                $lockedShipment->status = $newStatus;
            }
            $lockedShipment->save();

            // Override as in updateShipmentStatus(): the last parcel delivered moves
            // the order into `delivered`, and back out when that stops being true.
            $transition = $statusChanged ? self::deriveFulfillmentStatus($order, true) : null;

            $db->commit();

        } catch (\Throwable $e) {
            $db->rollBack();
            return new \WP_Error('db_error', __('Failed to update shipment tracking. Please try again.', 'fluent-cart'));
        }

        $shipment->setRawAttributes($lockedShipment->getAttributes(), true);

        static::dispatchFulfillmentTransition($order, $transition);

        if (!$statusChanged) {
            return false;
        }

        $payload = [
            'shipment'   => $shipment,
            'order'      => $order,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ];

        do_action('fluent_cart/shipment_tracking_updated', $payload);
        do_action('fluent_cart/order_shipment_status_changed_to_' . $newStatus, $payload);
        do_action('fluent_cart/order_shipment_status_changed', $payload);

        return true;
    }

    /**
     * Whether the shipment's carrier can fetch a label for it. No carrier call.
     */
    public static function hasLabel(Shipment $shipment): bool
    {
        $carrier = CarrierManager::carrier(sanitize_key((string) $shipment->carrier_slug));

        return $carrier instanceof AbstractShippingCarrier && $carrier->hasLabel($shipment);
    }

    /**
     * The shipment's label PDF, fetched from a link the carrier gives now: links
     * expire, so no PDF is stored.
     *
     * @return array|\WP_Error ['content' => string, 'filename' => string]; label_not_found
     *                         when the carrier has no label, else the fetch failed.
     */
    public static function getLabelPdf(Shipment $shipment)
    {
        $carrier = CarrierManager::carrier(sanitize_key((string) $shipment->carrier_slug));
        $url = $carrier instanceof AbstractShippingCarrier && $carrier->hasLabel($shipment)
            ? $carrier->getLabelDownloadUrl($shipment)
            : '';

        if (is_wp_error($url)) {
            return $url;
        }

        if (!is_string($url) || $url === '') {
            return new \WP_Error('label_not_found', __('This shipment has no label to print.', 'fluent-cart'));
        }

        if (!preg_match('#^https://#i', $url)) {
            return new \WP_Error('label_fetch_failed', __('The carrier sent a label link that is not secure.', 'fluent-cart'));
        }

        $max = 5 * MB_IN_BYTES;
        $response = wp_safe_remote_get($url, [
            'timeout'             => 20,
            'redirection'         => 3,
            'limit_response_size' => $max + 1,
        ]);
        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $body = is_wp_error($response) ? '' : (string) wp_remote_retrieve_body($response);

        if ($code !== 200 || strncmp($body, '%PDF', 4) !== 0 || strlen($body) > $max) {
            return new \WP_Error('label_fetch_failed', sprintf(
                /* translators: %d: HTTP status code, 0 when there was no response */
                __('The label could not be downloaded from the carrier, HTTP status %d. Please try again.', 'fluent-cart'),
                $code
            ));
        }

        return [
            'content'  => $body,
            'filename' => 'label-' . (int) $shipment->id . '.pdf',
        ];
    }

    /**
     * Whether the admin may pull this shipment's tracking: a carrier that syncs,
     * a label it bought (carriers pull by transaction_id, as the void does) unless
     * it tracks by number, a tracking number, and a status tracking can still move.
     */
    public static function canRefreshTracking(Shipment $shipment)
    {
        if (empty($shipment->tracking_number) || in_array($shipment->status, Shipment::terminalStatuses(), true)) {
            return false;
        }

        $carrier = CarrierManager::carrier(sanitize_key((string) $shipment->carrier_slug));
        if (!$carrier || !$carrier->supportsTrackingSync()) {
            return false;
        }

        $meta = is_array($shipment->meta) ? $shipment->meta : [];
        if (trim((string) Arr::get($meta, 'transaction_id', '')) !== '') {
            return true;
        }

        return $carrier instanceof AbstractShippingCarrier && $carrier->canTrackWithoutLabel($shipment);
    }

    /**
     * Orders by tracking_status_date when both events carry one (strtotime keeps
     * carrier time zones comparable), else rejects only a move to an earlier stage.
     */
    private static function isTrackingEventNewer(array $storedMeta, array $incomingMeta, $oldStatus, $newStatus)
    {
        $incomingDate = (string) Arr::get($incomingMeta, 'tracking_status_date', '');
        $storedDate   = (string) Arr::get($storedMeta, 'tracking_status_date', '');

        if ($incomingDate && $storedDate) {
            return strtotime($incomingDate) > strtotime($storedDate);
        }

        $newOrdinal = static::statusOrdinal($newStatus);
        $oldOrdinal = static::statusOrdinal($oldStatus);

        return $newOrdinal === null || $oldOrdinal === null || $newOrdinal >= $oldOrdinal;
    }

    /**
     * Lifecycle stage for ordering dateless events. Null for a filter-registered
     * status, whose place is unknown, so the guard is skipped.
     *
     * @param string $status
     * @return int|null
     */
    protected static function statusOrdinal($status)
    {
        $stages = [
            Shipment::STATUS_DISPATCHED => 1,
            Shipment::STATUS_IN_TRANSIT => 2,
            Shipment::STATUS_FAILED     => 3,
            Shipment::STATUS_DELIVERED  => 4,
            Shipment::STATUS_RETURNED   => 4,
        ];

        return isset($stages[$status]) ? $stages[$status] : null;
    }

    /**
     * Moves a shipment along its lifecycle (dispatched / in_transit / delivered,
     * in any direction) and re-derives the order's shipping status from it.
     *
     * Quantities are untouched: the units this shipment claimed stay fulfilled no
     * matter where in the lifecycle it sits — only deleting the shipment gives
     * them back.
     *
     * Fires, only on a real transition:
     *   fluent_cart/order_shipment_status_changed_to_{status}
     *   fluent_cart/order_shipment_status_changed
     * both with ['shipment' => Shipment, 'order' => Order, 'old_status', 'new_status'].
     * Mirrors the order-status pair in OrderStatusUpdated::afterDispatch(), so a
     * listener can subscribe to one status (e.g. ..._changed_to_delivered) without
     * having to filter the generic hook itself.
     *
     * @param Shipment $shipment
     * @param string $status
     * @return Order|\WP_Error  The refreshed order
     */
    public static function updateShipmentStatus(Shipment $shipment, $status)
    {
        // sanitize_key() also makes the value safe to interpolate into the
        // dynamic hook name below.
        $status   = sanitize_key((string) $status);
        $statuses = Shipment::getStatuses();

        if (!isset($statuses[$status])) {
            return new \WP_Error('invalid_status', __('The selected shipment status is not valid.', 'fluent-cart'));
        }

        $db = App::db();

        try {
            $db->beginTransaction();

            $order = static::lockOrderForShipping($shipment->order_id);

            if (!$order) {
                $db->rollBack();
                return new \WP_Error('order_not_found', __('Associated order not found.', 'fluent-cart'));
            }

            $lockedShipment = Shipment::query()
                ->where('id', $shipment->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedShipment) {
                $db->rollBack();
                return new \WP_Error('shipment_not_found', __('Shipment not found. It may have already been deleted.', 'fluent-cart'));
            }

            $oldStatus = $lockedShipment->status;
            $changed   = ($oldStatus !== $status);

            // Dirty-check: a no-op save would still bump updated_at for nothing.
            if ($changed) {
                $lockedShipment->status = $status;
                $lockedShipment->save();
            }

            // Overrides the terminal guard for the same reason deleteShipment() does:
            // this call is an explicit admin decision about the very records the
            // order's `delivered` status is now derived from. Marking the last
            // shipment delivered must be able to move the order INTO `delivered`, and
            // moving a shipment back off `delivered` must be able to move it back OUT
            // — a frozen status would keep asserting a delivery the shipment records
            // no longer support. The transition dispatches OrderStatusUpdated like any
            // other, so it is logged rather than silent.
            // A re-submitted status changes nothing, so it must not undo a manual "delivered".
            $transition = $changed ? self::deriveFulfillmentStatus($order, true) : null;

            $db->commit();

        } catch (\Throwable $e) {
            $db->rollBack();
            return new \WP_Error('db_error', __('Failed to update shipment. Please try again.', 'fluent-cart'));
        }

        $shipment->setRawAttributes($lockedShipment->getAttributes(), true);

        static::dispatchFulfillmentTransition($order, $transition);

        // Nothing moved — firing would make every listener defensive against a
        // "change" that never happened (the UI can re-submit the current status,
        // and a carrier webhook can replay the same event).
        if (!$changed) {
            return $order;
        }

        $payload = [
            'shipment'   => $shipment,
            'order'      => $order,
            'old_status' => $oldStatus,
            'new_status' => $status,
        ];

        do_action('fluent_cart/order_shipment_status_changed_to_' . $status, $payload);
        do_action('fluent_cart/order_shipment_status_changed', $payload);

        return $order;
    }

    /**
     * Deletes a shipment and reverses fulfilled_quantity for each item.
     * Releases refunded units the shipment had counted as shipped (no restock). A bought carrier label is voided after commit; the
     * delete stands even when the void fails.
     *
     * @param Shipment    $shipment
     * @param string|null $voidWarning Set to an admin-facing message when a label
     *                                 could not be voided.
     * @return Order|\WP_Error
     */
    public static function deleteShipment(Shipment $shipment, &$voidWarning = null)
    {
        $voidWarning = null;

        $db = App::db();

        try {
            $db->beginTransaction();

            $order = static::lockOrderForShipping($shipment->order_id);

            if (!$order) {
                $db->rollBack();
                return new \WP_Error('order_not_found', __('Associated order not found.', 'fluent-cart'));
            }

            // Lock and reload so concurrent deletes serialize; the loser finds
            // the row gone and must not reverse the quantities a second time.
            $lockedShipment = Shipment::query()
                ->where('id', $shipment->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedShipment) {
                $db->rollBack();
                return new \WP_Error('shipment_not_found', __('Shipment not found. It may have already been deleted.', 'fluent-cart'));
            }

            $items = $lockedShipment->items;
            if (!is_array($items)) {
                $items = [];
            }

            foreach ($items as $item) {
                $orderItemId = (int) Arr::get($item, 'order_item_id', 0);
                $qty         = (int) Arr::get($item, 'qty', 0);

                if ($orderItemId && $qty > 0) {
                    // Atomic reversal floored at 0 — GREATEST() matches the
                    // previous max(0, fulfilled - qty) behaviour in a single
                    // statement, without a read-modify-write round trip.
                    // As above, the SET side cannot carry bindings, so the
                    // (int) cast is what keeps the concatenation safe.
                    App::db()->table('fct_order_items')
                        ->where('id', $orderItemId)
                        ->where('order_id', $order->id)
                        ->update([
                            'fulfilled_quantity' => App::db()->raw('GREATEST(fulfilled_quantity - ' . $qty . ', 0)'),
                        ]);
                }
            }

            $itemIds = array_filter(array_map('intval', array_column($items, 'order_item_id')));
            $unshippedRefunds = $itemIds ? self::clampShippedRefunds($order->id, $itemIds) : [];

            $lockedShipment->delete();

            if ($unshippedRefunds && ModuleSettings::isActive('stock_management')) {
                (new StockManagement())->releaseUnshippedRefunds($order, $unshippedRefunds);
            }

            // Deleting a shipment is an explicit correction, so it is allowed to move the
            // order out of `delivered` — the units it claimed are no longer dispatched,
            // and leaving the status frozen would assert a delivery that the item rows
            // no longer support. The transition dispatches OrderStatusUpdated like any
            // other, so it is recorded rather than silent.
            $transition = self::deriveFulfillmentStatus($order, true);

            $db->commit();

        } catch (\Throwable $e) {
            $db->rollBack();
            return new \WP_Error('db_error', __('Failed to delete shipment. Please try again.', 'fluent-cart'));
        }

        static::dispatchFulfillmentTransition($order, $transition);

        // Outside the transaction: a void is an external side effect, and
        // $lockedShipment still holds the row as it was deleted.
        $voidWarning = static::voidCarrierLabel($lockedShipment, $order);

        do_action('fluent_cart/order_shipment_deleted', [
            'shipment' => $shipment,
            'order'    => $order,
        ]);

        return $order;
    }

    /**
     * Derives shipping_status from the current totals and saves it. Must run inside
     * the caller's transaction, on an order loaded by lockOrderForShipping(), so the
     * totals and the write cannot interleave with another shipment change.
     *
     * @param Order $order  The locked order row.
     * @param bool $overrideTerminalStatus
     * @return array|null  ['old' => string, 'new' => string] when the status changed.
     */
    private static function deriveFulfillmentStatus(Order $order, $overrideTerminalStatus = false)
    {
        $oldStatus = $order->shipping_status;

        // `unshippable` is a standing admin decision that this order is not to be
        // shipped at all. Nothing derived from shipment records may overrule it,
        // not even an explicit one — so it is checked before the override.
        if ($oldStatus === Status::SHIPPING_UNSHIPPABLE) {
            return null;
        }

        // `delivered`, by contrast, is now derivable: it is reached by marking every
        // shipment delivered. Passive derivation still must not overwrite it (an
        // admin may have set it by hand), but the two calls that act ON the very
        // records it is derived from — deleting a shipment, and changing a
        // shipment's status — opt in, so the order can move back out when those
        // records no longer support the claim. The transition dispatches
        // OrderStatusUpdated like any other, so it is logged rather than silent.
        if (!$overrideTerminalStatus && static::isTerminalShippingStatus($oldStatus)) {
            return null;
        }

        $totals = static::getFulfillmentTotals($order->id);

        $totalQty     = $totals['total'];
        $fulfilledQty = $totals['fulfilled'];

        // No physical items — nothing to derive a shipping status from.
        if ($totalQty <= 0) {
            return null;
        }

        if ($fulfilledQty === 0) {
            $newStatus = Status::SHIPPING_UNSHIPPED;
        } elseif ($fulfilledQty >= $totalQty) {
            // Everything is out the door — but the order is only `delivered` once
            // every shipment carrying it says so. One shipment still in transit
            // keeps the whole order at `shipped`.
            $newStatus = self::allShipmentsDelivered($order->id)
                ? Status::SHIPPING_DELIVERED
                : Status::SHIPPING_SHIPPED;
        } else {
            $newStatus = Status::SHIPPING_PARTIALLY_SHIPPED;
        }

        if ($oldStatus === $newStatus) {
            return null;
        }

        $order->updateStatus('shipping_status', $newStatus);

        return ['old' => $oldStatus, 'new' => $newStatus];
    }

    /**
     * Fires the status-change event, emails and hooks for a committed transition.
     * Runs after commit so listeners never execute under the order lock.
     *
     * @param Order $order
     * @param array|null $transition  From deriveFulfillmentStatus() or reconcileAfterItemEdit().
     * @param bool $byItemEdit  Words the activity entry for an order item edit.
     */
    public static function dispatchFulfillmentTransition(Order $order, $transition, $byItemEdit = false)
    {
        if (!$transition) {
            return;
        }

        $oldStatus = $transition['old'];
        $newStatus = $transition['new'];

        $activity = [
            'title'   => __('Shipping status updated', 'fluent-cart'),
            'content' => $byItemEdit
                ? sprintf(
                    /* translators: %1$s: previous shipping status, %2$s: new shipping status */
                    __('Shipping status has been updated from %1$s to %2$s by an order item change', 'fluent-cart'),
                    (string) $oldStatus,
                    (string) $newStatus
                )
                : sprintf(
                    /* translators: %1$s: previous shipping status, %2$s: new shipping status */
                    __('Shipping status has been updated from %1$s to %2$s by a shipment record change', 'fluent-cart'),
                    (string) $oldStatus,
                    (string) $newStatus
                ),
        ];

        // The customer gets a dedicated per-shipment email (fired from
        // `fluent_cart/order_shipment_created`) that carries the carrier and
        // tracking data. The generic "your order has shipped" notification that
        // rides on `shipping_status_changed_to_shipped` would be a second,
        // less useful copy of the same news — suppress it for the duration of
        // this dispatch only, so every other listener (integrations, stock,
        // activity log) still runs.
        $suppressShippedMail = function ($shouldSend, $context) {
            if (Arr::get($context, 'event') === 'shipping_status_changed_to_shipped') {
                return false;
            }

            return $shouldSend;
        };

        add_filter('fluent_cart/should_send_email_notification', $suppressShippedMail, 10, 2);

        try {
            // manageStock = false: creating or deleting a shipment must never move
            // stock on its own — it is committed at order creation.
            //
            // The one stock move that does still happen is not this flag's to make:
            // StockManagement::manageStockOnShippingStatusChanged() keys off the
            // status pair, not the flag, and shifts units on_hold <-> committed on a
            // transition into or out of `delivered`. Now that `delivered` can be
            // derived from the shipments, that listener is reachable from here — by
            // design, and symmetrically: moving a shipment back off `delivered`
            // derives the order out of `delivered` and reverses the same move. It is
            // exactly what an admin already gets by picking `delivered` in the
            // order's shipping-status dropdown.
            (new OrderStatusUpdated($order, $oldStatus, $newStatus, false, $activity, 'shipping_status'))->dispatch();
        } finally {
            // A throwing listener must not leave the suppression filter registered —
            // it would silently kill the shipped-mail for every later order in the
            // same request (a bulk action ships many orders in one pass).
            remove_filter('fluent_cart/should_send_email_notification', $suppressShippedMail, 10);
        }

        do_action('fluent_cart/order_shipping_status_updated', [
            'order'      => $order,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ]);
    }

    /**
     * True only when the order has at least one shipment and every one of them is
     * `delivered`.
     *
     * The "at least one" half matters: an order whose items were marked fulfilled
     * without any shipment record (the manual status dropdown does that) has
     * nothing to derive a delivery from, and must stay at `shipped` rather than
     * vacuously satisfying "all shipments delivered".
     *
     * @param int $orderId
     * @return bool
     */
    private static function allShipmentsDelivered($orderId)
    {
        $total = Shipment::where('order_id', (int) $orderId)->count();

        if ($total < 1) {
            return false;
        }

        $pending = Shipment::where('order_id', (int) $orderId)
            ->where('status', '!=', Shipment::STATUS_DELIVERED)
            ->count();

        return $pending === 0;
    }

    /**
     * Physical order items for an order, keyed by item id.
     *
     * @param Order $order
     * @return \FluentCart\Framework\Database\Orm\Collection
     */
    private static function getPhysicalOrderItems(Order $order)
    {
        return OrderItem::where('order_id', $order->id)
            ->where('fulfillment_type', Status::FULFILLMENT_TYPE_PHYSICAL)
            ->get()
            ->keyBy('id');
    }

    /**
     * Normalizes the client-supplied shipped_at (a UTC `Y-m-d H:i:s` string) into a
     * value the DATETIME column will accept. createFromFormat() rolls invalid parts
     * over instead of failing ('2026-13-45' becomes 2027-01-14), so the parse result
     * is only trusted when the parser reported no warnings or errors.
     *
     * @param mixed $value
     * @return string
     */
    private static function resolveShippedAt($value)
    {
        $raw = sanitize_text_field((string) $value);

        if ($raw) {
            $parsed = \DateTime::createFromFormat('Y-m-d H:i:s', $raw);
            $errors = \DateTime::getLastErrors();

            // PHP >= 8.2 returns false instead of a zero-filled array when clean.
            $hasIssues = is_array($errors)
                && (!empty($errors['warning_count']) || !empty($errors['error_count']));

            if ($parsed && !$hasIssues) {
                return $parsed->format('Y-m-d H:i:s');
            }
        }

        return gmdate('Y-m-d H:i:s');
    }

    /**
     * Validates that each item belongs to the order and qty does not exceed remaining unfulfilled.
     *
     * @param \FluentCart\Framework\Database\Orm\Collection $orderItems  Physical order items keyed by id
     * @param array $items  [{order_item_id: N, qty: N}]
     * @return true|\WP_Error
     */
    private static function validateItems($orderItems, array $items)
    {
        if (empty($items)) {
            return new \WP_Error('no_items', __('No items provided.', 'fluent-cart'));
        }

        $seenItemIds = [];

        foreach ($items as $item) {
            $orderItemId = (int) Arr::get($item, 'order_item_id', 0);
            $qty         = (int) Arr::get($item, 'qty', 0);

            if ($qty === 0) {
                continue;
            }

            if (isset($seenItemIds[$orderItemId])) {
                return new \WP_Error(
                    'duplicate_item',
                    sprintf(
                        /* translators: %d: order item ID */
                        __('Order item %d is listed more than once. Combine its quantities into one row.', 'fluent-cart'),
                        $orderItemId
                    )
                );
            }
            $seenItemIds[$orderItemId] = true;

            if (!isset($orderItems[$orderItemId])) {
                return new \WP_Error(
                    'invalid_item',
                    sprintf(
                        /* translators: %d: order item ID */
                        __('Order item %d does not belong to this order or is not a physical item.', 'fluent-cart'),
                        $orderItemId
                    )
                );
            }

            $orderItem = $orderItems[$orderItemId];
            $remaining = $orderItem->unitsLeft() - $orderItem->shippedUnitsKept();

            if ($qty > $remaining) {
                $itemName = self::resolveItemName($orderItem);
                return new \WP_Error(
                    'qty_exceeded',
                    sprintf(
                        /* translators: %1$s: product name, %2$d: remaining qty */
                        __('Quantity for "%1$s" exceeds remaining unfulfilled quantity of %2$d.', 'fluent-cart'),
                        $itemName,
                        $remaining
                    )
                );
            }
        }

        return true;
    }

    /**
     * Rejects values longer than their fct_shipments column. MySQL would otherwise
     * truncate them silently, which breaks tracking links.
     *
     * @param array $fields  field => sanitized value
     * @return true|\WP_Error
     */
    private static function validateFieldLengths(array $fields)
    {
        $limits = [
            'carrier_name'    => [100, __('Carrier name', 'fluent-cart')],
            'tracking_number' => [255, __('Tracking number', 'fluent-cart')],
            'tracking_url'    => [500, __('Tracking URL', 'fluent-cart')],
        ];

        foreach ($fields as $field => $value) {
            if (isset($limits[$field]) && mb_strlen((string) $value) > $limits[$field][0]) {
                return new \WP_Error('field_too_long', sprintf(
                    /* translators: %1$s: field label, %2$d: maximum number of characters */
                    __('%1$s must be %2$d characters or fewer.', 'fluent-cart'),
                    $limits[$field][1],
                    $limits[$field][0]
                ));
            }
        }

        return true;
    }

    /**
     * Resolves a display name for an order item: "product - variant" so split
     * shipments of one product say which variant shipped, else whichever
     * title is set, then a generic label.
     *
     * @param OrderItem|null $orderItem
     * @return string
     */
    private static function resolveItemName($orderItem)
    {
        if ($orderItem) {
            // A simple product's variation title is no variant and goes stale on rename.
            $isSimple = Arr::get($orderItem->other_info, 'variation_type') === Helper::PRODUCT_TYPE_SIMPLE;
            if ($orderItem->post_title && !$isSimple) {
                // Same variant label as the order emails.
                $variant = $orderItem->variation_display_title ?: $orderItem->title;
                if ($variant && $variant !== $orderItem->post_title) {
                    return $orderItem->post_title . ' - ' . $variant;
                }
            }
            if ($orderItem->post_title) {
                return $orderItem->post_title;
            }
            if ($orderItem->title) {
                return $orderItem->title;
            }
        }

        return __('item', 'fluent-cart');
    }
}

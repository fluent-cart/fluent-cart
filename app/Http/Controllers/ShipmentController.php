<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\App\Http\Requests\ShipmentCreateRequest;
use FluentCart\App\Http\Requests\ShipmentQuoteRequest;
use FluentCart\App\Http\Requests\ShipmentUpdateRequest;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Shipment;
use FluentCart\App\Modules\Shipping\Carriers\Core\CarrierManager;
use FluentCart\App\Services\ShipmentService;
use FluentCart\App\Services\Shipments\ShipmentInstance;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;

class ShipmentController extends Controller
{
    /**
     * GET /orders/{order_id}/shipments
     * List all shipments for an order.
     */
    public function index(Request $request, $order_id)
    {
        $order = Order::find((int) $order_id);

        if (!$order) {
            return $this->sendError(['message' => __('Order not found.', 'fluent-cart')], 404);
        }

        $shipments = Shipment::where('order_id', $order->id)
            ->orderBy('shipped_at', 'desc')
            ->orderBy('id', 'desc')
            ->addAppends(['can_refresh_tracking'])
            ->get();

        return [
            'shipments' => ShipmentService::withOrderCurrencyEstimates($order, $shipments->toArray()),
        ];
    }

    /**
     * POST /orders/{order_id}/shipments/quote
     * Price a shipment through its carrier without creating anything.
     */
    public function quote(ShipmentQuoteRequest $request, $order_id)
    {
        $order = Order::find((int) $order_id);

        if (!$order) {
            return $this->sendError(['message' => __('Order not found.', 'fluent-cart')], 404);
        }

        $quote = ShipmentService::quoteShipment($order, $request->getSafe($request->sanitize()));

        if (is_wp_error($quote)) {
            return $this->sendError(['message' => $quote->get_error_message()], 422);
        }

        return [
            'quote' => $quote,
        ];
    }

    /**
     * POST /orders/{order_id}/shipments
     * Create a shipment record.
     */
    public function store(ShipmentCreateRequest $request, $order_id)
    {
        $order = Order::find((int) $order_id);

        if (!$order) {
            return $this->sendError(['message' => __('Order not found.', 'fluent-cart')], 404);
        }

        // getSafe() is both the sanitizer and the key whitelist — all() would hand
        // the service raw, unfiltered input.
        $data = $request->getSafe($request->sanitize());

        return $this->createdResponse($order, ShipmentService::createShipment($order, $data));
    }

    /**
     * POST /orders/{order_id}/shipments/recheck-unconfirmed
     * Ask the carrier whether a label it did not confirm was bought, and record it if so.
     */
    public function recheckUnconfirmed(Request $request, $order_id)
    {
        $order = Order::find((int) $order_id);

        if (!$order) {
            return $this->sendError(['message' => __('Order not found.', 'fluent-cart')], 404);
        }

        $attemptId = $request->get('unconfirmed_id', '');
        if (!is_string($attemptId)) {
            return $this->invalidAttemptId();
        }

        return $this->createdResponse($order, ShipmentService::recheckUnconfirmedShipment($order, sanitize_text_field($attemptId)));
    }

    /**
     * POST /orders/{order_id}/shipments/dismiss-unconfirmed
     * Mark an unconfirmed label purchase resolved after checking it at the carrier.
     */
    public function dismissUnconfirmed(Request $request, $order_id)
    {
        $order = Order::find((int) $order_id);

        if (!$order) {
            return $this->sendError(['message' => __('Order not found.', 'fluent-cart')], 404);
        }

        $attemptId = $request->get('unconfirmed_id', '');
        if (!is_string($attemptId)) {
            return $this->invalidAttemptId();
        }

        $result = ShipmentService::dismissUnconfirmedShipment($order, sanitize_text_field($attemptId));

        if (is_wp_error($result)) {
            return $this->unconfirmedError($order, $result);
        }

        return [
            'message'               => __('Marked resolved.', 'fluent-cart'),
            'unconfirmed_shipments' => ShipmentService::unconfirmedShipments($order),
        ];
    }

    /**
     * Every answer carries the order's pending purchases, so the page never guesses the lock.
     *
     * @param Shipment|\WP_Error $result
     */
    private function createdResponse(Order $order, $result)
    {
        if (is_wp_error($result)) {
            return $this->unconfirmedError($order, $result);
        }

        // The service refreshes shipping_status on the passed $order instance.
        return [
            'shipment'              => $result->toArray(),
            'order_shipping_status' => $order->shipping_status,
            'message'               => __('Shipment created successfully.', 'fluent-cart'),
            'unconfirmed_shipments' => ShipmentService::unconfirmedShipments($order),
        ];
    }

    private function invalidAttemptId()
    {
        return $this->sendError(['message' => __('Invalid unconfirmed purchase id.', 'fluent-cart'), 'code' => 'invalid_unconfirmed_id'], 422);
    }

    private function unconfirmedError(Order $order, \WP_Error $result)
    {
        $error = [
            'message'               => $result->get_error_message(),
            'code'                  => $result->get_error_code(),
            'unconfirmed_shipments' => ShipmentService::unconfirmedShipments($order),
        ];

        // Still unknown: a label may have been bought.
        if (ShipmentService::isPurchaseUnconfirmed($result)) {
            $error['purchase_unconfirmed'] = true;
        }

        return $this->sendError($error, 422);
    }

    /**
     * PUT /orders/{order_id}/shipments/{shipment_id}
     * Move a shipment along its lifecycle (Shipment::getStatuses()).
     */
    public function update(ShipmentUpdateRequest $request, $order_id, $shipment_id)
    {
        $order = Order::find((int) $order_id);

        if (!$order) {
            return $this->sendError(['message' => __('Order not found.', 'fluent-cart')], 404);
        }

        // Scoped to the order in the query, not checked afterwards — a shipment id
        // from another order must not be addressable through this order's route.
        $shipment = Shipment::where('id', (int) $shipment_id)
            ->where('order_id', $order->id)
            ->first();

        if (!$shipment) {
            return $this->sendError(['message' => __('Shipment not found.', 'fluent-cart')], 404);
        }

        $data = $request->getSafe($request->sanitize());

        $result = ShipmentService::updateShipmentStatus($shipment, Arr::get($data, 'status', ''));

        if (is_wp_error($result)) {
            return $this->sendError(['message' => $result->get_error_message()], 422);
        }

        // updateShipmentStatus() returns the refreshed Order; $shipment carries the
        // persisted new status.
        return [
            'shipment'              => $shipment->toArray(),
            'order_shipping_status' => $result->shipping_status,
            'message'               => __('Shipment updated successfully.', 'fluent-cart'),
        ];
    }

    /**
     * DELETE /orders/{order_id}/shipments/{shipment_id}
     * Delete a shipment and reverse fulfilled_quantity.
     */
    public function destroy(Request $request, $order_id, $shipment_id)
    {
        $order = Order::find((int) $order_id);

        if (!$order) {
            return $this->sendError(['message' => __('Order not found.', 'fluent-cart')], 404);
        }

        $shipment = Shipment::where('id', (int) $shipment_id)
            ->where('order_id', $order->id)
            ->first();

        if (!$shipment) {
            return $this->sendError(['message' => __('Shipment not found.', 'fluent-cart')], 404);
        }

        $voidWarning = null;
        $result      = ShipmentService::deleteShipment($shipment, $voidWarning);

        if (is_wp_error($result)) {
            return $this->sendError(['message' => $result->get_error_message()], 422);
        }

        // The delete stood either way; a failed label void replaces the success line.
        return [
            'order_shipping_status' => $result->shipping_status,
            'void_warning'          => (bool) $voidWarning,
            'message'               => $voidWarning ?: __('Shipment deleted successfully.', 'fluent-cart'),
        ];
    }

    /**
     * GET /orders/{order_id}/shipments/{shipment_id}/label
     * Download the label PDF stored on the shipment.
     */
    public function label(Request $request, $order_id, $shipment_id)
    {
        $order = Order::find((int) $order_id);

        if (!$order) {
            return $this->sendError(['message' => __('Order not found.', 'fluent-cart')], 404);
        }

        $shipment = Shipment::where('id', (int) $shipment_id)
            ->where('order_id', $order->id)
            ->first();

        if (!$shipment) {
            return $this->sendError(['message' => __('Shipment not found.', 'fluent-cart')], 404);
        }

        $label = ShipmentService::getLabelPdf($shipment);

        if (is_wp_error($label)) {
            // No label is a 404; a carrier or download failure is the carrier's.
            return $this->sendError(['message' => $label->get_error_message()], $label->get_error_code() === 'label_not_found' ? 404 : 502);
        }

        return $this->fileResponse($label['content'], $label['filename'], 'application/pdf');
    }

    /**
     * A REST response that the server writes as raw bytes instead of JSON.
     */
    private function fileResponse(string $content, string $filename, string $contentType): \WP_REST_Response
    {
        $response = new \WP_REST_Response($content, 200, [
            'Content-Type'           => $contentType,
            'Content-Disposition'    => 'attachment; filename="' . sanitize_file_name($filename) . '"',
            'Content-Length'         => (string) strlen($content),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, no-store',
        ]);

        // Matched by body, not identity: a rest_post_dispatch filter may rebuild the response.
        $serve = function ($served, $result) use ($content, &$serve) {
            if ($served || !$result instanceof \WP_REST_Response || $result->get_data() !== $content) {
                return $served;
            }

            remove_filter('rest_pre_serve_request', $serve, 10);
            echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file body

            return true;
        };

        add_filter('rest_pre_serve_request', $serve, 10, 2);

        return $response;
    }

    /**
     * POST /orders/{order_id}/shipments/{shipment_id}/refresh-tracking
     * Pull the shipment's status from its carrier through the webhook's writer.
     */
    public function refreshTracking(Request $request, $order_id, $shipment_id)
    {
        $order = Order::find((int) $order_id);

        if (!$order) {
            return $this->sendError(['message' => __('Order not found.', 'fluent-cart')], 404);
        }

        $shipment = Shipment::where('id', (int) $shipment_id)
            ->where('order_id', $order->id)
            ->first();

        if (!$shipment) {
            return $this->sendError(['message' => __('Shipment not found.', 'fluent-cart')], 404);
        }

        $carrier = CarrierManager::carrier((string) $shipment->carrier_slug);

        if (!$carrier || !$carrier->supportsTrackingSync()) {
            return $this->sendError([
                'message' => __('This carrier does not support refreshing tracking.', 'fluent-cart')
            ], 422);
        }

        // Each refresh spends the store's carrier quota. A transient is not atomic,
        // so two simultaneous clicks may both pass; the cooldown only stops repeats.
        $cooldownKey = 'fct_track_refresh_' . (int) $shipment->id;
        if (get_transient($cooldownKey)) {
            return $this->sendError([
                'message' => __('Tracking was just refreshed. Please wait a few seconds before trying again.', 'fluent-cart')
            ], 429);
        }
        set_transient($cooldownKey, 1, 15);

        $metaBefore = is_array($shipment->meta) ? $shipment->meta : [];

        $instance = new ShipmentInstance(
            $order,
            is_array($shipment->items) ? array_values($shipment->items) : [],
            ['carrier_slug' => (string) $shipment->carrier_slug],
            $shipment
        );

        try {
            $result = $carrier->syncTrackingFromShipmentInstance($instance);
        } catch (\Throwable $e) {
            fluent_cart_error_log(
                'Carrier Tracking Sync Error: ' . $shipment->carrier_slug,
                $e->getMessage() . '. Debug Trace: ' . $e->getTraceAsString()
            );
            $result = new \WP_Error('carrier_sync_failed', __('Could not refresh tracking from the carrier. Please try again.', 'fluent-cart'));
        }

        if (is_wp_error($result)) {
            return $this->sendError(['message' => $result->get_error_message()], 422);
        }

        $applied  = (bool) $result;
        $shipment = Shipment::find($shipment->id);
        $order    = Order::find($order->id);

        // A repeated status still stores fresher scan details; `applied` only covers a status change.
        $metaAfter      = ($shipment && is_array($shipment->meta)) ? $shipment->meta : [];
        $detailsUpdated = $metaAfter != $metaBefore;

        if ($applied) {
            $message = __('Tracking updated from the carrier.', 'fluent-cart');
        } elseif ($detailsUpdated) {
            $message = __('Tracking details updated from the carrier.', 'fluent-cart');
        } else {
            $message = __('Tracking is already up to date.', 'fluent-cart');
        }

        return [
            'applied'               => $applied,
            'details_updated'       => $detailsUpdated,
            'shipment'              => $shipment ? $shipment->toArray() : null,
            'shipment_status'       => $shipment ? $shipment->status : '',
            'order_shipping_status' => $order ? $order->shipping_status : '',
            'message'               => $message,
        ];
    }
}

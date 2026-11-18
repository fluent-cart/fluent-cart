<?php

namespace FluentCart\App\Modules\Shipping;

use FluentCart\App\App;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\Shipment;
use FluentCart\App\Modules\Shipping\Carriers\Contracts\CarrierContract;
use FluentCart\App\Modules\Shipping\Carriers\Core\CarrierManager;
use FluentCart\App\Modules\Shipping\Carriers\Manual\ManualCarrier;
use FluentCart\App\Modules\Shipping\Carriers\Manual\ManualCarrierSettings;
use FluentCart\App\Modules\Shipping\Http\Handlers\ScriptHandler;
use FluentCart\App\Modules\Shipping\Services\Filter\ShippingZoneFilter;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class ShippingModule
{
    public static function register()
    {
        $self = new static();
        add_action('fluentcart_loaded', [$self, 'init']);

        add_filter('fluent_cart/checkout/before_patch_checkout_data', [$self, 'maybeRecalculateShippingCharges'], 9, 2);

        add_action('fluent_cart/cart/cart_data_items_updated', [$self, 'handleItemsChanges'], 9, 1);

        // ?fluent-cart=fct_shipping_listener_webhook&carrier={slug}, fired by WebRoutes.
        add_action('fluent_cart_action_fct_shipping_listener_webhook', [$self, 'handleCarrierWebhook']);
    }

    /**
     * Public tracking-webhook endpoint. The carrier authenticates and answers;
     * this only routes and turns a miss or a failure into a status code.
     */
    public function handleCarrierWebhook()
    {
        $carrierSlug = sanitize_key((string) App::request()->getSafe('carrier', 'sanitize_text_field'));
        $status      = $this->routeCarrierWebhook($carrierSlug);

        if ($status === 404) {
            wp_send_json(['message' => __('Not found.', 'fluent-cart')], 404);
        }

        if ($status === 500) {
            wp_send_json(['message' => __('Webhook processing failed.', 'fluent-cart')], 500);
        }
    }

    /** @var array|null The delivery being logged, between start and finish. */
    protected $webhookLog = null;

    /**
     * @param string|null $rawBody Request body, read from php://input when null.
     * @return int|null Null when the carrier handled it, 404 for an unknown carrier
     *                  (never confirm which slugs exist), 500 so the carrier retries.
     */
    public function routeCarrierWebhook($carrierSlug, $rawBody = null)
    {
        $carrier = $carrierSlug ? CarrierManager::carrier((string) $carrierSlug) : null;

        if (!$carrier) {
            return 404;
        }

        $this->startCarrierWebhookLog((string) $carrierSlug, $carrier, $rawBody);

        try {
            $carrier->handleWebhook();
        } catch (\Throwable $e) {
            fluent_cart_error_log(
                'Carrier Webhook Error: ' . $carrierSlug,
                $e->getMessage() . '. Debug Trace: ' . $e->getTraceAsString()
            );

            if ($this->webhookLog) {
                $this->webhookLog['status'] = 500;
            }
            $this->finishCarrierWebhookLog();

            return 500;
        }

        $this->finishCarrierWebhookLog();

        return null;
    }

    protected function startCarrierWebhookLog(string $carrierSlug, CarrierContract $carrier, $rawBody)
    {
        $this->webhookLog = [
            'slug'     => $carrierSlug,
            'name'     => (string) Arr::get($carrier->meta(), 'name', $carrierSlug),
            'raw_body' => $rawBody,
            'status'   => null,
            'changes'  => [],
        ];

        // The response body is the only generic record of the outcome.
        ob_start();
        $this->webhookLog['ob_level'] = ob_get_level();

        add_filter('status_header', [$this, 'captureWebhookStatus'], 10, 2);
        add_action('fluent_cart/shipment_tracking_updated', [$this, 'captureWebhookTrackingChange'], 10, 1);
        // Carriers answer with wp_send_json(), which exits. Priority 0 runs before
        // WordPress flushes the output buffers at shutdown priority 1.
        add_action('shutdown', [$this, 'finishCarrierWebhookLog'], 0, 0);
    }

    public function captureWebhookStatus($statusHeader, $code)
    {
        if ($this->webhookLog) {
            $this->webhookLog['status'] = (int) $code;
        }

        return $statusHeader;
    }

    public function captureWebhookTrackingChange($payload)
    {
        $shipment = Arr::get($payload, 'shipment');
        if (!$this->webhookLog || !$shipment) {
            return;
        }

        $this->webhookLog['changes'][] = [
            'shipment_id' => (int) $shipment->id,
            'order_id'    => (int) $shipment->order_id,
            'old_status'  => (string) Arr::get($payload, 'old_status', ''),
            'new_status'  => (string) Arr::get($payload, 'new_status', ''),
        ];
    }

    /**
     * Writes the delivery's log row. Runs once: after handleWebhook() returns, or
     * at shutdown when the carrier exited. Never logs the token, body or headers.
     */
    public function finishCarrierWebhookLog()
    {
        $log = $this->webhookLog;
        if (!$log) {
            return;
        }
        $this->webhookLog = null;

        remove_filter('status_header', [$this, 'captureWebhookStatus'], 10);
        remove_action('fluent_cart/shipment_tracking_updated', [$this, 'captureWebhookTrackingChange'], 10);
        remove_action('shutdown', [$this, 'finishCarrierWebhookLog'], 0);

        $responseBody = '';
        if (ob_get_level() === $log['ob_level']) {
            $responseBody = (string) ob_get_contents();
            ob_end_flush();
        }

        try {
            $this->writeCarrierWebhookLog($log, $responseBody);
        } catch (\Throwable $e) {
            // Logging must never change the carrier's response.
        }
    }

    protected function writeCarrierWebhookLog(array $log, string $responseBody)
    {
        $status = $log['status'];
        if (!$status) {
            $status = (int) http_response_code();
        }
        $status = $status ?: 200;

        $response = json_decode($responseBody, true);
        $outcome  = static::carrierWebhookOutcome($status, is_array($response) ? $response : [], !empty($log['changes']));

        foreach ($log['changes'] as $change) {
            $this->logCarrierWebhookTrackingChange($log, $status, $outcome, $change);
        }

        if ($log['changes']) {
            return;
        }

        $enabled = apply_filters('fluent_cart/shipping/log_carrier_webhooks', true, [
            'carrier' => $log['slug'],
            'outcome' => $outcome,
            'status'  => $status,
        ]);
        if (!$enabled) {
            return;
        }

        $shipment   = static::carrierWebhookShipment($status, is_array($response) ? $response : []);
        $suppressed = static::throttleCarrierWebhookLog($log['slug'], $outcome, $shipment ? (int) $shipment->id : 0);
        if ($suppressed === false) {
            return;
        }

        $lines = array_merge(
            $this->carrierWebhookLogLines($log, $status, $outcome),
            static::carrierWebhookDetailLines($status, is_array($response) ? $response : [])
        );
        if ($shipment) {
            /* translators: %d: shipment ID */
            $lines[] = sprintf(__('Shipment #%d', 'fluent-cart'), (int) $shipment->id);
        }
        if ($suppressed !== null) {
            $lines[] = __('Repeats of this outcome are logged at most once an hour.', 'fluent-cart');
        }
        if ($suppressed) {
            /* translators: %d: number of deliveries that were not logged */
            $lines[] = sprintf(__('%d similar deliveries were not logged since the previous entry.', 'fluent-cart'), $suppressed);
        }

        if ($outcome === 'error') {
            $logStatus = 'error';
        } elseif (strpos($outcome, 'rejected:') === 0) {
            $logStatus = 'warning';
        } else {
            $logStatus = 'info';
        }

        fluent_cart_add_log(
            /* translators: 1: carrier name, 2: outcome key such as ignored:event */
            sprintf(__('%1$s webhook: %2$s', 'fluent-cart'), $log['name'], $outcome),
            implode(' | ', $lines),
            $logStatus,
            $shipment
                ? ['module_name' => 'order', 'module_id' => (int) $shipment->order_id]
                : ['module_name' => 'shipping', 'module_id' => 0]
        );
    }

    /**
     * The shipment the carrier matched the delivery to, from its own response's
     * shipment_id. Rejected deliveries are unauthenticated, so they never link.
     *
     * @return Shipment|null
     */
    protected static function carrierWebhookShipment(int $status, array $response)
    {
        $shipmentId = isset($response['shipment_id']) && is_numeric($response['shipment_id']) ? (int) $response['shipment_id'] : 0;

        if ($shipmentId <= 0 || ($status >= 400 && $status < 500)) {
            return null;
        }

        $shipment = Shipment::query()->select(['id', 'order_id'])->find($shipmentId);

        return $shipment && $shipment->order_id ? $shipment : null;
    }

    /**
     * The tracking change is order history, so it is kept even when delivery
     * logging is switched off. It doubles as the delivery's log row.
     */
    protected function logCarrierWebhookTrackingChange(array $log, int $status, string $outcome, array $change)
    {
        $labels = Shipment::getStatuses();
        $old    = Arr::get($labels, $change['old_status'], $change['old_status']);
        $new    = Arr::get($labels, $change['new_status'], $change['new_status']);

        $lines   = $this->carrierWebhookLogLines($log, $status, $outcome);
        /* translators: %d: shipment ID */
        $lines[] = sprintf(__('Shipment #%d', 'fluent-cart'), $change['shipment_id']);

        fluent_cart_add_log(
            /* translators: 1: carrier name, 2: previous shipment status, 3: new shipment status */
            sprintf(__('Tracking updated via %1$s webhook: %2$s → %3$s', 'fluent-cart'), $log['name'], $old, $new),
            implode(' | ', $lines),
            'success',
            ['module_name' => 'order', 'module_id' => $change['order_id']]
        );
    }

    protected function carrierWebhookLogLines(array $log, int $status, string $outcome): array
    {
        $lines = [
            /* translators: 1: carrier name, 2: carrier slug */
            sprintf(__('Carrier: %1$s (%2$s)', 'fluent-cart'), $log['name'], $log['slug']),
            /* translators: %d: HTTP status code */
            sprintf(__('HTTP %d', 'fluent-cart'), $status),
            /* translators: %s: outcome key such as applied or ignored:event */
            sprintf(__('Outcome: %s', 'fluent-cart'), $outcome),
        ];

        // A rejected request is unauthenticated: nothing from its body is trusted.
        $event = strpos($outcome, 'rejected:') === 0 ? '' : static::carrierWebhookEventType($log['raw_body']);
        if ($event !== '') {
            /* translators: %s: the carrier's event type */
            $lines[] = sprintf(__('Event: %s', 'fluent-cart'), $event);
        }

        return $lines;
    }

    /**
     * The carrier's own description of an authenticated delivery (WebhookOutcomes
     * `details`): tracking number, carrier and test/live mode.
     */
    protected static function carrierWebhookDetailLines(int $status, array $response): array
    {
        $details = isset($response['details']) && is_array($response['details']) ? $response['details'] : [];
        if (!$details || $status >= 400) {
            return [];
        }

        $labels = [
            /* translators: %s: tracking number */
            'tracking_number' => __('Tracking number: %s', 'fluent-cart'),
            /* translators: %s: carrier code, e.g. usps */
            'carrier'         => __('Tracking carrier: %s', 'fluent-cart'),
            /* translators: %s: Test or Live */
            'mode'            => __('Mode: %s', 'fluent-cart'),
        ];
        $modes = ['test' => __('Test', 'fluent-cart'), 'live' => __('Live', 'fluent-cart')];
        if (isset($details['mode']) && is_scalar($details['mode'])) {
            $details['mode'] = isset($modes[$details['mode']]) ? $modes[$details['mode']] : '';
        }

        $lines = [];
        foreach ($labels as $key => $label) {
            $value = isset($details[$key]) && is_scalar($details[$key]) ? sanitize_text_field((string) $details[$key]) : '';
            if ($value !== '') {
                $lines[] = sprintf($label, substr($value, 0, 64));
            }
        }

        return $lines;
    }

    /**
     * The event name from the common top-level keys (Shippo `event`, ShipStation
     * `resource_type`). Only this one sanitized key is read from the body.
     */
    protected static function carrierWebhookEventType($rawBody): string
    {
        if ($rawBody === null) {
            $rawBody = (string) file_get_contents('php://input');
        }

        if (!is_string($rawBody) || $rawBody === '' || strlen($rawBody) > MB_IN_BYTES) {
            return '';
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return '';
        }

        foreach (['event', 'resource_type', 'event_type', 'type'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key])) {
                return substr(sanitize_key($payload[$key]), 0, 64);
            }
        }

        return '';
    }

    /**
     * @return string applied, error, rejected:invalid_token, rejected:http_{code},
     *                unknown_shipment, mode_mismatch, ignored:{reason} or received
     */
    public static function carrierWebhookOutcome(int $status, array $response, bool $changed = false): string
    {
        if ($status >= 500) {
            return 'error';
        }

        if ($status === 401) {
            return 'rejected:invalid_token';
        }

        if ($status >= 400) {
            return 'rejected:http_' . $status;
        }

        if ($changed || !empty($response['applied'])) {
            return 'applied';
        }

        if (isset($response['ignored']) && is_scalar($response['ignored'])) {
            $reason = substr(sanitize_key((string) $response['ignored']), 0, 40);

            return in_array($reason, ['unknown_shipment', 'mode_mismatch'], true) ? $reason : 'ignored:' . $reason;
        }

        if (array_key_exists('applied', $response)) {
            return 'ignored:no_change';
        }

        return 'received';
    }

    /**
     * Applied and error deliveries always log. Every other outcome logs at most
     * once an hour per carrier, all rejections sharing one bucket, so neither a
     * carrier subscribed to every event nor spam at the public URL floods the log.
     * A delivery matched to a shipment gets its own bucket per shipment: its row
     * lands on that order, so one busy shipment neither floods its order nor
     * silences another order's row.
     *
     * @return int|false|null Null: not throttled. False: suppress. Int: log, after
     *                        this many suppressed deliveries.
     */
    protected static function throttleCarrierWebhookLog(string $carrierSlug, string $outcome, int $shipmentId = 0)
    {
        if ($outcome === 'applied' || $outcome === 'error') {
            return null;
        }

        $bucket = strpos($outcome, 'rejected:') === 0 ? 'rejected' : preg_replace('/^ignored:/', '', $outcome);
        $key    = 'fct_carrier_webhook_log_' . $carrierSlug . '_' . $bucket . ($shipmentId > 0 ? '_s' . $shipmentId : '');
        $state  = get_transient($key);
        $now    = time();

        if (is_array($state) && $now < (int) Arr::get($state, 'until', 0)) {
            $state['suppressed'] = (int) Arr::get($state, 'suppressed', 0) + 1;
            set_transient($key, $state, DAY_IN_SECONDS);

            return false;
        }

        // Outlives the window so the next entry can report what was suppressed.
        set_transient($key, ['until' => $now + HOUR_IN_SECONDS, 'suppressed' => 0], DAY_IN_SECONDS);

        return is_array($state) ? (int) Arr::get($state, 'suppressed', 0) : 0;
    }

    public function init($app)
    {
        $app->router->group(function ($router) {
            require_once __DIR__ . '/Http/shipping-api.php';
        });

        (new ScriptHandler())->register();

        $carrierManager = CarrierManager::getInstance();
        $carrierManager->register('manual', new ManualCarrier(new ManualCarrierSettings()));

        $externalCarriers = apply_filters('fluent_cart/shipping/carrier_providers', []);
        if (!is_array($externalCarriers)) {
            $externalCarriers = [];
        }

        foreach ($externalCarriers as $slug => $externalCarrier) {
            // A misbehaving add-on must not fatal the site on fluentcart_loaded.
            if (!$externalCarrier instanceof CarrierContract) {
                _doing_it_wrong(
                    __METHOD__,
                    'Values returned from the fluent_cart/shipping/carrier_providers filter must implement CarrierContract.',
                    esc_html(FLUENTCART_VERSION)
                );
                continue;
            }
            $carrierManager->register($slug, $externalCarrier);
        }
    }

    public function handleItemsChanges($data)
    {
        $scope = Arr::get($data, 'scope');

        $ignores = [
            'apply_coupons',
            'discounts_recalculated',
            'remove_coupon',
            'payment_method_fee_recalculate',
        ];

        if (in_array($scope, $ignores)) {
            return;
        }

        $cart = Arr::get($data, 'cart');
        if (!$cart->requireShipping()) {
            return;
        }

        $fillData = $this->getRecalculatedCartDataArr([
            'cart_data'     => $cart->cart_data,
            'checkout_data' => $cart->checkout_data
        ], $cart);

        $cart->fill($fillData);

        // Recalculating is always right; PERSISTING is not. A listener on
        // `cart_data_items_updated` must not be the thing that first writes a
        // cart row — a caller may be recalculating an in-memory cart purely to
        // render totals, and `fct_carts` has no PRIMARY KEY, one index and only
        // a 30-day sweep, so a row written on a passive render is expensive and
        // effectively permanent.
        //
        // Every dispatch site in core saves the cart immediately BEFORE firing
        // this action (Cart::addItem/removeItem, the coupon paths, modal
        // checkout), so `exists` is already true there and nothing changes for
        // them. `fill()` still runs unconditionally, so in-memory totals are
        // correct either way.
        if ($cart->exists) {
            $cart->save();
        }
    }

    public function maybeRecalculateShippingCharges($fillData, $data)
    {
        $cart = $data['cart'];
        if (!$cart->requireShipping()) {
            return $fillData;
        }

        // Live carriers price by postcode and city too. Billing changes reach
        // shipping_* through mergeBillingWithShipping() when not shipping elsewhere.
        $watchingKeys = [
            'shipping_country',
            'shipping_state',
            'shipping_postcode',
            'shipping_city',
            'shipping_method_id',
            'ship_to_different'
        ];

        $changes = Arr::get($data, 'changes', []);
        // Live carriers also quote with the contact, once it is complete (AddressHelper::quoteContact()).
        $prevForm = (array) Arr::get((array) ($cart->checkout_data ?? []), 'form_data', []);
        $newForm = (array) Arr::get($fillData, 'checkout_data.form_data', []);
        $contactChanged = AddressHelper::quoteContact($prevForm) !== AddressHelper::quoteContact($newForm);
        // A carrier that builds its own request may quote from any field; its cache
        // keeps a request that did not change free.
        $formChanged = !$contactChanged
            && AddressHelper::quoteFormData(AddressHelper::checkoutFormFields((array) ($cart->checkout_data ?? [])))
                !== AddressHelper::quoteFormData(AddressHelper::checkoutFormFields((array) Arr::get($fillData, 'checkout_data', [])))
            && AddressHelper::carriersBuildRequests();
        if (!$contactChanged && !$formChanged && !array_intersect(array_keys($changes), $watchingKeys)) {
            // No changes detected
            return $fillData;
        }

        Arr::set($fillData, 'hook_changes.shipping', true);
        $fillData = $this->getRecalculatedCartDataArr($fillData, $cart);

        return $fillData;
    }

    private function getRecalculatedCartDataArr($fillData, $cart)
    {
        $shippingCountry = Arr::get($fillData, 'checkout_data.form_data.shipping_country', null);
        $shippingState = Arr::get($fillData, 'checkout_data.form_data.shipping_state', null);
        $addressPrefix = 'shipping_';

        // Checkout renderer resolves shipping methods with a billing fallback when no
        // shipping address is set (ship-to-different unchecked). Resolve the same way
        // here, otherwise this chain zeroes the method/charges the renderer just offered.
        if (!$shippingCountry) {
            $shippingCountry = Arr::get($fillData, 'checkout_data.form_data.billing_country', null);
            $shippingState = Arr::get($fillData, 'checkout_data.form_data.billing_state', null);
            $addressPrefix = 'billing_';
        }

        $availableShippingMethods = AddressHelper::getShippingMethods($shippingCountry, $shippingState, null, (array) Arr::get($fillData, 'cart_data', []), [
            'postcode'  => Arr::get($fillData, 'checkout_data.form_data.' . $addressPrefix . 'postcode', ''),
            'city'      => Arr::get($fillData, 'checkout_data.form_data.' . $addressPrefix . 'city', ''),
            'form_data' => AddressHelper::checkoutFormFields((array) Arr::get($fillData, 'checkout_data', [])),
        ] + AddressHelper::quoteContact((array) Arr::get($fillData, 'checkout_data.form_data', [])));

        if (!$availableShippingMethods || is_wp_error($availableShippingMethods)) {
            Arr::set($fillData, 'checkout_data.shipping_data.shipping_charge', 0);
            Arr::set($fillData, 'checkout_data.shipping_data.shipping_method_id', null);

            $cartData = Arr::get($fillData, 'cart_data', []);
            foreach ($cartData as &$cartItem) {
                $cartItem['shipping_charge'] = 0;
                $cartItem['itemwise_shipping_charge'] = 0;
            }
            unset($cartItem);

            Arr::set($fillData, 'cart_data', $cartData);

            return $fillData;
        }

        $prevShippingMethodId = Arr::get($fillData, 'checkout_data.shipping_data.shipping_method_id', null);

        $shippingRate = Arr::first($availableShippingMethods);
        if ($prevShippingMethodId) {
            foreach ($availableShippingMethods as $rate) {
                if ((string) Arr::get($rate, 'id') === (string) $prevShippingMethodId) {
                    $shippingRate = $rate;
                    break;
                }
            }
        }

        $rateId = Arr::get($shippingRate, 'id');
        $isZoneMethod = Arr::get($shippingRate, 'carrier') === 'manual' && is_numeric($rateId);

        // Use profile-aware calculation if any cart items have shipping classes
        $cartData = Arr::get($fillData, 'cart_data', []);
        $hasShippingClasses = $isZoneMethod && $this->cartHasShippingClasses($cartData);

        if ($hasShippingClasses && $shippingCountry) {
            $calculations = CartHelper::calculateShippingByProfile(
                $rateId,
                $cartData,
                $shippingCountry,
                $shippingState,
                'items'
            );
        } else {
            $calculations = CartHelper::itemizeSelectedRate($shippingRate, $cartData);
        }

        $newCharge = Arr::get($calculations, 'shipping_amount', 0);
        $items = Arr::get($calculations, 'items', []);

        Arr::set($fillData, 'cart_data', $items);

        Arr::set($fillData, 'checkout_data.shipping_data.shipping_charge', $newCharge);
        Arr::set($fillData, 'checkout_data.shipping_data.shipping_method_id', $rateId);

        return $fillData;
    }

    private function cartHasShippingClasses($cartData)
    {
        if (empty($cartData)) {
            return false;
        }

        $postIds = array_unique(array_column($cartData, 'post_id'));
        if (empty($postIds)) {
            return false;
        }

        $products = \FluentCart\App\Models\Product::query()
            ->whereIn('ID', $postIds)
            ->with(['detail'])
            ->get();

        foreach ($products as $product) {
            if ($product->detail && !empty($product->detail->other_info['shipping_class'])) {
                return true;
            }
        }

        return false;
    }

}

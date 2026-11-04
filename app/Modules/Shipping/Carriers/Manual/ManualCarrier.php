<?php

namespace FluentCart\App\Modules\Shipping\Carriers\Manual;

use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\ShippingMethod;
use FluentCart\App\Modules\MultiCurrency\Services\CartCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CouponCurrency;
use FluentCart\App\Modules\Shipping\Carriers\Core\AbstractShippingCarrier;
use FluentCart\App\Services\Shipments\ShipmentInstance;
use FluentCart\Framework\Support\Arr;

class ManualCarrier extends AbstractShippingCarrier
{
    public function meta(): array
    {
        return [
            'slug'        => 'manual',
            'name'        => __('Manual / Other', 'fluent-cart'),
            'description' => __('Zone-based shipping rates defined manually. Disable to remove these methods from checkout.', 'fluent-cart'),
            // Quotes its zone methods to fill the cost; buys no labels.
            'supports'    => ['rates'],
        ];
    }

    public function fields(): array
    {
        return [
            [
                'key'   => 'is_active',
                'type'  => 'toggle',
                'label' => __('Enable Manual Shipping', 'fluent-cart'),
                'hint'  => __('When enabled, zone-based shipping methods appear at checkout.', 'fluent-cart'),
            ],
        ];
    }

    /**
     * The store's zone methods as rates. Each rate keeps the method's own fields
     * (type, amount, settings, ...) so list consumers that read them still work.
     * The cost is already in the checkout currency: the zone calculator converts
     * it, so it is labelled with that currency and not converted again.
     *
     * @return array[]
     */
    public function getRates(array $args): array
    {
        $country = (string) Arr::get($args, 'country', '');
        if (!$country) {
            return [];
        }

        $items = Arr::get($args, 'items');
        if (!is_array($items)) {
            $cart = CartHelper::getCart();
            $items = $cart ? (array) $cart->cart_data : [];
        }
        $currency = strtoupper((string) Arr::get(CartCurrency::currentQuote(), 'currency', ''));

        $rates = [];
        foreach (ShippingMethod::getApplicableForCountry($country, Arr::get($args, 'state')) as $method) {
            // Unlisted, place-order rejects a stale selection of it instead of charging nothing.
            if (!CartHelper::shippingMethodCoversItems($method, $items)) {
                continue;
            }

            $charge = CartHelper::calculateShippingMethodCharge($method, $items);
            if (is_array($charge)) {
                $charge = Arr::get($charge, 'shipping_amount', 0);
            }

            $rates[] = array_merge($method->toArray(), [
                'id'             => (int) $method->id,
                'carrier'        => 'manual',
                'title'          => (string) $method->title,
                'cost'           => Helper::roundCent($charge),
                'currency'       => $currency,
                'states'         => is_array($method->states) ? $method->states : [],
                'description'    => (string) Arr::get((array) $method->meta, 'description', ''),
                'estimated_days' => 0,
            ]);
        }

        return $rates;
    }

    /**
     * The zone methods for this order's address, priced for the items being
     * dispatched, as the customer saw them at checkout. Amounts are customer-facing,
     * so a non-base-currency order is priced at its own frozen rate, never a live one.
     *
     * @return array|\WP_Error|null Null when disabled: the shipment is still recordable by hand.
     */
    public function getShipmentQuote(ShipmentInstance $instance)
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $order   = $instance->order;
        $address = $order->shipping_address ? $order->shipping_address : $order->billing_address;

        if (!$address || !$address->country) {
            return new \WP_Error(
                'manual_no_address',
                __('This order has no shipping address, so shipping methods cannot be calculated.', 'fluent-cart')
            );
        }

        $state = $address->state ? $address->state : null;
        $items = static::buildQuoteItems($instance);

        $rates = CartCurrency::withQuote(CouponCurrency::orderQuote($order), function () use ($address, $state, $items) {
            return $this->getRates([
                'country' => $address->country,
                'state'   => $state,
                'items'   => $items,
            ]);
        });

        $formatted = [];
        foreach ($rates as $rate) {
            // Same state rule as the checkout list: states limit a method to them.
            $methodStates = isset($rate['states']) ? (array) $rate['states'] : [];
            if ($methodStates && (!$state || !in_array($state, $methodStates))) {
                continue;
            }

            $formatted[] = [
                'rate_object_id' => (string) Arr::get($rate, 'id', ''),
                'service_name'   => (string) Arr::get($rate, 'title', ''),
                'cost_cents'     => (int) Arr::get($rate, 'cost', 0),
                'currency'       => (string) Arr::get($rate, 'currency', ''),
                'estimated_days' => (int) Arr::get($rate, 'estimated_days', 0),
                'tracking_url_pattern' => (string) Arr::get($rate, 'meta.tracking_url_pattern', ''),
            ];
        }

        if (!$formatted) {
            return new \WP_Error(
                'manual_no_rates',
                __('No shipping methods are configured for this order\'s address.', 'fluent-cart')
            );
        }

        // Preselect the method the order was placed with, while it is still offered.
        $paidRateId = (string) Arr::get((array) $order->config, 'shipping_method_id', '');
        $selected   = $formatted[0];
        foreach ($formatted as $rate) {
            if ($paidRateId !== '' && $rate['rate_object_id'] === $paidRateId) {
                $selected = $rate;
                break;
            }
        }

        return array_merge($selected, ['rates' => $formatted]);
    }

    /**
     * Cart-shaped lines for the shipping calculator, covering only what ships now.
     * A method's cost can depend on quantity or line value, so money fields are
     * pro-rated by the dispatched share.
     *
     * @return array[]
     */
    protected static function buildQuoteItems(ShipmentInstance $instance): array
    {
        $dispatchedQty = [];
        foreach ($instance->items as $dispatched) {
            $itemId = (int) Arr::get($dispatched, 'order_item_id', 0);
            $qty    = (int) Arr::get($dispatched, 'qty', 0);
            if ($itemId && $qty > 0) {
                $dispatchedQty[$itemId] = $qty;
            }
        }

        if (!$dispatchedQty) {
            return [];
        }

        // Scoped to the order: the ids come from the client.
        $orderItems = OrderItem::whereIn('id', array_keys($dispatchedQty))
            ->where('order_id', $instance->order->id)
            ->get();

        $items = [];
        foreach ($orderItems as $orderItem) {
            $item        = $orderItem->toArray();
            $originalQty = (int) Arr::get($item, 'quantity', 0);
            $qty         = $dispatchedQty[(int) $orderItem->id];

            if ($originalQty > 0 && $qty !== $originalQty) {
                $share = $qty / $originalQty;
                foreach (['line_total', 'discount_total', 'subtotal'] as $field) {
                    $item[$field] = Helper::roundCent((float) Arr::get($item, $field, 0) * $share);
                }
            }

            $item['quantity'] = $qty;
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @return array|\WP_Error Normalized settings, or WP_Error to reject the save.
     */
    public function validateSettings(array $settings)
    {
        // Only validate submitted keys — absent keys keep their stored value
        // (the save path merges into current settings).
        if (array_key_exists('is_active', $settings)) {
            $settings['is_active'] = $settings['is_active'] === 'yes' ? 'yes' : 'no';
        }

        return $settings;
    }

    /**
     * @return string|\WP_Error The sanitized pattern, '' when empty, or WP_Error when unusable.
     */
    public static function sanitizeTrackingUrlPattern($value)
    {
        $pattern = is_string($value) ? sanitize_text_field($value) : '';
        if ($pattern === '') {
            return '';
        }

        $sampleUrl = str_replace('{tracking_number}', '123456789', $pattern);
        if (
            strpos($pattern, '{tracking_number}') === false
            || !preg_match('#^https?://#i', $sampleUrl)
            || !esc_url_raw($sampleUrl, ['http', 'https'])
        ) {
            return new \WP_Error(
                'invalid_tracking_url_pattern',
                __('Tracking URL pattern must be a valid http(s) URL containing the {tracking_number} placeholder.', 'fluent-cart')
            );
        }

        return $pattern;
    }

    /**
     * @param int $methodId The zone method picked in the shipment drawer; its pattern builds the URL.
     */
    public function getTrackingUrl(string $trackingNumber, $methodId = 0): string
    {
        $pattern = '';
        $method  = $methodId ? ShippingMethod::find((int) $methodId) : null;
        if ($method) {
            $pattern = Arr::get((array) $method->meta, 'tracking_url_pattern', '');
        }
        if (!$pattern || !is_string($pattern)) {
            return '';
        }

        // Defense in depth: stored patterns are validated on save, but never
        // emit a non-http(s) URL to a consumer (emails, customer pages).
        // Encoded so characters like & ? # / stay inside the tracking number.
        $url = esc_url_raw(str_replace('{tracking_number}', rawurlencode($trackingNumber), $pattern), ['http', 'https']);

        return $url ? $url : '';
    }
}

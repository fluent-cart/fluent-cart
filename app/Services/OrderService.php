<?php

namespace FluentCart\App\Services;

use FluentCart\Api\ModuleSettings;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Model;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\Subscription;
use FluentCart\Framework\Support\Arr;

class OrderService
{
    /**
     * Group address and other data from the order data array.
     *
     * @param array $order_data
     * @return array
     */
    public static function groupSanitizedData($order_data = []): array
    {

        $billingAddress = static::extractAddressData($order_data, 'billing_');
        $shippingAddress = static::extractAddressData($order_data, 'shipping_');
        $others = static::extractOtherData($order_data);

        if (Arr::get($others, 'ship_to_different', 'no') === 'no') {
            $shippingAddress = $billingAddress;
        }

        $billingAddress = static::finalizeAddress($billingAddress, 'billing');
        $shippingAddress = static::finalizeAddress($shippingAddress, 'shipping');

        return [
            'billing_address'  => $billingAddress,
            'shipping_address' => $shippingAddress,
            'others'           => $others,
        ];
    }

    private static function extractAddressData($data, $prefix): array
    {
        $address = [];
        foreach ($data as $key => $value) {
            if (strpos($key, $prefix) === 0) {
                $newKey = str_replace($prefix, '', $key);
                $address[$newKey] = static::sanitizeEmailOrText($newKey, $value);
            }
        }
        return $address;
    }

    private static function extractOtherData($data): array
    {
        $others = [];
        foreach ($data as $key => $value) {
            if (strpos($key, 'billing_') !== 0 && strpos($key, 'shipping_') !== 0) {
                $others[$key] = sanitize_text_field($value);
            }
        }

        return $others;
    }

    private static function finalizeAddress($address, $type): array
    {
        $fullName = Arr::get($address, 'full_name', '');
        $name = Arr::get($address, 'name', '');
        $address['full_name'] = $fullName ?? $name;
        $address['first_name'] = AddressHelper::guessFirstNameAndLastName($address['full_name']);
        $address['type'] = $type;
        $name = $fullName ?? $name;
        $address = array_merge($address, AddressHelper::guessFirstNameAndLastName($name));
        $address['name'] = Arr::get($address, 'first_name', '') . ' ' . Arr::get($address, 'last_name', '');
        return $address;
    }


    /**
     * Sanitize email or text field based on the key.
     *
     * @param string $key
     * @param string $value
     * @return string
     */
    public static function sanitizeEmailOrText($key, $value): string
    {
        return $key === 'email' ? sanitize_email($value ?? '') : sanitize_text_field($value ?? '');
    }

    /**
     * Pluck product IDs from the order.
     *
     * @param Order $order
     * @return array
     */
    public static function pluckProductIds(Order $order): array
    {
        return array_column($order->order_items->toArray(), 'post_id');
    }


    /**
     * Validate products and check if they are available by stock.
     *
     * @param array $products
     * @return void
     * @throws \Exception
     */
    public static function validateProducts($products,$prevOrder = null)
    {
        $itemIds = array_column($products, 'object_id');
        $currentVariations = ProductVariation::query()
            ->whereIn('id', $itemIds)
            ->with(['product.detail', 'product_detail'])
            ->get()
            ->keyBy('id')
            ->toArray();

        foreach ($products as $product) {
            static::validateProductAvailability($product, $currentVariations);
            static::validateSubscriptionQuantity($product);
            static::validateStockStatus($product, $currentVariations, $prevOrder);
            static::validateStockQuantity($product, $currentVariations, $prevOrder);
        }
    }

    private static function validateProductAvailability($product, $currentVariations)
    {
        $currentVariation = $currentVariations[$product['object_id']] ?? null;

        if (
            empty($currentVariation) ||
            empty($currentVariation['product']) ||
            $currentVariation['product']['post_status'] !== 'publish'
        ) {
            throw new \Exception(sprintf(
                /* translators: %s: product title */
                esc_html__('[%s] is not available.', 'fluent-cart'),
                esc_html(Arr::get($product, 'title'))
            ));
        }
    }

    private static function validateSubscriptionQuantity($product)
    {
        $paymentType = Arr::get($product, 'other_info.payment_type', 'onetime');

        if ($paymentType === 'subscription' && Arr::get($product, 'quantity') > 1) {
            throw new \Exception(
                sprintf(
                    /* translators: %s: product title */
                    esc_html__('You cannot purchase multiple quantities of the subscription product [%s]. Please adjust the quantity to 1.', 'fluent-cart'),
                    esc_html(Arr::get($product, 'title'))
                )
            );
        }
    }

    private static function validateStockStatus($product, $currentVariations, $prevOrder = null)
    {
        if (!ModuleSettings::isActive('stock_management')) {
            return;
        }

        $currentVariation = $currentVariations[$product['object_id']] ?? null;

        // check if $prevOrder is not null.
        if ($prevOrder) {

            // get order_items from OrderItems
            $orderItems = $prevOrder->order_items;

            // filter payment_type is not signup_fee
            $orderItems = $orderItems->filter(function ($item) {
                return $item->payment_type !== 'signup_fee';
            });

            $stockMovement = OrderMeta::where('order_id', $prevOrder->id)
                ->where('meta_key', 'stock_movement')
                ->value('meta_value');

            // Decode stock movement if it's JSON
            $stockMovementData = $stockMovement ?? [];

            // Check if the current product variation is already on hold in stock movement
            $isStockOnHold = false;

            if (!empty($stockMovementData)) {

                // Find the order item that matches this variation ID
                foreach ($orderItems as $item) {
                    if ($item->object_id == Arr::get($currentVariation, 'id')) {
                        $orderItemId = $item->id;

                        // Check if this order item has stock on hold
                        // Structure: {"95":{"committed":10,"on_hold":0}} where 95 is order item ID
                        if (isset($stockMovementData[$orderItemId]['on_hold']) &&
                            $stockMovementData[$orderItemId]['on_hold'] > 0) {
                            $isStockOnHold = true;
                            break;
                        }
                    }
                }
            }


            // If stock is already on hold for this variation, skip stock validation
            if ($isStockOnHold) {
                // Stock is already reserved for this order, no need to check
                return;
            }

            // If stock is NOT on hold, perform normal stock validation
            if (
                Arr::get($currentVariation, 'stock_status') !== "in-stock" &&
                Arr::get($currentVariation, 'manage_stock') == 1
            ) {
                throw new \Exception(sprintf(
                    /* translators: %s: product title */
                    esc_html__('[%s] is out of stock.', 'fluent-cart'),
                    esc_html(Arr::get($product, 'title'))
                ));
            }

        } else {

            // No previous order, perform normal stock validation
            if (
                Arr::get($currentVariation, 'stock_status') !== "in-stock" &&
                Arr::get($currentVariation, 'manage_stock') == 1
            ) {
                throw new \Exception(sprintf(
                /* translators: %s: product title */
                    esc_html__('[%s] is out of stock.', 'fluent-cart'),
                    esc_html(Arr::get($product, 'title'))
                ));
            }
        }
    }

    private static function validateStockQuantity($product, $currentVariations, $prevOrder = null)
    {
        if (!ModuleSettings::isActive('stock_management')) {
            return;
        }
        $currentVariation = $currentVariations[$product['object_id']] ?? null;
        $productQuantity = (int)Arr::get($product, 'quantity');

        // check if $prevOrder is not null
        if ($prevOrder) {

            // get order_items from OrderItems
            $orderItems = $prevOrder->order_items;


            $orderItems = $orderItems->filter(function ($item) {
                return $item->payment_type !== 'signup_fee';
            });

            // get stock movement for this order
            $stockMovement = OrderMeta::where('order_id', $prevOrder->id)
                ->where('meta_key', 'stock_movement')
                ->value('meta_value');

            // Decode stock movement if it's JSON
            $stockMovementData = $stockMovement ?? [];

            // Check if the current product variation is already on hold in stock movement
            $isStockOnHold = false;

            if (!empty($stockMovementData)) {

                // Find the order item that matches this variation ID
                foreach ($orderItems as $item) {
                    if ($item->object_id == Arr::get($currentVariation, 'id')) {
                        $orderItemId = $item->id;

                        // Check if this order item has stock on hold
                        // Structure: {"95":{"committed":10,"on_hold":0}} where 95 is order item ID
                        if (isset($stockMovementData[$orderItemId]['on_hold']) &&
                            $stockMovementData[$orderItemId]['on_hold'] > 0) {
                            $isStockOnHold = true;
                            break;
                        }
                    }
                }
            }

            // If stock is already on hold for this variation, skip quantity validation
            if ($isStockOnHold) {
                // Stock is already reserved for this order, no need to check quantity
                return;
            }
        }

        // Perform normal quantity validation if no previous order OR stock is not on hold
        if (!static::allowItemToOrder($currentVariation, $productQuantity)) {
            $note = sprintf(
                /* translators: %s: product title */
                esc_html__('[%1$s] is out of stock. Only %2$s left.', 'fluent-cart'),
                esc_html(Arr::get($product, 'title')),
                esc_html(Arr::get($currentVariation, 'available'))
            );
            throw new \Exception(esc_html($note));
        }
    }

    /**
     * Check if the item can be ordered based on stock availability.
     *
     * @param array $variation
     * @param int $updatedQuantity
     * @return bool
     */
    private static function allowItemToOrder($variation, $updatedQuantity): bool
    {
        if (Arr::get($variation, 'manage_stock') == 0) {
            return true;
        }
        return $updatedQuantity <= Arr::get($variation, 'available');
    }

    /**
     * Get the total amount of items without discount.
     *
     * @param array $orderItems
     * @return float
     */
    public static function getItemsAmountWithoutDiscount(array $orderItems)
    {
        $total = 0;
        foreach ($orderItems as $orderItem) {
            $paymentType = Arr::get($orderItem, 'payment_type');
            if (!$paymentType) {
                $paymentType = Arr::get($orderItem, 'other_info.payment_type');
            }

            $total += self::calculateItemTotal($orderItem, $paymentType);

        }
        return $total;
    }

    private static function isDiscountedSubscription($orderItem): bool
    {
        $paymentType = Arr::get($orderItem, 'other_info.payment_type', '');
        return $paymentType === 'subscription' && Arr::get($orderItem, 'discount_total', 0) > 0;
    }

    private static function isPlanChangeAdjustment($orderItem, $paymentType): bool
    {
        $orderType = Arr::get($orderItem, 'other_info.order_type', '');
        return $orderType === 'plan_change' && $paymentType === 'adjustment';
    }

    private static function calculateItemTotal($orderItem, $paymentType): float
    {

        if (in_array($paymentType, ['signup_fee'], true)) {
            return floatval($orderItem['unit_price']);
        }

        if ($paymentType == 'subscription') {
            if ((int)Arr::get($orderItem, 'other_info.trial_days', 0) > 0) {
                return (int)Arr::get($orderItem, 'other_info.signup_fee', 0);
            }
            return intval(($orderItem['unit_price'] * $orderItem['quantity'])) + (int)Arr::get($orderItem, 'other_info.signup_fee', 0);
        }

        return intval(($orderItem['unit_price'] * $orderItem['quantity']));
    }

    /**
     * Get the total amount of items.
     *
     * @param array|Model $items
     * @param bool $formatted
     * @param bool $withCurrency
     * @return float|string
     */
    public static function getItemsAmountTotal($items = [], $formatted = true, $withCurrency = true, $shippingTotal = 0)
    {
        $items = $items instanceof Model ? $items->toArray() : $items;

        $total = 0;

        foreach ($items as $cartItem) {
            // according to  cart structure, line total is item_price * quantity
            $subtotal = floatval(Arr::get($cartItem, 'subtotal', 0));
            $discountTotal = floatval(Arr::get($cartItem, 'discount_total', 0));

            $otherInfo = Arr::get($cartItem, 'other_info', []);
            if (is_object($otherInfo)) {
                $otherInfo = (array)$otherInfo;
            }

            $trialDays = intval(Arr::get($otherInfo, 'trial_days', 0));
            $signupFee = floatval(Arr::get($otherInfo, 'signup_fee', 0));

            if ($trialDays > 0) {
                $total += $signupFee;
                continue;
            }


            $total += $subtotal - $discountTotal;


            $total += floatval($signupFee - Arr::get($otherInfo, 'signup_discount', 0));
        }

        $total += $shippingTotal;

        return $formatted ? Helper::toDecimal($total, $withCurrency) : intval($total);
    }

    private static function calculateItemAmount(array $cartItem, $price, $paymentType, $formatted, $withCurrency)
    {
        if ($paymentType === 'signup_fee') {
            return $price;
        }
        if ($paymentType === 'adjustment') {
            return $formatted ? Helper::toDecimal(intval($price), $withCurrency) : intval($price);
        }
        $quantity = intval(Arr::get($cartItem, 'quantity', 1));
        $discount = floatval(Arr::get($cartItem, 'discount_total', 0));
        return (($price * $quantity) - $discount);
    }

    /**
     * Create line items from order items.
     *
     * @param array $orderItems
     * @return array
     */
    public static function makeLineItemsFromOrderItems($orderItems): array
    {
        $subscriptionItems = [];
        $items = [];

        foreach ($orderItems as $orderItem) {
            $orderItem = $orderItem instanceof Model ? $orderItem->toArray() : $orderItem;
            if (static::isSubscriptionItem($orderItem)) {
                $subscriptionItems[] = static::prepareSubscriptionItem($orderItem);
            } else {
                $items[] = static::prepareRegularItem($orderItem);
            }
        }

        return [
            'items'             => $items,
            'subscriptionItems' => $subscriptionItems
        ];
    }

    private static function isSubscriptionItem(array $orderItem): bool
    {
        return Arr::get($orderItem, 'payment_type') === 'subscription';
    }

    private static function prepareSubscriptionItem(array $orderItem): array
    {
        $orderItem['other_info'] = Arr::get($orderItem, 'other_info', []);

        $otherInfo = (array)Arr::get($orderItem, 'other_info');

        return [
            'product_id'          => Arr::get($orderItem, 'post_id'),
            'object_id'           => Arr::get($orderItem, 'object_id', ''),
            'billing_interval'    => Arr::get($otherInfo, 'repeat_interval'),
            'signup_fee'          => Arr::get($otherInfo, 'signup_fee'),
            'bill_times'          => Arr::get($otherInfo, 'times'),
            'recurring_amount'    => floatval(Arr::get($orderItem, 'unit_price')) * intval(Arr::get($orderItem, 'quantity')),
            'unit_price'          => floatval(Arr::get($orderItem, 'unit_price', 0)),
            'recurring_tax_total' => floatval(Arr::get($orderItem, 'tax_amount')),
            'recurring_total'     => floatval(Arr::get($orderItem, 'unit_price')),
            'line_total'          => floatval(Arr::get($orderItem, 'line_total')),
            'trial_days'          => empty(Arr::get($otherInfo, 'trial_days')) ? 0 : Arr::get($otherInfo, 'trial_days'),
            'item_name'           => Arr::get($orderItem, 'title') . ' ' . Arr::get($orderItem, 'post_title'),
            'title'               => Arr::get($orderItem, 'title') . ' ' . Arr::get($orderItem, 'post_title'),
            'quantity'            => intval(Arr::get($orderItem, 'quantity')),
            'variation_id'        => Arr::get($orderItem, 'object_id', 0),
            'id'                  => Arr::get($orderItem, 'id'),
            'other_info'          => Arr::get($orderItem, 'other_info'),
        ];
    }

    private static function prepareRegularItem(array $orderItem): array
    {
        return [
            'product_id'     => Arr::get($orderItem, 'post_id'),
            'object_id'      => Arr::get($orderItem, 'object_id', ''),
            'payment_type'   => Arr::get($orderItem, 'payment_type'),
            'variation_id'   => Arr::get($orderItem, 'id'),
            'quantity'       => intval(Arr::get($orderItem, 'quantity')),
            'post_title'     => Arr::get($orderItem, 'post_title'),
            'title'          => Arr::get($orderItem, 'title'),
            'unit_price'     => floatval(Arr::get($orderItem, 'unit_price', 0)),
            'item_price'     => floatval(Arr::get($orderItem, 'unit_price')),
            'line_total'     => floatval(Arr::get($orderItem, 'line_total')),
            'fallback_title' => Arr::get($orderItem, 'title'),
        ];
    }

    /**
     * Get the root order ID from a given order.
     *
     * @param Order $order
     * @return id|null
     */
    public static function getRootOrderId($order, $visited = [])
    {
        if (in_array($order->id, $visited)) {
            return null; // breaking the loop
        }

        $visited[] = $order->id;
        if (empty($order->parent_id)) {
            return $order->id;
        }

        $parentOrder = Order::query()->where('id', $order->parent_id)->first();

        if (!$parentOrder) {
            return $order->id;
        }

        return static::getRootOrderId($parentOrder, $visited);
    }

    public static function getCouponDiscountTotal($orderItems)
    {
        if (empty($orderItems)) {
            return 0;
        }

        $coupon_discount_total = 0;
        foreach ($orderItems as $item) {
            $coupon_discount_total += Arr::get($item, 'discount_total', 0);
        }

        return $coupon_discount_total;
    }

    public static function getNextReceiptNumber()
    {
        $lastOrder = Order::query()->max('receipt_number');
        if (empty($lastOrder)) {
            $lastOrder = 0;
        }

        $nextOrderNumber = $lastOrder + 1;

        $min_receipt_number = (new StoreSettings())->get('min_receipt_number') ?? 1;
        $minReceiptNumber = apply_filters('fluent_cart/min_receipt_number', $min_receipt_number);

        if ($nextOrderNumber < $minReceiptNumber) {
            $nextOrderNumber = $minReceiptNumber;
        }

        return $nextOrderNumber;
    }

    public static function getInvoicePrefix()
    {
        $prefix = (new StoreSettings())->get('inv_prefix') ?? 'INV-';
        return apply_filters('fluent_cart/invoice_prefix', $prefix);
    }


    public static function transformSubscription(Subscription $subscription)
    {
        return [
            'uuid'                   => $subscription->uuid,
            'vendor_subscription_id' => $subscription->vendor_subscription_id,
            'status'                 => $subscription->status,
            'overridden_status'      => $subscription->overridden_status,
            'next_billing_date'      => $subscription->next_billing_date,
            'billing_info'           => $subscription->billingInfo,
            'current_payment_method' => $subscription->current_payment_method,
            'payment_method'         => $subscription->payment_method,
            'payment_info'           => $subscription->payment_info,
            'bill_times'             => $subscription->bill_times,
            'bill_count'             => $subscription->bill_count,
            'config'                 => $subscription->config,
            'reactivate_url'         => $subscription->getReactivateUrl(),
            'can_upgrade'            => $subscription->canUpgrade(),
            'can_switch_payment_method' => $subscription->canSwitchPaymentMethod(),
            'can_update_payment_method' => $subscription->canUpdatePaymentMethod(),
            'item_name'              => $subscription->item_name
        ];
    }

    public static function transformTransaction(OrderTransaction $transaction)
    {
        return [
            'uuid'             => $transaction->uuid,
            'invoice_no'       => $transaction->order ? $transaction->order->invoice_no : 'n/a',
            'created_at'       => $transaction->created_at->format('Y-m-d H:i:s'),
            'total'            => $transaction->total,
            'order_type'       => $transaction->order_type,
            'currency'         => $transaction->currency,
            'status'           => $transaction->status,
            'payment_method'   => $transaction->payment_method,
            'card_brand'       => $transaction->card_brand,
            'card_last_4'      => $transaction->card_last_4,
            'vendor_charge_id' => $transaction->vendor_charge_id,
            'transaction_type' => $transaction->transaction_type,
            'receipt_url'      => $transaction->order ? $transaction->order->getReceiptUrl() : '',
        ];
    }

}

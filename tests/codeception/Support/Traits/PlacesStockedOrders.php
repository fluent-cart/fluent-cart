<?php

namespace Tests\Support\Traits;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\ProductVariation;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;

/**
 * Stocked-order builder used by Functional/Rest/Stock/* Cests that exercise
 * the order_updated / order_status_changed / order_refunded / order_paid /
 * shipping_status_changed listeners. Each method seeds the post-order_created
 * state directly via factories — variant counters, OrderMeta stock_movement
 * entry, payment_status / shipping_status — so the test does not have to
 * re-walk the order_created path on every method. The HTTP action under test
 * (update, refund, cancel, deliver, pay, delete) then fires its event and the
 * listener computes its diff against the seeded fixture.
 *
 * Trait methods follow SKILL.md Step 6 Rule 4 — they build state via
 * factories, return the built ids, and carry no test assertions.
 */
trait PlacesStockedOrders
{
    /**
     * Seed a pending order with `quantity` units of `$variantId` reserved on
     * `on_hold`. Mirrors the post-order_created state: available decremented,
     * on_hold incremented, OrderMeta stock_movement keyed by order_item id.
     *
     * Returns [orderId, orderItemId].
     *
     * @return array{int, int}
     */
    protected function buildPendingOrderWithReservedStock(int $customerId, int $productId, int $variantId, int $quantity, int $unitPriceCents, string $fulfillmentType = 'digital', string $mode = Status::ORDER_MODE_LIVE): array
    {
        $variant = ProductVariation::query()->find($variantId);
        $newAvailable = max(0, (int) $variant->available - $quantity);
        $variant->available = $newAvailable;
        $variant->on_hold   = (int) $variant->on_hold + $quantity;
        $variant->stock_status = $newAvailable <= 0 ? 'out-of-stock' : 'in-stock';
        $variant->save();

        $order = (new OrderFactory())->create([
            'customer_id'      => $customerId,
            'status'           => Status::ORDER_ON_HOLD,
            'payment_status'   => Status::PAYMENT_PENDING,
            'shipping_status'  => Status::SHIPPING_UNSHIPPED,
            'fulfillment_type' => $fulfillmentType,
            'mode'             => $mode,
            'subtotal'         => $unitPriceCents * $quantity,
            'total_amount'     => $unitPriceCents * $quantity,
        ]);

        $orderItem = (new OrderItemFactory())->create($order->id, [
            'post_id'          => $productId,
            'object_id'        => $variantId,
            'quantity'         => $quantity,
            'unit_price'       => $unitPriceCents,
            'subtotal'         => $unitPriceCents * $quantity,
            'line_total'       => $unitPriceCents * $quantity,
            'fulfillment_type' => $fulfillmentType,
            'payment_type'     => 'onetime',
            'other_info'       => ['payment_type' => 'onetime'],
        ]);

        OrderMeta::query()->create([
            'order_id'   => $order->id,
            'meta_key'   => 'stock_movement',
            'meta_value' => [$orderItem->id => ['on_hold' => $quantity]],
        ]);

        return [(int) $order->id, (int) $orderItem->id];
    }

    /**
     * Seed a delivered physical order with `quantity` units of `$variantId`
     * committed. Mirrors the post-shipping_status_changed=delivered state:
     * available decremented, on_hold=0, committed incremented, stock_movement
     * recording the commit.
     *
     * Returns [orderId, orderItemId].
     *
     * @return array{int, int}
     */
    protected function buildDeliveredPhysicalOrderWithCommittedStock(int $customerId, int $productId, int $variantId, int $quantity, int $unitPriceCents, string $mode = Status::ORDER_MODE_LIVE): array
    {
        $variant = ProductVariation::query()->find($variantId);
        $newAvailable = max(0, (int) $variant->available - $quantity);
        $variant->available    = $newAvailable;
        $variant->committed    = (int) $variant->committed + $quantity;
        $variant->stock_status = $newAvailable <= 0 ? 'out-of-stock' : 'in-stock';
        $variant->save();

        $order = (new OrderFactory())->create([
            'customer_id'      => $customerId,
            'status'           => Status::ORDER_PROCESSING,
            'payment_status'   => Status::PAYMENT_PAID,
            'shipping_status'  => Status::SHIPPING_DELIVERED,
            'fulfillment_type' => 'physical',
            'mode'             => $mode,
            'subtotal'         => $unitPriceCents * $quantity,
            'total_amount'     => $unitPriceCents * $quantity,
            'total_paid'       => $unitPriceCents * $quantity,
        ]);

        $orderItem = (new OrderItemFactory())->create($order->id, [
            'post_id'          => $productId,
            'object_id'        => $variantId,
            'quantity'         => $quantity,
            'unit_price'       => $unitPriceCents,
            'subtotal'         => $unitPriceCents * $quantity,
            'line_total'       => $unitPriceCents * $quantity,
            'fulfillment_type' => 'physical',
            'payment_type'     => 'onetime',
            'other_info'       => ['payment_type' => 'onetime'],
        ]);

        OrderMeta::query()->create([
            'order_id'   => $order->id,
            'meta_key'   => 'stock_movement',
            'meta_value' => [$orderItem->id => ['committed' => $quantity, 'on_hold' => 0]],
        ]);

        return [(int) $order->id, (int) $orderItem->id];
    }

    /**
     * Seed a paid digital order with `quantity` units of `$variantId`
     * committed. Mirrors the post-order_paid state on a digital fulfillment:
     * available decremented, on_hold=0, committed incremented, stock_movement
     * recording the commit.
     *
     * Returns [orderId, orderItemId].
     *
     * @return array{int, int}
     */
    protected function buildPaidDigitalOrderWithCommittedStock(int $customerId, int $productId, int $variantId, int $quantity, int $unitPriceCents): array
    {
        $variant = ProductVariation::query()->find($variantId);
        $newAvailable = max(0, (int) $variant->available - $quantity);
        $variant->available    = $newAvailable;
        $variant->committed    = (int) $variant->committed + $quantity;
        $variant->stock_status = $newAvailable <= 0 ? 'out-of-stock' : 'in-stock';
        $variant->save();

        $order = (new OrderFactory())->create([
            'customer_id'      => $customerId,
            'status'           => Status::ORDER_PROCESSING,
            'payment_status'   => Status::PAYMENT_PAID,
            'shipping_status'  => Status::SHIPPING_UNSHIPPED,
            'fulfillment_type' => 'digital',
            'subtotal'         => $unitPriceCents * $quantity,
            'total_amount'     => $unitPriceCents * $quantity,
            'total_paid'       => $unitPriceCents * $quantity,
        ]);

        $orderItem = (new OrderItemFactory())->create($order->id, [
            'post_id'          => $productId,
            'object_id'        => $variantId,
            'quantity'         => $quantity,
            'unit_price'       => $unitPriceCents,
            'subtotal'         => $unitPriceCents * $quantity,
            'line_total'       => $unitPriceCents * $quantity,
            'fulfillment_type' => 'digital',
            'payment_type'     => 'onetime',
            'other_info'       => ['payment_type' => 'onetime'],
        ]);

        OrderMeta::query()->create([
            'order_id'   => $order->id,
            'meta_key'   => 'stock_movement',
            'meta_value' => [$orderItem->id => ['committed' => $quantity, 'on_hold' => 0]],
        ]);

        return [(int) $order->id, (int) $orderItem->id];
    }

    /**
     * Seed a successful charge transaction against an order built by one of
     * the methods above. The Refund service requires a charge transaction
     * with status=succeeded in order to record a refund row against it.
     *
     * Returns the transaction id.
     */
    protected function attachSucceededTransaction(int $orderId, int $amountCents): int
    {
        $txn = OrderTransaction::query()->create([
            'order_id'            => $orderId,
            'order_type'          => Status::ORDER_TYPE_PAYMENT,
            'transaction_type'    => Status::TRANSACTION_TYPE_CHARGE,
            'status'              => 'succeeded',
            'payment_method'      => 'offline_payment',
            'payment_mode'        => Status::ORDER_MODE_LIVE,
            'payment_method_type' => 'offline_payment',
            'currency'            => 'USD',
            'total'               => $amountCents,
            'uuid'                => md5((string) mt_rand()),
        ]);

        return (int) $txn->id;
    }
}

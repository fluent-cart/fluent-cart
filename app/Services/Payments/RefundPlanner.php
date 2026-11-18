<?php

namespace FluentCart\App\Services\Payments;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\Money;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Validator\Validator;

/**
 * Works out what an admin refund request refunds, from the order as stored: the amount, the units per
 * line and the shipping. The server prices every line itself. An item refund may post a lower amount:
 * shipping keeps what was entered for it and the difference comes off the item rows.
 */
class RefundPlanner
{
    /**
     * @param Order $order
     * @param array $refundInfo    transaction_id, amount, shipping_amount, manageStock, cancelSubscription.
     *                             With items selected, amount 0 or empty means the computed amount.
     * @param array $refundedItems [{id, refund_qty, shipped_qty}] shipped_qty: units of refund_qty that had
     *                             shipped. Physical, non-child lines only; defaults to the fewest possible.
     *
     * @return array|\WP_Error refund_amount, item_refunds, shipping_amount, refund_info (passed to
     *                         Refund::processRefund() as $args) and refund_intent (its $refundIntent).
     *                         `validation_failed` carries the validator's per-field errors.
     */
    public static function plan(Order $order, $refundInfo, $refundedItems)
    {
        $shippingCharged   = $order->shippingChargedTotal();
        $shippingRemaining = max(0, $shippingCharged - (int) $order->getMeta('shipping_refunded_amount'));
        $shippingAmount    = min(max(0, (int) Arr::get($refundInfo, 'shipping_amount', 0)), $shippingRemaining);

        $itemRefunds = [];

        if (!empty($refundedItems) || $shippingAmount > 0) {
            // A pending row's units and shipping are not counted yet, so they would be refunded again.
            $awaitingResync = OrderTransaction::query()
                ->where('order_id', $order->id)
                ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
                ->get()
                ->first(function ($refund) {
                    return $refund->status !== Status::TRANSACTION_FAILED && $refund->isAwaitingResync();
                });
            if ($awaitingResync) {
                return new \WP_Error('refund_resync_pending', __('Re-sync the pending refund first.', 'fluent-cart'));
            }

            $validator = Validator::make($refundInfo, [
                'transaction_id' => 'required',
            ], [
                'transaction_id.required' => __('Transaction ID is required', 'fluent-cart'),
            ]);
            if ($validator->validate()->fails()) {
                return new \WP_Error('validation_failed', __('Transaction ID is required', 'fluent-cart'), $validator->errors());
            }

            $refundAmount = 0;

            if (!empty($refundedItems)) {
                $requestedIds = array_values(array_unique(array_map(function ($i) {
                    return (int) Arr::get($i, 'id');
                }, $refundedItems)));

                $orderItemsById = OrderItem::query()
                    ->where('order_id', $order->id)
                    ->whereIn('id', $requestedIds)
                    ->get()
                    ->keyBy('id');

                foreach ($requestedIds as $rid) {
                    if (!$orderItemsById->has($rid)) {
                        return new \WP_Error('invalid_item', __('One or more selected items do not belong to this order.', 'fluent-cart'));
                    }
                }

                // A repeated id must not be priced twice against the same units left.
                $refundQtyById = [];
                $shippedQtyById = [];
                foreach ($refundedItems as $item) {
                    $id = (int) Arr::get($item, 'id');
                    $refundQtyById[$id] = (int) Arr::get($refundQtyById, $id, 0) + (int) Arr::get($item, 'refund_qty');
                    if (Arr::has($item, 'shipped_qty')) {
                        $shippedQtyById[$id] = (int) Arr::get($shippedQtyById, $id, 0) + (int) Arr::get($item, 'shipped_qty');
                    }
                }

                $roundUnit = function ($cents) use ($order) {
                    return (int) round(Money::roundIfZeroDecimal($cents, $order->currency));
                };

                foreach ($refundQtyById as $itemId => $requestedQty) {
                    $orderItem = $orderItemsById->get($itemId);

                    $lineMeta  = $orderItem->line_meta;
                    $unitsLeft = $orderItem->unitsLeft();
                    $refundQty = max(0, min($requestedQty, $unitsLeft));

                    // Same base the refund modal shows.
                    $remainingBase = max(0, $orderItem->chargedTotal($order) - (int) $orderItem->refund_total);

                    // A lowered refund counts its units, so money can stay on a line with none left.
                    // Units posted for such a line come from a stale modal; older modals post 1 for the signup fee.
                    $moneyOnly = $unitsLeft === 0 && $remainingBase > 0
                        && ($requestedQty <= 0 || $orderItem->payment_type === 'signup_fee');
                    if ($refundQty === 0 && !$moneyOnly) {
                        continue;
                    }

                    if ($refundQty >= $unitsLeft) {
                        // Refund the entire remaining base so trailing cents never strand.
                        $itemRefund = $remainingBase;
                    } else {
                        $itemRefund = min($remainingBase, $roundUnit($remainingBase / $unitsLeft) * $refundQty);
                    }

                    $refundAmount += $itemRefund;

                    $isChildItem  = !empty(Arr::get($lineMeta, 'parent_item_id'));
                    $fulfilledQty = (int) $orderItem->fulfilled_quantity;

                    $refundRow = [
                        'id'               => $orderItem->id,
                        'variation_id'     => $orderItem->object_id,
                        'refund_qty'       => $refundQty,
                        // Child items (signup_fee) share the parent's object_id; restocking them would add stock twice.
                        'restore_quantity' => $isChildItem ? 0 : $refundQty,
                        'restock_type'     => $isChildItem ? 'no_restock' : ($fulfilledQty > 0 ? 'return' : 'cancel'),
                        'pre_refund_qty'   => $unitsLeft,
                        'refund_amount'    => $itemRefund,
                    ];

                    if ($orderItem->fulfillment_type === Status::FULFILLMENT_TYPE_PHYSICAL && !$isChildItem) {
                        $bounds = self::shippedQtyBounds($orderItem, $refundQty);
                        $shippedQty = (int) Arr::get($shippedQtyById, $itemId, $bounds['min']);
                        if ($shippedQty < $bounds['min'] || $shippedQty > $bounds['max']) {
                            return new \WP_Error(
                                'invalid_shipped_qty',
                                /* translators: 1: item title, 2: fewest shipped units, 3: most shipped units */
                                sprintf(__('Already shipped for %1$s must be between %2$d and %3$d.', 'fluent-cart'), $orderItem->title, $bounds['min'], $bounds['max']),
                                ['status' => 422]
                            );
                        }
                        $refundRow['shipped_qty'] = $shippedQty;
                        // Shipped units move to committed; only unshipped units leave the hold.
                        $refundRow['restore_quantity'] = $refundQty - $shippedQty;
                        $refundRow['restock_type'] = 'cancel';
                    }

                    $itemRefunds[] = $refundRow;
                }
            }

            $refundAmount += $shippingAmount;

            // Plan-change prorate credit sits at order level, so line totals can exceed what was paid.
            // netAmount() drops MoR VAT (Paddle reverse-charge keeps total_paid gross).
            $netOrderPaidAmount = max(0, $order->netAmount((int) $order->total_paid) - (int) $order->total_refund - OrderTransaction::pendingRefundTotal($order->id));
            if ($refundAmount > $netOrderPaidAmount) {
                $excess = $refundAmount - $netOrderPaidAmount;
                $refundAmount = $netOrderPaidAmount;
                for ($i = count($itemRefunds) - 1; $i >= 0 && $excess > 0; $i--) {
                    $cut = min($excess, $itemRefunds[$i]['refund_amount']);
                    $itemRefunds[$i]['refund_amount'] -= $cut;
                    $excess -= $cut;
                }
                $shippingAmount -= min($excess, $shippingAmount);
            }

            if (!empty($itemRefunds)) {
                $postedAmount = Arr::get($refundInfo, 'amount');
                if ($postedAmount === null || $postedAmount === '') {
                    $postedAmount = 0;
                }
                if (!is_numeric($postedAmount) || $postedAmount < 0) {
                    return new \WP_Error('invalid_refund_amount', __('Invalid refund amount.', 'fluent-cart'), ['status' => 422]);
                }
                if (!Money::isWholeAmount($postedAmount, $order->currency)) {
                    return new \WP_Error('invalid_refund_precision', __('Refund amount must use the smallest unit of the transaction currency.', 'fluent-cart'), ['status' => 422]);
                }
                $postedAmount = Helper::roundCent($postedAmount);

                // 0 means the computed amount, so older clients keep refunding the full selection.
                if ($postedAmount > 0 && $postedAmount !== $refundAmount) {
                    if ($postedAmount > $refundAmount) {
                        return new \WP_Error('refund_amount_too_high', __('Refund amount cannot be more than the selected items and shipping.', 'fluent-cart'), ['status' => 422]);
                    }
                    if ($postedAmount <= $shippingAmount) {
                        return new \WP_Error('refund_amount_too_low', __('Refund amount must be more than the shipping refund.', 'fluent-cart'), ['status' => 422]);
                    }
                    $itemRefunds = self::cutItemRefunds($itemRefunds, $refundAmount - $postedAmount, $order->currency);
                    $refundAmount = $postedAmount;
                }
            }

            $refundInfo['item_ids']       = array_column($itemRefunds, 'id');
            $refundInfo['refunded_items'] = $itemRefunds;
            // The listener credits each item's own refund_amount instead of spreading the total.
            $refundInfo['is_item_refund'] = true;
            $refundInfo['manageStock']    = filter_var(Arr::get($refundInfo, 'manageStock', true), FILTER_VALIDATE_BOOLEAN);
        } else {
            $validator = Validator::make($refundInfo, [
                'transaction_id' => 'required',
                'amount'         => 'required',
            ], [
                'transaction_id.required' => __('Transaction ID is required', 'fluent-cart'),
                'amount.required'         => __('Refund amount is required', 'fluent-cart'),
            ]);
            if ($validator->validate()->fails()) {
                return new \WP_Error('validation_failed', __('Refund validation failed', 'fluent-cart'), $validator->errors());
            }
            $postedAmount = Arr::get($refundInfo, 'amount');
            if (is_numeric($postedAmount) && !Money::isWholeAmount($postedAmount, $order->currency)) {
                return new \WP_Error('invalid_refund_precision', __('Refund amount must use the smallest unit of the transaction currency.', 'fluent-cart'), ['status' => 422]);
            }
            $refundAmount = Helper::roundCent($postedAmount);
        }

        return [
            'refund_amount'   => $refundAmount,
            'item_refunds'    => $itemRefunds,
            'shipping_amount' => $shippingAmount,
            'refund_info'     => $refundInfo,
            'refund_intent'   => [
                'items'           => $itemRefunds,
                'shipping_amount' => $shippingAmount,
                'manage_stock'    => filter_var(Arr::get($refundInfo, 'manageStock', true), FILTER_VALIDATE_BOOLEAN),
            ],
        ];
    }

    /**
     * Takes $cut off the rows in proportion to each row's refund_amount, in whole currency units.
     * $cut is less than the rows' sum, so the loop always finds room for the leftover.
     *
     * @param array  $itemRefunds rows with refund_amount
     * @param int    $cut
     * @param string $currency
     *
     * @return array
     */
    private static function cutItemRefunds(array $itemRefunds, $cut, $currency)
    {
        $unit = Money::getDecimals($currency) === 0 ? 100 : 1;
        $total = (int) array_sum(array_column($itemRefunds, 'refund_amount'));

        $cuts = [];
        $fractions = [];
        foreach ($itemRefunds as $i => $row) {
            $amount = (int) $row['refund_amount'];
            if ($amount <= intdiv(PHP_INT_MAX, max(1, (int) $cut))) {
                $exact = (int) $cut * $amount;
                $cuts[$i] = intdiv(intdiv($exact, $total), $unit) * $unit;
                $fractions[$i] = ($exact - $cuts[$i] * $total) / $total;
            } else {
                // cut × amount passes PHP_INT_MAX on large zero-decimal lines.
                $share = $cut / $total * $amount;
                $cuts[$i] = min($amount, (int) floor($share / $unit) * $unit);
                $fractions[$i] = $share - $cuts[$i];
            }
        }

        arsort($fractions);
        $leftover = $cut - array_sum($cuts);
        foreach (array_reverse(array_keys($fractions)) as $i) {
            if ($leftover >= 0) {
                break;
            }
            $step = min(-$leftover, $cuts[$i]);
            $cuts[$i] -= $step;
            $leftover += $step;
        }
        while ($leftover > 0) {
            foreach (array_keys($fractions) as $i) {
                $step = min($leftover, $unit, $itemRefunds[$i]['refund_amount'] - $cuts[$i]);
                $cuts[$i] += $step;
                $leftover -= $step;
                if ($leftover <= 0) {
                    break;
                }
            }
        }

        foreach ($cuts as $i => $rowCut) {
            $itemRefunds[$i]['refund_amount'] -= $rowCut;
        }

        return $itemRefunds;
    }

    /**
     * How many of $refundQty units can have shipped: at least the units beyond those still to ship,
     * at most the shipped units the customer still has.
     *
     * @param OrderItem $orderItem
     * @param int       $refundQty
     *
     * @return array{min: int, max: int}
     */
    public static function shippedQtyBounds(OrderItem $orderItem, $refundQty)
    {
        $refundQty = (int) $refundQty;
        $shippedKept = $orderItem->shippedUnitsKept();
        $max = min($refundQty, $shippedKept);

        return [
            'min' => min($max, max(0, $refundQty - ($orderItem->unitsLeft() - $shippedKept))),
            'max' => $max,
        ];
    }

    /**
     * What a forced local refund will record, from the same figures the refund would use.
     *
     * @param Order $order
     * @param array $plan  Result of plan().
     *
     * @return array
     */
    public static function previewForcedLocalRefund(Order $order, array $plan)
    {
        $refundAmount = (int) Arr::get($plan, 'refund_amount', 0);
        $refundInfo = Arr::get($plan, 'refund_info', []);
        $refundedAfter = (int) $order->total_refund + $refundAmount;

        return [
            'amount'               => $refundAmount,
            'shipping_amount'      => (int) Arr::get($plan, 'shipping_amount', 0),
            'items'                => array_map(function ($item) {
                return [
                    'id'               => Arr::get($item, 'id'),
                    'refund_qty'       => Arr::get($item, 'refund_qty'),
                    'restore_quantity' => Arr::get($item, 'restore_quantity'),
                ];
            }, Arr::get($plan, 'item_refunds', [])),
            'manage_stock'         => filter_var(Arr::get($refundInfo, 'manageStock'), FILTER_VALIDATE_BOOLEAN),
            'payment_status_after' => $refundedAfter >= $order->netAmount((int) $order->total_paid)
                ? Status::PAYMENT_REFUNDED
                : Status::PAYMENT_PARTIALLY_REFUNDED,
            'cancel_subscription'  => Arr::get($refundInfo, 'cancelSubscription') == 'true',
        ];
    }
}

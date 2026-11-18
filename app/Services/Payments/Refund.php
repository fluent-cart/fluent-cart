<?php

namespace FluentCart\App\Services\Payments;

use FluentCart\Api\Resource\OrderResource;
use FluentCart\App\App;
use FluentCart\App\Events\Order\OrderRefund;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Money;
use FluentCart\App\Services\ShipmentService;
use FluentCart\Framework\Support\Arr;

class Refund
{
    const ROUTE_REMOTE = 'remote';
    const ROUTE_LOCAL = 'local';
    const ROUTE_FORCED_LOCAL = 'forced_local';
    const ROUTE_UNAVAILABLE = 'unavailable';

    /**
     * Refund through the gateway first and record the refund locally only once it is real.
     *
     * A gateway failure records nothing but an order activity log entry and returns a WP_Error
     * whose data carries `can_force_local`. The admin can then resend the same refund with
     * `force_local` to record it locally without the gateway.
     *
     * @param OrderTransaction $transaction  The charge transaction to refund.
     * @param int              $refundAmount Amount in cents.
     * @param array            $args         reason, item_ids, manageStock, refunded_items, is_item_refund, force_local.
     * @param array            $refundIntent items, shipping_amount, manage_stock: the item and shipping writes, saved in
     *                                       the same DB transaction as the refund row. See RefundPlanner::plan().
     *
     * @return array|\WP_Error refund_transaction, vendor_refund_id, manual_refund, refund_route, needs_resync.
     *                         A refund FluentCart could not record returns a `refund_not_recorded`
     *                         WP_Error carrying vendor_refund_id and amount.
     */
    public function processRefund($transaction, $refundAmount, $args = [], array $refundIntent = [])
    {
        $args = wp_parse_args($args, [
            'reason'      => '',
            'item_ids'    => [],
            'manageStock' => false,
            'force_local' => false,
        ]);

        $order = $transaction->order;

        $invalidAmount = $this->validateRefundAmount($transaction, $order, $refundAmount);
        if ($invalidAmount) {
            return $invalidAmount;
        }

        $gateway = App::gateway($transaction->payment_method);
        $gatewayName = PaymentHelper::getMethodAdminTitle($transaction->payment_method);

        list($route, $manualRefund) = $this->resolveRefundRoute($transaction, $order, $refundAmount, $args, $gateway);

        if ($route === self::ROUTE_UNAVAILABLE) {
            if (!$gateway || !$gateway->has('payment')) {
                /* translators: %1$s: payment gateway name */
                $message = sprintf(__('The %1$s payment gateway is not available, so it cannot refund this payment.', 'fluent-cart'), $gatewayName);
            } else {
                /* translators: %1$s: payment gateway name */
                $message = sprintf(__('%1$s has no charge ID for this payment, so it cannot refund it.', 'fluent-cart'), $gatewayName);
            }

            return $this->refundFailedError($order, 'gateway_unavailable', $gatewayName, $refundAmount, $message);
        }

        $vendorRefundId = '';
        $refundRowIdsBeforeGatewayCall = [];

        if ($this->callsGateway($route, $manualRefund, $gateway)) {
            $refundRowIdsBeforeGatewayCall = $this->existingRefundRowIds($transaction);
            $vendorRefundId = $this->refundOnGateway($gateway, $transaction, $refundAmount, $args);

            if (is_wp_error($vendorRefundId)) {
                return $this->refundFailedError($order, 'remote_refund_failed', $gatewayName, $refundAmount, $vendorRefundId->get_error_message());
            }
        }

        $refundRow = $this->buildRefundRow($transaction, $order, $refundAmount, $vendorRefundId, $route, $args);

        $saved = $this->saveRefund($order, $transaction, $refundRow, $refundAmount, $refundIntent, $route, $gatewayName, $refundRowIdsBeforeGatewayCall);
        if (is_wp_error($saved)) {
            return $saved;
        }

        list($refundTransaction, $needsResync, $refundedItems) = $saved;

        if ($route === self::ROUTE_FORCED_LOCAL) {
            $order->addLog(
                __('Refund recorded locally only', 'fluent-cart'),
                /* translators: %1$s: formatted refund amount, %2$s: payment gateway name */
                sprintf(__('%1$s was recorded as refunded but was not refunded through %2$s. Return the money to the customer outside FluentCart.', 'fluent-cart'), Helper::toDecimal($refundAmount, true, $order->currency), $gatewayName),
                'warning',
                wp_get_current_user()->display_name
            );
        }

        if (!$needsResync) {
            $manageStock = filter_var(Arr::get($args, 'manageStock'), FILTER_VALIDATE_BOOLEAN);
            $isItemRefund = filter_var(Arr::get($args, 'is_item_refund'), FILTER_VALIDATE_BOOLEAN);
            // A webhook that arrived during the gateway call may already have announced this refund.
            $order = Order::query()->findOrFail($order->id);
            $needsResync = !self::countRefundOrMarkForResync(new OrderRefund($order, $refundTransaction, Arr::get($args, 'item_ids'), $manageStock, $refundedItems ?: Arr::get($args, 'refunded_items', []), $isItemRefund));
        }

        return [
            'refund_transaction' => $refundTransaction,
            'vendor_refund_id'   => $vendorRefundId ?: null,
            'manual_refund'      => $manualRefund,
            'refund_route'       => $route,
            'needs_resync'       => $needsResync
        ];
    }

    /**
     * A local-refund-only gateway (Cash on Delivery) still records its own refund, unless a
     * `fluent_cart/order_refund_manually` filter already settled it.
     *
     * @param string                                                                  $route        One of the ROUTE_* constants.
     * @param array                                                                   $manualRefund Result of `fluent_cart/order_refund_manually`.
     * @param \FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway|null $gateway
     *
     * @return bool
     */
    private function callsGateway($route, array $manualRefund, $gateway)
    {
        if ($route === self::ROUTE_REMOTE) {
            return true;
        }

        return $route === self::ROUTE_LOCAL
            && Arr::get($manualRefund, 'status') !== 'yes'
            && $gateway && $gateway->has('refund') && $gateway->has('local_refund_only');
    }

    /**
     * @param OrderTransaction $transaction  Charge being refunded
     * @param Order            $order        Parent order
     * @param int              $refundAmount In cents
     *
     * @return \WP_Error|null
     */
    public function validateRefundAmount(OrderTransaction $transaction, $order, $refundAmount)
    {
        if ($refundAmount <= 0) {
            return new \WP_Error('invalid_refund_amount', __('Invalid refund amount.', 'fluent-cart'));
        }

        if (!Money::isWholeAmount($refundAmount, $transaction->currency)) {
            return new \WP_Error('invalid_refund_precision', __('Refund amount must use the smallest unit of the transaction currency.', 'fluent-cart'), ['status' => 422]);
        }

        if ($refundAmount > self::netPaidAmount($order)) {
            return new \WP_Error('invalid_refund_amount', __('Refund amount exceeds the net paid amount for this order.', 'fluent-cart'));
        }

        if ($transaction->getMaxRefundableAmount() < $refundAmount) {
            return new \WP_Error('invalid_refund_amount', __('Refund amount exceeds the maximum refundable amount for this transaction.', 'fluent-cart'));
        }

        return null;
    }

    /**
     * @param Order $order
     *
     * @return int Succeeded charges minus recorded and pending refunds, in cents
     */
    public static function netPaidAmount($order)
    {
        // From the ledger, not the columns: orders recounted before recountTotalPaid() kept
        // total_paid gross may store it net, so subtracting total_refund would double count.
        $orderPaid = (int) $order->transactions()
            ->where('status', Status::TRANSACTION_SUCCEEDED)
            ->sum('total');
        $orderRefunded = (int) $order->transactions()
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->where('status', Status::TRANSACTION_REFUNDED)
            ->sum('total');

        return $orderPaid - $orderRefunded - OrderTransaction::pendingRefundTotal($order->id);
    }

    /**
     * A forced local refund skips `fluent_cart/order_refund_manually`: the admin settles it outside FluentCart.
     *
     * @param \FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway|null $gateway
     *
     * @return array [route, manual refund result]
     */
    private function resolveRefundRoute(OrderTransaction $transaction, Order $order, $refundAmount, array $args, $gateway)
    {
        $manualRefund = ['status' => 'no', 'source' => ''];

        if (filter_var($args['force_local'], FILTER_VALIDATE_BOOLEAN)) {
            return [self::ROUTE_FORCED_LOCAL, $manualRefund];
        }

        $filtered = apply_filters('fluent_cart/order_refund_manually', $manualRefund, [
            'refund_amount' => $refundAmount,
            'transaction'   => $transaction,
            'order'         => $order,
            'args'          => $args
        ]);

        if (is_array($filtered)) {
            $manualRefund = $filtered;
        }

        return [$this->routeForGateway($transaction, $gateway, $manualRefund), $manualRefund];
    }

    /**
     * @param \FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway $gateway
     *
     * @return string|\WP_Error The vendor refund id, '' when the gateway returns none.
     */
    private function refundOnGateway($gateway, OrderTransaction $transaction, $refundAmount, array $args)
    {
        try {
            $vendorRefundId = $gateway->processRefund($transaction, $refundAmount, $args);
        } catch (\Throwable $e) {
            return new \WP_Error('refund_gateway_exception', $e->getMessage());
        }

        if (is_wp_error($vendorRefundId)) {
            return $vendorRefundId;
        }

        return is_scalar($vendorRefundId) ? (string) $vendorRefundId : '';
    }

    private function buildRefundRow(OrderTransaction $transaction, Order $order, $refundAmount, $vendorRefundId, $route, array $args)
    {
        return [
            'order_id'            => $transaction->order_id,
            'order_type'          => $order->type,
            'payment_method'      => $transaction->payment_method,
            'payment_mode'        => $transaction->payment_mode,
            'payment_method_type' => $transaction->payment_method_type,
            'transaction_type'    => Status::TRANSACTION_TYPE_REFUND,
            'subscription_id'     => $transaction->subscription_id,
            'status'              => Status::TRANSACTION_REFUNDED,
            'currency'            => $transaction->currency,
            'total'               => $refundAmount,
            'vendor_charge_id'    => $vendorRefundId,
            'meta'                => [
                'parent_id'    => $transaction->id,
                'reason'       => $args['reason'] ?? '',
                'created_by'   => get_current_user_id() ?: null,
                'refund_route'   => $route,
                'is_item_refund' => filter_var(Arr::get($args, 'is_item_refund'), FILTER_VALIDATE_BOOLEAN),
                'refunded_items' => Arr::get($args, 'refunded_items', []),
                'manage_stock'   => filter_var(Arr::get($args, 'manageStock'), FILTER_VALIDATE_BOOLEAN),
            ],
            'uuid'                => md5(time() . wp_generate_uuid4())
        ];
    }

    /**
     * Save the refund row with the item and shipping writes. The gateway already moved the money, so a DB
     * failure must not lose the refund: retry once, then save the bare row marked for resync.
     *
     * @param Order            $order                         The order being refunded.
     * @param OrderTransaction $transaction                   The charge being refunded.
     * @param array            $refundRow                     Columns of the refund row to insert.
     * @param int              $refundAmount                  Refund amount in cents.
     * @param array            $refundIntent                  Item and shipping writes, see processRefund().
     * @param string           $route                         Refund route (remote, local, forced_local).
     * @param string           $gatewayName                   Gateway title for messages.
     * @param int[]            $refundRowIdsBeforeGatewayCall Refund row ids on the order before the gateway call.
     *
     * @return array|\WP_Error [refund transaction, needs resync, refunded items as written], or `refund_not_recorded`.
     */
    private function saveRefund(Order $order, OrderTransaction $transaction, array $refundRow, $refundAmount, array $refundIntent, $route, $gatewayName, array $refundRowIdsBeforeGatewayCall)
    {
        $db = App::db();
        $hasWrites = self::hasRefundWrites($refundIntent);
        $lastError = '';

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $bareRow = $attempt === 3;
            $shippingChange = null;
            $appliedIntent = $refundIntent;
            $db->beginTransaction();
            try {
                $refundTransaction = $this->insertOrAdoptRefundRow($transaction, $refundRow, $refundAmount, $refundRowIdsBeforeGatewayCall);
                if (!$refundTransaction) {
                    $db->rollBack();

                    return $this->refundIdAlreadyRecordedError($order, $gatewayName, $refundAmount, $refundRow['vendor_charge_id']);
                }

                if (!$bareRow) {
                    $shippingChange = self::applyRefundIntent($order, $appliedIntent);
                    self::storeRefundedItems($refundTransaction, Arr::get($appliedIntent, 'items', []));
                } elseif ($hasWrites) {
                    self::markForResync($refundTransaction, $refundIntent);
                }
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollBack();
                $lastError = $e->getMessage();
                continue;
            }

            if ($shippingChange) {
                ShipmentService::dispatchFulfillmentTransition($shippingChange[0], $shippingChange[1], true);
            }

            if ($bareRow) {
                $order->addLog(
                    __('Refund recorded without item and shipping updates', 'fluent-cart'),
                    /* translators: %1$s: database error message */
                    sprintf(__('The refund went through, but the item and shipping updates failed. Re-sync the refund from the transaction list. Error: %1$s', 'fluent-cart'), $lastError),
                    'warning',
                    wp_get_current_user()->display_name
                );
            }

            return [$refundTransaction, $bareRow && $hasWrites, $bareRow ? [] : Arr::get($appliedIntent, 'items', [])];
        }

        $message = $this->notRecordedMessage($route, $gatewayName, $refundAmount, $order->currency, $refundRow['vendor_charge_id']);
        $order->addLog(
            __('Refund not recorded', 'fluent-cart'),
            /* translators: %1$s: message explaining the unrecorded refund, %2$s: database error message */
            sprintf(__('%1$s Error: %2$s', 'fluent-cart'), $message, $lastError),
            'error',
            wp_get_current_user()->display_name
        );

        return new \WP_Error('refund_not_recorded', $message, [
            'vendor_refund_id' => $refundRow['vendor_charge_id'] ?: null,
            'amount'           => $refundAmount,
        ]);
    }

    /**
     * @param OrderTransaction                                      $transaction
     * @param \FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway|null $gateway
     * @param array                                                 $manualRefund Result of `fluent_cart/order_refund_manually`.
     *
     * @return string One of the ROUTE_* constants.
     */
    private function routeForGateway($transaction, $gateway, array $manualRefund)
    {
        if (Arr::get($manualRefund, 'status') === 'yes') {
            return self::ROUTE_LOCAL;
        }

        // Deactivated Pro/add-on gateways stay registered as promo placeholders with no features.
        if (!$gateway || !$gateway->has('payment')) {
            return self::ROUTE_UNAVAILABLE;
        }

        if ($gateway->has('local_refund_only') || $gateway->has('offline') || !$gateway->has('refund')) {
            return self::ROUTE_LOCAL;
        }

        if (empty($transaction->vendor_charge_id)) {
            return self::ROUTE_UNAVAILABLE;
        }

        return self::ROUTE_REMOTE;
    }

    private function existingRefundRowIds(OrderTransaction $transaction)
    {
        $ids = OrderTransaction::query()
            ->where('order_id', $transaction->order_id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->pluck('id');

        return array_map('intval', is_array($ids) ? $ids : $ids->all());
    }

    /**
     * Insert the refund row, or adopt the one a gateway webhook created for the same vendor refund id while
     * the gateway call was in flight. Only a new row adds to the parent's refunded total; the webhook already
     * added it.
     *
     * @param OrderTransaction $transaction                   The charge being refunded.
     * @param array            $refundRow                     Columns of the refund row to insert.
     * @param int              $refundAmount                  Refund amount in cents.
     * @param int[]            $refundRowIdsBeforeGatewayCall Refund row ids on the order before the gateway call.
     *
     * @return OrderTransaction|null null when the vendor refund id is on a row that existed before the gateway
     *                               call, or on a row for another charge, amount or a failed refund.
     */
    private function insertOrAdoptRefundRow(OrderTransaction $transaction, array $refundRow, $refundAmount, array $refundRowIdsBeforeGatewayCall = [])
    {
        $vendorRefundId = $refundRow['vendor_charge_id'];

        if ($vendorRefundId !== '') {
            $existing = OrderTransaction::query()
                ->where('order_id', $transaction->order_id)
                ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
                ->where('vendor_charge_id', $vendorRefundId)
                ->first();

            if ($existing) {
                if (in_array((int) $existing->id, $refundRowIdsBeforeGatewayCall, true) || !$this->isSameRefund($existing, $transaction, $refundAmount)) {
                    return null;
                }

                $existing->meta = array_merge($existing->meta ?: [], Arr::only(Arr::get($refundRow, 'meta', []), ['is_item_refund', 'refunded_items', 'manage_stock']));
                $existing->save();

                return $existing;
            }
        }

        $refundTransaction = OrderTransaction::query()->create($refundRow);
        // Re-read: a rolled-back attempt leaves its writes on in-memory models.
        PaymentHelper::updateTransactionRefundedTotal(OrderTransaction::query()->findOrFail($transaction->id), $refundAmount);

        return $refundTransaction;
    }

    private function isSameRefund(OrderTransaction $existing, OrderTransaction $transaction, $refundAmount)
    {
        return (int) Arr::get(is_array($existing->meta) ? $existing->meta : [], 'parent_id') === (int) $transaction->id
            && (int) $existing->total === (int) $refundAmount
            && $existing->status !== Status::TRANSACTION_FAILED;
    }

    private function refundIdAlreadyRecordedError(Order $order, $gatewayName, $refundAmount, $vendorRefundId)
    {
        /* translators: %1$s: payment gateway name, %2$s: formatted refund amount, %3$s: vendor refund ID */
        $message = sprintf(__('%1$s answered the refund of %2$s with refund ID %3$s, which is already on a refund recorded for this order. FluentCart did not record this refund. Check %1$s before you refund again.', 'fluent-cart'), $gatewayName, Helper::toDecimal($refundAmount, true, $order->currency), $vendorRefundId);

        $order->addLog(
            __('Refund not recorded', 'fluent-cart'),
            $message,
            'error',
            wp_get_current_user()->display_name
        );

        return new \WP_Error('refund_not_recorded', $message, [
            'vendor_refund_id' => $vendorRefundId,
            'amount'           => $refundAmount,
        ]);
    }

    private function notRecordedMessage($route, $gatewayName, $refundAmount, $currency, $vendorRefundId)
    {
        if ($route !== self::ROUTE_REMOTE) {
            return __('FluentCart could not record the refund. Check the order activity before you refund again.', 'fluent-cart');
        }

        /* translators: %1$s: payment gateway name, %2$s: formatted refund amount, %3$s: vendor refund ID */
        return sprintf(__('%1$s refunded %2$s (refund ID: %3$s), but FluentCart could not record it. Do not refund again. Check the order activity; the gateway webhook can still record the refund.', 'fluent-cart'), $gatewayName, Helper::toDecimal($refundAmount, true, $currency), $vendorRefundId ?: '-');
    }

    private function refundFailedError(Order $order, $code, $gatewayName, $refundAmount, $gatewayMessage)
    {
        $order->addLog(
            /* translators: %1$s: payment gateway name */
            sprintf(__('Refund failed on %1$s', 'fluent-cart'), $gatewayName),
            /* translators: %1$s: formatted refund amount, %2$s: gateway error message */
            sprintf(__('Refund of %1$s was not recorded. %2$s', 'fluent-cart'), Helper::toDecimal($refundAmount, true, $order->currency), $gatewayMessage),
            'error',
            wp_get_current_user()->display_name
        );

        return new \WP_Error($code, $gatewayMessage, [
            'gateway'         => $gatewayName,
            'gateway_message' => $gatewayMessage,
            'can_force_local' => true,
        ]);
    }

    /**
     * Record a refund a gateway reported (webhook or resync) exactly once.
     *
     * Matches, in order: the row carrying this vendor refund id, whose status only moves out of pending; a row
     * recorded locally without a vendor id for the same charge and total, which takes the id and an empty
     * reason; otherwise a new row. Whenever the matched row is refunded, the charge refunded total is recounted
     * from its refunded rows (never lowered) and OrderRefund fires; it applies only what the order has not counted.
     *
     * Runs under the per-order refund lock the admin refund holds, so matching and inserting never interleave with
     * another path recording a refund for the same order; a busy lock waits up to 10s, and a timeout inserts nothing.
     *
     * @param array            $refundData        vendor_charge_id (required), total in cents, status (default refunded),
     *                                            reason, and any other refund transaction column.
     * @param OrderTransaction $parentTransaction The charge the refund belongs to.
     *
     * @return OrderTransaction|\WP_Error refund_lock_timeout when the order lock stayed busy for 10s; nothing was recorded, and the
     *                                  event is lost unless the caller answers non-2xx so the gateway redelivers.
     */
    public static function createOrRecordRefund($refundData, OrderTransaction $parentTransaction)
    {
        $vendorRefundId = (string) Arr::get($refundData, 'vendor_charge_id', '');
        if ($vendorRefundId === '') {
            return new \WP_Error('missing_vendor_refund_id', __('The gateway refund has no refund ID.', 'fluent-cart'));
        }

        return self::withOrderRefundLock($parentTransaction->order_id, 10, function () use ($refundData, $parentTransaction, $vendorRefundId) {
            return self::matchOrInsertRefund($refundData, $parentTransaction, $vendorRefundId);
        });
    }

    /**
     * Insert a refund row under the per-order refund lock, without matching an existing row.
     *
     * @param array            $refundData        total in cents, status (default refunded), reason, and any other refund column.
     * @param OrderTransaction $parentTransaction The charge the refund belongs to.
     *
     * @return OrderTransaction|\WP_Error refund_lock_timeout when the order lock stayed busy for 10s; nothing was recorded.
     */
    public static function recordRefund($refundData, OrderTransaction $parentTransaction)
    {
        return self::withOrderRefundLock($parentTransaction->order_id, 10, function () use ($refundData, $parentTransaction) {
            return self::insertReportedRefund($refundData, $parentTransaction);
        });
    }

    /**
     * Run $callback holding the per-order refund lock. Every path that records or counts a refund takes it.
     *
     * @param int|string $orderId
     * @param int        $waitSeconds 0 fails at once (admin, MCP, resync); webhooks wait so the gateway is not refused.
     * @param callable   $callback
     *
     * @return mixed|\WP_Error The callback's result, or refund_lock_timeout when the lock stayed busy.
     */
    public static function withOrderRefundLock($orderId, int $waitSeconds, callable $callback)
    {
        global $wpdb;
        $lockName = 'fct_refund_' . $orderId;
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, $waitSeconds)) !== 1) {
            return new \WP_Error('refund_lock_timeout', __('Another refund is being recorded for this order.', 'fluent-cart'));
        }

        try {
            return $callback();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }

    private static function matchOrInsertRefund($refundData, OrderTransaction $parentTransaction, $vendorRefundId)
    {
        $status = Arr::get($refundData, 'status', Status::TRANSACTION_REFUNDED);

        $refunds = OrderTransaction::query()
            ->where('order_id', $parentTransaction->order_id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->orderBy('id', 'DESC')
            ->get();

        foreach ($refunds as $refund) {
            if ((string) $refund->vendor_charge_id === $vendorRefundId) {
                return self::advanceRefundStatus($refund, $status, $parentTransaction);
            }
        }

        if ($status !== Status::TRANSACTION_FAILED) {
            foreach ($refunds as $refund) {
                if ((string) $refund->vendor_charge_id !== ''
                    || $refund->status === Status::TRANSACTION_FAILED
                    || (int) Arr::get($refund->meta, 'parent_id') !== (int) $parentTransaction->id
                    || (int) $refund->total !== (int) Arr::get($refundData, 'total')
                ) {
                    continue;
                }

                $webhookMeta = (array) Arr::get($refundData, 'meta', []);
                if (isset($refundData['reason'])) {
                    $webhookMeta['reason'] = $refundData['reason'];
                }

                $localMeta = is_array($refund->meta) ? $refund->meta : [];
                if (empty($localMeta['reason'])) {
                    unset($localMeta['reason']);
                }

                $refund->vendor_charge_id = $vendorRefundId;
                $refund->meta = array_merge($webhookMeta, $localMeta);
                $refund->save();

                return self::advanceRefundStatus($refund, $status, $parentTransaction);
            }
        }

        return self::insertReportedRefund($refundData, $parentTransaction);
    }

    private static function insertReportedRefund($refundData, OrderTransaction $parentTransaction)
    {
        if (empty($refundData['total']) || $refundData['total'] <= 0) {
            return new \WP_Error('invalid_refund_amount', __('Invalid refund amount.', 'fluent-cart'));
        }

        $status = Arr::get($refundData, 'status', Status::TRANSACTION_REFUNDED);

        $defaults = [
            'order_id'            => $parentTransaction->order_id,
            'order_type'          => $parentTransaction->order_type,
            'transaction_type'    => Status::TRANSACTION_TYPE_REFUND,
            'subscription_id'     => $parentTransaction->subscription_id,
            'card_last_4'         => $parentTransaction->card_last_4,
            'card_brand'          => $parentTransaction->card_brand,
            'payment_method'      => $parentTransaction->payment_method,
            'payment_mode'        => $parentTransaction->payment_mode,
            'payment_method_type' => $parentTransaction->payment_method_type,
            'status'              => $status,
            'currency'            => $parentTransaction->currency,
            'meta'                => [
                'parent_id' => $parentTransaction->id,
                'reason'    => Arr::get($refundData, 'reason', ''),
            ]
        ];

        unset($refundData['reason']);

        $refundData = wp_parse_args($refundData, $defaults);
        $createdRefund = OrderTransaction::query()->create($refundData);

        if ($status === Status::TRANSACTION_REFUNDED) {
            self::countRefund($createdRefund, $parentTransaction);
            self::flagChargeOverflow($createdRefund, $parentTransaction);
        }

        return $createdRefund;
    }

    private static function advanceRefundStatus(OrderTransaction $refund, $status, OrderTransaction $parentTransaction)
    {
        if ($refund->status === Status::TRANSACTION_REFUNDED && $status === Status::TRANSACTION_REFUNDED) {
            self::countRefund($refund, $parentTransaction);
            self::flagChargeOverflow($refund, $parentTransaction);
            return $refund;
        }

        if ($refund->status !== Status::TRANSACTION_PENDING || $refund->status === $status) {
            return $refund;
        }

        $moved = OrderTransaction::query()
            ->where('id', $refund->id)
            ->where('status', Status::TRANSACTION_PENDING)
            ->update(['status' => $status]);

        if (!$moved) {
            return OrderTransaction::query()->findOrFail($refund->id);
        }

        $refund->status = $status;

        if ($status === Status::TRANSACTION_REFUNDED) {
            self::countRefund($refund, $parentTransaction);
            self::flagChargeOverflow($refund, $parentTransaction);
        }

        return $refund;
    }

    /**
     * The gateway already moved the money, so a refund above its charge is counted in full and only flagged.
     * Excess another row already carries is left out, so the flags add up to refunded_total minus the charge
     * total, and a redelivery of a row that was within the charge never takes a later row's flag.
     */
    private static function flagChargeOverflow(OrderTransaction $refund, OrderTransaction $parentTransaction)
    {
        $meta = is_array($refund->meta) ? $refund->meta : [];
        if (isset($meta['exceeds_charge'])) {
            return;
        }

        /** @var OrderTransaction $charge */
        $charge = OrderTransaction::query()->findOrFail($parentTransaction->id);
        $flaggedElsewhere = 0;
        foreach ($charge->linkedRefunds() as $linked) {
            if ((int) $linked->id !== (int) $refund->id) {
                $flaggedElsewhere += (int) Arr::get(is_array($linked->meta) ? $linked->meta : [], 'exceeds_charge', 0);
            }
        }

        $excess = min((int) $refund->total, (int) Arr::get($charge->meta, 'refunded_total', 0) - (int) $charge->total - $flaggedElsewhere);
        if ($excess <= 0) {
            return;
        }

        $meta['exceeds_charge'] = $excess;
        $refund->meta = $meta;
        $refund->save();

        $order = Order::query()->findOrFail($charge->order_id);
        $order->addLog(
            __('Refund exceeds the charge', 'fluent-cart'),
            /* translators: %1$s: payment gateway name, %2$s: formatted excess amount */
            sprintf(__('%1$s reported a refund that takes this charge\'s refunds above the charge by %2$s. Check the earlier local refund.', 'fluent-cart'), PaymentHelper::getMethodAdminTitle($charge->payment_method), Helper::toDecimal($excess, true, $order->currency)),
            'warning'
        );
    }

    /**
     * Count a new refund row on the order. The money already moved, so a failure that leaves the row
     * uncounted marks it for re-sync instead of throwing. Callers hold the per-order refund lock.
     *
     * @param OrderRefund $event Event for the refund row.
     *
     * @return bool False when the row was left for resync.
     */
    public static function countRefundOrMarkForResync(OrderRefund $event)
    {
        try {
            $event->dispatch();

            return true;
        } catch (\Throwable $e) {
            $refund = $event->transaction;
            $order = Order::query()->findOrFail($event->order->id);
            if (!$refund || !(new OrderRefund($order, $refund))->willTrigger) {
                throw $e;
            }

            $meta = $refund->meta ?: [];
            $meta['is_item_refund'] = $event->isItemRefund;
            $meta['refunded_items'] = $event->refundedItems;
            $refund->meta = $meta;
            // Units and shipping are already written; resync only counts the row.
            self::markForResync($refund, [
                'items'           => [],
                'shipping_amount' => 0,
                'manage_stock'    => $event->manageStock,
            ]);

            try {
                $order->addLog(
                    __('Refund recorded without order totals', 'fluent-cart'),
                    /* translators: %1$s: error message */
                    sprintf(__('The refund went through, but updating the order totals failed. Re-sync the refund from the transaction list. Error: %1$s', 'fluent-cart'), $e->getMessage()),
                    'warning',
                    wp_get_current_user()->display_name
                );
            } catch (\Throwable $logError) {
            }

            return false;
        }
    }

    /**
     * Finish a refund row left for resync: apply the stored item and shipping writes, then count the row.
     * Never calls the gateway.
     *
     * @param Order            $order
     * @param OrderTransaction $refund The refund row awaiting resync.
     *
     * @return true|\WP_Error Error data carries the HTTP `status` (400 nothing to resync, 409 lock busy or
     *                        order changed, 500 write failed).
     */
    public static function resync(Order $order, OrderTransaction $refund)
    {
        $result = self::withOrderRefundLock($order->id, 0, function () use ($order, $refund) {
            /** @var OrderTransaction $refund */
            $refund = OrderTransaction::query()->findOrFail($refund->id);
            if ($refund->status !== Status::TRANSACTION_REFUNDED || !$refund->isAwaitingResync()) {
                return new \WP_Error('nothing_to_resync', __('This refund has nothing to re-sync.', 'fluent-cart'), ['status' => 400]);
            }

            $meta = $refund->meta;
            $refundIntent = Arr::get($meta, 'refund_intent', []);
            $items = Arr::get($refundIntent, 'items', []);

            $orderItemsById = $items
                ? OrderItem::query()->where('order_id', $order->id)->whereIn('id', array_column($items, 'id'))->get()->keyBy('id')
                : null;

            foreach ($items as $item) {
                $current = $orderItemsById ? $orderItemsById->get((int) Arr::get($item, 'id')) : null;
                if (!$current || $current->unitsLeft() !== (int) Arr::get($item, 'pre_refund_qty')) {
                    return new \WP_Error('refund_order_changed', __('The order has changed since this refund. Please reconcile it manually.', 'fluent-cart'), ['status' => 409]);
                }
            }

            /** @var Order $order */
            $order = Order::query()->findOrFail($order->id);
            $intentShipping = (int) Arr::get($refundIntent, 'shipping_amount', 0);
            if ($intentShipping > 0 && $intentShipping > $order->shippingChargedTotal() - (int) $order->getMeta('shipping_refunded_amount')) {
                return new \WP_Error('refund_order_changed', __('The order has changed since this refund. Please reconcile it manually.', 'fluent-cart'), ['status' => 409]);
            }

            $db = App::db();
            $db->beginTransaction();
            try {
                $shippingChange = self::applyRefundIntent($order, $refundIntent);
                $items = Arr::get($refundIntent, 'items', []);
                if ($items) {
                    $meta['refunded_items'] = $items;
                }
                $meta['local_sync'] = 'done';
                $refund->meta = $meta;
                $refund->save();
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollBack();

                return new \WP_Error('resync_failed', __('Re-sync failed. Please try again.', 'fluent-cart'), ['status' => 500]);
            }

            if ($shippingChange) {
                ShipmentService::dispatchFulfillmentTransition($shippingChange[0], $shippingChange[1], true);
            }

            $manageStock = filter_var(Arr::get($refundIntent, 'manage_stock', true), FILTER_VALIDATE_BOOLEAN);
            $eventItems = $items ?: Arr::get($meta, 'refunded_items', []);
            $isItemRefund = filter_var(Arr::get($meta, 'is_item_refund', true), FILTER_VALIDATE_BOOLEAN);
            if (!self::countRefundOrMarkForResync(new OrderRefund($order, $refund, array_column($eventItems, 'id'), $manageStock, $eventItems, $isItemRefund))) {
                return new \WP_Error('resync_failed', __('Re-sync failed. Please try again.', 'fluent-cart'), ['status' => 500]);
            }

            return true;
        });

        if (is_wp_error($result) && $result->get_error_code() === 'refund_lock_timeout') {
            $result->add_data(['status' => 409]);
        }

        return $result;
    }

    private static function hasRefundWrites(array $refundIntent)
    {
        return !empty(Arr::get($refundIntent, 'items')) || (int) Arr::get($refundIntent, 'shipping_amount', 0) > 0;
    }

    /**
     * The item and shipping writes of a refund. Callers run it inside a DB transaction under the per-order lock.
     *
     * Fits each item's shipped units to the shipments as they are now, and writes the fitted values back into $refundIntent.
     *
     * @return array|null [Order, transition] to pass to ShipmentService::dispatchFulfillmentTransition() after commit.
     */
    private static function applyRefundIntent(Order $order, array &$refundIntent)
    {
        $shippingChange = null;
        $items = Arr::get($refundIntent, 'items', []);
        if ($items) {
            // Shipment writes lock the order row before the item rows; same order here, or the two deadlock.
            $lockedOrder = ShipmentService::lockOrderForShipping($order->id);
            // Re-read: a rolled-back attempt leaves its writes on in-memory models.
            $orderItemsById = OrderItem::query()->where('order_id', $order->id)->whereIn('id', array_column($items, 'id'))->get()->keyBy('id');
            self::addRefundedQuantities($order, $items, $orderItemsById);
            $refundIntent['items'] = $items;

            $transition = $lockedOrder ? ShipmentService::reconcileAfterItemEdit($lockedOrder) : null;
            $shippingChange = $transition ? [$lockedOrder, $transition] : null;
        }

        $order->addShippingRefund((int) Arr::get($refundIntent, 'shipping_amount', 0));

        return $shippingChange;
    }

    private static function addRefundedQuantities(Order $order, array &$itemRefunds, $orderItemsById)
    {
        foreach ($itemRefunds as &$item) {
            $orderItem = $orderItemsById->get((int) Arr::get($item, 'id'));
            if (!$orderItem) {
                continue;
            }

            $lineMeta = $orderItem->line_meta;
            $refundQty = (int) Arr::get($item, 'refund_qty');

            if (Arr::has($item, 'shipped_qty')) {
                // Shipments can change between the plan and this write.
                $bounds = RefundPlanner::shippedQtyBounds($orderItem, $refundQty);
                $plannedQty = (int) Arr::get($item, 'shipped_qty');
                $shippedQty = min(max($plannedQty, $bounds['min']), $bounds['max']);
                $lineMeta['refunded_shipped_quantity'] = (int) Arr::get($lineMeta, 'refunded_shipped_quantity', 0) + $shippedQty;
                $item['shipped_qty'] = $shippedQty;
                $item['restore_quantity'] = $refundQty - $shippedQty;

                if ($shippedQty !== $plannedQty) {
                    $order->addLog(
                        __('Shipped units refunded adjusted', 'fluent-cart'),
                        /* translators: 1: item title, 2: shipped units chosen at refund, 3: shipped units recorded */
                        sprintf(__('Shipments of %1$s changed during the refund. Already shipped: %2$d chosen, %3$d recorded.', 'fluent-cart'), $orderItem->title, $plannedQty, $shippedQty),
                        'info',
                        wp_get_current_user()->display_name
                    );
                }
            }

            $lineMeta['refunded_quantity'] = (int) Arr::get($lineMeta, 'refunded_quantity', 0) + $refundQty;

            $orderItem->line_meta = $lineMeta;
            $orderItem->save();
        }
        unset($item);
    }

    /**
     * A redelivery recounts from the row's refunded_items, so keep them as applyRefundIntent() fitted them.
     */
    private static function storeRefundedItems(OrderTransaction $refund, array $items)
    {
        if (!$items) {
            return;
        }

        $meta = $refund->meta ?: [];
        $meta['refunded_items'] = $items;
        $refund->meta = $meta;
        $refund->save();
    }

    /**
     * Leave the row for resync. Order totals skip it until resync applies $refundIntent and counts it.
     */
    private static function markForResync(OrderTransaction $refund, array $refundIntent)
    {
        $meta = $refund->meta ?: [];
        $meta['local_sync'] = 'pending';
        $meta['refund_intent'] = $refundIntent;
        $refund->meta = $meta;
        $refund->save();
    }

    /**
     * Apply a refunded row to the order. Safe to repeat: OrderRefund applies only the part of the refunded
     * rows the order has not counted yet, so a redelivery repairs a run that stopped after the row was saved.
     * Callers hold the per-order refund lock taken in withOrderRefundLock().
     */
    private static function countRefund(OrderTransaction $refund, OrderTransaction $parentTransaction)
    {
        PaymentHelper::recountTransactionRefundedTotal($parentTransaction);
        $event = new OrderRefund(Order::query()->findOrFail($parentTransaction->order_id), $refund);
        if ($event->willTrigger) {
            $event->dispatch();
        }
    }

}

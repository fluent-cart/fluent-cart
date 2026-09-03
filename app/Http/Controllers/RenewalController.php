<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\StoreManagedRenewal\Services\RenewalService;
use FluentCart\App\Modules\Subscriptions\Services\SystemChargeService;
use FluentCart\Framework\Http\Request\Request;

class RenewalController extends Controller
{
    /**
     * List all invoices (renewal orders)
     */
    public function index(Request $request): \WP_REST_Response
    {
        $query = Order::query()
            ->where('type', Status::ORDER_TYPE_RENEWAL)
            ->with(['customer']);

        if ($paymentStatus = $request->get('payment_status')) {
            $query->where('payment_status', sanitize_text_field($paymentStatus));
        }

        if ($parentId = $request->get('parent_id')) {
            $query->where('parent_id', (int) $parentId);
        }

        if ($customerId = $request->get('customer_id')) {
            $query->where('customer_id', (int) $customerId);
        }

        $invoices = $query->orderBy('id', 'DESC')->paginate();

        return $this->response->sendSuccess([
            'invoices' => $invoices
        ]);
    }

    /**
     * Get single invoice details
     */
    public function show(Request $request, int $id): \WP_REST_Response
    {
        $invoice = Order::query()
            ->where('type', Status::ORDER_TYPE_RENEWAL)
            ->with(['customer', 'order_items', 'transactions'])
            ->find($id);

        if (!$invoice) {
            return $this->entityNotFoundError(
                __('Renewal order not found', 'fluent-cart'),
                __('Back to Orders', 'fluent-cart'),
                '/orders'
            );
        }

        return $this->response->sendSuccess([
            'invoice' => $invoice
        ]);
    }

    /**
     * Void/cancel a pending invoice
     */
    public function void(Request $request, Order $order): \WP_REST_Response
    {
        if ($order->type !== Status::ORDER_TYPE_RENEWAL) {
            return $this->sendError([
                'message' => __('Only renewal orders can be voided', 'fluent-cart')
            ], 400);
        }

        if (!in_array($order->payment_status, [Status::PAYMENT_PENDING, Status::PAYMENT_SCHEDULED])) {
            return $this->sendError([
                'message' => __('Only pending or scheduled invoices can be voided', 'fluent-cart')
            ], 400);
        }

        // Re-assert payment_status at mutation time — a webhook may pay this invoice
        // between the check above and this update. A raced-paid invoice must not be
        // flipped to canceled/failed.
        $voided = Order::query()
            ->where('id', $order->id)
            ->whereIn('payment_status', [Status::PAYMENT_PENDING, Status::PAYMENT_SCHEDULED])
            ->update([
                'payment_status' => Status::PAYMENT_FAILED,
                'status'         => Status::ORDER_CANCELED,
            ]);

        if (!$voided) {
            return $this->sendError([
                'message' => __('This invoice was just paid and can no longer be voided', 'fluent-cart')
            ], 409);
        }

        $order->payment_status = Status::PAYMENT_FAILED;
        $order->status = Status::ORDER_CANCELED;

        // Cancel associated pending transactions
        OrderTransaction::query()
            ->where('order_id', $order->id)
            ->where('status', Status::TRANSACTION_PENDING)
            ->update(['status' => Status::TRANSACTION_FAILED]);

        $order->addLog(
            'Renewal order voided',
            'Renewal order manually voided by admin',
            'warning'
        );

        // Advance the subscription past this period so the scheduler doesn't
        // immediately regenerate the same invoice.
        RenewalService::advanceAfterVoid($order);

        if ($order->customer) {
            $order->customer->recountStat();
        }

        do_action('fluent_cart/order_canceled', [
            'order'    => $order,
            'customer' => $order->customer,
        ]);

        do_action('fluent_cart/renewal_voided', [
            'order'    => $order,
            'customer' => $order->customer,
        ]);

        return $this->response->sendSuccess([
            'message' => __('Renewal order has been voided successfully', 'fluent-cart')
        ]);
    }

    /**
     * Resend invoice email notification
     */
    public function resend(Request $request, Order $order): \WP_REST_Response
    {
        if ($order->type !== Status::ORDER_TYPE_RENEWAL) {
            return $this->sendError([
                'message' => __('Only renewal orders can be resent', 'fluent-cart')
            ], 400);
        }

        if ($order->payment_status !== Status::PAYMENT_PENDING) {
            return $this->sendError([
                'message' => __('Only pending renewal orders can be resent', 'fluent-cart')
            ], 400);
        }

        $subscription = $order->parentOrder ? $order->parentOrder->subscriptions()->first() : null;

        // A system invoice flips to PENDING on its first failed auto-charge attempt
        // while a retry is still armed — resend must wait for the ladder to exhaust
        // instead of firing the pay-now email mid-dunning.
        if ($subscription && $subscription->isSystem() && !SystemChargeService::isExhausted($subscription, $order)) {
            return $this->sendError([
                'message' => __('This invoice is still being retried automatically. Resend is available once retries are exhausted.', 'fluent-cart')
            ], 400);
        }

        do_action('fluent_cart/renewal_created', [
            'subscription' => $subscription,
            'order'        => $order,
            'parent_order' => $order->parentOrder,
            'customer'     => $order->customer,
            'transaction'  => $order->transactions()->first(),
        ]);

        $order->addLog(
            'Renewal order email resent',
            'Renewal order email notification resent by admin',
            'info'
        );

        return $this->response->sendSuccess([
            'message' => __('Renewal order email has been resent', 'fluent-cart')
        ]);
    }
}

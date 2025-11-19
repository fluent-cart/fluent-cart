<?php

namespace FluentCart\App\Events\Order;

use FluentCart\App\Events\EventDispatcher;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Listeners;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderTransaction;

class OrderRefund extends EventDispatcher
{
    public string $hook = 'fluent_cart/order_refunded';
    public bool $autoFireHook = false;

    protected array $listeners = [
        Listeners\Order\OrderRefunded::class,
//        Listeners\UpdateStock::class,
    ];

    public Order $order;

    public ?OrderTransaction $transaction;

    /**
     * @var int[] Array of refunded order item IDs
     */
    public array $refundedItemIds;

    public array $refundedItems;

    public bool $manageStock;

    /*
    * Refunded amount in cents
    */
    public int $refundedAmount;

    public ?Customer $customer;

    public string $type;

    public bool $willTrigger = true;

    /**
     * OrderRefunded constructor.
     *
     * @param Order $order The refunded order
     * @param OrderTransaction|null $transaction The related transaction, if any
     * @param int[] $refundedItemIds Array of refunded order item IDs
     * @param bool $manageStock Whether to manage stock
     */
    public function __construct(Order $order, ?OrderTransaction $createdRefund = null, array $refundedItemIds = [], bool $manageStock = false, $refundedItems = [])
    {
        $this->order = $order;

        $calculatedRefundAmount = OrderTransaction::query()->where('order_id', $order->id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->where('status', Status::TRANSACTION_REFUNDED)
            ->sum('total');


        $newOrderPaymentStatus = $calculatedRefundAmount >= $order->total_paid ? Status::PAYMENT_REFUNDED : Status::PAYMENT_PARTIALLY_REFUNDED;

        $newRefundAmount = $calculatedRefundAmount - $order->total_refund;

        $isStatusChanged = $newOrderPaymentStatus !== $order->payment_status;

        if (!$newRefundAmount) {
            $this->listeners = [];
            $this->willTrigger = false;
            return;
        }

        $this->refundedItems = $refundedItems;
        $this->refundedItemIds = $refundedItemIds;
        $this->refundedAmount = $newRefundAmount;
        $this->order->load('customer', 'shipping_address', 'billing_address');
        $this->manageStock = $manageStock;
        $this->transaction = $createdRefund;
        $this->type = $newOrderPaymentStatus === Status::PAYMENT_REFUNDED ? 'full' : 'partial';
        $this->customer = $order->customer;
    }

    public function toArray(): array
    {
        return [
            'order'           => $this->order,
            'manage_stock'    => $this->manageStock,
            'transaction'     => $this->transaction,
            'customer'        => $this->customer,
            'refunded_item_ids'  => $this->refundedItemIds,
            'refunded_items'  => $this->refundedItems,
            'refunded_amount' => $this->refundedAmount,
            'type'            => $this->type
        ];
    }

    public function getActivityEventModel()
    {
        return $this->order;
    }

    public function afterDispatch()
    {
        if (!$this->willTrigger) {
            return;
        }

        $refundedItems = [];

        if ($this->refundedItemIds) {
            $refundedItems = OrderItem::query()
                ->whereIn('id', $this->refundedItemIds)
                ->get()
                ->toArray();
        }

        $newRefundedItems = [];
        if ($this->refundedItems) {
            foreach ($this->refundedItems as $item) {
                $orderItem = OrderItem::query()->find($item['id']);
                $orderItem['restore_quantity'] = $item['restore_quantity'] ?? null;
                $newRefundedItems[] = $orderItem;
            }
        }

        $data = [
            'order'           => $this->order,
            'refunded_items'  => $refundedItems,
            'new_refunded_items'  => $this->refundedItems,
            'refunded_amount' => $this->refundedAmount,
            'manage_stock'    => $this->manageStock,
            'transaction'     => $this->transaction,
            'customer'        => $this->customer,
            'type'            => $this->type
        ];

        do_action('fluent_cart/order_refunded', $data);

        if ($this->type === 'full') {
            do_action('fluent_cart/order_fully_refunded', $data);
        } else {
            do_action('fluent_cart/order_partially_refunded', $data);
        }
    }
}

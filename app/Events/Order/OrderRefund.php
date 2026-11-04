<?php

namespace FluentCart\App\Events\Order;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Events\EventDispatcher;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Listeners;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\StockManagement\StockManagement;
use FluentCart\Framework\Support\Arr;

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

    public bool $isItemRefund = false;

    /*
    * Refunded amount in cents
    */
    public int $refundedAmount;

    public ?Customer $customer;

    public string $type;

    public bool $willTrigger = true;

    private array $refundListeners;

    private bool $itemsFromRefundRow = false;

    /**
     * OrderRefunded constructor.
     *
     * @param Order $order The refunded order
     * @param OrderTransaction|null $transaction The related transaction, if any
     * @param int[] $refundedItemIds Array of refunded order item IDs
     * @param bool $manageStock Whether to manage stock
     */
    public function __construct(Order $order, ?OrderTransaction $createdRefund = null, array $refundedItemIds = [], bool $manageStock = false, $refundedItems = [], bool $isItemRefund = false)
    {
        // A webhook or retry counting an admin refund credits the lines the admin selected, not a spread.
        $rowMeta = $createdRefund && is_array($createdRefund->meta) ? $createdRefund->meta : [];
        if (!$refundedItems && !$isItemRefund && Arr::get($rowMeta, 'is_item_refund')) {
            $refundedItems = Arr::get($rowMeta, 'refunded_items', []);
            $refundedItemIds = array_map('intval', array_column($refundedItems, 'id'));
            $manageStock = (bool) Arr::get($rowMeta, 'manage_stock');
            $isItemRefund = true;
            $this->itemsFromRefundRow = true;
        }

        $this->order = $order;
        $this->transaction = $createdRefund;
        $this->refundedItemIds = $refundedItemIds;
        $this->refundedItems = $refundedItems;
        $this->manageStock = $manageStock;
        $this->isItemRefund = $isItemRefund;
        $this->refundListeners = $this->listeners;

        $this->resolveUncountedAmount();
    }

    /**
     * Callers must hold fct_refund_{order_id}; the uncounted amount is re-read here, so a refund already
     * applied adds nothing.
     */
    public function dispatch()
    {
        $this->order->refresh();
        $this->resolveUncountedAmount();
        parent::dispatch();
    }

    private function resolveUncountedAmount(): void
    {
        $order = $this->order;

        $calculatedRefundAmount = OrderTransaction::query()->where('order_id', $order->id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->where('status', Status::TRANSACTION_REFUNDED)
            ->get()
            ->filter(function ($transaction) {
                return !$transaction->isAwaitingResync();
            })
            ->sum('total');

        $netPaidTotal = $order->netAmount((int) $order->total_paid);
        $newOrderPaymentStatus = $calculatedRefundAmount >= $netPaidTotal ? Status::PAYMENT_REFUNDED : Status::PAYMENT_PARTIALLY_REFUNDED;

        $newRefundAmount = $calculatedRefundAmount - $order->total_refund;

        if (!$newRefundAmount) {
            $this->listeners = [];
            $this->willTrigger = false;
            return;
        }

        $this->listeners = $this->refundListeners;
        $this->willTrigger = true;
        $this->refundedAmount = $newRefundAmount;
        $this->order->load('customer', 'shipping_address', 'billing_address');
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

    public function shouldCreateActivity(): bool
    {
        return $this->willTrigger;
    }

    public function getActivityEventModel()
    {
        return $this->order;
    }

    public function afterDispatch()
    {
        if (!$this->willTrigger) {
            $this->restockAnnouncedRefund();
            return;
        }

        $refundedItems = [];

        if ($this->refundedItemIds) {
            $refundedItems = OrderItem::query()
                ->whereIn('id', $this->refundedItemIds)
                ->get()
                ->toArray();
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

    /**
     * A webhook can announce the refund amount before the admin request that carries the items,
     * so the stock the hook did not restore is restored here.
     */
    private function restockAnnouncedRefund(): void
    {
        // Items read back from the row were restocked when the row was counted.
        if ($this->itemsFromRefundRow || !$this->refundedItems || !ModuleSettings::isActive('stock_management')) {
            return;
        }

        (new StockManagement())->manageStockOnOrderRefunded([
            'order'              => $this->order,
            'manage_stock'       => $this->manageStock,
            'new_refunded_items' => $this->refundedItems,
        ]);
    }
}

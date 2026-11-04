<?php

namespace FluentCart\App\Events\Order;

use FluentCart\App\Events\EventDispatcher;
use FluentCart\App\Listeners;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;

class OrderPaid extends EventDispatcher
{
    /**
     * `source` value when a store admin marked the order paid by hand (mark as paid,
     * changing a transaction to succeeded, re-syncing statuses). Empty means a normal
     * payment: checkout, gateway confirmation or webhook.
     */
    const SOURCE_ADMIN = 'admin';

    public string $hook = 'fluent_cart/order_paid';
    protected array $listeners = [
        Listeners\Order\OrderPaid::class,
    ];

    /**
     * @var Order $order
     */
    public Order $order;

    /**
     * @var Customer|null $customer
     */
    public ?Customer $customer;

    /**
     * @var OrderTransaction|null $transaction
     */
    public ?OrderTransaction $transaction;

    /**
     * @var string $source What marked the order paid, see SOURCE_ADMIN. Empty for a normal payment.
     */
    public string $source = '';

    public function __construct(Order $order, $customer = null, $transaction = null, string $source = '')
    {
        $this->order = $order;
        $this->order->load('customer', 'order_items', 'shipping_address', 'billing_address');
        $this->customer = $customer;
        $this->transaction = $transaction;
        $this->source = $source;
    }

    public function toArray(): array
    {
        return [
            'order'       => $this->order,
            'customer'    => $this->customer ?? null,
            'transaction' => $this->transaction ?? null,
            'source'      => $this->source
        ];
    }

    public function getActivityEventModel()
    {
        return $this->order;
    }

    public function beforeDispatch()
    {
        if (!$this->order->receipt_number) {
            $this->order = $this->order->generateReceiptNumber();
        }
    }

    public function afterDispatch()
    {
        /**
         * 3rd party devs: Please do not use this hook to add any action that
         * use: fluent_cart/order_paid_done
         */
        $id = as_enqueue_async_action('fluent_cart/order_paid_async_private_handle', [
            [
                'order_id' => $this->order->id
            ]
        ], 'fluent-cart');

        if ($id) {
            $this->order->updateMeta('action_scheduler_id', $id);
        }

    }

}

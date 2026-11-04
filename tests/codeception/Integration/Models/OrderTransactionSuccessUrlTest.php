<?php

namespace Tests\Integration\Models;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\Payments\PaymentHelper;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;

class OrderTransactionSuccessUrlTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private OrderTransactionFactory $txnFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
        $this->txnFactory = new OrderTransactionFactory();
    }

    /**
     * Spec: Cases/payment-success-url.md [1]
     *   "The success URL built from a transaction model matches the one built from its uuid string."
     */
    public function test_success_url_from_model_matches_uuid_string(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $txn = $this->txnFactory->create($order->id);

        $fromModel = (new PaymentHelper('offline_payment'))->successUrl($txn);
        $fromUuid = (new PaymentHelper('offline_payment'))->successUrl($txn->uuid);

        $this->assertSame($fromUuid, $fromModel, 'Model and uuid-string callers must produce the same success URL');
    }

    /**
     * Spec: Cases/payment-success-url.md [2]
     *   "The success URL built from a transaction contains the payment method, transaction hash, and redirect flag."
     */
    public function test_get_success_url_contains_method_hash_and_redirect_flag(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $txn = $this->txnFactory->create($order->id);

        $url = $txn->getSuccessUrl();

        $this->assertStringContainsString('method=offline_payment', $url, 'Success URL must carry the payment method slug');
        $this->assertStringContainsString('trx_hash=' . $txn->uuid, $url, 'Success URL must carry the transaction uuid');
        $this->assertStringContainsString('fct_redirect=yes', $url, 'Success URL must carry the redirect flag');
    }

    /**
     * Spec: Cases/payment-success-url.md [3]
     *   "The success URL filter receives the transaction and its order in context."
     */
    public function test_success_url_filter_receives_transaction_and_order_context(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $txn = $this->txnFactory->create($order->id);

        $captured = null;
        add_filter('fluent_cart/payment/success_url', function ($url, $context) use (&$captured) {
            $captured = $context;
            return $url;
        }, 10, 2);

        $txn->getSuccessUrl();

        remove_all_filters('fluent_cart/payment/success_url');

        $this->assertIsArray($captured, 'Filter must receive a context array');
        $this->assertSame($txn->uuid, $captured['transaction_hash'], 'Legacy transaction_hash key must stay');
        $this->assertSame('offline_payment', $captured['payment_method'], 'Legacy payment_method key must stay');
        $this->assertInstanceOf(OrderTransaction::class, $captured['transaction'], 'Context must carry the transaction model');
        $this->assertSame($txn->id, $captured['transaction']->id, 'Context transaction must be the one the URL was built for');
        $this->assertInstanceOf(Order::class, $captured['order'], 'Context must carry the order model');
        $this->assertSame($order->id, $captured['order']->id, 'Context order must be the transaction\'s order');
    }

    /**
     * Spec: Cases/payment-success-url.md [5]
     *   "Extra query arguments are merged into the success URL built from a transaction."
     */
    public function test_get_success_url_merges_extra_args(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $txn = $this->txnFactory->create($order->id);

        $url = $txn->getSuccessUrl(['fct_extra' => 'value1']);

        $this->assertStringContainsString('fct_extra=value1', $url, 'Extra args must be merged into the success URL');
    }
}

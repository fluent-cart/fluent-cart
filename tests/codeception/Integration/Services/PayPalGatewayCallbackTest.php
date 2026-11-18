<?php

namespace Tests\Integration\Services;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Doubles\TestablePayPalGatewayCallback;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;
use Tests\Support\WpDieCapture;

class PayPalGatewayCallbackTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private ProductFactory $productFactory;
    private OrderFactory $orderFactory;
    private SubscriptionFactory $subscriptionFactory;
    private OrderTransactionFactory $txnFactory;

    public function setUp(): void
    {
        parent::setUp();

        $this->customerFactory = new CustomerFactory();
        $this->productFactory = new ProductFactory();
        $this->orderFactory = new OrderFactory();
        $this->subscriptionFactory = new SubscriptionFactory();
        $this->txnFactory = new OrderTransactionFactory();
    }

    public function test_paypal_subscription_confirmation_rejects_missing_custom_id(): void
    {
        $setup = $this->makePendingPayPalSubscriptionSetup('P-expected');

        App::request()->set('subscription_id', 'I-missing-custom');
        App::request()->set('ref_id', $setup['transaction']->uuid);

        $gateway = new TestablePayPalGatewayCallback([
            'id'      => 'I-missing-custom',
            'status'  => 'ACTIVE',
            'plan_id' => 'P-expected',
        ]);

        $capture = WpDieCapture::capture(function () use ($gateway) {
            $gateway->confirmPayPalSubscription();
        });

        $this->assertTrue($capture['died']);
        $this->assertSame(422, $capture['status']);
        $this->assertSame('failed', $capture['json']['status'] ?? null);
        $this->assertSame('PayPal subscription does not match this transaction!', $capture['json']['message'] ?? null);

        $freshSubscription = $setup['subscription']->fresh();
        $this->assertSame(Status::SUBSCRIPTION_PENDING, $freshSubscription->status);
        $this->assertEmpty($freshSubscription->vendor_subscription_id);
    }

    public function test_paypal_single_payment_confirmation_rejects_reused_capture_id(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'     => $customer->id,
            'payment_method'  => 'paypal',
            'currency'        => 'USD',
            'total_amount'    => 10000,
            'payment_status'  => Status::PAYMENT_PENDING,
        ]);
        $transaction = $this->txnFactory->create($order->id, [
            'payment_method' => 'paypal',
            'currency'       => 'USD',
            'total'          => 10000,
            'status'         => Status::TRANSACTION_PENDING,
        ]);

        $existingOrder = $this->orderFactory->create([
            'customer_id'     => $customer->id,
            'payment_method'  => 'paypal',
            'currency'        => 'USD',
            'total_amount'    => 10000,
            'payment_status'  => Status::PAYMENT_PENDING,
        ]);
        $existingTransaction = $this->txnFactory->create($existingOrder->id, [
            'payment_method' => 'paypal',
            'currency'       => 'USD',
            'total'          => 10000,
            'status'         => Status::TRANSACTION_PENDING,
        ]);
        $existingTransaction->update([
            'vendor_charge_id' => 'CAPTURE-CLAIMED',
        ]);

        App::request()->set('payId', 'PAYPAL-ORDER-CLAIMED');
        App::request()->set('ref_id', $transaction->uuid);

        $gateway = (new TestablePayPalGatewayCallback())
            ->withPayPalPayment([
                'status'         => 'COMPLETED',
                'purchase_units' => [
                    [
                        'reference_id' => $transaction->uuid,
                        'custom_id'    => $transaction->uuid,
                        'amount'       => [
                            'value'         => '100.00',
                            'currency_code' => 'USD',
                        ],
                        'payments'     => [
                            'captures' => [
                                ['id' => 'CAPTURE-CLAIMED', 'status' => 'COMPLETED'],
                            ],
                        ],
                    ],
                ],
            ]);

        $capture = WpDieCapture::capture(function () use ($gateway) {
            $gateway->confirmPayPalSinglePayment();
        });

        $this->assertTrue($capture['died']);
        $this->assertSame(422, $capture['status']);
        $this->assertSame('failed', $capture['json']['status'] ?? null);
        $this->assertSame('This PayPal payment has already been processed!', $capture['json']['message'] ?? null);

        $freshTransaction = $transaction->fresh();
        $this->assertSame(Status::TRANSACTION_PENDING, $freshTransaction->status);
        $this->assertEmpty($freshTransaction->vendor_charge_id);
    }

    public function test_paypal_subscription_confirmation_rejects_mismatched_custom_id(): void
    {
        $setup = $this->makePendingPayPalSubscriptionSetup('P-expected');

        App::request()->set('subscription_id', 'I-wrong-custom');
        App::request()->set('ref_id', $setup['transaction']->uuid);

        $gateway = new TestablePayPalGatewayCallback([
            'id'        => 'I-wrong-custom',
            'status'    => 'ACTIVE',
            'plan_id'   => 'P-expected',
            'custom_id' => 'different-local-subscription',
        ]);

        $capture = WpDieCapture::capture(function () use ($gateway) {
            $gateway->confirmPayPalSubscription();
        });

        $this->assertTrue($capture['died']);
        $this->assertSame(422, $capture['status']);
        $this->assertSame('PayPal subscription does not match this transaction!', $capture['json']['message'] ?? null);
    }

    public function test_paypal_subscription_confirmation_rejects_reused_subscription_id_after_binding_match(): void
    {
        $setup = $this->makePendingPayPalSubscriptionSetup('P-expected');
        $this->subscriptionFactory->create([
            'customer_id'             => $setup['customer']->id,
            'parent_order_id'         => $setup['order']->id,
            'product_id'              => $setup['product']->ID,
            'variation_id'            => $setup['variation']->id,
            'vendor_plan_id'          => 'P-expected',
            'vendor_subscription_id'  => 'I-reused',
            'status'                  => Status::SUBSCRIPTION_ACTIVE,
            'current_payment_method'  => 'paypal',
        ]);

        App::request()->set('subscription_id', 'I-reused');
        App::request()->set('ref_id', $setup['transaction']->uuid);

        $gateway = new TestablePayPalGatewayCallback([
            'id'        => 'I-reused',
            'status'    => 'ACTIVE',
            'plan_id'   => 'P-expected',
            'custom_id' => $setup['subscription']->uuid,
        ]);

        $capture = WpDieCapture::capture(function () use ($gateway) {
            $gateway->confirmPayPalSubscription();
        });

        $this->assertTrue($capture['died']);
        $this->assertSame(422, $capture['status']);
        $this->assertSame('This PayPal subscription has already been used!', $capture['json']['message'] ?? null);
    }

    private function makePendingPayPalSubscriptionSetup(string $vendorPlanId): array
    {
        $customer = $this->customerFactory->create();
        $product = $this->productFactory->withVariantAttributes([
            'payment_type' => 'subscription',
            'other_info'   => ['payment_type' => 'subscription'],
        ])->create();
        $variation = $product->variants->first();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'type'           => Status::ORDER_TYPE_SUBSCRIPTION,
            'payment_method' => 'paypal',
        ]);
        $subscription = $this->subscriptionFactory->create([
            'customer_id'      => $customer->id,
            'parent_order_id'  => $order->id,
            'product_id'       => $product->ID,
            'variation_id'     => $variation->id,
            'vendor_plan_id'   => $vendorPlanId,
            'status'           => Status::SUBSCRIPTION_PENDING,
        ]);
        $transaction = $this->txnFactory->create($order->id, [
            'order_type'       => Status::ORDER_TYPE_SUBSCRIPTION,
            'payment_method'   => 'paypal',
            'subscription_id'  => $subscription->id,
            'status'           => Status::TRANSACTION_PENDING,
            'total'            => 0,
        ]);

        return compact('customer', 'product', 'variation', 'order', 'subscription', 'transaction');
    }
}

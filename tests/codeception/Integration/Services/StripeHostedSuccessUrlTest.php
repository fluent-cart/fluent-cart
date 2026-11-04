<?php

namespace Tests\Integration\Services;

use FluentCart\App\Modules\PaymentMethods\StripeGateway\Confirmations;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Processor;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;

class StripeHostedSuccessUrlTest extends DatabaseTestCase
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
     * Spec: Cases/payment-success-url.md [6]
     *   "The return URL sent to Stripe hosted checkout ignores the success url filter
     *    and dispatches the hosted-return action with exactly one transaction hash."
     */
    public function test_gateway_return_url_ignores_success_url_filter(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $txn = $this->txnFactory->create($order->id, ['payment_method' => 'stripe']);

        add_filter('fluent_cart/payment/success_url', function () {
            return 'https://elsewhere.example/thanks';
        });

        $url = Processor::getHostedGatewayReturnUrl($txn);

        remove_all_filters('fluent_cart/payment/success_url');

        $this->assertStringNotContainsString('elsewhere.example', $url, 'The gateway return URL must not be affected by the success url filter');
        $this->assertStringContainsString('fluent-cart=fct_stripe_hosted', $url, 'The gateway return URL must dispatch the WebRoutes hosted-return action');
        $this->assertSame(1, substr_count($url, 'trx_hash='), 'The gateway return URL must carry exactly one trx_hash param');
        $this->assertStringContainsString('trx_hash=' . $txn->uuid, $url, 'The gateway return URL trx_hash must be the transaction uuid');
    }

    /**
     * Spec: Cases/payment-success-url.md [7]
     *   "After a confirmed hosted return, the buyer is redirected to the filtered success URL."
     */
    public function test_hosted_return_redirect_target_is_the_filtered_success_url(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $txn = $this->txnFactory->create($order->id, ['payment_method' => 'stripe']);

        add_filter('fluent_cart/payment/success_url', function ($url) {
            return add_query_arg('custom_dest', '1', $url);
        });

        $redirectUrl = (new Confirmations())->getHostedReturnRedirectUrl($txn);

        remove_all_filters('fluent_cart/payment/success_url');

        $this->assertStringContainsString('custom_dest=1', $redirectUrl, 'The post-confirm redirect must honor the success url filter');
        $this->assertStringContainsString('trx_hash=' . $txn->uuid, $redirectUrl, 'The post-confirm redirect must carry the transaction uuid');
        $this->assertStringContainsString('method=stripe', $redirectUrl, 'The post-confirm redirect must be the canonical success URL');
    }
}

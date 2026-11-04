<?php

namespace Tests\Integration\Modules\PaymentMethods;

use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Processor;
use FluentCart\App\Services\Payments\PaymentInstance;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderAddressFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;
use Tests\Support\Traits\ConfiguresStripeTestMode;

/**
 * Reactivating a subscription is how a customer corrects the billing address
 * on future Stripe invoices, so only that checkout updates the reused Stripe
 * customer's address. Stripe is faked at the HTTP layer.
 */
class StripeReactivationBillingAddressTest extends WPTestCase
{
    use ConfiguresStripeTestMode;

    private $httpMock;

    /** @var array<string, array> */
    private array $stripeCustomers = [];

    /** @var array<int, array{method:string, path:string, body:array}> */
    private array $calls = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->enableStripeTestMode();

        $this->httpMock = function ($preempt, $args, $url) {
            if (strpos($url, 'api.stripe.com') === false) {
                return $preempt;
            }

            $method = strtoupper($args['method'] ?? 'GET');
            $path = preg_replace('#^https://api\.stripe\.com/v1/#', '', strtok($url, '?'));
            $body = [];
            if (!empty($args['body']) && is_string($args['body'])) {
                parse_str($args['body'], $body);
            }
            $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body];

            if (strpos($path, 'customers/') === 0) {
                $id = substr($path, strlen('customers/'));
                if ($method === 'POST') {
                    $this->stripeCustomers[$id] = array_merge($this->stripeCustomers[$id], $body);
                }
                $response = $this->stripeCustomers[$id];
            } elseif (strpos($path, 'plans/') === 0) {
                $response = ['id' => substr($path, strlen('plans/')), 'object' => 'plan'];
            } elseif ($path === 'subscriptions' && $method === 'POST') {
                $response = [
                    'id'                   => 'sub_fake_new',
                    'customer'             => $body['customer'] ?? '',
                    'status'               => 'incomplete',
                    'pending_setup_intent' => null,
                    'latest_invoice'       => [
                        'payment_intent'      => 'pi_fake',
                        'confirmation_secret' => ['client_secret' => 'pi_fake_secret'],
                    ],
                ];
            } elseif ($path === 'checkout/sessions' && $method === 'POST') {
                $response = ['id' => 'cs_fake', 'customer' => $body['customer'] ?? '', 'url' => 'https://checkout.stripe.com/c/pay/cs_fake'];
            } else {
                $response = ['error' => ['message' => 'Unexpected fake Stripe call: ' . $method . ' ' . $path]];
            }

            return [
                'headers'  => [],
                'body'     => wp_json_encode($response),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
                'filename' => null,
            ];
        };
        add_filter('pre_http_request', $this->httpMock, 10, 3);
    }

    public function tearDown(): void
    {
        remove_filter('pre_http_request', $this->httpMock, 10);
        parent::tearDown();
    }

    private function customerWithStripeCustomer(): Customer
    {
        $customer = (new CustomerFactory())->create();
        $customer->updateMeta('stripe_customer_id', 'cus_existing');
        $this->stripeCustomers['cus_existing'] = [
            'id'       => 'cus_existing',
            'email'    => $customer->email,
            'currency' => 'usd',
            'address'  => ['line1' => '1 Wrong St', 'line2' => 'Apt 9', 'city' => 'Wrongville', 'country' => 'US'],
        ];

        return $customer;
    }

    private function order(Customer $customer, array $attributes, array $billingAddress): Order
    {
        $order = (new OrderFactory())->create(array_merge([
            'customer_id'    => $customer->id,
            'currency'       => 'USD',
            'mode'           => 'test',
            'payment_method' => 'stripe',
        ], $attributes));
        (new OrderAddressFactory())->create($order->id, $billingAddress);
        (new OrderTransactionFactory())->create($order->id, [
            'currency'       => 'USD',
            'payment_method' => 'stripe',
            'payment_mode'   => 'test',
            'total'          => 2999,
        ]);

        return $order;
    }

    /**
     * The shape Pro's reactivation checkout leaves before payment: a renewal
     * order under the original parent, recorded on the subscription.
     */
    private function reactivationOrder(Customer $customer, array $billingAddress): Order
    {
        $product = (new ProductFactory())->withVariantAttributes(['payment_type' => 'subscription'])->create();
        $parent = $this->order($customer, ['type' => 'subscription'], []);
        $subscription = (new SubscriptionFactory())->create([
            'customer_id'            => $customer->id,
            'parent_order_id'        => $parent->id,
            'product_id'             => $product->ID,
            'variation_id'           => $product->variants->first()->id,
            'current_payment_method' => 'stripe',
            'status'                 => 'canceled',
        ]);

        $order = $this->order($customer, ['type' => 'renewal', 'parent_id' => $parent->id], $billingAddress);

        $config = $subscription->config;
        $config['reactivation_order_id'] = $order->id;
        $subscription->config = $config;
        $subscription->save();

        return $order;
    }

    private function updatesTo(string $id): array
    {
        return array_values(array_filter($this->calls, function ($call) use ($id) {
            return $call['method'] === 'POST' && $call['path'] === 'customers/' . $id;
        }));
    }

    private function pay(Order $order)
    {
        $result = (new Processor())->handleSubscription(new PaymentInstance($order->fresh()), []);
        $this->assertNotInstanceOf(\WP_Error::class, $result, is_wp_error($result) ? $result->get_error_message() : '');
    }

    public function test_reactivation_updates_the_stripe_customer_address(): void
    {
        $customer = $this->customerWithStripeCustomer();
        $order = $this->reactivationOrder($customer, [
            'address_1' => '5 Correct Ave',
            'address_2' => '',
            'city'      => 'Boston',
            'state'     => 'MA',
            'postcode'  => '02101',
            'country'   => 'US',
        ]);

        $this->pay($order);

        $updates = $this->updatesTo('cus_existing');
        $this->assertCount(1, $updates);
        $this->assertSame([
            'line1'       => '5 Correct Ave',
            'line2'       => '',
            'city'        => 'Boston',
            'country'     => 'US',
            'postal_code' => '02101',
            'state'       => 'MA',
        ], $updates[0]['body']['address'], 'An empty second line clears the wrong one.');
        $this->assertSame('cus_existing', end($this->calls)['body']['customer'], 'The reactivated subscription bills the same customer.');
    }

    public function test_hosted_reactivation_updates_the_stripe_customer_address(): void
    {
        $hosted = function ($settings) {
            $settings['checkout_mode'] = 'hosted';
            return $settings;
        };
        add_filter('fluent_cart/stripe_settings', $hosted);

        try {
            $customer = $this->customerWithStripeCustomer();
            $this->pay($this->reactivationOrder($customer, ['address_1' => '5 Correct Ave', 'city' => 'Boston']));
        } finally {
            remove_filter('fluent_cart/stripe_settings', $hosted);
        }

        $updates = $this->updatesTo('cus_existing');
        $this->assertCount(1, $updates);
        $this->assertSame('5 Correct Ave', $updates[0]['body']['address']['line1']);
        $this->assertSame('Boston', $updates[0]['body']['address']['city']);
    }

    public function test_a_normal_subscription_purchase_does_not_replace_the_address(): void
    {
        $customer = $this->customerWithStripeCustomer();
        $product = (new ProductFactory())->withVariantAttributes(['payment_type' => 'subscription'])->create();
        $order = $this->order($customer, ['type' => 'subscription'], ['address_1' => '5 Correct Ave', 'city' => 'Boston']);
        (new SubscriptionFactory())->create([
            'customer_id'            => $customer->id,
            'parent_order_id'        => $order->id,
            'product_id'             => $product->ID,
            'variation_id'           => $product->variants->first()->id,
            'current_payment_method' => 'stripe',
            'status'                 => 'pending',
        ]);

        $this->pay($order);

        $this->assertCount(0, $this->updatesTo('cus_existing'));
        $this->assertSame('1 Wrong St', $this->stripeCustomers['cus_existing']['address']['line1']);
    }
}

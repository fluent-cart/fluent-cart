<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\SwitchCustomerMethod;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderAddressFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;
use Tests\Support\Traits\ConfiguresStripeTestMode;
use Tests\Support\WpDieCapture;

/**
 * Switching a subscription from another gateway to Stripe must use a Stripe
 * customer that can bill the subscription's currency. Stripe is faked at the HTTP layer and pins a customer to the currency of
 * its first subscription, like the real API.
 */
class StripeSwitchPaymentMethodCurrencyTest extends WPTestCase
{
    use ConfiguresStripeTestMode;

    private $httpMock;

    /** @var array<string, array> fake Stripe customers by id */
    private array $stripeCustomers = [];

    /** @var array<int, array{method:string, path:string, body:array, query:array}> */
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
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body, 'query' => $query];

            $status = 200;
            if ($path === 'customers' && $method === 'GET') {
                $email = $query['email'] ?? ($body['email'] ?? '');
                $response = ['data' => array_values(array_filter($this->stripeCustomers, function ($c) use ($email) {
                    return $c['email'] === $email;
                }))];
            } elseif ($path === 'customers' && $method === 'POST') {
                $id = 'cus_fake_new_' . (count($this->stripeCustomers) + 1);
                $this->stripeCustomers[$id] = ['id' => $id, 'email' => $body['email'] ?? null, 'currency' => null];
                $response = $this->stripeCustomers[$id];
            } elseif (preg_match('#^customers/([^/]+)/payment_methods$#', $path)) {
                $response = ['data' => []];
            } elseif (preg_match('#^customers/([^/]+)$#', $path, $m) && $method === 'GET') {
                if (isset($this->stripeCustomers[$m[1]])) {
                    $response = $this->stripeCustomers[$m[1]];
                } else {
                    $status = 404;
                    $response = ['error' => ['message' => 'No such customer']];
                }
            } elseif (preg_match('#^payment_methods/[^/]+/attach$#', $path)) {
                $response = ['id' => 'pm_fake', 'customer' => $body['customer'] ?? ''];
            } elseif (strpos($path, 'products/') === 0 && $method === 'GET') {
                $response = ['id' => substr($path, strlen('products/'))];
            } elseif (strpos($path, 'plans/') === 0 && $method === 'GET') {
                $response = ['id' => substr($path, strlen('plans/')), 'object' => 'plan'];
            } elseif ($path === 'subscriptions' && $method === 'POST') {
                $customerId = $body['customer'] ?? '';
                $planCurrency = strtolower(substr((string) ($body['items'][0]['plan'] ?? ''), -3));
                $customerCurrency = $this->stripeCustomers[$customerId]['currency'] ?? null;
                if ($customerCurrency && $customerCurrency !== $planCurrency) {
                    $status = 400;
                    $response = ['error' => ['message' => 'You cannot combine currencies on a single customer.']];
                } else {
                    if (isset($this->stripeCustomers[$customerId])) {
                        $this->stripeCustomers[$customerId]['currency'] = $planCurrency;
                    }
                    $response = ['id' => 'sub_fake_switched', 'customer' => $customerId, 'status' => 'active'];
                }
            } else {
                $status = 404;
                $response = ['error' => ['message' => 'Unexpected fake Stripe call: ' . $method . ' ' . $path]];
            }

            return [
                'headers'  => [],
                'body'     => wp_json_encode($response),
                'response' => ['code' => $status, 'message' => $status === 200 ? 'OK' : 'Error'],
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

    /**
     * A EUR subscription currently paid with another gateway. The shopper's
     * Stripe customer already bills GBP.
     */
    private function eurSubscription(): Subscription
    {
        $customer = (new CustomerFactory())->create();
        $customer->updateMeta('stripe_customer_id', 'cus_legacy_gbp');
        $this->stripeCustomers['cus_legacy_gbp'] = ['id' => 'cus_legacy_gbp', 'email' => $customer->email, 'currency' => 'gbp'];

        $product = (new ProductFactory())->withVariantAttributes(['payment_type' => 'subscription'])->create();
        $variant = $product->variants->first();

        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'type'           => 'subscription',
            'currency'       => 'EUR',
            'mode'           => 'test',
            'payment_method' => 'offline_payment',
        ]);
        (new OrderAddressFactory())->create($order->id);

        return (new SubscriptionFactory())->create([
            'customer_id'            => $customer->id,
            'parent_order_id'        => $order->id,
            'product_id'             => $product->ID,
            'variation_id'           => $variant->id,
            'currency'               => 'EUR',
            'recurring_total'        => 2000,
            'current_payment_method' => 'offline_payment',
            // A switch needs paid days left, so the new vendor subscription trials until this date.
            'next_billing_date'      => gmdate('Y-m-d H:i:s', strtotime('+10 days')),
            'status'                 => 'active',
        ]);
    }

    private function switchToStripe(Subscription $subscription): array
    {
        $customer = Customer::find($subscription->customer_id);

        return WpDieCapture::capture(function () use ($subscription, $customer) {
            (new SwitchCustomerMethod())->switchPayMethod([
                'currentPaymentMethod' => 'offline_payment',
                'verification_status'  => 'verified',
                'vendorPaymentMethod'  => [
                    'id'              => 'pm_fake',
                    'type'            => 'card',
                    'card'            => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030],
                    'billing_details' => ['email' => $customer->email, 'name' => 'Test Buyer'],
                ],
            ], $subscription->id);
        });
    }

    private function subscriptionCreates(): array
    {
        return array_values(array_filter($this->calls, function ($call) {
            return $call['method'] === 'POST' && $call['path'] === 'subscriptions';
        }));
    }

    public function test_switch_uses_a_stripe_customer_that_can_bill_the_subscription_currency(): void
    {
        $subscription = $this->eurSubscription();

        $result = $this->switchToStripe($subscription);

        $this->assertSame('success', $result['json']['status'] ?? null, $result['body']);
        $eurCustomerId = Customer::find($subscription->customer_id)->getMeta('stripe_customer_id_eur');
        $this->assertNotEmpty($eurCustomerId);
        $creates = $this->subscriptionCreates();
        $this->assertSame($eurCustomerId, $creates[0]['body']['customer']);
        $this->assertSame($eurCustomerId, Subscription::find($subscription->id)->vendor_customer_id);
    }
}

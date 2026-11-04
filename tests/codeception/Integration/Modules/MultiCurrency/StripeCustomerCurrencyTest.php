<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Processor;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\StripeHelper;
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
 * MC-KI-01: Stripe bills one currency per customer, so a shopper with a GBP
 * Stripe subscription needs a separate Stripe customer for an EUR subscription.
 * Stripe is faked at the HTTP layer with an in-memory customer store.
 */
class StripeCustomerCurrencyTest extends WPTestCase
{
    use ConfiguresStripeTestMode;

    private $httpMock;

    /** @var array<string, array> fake Stripe customers by id */
    private array $stripeCustomers = [];

    /** @var array<int, array{method:string, path:string, body:array}> */
    private array $calls = [];

    private int $createdCount = 0;

    public function setUp(): void
    {
        parent::setUp();
        $this->enableStripeTestMode();
        $this->installStripeFake();
    }

    public function tearDown(): void
    {
        if ($this->httpMock) {
            remove_filter('pre_http_request', $this->httpMock, 10);
            $this->httpMock = null;
        }
        parent::tearDown();
    }

    private function installStripeFake(): void
    {
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

            $status = 200;
            $response = [];

            if ($path === 'customers' && $method === 'POST') {
                $this->createdCount++;
                $id = 'cus_fake_new_' . $this->createdCount;
                $this->stripeCustomers[$id] = [
                    'id'       => $id,
                    'object'   => 'customer',
                    'email'    => $body['email'] ?? null,
                    'name'     => $body['name'] ?? null,
                    'currency' => null,
                ];
                $response = $this->stripeCustomers[$id];
            } elseif (strpos($path, 'customers/') === 0 && $method === 'GET') {
                $id = substr($path, strlen('customers/'));
                if (isset($this->stripeCustomers[$id])) {
                    $response = $this->stripeCustomers[$id];
                } else {
                    $status = 404;
                    $response = ['error' => ['message' => 'No such customer: ' . $id]];
                }
            } elseif (strpos($path, 'plans/') === 0 && $method === 'GET') {
                $response = ['id' => substr($path, strlen('plans/')), 'object' => 'plan'];
            } elseif ($path === 'subscriptions' && $method === 'POST') {
                // Stripe pins a customer to the currency of its first subscription.
                $customerId = $body['customer'] ?? '';
                $planCurrency = strtolower(substr((string) ($body['items'][0]['plan'] ?? ''), -3));
                $customerCurrency = $this->stripeCustomers[$customerId]['currency'] ?? null;
                if ($customerCurrency && $customerCurrency !== $planCurrency) {
                    return [
                        'headers'  => [],
                        'body'     => wp_json_encode(['error' => ['message' => 'You cannot combine currencies on a single customer. This customer has an active subscription, subscription schedule, discount, quote, or invoice item with currency ' . $customerCurrency . '.']]),
                        'response' => ['code' => 400, 'message' => 'Bad Request'],
                        'cookies'  => [],
                        'filename' => null,
                    ];
                }
                if (isset($this->stripeCustomers[$customerId])) {
                    $this->stripeCustomers[$customerId]['currency'] = $planCurrency;
                }
                $response = [
                    'id'                   => 'sub_fake_1',
                    'customer'             => $body['customer'] ?? '',
                    'status'               => 'incomplete',
                    'pending_setup_intent' => null,
                    'latest_invoice'       => [
                        'payment_intent'      => 'pi_fake_sub_1',
                        'confirmation_secret' => ['client_secret' => 'pi_fake_sub_1_secret'],
                    ],
                ];
            } elseif ($path === 'checkout/sessions' && $method === 'POST') {
                $response = [
                    'id'       => 'cs_fake_1',
                    'customer' => $body['customer'] ?? '',
                    'url'      => 'https://checkout.stripe.com/c/pay/cs_fake_1',
                ];
            } elseif ($path === 'payment_intents' && $method === 'POST') {
                $response = [
                    'id'            => 'pi_fake_one_time_1',
                    'status'        => 'requires_payment_method',
                    'customer'      => $body['customer'] ?? '',
                    'amount'        => (int) ($body['amount'] ?? 0),
                    'currency'      => $body['currency'] ?? '',
                    'client_secret' => 'pi_fake_one_time_1_secret',
                ];
            } else {
                $status = 404;
                $response = ['error' => ['message' => 'Unexpected fake Stripe call: ' . $method . ' ' . $path]];
            }

            return [
                'headers'  => [],
                'body'     => wp_json_encode($response),
                'response' => ['code' => $status, 'message' => $status === 200 ? 'OK' : 'Not Found'],
                'cookies'  => [],
                'filename' => null,
            ];
        };

        add_filter('pre_http_request', $this->httpMock, 10, 3);
    }

    private function seedStripeCustomer(string $id, string $email, $currency): void
    {
        $this->stripeCustomers[$id] = [
            'id'       => $id,
            'object'   => 'customer',
            'email'    => $email,
            'currency' => $currency,
        ];
    }

    private function customerWithLegacyStripeId(string $stripeId, $stripeCurrency): Customer
    {
        $customer = (new CustomerFactory())->create();
        $customer->updateMeta('stripe_customer_id', $stripeId);
        $this->seedStripeCustomer($stripeId, $customer->email, $stripeCurrency);

        return $customer;
    }

    private function customerCreates(): int
    {
        return count(array_filter($this->calls, function ($call) {
            return $call['method'] === 'POST' && $call['path'] === 'customers';
        }));
    }

    public function test_other_currency_gets_a_dedicated_customer_stored_per_currency(): void
    {
        $customer = $this->customerWithLegacyStripeId('cus_legacy_gbp', 'gbp');

        $stripeCustomer = StripeHelper::createOrGetStripeCustomer($customer, 'EUR');

        $this->assertNotInstanceOf(\WP_Error::class, $stripeCustomer);
        $this->assertNotSame('cus_legacy_gbp', $stripeCustomer['id'], 'A GBP-billing Stripe customer cannot take an EUR subscription.');
        $this->assertSame(1, $this->customerCreates());
        $this->assertSame($customer->email, $this->stripeCustomers[$stripeCustomer['id']]['email']);
        $this->assertSame($stripeCustomer['id'], $customer->getMeta('stripe_customer_id_eur'));
        $this->assertSame('cus_legacy_gbp', $customer->getMeta('stripe_customer_id'), 'The legacy id keeps serving GBP.');
    }

    public function test_same_currency_reuses_the_legacy_customer_without_creating_one(): void
    {
        $customer = $this->customerWithLegacyStripeId('cus_legacy_gbp', 'gbp');

        $stripeCustomer = StripeHelper::createOrGetStripeCustomer($customer, 'GBP');

        $this->assertSame('cus_legacy_gbp', $stripeCustomer['id']);
        $this->assertSame(0, $this->customerCreates());
        $this->assertCount(1, $this->calls, 'Same-currency flow makes exactly the one lookup it made before.');
        $this->assertNull($customer->getMeta('stripe_customer_id_gbp'));
    }

    public function test_never_billed_legacy_customer_is_reused_for_any_currency(): void
    {
        $customer = $this->customerWithLegacyStripeId('cus_legacy_blank', null);

        $this->assertSame('cus_legacy_blank', StripeHelper::createOrGetStripeCustomer($customer, 'EUR')['id']);
        $this->assertSame('cus_legacy_blank', StripeHelper::createOrGetStripeCustomer($customer, 'JPY')['id']);
        $this->assertSame(0, $this->customerCreates());
        $this->assertNull($customer->getMeta('stripe_customer_id_eur'));
    }

    public function test_second_order_in_that_currency_reuses_the_stored_dedicated_customer(): void
    {
        $customer = $this->customerWithLegacyStripeId('cus_legacy_gbp', 'gbp');

        $first = StripeHelper::createOrGetStripeCustomer($customer, 'EUR');
        $this->assertNotSame('cus_legacy_gbp', $first['id']);
        // The first EUR subscription makes Stripe pin that customer to eur.
        $this->stripeCustomers[$first['id']]['currency'] = 'eur';
        $this->calls = [];

        $second = StripeHelper::createOrGetStripeCustomer(Customer::find($customer->id), 'eur');

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(0, $this->customerCreates());
        $this->assertCount(1, $this->calls, 'The stored EUR customer is checked directly.');
        $this->assertSame('customers/' . $first['id'], $this->calls[0]['path']);
    }

    public function test_call_without_currency_keeps_the_legacy_customer(): void
    {
        $customer = $this->customerWithLegacyStripeId('cus_legacy_gbp', 'gbp');

        $this->assertSame('cus_legacy_gbp', StripeHelper::createOrGetStripeCustomer($customer)['id']);
        $this->assertSame(0, $this->customerCreates());
    }

    public function test_unusable_stored_currency_customer_is_replaced(): void
    {
        $customer = $this->customerWithLegacyStripeId('cus_legacy_gbp', 'gbp');
        $customer->updateMeta('stripe_customer_id_eur', 'cus_deleted_eur');

        $stripeCustomer = StripeHelper::createOrGetStripeCustomer($customer, 'EUR');

        $this->assertNotSame('cus_deleted_eur', $stripeCustomer['id']);
        $this->assertNotSame('cus_legacy_gbp', $stripeCustomer['id']);
        $this->assertSame($stripeCustomer['id'], $customer->getMeta('stripe_customer_id_eur'));
        $this->assertSame('cus_legacy_gbp', $customer->getMeta('stripe_customer_id'));
    }

    public function test_stored_currency_customer_billing_another_currency_is_not_used(): void
    {
        $customer = $this->customerWithLegacyStripeId('cus_legacy_gbp', 'gbp');
        $customer->updateMeta('stripe_customer_id_eur', 'cus_wrong_usd');
        $this->seedStripeCustomer('cus_wrong_usd', $customer->email, 'usd');

        $stripeCustomer = StripeHelper::createOrGetStripeCustomer($customer, 'EUR');

        $this->assertNotContains($stripeCustomer['id'], ['cus_wrong_usd', 'cus_legacy_gbp']);
        $this->assertSame($stripeCustomer['id'], $customer->getMeta('stripe_customer_id_eur'));
    }

    public function test_first_ever_stripe_customer_is_still_stored_under_the_legacy_key(): void
    {
        $customer = (new CustomerFactory())->create();

        $stripeCustomer = StripeHelper::createOrGetStripeCustomer($customer, 'EUR');

        $this->assertSame($stripeCustomer['id'], $customer->getMeta('stripe_customer_id'));
        $this->assertNull($customer->getMeta('stripe_customer_id_eur'));
    }

    public function test_invalid_legacy_customer_is_replaced_under_the_legacy_key(): void
    {
        $customer = (new CustomerFactory())->create();
        $customer->updateMeta('stripe_customer_id', 'cus_gone');

        $stripeCustomer = StripeHelper::createOrGetStripeCustomer($customer, 'EUR');

        $this->assertNotSame('cus_gone', $stripeCustomer['id']);
        $this->assertSame($stripeCustomer['id'], $customer->getMeta('stripe_customer_id'));
        $this->assertNull($customer->getMeta('stripe_customer_id_eur'));
    }

    /**
     * @return array{0: PaymentInstance, 1: Subscription}
     */
    private function makeOrder(Customer $customer, string $currency, string $type): array
    {
        $product = (new ProductFactory())->withVariantAttributes(['payment_type' => 'subscription'])->create();
        $variant = $product->variants->first();

        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'type'           => $type,
            'currency'       => $currency,
            'mode'           => 'test',
            'payment_method' => 'stripe',
        ]);
        (new OrderAddressFactory())->create($order->id);
        (new OrderTransactionFactory())->create($order->id, [
            'currency'       => $currency,
            'payment_method' => 'stripe',
            'payment_mode'   => 'test',
            'total'          => 2999,
        ]);

        $subscription = null;
        if ($type === 'subscription') {
            $subscription = (new SubscriptionFactory())->create([
                'customer_id'            => $customer->id,
                'parent_order_id'        => $order->id,
                'product_id'             => $product->ID,
                'variation_id'           => $variant->id,
                'current_payment_method' => 'stripe',
                'status'                 => 'pending',
            ]);
        }

        return [new PaymentInstance($order->fresh()), $subscription];
    }

    public function test_eur_subscription_checkout_creates_the_subscription_on_the_eur_customer(): void
    {
        $customer = $this->customerWithLegacyStripeId('cus_legacy_gbp', 'gbp');
        [$paymentInstance, $subscription] = $this->makeOrder($customer, 'EUR', 'subscription');

        $result = (new Processor())->handleSubscription($paymentInstance, []);

        $this->assertNotInstanceOf(\WP_Error::class, $result, is_wp_error($result) ? $result->get_error_message() : '');
        $subscriptionCreates = array_values(array_filter($this->calls, function ($call) {
            return $call['method'] === 'POST' && $call['path'] === 'subscriptions';
        }));
        $this->assertCount(1, $subscriptionCreates);

        $eurCustomerId = $customer->getMeta('stripe_customer_id_eur');
        $this->assertNotEmpty($eurCustomerId);
        $this->assertSame($eurCustomerId, $subscriptionCreates[0]['body']['customer']);
        $this->assertSame($eurCustomerId, Subscription::find($subscription->id)->vendor_customer_id);
    }

    public function test_hosted_eur_subscription_checkout_uses_the_eur_customer(): void
    {
        $hosted = function ($settings) {
            $settings['checkout_mode'] = 'hosted';
            return $settings;
        };
        add_filter('fluent_cart/stripe_settings', $hosted);

        try {
            $customer = $this->customerWithLegacyStripeId('cus_legacy_gbp', 'gbp');
            [$paymentInstance, $subscription] = $this->makeOrder($customer, 'EUR', 'subscription');

            $result = (new Processor())->handleSubscription($paymentInstance, []);
        } finally {
            remove_filter('fluent_cart/stripe_settings', $hosted);
        }

        $this->assertNotInstanceOf(\WP_Error::class, $result, is_wp_error($result) ? $result->get_error_message() : '');
        $sessionCreates = array_values(array_filter($this->calls, function ($call) {
            return $call['method'] === 'POST' && $call['path'] === 'checkout/sessions';
        }));
        $this->assertCount(1, $sessionCreates);

        $eurCustomerId = $customer->getMeta('stripe_customer_id_eur');
        $this->assertNotEmpty($eurCustomerId);
        $this->assertSame($eurCustomerId, $sessionCreates[0]['body']['customer']);
        $this->assertSame($eurCustomerId, Subscription::find($subscription->id)->vendor_customer_id);
    }

    public function test_one_time_payment_in_another_currency_keeps_the_legacy_customer(): void
    {
        // PaymentIntents are not tied to a customer currency, so cards saved on
        // the legacy customer stay usable.
        $customer = $this->customerWithLegacyStripeId('cus_legacy_gbp', 'gbp');
        [$paymentInstance] = $this->makeOrder($customer, 'EUR', 'payment');

        $result = (new Processor())->handleSinglePayment($paymentInstance, []);

        $this->assertNotInstanceOf(\WP_Error::class, $result, is_wp_error($result) ? $result->get_error_message() : '');
        $intentCreates = array_values(array_filter($this->calls, function ($call) {
            return $call['method'] === 'POST' && $call['path'] === 'payment_intents';
        }));
        $this->assertCount(1, $intentCreates);
        $this->assertSame('cus_legacy_gbp', $intentCreates[0]['body']['customer']);
        $this->assertSame(0, $this->customerCreates());
    }
}

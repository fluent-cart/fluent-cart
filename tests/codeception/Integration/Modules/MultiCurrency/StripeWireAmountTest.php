<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Plan;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Processor;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\StripeHelper;
use FluentCart\App\Services\Payments\PaymentInstance;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderAddressFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Traits\ConfiguresStripeTestMode;

/**
 * Stripe takes UGX amounts ×100 like a two-decimal currency, although it
 * charges whole shillings. Every other zero-decimal currency goes on the
 * wire unscaled. Stripe is faked at the HTTP layer.
 */
class StripeWireAmountTest extends WPTestCase
{
    use ConfiguresStripeTestMode;

    private $httpMock;

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

            $status = 200;
            if ($path === 'customers' && $method === 'POST') {
                $response = ['id' => 'cus_fake_1', 'email' => $body['email'] ?? null];
            } elseif ($path === 'payment_intents' && $method === 'POST') {
                $response = [
                    'id'            => 'pi_fake_1',
                    'status'        => 'requires_payment_method',
                    'amount'        => (int) ($body['amount'] ?? 0),
                    'currency'      => $body['currency'] ?? '',
                    'client_secret' => 'pi_fake_1_secret',
                ];
            } elseif ($path === 'refunds' && $method === 'POST') {
                $response = ['id' => 're_fake_1', 'status' => 'succeeded'];
            } elseif (strpos($path, 'products/') === 0 && $method === 'GET') {
                $response = ['id' => substr($path, strlen('products/')), 'object' => 'product'];
            } elseif ($path === 'plans' && $method === 'POST') {
                $response = $body + ['object' => 'plan'];
            } else {
                $status = 404;
                $response = ['error' => ['message' => 'No such object: ' . $path]];
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

    public function tearDown(): void
    {
        remove_filter('pre_http_request', $this->httpMock, 10);
        parent::tearDown();
    }

    private function callsTo(string $method, string $path): array
    {
        return array_values(array_filter($this->calls, function ($call) use ($method, $path) {
            return $call['method'] === $method && $call['path'] === $path;
        }));
    }

    private function oneTimePayment(string $currency, int $total): PaymentInstance
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'type'           => 'payment',
            'currency'       => $currency,
            'mode'           => 'test',
            'payment_method' => 'stripe',
            'total_amount'   => $total,
        ]);
        (new OrderAddressFactory())->create($order->id);
        (new OrderTransactionFactory())->create($order->id, [
            'currency'       => $currency,
            'payment_method' => 'stripe',
            'payment_mode'   => 'test',
            'total'          => $total,
        ]);

        return new PaymentInstance($order->fresh());
    }

    public function test_only_ugx_leaves_the_shared_zero_decimal_list(): void
    {
        $this->assertFalse(StripeHelper::isZeroDecimal('UGX'));
        $this->assertFalse(StripeHelper::isZeroDecimal('ugx'));
        $this->assertTrue(StripeHelper::isZeroDecimal('JPY'));
        $this->assertTrue(StripeHelper::isZeroDecimal('krw'));
        $this->assertFalse(StripeHelper::isZeroDecimal('USD'));
        $this->assertFalse(StripeHelper::isZeroDecimal(''));
        $this->assertFalse(StripeHelper::isZeroDecimal(null));
    }

    public function test_ugx_payment_intent_sends_the_amount_times_100(): void
    {
        // 50,000 UGX is stored as 5,000,000 and goes to Stripe as 5,000,000.
        $result = (new Processor())->handleSinglePayment($this->oneTimePayment('UGX', 5000000), []);

        $this->assertNotInstanceOf(\WP_Error::class, $result, is_wp_error($result) ? $result->get_error_message() : '');
        $intents = $this->callsTo('POST', 'payment_intents');
        $this->assertCount(1, $intents);
        $this->assertSame('5000000', (string) $intents[0]['body']['amount']);
    }

    public function test_jpy_payment_intent_still_sends_whole_yen(): void
    {
        $result = (new Processor())->handleSinglePayment($this->oneTimePayment('JPY', 150000), []);

        $this->assertNotInstanceOf(\WP_Error::class, $result, is_wp_error($result) ? $result->get_error_message() : '');
        $intents = $this->callsTo('POST', 'payment_intents');
        $this->assertCount(1, $intents);
        $this->assertSame('1500', (string) $intents[0]['body']['amount']);
    }

    public function test_ugx_refund_sends_the_amount_times_100(): void
    {
        $paymentInstance = $this->oneTimePayment('UGX', 5000000);
        $transaction = OrderTransaction::query()->where('order_id', $paymentInstance->order->id)->first();
        $transaction->update(['vendor_charge_id' => 'pi_fake_paid']);

        $result = StripeHelper::processRemoteRefund($transaction, 2000000, []);

        $this->assertSame('re_fake_1', $result);
        $refunds = $this->callsTo('POST', 'refunds');
        $this->assertCount(1, $refunds);
        $this->assertSame('2000000', (string) $refunds[0]['body']['amount']);
    }

    public function test_ugx_plan_sends_the_amount_times_100_under_a_new_plan_id(): void
    {
        $product = (new ProductFactory())->withVariantAttributes(['payment_type' => 'subscription'])->create();
        $variant = $product->variants->first();
        $data = [
            'order_id'         => $this->oneTimePayment('UGX', 5000000)->order->id,
            'product_id'       => $product->ID,
            'variation_id'     => $variant->id,
            'billing_interval' => 'monthly',
            'recurring_total'  => 5000000,
            'currency'         => 'UGX',
            'trial_days'       => 0,
            'interval_count'   => 1,
        ];

        $plan = Plan::getStripePricing($data);

        $this->assertNotInstanceOf(\WP_Error::class, $plan, is_wp_error($plan) ? $plan->get_error_message() : '');
        $created = $this->callsTo('POST', 'plans');
        $this->assertCount(1, $created);
        $this->assertSame('5000000', (string) $created[0]['body']['amount']);

        // A UGX plan created before this fix carries the same price inputs at 1/100 of the amount.
        $oldPlanId = 'fct_' . \FluentCart\App\Helpers\Helper::getSitePrefix() . '_price_' . $variant->id . '_5000000_monthly_1_0_UGX';
        $this->assertNotSame($oldPlanId, $created[0]['body']['id']);
        $this->assertCount(0, $this->callsTo('GET', 'plans/' . $oldPlanId));
    }

    public function test_jpy_plan_keeps_its_plan_id_and_whole_yen(): void
    {
        $product = (new ProductFactory())->withVariantAttributes(['payment_type' => 'subscription'])->create();
        $variant = $product->variants->first();

        Plan::getStripePricing([
            'order_id'         => $this->oneTimePayment('JPY', 150000)->order->id,
            'product_id'       => $product->ID,
            'variation_id'     => $variant->id,
            'billing_interval' => 'monthly',
            'recurring_total'  => 150000,
            'currency'         => 'JPY',
            'trial_days'       => 0,
            'interval_count'   => 1,
        ]);

        $created = $this->callsTo('POST', 'plans');
        $this->assertCount(1, $created);
        $this->assertSame('1500', (string) $created[0]['body']['amount']);
        $this->assertSame(
            'fct_' . \FluentCart\App\Helpers\Helper::getSitePrefix() . '_price_' . $variant->id . '_150000_monthly_1_0_JPY',
            $created[0]['body']['id']
        );
    }

    public function test_incoming_ugx_amounts_map_back_unchanged(): void
    {
        $this->assertSame(5000000, StripeHelper::toInternalAmount(5000000, 'ugx'));
        $this->assertSame(150000, StripeHelper::toInternalAmount(1500, 'jpy'));

        $update = StripeHelper::getSubscriptionUpdateData([
            'status' => 'active',
            'plan'   => ['amount' => 5000000, 'currency' => 'ugx'],
        ]);
        $this->assertSame(5000000, (int) $update['recurring_total']);
    }
}

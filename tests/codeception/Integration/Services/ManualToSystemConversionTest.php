<?php

namespace Tests\Integration\Services;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Activity;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\PayPal;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\Processor as PayPalProcessor;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Confirmations;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Stripe;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionManagementMode;
use FluentCart\App\Modules\Subscriptions\Services\SystemChargeService;
use FluentCart\App\Services\Payments\PaymentInstance;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeSystemGateway;
use Tests\Support\Traits\BuildsSystemSubscriptionInvoices;
use Tests\Support\Traits\ConfiguresStoreOrderMode;
use Tests\Support\Traits\MocksPayPalHttp;

/**
 * A manual subscription becomes `system` (auto-charged by FluentCart) when its
 * renewal invoice is paid through Stripe or PayPal, on a
 * store-managed store with auto-charge on. No saved method ⇒ it stays manual.
 */
class ManualToSystemConversionTest extends DatabaseTestCase
{
    use BuildsSystemSubscriptionInvoices;
    use ConfiguresStoreOrderMode;
    use MocksPayPalHttp;

    private string $mode = SubscriptionManagementMode::STORE_MANAGED;
    private bool $systemCharge = true;
    private array $converted = [];
    private ?string $intentUsage = 'off_session';
    private $stripeHttpFilter;
    private int $intentFetches = 0;
    private bool $intentFetchFails = false;

    public function setUp(): void
    {
        parent::setUp();

        FakeSystemGateway::register();
        $this->setStoreOrderMode('live');
        $this->installPayPalHttpMock();
        if (!defined('FCT_STRIPE_TEST_SECRET_KEY')) {
            define('FCT_STRIPE_TEST_SECRET_KEY', 'sk_test_fct_suite_fake_key');
        }
        $this->stripeHttpFilter = function ($preempt, $args, $url) {
            if (strpos($url, 'api.stripe.com/v1/payment_intents/') === false) {
                return $preempt;
            }
            $this->intentFetches++;
            if ($this->intentFetchFails) {
                return new \WP_Error('http_request_failed', 'timeout');
            }

            return [
                'headers'  => [],
                'body'     => wp_json_encode(['id' => 'pi_conv', 'object' => 'payment_intent', 'setup_future_usage' => $this->intentUsage]),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
                'filename' => null,
            ];
        };
        add_filter('pre_http_request', $this->stripeHttpFilter, 10, 3);
        add_filter('pre_wp_mail', '__return_true');
        add_filter('fluent_cart/subscription/management_mode', [$this, 'filterMode']);
        add_filter('fluent_cart/subscriptions/system_collection_enabled', [$this, 'filterSystemCharge']);
        add_action('fluent_cart/subscription_converted_to_system', [$this, 'recordConversion']);
    }

    public function tearDown(): void
    {
        remove_action('fluent_cart/subscription_converted_to_system', [$this, 'recordConversion']);
        remove_filter('fluent_cart/subscriptions/system_collection_enabled', [$this, 'filterSystemCharge']);
        remove_filter('fluent_cart/subscription/management_mode', [$this, 'filterMode']);
        remove_filter('pre_wp_mail', '__return_true');
        remove_filter('pre_http_request', $this->stripeHttpFilter, 10);
        $this->removePayPalHttpMock();
        FakeSystemGateway::remove();
        parent::tearDown();
    }

    public function filterMode()
    {
        return $this->mode;
    }

    public function filterSystemCharge()
    {
        return $this->systemCharge;
    }

    public function recordConversion($data)
    {
        $this->converted[] = $data;
    }

    public function test_only_an_open_manual_sub_on_a_system_capable_gateway_with_auto_charge_on_can_convert(): void
    {
        $stripe = new Stripe();
        $noSystem = new class {
            public function has($feature)
            {
                return false;
            }
        };

        ['sub' => $sub] = $this->buildSystemSubscriptionWithInvoice(['collection_method' => 'manual']);

        $this->assertTrue(SubscriptionManagementMode::canConvertToSystem($sub, $stripe));
        $this->assertTrue(SubscriptionManagementMode::canConvertToSystem($sub, new PayPal()));
        $this->assertFalse(SubscriptionManagementMode::canConvertToSystem($sub, $noSystem), 'gateway without system_subscription');

        $sub->status = Status::SUBSCRIPTION_EXPIRED;
        $this->assertTrue(SubscriptionManagementMode::canConvertToSystem($sub, $stripe), 'expired can still renew');
        $sub->status = Status::SUBSCRIPTION_CANCELED;
        $this->assertFalse(SubscriptionManagementMode::canConvertToSystem($sub, $stripe));
        $sub->status = Status::SUBSCRIPTION_COMPLETED;
        $this->assertFalse(SubscriptionManagementMode::canConvertToSystem($sub, $stripe));
        $sub->status = Status::SUBSCRIPTION_ACTIVE;

        $sub->collection_method = 'system';
        $this->assertFalse(SubscriptionManagementMode::canConvertToSystem($sub, $stripe));
        $sub->collection_method = 'automatic';
        $this->assertFalse(SubscriptionManagementMode::canConvertToSystem($sub, $stripe));
        $sub->collection_method = 'manual';

        $this->systemCharge = false;
        $this->assertFalse(SubscriptionManagementMode::canConvertToSystem($sub, $stripe), 'auto-charge off');

        $this->systemCharge = true;
        $this->mode = SubscriptionManagementMode::GATEWAY_MANAGED;
        $this->assertFalse(SubscriptionManagementMode::canConvertToSystem($sub, $stripe), 'gateway-managed store');
    }

    public function test_flag_is_set_for_an_eligible_renewal_and_cleared_on_a_retry_that_no_longer_qualifies(): void
    {
        ['invoice' => $invoice] = $this->buildSystemSubscriptionWithInvoice(['collection_method' => 'manual']);
        $instance = new PaymentInstance(Order::query()->find($invoice->id));

        $this->assertTrue(SubscriptionManagementMode::flagSystemConversion($instance, new Stripe()));
        $this->assertTrue(SubscriptionManagementMode::transactionConvertsToSystem($this->invoiceTransaction($invoice)));

        $this->systemCharge = false;
        $retry = new PaymentInstance(Order::query()->find($invoice->id));
        $this->assertFalse(SubscriptionManagementMode::flagSystemConversion($retry, new Stripe()));
        $this->assertFalse(SubscriptionManagementMode::transactionConvertsToSystem($this->invoiceTransaction($invoice)));
    }

    public function test_flag_is_not_set_for_a_non_renewal_order_or_when_auto_charge_is_off(): void
    {
        ['invoice' => $invoice, 'order' => $parent] = $this->buildSystemSubscriptionWithInvoice(['collection_method' => 'manual']);

        $parentInstance = new PaymentInstance(Order::query()->find($parent->id));
        $this->assertFalse(SubscriptionManagementMode::flagSystemConversion($parentInstance, new Stripe()));

        $this->systemCharge = false;
        $instance = new PaymentInstance(Order::query()->find($invoice->id));
        $this->assertFalse(SubscriptionManagementMode::flagSystemConversion($instance, new Stripe()));
        $this->assertFalse(SubscriptionManagementMode::transactionConvertsToSystem($this->invoiceTransaction($invoice)));
    }

    public function test_promote_without_a_saved_method_keeps_the_sub_manual(): void
    {
        ['sub' => $sub] = $this->buildSystemSubscriptionWithInvoice(['collection_method' => 'manual']);

        $this->assertFalse(SystemChargeService::promoteToSystem($sub, ['method' => 'stripe', 'vendor_method_id' => ''], 'cus_x', 'stripe'));

        $this->assertSame('manual', $this->reloadSubscription($sub)->collection_method);
        $this->assertSame([], $this->converted);
    }

    public function test_stripe_renewal_paid_converts_to_system(): void
    {
        ['sub' => $sub, 'invoice' => $invoice] = $this->buildSystemSubscriptionWithInvoice(['collection_method' => 'manual']);
        SubscriptionManagementMode::flagSystemConversion(new PaymentInstance(Order::query()->find($invoice->id)), new Stripe());

        (new Confirmations())->confirmPaymentSuccessByCharge($this->invoiceTransaction($invoice), ['charge' => $this->stripeCharge()]);

        $after = $this->reloadSubscription($sub);
        $this->assertSame('system', $after->collection_method);
        $this->assertSame('stripe', $after->current_payment_method);
        $this->assertSame('cus_conv', $after->vendor_customer_id);
        $this->assertSame('pm_conv', $after->getMeta('active_payment_method', [])['vendor_method_id'] ?? null);
        $this->assertTrue(SubscriptionManagementMode::isSubscriptionStoreManaged($after));
        $this->assertCount(1, $this->converted, 'one conversion event');
        $this->assertSame('stripe', $this->converted[0]['payment_method']);
        $this->assertSame((int) $sub->id, (int) $this->converted[0]['subscription']->id);
        $this->assertSame('paid', Order::query()->find($invoice->id)->payment_status);
    }

    public function test_stripe_renewal_paid_without_flag_stays_manual(): void
    {
        ['sub' => $sub, 'invoice' => $invoice] = $this->buildSystemSubscriptionWithInvoice(['collection_method' => 'manual']);

        (new Confirmations())->confirmPaymentSuccessByCharge($this->invoiceTransaction($invoice), ['charge' => $this->stripeCharge()]);

        $this->assertSame('manual', $this->reloadSubscription($sub)->collection_method);
        $this->assertSame('paid', Order::query()->find($invoice->id)->payment_status);
        $this->assertSame([], $this->converted);
        $this->assertSame(0, $this->intentFetches, 'no flag, no intent fetch');
    }

    public function test_flagged_payment_whose_intent_saved_no_card_stays_manual(): void
    {
        ['sub' => $sub, 'invoice' => $invoice] = $this->buildSystemSubscriptionWithInvoice(['collection_method' => 'manual']);
        // A retry flags the transaction, then confirms an earlier succeeded intent that saved no card.
        SubscriptionManagementMode::flagSystemConversion(new PaymentInstance(Order::query()->find($invoice->id)), new Stripe());
        $this->intentUsage = null;

        (new Confirmations())->confirmPaymentSuccessByCharge($this->invoiceTransaction($invoice), ['charge' => $this->stripeCharge()]);

        $this->assertSame('manual', $this->reloadSubscription($sub)->collection_method);
        $this->assertSame('paid', Order::query()->find($invoice->id)->payment_status);
        $this->assertSame([], $this->converted);
    }

    public function test_a_failed_conversion_write_rolls_back_and_leaves_the_sub_manual(): void
    {
        ['sub' => $sub] = $this->buildSystemSubscriptionWithInvoice(['collection_method' => 'manual']);
        $failSubscriptionWrite = function ($query) {
            if (stripos($query, 'UPDATE') === 0 && strpos($query, 'fct_subscriptions') !== false && strpos($query, '`config`') !== false) {
                throw new \RuntimeException('subscription write failed');
            }
            return $query;
        };
        add_filter('query', $failSubscriptionWrite);

        try {
            SystemChargeService::promoteToSystem($this->reloadSubscription($sub), ['method' => 'stripe', 'vendor_method_id' => 'pm_conv'], 'cus_conv', 'stripe');
            $this->fail('expected the subscription write to throw');
        } catch (\RuntimeException $e) {
            $this->assertSame('subscription write failed', $e->getMessage());
        } finally {
            remove_filter('query', $failSubscriptionWrite);
        }

        $after = $this->reloadSubscription($sub);
        $this->assertSame('manual', $after->collection_method);
        $this->assertSame([], $after->getMeta('active_payment_method', []), 'meta write rolled back');
        $this->assertSame([], $this->converted);
    }

    public function test_failed_intent_fetch_keeps_the_sub_manual_and_logs_it(): void
    {
        ['sub' => $sub, 'invoice' => $invoice] = $this->buildSystemSubscriptionWithInvoice(['collection_method' => 'manual']);
        SubscriptionManagementMode::flagSystemConversion(new PaymentInstance(Order::query()->find($invoice->id)), new Stripe());
        $this->intentFetchFails = true;

        (new Confirmations())->confirmPaymentSuccessByCharge($this->invoiceTransaction($invoice), ['charge' => $this->stripeCharge()]);

        $this->assertSame('manual', $this->reloadSubscription($sub)->collection_method);
        $this->assertSame('paid', Order::query()->find($invoice->id)->payment_status);
        $this->assertTrue(Activity::query()
            ->where('module_name', 'subscription')
            ->where('module_id', $sub->id)
            ->where('title', 'Auto-charge Conversion Skipped')
            ->exists());
    }

    public function test_concurrent_promotes_convert_once(): void
    {
        ['sub' => $sub] = $this->buildSystemSubscriptionWithInvoice(['collection_method' => 'manual']);
        $first = $this->reloadSubscription($sub);
        $second = $this->reloadSubscription($sub);
        $billing = ['method' => 'stripe', 'vendor_method_id' => 'pm_conv'];

        $this->assertTrue(SystemChargeService::promoteToSystem($first, $billing, 'cus_conv', 'stripe'));
        $this->assertFalse(SystemChargeService::promoteToSystem($second, $billing, 'cus_conv', 'stripe'));

        $this->assertSame('system', $this->reloadSubscription($sub)->collection_method);
        $this->assertCount(1, $this->converted);
        $this->assertSame(1, \FluentCart\App\Models\SubscriptionMeta::query()
            ->where('subscription_id', $sub->id)
            ->where('meta_key', 'active_payment_method')
            ->count(), 'the losing promote writes no meta');
    }

    public function test_paypal_renewal_paid_with_vaulted_token_converts_to_system(): void
    {
        ['sub' => $sub, 'invoice' => $invoice] = $this->buildSystemSubscriptionWithInvoice(['collection_method' => 'manual']);
        SubscriptionManagementMode::flagSystemConversion(new PaymentInstance(Order::query()->find($invoice->id)), new PayPal());

        (new PayPalProcessor())->maybePersistVaultToken($this->invoiceTransaction($invoice), $this->capturedPayPalOrder());

        $after = $this->reloadSubscription($sub);
        $this->assertSame('system', $after->collection_method);
        $this->assertSame('paypal', $after->current_payment_method);
        $this->assertSame('ppcust_1', $after->vendor_customer_id);
        $this->assertSame('tok_vault_1', $after->getMeta('active_payment_method', [])['vendor_method_id'] ?? null);
        $this->assertTrue(SubscriptionManagementMode::isSubscriptionStoreManaged($after));
        $this->assertCount(1, $this->converted);
        $this->assertSame('paypal', $this->converted[0]['payment_method']);
    }

    public function test_paypal_renewal_paid_without_flag_stays_manual(): void
    {
        ['sub' => $sub, 'invoice' => $invoice] = $this->buildSystemSubscriptionWithInvoice(['collection_method' => 'manual']);

        (new PayPalProcessor())->maybePersistVaultToken($this->invoiceTransaction($invoice), $this->capturedPayPalOrder());

        $this->assertSame('manual', $this->reloadSubscription($sub)->collection_method);
        $this->assertSame([], $this->converted);
    }

    public function test_paypal_renewal_paid_without_a_token_stays_manual(): void
    {
        ['sub' => $sub, 'invoice' => $invoice] = $this->buildSystemSubscriptionWithInvoice(['collection_method' => 'manual']);
        SubscriptionManagementMode::flagSystemConversion(new PaymentInstance(Order::query()->find($invoice->id)), new PayPal());

        (new PayPalProcessor())->maybePersistVaultToken($this->invoiceTransaction($invoice), $this->capturedPayPalOrderWithoutVaulting());
        (new PayPalProcessor())->maybePersistVaultToken($this->invoiceTransaction($invoice), $this->capturedPayPalOrderWithDeclinedSave());

        $after = $this->reloadSubscription($sub);
        $this->assertSame('manual', $after->collection_method);
        $this->assertSame([], $this->converted);
    }

    public function test_gateway_managed_store_still_converts_manual_to_automatic(): void
    {
        $this->mode = SubscriptionManagementMode::GATEWAY_MANAGED;
        ['sub' => $sub, 'invoice' => $invoice] = $this->buildSystemSubscriptionWithInvoice(['collection_method' => 'manual']);
        $instance = new PaymentInstance(Order::query()->find($invoice->id));

        $this->assertFalse(SubscriptionManagementMode::flagSystemConversion($instance, new Stripe()));
        $this->assertTrue((new Stripe())->convertManualSubscription($instance, []));
        $this->assertSame('automatic', $this->reloadSubscription($sub)->collection_method);
    }

    private function invoiceTransaction($invoice): OrderTransaction
    {
        return OrderTransaction::query()
            ->where('order_id', $invoice->id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->latest()
            ->first();
    }

    private function stripeCharge(): array
    {
        return [
            'id'                     => 'ch_conv',
            'status'                 => 'succeeded',
            'amount'                 => 2999,
            'currency'               => 'usd',
            'livemode'               => false,
            'customer'               => 'cus_conv',
            'payment_method'         => 'pm_conv',
            'payment_intent'         => 'pi_conv',
            'payment_method_details' => [
                'type' => 'card',
                'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030],
            ],
        ];
    }
}

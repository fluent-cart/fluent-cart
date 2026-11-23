<?php

namespace Tests\Integration\Services;

use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Processor;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionGatewayGate;
use FluentCart\App\Services\Payments\PaymentInstance;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeSystemGateway;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;

/**
 * SCF-03: the zero-payable trial setup flow — gateway contract defaults, the
 * store-managed gateway-gate matrix for zero-payable carts, and the setup flow
 * reaching Stripe without a consent checkbox (disclosure only, like paid checkout).
 */
class TrialSetupAdmissionTest extends DatabaseTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        FakeSystemGateway::register();
    }

    public function tearDown(): void
    {
        FakeSystemGateway::remove();
        $this->setStoreSettings([]); // restore defaults + flush static cache
        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function setStoreSettings(array $overrides): void
    {
        $settings = get_option('fluent_cart_store_settings', []);
        unset($settings['subscription_management_mode'], $settings['subscription_system_charge']);
        $settings = array_merge($settings, $overrides);
        update_option('fluent_cart_store_settings', $settings);

        wp_cache_flush();
        $ref = new \ReflectionClass(\FluentCart\Api\StoreSettings::class);
        if ($ref->hasProperty('cachedStoreSettings')) {
            $prop = $ref->getProperty('cachedStoreSettings');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }
    }

    private function trialCart(): Cart
    {
        $cart = new Cart();
        $cart->cart_data = [[
            'subtotal'   => 1500,
            'quantity'   => 1,
            'other_info' => ['payment_type' => 'subscription', 'trial_days' => 14, 'signup_fee' => 0],
        ]];

        return $cart;
    }

    private function runAdmissionFilter(Cart $cart): array
    {
        $methods = [
            FakeSystemGateway::SLUG => GatewayManager::getInstance()->get(FakeSystemGateway::SLUG),
            'offline_payment'       => GatewayManager::getInstance()->get('offline_payment'),
        ];

        return (new SubscriptionGatewayGate())->filterCheckoutPaymentMethods($methods, ['cart' => $cart]);
    }

    private function reactivationCartFor(Subscription $subscription): Cart
    {
        $cart = new Cart();
        $cart->cart_data = [[
            'subtotal'   => 1500,
            'quantity'   => 1,
            'other_info' => ['payment_type' => 'subscription'],
        ]];
        $cart->checkout_data = ['renew_data' => ['subscription_hash' => $subscription->uuid]];

        return $cart;
    }

    private function makeSubscription(array $overrides): Subscription
    {
        $customer = (new CustomerFactory())->create();
        $product = (new ProductFactory())->withVariantAttributes(['payment_type' => 'subscription'])->create();
        $variant = $product->variants->first();
        $order = (new OrderFactory())->withAddresses()->create([
            'customer_id' => $customer->id,
            'type'        => 'subscription',
            'status'      => 'active',
        ]);

        return (new SubscriptionFactory())->create(array_merge([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => $product->ID,
            'variation_id'    => $variant->id,
        ], $overrides));
    }

    /** FakeSystemGateway reporting `subscriptions` but not `system_subscription` — a plain vendor-subscription gateway. */
    private function withPlainSubscriptionGateway(callable $fn): void
    {
        $gateway = GatewayManager::getInstance()->get(FakeSystemGateway::SLUG);
        $original = $gateway->supportedFeatures;
        $gateway->supportedFeatures = ['payment', 'subscriptions'];

        try {
            $fn();
        } finally {
            $gateway->supportedFeatures = $original;
        }
    }

    // =========================================================================
    // Gateway contract
    // =========================================================================

    public function test_setup_without_charge_defaults_to_false(): void
    {
        $gateway = GatewayManager::getInstance()->get(FakeSystemGateway::SLUG);
        $this->assertFalse($gateway->supportsSetupWithoutCharge(), 'base contract must default to false');

        $cod = GatewayManager::getInstance()->get('offline_payment');
        $this->assertFalse($cod->supportsSetupWithoutCharge());
    }

    // =========================================================================
    // Admission matrix — zero-payable trial cart under store-managed mode
    // =========================================================================

    public function test_zero_payable_trial_shows_setup_capable_gateway_when_auto_charge_on(): void
    {
        $this->setStoreSettings([
            'subscription_management_mode' => 'store_managed',
            'subscription_system_charge'   => 'yes',
        ]);
        FakeSystemGateway::$setupWithoutCharge = true;

        $visible = $this->runAdmissionFilter($this->trialCart());

        $this->assertArrayHasKey(FakeSystemGateway::SLUG, $visible, 'setup-capable gateway must be offered');
        $this->assertArrayHasKey('offline_payment', $visible, 'non-auto gateways stay visible');
    }

    public function test_zero_payable_trial_hides_setup_capable_gateway_when_auto_charge_off(): void
    {
        $this->setStoreSettings([
            'subscription_management_mode' => 'store_managed',
            'subscription_system_charge'   => 'no',
        ]);
        FakeSystemGateway::$setupWithoutCharge = true;

        $visible = $this->runAdmissionFilter($this->trialCart());

        $this->assertArrayNotHasKey(
            FakeSystemGateway::SLUG,
            $visible,
            'auto-charge is off, so the resulting subscription falls back to manual collection — a saved token would never be auto-charged, so no setup is offered; only offline stays available'
        );
    }

    public function test_zero_payable_trial_hides_gateway_without_setup_support(): void
    {
        $this->setStoreSettings([
            'subscription_management_mode' => 'store_managed',
            'subscription_system_charge'   => 'yes',
        ]);
        FakeSystemGateway::$setupWithoutCharge = false;

        $visible = $this->runAdmissionFilter($this->trialCart());

        $this->assertArrayNotHasKey(FakeSystemGateway::SLUG, $visible, 'a $0 charge would be rejected — pre-feature behavior');
    }

    public function test_zero_payable_trial_gateway_managed_keeps_subscription_gateway_and_hides_offline(): void
    {
        $this->setStoreSettings([
            'subscription_management_mode' => 'gateway_managed',
        ]);

        $visible = $this->runAdmissionFilter($this->trialCart());

        $this->assertArrayHasKey(FakeSystemGateway::SLUG, $visible, 'native subscription support carries the trial at the vendor');
        $this->assertArrayNotHasKey('offline_payment', $visible, 'offline cannot run the subscription — no manual fallback by default');
    }

    public function test_zero_payable_trial_gateway_managed_shows_offline_when_manual_fallback_enabled(): void
    {
        $this->setStoreSettings([
            'subscription_management_mode' => 'gateway_managed',
            'subscription_manual_fallback' => 'yes',
        ]);

        $visible = $this->runAdmissionFilter($this->trialCart());

        $this->assertArrayHasKey('offline_payment', $visible, 'manual fallback opt-in restores offline for zero-payable trials');
    }

    public function test_zero_payable_trial_hides_payment_only_online_gateway_in_every_mode(): void
    {
        $gateway = GatewayManager::getInstance()->get(FakeSystemGateway::SLUG);
        $original = $gateway->supportedFeatures;
        $gateway->supportedFeatures = ['payment', 'refund'];

        try {
            $modes = [
                ['subscription_management_mode' => 'gateway_managed', 'expect_offline' => false],
                ['subscription_management_mode' => 'store_managed', 'subscription_system_charge' => 'no', 'expect_offline' => true],
                ['subscription_management_mode' => 'store_managed', 'subscription_system_charge' => 'yes', 'expect_offline' => true],
            ];

            foreach ($modes as $settings) {
                $expectOffline = $settings['expect_offline'];
                unset($settings['expect_offline']);
                $this->setStoreSettings($settings);

                $visible = $this->runAdmissionFilter($this->trialCart());

                $this->assertArrayNotHasKey(
                    FakeSystemGateway::SLUG,
                    $visible,
                    'an online gateway without subscription/offline capability cannot process a zero-amount checkout'
                );

                if ($expectOffline) {
                    $this->assertArrayHasKey('offline_payment', $visible);
                } else {
                    $this->assertArrayNotHasKey('offline_payment', $visible, 'gateway-managed default has no manual fallback, not even offline');
                }
            }
        } finally {
            $gateway->supportedFeatures = $original;
        }
    }

    public function test_zero_payable_trial_gateway_managed_still_hides_plain_online_gateway_when_manual_fallback_enabled(): void
    {
        $gateway = GatewayManager::getInstance()->get(FakeSystemGateway::SLUG);
        $original = $gateway->supportedFeatures;
        $gateway->supportedFeatures = ['payment', 'refund'];

        $this->setStoreSettings([
            'subscription_management_mode' => 'gateway_managed',
            'subscription_manual_fallback' => 'yes',
        ]);

        try {
            $visible = $this->runAdmissionFilter($this->trialCart());
        } finally {
            $gateway->supportedFeatures = $original;
        }

        $this->assertArrayNotHasKey(
            FakeSystemGateway::SLUG,
            $visible,
            'the opt-in cannot rescue a gateway that can neither process a zero-amount checkout nor save a token — zero-payable still needs a zero-payment-capable gateway'
        );
    }

    public function test_payable_subscription_cart_is_unaffected(): void
    {
        $this->setStoreSettings([
            'subscription_management_mode' => 'store_managed',
            'subscription_system_charge'   => 'yes',
        ]);
        FakeSystemGateway::$setupWithoutCharge = false;

        $cart = new Cart();
        $cart->cart_data = [[
            'subtotal'   => 1500,
            'quantity'   => 1,
            'other_info' => ['payment_type' => 'subscription', 'trial_days' => 0, 'signup_fee' => 0],
        ]];

        $visible = $this->runAdmissionFilter($cart);

        $this->assertArrayHasKey(FakeSystemGateway::SLUG, $visible, 'payable carts keep the existing one-time-charge rule');
    }

    private function payableSubscriptionCart(): Cart
    {
        $cart = new Cart();
        $cart->cart_data = [[
            'subtotal'   => 1500,
            'quantity'   => 1,
            'other_info' => ['payment_type' => 'subscription', 'trial_days' => 0, 'signup_fee' => 0],
        ]];

        return $cart;
    }

    public function test_payable_gateway_managed_hides_offline_by_default(): void
    {
        $this->setStoreSettings(['subscription_management_mode' => 'gateway_managed']);

        $visible = $this->runAdmissionFilter($this->payableSubscriptionCart());

        $this->assertArrayHasKey(FakeSystemGateway::SLUG, $visible, 'native subscription support always runs the subscription');
        $this->assertArrayNotHasKey('offline_payment', $visible, 'no manual fallback by default — a payable cart cannot silently fall back to manual billing');
    }

    public function test_payable_gateway_managed_shows_every_gateway_when_manual_fallback_enabled(): void
    {
        $this->setStoreSettings([
            'subscription_management_mode' => 'gateway_managed',
            'subscription_manual_fallback' => 'yes',
        ]);

        $visible = $this->runAdmissionFilter($this->payableSubscriptionCart());

        $this->assertArrayHasKey(FakeSystemGateway::SLUG, $visible);
        $this->assertArrayHasKey('offline_payment', $visible, 'opt-in restores manual fallback for non-subscription gateways');
    }

    // =========================================================================
    // Existing-subscription cart (renewal / reactivation) — born vs. current mode
    // =========================================================================

    public function test_automatic_subscription_hides_non_subscription_gateways_under_gateway_managed(): void
    {
        $this->setStoreSettings(['subscription_management_mode' => 'gateway_managed']);

        $sub = $this->makeSubscription(['collection_method' => 'automatic', 'config' => []]);

        $this->withPlainSubscriptionGateway(function () use ($sub) {
            $visible = $this->runAdmissionFilter($this->reactivationCartFor($sub));

            $this->assertArrayHasKey(FakeSystemGateway::SLUG, $visible, 'the gateway can keep owning the schedule');
            $this->assertArrayNotHasKey(
                'offline_payment',
                $visible,
                'paying an automatic subscription offline downgrades it to manual billing — not offered by default'
            );
        });
    }

    public function test_automatic_subscription_ignores_manual_fallback_opt_in(): void
    {
        $this->setStoreSettings([
            'subscription_management_mode' => 'gateway_managed',
            'subscription_manual_fallback' => 'yes',
        ]);

        $sub = $this->makeSubscription(['collection_method' => 'automatic', 'config' => []]);

        $this->withPlainSubscriptionGateway(function () use ($sub) {
            $visible = $this->runAdmissionFilter($this->reactivationCartFor($sub));

            $this->assertArrayHasKey(FakeSystemGateway::SLUG, $visible);
            $this->assertArrayNotHasKey(
                'offline_payment',
                $visible,
                'the opt-in governs manual subscriptions only — an automatic one already has a working schedule to protect'
            );
        });
    }

    public function test_automatic_subscription_shows_every_gateway_under_store_managed(): void
    {
        $this->setStoreSettings(['subscription_management_mode' => 'store_managed']);

        $sub = $this->makeSubscription(['collection_method' => 'automatic', 'config' => []]);

        $this->withPlainSubscriptionGateway(function () use ($sub) {
            $visible = $this->runAdmissionFilter($this->reactivationCartFor($sub));

            $this->assertArrayHasKey('offline_payment', $visible, 'store-managed bills manually by design — nothing to protect the schedule from');
        });
    }

    public function test_manual_subscription_born_gateway_managed_allows_conversion_when_store_still_gateway_managed(): void
    {
        $this->setStoreSettings(['subscription_management_mode' => 'gateway_managed']);

        $sub = $this->makeSubscription(['collection_method' => 'manual', 'config' => []]);

        $this->withPlainSubscriptionGateway(function () use ($sub) {
            $visible = $this->runAdmissionFilter($this->reactivationCartFor($sub));

            $this->assertArrayHasKey(
                FakeSystemGateway::SLUG,
                $visible,
                'manual was a fallback (gateway could not create a vendor sub), not a deliberate choice — paying via a subscription-capable gateway now converts it to automatic'
            );
        });
    }

    public function test_manual_subscription_born_gateway_managed_hides_non_subscription_gateways_by_default(): void
    {
        $this->setStoreSettings(['subscription_management_mode' => 'gateway_managed']);

        $sub = $this->makeSubscription(['collection_method' => 'manual', 'config' => []]);

        $this->withPlainSubscriptionGateway(function () use ($sub) {
            $visible = $this->runAdmissionFilter($this->reactivationCartFor($sub));

            $this->assertArrayNotHasKey(
                'offline_payment',
                $visible,
                'paying offline leaves it billing manually forever — the same silent fallback the new-subscription gate now refuses'
            );
        });
    }

    public function test_manual_subscription_born_gateway_managed_shows_non_subscription_gateways_when_manual_fallback_enabled(): void
    {
        $this->setStoreSettings([
            'subscription_management_mode' => 'gateway_managed',
            'subscription_manual_fallback' => 'yes',
        ]);

        $sub = $this->makeSubscription(['collection_method' => 'manual', 'config' => []]);

        $this->withPlainSubscriptionGateway(function () use ($sub) {
            $visible = $this->runAdmissionFilter($this->reactivationCartFor($sub));

            $this->assertArrayHasKey(FakeSystemGateway::SLUG, $visible);
            $this->assertArrayHasKey('offline_payment', $visible, 'opt-in restores manual billing on renewals too');
        });
    }

    public function test_manual_subscription_stamped_store_managed_never_shows_plain_subscription_gateway(): void
    {
        // Store is back to gateway_managed today, but this subscription was BORN
        // store-managed (stamped) — the stamp wins, conversion must stay closed.
        $this->setStoreSettings(['subscription_management_mode' => 'gateway_managed']);

        $sub = $this->makeSubscription([
            'collection_method' => 'manual',
            'config'            => ['management_mode' => 'store_managed'],
        ]);

        $this->withPlainSubscriptionGateway(function () use ($sub) {
            $visible = $this->runAdmissionFilter($this->reactivationCartFor($sub));

            $this->assertArrayNotHasKey(
                FakeSystemGateway::SLUG,
                $visible,
                'born store-managed (stamped) is a deliberate choice — flipping the store setting back must never re-open conversion'
            );
            $this->assertArrayHasKey('offline_payment', $visible);
        });
    }

    public function test_manual_subscription_born_gateway_managed_restricts_once_store_is_store_managed(): void
    {
        // Unstamped (born gateway-managed), but the store has since switched to
        // store_managed — the LIVE setting closes the conversion window too.
        $this->setStoreSettings(['subscription_management_mode' => 'store_managed']);

        $sub = $this->makeSubscription(['collection_method' => 'manual', 'config' => []]);

        $this->withPlainSubscriptionGateway(function () use ($sub) {
            $visible = $this->runAdmissionFilter($this->reactivationCartFor($sub));

            $this->assertArrayNotHasKey(
                FakeSystemGateway::SLUG,
                $visible,
                'store is store_managed today — no vendor subscription may be created underneath any subscription, unstamped or not'
            );
        });
    }

    public function test_system_subscription_never_shows_plain_subscription_gateway(): void
    {
        $this->setStoreSettings(['subscription_management_mode' => 'gateway_managed']);

        $sub = $this->makeSubscription([
            'collection_method' => 'system',
            'config'            => ['management_mode' => 'store_managed'],
        ]);

        $this->withPlainSubscriptionGateway(function () use ($sub) {
            $visible = $this->runAdmissionFilter($this->reactivationCartFor($sub));

            $this->assertArrayNotHasKey(FakeSystemGateway::SLUG, $visible, 'system has no conversion path at all, ever');
        });

        $visible = $this->runAdmissionFilter($this->reactivationCartFor($sub));
        $this->assertArrayHasKey(FakeSystemGateway::SLUG, $visible, 'a real system_subscription gateway stays admitted for the one-time renewal charge');
    }

    public function test_unresolvable_existing_subscription_gates_nothing(): void
    {
        $this->setStoreSettings(['subscription_management_mode' => 'gateway_managed']);

        $cart = new Cart();
        $cart->cart_data = [[
            'subtotal'   => 1500,
            'quantity'   => 1,
            'other_info' => ['payment_type' => 'subscription'],
        ]];
        $cart->checkout_data = ['renew_data' => ['subscription_hash' => 'no-such-subscription-uuid']];

        $this->withPlainSubscriptionGateway(function () use ($cart) {
            $visible = $this->runAdmissionFilter($cart);

            $this->assertArrayHasKey(
                FakeSystemGateway::SLUG,
                $visible,
                'nothing to gate — the charge itself fails later with no_subscription, and hiding gateways would break plain one-time checkouts'
            );
            $this->assertArrayHasKey('offline_payment', $visible);
        });
    }

    // =========================================================================
    // No consent checkbox: the setup flow goes straight to Stripe
    // =========================================================================

    public function test_zero_payable_setup_flow_needs_no_consent_flag(): void
    {
        $customerFactory = new CustomerFactory();
        $productFactory = new ProductFactory();
        $orderFactory = new OrderFactory();
        $itemFactory = new OrderItemFactory();
        $txnFactory = new OrderTransactionFactory();
        $subscriptionFactory = new SubscriptionFactory();

        $customer = $customerFactory->create();
        $product = $productFactory->withVariantAttributes(['payment_type' => 'subscription'])->create();
        $variant = $product->variants->first();

        $order = $orderFactory->withAddresses()->create([
            'customer_id'    => $customer->id,
            'type'           => 'subscription',
            'status'         => 'pending',
            'payment_status' => 'pending',
            'payment_method' => 'stripe',
            'total_amount'   => 0,
            'total_paid'     => 0,
        ]);

        $itemFactory->create($order->id, [
            'post_id'      => $product->ID,
            'object_id'    => $variant->id,
            'payment_type' => 'subscription',
        ]);

        $sub = $subscriptionFactory->create([
            'customer_id'            => $customer->id,
            'parent_order_id'        => $order->id,
            'product_id'             => $product->ID,
            'variation_id'           => $variant->id,
            'collection_method'      => 'system',
            'current_payment_method' => 'stripe',
            'status'                 => 'intended',
            'trial_days'             => 14,
        ]);

        $txnFactory->create($order->id, [
            'subscription_id'  => $sub->id,
            'transaction_type' => 'charge',
            'status'           => 'pending',
            'total'            => 0,
        ]);

        unset($_REQUEST['_fct_system_consent'], $_POST['_fct_system_consent'], $_GET['_fct_system_consent']);

        $stripeCalls = 0;
        $blockHttp = function ($pre, $args, $url) use (&$stripeCalls) {
            if (strpos($url, 'api.stripe.com') !== false) {
                $stripeCalls++;
            }
            return new \WP_Error('http_blocked', 'test cause');
        };
        $stripeKeys = function ($settings) {
            $settings['test_secret_key'] = \FluentCart\App\Helpers\Helper::encryptKey('sk_test_consent_regression');
            $settings['live_secret_key'] = \FluentCart\App\Helpers\Helper::encryptKey('sk_test_consent_regression');
            return $settings;
        };
        add_filter('pre_http_request', $blockHttp, 10, 3);
        add_filter('fluent_cart/stripe_settings', $stripeKeys);

        try {
            $result = (new Processor())->handleSetupOnlyPayment(
                new PaymentInstance(\FluentCart\App\Models\Order::query()->find($order->id))
            );
        } finally {
            remove_filter('pre_http_request', $blockHttp, 10);
            remove_filter('fluent_cart/stripe_settings', $stripeKeys);
        }

        $this->assertFalse(
            is_wp_error($result) && $result->get_error_code() === 'consent_required',
            'a trial setup checkout must not require a consent checkbox'
        );
        $this->assertGreaterThan(0, $stripeCalls, 'the setup flow must reach Stripe without a consent flag');
    }
}

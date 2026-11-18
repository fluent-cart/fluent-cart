<?php

namespace Tests\Integration\Services;

use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\Cod\Cod;
use FluentCart\App\Modules\PaymentMethods\Cod\CodSettingsBase;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\WpDieCapture;

class AbstractPaymentGatewayTest extends WPTestCase
{
    private Cod $cod;

    public function setUp(): void
    {
        parent::setUp();
        $this->cod = new Cod();
    }

    // =========================================================================
    // Spec 39-40: Feature flags
    // =========================================================================

    public function test_has_returns_true_for_registered_feature(): void
    {
        $this->assertTrue($this->cod->has('refund'));
        $this->assertTrue($this->cod->has('payment'));
    }

    public function test_has_returns_false_for_unknown_feature(): void
    {
        $this->assertFalse($this->cod->has('unknown'));
    }

    // =========================================================================
    // Spec 41: Constructor appends subscriptions when module present
    // =========================================================================

    public function test_constructor_appends_subscriptions_when_subscription_module_passed(): void
    {
        // COD no longer passes a subscription module (store-billed since the invoice
        // engine) — use an inline gateway to assert the constructor contract itself.
        $module = new class extends AbstractSubscriptionModule {
            public function cancel($vendorSubscriptionId, $args = []) { return []; }
            public function reSyncSubscriptionFromRemote(\FluentCart\App\Models\Subscription $s) { return $s; }
        };

        $gateway = new class(new CodSettingsBase(), $module) extends AbstractPaymentGateway {
            public array $supportedFeatures = ['payment'];
            public function meta(): array { return ['slug' => 'test_gw_subs', 'route' => 'test_gw_subs', 'upcoming' => false]; }
            public function makePaymentFromPaymentInstance($p) { return []; }
            public function handleIPN() {}
            public function getOrderInfo(array $d) {}
            public function fields() { return []; }
        };

        $this->assertTrue($gateway->has('subscriptions'));
        $this->assertNotNull($gateway->subscriptions);
    }

    // =========================================================================
    // Spec 42: Null subscription module does NOT add 'subscriptions'
    // =========================================================================

    public function test_constructor_with_null_subscription_module_does_not_add_subscriptions(): void
    {
        $gateway = new class(new CodSettingsBase()) extends AbstractPaymentGateway {
            public array $supportedFeatures = ['payment'];
            public function meta(): array { return ['slug' => 'test_gw', 'route' => 'test_gw', 'upcoming' => false]; }
            public function makePaymentFromPaymentInstance($p) { return []; }
            public function handleIPN() {}
            public function getOrderInfo(array $d) {}
            public function fields() { return []; }
        };

        $this->assertFalse($gateway->has('subscriptions'));
        $this->assertNull($gateway->subscriptions);
    }

    // =========================================================================
    // Spec 43-44: Metadata
    // =========================================================================

    public function test_get_meta_returns_merged_settings(): void
    {
        $meta = $this->cod->getMeta();
        $this->assertArrayHasKey('title', $meta);
        $this->assertArrayHasKey('route', $meta);
        $this->assertArrayHasKey('slug', $meta);
    }

    public function test_get_meta_with_key_returns_single_value(): void
    {
        $this->assertSame('offline_payment', $this->cod->getMeta('slug'));
        $this->assertSame('offline_payment', $this->cod->getMeta('route'));
    }

    // =========================================================================
    // Spec 45: isUpcoming
    // =========================================================================

    public function test_is_upcoming_reflects_meta_flag(): void
    {
        $this->assertFalse($this->cod->isUpcoming());
    }

    // =========================================================================
    // Spec 46: updateSettings rejects when upcoming
    // =========================================================================

    public function test_update_settings_rejects_when_upcoming(): void
    {
        $gateway = new class(new CodSettingsBase()) extends AbstractPaymentGateway {
            public array $supportedFeatures = ['payment'];
            public function meta(): array { return ['slug' => 'upc_gw', 'route' => 'upc_gw', 'upcoming' => true]; }
            public function makePaymentFromPaymentInstance($p) { return []; }
            public function handleIPN() {}
            public function getOrderInfo(array $d) {}
            public function fields() { return []; }
        };

        $capture = WpDieCapture::capture(fn() => $gateway->updateSettings(['is_active' => 'yes']));

        $this->assertTrue($capture['died'], 'upcoming gateway must short-circuit via wp_send_json');
        $this->assertSame(422, $capture['status']);
        $this->assertSame('failed', $capture['json']['status'] ?? null);
        $this->assertStringContainsString('upcoming', strtolower($capture['json']['message'] ?? ''));
    }

    // =========================================================================
    // Spec 47-49: updateSettings sanitize + unset provider + persist
    // =========================================================================

    public function test_update_settings_unsets_provider_and_persists(): void
    {
        $settings = $this->cod->updateSettings([
            'is_active' => 'no',
            'provider'  => 'should_be_stripped',
        ]);

        $this->assertArrayNotHasKey('provider', $settings);

        $persisted = fluent_cart_get_option('fluent_cart_payment_settings_offline_payment');
        $this->assertIsArray($persisted);
        $this->assertArrayNotHasKey('provider', $persisted);
    }

    // =========================================================================
    // Spec 50: Invalid settings reject (static validateSettings)
    // =========================================================================

    public function test_invalid_settings_rejected_by_validate_settings(): void
    {
        $gateway = new class(new CodSettingsBase()) extends AbstractPaymentGateway {
            public array $supportedFeatures = ['payment'];
            public function meta(): array { return ['slug' => 'bad_gw', 'route' => 'bad_gw', 'upcoming' => false]; }
            public function makePaymentFromPaymentInstance($p) { return []; }
            public function handleIPN() {}
            public function getOrderInfo(array $d) {}
            public function fields() {
                return ['is_active' => ['type' => 'input-text', 'label' => 'Active']];
            }
            public static function validateSettings($data): array {
                return ['status' => 'failed', 'message' => 'Bad credentials'];
            }
        };

        $capture = WpDieCapture::capture(fn() => $gateway->updateSettings(['is_active' => 'yes']));

        $this->assertTrue($capture['died']);
        $this->assertSame(422, $capture['status']);
        $this->assertSame('failed', $capture['json']['status'] ?? null);
        $this->assertSame('Bad credentials', $capture['json']['message'] ?? null);
    }

    // =========================================================================
    // Spec 51-52: URL generation
    // =========================================================================

    public function test_get_success_url_uses_payment_helper(): void
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $txn = (new OrderTransactionFactory())->create($order->id, ['uuid' => 'success-url-uuid']);

        $url = $this->cod->getSuccessUrl($txn);
        $this->assertStringContainsString('trx_hash=success-url-uuid', $url);
        $this->assertStringContainsString('method=offline_payment', $url);
    }

    public function test_get_cancel_url_returns_checkout_url(): void
    {
        $url = Cod::getCancelUrl();
        $this->assertIsString($url);
    }

    // =========================================================================
    // Spec 53-54: Filter application
    // =========================================================================

    public function test_get_transaction_url_filter_hook_is_registered(): void
    {
        $this->assertTrue(
            has_filter('fluent_cart/transaction/url_offline_payment', [$this->cod, 'getTransactionUrl']) !== false
        );
    }

    public function test_get_subscription_url_filter_hook_is_registered(): void
    {
        $this->assertTrue(
            has_filter('fluent_cart/subscription/url_offline_payment', [$this->cod, 'getSubscriptionUrl']) !== false
        );
    }

    // =========================================================================
    // Spec 55: Base processRefund returns WP_Error
    // =========================================================================

    public function test_base_process_refund_returns_wp_error(): void
    {
        $gateway = new class(new CodSettingsBase()) extends AbstractPaymentGateway {
            public array $supportedFeatures = ['payment'];
            public function meta(): array { return ['slug' => 'no_refund', 'route' => 'no_refund', 'upcoming' => false]; }
            public function makePaymentFromPaymentInstance($p) { return []; }
            public function handleIPN() {}
            public function getOrderInfo(array $d) {}
            public function fields() { return []; }
        };

        $result = $gateway->processRefund(null, 100, []);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('not_implemented', $result->get_error_code());
    }

    // =========================================================================
    // Spec 58: Gateway not enabled
    // =========================================================================

    public function test_validate_payment_method_rejects_when_gateway_not_enabled(): void
    {
        $this->cod->updateSettings(['is_active' => 'no']);
        $gateway = new Cod();

        $result = $gateway->validatePaymentMethod(['isZeroPayment' => false]);
        $this->assertFalse($result['isValid']);
        $this->assertNotEmpty($result['reason']);
    }

    // =========================================================================
    // Spec 59-62: updateOrderDataByOrder
    // =========================================================================

    public function test_update_order_data_by_order_calls_status_helper_and_dispatches_hook(): void
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id'      => $customer->id,
            'status'           => \FluentCart\App\Helpers\Status::ORDER_ON_HOLD,
            'payment_status'   => \FluentCart\App\Helpers\Status::PAYMENT_PENDING,
            'total_amount'     => 5000,
            'total_paid'       => 0,
            'fulfillment_type' => 'digital',
        ]);
        $txn = (new OrderTransactionFactory())->create($order->id, [
            'status' => \FluentCart\App\Helpers\Status::TRANSACTION_PENDING,
            'total'  => 5000,
        ]);

        $fired = false;
        add_action('fluent_cart/payments/after_payment_paid', function () use (&$fired) {
            $fired = true;
        });

        $this->cod->updateOrderDataByOrder($order, [
            'status' => 'succeeded',
            'total'  => 5000,
        ], $txn);

        remove_all_filters('fluent_cart/payments/after_payment_paid');

        $fresh = \FluentCart\App\Models\Order::query()->find($order->id);
        $this->assertSame(\FluentCart\App\Helpers\Status::PAYMENT_PAID, $fresh->payment_status);
        $this->assertTrue($fired, 'fluent_cart/payments/after_payment_paid should fire');
        $this->assertContains(
            $fresh->status,
            [\FluentCart\App\Helpers\Status::ORDER_PROCESSING, \FluentCart\App\Helpers\Status::ORDER_COMPLETED]
        );
    }

    public function test_update_order_data_by_order_completes_digital_order_when_paid(): void
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id'      => $customer->id,
            'status'           => \FluentCart\App\Helpers\Status::ORDER_ON_HOLD,
            'payment_status'   => \FluentCart\App\Helpers\Status::PAYMENT_PENDING,
            'total_amount'     => 5000,
            'total_paid'       => 0,
            'fulfillment_type' => 'digital',
        ]);
        $txn = (new OrderTransactionFactory())->create($order->id, [
            'status' => \FluentCart\App\Helpers\Status::TRANSACTION_PENDING,
            'total'  => 5000,
        ]);

        $this->cod->updateOrderDataByOrder($order, [
            'status' => 'succeeded',
            'total'  => 5000,
        ], $txn);

        $fresh = \FluentCart\App\Models\Order::query()->find($order->id);
        $this->assertSame(\FluentCart\App\Helpers\Status::ORDER_COMPLETED, $fresh->status);
    }

    // =========================================================================
    // Spec 63-65: paymentFailedNote
    // =========================================================================

    public function test_payment_failed_note_returns_error_when_transaction_missing(): void
    {
        $request = new \FluentCart\Framework\Http\Request\Request(
            \FluentCart\App\App::getInstance(),
            ['trx_hash' => 'nonexistent-hash'],
            []
        );

        $content = $this->cod->paymentFailedNote('', ['request' => $request]);
        $this->assertStringContainsString('Transaction not found', $content);
    }

    // =========================================================================
    // Checkout skeleton: default only for gateways that load a checkout script
    // =========================================================================

    private function scriptGateway(array $scripts): AbstractPaymentGateway
    {
        return new class(new CodSettingsBase(), $scripts) extends AbstractPaymentGateway {
            private array $scripts;
            public array $supportedFeatures = ['payment'];
            public function __construct($settings, array $scripts) { parent::__construct($settings); $this->scripts = $scripts; }
            public function meta(): array { return ['slug' => 'test_gw_skel', 'route' => 'test_gw_skel', 'upcoming' => false]; }
            public function makePaymentFromPaymentInstance($p) { return []; }
            public function handleIPN() {}
            public function getOrderInfo(array $d) {}
            public function fields() { return []; }
            public function getEnqueueScriptSrc($hasSubscription): array { return $this->scripts; }
        };
    }

    public function test_default_skeleton_is_rendered_for_a_gateway_with_a_checkout_script(): void
    {
        $gateway = $this->scriptGateway([['handle' => 'fct-test-skel', 'src' => 'https://example.com/skel.js']]);
        $gateway->beforeRenderPaymentMethod(false);

        $this->assertStringContainsString('fct-payment-skeleton__bar', $gateway->getCheckoutSkeleton());
    }

    public function test_no_skeleton_for_a_gateway_without_a_checkout_script(): void
    {
        $gateway = $this->scriptGateway([]);
        $gateway->beforeRenderPaymentMethod(false);

        $this->assertSame('', $gateway->getCheckoutSkeleton());
    }

    public function test_cod_has_no_skeleton_although_it_loads_a_script(): void
    {
        $this->cod->beforeRenderPaymentMethod(false);

        $this->assertSame('', $this->cod->getCheckoutSkeleton());
    }
}

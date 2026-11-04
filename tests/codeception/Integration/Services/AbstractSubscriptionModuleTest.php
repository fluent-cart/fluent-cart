<?php

namespace Tests\Integration\Services;

use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;

class AbstractSubscriptionModuleTest extends WPTestCase
{
    private AbstractSubscriptionModule $module;

    public function setUp(): void
    {
        parent::setUp();
        $this->module = new class extends AbstractSubscriptionModule {};
    }

    // =========================================================================
    // Spec 66: Default cancel() returns WP_Error
    // =========================================================================

    public function test_default_cancel_returns_wp_error(): void
    {
        $result = $this->module->cancel('vendor_sub_1');
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('not_implemented', $result->get_error_code());
    }

    // =========================================================================
    // Spec 67: reSyncSubscriptionFromRemote returns WP_Error
    // =========================================================================

    public function test_default_resync_subscription_from_remote_returns_wp_error(): void
    {
        $customer = (new CustomerFactory())->create();
        $sub = Subscription::query()->create([
            'customer_id'      => $customer->id,
            'status'           => 'active',
            'item_name'        => 'Sub',
            'recurring_amount' => 1000,
            'billing_interval' => 'monthly',
            'payment_method'   => 'offline_payment',
        ]);

        $result = $this->module->reSyncSubscriptionFromRemote($sub);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('not_implemented', $result->get_error_code());
    }

    // =========================================================================
    // Spec 68-74: throw-with-404 defaults
    // =========================================================================

    public function test_default_fetch_subscription_throws(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionCode(404);
        $this->module->fetchSubscription([], null, null);
    }

    public function test_default_card_update_throws(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionCode(404);
        $this->module->cardUpdate([], 1);
    }

    public function test_default_switch_payment_method_throws(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionCode(404);
        $this->module->switchPaymentMethod([], 1);
    }

    public function test_default_reactivate_subscription_throws(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionCode(404);
        $this->module->reactivateSubscription([], 1);
    }

    public function test_default_pause_subscription_throws(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionCode(404);
        $this->module->pauseSubscription([], null, null);
    }

    public function test_default_resume_subscription_throws(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionCode(404);
        $this->module->resumeSubscription([], null, null);
    }

    public function test_default_cancel_subscription_throws(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionCode(404);
        $this->module->cancelSubscription([], null, null);
    }
}

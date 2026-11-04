<?php

namespace Tests\Integration\Events;

use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Listeners\Order\OrderPaid;
use FluentCart\Framework\Http\Request\Request;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Traits\RequiresEmailVerification;

class AccountCreationAutoLoginTest extends WPTestCase
{
    use RequiresEmailVerification;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireEmailVerification();
    }

    protected function tearDown(): void
    {
        $this->restoreEmailVerificationDefault();
        wp_set_current_user(0);
        StoreSettings::clearCache();
        parent::tearDown();
    }

    public function loginScenarios(): array
    {
        return [
            'default on' => [null, '', false, true],
            'explicit off' => ['no', '', false, false],
            'enabled' => ['yes', '', false, true],
            'invalid value' => ['invalid', '', false, false],
            'webhook' => ['yes', 'payment_ipn', false, false],
            'already logged in' => ['yes', '', true, false],
        ];
    }

    /** @dataProvider loginScenarios */
    public function test_checkout_account_creation_respects_login_setting_and_request_context($setting, string $action, bool $signedIn, bool $expectLogin): void
    {
        $settings = get_option('fluent_cart_store_settings', []);
        unset($settings['auto_login_after_account_creation']);
        if ($setting !== null) {
            $settings['auto_login_after_account_creation'] = $setting;
        }
        update_option('fluent_cart_store_settings', $settings);
        StoreSettings::clearCache();

        $currentUserId = $signedIn ? self::factory()->user->create() : 0;
        wp_set_current_user($currentUserId);
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'config' => ['create_account_after_paid' => 'yes'],
        ]);

        $app = App::getInstance();
        $originalRequest = App::request();
        $request = new Request($app, ['fluent-cart' => $action], []);
        $app->instance('request', $request);
        $app->instance(Request::class, $request);
        $cookieUsers = [];
        $captureCookie = function ($cookie, $expire, $expiration, $userId) use (&$cookieUsers): void {
            $cookieUsers[] = $userId;
        };
        add_action('set_logged_in_cookie', $captureCookie, 10, 4);
        add_filter('pre_wp_mail', '__return_true');

        try {
            OrderPaid::maybeCreateUser($order);

            $createdUserId = (int) email_exists($customer->email);
            $this->assertGreaterThan(0, $createdUserId, 'The setting must not prevent account creation.');
            $this->assertSame($customer->email, get_userdata($createdUserId)->user_email, 'The created account must use the checkout email.');
            $this->assertSame($createdUserId, (int) $customer->fresh()->user_id, 'A first purchase links its own customer: the order just paid is the only one on it.');
            $this->assertFalse(\FluentCart\App\Services\CustomerIdentity\EmailVerificationService::isRequired($createdUserId), 'A first-purchase account does not wait for a confirmation step.');
            $this->assertSame($expectLogin ? $createdUserId : $currentUserId, get_current_user_id(), 'Only an opted-in guest browser request may authenticate the new account.');
            $this->assertSame($expectLogin ? [$createdUserId] : [], $cookieUsers, 'Authentication cookies must follow the setting and existing request guards.');
        } finally {
            remove_action('set_logged_in_cookie', $captureCookie, 10);
            remove_filter('pre_wp_mail', '__return_true');
            $app->instance('request', $originalRequest);
            $app->instance(Request::class, $originalRequest);
        }
    }
}

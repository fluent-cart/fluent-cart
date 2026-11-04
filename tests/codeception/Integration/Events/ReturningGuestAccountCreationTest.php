<?php

namespace Tests\Integration\Events;

use FluentCart\Api\Resource\CustomerResource;
use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Listeners\Order\OrderPaid;
use FluentCart\App\Services\CustomerIdentity\EmailVerificationService;
use FluentCart\Framework\Http\Request\Request;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

class ReturningGuestAccountCreationTest extends WPTestCase
{
    public function accountScenarios(): array
    {
        return [
            'first purchase' => ['no', null, 'all', 'payment', [], true],
            'first purchase with verification' => ['yes', null, 'all', 'payment', [], true],
            'unpaid history' => ['no', 'pending', 'all', 'payment', [], true],
            'paid history and opt-in' => ['no', 'paid', 'user_choice', 'payment', ['create_account_after_paid' => 'yes'], true],
            'subscription with history' => ['no', 'pending', 'user_choice', 'subscription', [], true],
            'verification with history' => ['yes', 'paid', 'all', 'payment', [], true],
            'verified subscription flow' => ['yes', 'pending', 'user_choice', 'subscription', [], true],
            'first purchase without opt-in' => ['no', null, 'user_choice', 'payment', [], false],
        ];
    }

    /** @dataProvider accountScenarios */
    public function test_payment_requires_inbox_proof_before_granting_other_order_ownership(string $verification, ?string $priorStatus, string $mode, string $type, array $config, bool $expectAccount): void
    {
        $originalSettings = get_option('fluent_cart_store_settings', []);
        update_option('fluent_cart_store_settings', array_merge($originalSettings, [
            'require_customer_email_verification' => $verification,
            'user_account_creation_mode' => $mode,
            'auto_login_after_account_creation' => 'yes',
        ]));
        StoreSettings::clearCache();
        wp_set_current_user(0);

        $customer = (new CustomerFactory())->create(['user_id' => 0]);
        if ($priorStatus !== null) {
            (new OrderFactory())->create(['customer_id' => $customer->id, 'payment_status' => $priorStatus]);
        }
        $order = (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'payment_status' => 'paid',
            'type' => $type,
            'config' => $config,
        ]);

        $app = App::getInstance();
        $originalRequest = App::request();
        $request = new Request($app, [], []);
        $app->instance('request', $request);
        $app->instance(Request::class, $request);
        $cookieUsers = [];
        $captureCookie = function ($cookie, $expire, $expiration, $userId) use (&$cookieUsers): void {
            $cookieUsers[] = (int) $userId;
        };
        add_action('set_logged_in_cookie', $captureCookie, 10, 4);
        add_filter('pre_wp_mail', '__return_true');

        try {
            OrderPaid::maybeCreateUser($order);

            // Payment proves this order only. With other orders the buyer is not signed in;
            // the set-password email reaches the inbox owner, who alone can sign in.
            $signsIn = $expectAccount && $priorStatus === null;
            $userId = (int) email_exists($customer->email);
            $this->assertSame($expectAccount, $userId > 0, 'The account follows the account-creation setting.');
            $this->assertSame($expectAccount ? $userId : 0, (int) $customer->fresh()->user_id, 'A new account is linked to its checkout customer.');
            $this->assertSame($signsIn ? $userId : 0, get_current_user_id(), 'Only a first purchase signs the buyer in.');
            $this->assertSame($signsIn ? [$userId] : [], $cookieUsers, 'Other orders on the email must not issue authentication cookies.');
            if ($expectAccount && $verification === 'yes') {
                $this->assertFalse(EmailVerificationService::isRequired($userId), 'A new account does not wait for a confirmation step.');
            }
        } finally {
            remove_action('set_logged_in_cookie', $captureCookie, 10);
            remove_filter('pre_wp_mail', '__return_true');
            $app->instance('request', $originalRequest);
            $app->instance(Request::class, $originalRequest);
            update_option('fluent_cart_store_settings', $originalSettings);
            StoreSettings::clearCache();
            CustomerResource::resetCurrentCustomerRuntimeCache();
            wp_set_current_user(0);
        }
    }
}

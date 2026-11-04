<?php

namespace Tests\Integration\Api;

use FluentCart\Api\StoreSettings;
use FluentCart\Api\User;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\WpDieCapture;

class RegistrationAutoLoginTest extends WPTestCase
{
    protected function tearDown(): void
    {
        wp_set_current_user(0);
        StoreSettings::clearCache();
        parent::tearDown();
    }

    public function loginSettings(): array
    {
        return [
            'default on' => [null, true],
            'explicit off' => ['no', false],
            'enabled' => ['yes', true],
            'invalid value' => ['invalid', false],
        ];
    }

    /** @dataProvider loginSettings */
    public function test_registration_logs_in_unless_explicitly_disabled($setting, bool $autoLogin): void
    {
        wp_set_current_user(0);
        $settings = get_option('fluent_cart_store_settings', []);
        unset($settings['auto_login_after_account_creation']);
        if ($setting !== null) {
            $settings['auto_login_after_account_creation'] = $setting;
        }
        update_option('fluent_cart_store_settings', $settings);
        StoreSettings::clearCache();

        $cookieUsers = [];
        $emails = [];
        $captureCookie = function ($cookie, $expire, $expiration, $userId) use (&$cookieUsers): void {
            $cookieUsers[] = $userId;
        };
        $captureEmail = function ($result, $attributes) use (&$emails) {
            $emails[] = $attributes['to'];
            return true;
        };
        add_action('set_logged_in_cookie', $captureCookie, 10, 4);
        add_filter('pre_wp_mail', $captureEmail, 10, 2);

        try {
            $response = WpDieCapture::capture(function (): void {
                (new User())->validateAndCreate([
                    'email' => 'compliance-registration@example.test',
                    'full_name' => 'Compliance Customer',
                ]);
            });
            $body = $response['json'];

            $userId = (int) email_exists('compliance-registration@example.test');
            $this->assertSame(200, $response['status'], 'Registration must succeed: ' . wp_json_encode($body));
            $this->assertTrue($body['success'], 'Registration must report success with either login setting.');
            $this->assertSame('user_created', $body['data']['code'], 'The registration response code must stay compatible.');
            $this->assertGreaterThan(0, $userId, 'An account must be persisted even when automatic login is disabled.');
            $this->assertContains('compliance-registration@example.test', $emails, 'Account setup email must still be sent.');

            $profileUrl = (new StoreSettings())->getCustomerProfilePage() . '#/profile';
            if ($autoLogin) {
                $this->assertSame($userId, get_current_user_id(), 'Enabled automatic login must authenticate the new user.');
                $this->assertSame([$userId], $cookieUsers, 'Enabled automatic login must issue the authentication cookie.');
                $this->assertSame($profileUrl, $body['data']['redirect_url'], 'Authenticated customers must reach their profile.');
                $this->assertStringContainsString('You are now logged in', $body['data']['message'], 'The message must match the authenticated state.');
            } else {
                $this->assertSame(0, get_current_user_id(), 'Disabled or invalid automatic login must leave the visitor logged out.');
                $this->assertSame([], $cookieUsers, 'Disabled automatic login must issue no authentication cookies.');
                $this->assertSame(wp_login_url($profileUrl), $body['data']['redirect_url'], 'Logged-out customers must be directed to login.');
                $this->assertStringContainsString('Please check your email', $body['data']['message'], 'Logged-out customers must receive account setup instructions.');
            }
        } finally {
            remove_action('set_logged_in_cookie', $captureCookie, 10);
            remove_filter('pre_wp_mail', $captureEmail, 10);
        }
    }
}

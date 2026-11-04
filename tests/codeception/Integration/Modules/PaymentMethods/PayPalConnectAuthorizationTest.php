<?php

namespace Tests\Integration\Modules\PaymentMethods;

use FluentCart\App\App;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\PayPalPartnerRenderer;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\ConnectConfig;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Traits\BuildsPayPalConnectFixture;
use Tests\Support\WpDieCapture;

class PayPalConnectAuthorizationTest extends DatabaseTestCase
{
    use BuildsPayPalConnectFixture;

    public function setUp(): void
    {
        parent::setUp();
        $this->installPayPalConnectFixture();
    }

    public function tearDown(): void
    {
        $this->restorePayPalConnectFixture();
        parent::tearDown();
        fluent_cart_get_option('fluent_cart_payment_settings_paypal', false, false);
    }

    public function test_logged_out_visitor_cannot_change_paypal_connection_settings(): void
    {
        wp_set_current_user(0);
        $before = $this->readPayPalConnectSettings();

        $response = WpDieCapture::capture(function () {
            App::gateway('paypal')->appAuthenticator([
                'mode' => 'live',
                'permissionsGranted' => 'true',
                'merchantId' => 'forged@example.test',
                'accountStatus' => 'FORGED',
            ]);
        });

        $this->assertSame($before, $this->readPayPalConnectSettings(), 'An anonymous callback must leave all saved gateway settings unchanged.');
        $this->assertSame(403, $response['status'], 'An anonymous callback must be forbidden.');
    }

    public function test_administrator_without_nonce_cannot_change_paypal_connection_settings(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $before = $this->readPayPalConnectSettings();

        $response = WpDieCapture::capture(function () {
            App::gateway('paypal')->appAuthenticator([
                'mode' => 'live',
                'permissionsGranted' => 'true',
                'merchantId' => 'forged@example.test',
                'accountStatus' => 'FORGED',
            ]);
        });

        $this->assertSame($before, $this->readPayPalConnectSettings(), 'A callback without CSRF protection must leave all saved gateway settings unchanged.');
        $this->assertSame(403, $response['status'], 'An administrator callback without a nonce must be forbidden.');
    }

    /** @dataProvider rejectedCallbackRequests */
    public function test_invalid_callback_cannot_change_any_saved_setting($role, $mode, $nonceAction, $expectedStatus): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => $role]));
        $before = $this->readPayPalConnectSettings();
        $nonce = wp_create_nonce($nonceAction);

        $response = WpDieCapture::capture(function () use ($mode, $nonce) {
            App::gateway('paypal')->appAuthenticator([
                'mode' => $mode,
                '_wpnonce' => $nonce,
                'permissionsGranted' => 'true',
                'merchantId' => 'forged@example.test',
                'accountStatus' => 'FORGED',
            ]);
        });

        $this->assertSame($before, $this->readPayPalConnectSettings(), 'Rejected callbacks must preserve the entire stored settings record.');
        $this->assertSame($expectedStatus, $response['status'], 'Invalid callback authorization or mode must be rejected.');
        $this->assertSame([], $this->payPalConnectRequests, 'Rejected callbacks must not make external requests.');
    }

    public static function rejectedCallbackRequests(): array
    {
        return [
            'subscriber with own nonce' => ['subscriber', 'live', 'fluent_cart_paypal_connect_return_live', 403],
            'wrong nonce action' => ['administrator', 'live', 'wp_rest', 403],
            'test nonce for live callback' => ['administrator', 'live', 'fluent_cart_paypal_connect_return_test', 403],
            'initiation nonce for callback' => ['administrator', 'live', 'fluent_cart_paypal_connect_live', 403],
            'junk mode with matching nonce' => ['administrator', 'junk', 'fluent_cart_paypal_connect_return_junk', 400],
            'missing mode' => ['administrator', null, 'fluent_cart_paypal_connect_return_live', 400],
            'array mode' => ['administrator', ['live'], 'fluent_cart_paypal_connect_return_live', 400],
        ];
    }

    public function test_callback_nonce_cannot_be_reused_by_another_administrator(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $nonce = wp_create_nonce('fluent_cart_paypal_connect_return_live');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $before = $this->readPayPalConnectSettings();

        $response = WpDieCapture::capture(function () use ($nonce) {
            App::gateway('paypal')->appAuthenticator([
                'mode' => 'live',
                '_wpnonce' => $nonce,
                'permissionsGranted' => 'true',
                'merchantId' => 'forged@example.test',
                'accountStatus' => 'FORGED',
            ]);
        });

        $this->assertSame(403, $response['status'], 'A return nonce must belong to the current administrator.');
        $this->assertSame($before, $this->readPayPalConnectSettings(), 'A callback from another administrator must preserve saved settings.');
    }

    /** @dataProvider rejectedOnboardingRequests */
    public function test_unauthorized_onboarding_stops_before_contacting_paypal($role, $mode, $nonceAction, $expectedStatus): void
    {
        $userId = $role ? self::factory()->user->create(['role' => $role]) : 0;
        wp_set_current_user($userId);
        $before = $this->readPayPalConnectSettings();
        $nonce = $nonceAction ? wp_create_nonce($nonceAction) : '';

        $response = WpDieCapture::capture(function () use ($mode, $nonce) {
            (new PayPalPartnerRenderer($mode))->template(['mode' => $mode, '_wpnonce' => $nonce]);
        });

        $this->assertSame($expectedStatus, $response['status'], 'Unauthorized onboarding must return an error.');
        $this->assertSame([], $this->payPalConnectRequests, 'Unauthorized onboarding must not create a remote partner referral.');
        $this->assertSame($before, $this->readPayPalConnectSettings(), 'Unauthorized onboarding must preserve gateway settings.');
    }

    public static function rejectedOnboardingRequests(): array
    {
        return [
            'logged out with guest nonce' => [null, 'live', 'fluent_cart_paypal_connect_live', 403],
            'subscriber with own nonce' => ['subscriber', 'test', 'fluent_cart_paypal_connect_test', 403],
            'administrator without nonce' => ['administrator', 'live', null, 403],
            'callback nonce for initiation' => ['administrator', 'live', 'fluent_cart_paypal_connect_return_live', 403],
            'test nonce for live initiation' => ['administrator', 'live', 'fluent_cart_paypal_connect_test', 403],
            'junk mode with matching nonce' => ['administrator', 'junk', 'fluent_cart_paypal_connect_junk', 400],
        ];
    }

    /** @dataProvider connectionModes */
    public function test_authorized_onboarding_return_updates_only_the_selected_modes_metadata(string $mode): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option('fluent_cart_store_settings', ['store_country' => 'US', 'currency' => 'USD']);
        StoreSettings::clearCache();
        $expected = $this->readPayPalConnectSettings();

        $config = ConnectConfig::getConnectConfig();
        $startUrl = $config['connect_config'][$mode . '_redirect'];
        parse_str(wp_parse_url($startUrl, PHP_URL_QUERY), $startData);
        $this->assertSame('paypal_connect', $startData['fluent-cart'], 'Connect links must use the gateway action route.');
        $this->assertSame('connect', $startData['intent'], 'Connect links must select the initiation handler.');
        $response = WpDieCapture::capture(function () use ($startData) {
            do_action('fluent_cart_action_' . $startData['fluent-cart'], $startData);
        });

        $this->assertFalse($response['died'], 'The generated Connect PayPal link must pass initiation checks.');
        $this->assertCount(1, $this->payPalConnectRequests, 'Authorized onboarding must create one partner referral.');
        $payload = json_decode($this->payPalConnectRequests[0]['args']['body'], true);
        $returnUrl = $payload['partner_config_override']['return_url'];
        $this->assertStringNotContainsString('&amp;', $returnUrl, 'PayPal must receive a raw URL, not an HTML-escaped nonce URL.');
        parse_str(wp_parse_url($returnUrl, PHP_URL_QUERY), $returnData);
        $this->assertSame($mode, $returnData['mode'], 'The return URL must retain the selected mode.');
        $this->assertSame('paypal_connect', $returnData['fluent-cart'], 'PayPal must return through the gateway action route.');
        $this->assertSame('return', $returnData['intent'], 'The return URL must select the metadata callback.');
        $this->assertArrayNotHasKey('fct_app_authenticator', $returnData, 'New return URLs must not invoke the earlier legacy dispatcher as well.');
        $returnData['permissionsGranted'] = 'true';
        $returnData['merchantId'] = 'connected@example.test';
        $returnData['accountStatus'] = 'BUSINESS_ACCOUNT';

        $callback = WpDieCapture::capture(function () use ($returnData) {
            do_action('fluent_cart_action_' . $returnData['fluent-cart'], $returnData);
        });

        $expected[$mode . '_email_address'] = 'connected@example.test';
        $expected[$mode . '_account_status'] = 'BUSINESS_ACCOUNT';
        $this->assertFalse($callback['died'], 'A return URL issued for the same administrator and mode must succeed.');
        $this->assertSame($expected, $this->readPayPalConnectSettings(), 'Only the selected mode metadata may change; credentials and other settings must be preserved.');
        $this->assertSame([admin_url('admin.php?page=fluent-cart#/settings/payments/paypal')], $this->payPalConnectRedirects, 'A successful callback must return to the gateway settings page.');
    }

    public static function connectionModes(): array
    {
        return [['live'], ['test']];
    }

    /** @dataProvider rejectedActionRequests */
    public function test_action_route_rejects_invalid_requests_before_any_side_effect($role, $intent, $mode, $nonceAction, $expectedStatus): void
    {
        wp_set_current_user($role ? self::factory()->user->create(['role' => $role]) : 0);
        $before = $this->readPayPalConnectSettings();
        $data = [
            'intent' => $intent,
            'mode' => $mode,
            '_wpnonce' => $nonceAction ? wp_create_nonce($nonceAction) : '',
            'permissionsGranted' => 'true',
            'merchantId' => 'forged@example.test',
            'accountStatus' => 'FORGED',
        ];

        $response = WpDieCapture::capture(function () use ($data) {
            do_action('fluent_cart_action_paypal_connect', $data);
        });

        $this->assertSame($expectedStatus, $response['status'], 'The registered action must reject invalid connection requests.');
        $this->assertSame($before, $this->readPayPalConnectSettings(), 'Rejected action requests must preserve all saved settings.');
        $this->assertSame([], $this->payPalConnectRequests, 'Rejected action requests must not contact PayPal.');
        $this->assertSame([], $this->payPalConnectRedirects, 'Rejected action requests must not redirect as though connection succeeded.');
    }

    public static function rejectedActionRequests(): array
    {
        return [
            'guest initiation' => [null, 'connect', 'live', null, 403],
            'guest return' => [null, 'return', 'live', null, 403],
            'subscriber initiation' => ['subscriber', 'connect', 'test', 'fluent_cart_paypal_connect_test', 403],
            'subscriber return' => ['subscriber', 'return', 'test', 'fluent_cart_paypal_connect_return_test', 403],
            'initiation without nonce' => ['administrator', 'connect', 'live', null, 403],
            'return without nonce' => ['administrator', 'return', 'live', null, 403],
            'return nonce for initiation' => ['administrator', 'connect', 'live', 'fluent_cart_paypal_connect_return_live', 403],
            'initiation nonce for return' => ['administrator', 'return', 'live', 'fluent_cart_paypal_connect_live', 403],
            'wrong initiation mode nonce' => ['administrator', 'connect', 'live', 'fluent_cart_paypal_connect_test', 403],
            'wrong return mode nonce' => ['administrator', 'return', 'live', 'fluent_cart_paypal_connect_return_test', 403],
            'invalid initiation mode' => ['administrator', 'connect', 'junk', 'fluent_cart_paypal_connect_junk', 400],
            'array return mode' => ['administrator', 'return', ['live'], 'fluent_cart_paypal_connect_return_live', 400],
            'missing initiation mode' => ['administrator', 'connect', null, 'fluent_cart_paypal_connect_live', 400],
            'missing return mode' => ['administrator', 'return', null, 'fluent_cart_paypal_connect_return_live', 400],
            'unknown intent' => ['administrator', 'disconnect', 'live', 'fluent_cart_paypal_connect_live', 400],
            'missing intent' => ['administrator', null, 'live', 'fluent_cart_paypal_connect_live', 400],
            'array intent' => ['administrator', ['connect'], 'live', 'fluent_cart_paypal_connect_live', 400],
        ];
    }

    /** @dataProvider connectionModes */
    public function test_legacy_connection_handlers_still_accept_authorized_requests(string $mode): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option('fluent_cart_store_settings', ['store_country' => 'US', 'currency' => 'USD']);
        StoreSettings::clearCache();
        $expected = $this->readPayPalConnectSettings();

        $start = WpDieCapture::capture(function () use ($mode) {
            (new PayPalPartnerRenderer($mode))->template([
                'fluent-cart' => 'fluent_cart_payment_authenticate',
                'payment_method' => 'paypal',
                'type' => 'connect',
                'mode' => $mode,
                '_wpnonce' => wp_create_nonce('fluent_cart_paypal_connect_' . $mode),
            ]);
        });

        $this->assertFalse($start['died'], 'The legacy initiation handler must still accept authorized requests.');
        $this->assertCount(1, $this->payPalConnectRequests, 'The legacy initiation handler must still create one partner referral.');

        $callback = WpDieCapture::capture(function () use ($mode) {
            App::gateway('paypal')->appAuthenticator([
                'fct_app_authenticator' => '',
                'method' => 'paypal',
                'mode' => $mode,
                '_wpnonce' => wp_create_nonce('fluent_cart_paypal_connect_return_' . $mode),
                'permissionsGranted' => 'true',
                'merchantId' => 'legacy@example.test',
                'accountStatus' => 'BUSINESS_ACCOUNT',
            ]);
        });

        $expected[$mode . '_email_address'] = 'legacy@example.test';
        $expected[$mode . '_account_status'] = 'BUSINESS_ACCOUNT';
        $this->assertFalse($callback['died'], 'The legacy return handler must still accept authorized requests.');
        $this->assertSame($expected, $this->readPayPalConnectSettings(), 'Legacy returns may update only the selected mode metadata.');
        $this->assertSame([admin_url('admin.php?page=fluent-cart#/settings/payments/paypal')], $this->payPalConnectRedirects, 'Legacy returns must still redirect to the gateway settings.');
    }
}

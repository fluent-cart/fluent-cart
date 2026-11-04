<?php

namespace Tests\Integration\Modules\Turnstile;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Models\Activity;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Traits\MocksTurnstileSiteverify;

/**
 * Turnstile outcomes at checkout: a rejected token, a missing token and an
 * unreachable Cloudflare each get their own error code, and only the last is retried.
 */
class TurnstileValidatorTest extends DatabaseTestCase
{
    use MocksTurnstileSiteverify;

    private $originalSettings;

    public function setUp(): void
    {
        parent::setUp();

        $this->originalSettings = get_option(ModuleSettings::MODULE_SETTINGS_OPTION, null);
        $settings = is_array($this->originalSettings) ? $this->originalSettings : [];
        $settings['turnstile'] = [
            'active'     => 'yes',
            'site_key'   => 'site-key',
            'secret_key' => 'secret-key',
        ];
        update_option(ModuleSettings::MODULE_SETTINGS_OPTION, $settings);
        ModuleSettings::getAllSettings(false);

        $this->installTurnstileHttpMock();
    }

    public function tearDown(): void
    {
        $this->removeTurnstileHttpMock();
        remove_all_filters('fluent_cart/turnstile/allow_when_unreachable');
        Activity::query()->where('title', 'Turnstile token rejected')->delete();
        foreach (['timeout-or-duplicate', 'invalid-input-secret', 'invalid-input-response'] as $code) {
            delete_transient('fluent_cart_turnstile_rejected_' . $code);
        }

        if ($this->originalSettings === null) {
            delete_option(ModuleSettings::MODULE_SETTINGS_OPTION);
        } else {
            update_option(ModuleSettings::MODULE_SETTINGS_OPTION, $this->originalSettings);
        }
        ModuleSettings::getAllSettings(false);

        parent::tearDown();
    }

    public function test_a_verified_token_passes(): void
    {
        $this->turnstileReplies = [$this->turnstileReply(200, ['success' => true])];

        $this->assertTrue($this->validateTurnstileCheckout());
        $this->assertCount(1, $this->turnstileSent);
        $this->assertSame('secret-key', $this->turnstileSent[0]['secret'] ?? null, 'siteverify needs the configured secret');
        $this->assertSame('token-1', $this->turnstileSent[0]['response'] ?? null, 'siteverify needs the checkout token');
    }

    public function test_a_missing_token_is_refused_as_missing(): void
    {
        $result = $this->validateTurnstileCheckout('');

        $this->assertWPError($result);
        $this->assertSame('turnstile_missing', $result->get_error_code());
        $this->assertCount(0, $this->turnstileSent, 'no token means nothing to verify');
    }

    public function test_a_rejected_token_is_refused_as_invalid_without_a_retry(): void
    {
        $this->turnstileReplies = [$this->turnstileReply(200, ['success' => false, 'error-codes' => ['timeout-or-duplicate']])];

        $result = $this->validateTurnstileCheckout();

        $this->assertWPError($result);
        $this->assertSame('turnstile_invalid', $result->get_error_code());
        $this->assertCount(1, $this->turnstileSent, 'a token Cloudflare answered for must not be re-sent');
    }

    public function test_an_unreachable_cloudflare_is_refused_as_unreachable(): void
    {
        $this->turnstileReplies = [
            new \WP_Error('http_request_failed', 'cURL error 28: timed out'),
            new \WP_Error('http_request_failed', 'cURL error 28: timed out'),
        ];

        $result = $this->validateTurnstileCheckout();

        $this->assertWPError($result);
        $this->assertSame('turnstile_unreachable', $result->get_error_code());
    }

    public function test_a_transport_failure_is_retried_once_with_the_same_idempotency_key(): void
    {
        $this->turnstileReplies = [
            $this->turnstileReply(503, '<html>Service Unavailable</html>'),
            $this->turnstileReply(200, ['success' => true]),
        ];

        $this->assertTrue($this->validateTurnstileCheckout());
        $this->assertCount(2, $this->turnstileSent);
        $this->assertNotEmpty($this->turnstileSent[0]['idempotency_key'] ?? '');
        $this->assertSame($this->turnstileSent[0]['idempotency_key'], $this->turnstileSent[1]['idempotency_key']);
        foreach ($this->turnstileSent as $i => $sent) {
            $this->assertSame('secret-key', $sent['secret'] ?? null, "request {$i} must carry the configured secret");
            $this->assertSame('token-1', $sent['response'] ?? null, "request {$i} must carry the checkout token");
        }
    }

    public function test_a_store_can_choose_to_allow_checkout_when_cloudflare_is_unreachable(): void
    {
        $this->turnstileReplies = [
            new \WP_Error('http_request_failed', 'timed out'),
            new \WP_Error('http_request_failed', 'timed out'),
        ];
        add_filter('fluent_cart/turnstile/allow_when_unreachable', '__return_true');

        $this->assertTrue($this->validateTurnstileCheckout());
    }

    public function test_allowing_unreachable_never_lets_a_rejected_token_through(): void
    {
        $this->turnstileReplies = [$this->turnstileReply(200, ['success' => false, 'error-codes' => ['invalid-input-response']])];
        add_filter('fluent_cart/turnstile/allow_when_unreachable', '__return_true');

        $result = $this->validateTurnstileCheckout();

        $this->assertWPError($result);
        $this->assertSame('turnstile_invalid', $result->get_error_code());
    }

    public function test_rejection_reasons_are_logged_once_per_reason_outside_dev_mode(): void
    {
        $this->turnstileReplies = [
            $this->turnstileReply(200, ['success' => false, 'error-codes' => ['timeout-or-duplicate']]),
            $this->turnstileReply(200, ['success' => false, 'error-codes' => ['timeout-or-duplicate']]),
            $this->turnstileReply(200, ['success' => false, 'error-codes' => ['invalid-input-secret']]),
        ];

        $this->validateTurnstileCheckout('token-1');
        $this->validateTurnstileCheckout('token-2');
        $this->validateTurnstileCheckout('token-3');

        $logged = Activity::query()->where('title', 'Turnstile token rejected')->pluck('content')->all();
        $this->assertCount(2, $logged, 'one entry per reason, not one per refused checkout');
        $this->assertStringContainsString('invalid-input-secret', implode(' ', $logged));
    }

    public function test_turnstile_switched_on_without_both_keys_does_not_block_checkout(): void
    {
        foreach (['site_key', 'secret_key'] as $missingKey) {
            $settings = get_option(ModuleSettings::MODULE_SETTINGS_OPTION);
            $settings['turnstile'] = ['active' => 'yes', 'site_key' => 'site-key', 'secret_key' => 'secret-key'];
            $settings['turnstile'][$missingKey] = '';
            update_option(ModuleSettings::MODULE_SETTINGS_OPTION, $settings);
            ModuleSettings::getAllSettings(false);

            // No widget can issue or verify a token, so requiring one refuses every order.
            $this->assertTrue($this->validateTurnstileCheckout(''), $missingKey . ' missing');
        }
        $this->assertCount(0, $this->turnstileSent);
    }
}

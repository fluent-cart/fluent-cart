<?php

namespace Tests\Integration\Services;

use FluentCart\App\Models\Customer;
use FluentCart\App\Services\CustomerIdentity\EmailClaimPortal;
use FluentCart\App\Services\CustomerIdentity\EmailVerificationService;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\WPUserFactory;
use Tests\Support\Traits\BuildsEmailClaims;
use Tests\Support\Traits\RequiresEmailVerification;

class CustomerPasswordResetVerificationTest extends WPTestCase
{
    use BuildsEmailClaims;
    use RequiresEmailVerification;

    private array $originalCookies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireEmailVerification();
        $this->originalCookies = $_COOKIE;
        $_POST = [];
        $_GET = [];
        wp_set_current_user(0);
        $this->configurePortalPage();
        $this->captureMail();
    }

    protected function tearDown(): void
    {
        $this->releaseMail();
        $_COOKIE = $this->originalCookies;
        $_POST = [];
        $_GET = [];
        wp_set_current_user(0);
        $this->restoreEmailVerificationDefault();
        parent::tearDown();
    }

    public function test_setting_a_password_from_the_email_link_verifies_and_links_the_existing_customer(): void
    {
        $guest = (new CustomerFactory())->create(['email' => 'password-proof@example.test']);
        $order = (new OrderFactory())->create(['customer_id' => $guest->id]);
        $userId = WPUserFactory::create(['user_email' => $guest->email], false);
        $user = get_userdata($userId);
        $key = get_password_reset_key($user);
        $this->assertTrue(EmailVerificationService::isRequired($userId), 'Generating a link is not inbox proof');
        $_COOKIE['wp-resetpass-' . COOKIEHASH] = $user->user_login . ':' . $key;
        $_POST['rp_key'] = $key;
        reset_password($user, 'New-password-from-inbox-42!');
        $this->assertFalse(EmailVerificationService::isRequired($userId), 'A completed emailed password setup verifies the inbox');
        $this->assertSame($userId, (int) $guest->fresh()->user_id, 'Password setup links the existing guest customer');
        $this->assertSame((int) $guest->id, (int) $order->fresh()->customer_id, 'The purchase keeps its customer ID');
        $this->assertSame(1, Customer::query()->where('email', $guest->email)->count(), 'Password setup must not create a duplicate');
        wp_set_current_user($userId);
        $this->assertSame('', EmailClaimPortal::render(), 'The dashboard must not require another confirmation');
        foreach ($this->capturedMail as $mail) {
            $this->assertStringNotContainsString('fct_email_claim=', $mail['message'], 'WordPress password-change notices must not include another confirmation request');
        }
    }

    public function test_manual_password_changes_and_ordinary_login_do_not_verify_email(): void
    {
        $userId = WPUserFactory::create([], false);
        wp_set_password('Manual-password-42!', $userId);
        reset_password(get_userdata($userId), 'Another-manual-password-42!');
        wp_set_current_user($userId);
        $this->assertTrue(EmailVerificationService::isRequired($userId), 'Changing credentials without the emailed key is not verification');
    }

    /** @dataProvider invalidProofs */
    public function test_invalid_password_reset_proof_does_not_verify(string $case): void
    {
        $userId = WPUserFactory::create([], false);
        $user = get_userdata($userId);
        $key = get_password_reset_key($user);
        $_COOKIE['wp-resetpass-' . COOKIEHASH] = $user->user_login . ':' . $key;
        $_POST['rp_key'] = $key;
        if ($case === 'mismatch') {
            $_POST['rp_key'] = 'different-key';
        } elseif ($case === 'forged') {
            $_POST['rp_key'] = 'forged-key';
            $_COOKIE['wp-resetpass-' . COOKIEHASH] = $user->user_login . ':forged-key';
        } elseif ($case === 'other_account') {
            $other = get_userdata(WPUserFactory::create([], false));
            $otherKey = get_password_reset_key($other);
            $_COOKIE['wp-resetpass-' . COOKIEHASH] = $other->user_login . ':' . $otherKey;
            $_POST['rp_key'] = $otherKey;
        } elseif ($case === 'email_changed') {
            wp_update_user(['ID' => $userId, 'user_email' => 'changed-reset@example.test']);
        } else {
            add_filter('password_reset_expiration', '__return_zero');
        }
        try {
            reset_password($user, 'Manual-call-with-invalid-proof-42!');
        } finally {
            remove_filter('password_reset_expiration', '__return_zero');
        }
        $this->assertTrue(EmailVerificationService::isRequired($userId), 'Invalid or stale proof must leave verification required');
        $this->assertFalse(Customer::query()->where('user_id', $userId)->exists(), 'Invalid proof must not link or create a customer');
    }

    public function invalidProofs(): array
    {
        return [['mismatch'], ['forged'], ['other_account'], ['email_changed'], ['expired']];
    }

    public function test_password_reset_cannot_take_a_customer_owned_by_another_account(): void
    {
        $userId = WPUserFactory::create([], false);
        $user = get_userdata($userId);
        $otherId = WPUserFactory::create();
        $owned = (new CustomerFactory())->create(['email' => $user->user_email, 'user_id' => $otherId]);
        $key = get_password_reset_key($user);
        $_COOKIE['wp-resetpass-' . COOKIEHASH] = $user->user_login . ':' . $key;
        $_POST['rp_key'] = $key;
        reset_password($user, 'Valid-reset-conflicting-customer-42!');
        $this->assertSame($otherId, (int) $owned->fresh()->user_id, 'Inbox proof must not overwrite another account link');
        $this->assertTrue(EmailVerificationService::isRequired($userId), 'An ownership conflict must not unlock the dashboard');
    }
}

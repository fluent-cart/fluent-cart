<?php

namespace Tests\Integration\Services;

use FluentCart\Api\Resource\CustomerResource;
use FluentCart\App\Hooks\Handlers\ShortCodes\CustomerProfileHandler;
use FluentCart\App\Listeners\Order\OrderPaid;
use FluentCart\App\Models\Customer;
use FluentCart\App\Services\CustomerIdentity\EmailClaimPortal;
use FluentCart\App\Services\CustomerIdentity\EmailClaimService;
use FluentCart\App\Services\CustomerIdentity\EmailVerificationService;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\WPUserFactory;
use Tests\Support\Traits\BuildsEmailClaims;
use Tests\Support\Traits\RequiresEmailVerification;

class CustomerEmailVerificationTest extends WPTestCase
{
    use BuildsEmailClaims;
    use RequiresEmailVerification;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireEmailVerification();
        $this->configurePortalPage();
        $this->captureMail();
        CustomerResource::resetCurrentCustomerRuntimeCache();
    }

    protected function tearDown(): void
    {
        $this->releaseMail();
        wp_set_current_user(0);
        $_GET = $_POST = [];
        CustomerResource::resetCurrentCustomerRuntimeCache();
        $this->restoreEmailVerificationDefault();
        parent::tearDown();
    }

    public function test_registration_does_not_adopt_guest_purchases_and_requires_proof(): void
    {
        $guest = (new CustomerFactory())->create(['email' => 'unverified@example.com']);
        $order = (new OrderFactory())->create(['customer_id' => $guest->id]);
        $userId = WPUserFactory::create(['user_email' => $guest->email], false);
        wp_set_current_user($userId);

        $this->assertTrue(EmailVerificationService::isRequired($userId), 'A logged-in new account has not proved its inbox.');
        $this->assertEmpty($guest->fresh()->user_id, 'Registration must not adopt guest history.');
        $this->assertNull(CustomerResource::getCurrentCustomer(), 'Email equality must not resolve a guest customer.');
        $this->assertSame('unverified', EmailClaimService::getOffer()['reason'], 'The portal must offer proof for a new account.');

        $token = $this->issueClaimAndGetToken();
        $this->assertNotEmpty($token, 'The verification link must be delivered.');
        $this->assertSame('confirmed', EmailClaimService::confirm($token), 'Inbox proof must unlock recovery.');
        $owned = CustomerResource::getCurrentCustomer();
        $this->assertSame($userId, (int) $owned->user_id, 'Recovered purchases must belong to the requesting account.');
        $this->assertSame((int) $guest->id, (int) $owned->id, 'Verification must reuse the guest customer ID');
        $this->assertSame(1, Customer::query()->where('email', $guest->email)->count(), 'Verification must not create another customer');
        $this->assertSame((int) $owned->id, (int) $order->fresh()->customer_id, 'Only confirmation may move the guest order.');
        $this->assertFalse(EmailVerificationService::isRequired($userId), 'Confirmation must persist verified state.');
    }

    public function test_matching_customer_email_still_needs_proof_for_a_new_account(): void
    {
        $userId = WPUserFactory::create([], false);
        $customer = (new CustomerFactory())->createForUser($userId);
        wp_set_current_user($userId);

        ob_start();
        (new CustomerProfileHandler())->renderCustomerAppContainer();
        $html = ob_get_clean();
        $this->assertStringContainsString('Send confirmation email', $html, 'The customer must see a usable verification form.');
        $this->assertStringNotContainsString('data-fluent-cart-customer-profile-app', $html, 'The unverified account must not load the purchase dashboard.');
        $this->assertStringContainsString(esc_html($customer->email), $html, 'The form must identify the destination inbox.');

        $token = $this->issueClaimAndGetToken();
        $_GET[EmailClaimService::QUERY_TOKEN] = $token;
        $this->assertStringContainsString('Confirm email address', EmailClaimPortal::render(), 'Opening a link must render confirmation.');
        $this->assertTrue(EmailVerificationService::isRequired($userId), 'A GET or mail scanner must not verify the account.');
        $_POST = [EmailClaimPortal::ACTION_FIELD => 'confirm', EmailClaimService::QUERY_TOKEN => $token, '_wpnonce' => wp_create_nonce(EmailClaimPortal::NONCE_ACTION)];
        $this->assertStringContainsString('fct_email_claim_status=confirmed', EmailClaimPortal::handleSubmission(), 'The nonce-protected form must confirm the account.');
        $this->assertFalse(EmailVerificationService::isRequired($userId), 'Explicit confirmation must grant portal access.');
    }

    public function test_email_changes_preserve_contact_and_invalidate_links_even_when_changed_back(): void
    {
        $customer = (new CustomerFactory())->createWithUser();
        $userId = (int) $customer->user_id;
        wp_set_current_user($userId);
        $newEmail = 'changed-inbox@example.com';
        wp_update_user(['ID' => $userId, 'user_email' => $newEmail]);

        $this->assertSame($customer->email, $customer->fresh()->email, 'An account email edit must not update the customer contact.');
        $this->assertTrue(EmailVerificationService::isRequired($userId), 'An email change must require fresh proof.');
        $token = $this->issueClaimAndGetToken();
        wp_update_user(['ID' => $userId, 'user_email' => 'temporary@example.com']);
        wp_update_user(['ID' => $userId, 'user_email' => $newEmail]);
        $this->assertSame('stale', EmailClaimService::confirm($token), 'Changing away and back must not revive a link.');
        $this->assertSame($customer->email, $customer->fresh()->email, 'A stale link must leave the old contact intact.');
        $this->assertSame('confirmed', EmailClaimService::confirm($this->issueClaimAndGetToken()), 'The current inbox can be proved with a new link.');
        $this->assertSame($newEmail, $customer->fresh()->email, 'Only confirmation must update the customer email.');
    }

    public function test_name_only_updates_do_not_invalidate_verification(): void
    {
        $customer = (new CustomerFactory())->createWithUser();
        wp_update_user(['ID' => $customer->user_id, 'first_name' => 'Changed']);
        $this->assertFalse(EmailVerificationService::isRequired((int) $customer->user_id), 'Name changes must not require another email confirmation.');
    }

    public function test_checkout_on_an_email_with_old_guest_history_never_signs_the_buyer_in(): void
    {
        wp_set_current_user(0);
        $guest = (new CustomerFactory())->create(['email' => 'original-guest@example.com']);
        $olderOrder = (new OrderFactory())->create(['customer_id' => $guest->id]);
        $checkoutOrder = (new OrderFactory())->create(['customer_id' => $guest->id, 'config' => ['create_account_after_paid' => 'yes']]);
        OrderPaid::maybeCreateUser($checkoutOrder);

        $userId = (int) email_exists($guest->email);
        $this->assertGreaterThan(0, $userId, 'Checkout creates the account; its password is set from that inbox.');
        $this->assertSame(0, get_current_user_id(), 'Payment proves this order only, so the buyer is not signed in.');
        $this->assertSame($userId, (int) $guest->fresh()->user_id, 'The history belongs to the account only the inbox owner can open.');
        $this->assertSame((int) $guest->id, (int) $olderOrder->fresh()->customer_id, 'The older order keeps its customer.');
    }

    public function test_merge_failure_rolls_back_ownership_contact_and_verification(): void
    {
        $userId = WPUserFactory::create(['user_email' => 'rollback@example.com'], false);
        $guest = (new CustomerFactory())->create(['email' => 'rollback@example.com']);
        (new CustomerFactory())->create(['email' => 'rollback@example.com']); // Legacy duplicate exercises the transfer rollback.
        $order = (new OrderFactory())->create(['customer_id' => $guest->id]);
        wp_set_current_user($userId);
        $token = $this->issueClaimAndGetToken();
        $fail = function (): void { throw new \RuntimeException('Extension transfer failed'); };
        add_action('fluent_cart/customer_resources_moved', $fail);
        try {
            $this->assertSame('failed', EmailClaimService::confirm($token), 'A failed extension transfer must not report confirmation.');
        } finally {
            remove_action('fluent_cart/customer_resources_moved', $fail);
        }
        $this->assertSame((int) $guest->id, (int) $order->fresh()->customer_id, 'Failed transfers must roll back the order owner.');
        $this->assertNotNull($guest->fresh(), 'The guest record must survive a failed transfer.');
        $this->assertFalse(Customer::query()->where('user_id', $userId)->exists(), 'A failed transfer must roll back the new customer.');
        $this->assertTrue(EmailVerificationService::isRequired($userId), 'A failed transfer must not leave cached verified state.');
        $this->assertSame('confirmed', EmailClaimService::confirm($token), 'A rolled-back claim must remain retryable.');
    }

    public function test_send_limits_do_not_verify_an_account_and_mail_failure_invalidates_the_link(): void
    {
        $userId = WPUserFactory::create([], false);
        wp_set_current_user($userId);
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame('sent', EmailClaimService::issue(), 'Requests within the account limit must be delivered.');
        }
        $this->assertSame('throttled', EmailClaimService::issue(), 'Repeated email requests must be bounded.');
        $this->assertCount(5, $this->capturedMail, 'A throttled request must not send mail.');
        $this->assertTrue(EmailVerificationService::isRequired($userId), 'Sending mail is not proof of reading it.');

        $otherId = WPUserFactory::create([], false);
        wp_set_current_user($otherId);
        $this->releaseMail();
        add_filter('pre_wp_mail', '__return_false');
        $this->assertSame('send_failed', EmailClaimService::issue(), 'Mail failures must be reported.');
        $this->assertEmpty(get_user_meta($otherId, EmailClaimService::META_KEY, true), 'A failed send must not retain a usable link.');
        $this->assertTrue(EmailVerificationService::isRequired($otherId), 'A failed send must never verify the inbox.');
    }

    public function test_an_account_without_verification_state_keeps_access_until_its_email_changes(): void
    {
        $customer = (new CustomerFactory())->createWithUser();
        $userId = (int) $customer->user_id;
        delete_user_meta($userId, EmailVerificationService::UNVERIFIED_META_KEY);
        wp_set_current_user($userId);
        $this->assertFalse(EmailVerificationService::isRequired($userId), 'An account predating verification must keep its access.');
        $this->assertSame((int) $customer->id, (int) CustomerResource::getCurrentCustomer()->id, 'The existing link must still resolve.');
        $this->assertNull(EmailClaimService::getOffer(), 'A matching legacy account has nothing to confirm.');

        wp_update_user(['ID' => $userId, 'user_email' => 'legacy-moved@example.com']);
        $this->assertTrue(EmailVerificationService::isRequired($userId), 'A later email change must still require proof.');
    }
}

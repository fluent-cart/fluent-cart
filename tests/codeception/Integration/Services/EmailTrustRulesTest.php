<?php

namespace Tests\Integration\Services;

use FluentCart\Api\Resource\CustomerResource;
use FluentCart\App\Hooks\Handlers\ShortCodes\CustomerProfileHandler;
use FluentCart\App\Services\CustomerIdentity\EmailClaimService;
use FluentCart\App\Services\CustomerIdentity\EmailVerificationService;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\WPUserFactory;
use Tests\Support\Traits\BuildsEmailClaims;
use Tests\Support\Traits\RequiresEmailVerification;

/**
 * An account email is trusted unless the holder changed it with no inbox check, or the
 * linked customer's contact email drifted from it. Only that case is recorded.
 */
class EmailTrustRulesTest extends WPTestCase
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
        CustomerResource::resetCurrentCustomerRuntimeCache();
        $this->restoreEmailVerificationDefault();
        parent::tearDown();
    }

    public function test_an_account_without_a_customer_opens_the_portal(): void
    {
        $userId = WPUserFactory::create(['user_email' => 'trusted-empty@example.com']);
        wp_set_current_user($userId);

        $this->assertFalse(EmailVerificationService::isRequired($userId), 'A trusted account has nothing to verify.');
        $this->assertNull(EmailClaimService::getOffer(), 'No confirmation may be offered when nothing can be claimed.');
        ob_start();
        (new CustomerProfileHandler())->renderCustomerAppContainer();
        $html = ob_get_clean();
        $this->assertStringContainsString('data-fluent-cart-customer-profile-app', $html, 'The dashboard must load.');
    }

    public function test_a_drifted_contact_email_is_gated_until_confirmed(): void
    {
        $userId = WPUserFactory::create(['user_email' => 'drift-account@example.com']);
        $customer = (new CustomerFactory())->createForUser($userId, ['email' => 'drift-contact@example.com']);
        wp_set_current_user($userId);

        $this->assertTrue(EmailVerificationService::isRequired($userId), 'The account email does not match its contact email.');
        $this->assertSame('diverged', EmailClaimService::getOffer()['reason'], 'The mismatch must be offered for confirmation.');

        $this->assertSame('confirmed', EmailClaimService::confirm($this->issueClaimAndGetToken()), 'The account confirms its inbox.');
        $this->assertSame('drift-account@example.com', $customer->fresh()->email, 'Confirmation must align the contact email.');
        $this->assertFalse(EmailVerificationService::isRequired($userId), 'The portal opens after proof.');
    }

    public function test_a_trusted_account_links_its_guest_orders_on_the_first_visit(): void
    {
        $userId = WPUserFactory::create(['user_email' => 'trusted-guest@example.com']);
        $guest = (new CustomerFactory())->create(['email' => 'trusted-guest@example.com']);
        $order = (new OrderFactory())->create(['customer_id' => $guest->id]);
        wp_set_current_user($userId);

        $this->assertFalse(EmailVerificationService::isRequired($userId), 'The portal is open.');
        $this->assertSame((int) $guest->id, (int) CustomerResource::getCurrentCustomer()->id, 'The guest customer is resolved.');
        $this->assertSame($userId, (int) $guest->fresh()->user_id, 'The guest customer is linked.');
        $this->assertSame((int) $guest->id, (int) $order->fresh()->customer_id, 'The order keeps its customer.');
    }

    public function test_only_an_unproven_email_is_gated(): void
    {
        $unverifiedId = WPUserFactory::create([], false);
        (new CustomerFactory())->createForUser($unverifiedId);
        $this->assertTrue(EmailVerificationService::isRequired($unverifiedId), 'A recorded unproven email is gated.');

        $changedId = WPUserFactory::create(['user_email' => 'self-change@example.com']);
        (new CustomerFactory())->createForUser($changedId, ['email' => 'self-change@example.com']);
        wp_update_user(['ID' => $changedId, 'user_email' => 'self-change-new@example.com']);
        $this->assertTrue(EmailVerificationService::isRequired($changedId), 'An email changed without a check must be proven.');

        $trusted = (new CustomerFactory())->createWithUser();
        $this->assertFalse(EmailVerificationService::isRequired((int) $trusted->user_id), 'A trusted account keeps access.');
    }

    public function test_registration_records_nothing_and_counts_as_proof(): void
    {
        $userId = self::factory()->user->create(['user_email' => 'new-signup@example.com']);

        $this->assertFalse(metadata_exists('user', $userId, EmailVerificationService::UNVERIFIED_META_KEY), 'Registration records nothing.');
        $this->assertFalse(EmailVerificationService::isRequired($userId), 'A new account is not gated.');
    }

    public function test_an_email_changed_while_not_required_needs_proof_once_required(): void
    {
        $this->disableEmailVerification();
        $userId = WPUserFactory::create(['user_email' => 'changed-before@example.com']);
        wp_update_user(['ID' => $userId, 'user_email' => 'changed-while-off@example.com']);

        $this->assertFalse(EmailVerificationService::isRequired($userId), 'Not required: the portal stays open.');
        $this->assertFalse(EmailVerificationService::canClaimByEmail($userId), 'Not required: the email still cannot claim guest records.');

        $this->requireEmailVerification();
        $this->assertTrue(EmailVerificationService::isRequired($userId), 'Required: the unproven change is gated.');
    }
}

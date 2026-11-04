<?php

namespace Tests\Integration\Services;

use FluentCart\App\Services\CustomerIdentity\EmailVerificationService;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\WPUserFactory;
use Tests\Support\Traits\RequiresEmailVerification;

class CustomerOwnershipLifecycleTest extends WPTestCase
{
    use RequiresEmailVerification;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireEmailVerification();
        add_filter('pre_wp_mail', '__return_true');
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        remove_filter('pre_wp_mail', '__return_true');
        $this->restoreEmailVerificationDefault();
        parent::tearDown();
    }

    public function destinationScenarios(): array
    {
        return ['unused address' => ['none'], 'guest address' => ['guest'], 'another account' => ['owned']];
    }

    /** @dataProvider destinationScenarios */
    public function test_profile_updates_never_reassign_customers_sharing_an_email(string $destinationType): void
    {
        $userId = WPUserFactory::create(['user_email' => 'shared-source@example.com']);
        $guest = (new CustomerFactory())->create(['email' => 'shared-source@example.com']);
        $owned = (new CustomerFactory())->createForUser($userId);
        $guestOrder = (new OrderFactory())->create(['customer_id' => $guest->id]);
        $ownedOrder = (new OrderFactory())->create(['customer_id' => $owned->id]);
        $destination = null;
        $destinationOwner = $destinationType === 'owned' ? WPUserFactory::create() : 0;
        if ($destinationType !== 'none') {
            $destination = (new CustomerFactory())->create(['email' => 'destination@example.com', 'user_id' => $destinationOwner]);
        }
        EmailVerificationService::markVerified($userId);
        update_user_meta($userId, \FluentCart\App\Services\CustomerIdentity\EmailClaimService::META_KEY, ['hash' => 'old-link']);

        $result = wp_update_user(['ID' => $userId, 'user_email' => 'destination@example.com']);

        $this->assertSame($userId, $result, 'The account email update must succeed.');
        $this->assertSame('shared-source@example.com', $guest->fresh()->email, 'An older guest row must not be selected by its email.');
        $this->assertSame('shared-source@example.com', $owned->fresh()->email, 'The account-linked contact must wait for inbox proof.');
        $this->assertEmpty($guest->fresh()->user_id, 'A profile update must not adopt the guest record.');
        $this->assertSame($userId, (int) $owned->fresh()->user_id, 'The account must retain its existing customer link.');
        $this->assertSame((int) $guest->id, (int) $guestOrder->fresh()->customer_id, 'Guest purchases must stay with their original customer.');
        $this->assertSame((int) $owned->id, (int) $ownedOrder->fresh()->customer_id, 'Account purchases must stay with their linked customer.');
        if ($destination) {
            $this->assertSame($destinationOwner, (int) $destination->fresh()->user_id, 'A destination email collision must not change ownership.');
        }
        $this->assertTrue(EmailVerificationService::isRequired($userId), 'Changing an email must invalidate existing inbox proof.');
        $this->assertEmpty(get_user_meta($userId, \FluentCart\App\Services\CustomerIdentity\EmailClaimService::META_KEY, true), 'Changing an email must invalidate outstanding links.');
    }

    public function test_staff_email_change_moves_the_contact_email_without_a_confirmation_link(): void
    {
        $userId = WPUserFactory::create(['user_email' => 'staff-source@example.com']);
        $owned = (new CustomerFactory())->createForUser($userId);
        $guest = (new CustomerFactory())->create(['email' => 'staff-destination@example.com']);
        $guestOrder = (new OrderFactory())->create(['customer_id' => $guest->id]);
        EmailVerificationService::markVerified($userId);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        wp_update_user(['ID' => $userId, 'user_email' => 'staff-destination@example.com']);

        $this->assertSame('staff-destination@example.com', $owned->fresh()->email, 'A staff edit must move the contact email with the account.');
        $this->assertFalse(EmailVerificationService::isRequired($userId), 'A staff edit must not leave the customer behind a confirmation gate.');
        $this->assertSame((int) $owned->id, (int) $guestOrder->fresh()->customer_id, 'Guest purchases at the new address join the account, as a confirmed claim does.');
    }

    public function test_self_service_email_change_still_requires_confirmation(): void
    {
        $userId = WPUserFactory::create(['user_email' => 'self-source@example.com']);
        $owned = (new CustomerFactory())->createForUser($userId);
        EmailVerificationService::markVerified($userId);
        wp_set_current_user($userId);

        wp_update_user(['ID' => $userId, 'user_email' => 'self-destination@example.com']);

        $this->assertSame('self-source@example.com', $owned->fresh()->email, 'An account holder must prove the new inbox before the contact email moves.');
        $this->assertTrue(EmailVerificationService::isRequired($userId), 'A self-service email change must still require inbox proof.');
    }

    public function test_self_service_email_change_confirmed_through_the_wordpress_link_moves_the_contact_email(): void
    {
        $userId = WPUserFactory::create(['user_email' => 'wp-confirmed-source@example.com']);
        $owned = (new CustomerFactory())->createForUser($userId);
        EmailVerificationService::markVerified($userId);
        wp_set_current_user($userId);
        // What profile.php holds while it applies a clicked newuseremail link.
        update_user_meta($userId, '_new_email', ['hash' => 'wp-core-hash', 'newemail' => 'wp-confirmed-destination@example.com']);
        $_GET['newuseremail'] = 'wp-core-hash';

        try {
            wp_update_user(['ID' => $userId, 'user_email' => 'wp-confirmed-destination@example.com']);
        } finally {
            unset($_GET['newuseremail']);
        }

        $this->assertSame('wp-confirmed-destination@example.com', $owned->fresh()->email, 'An inbox WordPress already confirmed must move the contact email.');
        $this->assertFalse(EmailVerificationService::isRequired($userId), 'WordPress\'s own confirmation link is inbox proof; no second gate.');
    }

    public function test_self_service_email_change_with_a_wrong_wordpress_link_hash_still_requires_confirmation(): void
    {
        $userId = WPUserFactory::create(['user_email' => 'wp-forged-source@example.com']);
        $owned = (new CustomerFactory())->createForUser($userId);
        EmailVerificationService::markVerified($userId);
        wp_set_current_user($userId);
        update_user_meta($userId, '_new_email', ['hash' => 'wp-core-hash', 'newemail' => 'wp-forged-destination@example.com']);
        $_GET['newuseremail'] = 'guessed-hash';

        try {
            wp_update_user(['ID' => $userId, 'user_email' => 'wp-forged-destination@example.com']);
        } finally {
            unset($_GET['newuseremail']);
        }

        $this->assertSame('wp-forged-source@example.com', $owned->fresh()->email, 'A link that does not match the stored hash proves nothing.');
        $this->assertTrue(EmailVerificationService::isRequired($userId));
    }

    public function test_staff_email_change_keeps_the_gate_when_another_account_holds_the_address(): void
    {
        $userId = WPUserFactory::create(['user_email' => 'conflict-source@example.com']);
        $owned = (new CustomerFactory())->createForUser($userId);
        // The address is free in WordPress — a duplicate account email is rejected
        // before profile_update — but another account's contact email has drifted onto it.
        $rival = (new CustomerFactory())->createWithUser([], ['user_email' => 'conflict-rival@example.com']);
        $rival->email = 'conflict-destination@example.com';
        $rival->save();
        EmailVerificationService::markVerified($userId);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        wp_update_user(['ID' => $userId, 'user_email' => 'conflict-destination@example.com']);

        $this->assertSame('conflict-source@example.com', $owned->fresh()->email, 'An address owned by another account must not be taken over.');
        $this->assertSame((int) $rival->user_id, (int) $rival->fresh()->user_id, 'The other account keeps its customer.');
        $this->assertTrue(EmailVerificationService::isRequired($userId), 'A conflicting staff edit falls back to the confirmation gate.');
    }

    public function test_registration_does_not_adopt_an_existing_guest_record(): void
    {
        $guest = (new CustomerFactory())->create(['email' => 'registration-guest@example.com']);
        $order = (new OrderFactory())->create(['customer_id' => $guest->id]);
        $userId = self::factory()->user->create(['user_email' => $guest->email]);

        $this->assertEmpty($guest->fresh()->user_id, 'Creating credentials does not prove ownership of guest history.');
        $this->assertSame((int) $guest->id, (int) $order->fresh()->customer_id, 'Registration must preserve guest purchase ownership.');
        $this->assertFalse(EmailVerificationService::isRequired($userId), 'Registration counts as proof, so a new account is not gated.');
    }

    public function test_deleting_an_account_unlinks_only_its_own_customers(): void
    {
        $userId = WPUserFactory::create(['user_email' => 'deleted-account@example.com']);
        $other = (new CustomerFactory())->createWithUser(['email' => 'deleted-account@example.com'], ['user_email' => 'other-owner@example.com']);
        $owned = (new CustomerFactory())->createForUser($userId, ['email' => 'older-contact@example.com']);

        wp_delete_user($userId);

        $this->assertEmpty($owned->fresh()->user_id, 'Account deletion must unlink its own customer even when the emails differ.');
        $this->assertSame((int) $other->user_id, (int) $other->fresh()->user_id, 'An email match must not unlink another account.');
    }

    public function test_missing_verification_state_keeps_legacy_access_and_name_edits_preserve_valid_proof(): void
    {
        $owned = (new CustomerFactory())->createWithUser();
        $userId = (int) $owned->user_id;
        delete_user_meta($userId, EmailVerificationService::UNVERIFIED_META_KEY);

        $this->assertFalse(EmailVerificationService::isRequired($userId), 'Missing metadata marks an account that predates verification.');
        wp_update_user(['ID' => $userId, 'first_name' => 'Legacy']);
        $this->assertFalse(EmailVerificationService::isRequired($userId), 'A name-only edit must not create a verification requirement.');
        EmailVerificationService::markVerified($userId);
        wp_update_user(['ID' => $userId, 'first_name' => 'Updated']);
        $this->assertFalse(EmailVerificationService::isRequired($userId), 'A name-only edit must preserve recorded inbox proof.');
    }
}

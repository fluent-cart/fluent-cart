<?php

namespace Tests\Integration\Services;

use FluentCart\Api\Resource\CustomerResource;
use FluentCart\App\Models\CustomerMeta;
use FluentCart\App\Models\User;
use FluentCart\App\Services\CustomerIdentity\EmailClaimService;
use FluentCart\App\Services\CustomerIdentity\EmailVerificationService;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\CustomerAddressFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;
use Tests\Support\Factory\WPUserFactory;
use Tests\Support\Traits\BuildsEmailClaims;
use Tests\Support\Traits\RequiresEmailVerification;

class CustomerEmailChangeVerificationTest extends WPTestCase
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

    public function test_shared_old_email_never_moves_the_older_guests_resources(): void
    {
        $userId = WPUserFactory::create(['user_email' => 'shared-old@example.com']);
        // Insert the guest first: an email-only first() would select the wrong source.
        $guest = (new CustomerFactory())->create(['email' => 'shared-old@example.com']);
        $owned = (new CustomerFactory())->createForUser($userId);
        $destination = (new CustomerFactory())->create(['email' => 'confirmed-new@example.com']);
        $oldOrder = (new OrderFactory())->create(['customer_id' => $guest->id]);
        $ownedOrder = (new OrderFactory())->create(['customer_id' => $owned->id]);
        $destinationOrder = (new OrderFactory())->create(['customer_id' => $destination->id]);
        $product = (new ProductFactory())->create();
        $subscription = (new SubscriptionFactory())->create(['customer_id' => $guest->id, 'parent_order_id' => $oldOrder->id, 'product_id' => $product->ID, 'variation_id' => $product->variants->first()->id]);
        $address = (new CustomerAddressFactory())->create($guest->id);
        $cart = (new CartFactory())->create(['customer_id' => $guest->id]);
        $meta = CustomerMeta::query()->create(['customer_id' => $guest->id, 'meta_key' => 'private_guest_data', 'meta_value' => 'keep']);
        $guestResources = [$oldOrder, $subscription, $address, $cart, $meta];
        wp_set_current_user($userId);

        $result = wp_update_user(['ID' => $userId, 'user_email' => $destination->email]);

        $this->assertSame($userId, $result, 'The WordPress account email must change successfully.');
        $this->assertTrue(EmailVerificationService::isRequired($userId), 'The changed inbox must require proof.');
        $this->assertSame('shared-old@example.com', $owned->fresh()->email, 'The linked customer contact must wait for verification.');
        $this->assertSame('shared-old@example.com', $guest->fresh()->email, 'The older guest contact must remain unchanged.');
        foreach ($guestResources as $resource) {
            $this->assertSame((int) $guest->id, (int) $resource->fresh()->customer_id, 'Profile updates must not move guest resources: ' . get_class($resource));
        }
        $this->assertSame((int) $destination->id, (int) $destinationOrder->fresh()->customer_id, 'The destination guest purchase must wait for proof.');

        $this->assertSame('confirmed', EmailClaimService::confirm($this->issueClaimAndGetToken()), 'The new inbox holder must be able to verify.');

        $this->assertSame('confirmed-new@example.com', $owned->fresh()->email, 'Verification must update the customer linked by user ID.');
        $this->assertSame($userId, (int) $owned->fresh()->user_id, 'The original account link must remain intact.');
        $this->assertSame((int) $owned->id, (int) $ownedOrder->fresh()->customer_id, 'The account must retain its original purchase.');
        $this->assertSame((int) $owned->id, (int) $destinationOrder->fresh()->customer_id, 'Only guest history at the verified new address may be recovered.');
        foreach ($guestResources as $resource) {
            $this->assertSame((int) $guest->id, (int) $resource->fresh()->customer_id, 'Proof of the new address must not claim old-address guest resources: ' . get_class($resource));
        }
        $this->assertEmpty($guest->fresh()->user_id, 'The old-address guest must remain unlinked.');
        $this->assertFalse(EmailVerificationService::isRequired($userId), 'Successful proof must restore account access.');
    }

    public function test_shared_old_email_without_a_destination_updates_only_the_linked_customer_after_proof(): void
    {
        $userId = WPUserFactory::create(['user_email' => 'shared-contact@example.com']);
        $guest = (new CustomerFactory())->create(['email' => 'shared-contact@example.com']);
        $owned = (new CustomerFactory())->createForUser($userId);
        wp_set_current_user($userId);

        wp_update_user(['ID' => $userId, 'user_email' => 'fresh-contact@example.com']);

        $this->assertSame('shared-contact@example.com', $guest->fresh()->email, 'An account edit must not bulk-update guest contacts.');
        $this->assertSame('shared-contact@example.com', $owned->fresh()->email, 'The linked contact must remain unchanged before proof.');
        $this->assertSame('confirmed', EmailClaimService::confirm($this->issueClaimAndGetToken()), 'A new address without a collision can be verified.');
        $this->assertSame('fresh-contact@example.com', $owned->fresh()->email, 'Only the account-linked contact may be updated.');
        $this->assertSame('shared-contact@example.com', $guest->fresh()->email, 'The guest contact must still be unchanged after proof.');
    }

    public function test_email_collision_with_another_account_keeps_both_customers_and_orders_unchanged(): void
    {
        $owned = (new CustomerFactory())->createWithUser();
        $other = (new CustomerFactory())->createWithUser(['email' => 'other-contact@example.com'], ['user_email' => 'other-account@example.com']);
        $order = (new OrderFactory())->create(['customer_id' => $other->id]);
        $userId = (int) $owned->user_id;
        wp_set_current_user($userId);

        // The destination customer contact differs from its owner's WordPress email.
        $result = wp_update_user(['ID' => $userId, 'user_email' => $other->email]);

        $this->assertSame($userId, $result, 'The account email edit must reach the profile-update hook.');
        $this->assertSame($owned->email, $owned->fresh()->email, 'A collision must not rewrite the linked contact.');
        $this->assertSame((int) $other->user_id, (int) $other->fresh()->user_id, 'The other account must retain its customer.');
        $this->assertSame((int) $other->id, (int) $order->fresh()->customer_id, 'The other account must retain its purchase.');
        $this->assertTrue(EmailVerificationService::isRequired($userId), 'A collision must not bypass the dashboard gate.');
        $this->assertSame('unavailable', EmailClaimService::issue(), 'An address owned by another customer must require store assistance.');
    }

    public function test_direct_customer_email_edit_requires_new_proof_and_invalidates_a_pending_link(): void
    {
        $owned = (new CustomerFactory())->createWithUser();
        $userId = (int) $owned->user_id;
        $accountEmail = get_userdata($userId)->user_email;
        wp_set_current_user($userId);
        $this->assertFalse(EmailVerificationService::isRequired($userId), 'The fixture must start with a verified matching address.');

        $owned->update(['email' => 'manually-edited@example.com']);

        $this->assertTrue(EmailVerificationService::isRequired($userId), 'A customer edit outside WordPress must close access.');
        $token = $this->issueClaimAndGetToken();
        $this->assertSame($accountEmail, end($this->capturedMail)['to'], 'Proof must go to the signed-in account inbox, not the edited customer contact.');
        $owned->update(['email' => 'edited-again@example.com']);
        $this->assertSame('stale', EmailClaimService::confirm($token), 'A link must be bound to the customer contact state it was issued for.');
        $this->assertTrue(EmailVerificationService::isRequired($userId), 'A stale link must leave the account gated.');
        $this->assertSame('confirmed', EmailClaimService::confirm($this->issueClaimAndGetToken()), 'Fresh inbox proof must reconcile a direct customer edit.');
        $this->assertSame($accountEmail, $owned->fresh()->email, 'The verified WordPress inbox must become the customer contact.');
    }

    public function test_direct_wordpress_email_edit_is_detected_without_the_profile_hook(): void
    {
        $owned = (new CustomerFactory())->createWithUser();
        $userId = (int) $owned->user_id;
        User::query()->getQuery()->where('ID', $userId)->update(['user_email' => 'raw-db-edit@example.com']);
        clean_user_cache($userId);
        wp_set_current_user($userId);

        $this->assertTrue(EmailVerificationService::isRequired($userId), 'Access checks must detect raw database edits without relying on a hook.');
        $this->assertSame($owned->email, $owned->fresh()->email, 'A raw account edit must not move the customer contact.');
        $this->assertSame('confirmed', EmailClaimService::confirm($this->issueClaimAndGetToken()), 'The current inbox can be verified after a raw edit.');
        $this->assertSame('raw-db-edit@example.com', $owned->fresh()->email, 'Confirmation must reconcile the live account email.');
    }
}

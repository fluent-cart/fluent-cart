<?php

namespace Tests\Integration\Services;

use FluentCart\Api\Resource\CustomerResource;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Customer;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\WPUserFactory;
use Tests\Support\Traits\RequiresEmailVerification;

/**
 * Case 1 of the customer identity binding fix (dev/CUSTOMER_IDENTITY_BINDING_AUDIT.md §3.1).
 *
 * CustomerResource::getCurrentCustomer() is the storefront's entire access
 * control: every `customer-profile` route trusts whatever record it returns.
 * It used to match on the account email as a fallback and then write the
 * account's user_id onto the matched row. A WordPress email is a self-service
 * field the holder can change with no confirmation (REST, WP-CLI, front-end
 * forms), so that fallback let any account reach — and permanently claim —
 * a guest's purchase history.
 *
 * Required mode now links a guest row when the account's email has recorded proof
 * (registration counts); these scenarios use an email the account set itself.
 *
 * Every scenario below creates the WordPress user BEFORE the customer row it
 * must not reach. Creating the user afterwards would fire user_register /
 * profile_update, whose own email-match binds are separate cases of this fix
 * and would mask what the resolver does on its own.
 */
class CustomerIdentityResolutionTest extends WPTestCase
{
    use RequiresEmailVerification;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireEmailVerification();
        CustomerResource::resetCurrentCustomerRuntimeCache();
    }

    protected function tearDown(): void
    {
        CustomerResource::resetCurrentCustomerRuntimeCache();
        wp_set_current_user(0);
        $this->restoreEmailVerificationDefault();
        parent::tearDown();
    }

    /**
     * Spec: Cases/customer-identity-binding.md [1]
     *   "The dashboard resolves the customer linked to the signed-in account even when their emails differ."
     */
    public function test_the_dashboard_resolves_the_customer_linked_to_the_signed_in_account_even_when_their_emails_differ(): void
    {
        $userId = WPUserFactory::create(['user_email' => 'account-' . wp_generate_password(6, false) . '@example.com']);
        $customer = (new CustomerFactory())->create([
            'user_id' => $userId,
            'email'   => 'orders-' . wp_generate_password(6, false) . '@example.com',
        ]);

        wp_set_current_user($userId);

        $resolved = CustomerResource::getCurrentCustomer();

        $this->assertNotNull($resolved, 'A customer linked by user_id must resolve regardless of email');
        $this->assertSame($customer->id, $resolved->id, 'The linked customer row must be the one resolved');
    }

    /**
     * Spec: Cases/customer-identity-binding.md [2]
     *   "The dashboard does not resolve a guest customer that merely shares the account's email."
     */
    public function test_the_dashboard_does_not_resolve_a_guest_customer_that_merely_shares_the_accounts_email(): void
    {
        $email = 'victim-' . wp_generate_password(6, false) . '@example.com';
        // The account set this email itself, with no inbox proof.
        $attackerId = WPUserFactory::create(['user_email' => $email], false);
        $guest = (new CustomerFactory())->create(['email' => $email, 'user_id' => null]);

        wp_set_current_user($attackerId);

        $resolved = CustomerResource::getCurrentCustomer();

        $this->assertNull($resolved, 'An email match alone must not resolve a customer the account is not linked to');
        $this->assertNull(
            Customer::query()->find($guest->id)->user_id,
            'Resolving the dashboard must not write the account onto the guest customer'
        );
    }

    /**
     * Spec: Cases/customer-identity-binding.md [3]
     *   "The dashboard does not rebind a customer that belongs to another account."
     */
    public function test_the_dashboard_does_not_rebind_a_customer_that_belongs_to_another_account(): void
    {
        $sharedEmail = 'drifted-' . wp_generate_password(6, false) . '@example.com';
        $attackerId = WPUserFactory::create(['user_email' => $sharedEmail]);
        // The victim's WordPress email has since moved on; their customer row still carries the old address.
        $victimId = WPUserFactory::create(['user_email' => 'victim-now-' . wp_generate_password(6, false) . '@example.com']);
        $victimCustomer = (new CustomerFactory())->create(['email' => $sharedEmail, 'user_id' => $victimId]);

        wp_set_current_user($attackerId);

        $resolved = CustomerResource::getCurrentCustomer();

        $this->assertNull($resolved, 'Another account\'s customer must not resolve for the attacker');
        $this->assertSame(
            $victimId,
            (int) Customer::query()->find($victimCustomer->id)->user_id,
            'The victim\'s customer must still belong to the victim'
        );
    }

    /**
     * Spec: Cases/customer-identity-binding.md [4]
     *   "Creating a customer for a signed-in account never claims an existing guest record with the same email."
     */
    public function test_creating_a_customer_for_a_signed_in_account_never_claims_an_existing_guest_record(): void
    {
        $email = 'guest-history-' . wp_generate_password(6, false) . '@example.com';
        $userId = WPUserFactory::create(['user_email' => $email], false);
        $guest = (new CustomerFactory())->create(['email' => $email, 'user_id' => null]);

        wp_set_current_user($userId);

        $resolved = CustomerResource::getCurrentCustomer(true);

        $this->assertNull($resolved, 'The getter must wait for verification instead of exposing the guest');
        $this->assertSame(1, Customer::query()->where('email', $email)->count(), 'Requesting a customer must not create a duplicate');
        $this->assertNull(
            Customer::query()->find($guest->id)->user_id,
            'The guest record must remain unlinked'
        );
    }

    /**
     * Spec: Cases/customer-identity-binding.md [5]
     *   "When an account is linked to more than one customer, the oldest one is resolved."
     */
    public function test_when_an_account_is_linked_to_more_than_one_customer_the_oldest_one_is_resolved(): void
    {
        $userId = WPUserFactory::create();
        $factory = new CustomerFactory();
        $older = $factory->create(['user_id' => $userId]);
        $factory->create(['user_id' => $userId]);

        wp_set_current_user($userId);

        $resolved = CustomerResource::getCurrentCustomer();

        $this->assertSame($older->id, $resolved->id, 'Duplicate links must resolve deterministically to the oldest row');
    }

    /**
     * Spec: Cases/customer-identity-binding.md [6]
     *   "Looking a customer up by WordPress user does not fall back to an email match."
     */
    public function test_looking_a_customer_up_by_wordpress_user_does_not_fall_back_to_an_email_match(): void
    {
        $email = 'helper-victim-' . wp_generate_password(6, false) . '@example.com';
        $userId = WPUserFactory::create(['user_email' => $email]);
        (new CustomerFactory())->create(['email' => $email, 'user_id' => null]);

        $this->assertNull(
            Helper::getCustomerByUser($userId),
            'Helper::getCustomerByUser() must resolve by user_id only'
        );
    }
}

<?php

namespace Tests\Integration\Models;

use FluentCart\App\Models\Customer;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\WPUserFactory;

/**
 * Case 2 of the customer identity binding fix (dev-docs/CUSTOMER_IDENTITY_BINDING_AUDIT.md §3.4).
 *
 * Customer::getWpUser() and getWpUserId($recheck) used to look the WordPress
 * user up by the customer's email and, on a mismatch, write that user's ID
 * onto the row. A getter that persists an identity change is a rebinding
 * path every caller inherits without knowing — and the WP email it trusted
 * is a self-service field. The getters must now answer from user_id only and
 * never write.
 *
 * The WordPress user is always created before the customer row so that the
 * registration hook (a separate case) cannot link them first.
 */
class CustomerIdentityGettersTest extends WPTestCase
{
    /**
     * Spec: Cases/customer-identity-binding.md [7]
     *   "Reading a customer's WordPress user returns the linked account only."
     */
    public function test_reading_a_customers_wordpress_user_returns_the_linked_account_only(): void
    {
        $userId = WPUserFactory::create(['user_email' => 'linked-' . wp_generate_password(6, false) . '@example.com']);
        $customer = (new CustomerFactory())->create([
            'user_id' => $userId,
            'email'   => 'other-' . wp_generate_password(6, false) . '@example.com',
        ]);

        $user = $customer->getWpUser();

        $this->assertInstanceOf(\WP_User::class, $user, 'A linked customer resolves its user');
        $this->assertSame($userId, $user->ID, 'The linked account is returned even though the emails differ');
        $this->assertSame($userId, (int) $customer->getWpUserId(true), 'getWpUserId(true) agrees with the link');
    }

    /**
     * Spec: Cases/customer-identity-binding.md [8]
     *   "Reading an unlinked customer's WordPress user does not adopt the account that holds their email."
     */
    public function test_reading_an_unlinked_customers_wordpress_user_does_not_adopt_the_account_that_holds_their_email(): void
    {
        $email = 'holder-' . wp_generate_password(6, false) . '@example.com';
        WPUserFactory::create(['user_email' => $email]);
        $customer = (new CustomerFactory())->create(['email' => $email, 'user_id' => null]);

        $user = $customer->getWpUser();
        $userId = $customer->getWpUserId(true);

        $this->assertFalse($user, 'An unlinked customer has no WordPress user, whoever holds the email');
        $this->assertEmpty($userId, 'getWpUserId(true) reports no link');
        $this->assertNull(
            Customer::query()->find($customer->id)->user_id,
            'Neither getter may write the email holder onto the customer row'
        );
    }

    /**
     * Spec: Cases/customer-identity-binding.md [9]
     *   "Reading a linked customer's WordPress user never rebinds it to another account that holds their email."
     */
    public function test_reading_a_linked_customers_wordpress_user_never_rebinds_it_to_another_account(): void
    {
        $sharedEmail = 'shared-' . wp_generate_password(6, false) . '@example.com';
        $holderId = WPUserFactory::create(['user_email' => $sharedEmail]);
        $ownerId = WPUserFactory::create(['user_email' => 'owner-' . wp_generate_password(6, false) . '@example.com']);
        $customer = (new CustomerFactory())->create(['email' => $sharedEmail, 'user_id' => $ownerId]);

        $user = $customer->getWpUser();
        $recheckedId = (int) $customer->getWpUserId(true);

        $this->assertSame($ownerId, $user->ID, 'The owner stays the owner');
        $this->assertSame($ownerId, $recheckedId, 'Rechecking does not switch to the email holder');
        $this->assertNotSame($holderId, (int) Customer::query()->find($customer->id)->user_id, 'The row was not rebound');
        $this->assertSame($ownerId, (int) Customer::query()->find($customer->id)->user_id, 'The row still belongs to the owner');
    }
}

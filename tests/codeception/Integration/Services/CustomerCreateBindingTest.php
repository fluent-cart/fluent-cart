<?php

namespace Tests\Integration\Services;

use FluentCart\Api\Resource\CustomerResource;
use FluentCart\App\Models\Customer;
use FluentCart\Framework\Support\Arr;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\WPUserFactory;

/**
 * Case 5 of the customer identity binding fix (dev/CUSTOMER_IDENTITY_BINDING_AUDIT.md §6.2).
 *
 * CustomerResource::create() is shared by the admin screen, the MCP tools, the
 * User API and guest checkout. It looked the WordPress user up by the submitted
 * email and wrote that user's ID onto the row — including onto an EXISTING row
 * that firstOrCreate() had merely found. A fresh row may be linked when the
 * actor has authority over the account (an admin) or when the caller supplies
 * the identity it established (a signed-in checkout, the User API). An email
 * typed by an anonymous caller links nothing, and an existing row is never
 * claimed here.
 */
class CustomerCreateBindingTest extends WPTestCase
{
    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_an_anonymous_creation_does_not_link_the_new_row_to_the_account_holding_its_email(): void
    {
        $email = 'anon-' . wp_generate_password(6, false) . '@example.com';
        WPUserFactory::create(['user_email' => $email]);
        wp_set_current_user(0);

        $result = CustomerResource::create(['first_name' => 'Guest', 'last_name' => 'Buyer', 'email' => $email]);

        $this->assertNotInstanceOf(\WP_Error::class, $result, 'creation succeeds');
        $this->assertEmpty(Customer::query()->find(Arr::get($result, 'data.id'))->user_id, 'a guest typing an account\'s email links nothing');
    }

    public function test_creation_against_an_existing_row_never_writes_a_link_onto_it(): void
    {
        $email = 'existing-' . wp_generate_password(6, false) . '@example.com';
        $holderId = WPUserFactory::create(['user_email' => $email]);
        $existing = (new CustomerFactory())->create(['email' => $email, 'user_id' => null]);
        wp_set_current_user(WPUserFactory::create(['role' => 'administrator']));

        $result = CustomerResource::create(['first_name' => 'Again', 'last_name' => 'Buyer', 'email' => $email]);

        $this->assertInstanceOf(\WP_Error::class, $result, 'an existing email is reported as already existing');
        $this->assertNull(Customer::query()->find($existing->id)->user_id, 'the existing row is not claimed for the account holding the email');
        $this->assertNotSame($holderId, (int) Customer::query()->find($existing->id)->user_id, 'no bind by email match');
    }

    public function test_an_administrator_creating_a_fresh_row_links_it_to_the_account_holding_its_email(): void
    {
        $email = 'admin-made-' . wp_generate_password(6, false) . '@example.com';
        $holderId = WPUserFactory::create(['user_email' => $email]);
        wp_set_current_user(WPUserFactory::create(['role' => 'administrator']));

        $result = CustomerResource::create(['first_name' => 'Made', 'last_name' => 'ByAdmin', 'email' => $email]);

        $this->assertSame($holderId, (int) Customer::query()->find(Arr::get($result, 'data.id'))->user_id, 'authority over the account links the fresh row');
    }

    public function test_registration_does_not_create_a_duplicate_for_an_existing_guest_email(): void
    {
        $guest = (new CustomerFactory())->create(['email' => 'reuse-registration@example.com']);
        $userId = WPUserFactory::create(['user_email' => $guest->email], false);
        wp_set_current_user($userId);
        CustomerResource::create(['email' => $guest->email, 'user_id' => $userId, 'first_name' => 'New']);
        $this->assertSame(1, Customer::query()->where('email', $guest->email)->count(), 'Registration keeps one customer for this email');
        $this->assertEmpty($guest->fresh()->user_id, 'Registration is not inbox proof');
        $this->assertSame($guest->first_name, $guest->fresh()->first_name, 'Registration cannot overwrite the existing customer');
    }

    public function test_a_caller_supplied_user_id_is_kept_on_a_fresh_row(): void
    {
        $userId = WPUserFactory::create();
        wp_set_current_user(0);

        $result = CustomerResource::create([
            'first_name' => 'Api',
            'last_name'  => 'User',
            'email'      => 'api-' . wp_generate_password(6, false) . '@example.com',
            'user_id'    => $userId,
        ]);

        $this->assertSame($userId, (int) Customer::query()->find(Arr::get($result, 'data.id'))->user_id, 'an identity the caller established is written at creation');
    }
}

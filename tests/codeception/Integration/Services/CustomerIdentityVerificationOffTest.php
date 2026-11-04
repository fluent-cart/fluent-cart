<?php

namespace Tests\Integration\Services;

use FluentCart\Api\Resource\CustomerResource;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Models\Customer;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\WPUserFactory;

/**
 * With "Customer email verification" set to Not required, a signed-in account
 * with no customer of its own takes over the unlinked customer that shares its
 * email, so guest purchase history shows up without the claim flow. An email the
 * account gave itself without inbox proof does not take over anything.
 * The Required mode is pinned by CustomerIdentityResolutionTest.
 */
class CustomerIdentityVerificationOffTest extends WPTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $disable = function ($settings) {
            $settings = is_array($settings) ? $settings : [];
            $settings['require_customer_email_verification'] = 'no';
            return $settings;
        };
        add_filter('option_fluent_cart_store_settings', $disable);
        add_filter('default_option_fluent_cart_store_settings', $disable);
        $this->clearStoreSettingsMemo();
        CustomerResource::resetCurrentCustomerRuntimeCache();
    }

    protected function tearDown(): void
    {
        CustomerResource::resetCurrentCustomerRuntimeCache();
        wp_set_current_user(0);
        parent::tearDown();
        $this->clearStoreSettingsMemo();
    }

    private function clearStoreSettingsMemo(): void
    {
        $prop = (new \ReflectionClass(StoreSettings::class))->getProperty('cachedStoreSettings');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    public function test_a_signed_in_account_takes_over_the_unlinked_guest_customer_with_its_email(): void
    {
        $email = 'guest-' . wp_generate_password(6, false) . '@example.com';
        $userId = WPUserFactory::create(['user_email' => $email]);
        $guest = (new CustomerFactory())->create(['email' => $email, 'user_id' => null]);

        wp_set_current_user($userId);

        $resolved = CustomerResource::getCurrentCustomer();

        $this->assertNotNull($resolved);
        $this->assertSame($guest->id, $resolved->id);
        $this->assertSame($userId, (int) Customer::query()->find($guest->id)->user_id);
    }

    public function test_the_portal_entry_links_the_guest_customer_instead_of_creating_an_empty_one(): void
    {
        $email = 'portal-' . wp_generate_password(6, false) . '@example.com';
        $userId = WPUserFactory::create(['user_email' => $email]);
        $guest = (new CustomerFactory())->create(['email' => $email, 'user_id' => 0]);

        wp_set_current_user($userId);

        $resolved = CustomerResource::getCurrentCustomer(true);

        $this->assertSame($guest->id, $resolved->id);
        $this->assertSame(1, Customer::query()->where('email', $email)->count());
    }

    public function test_the_oldest_unlinked_customer_is_taken_when_several_share_the_email(): void
    {
        $email = 'dupes-' . wp_generate_password(6, false) . '@example.com';
        $userId = WPUserFactory::create(['user_email' => $email]);
        $oldest = (new CustomerFactory())->create(['email' => $email, 'user_id' => null]);
        $newer = (new CustomerFactory())->create(['email' => $email, 'user_id' => null]);

        wp_set_current_user($userId);

        $this->assertSame($oldest->id, CustomerResource::getCurrentCustomer()->id);
        $this->assertNull(Customer::query()->find($newer->id)->user_id);
    }

    public function test_a_customer_owned_by_another_account_is_never_taken(): void
    {
        $email = 'owned-' . wp_generate_password(6, false) . '@example.com';
        $ownerId = WPUserFactory::create(['user_email' => 'owner-' . wp_generate_password(6, false) . '@example.com']);
        $owned = (new CustomerFactory())->create(['email' => $email, 'user_id' => $ownerId]);
        $userId = WPUserFactory::create(['user_email' => $email]);

        wp_set_current_user($userId);

        $this->assertNull(CustomerResource::getCurrentCustomer());
        $this->assertSame($ownerId, (int) Customer::query()->find($owned->id)->user_id);

        $created = CustomerResource::getCurrentCustomer(true);

        $this->assertNotSame($owned->id, $created->id);
        $this->assertSame($userId, (int) $created->user_id);
    }

    public function test_a_self_set_email_does_not_take_over_the_guest_customer(): void
    {
        $email = 'victim-' . wp_generate_password(6, false) . '@example.com';
        $userId = WPUserFactory::create(['user_email' => 'mover-' . wp_generate_password(6, false) . '@example.com']);
        $guest = (new CustomerFactory())->create(['email' => $email, 'user_id' => null]);

        // POST /wp/v2/users/me applies the change at once, with no inbox proof.
        wp_set_current_user($userId);
        add_filter('send_email_change_email', '__return_false');
        wp_update_user(['ID' => $userId, 'user_email' => $email]);
        remove_filter('send_email_change_email', '__return_false');
        CustomerResource::resetCurrentCustomerRuntimeCache();

        $this->assertNull(CustomerResource::getCurrentCustomer(true));
        $this->assertNull(Customer::query()->find($guest->id)->user_id);
        $this->assertSame(1, Customer::query()->where('email', $email)->count());
    }

    public function test_an_account_that_already_has_a_customer_leaves_guest_customers_alone(): void
    {
        $email = 'linked-' . wp_generate_password(6, false) . '@example.com';
        $userId = WPUserFactory::create(['user_email' => $email]);
        $mine = (new CustomerFactory())->create(['email' => $email, 'user_id' => $userId]);
        $guest = (new CustomerFactory())->create(['email' => $email, 'user_id' => null]);

        wp_set_current_user($userId);

        $this->assertSame($mine->id, CustomerResource::getCurrentCustomer()->id);
        $this->assertNull(Customer::query()->find($guest->id)->user_id);
    }
}

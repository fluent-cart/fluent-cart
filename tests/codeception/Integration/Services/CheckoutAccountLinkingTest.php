<?php

namespace Tests\Integration\Services;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Listeners\Order\OrderPaid;
use FluentCart\App\Models\Customer;
use FluentCart\App\Services\AuthService;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\WPUserFactory;
use Tests\Support\Traits\RequiresEmailVerification;

/**
 * Accounts created from a customer are registered the same way whatever the
 * verification setting or database engine. With verification not required,
 * the new account is also linked to that customer.
 */
class CheckoutAccountLinkingTest extends WPTestCase
{
    use RequiresEmailVerification;

    private $engineFilter;

    protected function setUp(): void
    {
        parent::setUp();
        add_filter('pre_wp_mail', '__return_true');
        $this->disableEmailVerification();
    }

    protected function tearDown(): void
    {
        if ($this->engineFilter) {
            remove_filter('query', $this->engineFilter);
            $this->engineFilter = null;
        }
        remove_filter('pre_wp_mail', '__return_true');
        $this->restoreEmailVerificationDefault();
        wp_set_current_user(0);
        StoreSettings::clearCache();
        parent::tearDown();
    }

    public function test_paid_checkout_creates_and_links_the_account(): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'linked-checkout@example.test']);
        $order = (new OrderFactory())->create(['customer_id' => $customer->id, 'config' => ['create_account_after_paid' => 'yes']]);

        OrderPaid::maybeCreateUser($order);

        $userId = (int) email_exists($customer->email);
        $this->assertGreaterThan(0, $userId, 'Checkout must create the account.');
        $this->assertSame($userId, (int) $customer->fresh()->user_id, 'The account must be linked to its checkout customer.');
    }

    public function storageWithoutTransactions(): array
    {
        return ['MyISAM users table' => ['myisam'], 'unreadable engine metadata' => ['unreadable']];
    }

    /** @dataProvider storageWithoutTransactions */
    public function test_account_creation_does_not_depend_on_the_database_engine(string $storage): void
    {
        global $wpdb;
        $users = $wpdb->users;
        $this->engineFilter = function ($query) use ($storage, $users) {
            if (stripos($query, 'information_schema.TABLES') === false) {
                return $query;
            }
            return $storage === 'unreadable'
                ? $query . ' AND 1 = 0'
                : str_replace('SELECT ENGINE FROM', "SELECT CASE WHEN TABLE_NAME = '" . esc_sql($users) . "' THEN 'MyISAM' ELSE ENGINE END FROM", $query);
        };
        add_filter('query', $this->engineFilter);
        $customer = (new CustomerFactory())->create(['email' => 'engine-' . $storage . '@example.test']);

        $userId = AuthService::createUserFromCustomer($customer, false);

        $this->assertIsInt($userId, is_wp_error($userId) ? $userId->get_error_message() : 'Registration must return the new user ID.');
        $this->assertSame($userId, (int) $customer->fresh()->user_id, 'The account must be linked on any storage engine.');
    }

    public function test_registration_listeners_see_the_linked_customer(): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'listener-order@example.test']);
        $seen = [];
        $onRegister = function ($userId) use ($customer, &$seen) {
            $seen['user_register'] = (int) Customer::query()->where('id', $customer->id)->value('user_id') === (int) $userId;
        };
        $afterRegister = function ($userId) use ($customer, &$seen) {
            $seen['after_register'] = (int) Customer::query()->where('id', $customer->id)->value('user_id') === (int) $userId;
        };
        add_action('user_register', $onRegister, 10, 1);
        add_action('fluent_cart/user/after_register', $afterRegister, 10, 1);
        try {
            AuthService::createUserFromCustomer($customer, false);
        } finally {
            remove_action('user_register', $onRegister, 10);
            remove_action('fluent_cart/user/after_register', $afterRegister, 10);
        }

        $this->assertSame(['user_register' => true, 'after_register' => true], $seen, 'Other listeners must run after the customer is linked.');
    }

    public function test_a_customer_claimed_by_another_account_is_never_taken(): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'claimed-meanwhile@example.test']);
        $otherId = WPUserFactory::create(['user_email' => 'other-owner@example.test']);
        Customer::query()->where('id', $customer->id)->update(['user_id' => $otherId]);

        $userId = AuthService::createUserFromCustomer($customer, false);

        $this->assertIsInt($userId, 'The account must still be created.');
        $this->assertSame($otherId, (int) $customer->fresh()->user_id, 'Another account\'s customer must keep its owner.');
    }

    public function test_a_customer_whose_email_changes_during_registration_is_not_linked(): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'before-edit@example.test']);
        $editEmail = function ($data) use ($customer) {
            Customer::query()->where('id', $customer->id)->update(['email' => 'after-edit@example.test']);
            return $data;
        };
        add_filter('wp_pre_insert_user_data', $editEmail);
        try {
            $userId = AuthService::createUserFromCustomer($customer, false);
        } finally {
            remove_filter('wp_pre_insert_user_data', $editEmail);
        }

        $this->assertIsInt($userId, 'The account for the old address must still be created.');
        $this->assertEmpty($customer->fresh()->user_id, 'A customer now identified by another email must not be linked.');
    }

    public function test_the_stored_email_is_compared_case_insensitively(): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'Mixed.Case@Example.test']);

        $userId = AuthService::createUserFromCustomer($customer, false);

        $this->assertSame($userId, (int) $customer->fresh()->user_id, 'Letter case must not block the link.');
    }

    public function test_verification_required_still_links_the_new_account(): void
    {
        $this->requireEmailVerification();
        $customer = (new CustomerFactory())->create(['email' => 'required-mode@example.test']);

        $userId = AuthService::createUserFromCustomer($customer, false);

        $this->assertIsInt($userId, 'The account must be created.');
        $this->assertSame($userId, (int) $customer->fresh()->user_id, 'Only the inbox owner can sign in to a new account, so it is linked in both modes.');
    }
}

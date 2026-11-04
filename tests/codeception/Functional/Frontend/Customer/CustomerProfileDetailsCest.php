<?php

namespace Tests\Functional\Frontend\Customer;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/customers.md → "Customer profile mutations" section,
 *       items 142-143.
 */
class CustomerProfileDetailsCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/customers.md [142]
     *   "A customer can update their own profile name and the linked WordPress profile is kept in sync."
     */
    public function customerCanUpdateOwnProfileName(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = (new CustomerFactory())->createForUser($userId);

        [$status, $body] = $I->post('/fluent-cart/v2/customer-profile/update', [
            'first_name' => 'Profile',
            'last_name'  => 'Updated',
            'email'      => $customer->email,
        ]);

        Assert::assertSame(200, $status, 'own profile update must return HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString('updated successfully', (string) ($body['message'] ?? ''), 'own profile response must confirm the update');
        Assert::assertSame('Profile', (string) $customer->fresh()->first_name, 'own profile update must persist customer first_name');
        Assert::assertSame('Profile Updated', (string) get_userdata($userId)->display_name, 'own profile update must synchronize the WordPress display name');
    }

    /**
     * Spec: Cases/Functional/customers.md [143]
     *   "A customer cannot update another customer's profile by supplying another customer or user identifier."
     */
    public function customerCannotRedirectProfileUpdateToAnotherCustomer(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $owner = (new CustomerFactory())->createForUser($userId, ['first_name' => 'Owner']);
        $foreign = (new CustomerFactory())->create(['first_name' => 'Foreign', 'email' => 'foreign-profile-update@example.test']);

        [$status, $body] = $I->post('/fluent-cart/v2/customer-profile/update', [
            'customerId' => $foreign->id,
            'userId'     => 999999,
            'first_name' => 'Still Owner',
            'last_name' => 'Updated',
            'email'     => $owner->email,
        ]);

        Assert::assertSame(200, $status, 'profile update with injected ownership identifiers must still complete for the current owner. Body: ' . wp_json_encode($body));
        Assert::assertSame('Still Owner', (string) $owner->fresh()->first_name, 'profile update must remain scoped to the current customer');
        Assert::assertSame('Foreign', (string) $foreign->fresh()->first_name, 'injected foreign customerId must not mutate the foreign customer');
        Assert::assertSame('Still Owner Updated', (string) get_userdata($userId)->display_name, 'profile update must synchronize only the current WordPress user');
    }
}

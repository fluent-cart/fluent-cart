<?php

namespace Tests\Functional\Frontend\Customer;

use FluentCart\App\Models\CustomerAddresses;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerAddressFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/customers.md → "Customer profile mutations" section,
 *       items 134-141.
 */
class CustomerProfileAddressCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/customers.md [134]
     *   "A customer can create an address from their profile and it is attached to their own account."
     */
    public function customerCanCreateOwnProfileAddress(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = (new CustomerFactory())->createForUser($userId);

        [$status, $body] = $I->post('/fluent-cart/v2/customer-profile/create-address', [
            'type'       => 'billing',
            'name'       => 'Profile Owner',
            'email'      => $customer->email,
            'label'      => 'Home',
            'address_1'  => '30 Profile Way',
            'city'       => 'Boston',
            'state'      => 'MA',
            'postcode'   => '02108',
            'country'    => 'US',
        ]);

        Assert::assertSame(200, $status, 'profile-address create must return HTTP 200. Body: ' . wp_json_encode($body));
        $address = CustomerAddresses::query()->where('customer_id', $customer->id)->first();
        Assert::assertNotNull($address, 'profile-address create must persist an address for the current customer');
        Assert::assertSame('30 Profile Way', (string) $address->address_1, 'profile-address create must persist the submitted street');
        Assert::assertSame((int) $address->id, (int) ($body['data']['id'] ?? 0), 'profile-address response must serialize the created address identifier');
        Assert::assertStringContainsString('created successfully', (string) ($body['message'] ?? ''), 'profile-address response must confirm creation');
    }

    /**
     * Spec: Cases/Functional/customers.md [135]
     *   "A customer cannot attach a profile address to another customer by supplying that customer's identifier."
     */
    public function customerCannotRedirectProfileAddressToAnotherCustomer(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $owner = (new CustomerFactory())->createForUser($userId);
        $foreign = (new CustomerFactory())->create(['email' => 'foreign-profile-create@example.test']);

        [$status, $body] = $I->post('/fluent-cart/v2/customer-profile/create-address', [
            'customerId'=> $foreign->id,
            'userId'    => 999999,
            'type'      => 'billing',
            'name'      => 'Owned Profile Address',
            'email'     => $owner->email,
            'label'     => 'Home',
            'address_1' => '31 Profile Way',
            'city'      => 'Boston',
            'state'     => 'MA',
            'postcode'  => '02108',
            'country'   => 'US',
        ]);

        Assert::assertSame(200, $status, 'profile-address create with injected ownership identifiers must still complete for the current owner. Body: ' . wp_json_encode($body));
        Assert::assertSame(1, CustomerAddresses::query()->where('customer_id', $owner->id)->count(), 'injected ownership identifiers must not redirect the profile address');
        Assert::assertSame(0, CustomerAddresses::query()->where('customer_id', $foreign->id)->count(), 'injected foreign customerId must never create an address for the foreign customer');
    }

    /**
     * Spec: Cases/Functional/customers.md [136]
     *   "A customer can edit one of their own profile addresses."
     */
    public function customerCanEditOwnProfileAddress(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = (new CustomerFactory())->createForUser($userId);
        $address = (new CustomerAddressFactory())->create($customer->id, ['address_1' => 'Original Profile Street']);

        [$status, $body] = $I->post('/fluent-cart/v2/customer-profile/edit-address', [
            'id'         => $address->id,
            'type'       => 'billing',
            'name'       => 'Profile Owner',
            'email'      => $customer->email,
            'label'      => 'Office',
            'address_1'  => '32 Updated Profile Way',
            'city'       => 'Cambridge',
            'state'      => 'MA',
            'postcode'   => '02139',
            'country'    => 'US',
        ]);

        Assert::assertSame(200, $status, 'own profile-address edit must return HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertSame('32 Updated Profile Way', (string) $address->fresh()->address_1, 'own profile-address edit must persist the submitted street');
        Assert::assertStringContainsString('updated successfully', (string) ($body['message'] ?? ''), 'own profile-address response must confirm the update');
    }

    /**
     * Spec: Cases/Functional/customers.md [137]
     *   "A customer cannot edit another customer's profile address."
     */
    public function customerCannotEditAnotherCustomersProfileAddress(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $owner = (new CustomerFactory())->createForUser($userId);
        $foreign = (new CustomerFactory())->create(['email' => 'foreign-profile-edit@example.test']);
        $address = (new CustomerAddressFactory())->create($foreign->id, ['address_1' => 'Foreign Profile Street']);

        [$status, $body] = $I->post('/fluent-cart/v2/customer-profile/edit-address', [
            'id'         => $address->id,
            'type'       => 'billing',
            'name'       => 'Attacker',
            'email'      => $owner->email,
            'label'      => 'Office',
            'address_1'  => 'Compromised Profile Street',
            'city'       => 'Boston',
            'state'      => 'MA',
            'postcode'   => '02108',
            'country'    => 'US',
        ]);

        Assert::assertSame(422, $status, 'cross-customer profile-address edit must return HTTP 422. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString('not authorized', (string) ($body['message'] ?? ''), 'cross-customer profile-address response must name the authorization failure');
        Assert::assertSame('Foreign Profile Street', (string) $address->fresh()->address_1, 'cross-customer profile-address rejection must leave the foreign address unchanged');
    }

    /**
     * Spec: Cases/Functional/customers.md [138]
     *   "A customer can delete one of their own profile addresses."
     */
    public function customerCanDeleteOwnProfileAddress(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = (new CustomerFactory())->createForUser($userId);
        (new CustomerAddressFactory())->create($customer->id, ['is_primary' => 1]);
        $address = (new CustomerAddressFactory())->create($customer->id, ['is_primary' => 0]);

        [$status, $body] = $I->post('/fluent-cart/v2/customer-profile/delete-address', [
            'addressId' => $address->id,
        ]);

        Assert::assertSame(200, $status, 'own profile-address delete must return HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertSame(0, CustomerAddresses::query()->where('id', $address->id)->count(), 'own profile-address delete must remove the row');
        Assert::assertStringContainsString('successfully deleted', (string) ($body['message'] ?? ''), 'own profile-address response must confirm deletion');
    }

    /**
     * Spec: Cases/Functional/customers.md [139]
     *   "A customer cannot delete another customer's profile address."
     */
    public function customerCannotDeleteAnotherCustomersProfileAddress(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        (new CustomerFactory())->createForUser($userId);
        $foreign = (new CustomerFactory())->create(['email' => 'foreign-profile-delete@example.test']);
        $address = (new CustomerAddressFactory())->create($foreign->id);

        [$status, $body] = $I->post('/fluent-cart/v2/customer-profile/delete-address', [
            'addressId' => $address->id,
        ]);

        Assert::assertSame(422, $status, 'cross-customer profile-address delete must return HTTP 422. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString('not authorized', (string) ($body['message'] ?? ''), 'cross-customer profile-address delete must name the authorization failure');
        Assert::assertSame(1, CustomerAddresses::query()->where('id', $address->id)->count(), 'cross-customer profile-address rejection must retain the foreign address');
    }

    /**
     * Spec: Cases/Functional/customers.md [140]
     *   "A customer can make one of their own profile addresses primary."
     */
    public function customerCanMakeOwnProfileAddressPrimary(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = (new CustomerFactory())->createForUser($userId);
        $oldPrimary = (new CustomerAddressFactory())->create($customer->id, ['is_primary' => 1]);
        $target = (new CustomerAddressFactory())->create($customer->id, ['is_primary' => 0]);

        [$status, $body] = $I->post('/fluent-cart/v2/customer-profile/make-primary-address', [
            'addressId' => $target->id,
            'type'      => 'billing',
        ]);

        Assert::assertSame(200, $status, 'own profile-address make-primary must return HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertSame(1, (int) $target->fresh()->is_primary, 'profile make-primary must promote the selected owned address');
        Assert::assertSame(0, (int) $oldPrimary->fresh()->is_primary, 'profile make-primary must demote the prior owned primary address');
    }

    /**
     * Spec: Cases/Functional/customers.md [141]
     *   "A customer cannot make another customer's profile address primary."
     */
    public function customerCannotMakeAnotherCustomersProfileAddressPrimary(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $owner = (new CustomerFactory())->createForUser($userId);
        $ownerPrimary = (new CustomerAddressFactory())->create($owner->id, ['is_primary' => 1]);
        $foreign = (new CustomerFactory())->create(['email' => 'foreign-profile-primary@example.test']);
        $address = (new CustomerAddressFactory())->create($foreign->id, ['is_primary' => 0]);

        [$status, $body] = $I->post('/fluent-cart/v2/customer-profile/make-primary-address', [
            'addressId' => $address->id,
            'type'      => 'billing',
        ]);

        Assert::assertSame(422, $status, 'cross-customer profile-address make-primary must return HTTP 422. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString('not authorized', (string) ($body['message'] ?? ''), 'cross-customer profile make-primary must name the authorization failure');
        Assert::assertSame(0, (int) $address->fresh()->is_primary, 'cross-customer rejection must not promote the foreign profile address');
        Assert::assertSame(1, (int) $ownerPrimary->fresh()->is_primary, 'cross-customer rejection must retain the current customer primary address');
    }
}

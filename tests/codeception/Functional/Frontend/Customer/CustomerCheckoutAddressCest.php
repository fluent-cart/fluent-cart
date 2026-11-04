<?php

namespace Tests\Functional\Frontend\Customer;

use FluentCart\App\Models\CustomerAddresses;
use FluentCart\App\Services\CustomerIdentity\EmailVerificationService;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\CustomerAddressFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\RequiresEmailVerification;

/**
 * Spec: Cases/Functional/customers.md → "Customer-owned details and checkout addresses"
 *       section, items 126-133.
 */
class CustomerCheckoutAddressCest
{
    use RequiresEmailVerification;

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $this->requireEmailVerification();
    }

    public function _after(FunctionalTester $I): void
    {
        $this->restoreEmailVerificationDefault();
    }

    public function unverifiedCustomerCannotReadOrCreateCheckoutAddresses(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = (new CustomerFactory())->createForUser($userId);
        $address = (new CustomerAddressFactory())->create($customer->id, ['address_1' => 'Private Saved Street']);
        $cart = (new CartFactory())->create(['user_id' => $userId, 'customer_id' => $customer->id, 'cart_group' => 'instant']);
        $originalCheckout = $cart->checkout_data;
        EmailVerificationService::markUnverified($userId, $customer->email);

        [$status, $body] = $I->get('/fluent-cart/v2/customers/' . $address->id . '/update-address-select', [
            'fct_cart_hash' => $cart->cart_hash,
        ]);
        Assert::assertSame(403, $status, 'Unverified customers must not select saved addresses.');
        Assert::assertStringNotContainsString('Private Saved Street', wp_json_encode($body));
        Assert::assertSame($originalCheckout, $cart->fresh()->checkout_data, 'Denied selection must not change the cart.');

        [$status] = $I->post('/fluent-cart/v2/customers/add-address', [
            'type' => 'billing',
            'product_type' => 'digital',
            'billing_name' => 'Unverified Buyer',
            'billing_email' => $customer->email,
            'billing_label' => 'Home',
            'billing_address_1' => 'Injected Street',
            'billing_city' => 'Boston',
            'billing_state' => 'MA',
            'billing_postcode' => '02108',
            'billing_country' => 'US',
        ]);
        Assert::assertSame(403, $status, 'Unverified customers must not create saved addresses.');
        Assert::assertSame(1, CustomerAddresses::query()->where('customer_id', $customer->id)->count());
        Assert::assertSame('Private Saved Street', $address->fresh()->address_1);

        EmailVerificationService::markVerified($userId);
        [$status, $body] = $I->get('/fluent-cart/v2/customers/' . $address->id . '/update-address-select', [
            'fct_cart_hash' => $cart->cart_hash,
        ]);
        Assert::assertSame(200, $status, 'Verification must restore saved-address selection: ' . wp_json_encode($body));
        Assert::assertSame((int) $address->id, (int) $cart->fresh()->checkout_data['form_data']['billing_address_id']);
    }

    /**
     * Spec: Cases/Functional/customers.md [126]
     *   "A customer can create a checkout address and it is attached to their own account."
     */
    public function customerCanCreateOwnCheckoutAddress(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = (new CustomerFactory())->createForUser($userId);
        (new CartFactory())->create(['user_id' => $userId, 'customer_id' => $customer->id]);

        [$status, $body] = $I->post('/fluent-cart/v2/customers/add-address', [
            'type'              => 'billing',
            'product_type'      => 'digital',
            'billing_name'      => 'Checkout Owner',
            'billing_email'     => $customer->email,
            'billing_label'     => 'Home',
            'billing_address_1' => '20 Portal Way',
            'billing_city'      => 'Boston',
            'billing_state'     => 'MA',
            'billing_postcode'  => '02108',
            'billing_country'   => 'US',
        ]);

        Assert::assertSame(200, $status, 'checkout-address create must return HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString('created successfully', (string) ($body['message'] ?? ''), 'checkout-address create response must confirm success');
        $address = CustomerAddresses::query()->where('customer_id', $customer->id)->first();
        Assert::assertNotNull($address, 'checkout-address create must persist an address for the current customer');
        Assert::assertSame('20 Portal Way', (string) $address->address_1, 'checkout-address create must persist the submitted street');
    }

    /**
     * Spec: Cases/Functional/customers.md [127]
     *   "A customer cannot attach a newly created checkout address to another customer by supplying that customer's identifier."
     */
    public function customerCannotRedirectCheckoutAddressToAnotherCustomer(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $owner = (new CustomerFactory())->createForUser($userId);
        $foreign = (new CustomerFactory())->create(['email' => 'foreign-checkout-create@example.test']);
        (new CartFactory())->create(['user_id' => $userId, 'customer_id' => $owner->id]);

        [$status, $body] = $I->post('/fluent-cart/v2/customers/add-address', [
            'customer_id'       => $foreign->id,
            'type'              => 'billing',
            'product_type'      => 'digital',
            'billing_name'      => 'Owned Address',
            'billing_email'     => $owner->email,
            'billing_label'     => 'Home',
            'billing_address_1' => '21 Portal Way',
            'billing_city'      => 'Boston',
            'billing_state'     => 'MA',
            'billing_postcode'  => '02108',
            'billing_country'   => 'US',
        ]);

        Assert::assertSame(200, $status, 'checkout-address create with an injected customer_id must still complete for the current owner. Body: ' . wp_json_encode($body));
        Assert::assertSame(1, CustomerAddresses::query()->where('customer_id', $owner->id)->count(), 'injected customer_id must not redirect the address away from the current customer');
        Assert::assertSame(0, CustomerAddresses::query()->where('customer_id', $foreign->id)->count(), 'injected foreign customer_id must never create an address for the foreign customer');
    }

    /**
     * Spec: Cases/Functional/customers.md [128]
     *   "A customer edits their own addresses through the customer-profile endpoints; a customer PUT to the admin customers address endpoint is refused even for their own address."
     *
     * PUT /customers/{id}/address is the admin management surface. The shadowed
     * frontend duplicate was removed (audit item #5) — address self-service is
     * POST /customer-profile/edit-address, covered by CustomerProfileAddressCest.
     */
    public function customerPutToAdminAddressEndpointIsRefusedEvenForOwnAddress(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = (new CustomerFactory())->createForUser($userId);
        $address = (new CustomerAddressFactory())->create($customer->id, ['address_1' => 'Original Way']);

        [$status, $body] = $I->put('/fluent-cart/v2/customers/' . $customer->id . '/address', [
            'address'           => ['id' => $address->id],
            'type'              => 'billing',
            'billing_address_1' => '22 Updated Way',
        ]);

        Assert::assertSame(403, $status, 'the admin address endpoint must refuse a customer even for their own address. Body: ' . wp_json_encode($body));
        Assert::assertSame('Original Way', (string) $address->fresh()->address_1, 'the refused admin-endpoint request must not mutate the address');
    }

    /**
     * Spec: Cases/Functional/customers.md [129]
     *   "A customer cannot edit another customer's checkout address."
     */
    public function customerCannotEditAnotherCustomersCheckoutAddress(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $owner = (new CustomerFactory())->createForUser($userId);
        $foreign = (new CustomerFactory())->create(['email' => 'foreign-checkout-edit@example.test']);
        $address = (new CustomerAddressFactory())->create($foreign->id, ['address_1' => 'Foreign Street']);

        [$status, $body] = $I->put('/fluent-cart/v2/customers/' . $owner->id . '/address', [
            'address'           => ['id' => $address->id],
            'type'              => 'billing',
            'billing_label'     => 'Office',
            'billing_name'      => 'Attacker',
            'billing_email'     => $owner->email,
            'billing_address_1' => 'Compromised Street',
            'billing_city'      => 'Boston',
            'billing_state'     => 'MA',
            'billing_postcode'  => '02108',
            'billing_country'   => 'US',
        ]);

        Assert::assertSame(403, $status, 'cross-customer checkout-address edit must be refused with HTTP 403. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString('not allowed', (string) ($body['message'] ?? ''), 'cross-customer checkout-address response must name the permission failure');
        Assert::assertSame('Foreign Street', (string) $address->fresh()->address_1, 'cross-customer checkout-address rejection must leave the foreign address unchanged');
    }

    /**
     * Spec: Cases/Functional/customers.md [130]
     *   "A customer deletes their own addresses through the customer-profile endpoints; a customer DELETE to the admin customers address endpoint is refused even for their own address."
     *
     * DELETE /customers/{id}/address is the admin management surface. The
     * shadowed frontend duplicate was removed (audit item #5) — self-service is
     * POST /customer-profile/delete-address, covered by CustomerProfileAddressCest.
     */
    public function customerDeleteToAdminAddressEndpointIsRefusedEvenForOwnAddress(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = (new CustomerFactory())->createForUser($userId);
        $address = (new CustomerAddressFactory())->create($customer->id, ['is_primary' => 0]);

        [$status, $body] = $I->delete('/fluent-cart/v2/customers/' . $customer->id . '/address', [
            'address' => ['id' => $address->id],
        ]);

        Assert::assertSame(403, $status, 'the admin address endpoint must refuse a customer delete even for their own address. Body: ' . wp_json_encode($body));
        Assert::assertSame(1, CustomerAddresses::query()->where('id', $address->id)->count(), 'the refused admin-endpoint delete must retain the address row');
    }

    /**
     * Spec: Cases/Functional/customers.md [131]
     *   "A customer cannot delete another customer's checkout address."
     */
    public function customerCannotDeleteAnotherCustomersCheckoutAddress(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $owner = (new CustomerFactory())->createForUser($userId);
        $foreign = (new CustomerFactory())->create(['email' => 'foreign-checkout-delete@example.test']);
        $address = (new CustomerAddressFactory())->create($foreign->id);

        [$status, $body] = $I->delete('/fluent-cart/v2/customers/' . $owner->id . '/address', [
            'address' => ['id' => $address->id],
        ]);

        Assert::assertSame(403, $status, 'cross-customer checkout-address delete must be refused with HTTP 403. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString('not allowed', (string) ($body['message'] ?? ''), 'cross-customer checkout-address delete must name the permission failure');
        Assert::assertSame(1, CustomerAddresses::query()->where('id', $address->id)->count(), 'cross-customer checkout-address rejection must retain the foreign address');
    }

    /**
     * Spec: Cases/Functional/customers.md [132]
     *   "A customer promotes a primary address through the customer-profile endpoints; a customer POST to the admin customers make-primary endpoint is refused even for their own address."
     *
     * POST /customers/{id}/address/make-primary is the admin management
     * surface. The shadowed frontend duplicate was removed (audit item #5) —
     * self-service is POST /customer-profile/make-primary-address, covered by
     * CustomerProfileAddressCest.
     */
    public function customerPostToAdminMakePrimaryEndpointIsRefusedEvenForOwnAddress(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = (new CustomerFactory())->createForUser($userId);
        $oldPrimary = (new CustomerAddressFactory())->create($customer->id, ['is_primary' => 1]);
        $target = (new CustomerAddressFactory())->create($customer->id, ['is_primary' => 0, 'address_1' => 'Primary Target']);

        [$status, $body] = $I->post('/fluent-cart/v2/customers/' . $customer->id . '/address/make-primary', [
            'address' => ['id' => $target->id, 'type' => 'billing'],
        ]);

        Assert::assertSame(403, $status, 'the admin make-primary endpoint must refuse a customer even for their own address. Body: ' . wp_json_encode($body));
        Assert::assertSame(0, (int) $target->fresh()->is_primary, 'the refused admin-endpoint request must not promote the address');
        Assert::assertSame(1, (int) $oldPrimary->fresh()->is_primary, 'the refused admin-endpoint request must not demote the prior primary');
    }

    /**
     * Spec: Cases/Functional/customers.md [133]
     *   "A customer cannot make another customer's checkout address primary."
     */
    public function customerCannotMakeAnotherCustomersCheckoutAddressPrimary(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $owner = (new CustomerFactory())->createForUser($userId);
        $ownerPrimary = (new CustomerAddressFactory())->create($owner->id, ['is_primary' => 1]);
        $foreign = (new CustomerFactory())->create(['email' => 'foreign-checkout-primary@example.test']);
        $foreignAddress = (new CustomerAddressFactory())->create($foreign->id, ['is_primary' => 0]);

        [$status, $body] = $I->post('/fluent-cart/v2/customers/' . $foreign->id . '/address/make-primary', [
            'address' => ['id' => $foreignAddress->id, 'type' => 'billing'],
        ]);

        Assert::assertSame(403, $status, 'cross-customer checkout-address make-primary must be refused with HTTP 403. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString('not allowed', (string) ($body['message'] ?? ''), 'cross-customer make-primary response must name the permission failure');
        Assert::assertSame(0, (int) $foreignAddress->fresh()->is_primary, 'cross-customer rejection must not promote the foreign address');
        Assert::assertSame(1, (int) $ownerPrimary->fresh()->is_primary, 'cross-customer rejection must retain the current customer primary address');
    }

    /**
     * Spec: Cases/Functional/customers.md [146]
     *   "A guest hitting the checkout add-address endpoint receives the controller's no-account error, not a generic permission wall."
     *
     * CustomerController::createAddress fully self-guards (getCurrentCustomer +
     * ownership), so the policy no longer needs to pre-empt guests with the
     * generic rest_forbidden — the controller's own branch answers (audit item #6).
     */
    public function guestAddAddressReceivesControllerNoAccountErrorNotGenericPermissionWall(FunctionalTester $I): void
    {
        $I->logout();

        [$status, $body] = $I->post('/fluent-cart/v2/customers/add-address', [
            'type'              => 'billing',
            'billing_label'     => 'Home',
            'billing_name'      => 'Guest Visitor',
            'billing_email'     => 'guest-add@example.test',
            'billing_address_1' => '1 Guest Lane',
            'billing_city'      => 'Boston',
            'billing_state'     => 'MA',
            'billing_postcode'  => '02108',
            'billing_country'   => 'US',
        ]);

        Assert::assertSame(422, $status, 'guest add-address must reach the controller and get its 422 envelope, not the policy rest_forbidden. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString(
            'associated account',
            (string) ($body['message'] ?? ''),
            'guest add-address response must carry the controller no-account message. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(0, CustomerAddresses::query()->where('address_1', '1 Guest Lane')->count(), 'a guest request must never create an address row');
    }

    /**
     * Spec: Cases/Functional/customers.md [147]
     *   "A guest hitting the checkout address-select endpoint receives the controller's address-not-found error, not a generic permission wall."
     *
     * CustomerController::updateAddressSelect only acts on addresses owned by
     * the resolved current customer, so a guest can never select or leak an
     * address — the controller's own branch answers (audit item #6).
     */
    public function guestAddressSelectReceivesControllerNotFoundErrorNotGenericPermissionWall(FunctionalTester $I): void
    {
        $I->logout();

        $foreign = (new CustomerFactory())->create(['email' => 'foreign-guest-select@example.test']);
        $foreignAddress = (new CustomerAddressFactory())->create($foreign->id);

        [$status, $body] = $I->get('/fluent-cart/v2/customers/' . $foreignAddress->id . '/update-address-select');

        Assert::assertSame(422, $status, 'guest address-select must reach the controller and get its 422 envelope, not the policy rest_forbidden. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString(
            'Address not found',
            (string) ($body['message'] ?? ''),
            'guest address-select response must carry the controller address-not-found message — it must not leak the foreign address. Body: ' . wp_json_encode($body)
        );
    }
}

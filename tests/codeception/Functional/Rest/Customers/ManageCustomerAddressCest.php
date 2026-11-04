<?php

namespace Tests\Functional\Rest\Customers;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerAddressFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderAddressFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/customers.md → "Managing customer addresses" section, items 37-51.
 */
class ManageCustomerAddressCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/customers.md [37]
     *   "Admin can list a customer's billing addresses with the primary one first."
     */
    public function adminCanListCustomerBillingAddressesWithPrimaryFirst(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'addr-list-billing-' . uniqid('', true) . '@example.test']);
        $factory = new CustomerAddressFactory();
        $secondary = $factory->create((int) $customer->id, ['type' => 'billing', 'is_primary' => 0, 'address_1' => '2 Secondary B St']);
        $primary   = $factory->create((int) $customer->id, ['type' => 'billing', 'is_primary' => 1, 'address_1' => '1 Primary B Ave']);
        $other     = $factory->create((int) $customer->id, ['type' => 'shipping', 'is_primary' => 1, 'address_1' => '99 Foreign Type']);

        [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id . '/address', [
            'type' => 'billing',
        ]);

        $I->seeResponseCodeIs(200);
        $rows = $body['addresses'] ?? [];
        Assert::assertNotEmpty($rows, 'billing-address listing must not be empty');

        $ids = array_map('intval', array_column($rows, 'id'));
        Assert::assertContains((int) $primary->id, $ids, 'primary billing address must appear in the listing');
        Assert::assertContains((int) $secondary->id, $ids, 'secondary billing address must appear in the listing');
        Assert::assertNotContains((int) $other->id, $ids, 'shipping address must not leak into the billing listing');
        Assert::assertSame((int) $primary->id, $ids[0], 'primary billing address must come first in the listing');
    }

    /**
     * Spec: Cases/Functional/customers.md [38]
     *   "Admin can list a customer's shipping addresses with the primary one first."
     */
    public function adminCanListCustomerShippingAddressesWithPrimaryFirst(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'addr-list-shipping-' . uniqid('', true) . '@example.test']);
        $factory = new CustomerAddressFactory();
        $secondary = $factory->create((int) $customer->id, ['type' => 'shipping', 'is_primary' => 0, 'address_1' => '2 Secondary S St']);
        $primary   = $factory->create((int) $customer->id, ['type' => 'shipping', 'is_primary' => 1, 'address_1' => '1 Primary S Ave']);
        $other     = $factory->create((int) $customer->id, ['type' => 'billing', 'is_primary' => 1, 'address_1' => '99 Foreign Type']);

        [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id . '/address', [
            'type' => 'shipping',
        ]);

        $I->seeResponseCodeIs(200);
        $rows = $body['addresses'] ?? [];
        Assert::assertNotEmpty($rows, 'shipping-address listing must not be empty');

        $ids = array_map('intval', array_column($rows, 'id'));
        Assert::assertContains((int) $primary->id, $ids, 'primary shipping address must appear in the listing');
        Assert::assertContains((int) $secondary->id, $ids, 'secondary shipping address must appear in the listing');
        Assert::assertNotContains((int) $other->id, $ids, 'billing address must not leak into the shipping listing');
        Assert::assertSame((int) $primary->id, $ids[0], 'primary shipping address must come first in the listing');
    }

    /**
     * Spec: Cases/Functional/customers.md [39]
     *   "Admin can add a billing address to a customer."
     */
    public function adminCanAddBillingAddressToCustomer(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'addr-add-billing-' . uniqid('', true) . '@example.test']);
        // Pre-existing billing address ensures the new one is NOT auto-primary,
        // so this test only verifies the create path, not the auto-primary behavior (item 40).
        (new CustomerAddressFactory())->create((int) $customer->id, ['type' => 'billing', 'is_primary' => 1, 'address_1' => '1 Existing']);

        [, $body] = $I->post('/fluent-cart/v2/customers/' . (int) $customer->id . '/address', [
            'type'      => 'billing',
            'name'      => 'Newly Added',
            'email'     => 'add-billing@example.test',
            'phone'     => '555-0101',
            'address_1' => '500 New Billing Ave',
            'city'      => 'Chicago',
            'state'     => 'IL',
            'postcode'  => '60601',
            'country'   => 'US',
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, type, address_1, city, country, is_primary FROM {$wpdb->prefix}fct_customer_addresses WHERE customer_id = %d AND address_1 = %s",
            $customer->id,
            '500 New Billing Ave'
        ), ARRAY_A);
        Assert::assertNotEmpty($row, 'a billing address row must exist for the seeded address line');
        Assert::assertSame('billing', (string) $row['type'], 'created row must carry the billing type');
        Assert::assertSame('Chicago', (string) $row['city'], 'created row must persist the city as supplied');
        Assert::assertSame('US', (string) $row['country'], 'created row must persist the country as supplied');
        Assert::assertSame(0, (int) $row['is_primary'], 'when a primary billing address already exists, the new one must NOT auto-promote to primary');
    }

    /**
     * Spec: Cases/Functional/customers.md [40]
     *   "Admin sees a customer's first billing address marked primary automatically."
     */
    public function customersFirstBillingAddressIsAutoMarkedPrimary(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'addr-first-primary-' . uniqid('', true) . '@example.test']);
        // No prior billing address — this is the customer's first one.

        [, $body] = $I->post('/fluent-cart/v2/customers/' . (int) $customer->id . '/address', [
            'type'      => 'billing',
            'name'      => 'First Address',
            'email'     => 'first-billing@example.test',
            'phone'     => '555-0102',
            'address_1' => '1 First Billing Ln',
            'city'      => 'Boston',
            'state'     => 'MA',
            'postcode'  => '02100',
            'country'   => 'US',
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, is_primary FROM {$wpdb->prefix}fct_customer_addresses WHERE customer_id = %d AND type = %s",
            $customer->id,
            'billing'
        ), ARRAY_A);
        Assert::assertNotEmpty($row, 'a billing address row must exist for the customer after creation');
        Assert::assertSame(1, (int) $row['is_primary'], 'first billing address for a customer must be auto-marked primary');
    }

    /**
     * Spec: Cases/Functional/customers.md [41]
     *   "Admin can add a shipping address to a customer."
     */
    public function adminCanAddShippingAddressToCustomer(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'addr-add-shipping-' . uniqid('', true) . '@example.test']);

        [, $body] = $I->post('/fluent-cart/v2/customers/' . (int) $customer->id . '/address', [
            'type'      => 'shipping',
            'name'      => 'Ship Recipient',
            'email'     => 'add-shipping@example.test',
            'phone'     => '555-0103',
            'address_1' => '600 Shipping Pkwy',
            'city'      => 'Seattle',
            'state'     => 'WA',
            'postcode'  => '98101',
            'country'   => 'US',
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, type, address_1, city, country FROM {$wpdb->prefix}fct_customer_addresses WHERE customer_id = %d AND address_1 = %s",
            $customer->id,
            '600 Shipping Pkwy'
        ), ARRAY_A);
        Assert::assertNotEmpty($row, 'a shipping address row must exist for the seeded address line');
        Assert::assertSame('shipping', (string) $row['type'], 'created row must carry the shipping type');
        Assert::assertSame('Seattle', (string) $row['city'], 'created row must persist the city as supplied');
        Assert::assertSame('US', (string) $row['country'], 'created row must persist the country as supplied');
    }

    /**
     * Spec: Cases/Functional/customers.md [42]
     *   "Admin can update a customer's address details such as street and city."
     */
    public function adminCanUpdateCustomerAddressStreetAndCity(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'addr-update-' . uniqid('', true) . '@example.test']);
        $address  = (new CustomerAddressFactory())->create((int) $customer->id, [
            'type'      => 'billing',
            'is_primary' => 1,
            'address_1' => '1 OldStreet',
            'city'      => 'OldCity',
        ]);

        [, $body] = $I->put('/fluent-cart/v2/customers/' . (int) $customer->id . '/address', [
            'id'        => (int) $address->id,
            'type'      => 'billing',
            'name'      => 'Updated Person',
            'email'     => 'addr-update@example.test',
            'phone'     => '555-0104',
            'address_1' => '777 NewStreet',
            'city'      => 'NewCity',
            'state'     => 'NY',
            'postcode'  => '10001',
            'country'   => 'US',
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT address_1, city FROM {$wpdb->prefix}fct_customer_addresses WHERE id = %d",
            $address->id
        ), ARRAY_A);
        Assert::assertSame('777 NewStreet', (string) $row['address_1'], 'street must persist as updated');
        Assert::assertSame('NewCity', (string) $row['city'], 'city must persist as updated');
    }

    /**
     * Spec: Cases/Functional/customers.md [43]
     *   "Admin can promote a non-primary address to be the customer's primary one for that address type."
     */
    public function adminCanPromoteNonPrimaryAddressToPrimary(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'addr-promote-' . uniqid('', true) . '@example.test']);
        $factory  = new CustomerAddressFactory();
        $primary    = $factory->create((int) $customer->id, ['type' => 'billing', 'is_primary' => 1, 'address_1' => '1 Primary']);
        $promotee   = $factory->create((int) $customer->id, ['type' => 'billing', 'is_primary' => 0, 'address_1' => '2 Promotee']);

        [, $body] = $I->post('/fluent-cart/v2/customers/' . (int) $customer->id . '/address/make-primary', [
            'addressId' => (int) $promotee->id,
            'type'      => 'billing',
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $promoteeIsPrimary = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT is_primary FROM {$wpdb->prefix}fct_customer_addresses WHERE id = %d",
            $promotee->id
        ));
        Assert::assertSame(1, $promoteeIsPrimary, 'promoted address must be marked primary in the DB');
    }

    /**
     * Spec: Cases/Functional/customers.md [44]
     *   "Admin sees the previously primary address demoted automatically when another is promoted."
     */
    public function previousPrimaryAddressIsAutoDemotedWhenAnotherIsPromoted(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'addr-demote-' . uniqid('', true) . '@example.test']);
        $factory  = new CustomerAddressFactory();
        $oldPrimary = $factory->create((int) $customer->id, ['type' => 'billing', 'is_primary' => 1, 'address_1' => '1 OldPrimary']);
        $newPrimary = $factory->create((int) $customer->id, ['type' => 'billing', 'is_primary' => 0, 'address_1' => '2 NewPrimary']);

        [, $body] = $I->post('/fluent-cart/v2/customers/' . (int) $customer->id . '/address/make-primary', [
            'addressId' => (int) $newPrimary->id,
            'type'      => 'billing',
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $oldFlag = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT is_primary FROM {$wpdb->prefix}fct_customer_addresses WHERE id = %d",
            $oldPrimary->id
        ));
        $newFlag = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT is_primary FROM {$wpdb->prefix}fct_customer_addresses WHERE id = %d",
            $newPrimary->id
        ));
        Assert::assertSame(0, $oldFlag, 'previous primary billing address must be auto-demoted to non-primary');
        Assert::assertSame(1, $newFlag, 'newly promoted address must be the only primary billing address');
    }

    /**
     * Spec: Cases/Functional/customers.md [45]
     *   "Admin can delete a non-primary customer address when more than one remains."
     */
    public function adminCanDeleteNonPrimaryCustomerAddressWhenMoreThanOneRemains(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'addr-del-ok-' . uniqid('', true) . '@example.test']);
        $factory  = new CustomerAddressFactory();
        $primary    = $factory->create((int) $customer->id, ['type' => 'billing', 'is_primary' => 1, 'address_1' => '1 Primary Stays']);
        $secondary  = $factory->create((int) $customer->id, ['type' => 'billing', 'is_primary' => 0, 'address_1' => '2 Goes Away']);

        [, $body] = $I->delete('/fluent-cart/v2/customers/' . (int) $customer->id . '/address', [
            'address' => ['id' => (int) $secondary->id],
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_customer_addresses WHERE id = %d",
            $secondary->id
        ));
        Assert::assertSame(0, $exists, 'deleted non-primary address row must be gone from the DB');
        $primaryStillExists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_customer_addresses WHERE id = %d",
            $primary->id
        ));
        Assert::assertSame(1, $primaryStillExists, 'primary address must remain untouched by the non-primary delete');
    }

    /**
     * Spec: Cases/Functional/customers.md [46]
     *   "Admin is rejected when deleting a customer's primary address."
     */
    public function deletingPrimaryCustomerAddressIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'addr-del-primary-' . uniqid('', true) . '@example.test']);
        $factory  = new CustomerAddressFactory();
        $primary    = $factory->create((int) $customer->id, ['type' => 'billing', 'is_primary' => 1, 'address_1' => '1 PrimaryProtect']);
        $secondary  = $factory->create((int) $customer->id, ['type' => 'billing', 'is_primary' => 0, 'address_1' => '2 Other']);

        [, $body] = $I->delete('/fluent-cart/v2/customers/' . (int) $customer->id . '/address', [
            'address' => ['id' => (int) $primary->id],
        ]);

        $status = $I->grabResponseStatus();
        Assert::assertGreaterThanOrEqual(400, $status, 'deleting a primary address must not return success');
        Assert::assertLessThan(500, $status, 'rejection must be a client error, not a server error');
        Assert::assertNotEmpty($body['message'] ?? '', 'rejection response must include a non-empty message field');
        Assert::assertStringContainsStringIgnoringCase('primary', (string) ($body['message'] ?? ''), 'rejection message must mention the primary-address constraint');

        global $wpdb;
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_customer_addresses WHERE id = %d",
            $primary->id
        ));
        Assert::assertSame(1, $exists, 'primary address row must remain in the DB after a rejected delete');
    }

    /**
     * Spec: Cases/Functional/customers.md [47]
     *   "Admin is rejected when deleting a customer's only remaining address."
     */
    public function deletingOnlyRemainingCustomerAddressIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'addr-del-only-' . uniqid('', true) . '@example.test']);
        // Only one address exists — non-primary so we hit the only-remaining branch (not the primary branch).
        $only = (new CustomerAddressFactory())->create((int) $customer->id, [
            'type'      => 'billing',
            'is_primary' => 0,
            'address_1' => '1 OnlyAddress',
        ]);

        [, $body] = $I->delete('/fluent-cart/v2/customers/' . (int) $customer->id . '/address', [
            'address' => ['id' => (int) $only->id],
        ]);

        $status = $I->grabResponseStatus();
        Assert::assertGreaterThanOrEqual(400, $status, 'deleting the only remaining address must not return success');
        Assert::assertLessThan(500, $status, 'rejection must be a client error, not a server error');
        Assert::assertNotEmpty($body['message'] ?? '', 'rejection response must include a non-empty message field');
        Assert::assertStringContainsStringIgnoringCase('one', (string) ($body['message'] ?? ''), 'rejection message must mention the at-least-one-address constraint');

        global $wpdb;
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_customer_addresses WHERE id = %d",
            $only->id
        ));
        Assert::assertSame(1, $exists, 'only remaining address row must remain in the DB after a rejected delete');
    }

    /**
     * Spec: Cases/Functional/customers.md [48]
     *   "Admin is rejected when adding a customer address without a name."
     */
    public function addingCustomerAddressWithoutNameIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'addr-noname-' . uniqid('', true) . '@example.test']);

        [, $body] = $I->post('/fluent-cart/v2/customers/' . (int) $customer->id . '/address', [
            'type'      => 'billing',
            'email'     => 'noname@example.test',
            'address_1' => '1 NoName Ln',
            'city'      => 'Anywhere',
            'state'     => 'NY',
            'postcode'  => '10001',
            'country'   => 'US',
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertArrayHasKey('name', (array) $body, 'validation payload must key the failed rule by field name (name). Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('required', (array) ($body['name'] ?? []), 'validation entry for name must surface the "required" rule key');

        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_customer_addresses WHERE customer_id = %d",
            $customer->id
        ));
        Assert::assertSame(0, $count, 'no address row may be created when name validation fails');
    }

    /**
     * Spec: Cases/Functional/customers.md [49]
     *   "Admin is rejected when adding a customer address without a street address."
     */
    public function addingCustomerAddressWithoutStreetIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'addr-nostreet-' . uniqid('', true) . '@example.test']);

        [, $body] = $I->post('/fluent-cart/v2/customers/' . (int) $customer->id . '/address', [
            'type'    => 'billing',
            'name'    => 'No Street',
            'email'   => 'nostreet@example.test',
            'city'    => 'Anywhere',
            'state'   => 'NY',
            'postcode'=> '10001',
            'country' => 'US',
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertArrayHasKey('address_1', (array) $body, 'validation payload must key the failed rule by field name (address_1). Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('required', (array) ($body['address_1'] ?? []), 'validation entry for address_1 must surface the "required" rule key');

        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_customer_addresses WHERE customer_id = %d",
            $customer->id
        ));
        Assert::assertSame(0, $count, 'no address row may be created when street validation fails');
    }

    /**
     * Spec: Cases/Functional/customers.md [50]
     *   "Admin is rejected when adding a customer address without a country."
     */
    public function addingCustomerAddressWithoutCountryIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'addr-nocountry-' . uniqid('', true) . '@example.test']);

        [, $body] = $I->post('/fluent-cart/v2/customers/' . (int) $customer->id . '/address', [
            'type'      => 'billing',
            'name'      => 'No Country',
            'email'     => 'nocountry@example.test',
            'address_1' => '1 NoCountry Ln',
            'city'      => 'Anywhere',
            'state'     => 'NY',
            'postcode'  => '10001',
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertArrayHasKey('country', (array) $body, 'validation payload must key the failed rule by field name (country). Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('required', (array) ($body['country'] ?? []), 'validation entry for country must surface the "required" rule key');

        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_customer_addresses WHERE customer_id = %d",
            $customer->id
        ));
        Assert::assertSame(0, $count, 'no address row may be created when country validation fails');
    }

    /**
     * Spec: Cases/Functional/customers.md [51]
     *   "Admin sees a customer's address change copied onto an existing order's address record when the change is made in the context of a specific order."
     */
    public function customerAddressChangeIsCopiedToExistingOrderAddressWhenOrderContextSupplied(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'addr-copy-order-' . uniqid('', true) . '@example.test']);
        $order    = (new OrderFactory())->create(['customer_id' => $customer->id, 'invoice_no' => 'ADDR-COPY-1']);
        // Pre-existing order address so we hit the OrderAddressResource::update branch (item 51 specifies "existing").
        (new OrderAddressFactory())->create((int) $order->id, [
            'type'      => 'billing',
            'name'      => 'Old Order Name',
            'address_1' => '1 OldOrderStreet',
            'city'      => 'OldOrderCity',
        ]);
        // No prior customer address on this type so the new one becomes primary
        // (the order-copy branch only fires for primary writes).

        [, $body] = $I->post('/fluent-cart/v2/customers/' . (int) $customer->id . '/address', [
            'type'      => 'billing',
            'name'      => 'New Order Name',
            'email'     => 'addr-order-copy@example.test',
            'phone'     => '555-0151',
            'address_1' => '777 NewBillingFromOrder',
            'city'      => 'NewBillingCity',
            'state'     => 'CA',
            'postcode'  => '94000',
            'country'   => 'US',
            'order_id'  => (int) $order->id,
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $orderAddr = $wpdb->get_row($wpdb->prepare(
            "SELECT address_1, city FROM {$wpdb->prefix}fct_order_addresses WHERE order_id = %d AND type = %s",
            $order->id,
            'billing'
        ), ARRAY_A);
        Assert::assertNotEmpty($orderAddr, 'order address row must still exist after the customer-address change');
        Assert::assertSame('777 NewBillingFromOrder', (string) $orderAddr['address_1'], 'order address street must be replaced with the customer-address change when order_id is supplied');
        Assert::assertSame('NewBillingCity', (string) $orderAddr['city'], 'order address city must be replaced with the customer-address change when order_id is supplied');
    }
}

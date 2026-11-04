<?php

namespace Tests\Integration\Http;

use FluentCart\App\Models\Customer;
use FluentCart\App\Models\CustomerAddresses;
use FluentCart\App\Services\Renderer\CheckoutFieldsSchema;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\CustomerAddressFactory;
use Tests\Support\RestTestCase;
use Tests\Support\Traits\AssertsRestResponses;

class CustomerControllerTest extends RestTestCase
{
    use AssertsRestResponses;

    private CustomerFactory $customerFactory;
    private CustomerAddressFactory $addressFactory;

    /**
     * Every route in this file is parameterized (/customers/{customerId}/...).
     * A reused WPFluent Route memoizes the first dispatch's URL params
     * (Route::getParameter — TESTABILITY-DEBT #3), so the server must be
     * rebuilt per dispatch or later tests receive a stale customerId.
     */
    protected bool $rebuildRestServerPerDispatch = true;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->addressFactory = new CustomerAddressFactory();
    }

    public function tearDown(): void
    {
        wp_cache_flush();
        gc_collect_cycles();
        parent::tearDown();
    }

    
    // =========================================================================
    // Spec 98-100: GET /customers
    // =========================================================================

    public function test_get_customers_returns_paginated_list(): void
    {
        $this->customerFactory->create();
        $this->customerFactory->create();

        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/customers');
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('customers', $data);
    }

    public function test_get_customers_supports_search(): void
    {
        $target = $this->customerFactory->create(['first_name' => 'SearchableUniqueXYZ', 'email' => 'searchable-unique@ex.com']);
        $this->customerFactory->create(['first_name' => 'Other', 'email' => 'other-search@ex.com']);

        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/customers');
        $request->set_param('search', 'SearchableUniqueXYZ');
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        // The paginated response may nest data inside customers.data or customers directly
        $customers = $data['customers'] ?? [];
        $items = $this->extractPaginatedItems($customers);
        $ids = array_column($items, 'id');
        $this->assertContains($target->id, $ids, 'Search results should include the matching customer');
    }

    public function test_get_customers_supports_sort_params(): void
    {
        $this->customerFactory->create(['email' => 'aaa-sort@ex.com', 'first_name' => 'AAA']);
        $this->customerFactory->create(['email' => 'zzz-sort@ex.com', 'first_name' => 'ZZZ']);

        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/customers');
        $request->set_param('sort_by', 'email');
        $request->set_param('sort_type', 'ASC');
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $customers = $data['customers'] ?? [];
        $items = $this->extractPaginatedItems($customers);
        if (count($items) >= 2) {
            $emails = array_column($items, 'email');
            $this->assertLessThanOrEqual($emails[1], $emails[0], 'Results should be sorted by email ASC');
        }
    }

    // =========================================================================
    // Spec 101-104: POST /customers
    // =========================================================================

    public function test_create_customer_with_valid_data(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/customers');
        $request->set_body_params([
            'first_name' => 'HttpTest',
            'last_name'  => 'Customer',
            'full_name'  => 'HttpTest Customer',
            'email'      => 'httptest-create@example.com',
        ]);
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
    }

    public function test_create_customer_missing_email_returns_validation_error(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/customers');
        $request->set_body_params([
            'first_name' => 'NoEmail',
            'full_name'  => 'NoEmail',
        ]);
        $response = $this->dispatchRequestSilently($request);

        $this->assertValidationErrorForField($response, 'email', 'Email is required.');
    }

    public function test_create_customer_missing_name_returns_validation_error(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/customers');
        $request->set_body_params([
            'email' => 'no-name@example.com',
        ]);
        $response = $this->dispatchRequestSilently($request);

        $expectedField = CheckoutFieldsSchema::isFullNameRequired() ? 'full_name' : 'first_name';
        $expectedMessage = CheckoutFieldsSchema::isFullNameRequired()
            ? 'Full Name is required.'
            : 'First Name is required.';

        $this->assertValidationErrorForField($response, $expectedField, $expectedMessage);
    }

    public function test_create_customer_duplicate_email_returns_error(): void
    {
        $this->customerFactory->create(['email' => 'dupe-http@example.com']);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/customers');
        $request->set_body_params([
            'first_name' => 'Dupe',
            'full_name'  => 'Dupe',
            'email'      => 'dupe-http@example.com',
        ]);
        $response = $this->dispatchRequestSilently($request);

        $this->assertValidationErrorForField($response, 'email', 'Email already exists.');
    }

    // =========================================================================
    // Spec 105-106: GET /customers/{id}
    // =========================================================================

    public function test_get_single_customer_returns_customer(): void
    {
        $customer = $this->customerFactory->create();

        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/customers/' . $customer->id);
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('customer', $data);
    }

    public function test_get_nonexistent_customer_returns_error(): void
    {
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/customers/999999');
        $response = $this->dispatchRequestSilently($request);

        $data = $this->assertErrorResponseMessage(
            $response,
            [404],
            'Customer not found',
            'fluent_cart_entity_not_found'
        );
        $this->assertSame('/customers', $data['data']['route'] ?? null);
    }

    // =========================================================================
    // Spec 107-108: PUT /customers/{id}
    // =========================================================================

    public function test_update_customer_via_rest(): void
    {
        $customer = $this->customerFactory->create();

        $request = new \WP_REST_Request('PUT', '/fluent-cart/v2/customers/' . $customer->id);
        $request->set_body_params([
            'id'         => $customer->id,
            'first_name' => 'RestUpdated',
            'last_name'  => 'Smith',
            'full_name'  => 'RestUpdated Smith',
            'email'      => $customer->email,
        ]);
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status(), 'Update should succeed. Body: ' . wp_json_encode($response->get_data()));
        $fresh = Customer::find($customer->id);
        // resolveCustomerName may use first_name/last_name or full_name depending on config.
        // Either way the customer name should have been updated.
        $this->assertNotSame($customer->first_name, $fresh->first_name, 'Customer first_name should have changed');
    }

    public function test_update_nonexistent_customer_via_rest(): void
    {
        $request = new \WP_REST_Request('PUT', '/fluent-cart/v2/customers/999999');
        $request->set_body_params([
            'id'         => 999999,
            'first_name' => 'Ghost',
            'full_name'  => 'Ghost',
            'email'      => 'ghost@example.com',
        ]);
        $response = $this->dispatchRequestSilently($request);

        $this->assertErrorResponseMessage(
            $response,
            [400, 500],
            'Customer not found, please reload the page and try again!'
        );
    }

    // =========================================================================
    // Spec 109: GET /customers/{id}/orders
    // =========================================================================

    public function test_get_customer_orders(): void
    {
        $customer = $this->customerFactory->create();

        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/customers/' . $customer->id . '/orders');
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('orders', $data);
    }

    // =========================================================================
    // Spec 110-111: GET /customers/{id}/address
    // =========================================================================

    public function test_get_customer_billing_addresses(): void
    {
        $customer = $this->customerFactory->create();
        $this->addressFactory->create($customer->id, [
            'type'  => 'billing',
            'email' => $customer->email,
        ]);

        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/customers/' . $customer->id . '/address');
        $request->set_param('type', 'billing');
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('addresses', $data);
    }

    public function test_get_customer_shipping_addresses(): void
    {
        $customer = $this->customerFactory->create();
        $this->addressFactory->create($customer->id, [
            'type'  => 'shipping',
            'email' => $customer->email,
        ]);

        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/customers/' . $customer->id . '/address');
        $request->set_param('type', 'shipping');
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
    }

    // =========================================================================
    // Spec 112-113: POST /customers/{id}/address
    // =========================================================================

    public function test_create_customer_address_via_rest(): void
    {
        $customer = $this->customerFactory->create();

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/customers/' . $customer->id . '/address');
        $request->set_body_params([
            'name'      => 'John Doe',
            'email'     => $customer->email,
            'phone'     => '555-0100',
            'address_1' => '123 Main St',
            'city'      => 'New York',
            'state'     => 'NY',
            'postcode'  => '10001',
            'country'   => 'US',
            'type'      => 'billing',
        ]);
        $response = $this->dispatchRequest($request);

        $this->assertSame(
            200,
            $response->get_status(),
            'Creating an address with only the required fields must succeed. Body: ' . wp_json_encode($response->get_data())
        );
        $this->assertStringContainsString(
            'Billing address created successfully',
            $this->flattenMessages($response->get_data()['message'] ?? ''),
            'Success envelope must carry the created-address message'
        );

        $row = CustomerAddresses::query()
            ->where('customer_id', $customer->id)
            ->where('address_1', '123 Main St')
            ->first();
        $this->assertNotNull($row, 'Address row must be persisted for the customer');
        $this->assertSame('billing', $row->type, 'Persisted address must keep the submitted type');
        $this->assertSame(1, (int)$row->is_primary, 'First address of a type becomes primary');
        $this->assertSame('', $row->label, "Omitted label must persist as the column default '', never NULL or a placeholder");
    }

    public function test_create_address_missing_required_fields_returns_error(): void
    {
        $customer = $this->customerFactory->create();

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/customers/' . $customer->id . '/address');
        $request->set_body_params([
            'type' => 'billing',
            // Missing: name, email, address_1, city, country
        ]);
        $response = $this->dispatchRequestSilently($request);

        $data = $response->get_data();
        $this->assertContains(
            $response->get_status(),
            [200, 422],
            'Missing address fields should return a validation error payload'
        );
        $this->assertIsArray($data);
        foreach (['name', 'email', 'address_1', 'city', 'country'] as $field) {
            $this->assertArrayHasKey($field, $data, 'Expected validation error for field: ' . $field);
        }
    }

    // =========================================================================
    // Spec 114: PUT /customers/{id}/address
    // =========================================================================

    public function test_update_address_via_rest(): void
    {
        $customer = $this->customerFactory->create();
        $address = $this->addressFactory->create($customer->id, [
            'type'  => 'billing',
            'email' => $customer->email,
        ]);

        $request = new \WP_REST_Request('PUT', '/fluent-cart/v2/customers/' . $customer->id . '/address');
        $request->set_body_params([
            'id'        => $address->id,
            'name'      => 'Updated Name',
            'email'     => $customer->email,
            'phone'     => '555-9999',
            'address_1' => '999 Updated St',
            'city'      => 'Updated City',
            'state'     => 'CA',
            'postcode'  => '90210',
            'country'   => 'US',
            'type'      => 'billing',
        ]);
        $response = $this->dispatchRequest($request);

        $this->assertSame(
            200,
            $response->get_status(),
            'Address update must succeed. Body: ' . wp_json_encode($response->get_data())
        );
        $fresh = CustomerAddresses::find($address->id);
        $this->assertSame('999 Updated St', $fresh->address_1, 'Updated street must be persisted');
        $this->assertSame('Updated City', $fresh->city, 'Updated city must be persisted');
        $this->assertSame('billing', $fresh->type, 'Address type must be unchanged by the update');
    }

    // =========================================================================
    // Spec 115: DELETE /customers/{id}/address
    // =========================================================================

    public function test_delete_non_primary_address_via_rest(): void
    {
        $customer = $this->customerFactory->create();
        $this->addressFactory->create($customer->id, [
            'is_primary' => 1,
            'type'       => 'billing',
            'email'      => $customer->email,
        ]);
        $secondary = $this->addressFactory->create($customer->id, [
            'is_primary' => 0,
            'type'       => 'billing',
            'email'      => $customer->email,
            'address_1'  => '456 Other St',
        ]);

        $request = new \WP_REST_Request('DELETE', '/fluent-cart/v2/customers/' . $customer->id . '/address');
        $request->set_body_params([
            'address' => ['id' => $secondary->id],
        ]);
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
    }

    // =========================================================================
    // Spec 116: POST /customers/{id}/address/make-primary
    // =========================================================================

    public function test_make_address_primary_via_rest(): void
    {
        $customer = $this->customerFactory->create();
        $addr1 = $this->addressFactory->create($customer->id, [
            'type'       => 'billing',
            'is_primary' => 1,
            'email'      => $customer->email,
        ]);
        $addr2 = $this->addressFactory->create($customer->id, [
            'type'       => 'billing',
            'is_primary' => 0,
            'email'      => $customer->email,
            'address_1'  => '456 Other St',
        ]);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/customers/' . $customer->id . '/address/make-primary');
        $request->set_body_params([
            'addressId' => $addr2->id,
            'type'      => 'billing',
        ]);
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $this->assertSame(1, (int)CustomerAddresses::find($addr2->id)->is_primary);
    }

    // =========================================================================
    // Spec 117-118: POST /customers/{id}/attachable-user
    // =========================================================================

    public function test_attach_wp_user_to_customer(): void
    {
        $customer = $this->customerFactory->create(['user_id' => 0]);
        $userId = static::factory()->user->create([
            'user_email' => 'attach-' . wp_generate_password(4, false) . '@example.com',
            'role'       => 'subscriber',
        ]);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/customers/' . $customer->id . '/attachable-user');
        $request->set_body_params(['user_id' => (string)$userId]);
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status(), 'Body: ' . wp_json_encode($response->get_data()));
        $this->assertSame('User attached successfully', $response->get_data()['message'] ?? null);
        $this->assertSame($userId, (int)Customer::find($customer->id)->user_id);
    }

    public function test_attach_user_fails_when_already_linked(): void
    {
        $customer = $this->customerFactory->createWithUser();
        $alreadyLinkedUserId = (int)$customer->user_id;
        $otherUserId = static::factory()->user->create([
            'user_email' => 'attach-linked-' . wp_generate_password(4, false) . '@example.com',
            'role'       => 'subscriber',
        ]);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/customers/' . $customer->id . '/attachable-user');
        $request->set_body_params(['user_id' => (string)$otherUserId]);
        $response = $this->dispatchRequestSilently($request);

        $this->assertErrorResponseMessage($response, [422], 'Can not attach user');
        $this->assertSame(
            $alreadyLinkedUserId,
            (int)Customer::find($customer->id)->user_id,
            'Linked user must remain unchanged after a rejected attach'
        );
    }

    // =========================================================================
    // Spec 119-120: POST /customers/{id}/detach-user
    // =========================================================================

    public function test_detach_user_clears_user_id(): void
    {
        $customer = $this->customerFactory->createWithUser();

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/customers/' . $customer->id . '/detach-user');
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $fresh = Customer::find($customer->id);
        // Controller sets user_id to null, DB may store as 0 or null
        $this->assertTrue(
            empty($fresh->user_id) || $fresh->user_id === null || $fresh->user_id === 0 || $fresh->user_id === '0',
            'user_id should be cleared after detach'
        );
    }

    public function test_detach_user_nonexistent_customer_returns_error(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/customers/999999/detach-user');
        $response = $this->dispatchRequestSilently($request);

        $this->assertErrorResponseMessage($response, [422], 'Customer not found.');
    }

    // =========================================================================
    // Spec 121-122: POST /customers/{id}/recalculate-ltv
    // =========================================================================

    public function test_recalculate_ltv_returns_updated_customer(): void
    {
        $customer = $this->customerFactory->create();

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/customers/' . $customer->id . '/recalculate-ltv');
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('customer', $data);
    }

    public function test_recalculate_ltv_nonexistent_returns_error(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/customers/999999/recalculate-ltv');
        $response = $this->dispatchRequestSilently($request);

        $this->assertErrorResponseMessage($response, [422], 'Customer not found.');
    }

    // =========================================================================
    // Spec 123-124: POST /customers/do-bulk-action
    // =========================================================================

    public function test_bulk_delete_customers(): void
    {
        $c = $this->customerFactory->create();

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/customers/do-bulk-action');
        $request->set_body_params([
            'action'       => 'delete_customers',
            'customer_ids' => [$c->id],
        ]);
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $this->assertNull(Customer::find($c->id));
    }

    public function test_bulk_change_status(): void
    {
        $c = $this->customerFactory->create(['status' => 'active']);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/customers/do-bulk-action');
        $request->set_body_params([
            'action'       => 'change_customer_status',
            'customer_ids' => [$c->id],
            'new_status'   => 'inactive',
        ]);
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $this->assertSame('inactive', Customer::find($c->id)->status);
    }

    // =========================================================================
    // Spec 125: GET /customers/attachable-user
    // =========================================================================

    public function test_get_attachable_users_excludes_linked(): void
    {
        // Create a customer linked to a WP user — this user should be excluded
        $linkedCustomer = $this->customerFactory->createWithUser();
        $linkedUserId = $linkedCustomer->user_id;

        // Create an unlinked WP user — this user should appear
        $unlinkedUserId = static::factory()->user->create([
            'user_email' => 'unlinked-' . wp_generate_password(4, false) . '@example.com',
            'role'       => 'subscriber',
        ]);

        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/customers/attachable-user');
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('users', $data);

        $users = $data['users'];
        $usersArr = is_object($users) && method_exists($users, 'toArray') ? $users->toArray() : (array)$users;
        $returnedIds = array_map('intval', array_column($usersArr, 'ID'));
        $this->assertContains($unlinkedUserId, $returnedIds, 'Unlinked user should appear in attachable users');
        $this->assertNotContains((int)$linkedUserId, $returnedIds, 'Linked user should be excluded from attachable users');
    }

    // Permissions are tested via real REST dispatch in CustomerPermissionsTest.php
}

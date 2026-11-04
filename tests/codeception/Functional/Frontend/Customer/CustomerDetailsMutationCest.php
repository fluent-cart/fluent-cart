<?php

namespace Tests\Functional\Frontend\Customer;

use FluentCart\App\Models\Customer;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/customers.md → "Customer-owned details and checkout addresses"
 *       section, items 124-125.
 */
class CustomerDetailsMutationCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/customers.md [124]
     *   "A customer updates their own details through the customer-profile endpoint; a customer request to the admin customers endpoint is refused even for their own record."
     *
     * PUT /customers/{id} is the admin management surface (CustomerPolicy).
     * The frontend duplicate that used to shadow-register the same path was
     * removed (audit item #5) — own-details self-service is POST
     * /customer-profile/update, covered by CustomerProfileDetailsCest.
     */
    public function customerPutToAdminCustomersEndpointIsRefusedEvenForOwnRecord(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = (new CustomerFactory())->createForUser($userId);

        [$status, $body] = $I->put('/fluent-cart/v2/customers/' . $customer->id, [
            'id'         => $customer->id,
            'first_name' => 'Updated',
            'last_name'  => 'Owner',
            'email'      => $customer->email,
        ]);

        Assert::assertSame(403, $status, 'the admin customers endpoint must refuse a customer even for their own record. Body: ' . wp_json_encode($body));
        $stored = Customer::query()->find($customer->id);
        Assert::assertNotSame('Updated', (string) $stored->first_name, 'the refused admin-endpoint request must not mutate the customer record');
    }

    /**
     * Spec: Cases/Functional/customers.md [125]
     *   "A customer cannot update another customer's details by changing the route identifier."
     */
    public function customerCannotUpdateAnotherCustomersDetails(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $owner = (new CustomerFactory())->createForUser($userId);
        $foreign = (new CustomerFactory())->create([
            'first_name' => 'Foreign',
            'last_name'  => 'Customer',
            'email'      => 'foreign-details@example.test',
        ]);

        [$status, $body] = $I->put('/fluent-cart/v2/customers/' . $foreign->id, [
            'id'         => $foreign->id,
            'first_name' => 'Compromised',
            'last_name'  => 'Customer',
            'email'      => $foreign->email,
        ]);

        Assert::assertSame(403, $status, 'cross-customer details update must be refused with HTTP 403. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString('not allowed', (string) ($body['message'] ?? ''), 'cross-customer details response must name the permission failure');
        Assert::assertSame('Foreign', (string) $foreign->fresh()->first_name, 'cross-customer details rejection must leave the foreign customer unchanged');
        Assert::assertSame($userId, (int) $owner->fresh()->user_id, 'cross-customer rejection must retain the current customer ownership link');
    }
}

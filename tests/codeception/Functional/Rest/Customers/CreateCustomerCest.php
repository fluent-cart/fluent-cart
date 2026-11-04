<?php

namespace Tests\Functional\Rest\Customers;

use FluentCart\App\Models\Customer;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\WPUserFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/customers.md → "Creating a customer" section, items 16-22.
 */
class CreateCustomerCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/customers.md [16]
     *   "Admin can create a customer with a first name, last name, and email."
     */
    public function adminCanCreateCustomerWithFirstNameLastNameAndEmail(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $email = 'create-basic-' . uniqid('', true) . '@example.test';

        [, $body] = $I->post('/fluent-cart/v2/customers', [
            'full_name' => 'Alice Anders',
            'email'     => $email,
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertNotEmpty($body, 'create response body must not be empty');

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}fct_customers WHERE email = %s", $email),
            ARRAY_A
        );
        Assert::assertNotEmpty($row, 'a row in fct_customers must exist for the created email');
        Assert::assertSame('Alice', $row['first_name'], 'first_name must persist as parsed from full_name');
        Assert::assertSame('Anders', $row['last_name'], 'last_name must persist as parsed from full_name');
        Assert::assertSame($email, $row['email'], 'email must persist as supplied');
    }

    /**
     * Spec: Cases/Functional/customers.md [17]
     *   "Admin sees a newly created customer attached automatically to a matching WordPress user when one already exists with that email."
     */
    public function newlyCreatedCustomerIsAutoAttachedToMatchingWpUser(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $email = 'autoattach-' . uniqid('', true) . '@example.test';
        $wpUserId = WPUserFactory::create([
            'user_email' => $email,
            'first_name' => 'Match',
            'last_name'  => 'User',
        ]);

        [, $body] = $I->post('/fluent-cart/v2/customers', [
            'full_name' => 'Match User',
            'email'     => $email,
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}fct_customers WHERE email = %s", $email),
            ARRAY_A
        );
        Assert::assertNotEmpty($row, 'created customer row must exist');
        Assert::assertSame((int) $wpUserId, (int) $row['user_id'], 'created customer must auto-attach to the existing WP user with the same email');
    }

    /**
     * Spec: Cases/Functional/customers.md [18]
     *   "Admin can request that a new WordPress user be created and linked when creating a customer."
     */
    public function adminCanRequestNewWpUserCreatedAndLinkedWithCustomer(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $email = 'wpcreate-' . uniqid('', true) . '@example.test';

        Assert::assertFalse(get_user_by('email', $email), 'precondition: WP user with this email must not exist before request');

        [, $body] = $I->post('/fluent-cart/v2/customers', [
            'full_name' => 'NewUser Linked',
            'email'     => $email,
            'wp_user'   => 'yes',
        ]);

        $I->seeResponseCodeIs(200);

        $wpUser = get_user_by('email', $email);
        Assert::assertNotFalse($wpUser, 'a WP user must be created when wp_user=yes is requested');

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}fct_customers WHERE email = %s", $email),
            ARRAY_A
        );
        Assert::assertNotEmpty($row, 'created customer row must exist');
        Assert::assertSame((int) $wpUser->ID, (int) $row['user_id'], 'created customer must link to the freshly-created WP user');
    }

    /**
     * Spec: Cases/Functional/customers.md [19]
     *   "Admin is rejected when creating a customer without an email."
     */
    public function creatingCustomerWithoutEmailIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/customers', [
            'full_name' => 'NoEmail Person',
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertArrayHasKey('email', (array) $body, 'validation payload must key the failed rule by field name (email). Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('required', (array) ($body['email'] ?? []), 'validation entry for email must surface the "required" rule key. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty($body['email']['required'] ?? null, 'required-rule message for email must be non-empty');

        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_customers");
        Assert::assertSame(0, $count, 'no customer row may be created when email validation fails');
    }

    /**
     * Spec: Cases/Functional/customers.md [20]
     *   "Admin is rejected when creating a customer without a first name."
     */
    public function creatingCustomerWithoutFirstNameIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $email = 'no-first-' . uniqid('', true) . '@example.test';

        [, $body] = $I->post('/fluent-cart/v2/customers', [
            'email' => $email,
        ]);

        $I->seeResponseCodeIs(422);
        // Production validates the name field that the checkout schema makes required: in this
        // env CheckoutFieldsSchema::isFullNameRequired()=true so the rejected key is full_name.
        Assert::assertArrayHasKey('full_name', (array) $body, 'validation payload must key the failed rule by full_name (active under default checkout schema). Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('required', (array) ($body['full_name'] ?? []), 'validation entry for full_name must surface the "required" rule key. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty($body['full_name']['required'] ?? null, 'required-rule message for full_name must be non-empty');

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT id FROM {$wpdb->prefix}fct_customers WHERE email = %s", $email),
            ARRAY_A
        );
        Assert::assertEmpty($row, 'no customer row may be created when name validation fails');
    }

    /**
     * Spec: Cases/Functional/customers.md [21]
     *   "Admin is rejected when creating a customer with an email address already used by another customer."
     */
    public function creatingCustomerWithDuplicateEmailIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $email = 'duplicate-' . uniqid('', true) . '@example.test';
        $existing = (new CustomerFactory())->create(['email' => $email, 'first_name' => 'Existing']);

        [, $body] = $I->post('/fluent-cart/v2/customers', [
            'full_name' => 'Duplicate Attempt',
            'email'     => $email,
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertArrayHasKey('email', (array) $body, 'validation payload must key the failed rule by field name (email). Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('exist', (array) ($body['email'] ?? []), 'duplicate-email rejection must surface the "exist" rule key. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsStringIgnoringCase(
            'exist',
            (string) ($body['email']['exist'] ?? ''),
            'exist-rule message for email must indicate the email already exists'
        );

        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_customers WHERE email = %s",
            $email
        ));
        Assert::assertSame(1, $count, 'only the originally seeded customer must exist for this email after the rejected duplicate attempt');

        $remaining = $wpdb->get_var($wpdb->prepare(
            "SELECT first_name FROM {$wpdb->prefix}fct_customers WHERE email = %s LIMIT 1",
            $email
        ));
        Assert::assertSame('Existing', $remaining, 'pre-existing customer record must be untouched by the rejected duplicate request');
    }

}

<?php

namespace Tests\Functional\Rest\Customers;

use FluentCart\App\Models\Customer;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\LabelFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/customers.md → "Updating a customer" section, items 23-29.
 */
class UpdateCustomerCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/customers.md [23]
     *   "Admin can rename a customer."
     */
    public function adminCanRenameCustomer(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $email    = 'rename-' . uniqid('', true) . '@example.test';
        $customer = (new CustomerFactory())->create([
            'email'      => $email,
            'first_name' => 'Original',
            'last_name'  => 'Name',
        ]);

        [, $body] = $I->put('/fluent-cart/v2/customers/' . (int) $customer->id, [
            'id'        => (int) $customer->id,
            'full_name' => 'Renamed Person',
            'email'     => $email,
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT first_name, last_name FROM {$wpdb->prefix}fct_customers WHERE id = %d", $customer->id),
            ARRAY_A
        );
        Assert::assertSame('Renamed', $row['first_name'], 'first_name must be updated to the parsed first part of the new full_name');
        Assert::assertSame('Person', $row['last_name'], 'last_name must be updated to the parsed remainder of the new full_name');
    }

    /**
     * Spec: Cases/Functional/customers.md [24]
     *   "Admin can change a customer's city, state, postal code, and country."
     */
    public function adminCanChangeCustomerCityStatePostcodeAndCountry(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $email    = 'address-' . uniqid('', true) . '@example.test';
        $customer = (new CustomerFactory())->create([
            'email'    => $email,
            'city'     => 'OldCity',
            'state'    => 'OS',
            'postcode' => '00000',
            'country'  => 'US',
        ]);

        [, $body] = $I->put('/fluent-cart/v2/customers/' . (int) $customer->id, [
            'id'        => (int) $customer->id,
            'full_name' => $customer->first_name . ' ' . $customer->last_name,
            'email'     => $email,
            'city'      => 'NewCity',
            'state'     => 'CA',
            'postcode'  => '94000',
            'country'   => 'GB',
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT city, state, postcode, country FROM {$wpdb->prefix}fct_customers WHERE id = %d", $customer->id),
            ARRAY_A
        );
        Assert::assertSame('NewCity', $row['city'], 'city must persist as updated');
        Assert::assertSame('CA', $row['state'], 'state must persist as updated');
        Assert::assertSame('94000', $row['postcode'], 'postcode must persist as updated');
        Assert::assertSame('GB', $row['country'], 'country must persist as updated');
    }

    /**
     * Spec: Cases/Functional/customers.md [25]
     *   "Admin sees the linked WordPress user's display name kept in sync when a customer attached to a WordPress user is renamed."
     */
    public function linkedWpUserDisplayNameIsKeptInSyncWhenCustomerRenamed(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $email = 'sync-display-' . uniqid('', true) . '@example.test';
        $customer = (new CustomerFactory())->createWithUser([
            'email'      => $email,
            'first_name' => 'OriginalDisp',
            'last_name'  => 'Old',
        ]);
        Assert::assertGreaterThan(0, (int) $customer->user_id, 'precondition: customer must have a linked WP user_id');

        [, $body] = $I->put('/fluent-cart/v2/customers/' . (int) $customer->id, [
            'id'        => (int) $customer->id,
            'full_name' => 'Synced NewName',
            'email'     => $email,
        ]);

        $I->seeResponseCodeIs(200);

        $wpUser = get_user_by('ID', (int) $customer->user_id);
        Assert::assertNotFalse($wpUser, 'linked WP user must still exist after rename');
        Assert::assertSame(
            'Synced NewName',
            (string) $wpUser->display_name,
            'WP user display_name must be kept in sync with the customer\'s new full name'
        );
        Assert::assertSame('Synced', get_user_meta((int) $customer->user_id, 'first_name', true), 'WP user first_name meta must reflect the parsed first name');
        Assert::assertSame('NewName', get_user_meta((int) $customer->user_id, 'last_name', true), 'WP user last_name meta must reflect the parsed last name');
    }

    /**
     * Spec: Cases/Functional/customers.md [26]
     *   "Admin is rejected when updating a customer's email to one already used by another customer."
     */
    public function updatingCustomerEmailToDuplicateIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new CustomerFactory();
        $taken    = 'update-dup-taken-' . uniqid('', true) . '@example.test';
        $original = 'update-dup-original-' . uniqid('', true) . '@example.test';

        $other  = $factory->create(['email' => $taken, 'first_name' => 'Owner']);
        $target = $factory->create(['email' => $original, 'first_name' => 'Target']);

        [, $body] = $I->put('/fluent-cart/v2/customers/' . (int) $target->id, [
            'id'        => (int) $target->id,
            'full_name' => 'Target Person',
            'email'     => $taken,
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertArrayHasKey('email', (array) $body, 'validation payload must key the failed rule by field name (email). Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('exist', (array) ($body['email'] ?? []), 'duplicate-email rejection on update must surface the "exist" rule key. Body: ' . wp_json_encode($body));

        global $wpdb;
        $remaining = $wpdb->get_var($wpdb->prepare(
            "SELECT email FROM {$wpdb->prefix}fct_customers WHERE id = %d",
            $target->id
        ));
        Assert::assertSame(
            $original,
            $remaining,
            'target customer\'s email must remain unchanged after a rejected duplicate-email update'
        );
    }

    /**
     * Spec: Cases/Functional/customers.md [27]
     *   "Admin can update the labels assigned to a customer."
     */
    public function adminCanUpdateLabelsAssignedToCustomer(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'label-assign-' . uniqid('', true) . '@example.test']);
        $labelFactory = new LabelFactory();
        $existing = $labelFactory->create();
        $kept     = $labelFactory->create();
        $added    = $labelFactory->create();
        $labelFactory->attachTo((int) $existing->id, (int) $customer->id, Customer::class);
        $labelFactory->attachTo((int) $kept->id, (int) $customer->id, Customer::class);

        [, $body] = $I->put('/fluent-cart/v2/customers/' . (int) $customer->id . '/additional-info', [
            'labels' => [(int) $kept->id, (int) $added->id],
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT label_id FROM {$wpdb->prefix}fct_label_relationships WHERE labelable_id = %d AND labelable_type = %s",
            $customer->id,
            Customer::class
        ));
        $finalIds = array_map('intval', (array) $rows);
        sort($finalIds);
        $expected = [(int) $kept->id, (int) $added->id];
        sort($expected);
        Assert::assertSame(
            $expected,
            $finalIds,
            'after update, the customer\'s label relationships must be exactly the new set: previously-attached label removed, kept label kept, added label inserted'
        );
        Assert::assertNotContains((int) $existing->id, $finalIds, 'previously-attached label that was not in the new set must be detached');
    }

    /**
     * Spec: Cases/Functional/customers.md [28]
     *   "Admin receives a \"no changes to update\" notice when reassigning the same labels a customer already has."
     */
    public function customerWithoutLabelChangesReceivesNoChangesNotice(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'label-nochange-' . uniqid('', true) . '@example.test']);

        // Customer has zero labels; submit zero labels → controller hits the "no changes" branch.
        [, $body] = $I->put('/fluent-cart/v2/customers/' . (int) $customer->id . '/additional-info', [
            'labels' => [],
        ]);

        // sendError returns a non-2xx; updateAdditionalInfo emits code 400 with the no-changes message.
        Assert::assertGreaterThanOrEqual(400, $I->grabResponseStatus(), 'no-changes path must surface a non-success status');
        Assert::assertLessThan(500, $I->grabResponseStatus(), 'no-changes path must be a client-side response, not a server error');

        $message = $body['message']
            ?? ($body['data']['message'] ?? null)
            ?? ($body['errors'][0]['message'] ?? null)
            ?? wp_json_encode($body);
        Assert::assertStringContainsStringIgnoringCase(
            'no',
            (string) $message,
            'response must include the "no changes" wording. Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsStringIgnoringCase(
            'change',
            (string) $message,
            'response must include the "change" wording for the no-changes notice. Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_label_relationships WHERE labelable_id = %d AND labelable_type = %s",
            $customer->id,
            Customer::class
        ));
        Assert::assertSame(0, $count, 'no label relationship rows may be created when the no-changes path is hit');
    }

    /**
     * Spec: Cases/Functional/customers.md [29]
     *   "Admin receives a clear \"not found\" error when updating labels for a customer that does not exist."
     */
    public function updatingLabelsForMissingCustomerReturnsNotFound(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->put('/fluent-cart/v2/customers/9999999/additional-info', [
            'labels' => [1],
        ]);

        $status = $I->grabResponseStatus();
        Assert::assertGreaterThanOrEqual(400, $status, 'updating labels on a missing customer must not succeed');
        Assert::assertLessThan(500, $status, 'rejection must be a client error, not a server error');

        $message = $body['message']
            ?? ($body['data']['message'] ?? null)
            ?? ($body['errors'][0]['message'] ?? null)
            ?? wp_json_encode($body);
        Assert::assertStringContainsStringIgnoringCase(
            'not found',
            (string) $message,
            'response must include a clear "not found" message. Body: ' . wp_json_encode($body)
        );
    }
}

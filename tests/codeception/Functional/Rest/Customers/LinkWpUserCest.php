<?php

namespace Tests\Functional\Rest\Customers;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\WPUserFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/customers.md → "Linking and unlinking WordPress users" section, items 52-57.
 */
class LinkWpUserCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/customers.md [52]
     *   "Admin can list WordPress users that are not yet attached to any customer."
     */
    public function adminCanListWpUsersNotYetAttachedToAnyCustomer(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $unattachedUserId = WPUserFactory::create([
            'user_email' => 'unattached-' . uniqid('', true) . '@example.test',
            'first_name' => 'UnAttached',
        ]);
        $attachedUserId = WPUserFactory::create([
            'user_email' => 'attached-' . uniqid('', true) . '@example.test',
            'first_name' => 'Attached',
        ]);
        (new CustomerFactory())->create([
            'email'   => 'attached-cust-' . uniqid('', true) . '@example.test',
            'user_id' => $attachedUserId,
        ]);

        [, $body] = $I->get('/fluent-cart/v2/customers/attachable-user');

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('users', $body, 'response must include a users key');
        $userIds = array_map('intval', array_column((array) $body['users'], 'ID'));
        Assert::assertContains($unattachedUserId, $userIds, 'WP user with no linked customer must appear in attachable list');
        Assert::assertNotContains($attachedUserId, $userIds, 'WP user already linked to a customer must NOT appear in attachable list');
    }

    /**
     * Spec: Cases/Functional/customers.md [53]
     *   "Admin can attach a WordPress user to a customer that has none."
     */
    public function adminCanAttachWpUserToCustomerWithNone(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $userId = WPUserFactory::create([
            'user_email' => 'to-attach-' . uniqid('', true) . '@example.test',
            'first_name' => 'ToAttach',
        ]);
        $customer = (new CustomerFactory())->create([
            'email'   => 'no-user-' . uniqid('', true) . '@example.test',
            'user_id' => null,
        ]);

        [, $body] = $I->post('/fluent-cart/v2/customers/' . (int) $customer->id . '/attachable-user', [
            'user_id' => (string) $userId,
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertNotEmpty($body['message'] ?? '', 'success response must include a confirmation message');
        Assert::assertStringContainsStringIgnoringCase('attach', (string) ($body['message'] ?? ''), 'success message must mention the attach action');

        global $wpdb;
        $linkedUserId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}fct_customers WHERE id = %d",
            $customer->id
        ));
        Assert::assertSame($userId, $linkedUserId, 'customer.user_id must persist as the attached WP user id');
    }

    /**
     * Spec: Cases/Functional/customers.md [54]
     *   "Admin is rejected when attaching a WordPress user to a customer that is already linked to one."
     */
    public function attachingWpUserToAlreadyLinkedCustomerIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $existingUserId = WPUserFactory::create(['user_email' => 'already-linked-' . uniqid('', true) . '@example.test']);
        $newUserId      = WPUserFactory::create(['user_email' => 'new-attempt-' . uniqid('', true) . '@example.test']);
        $customer = (new CustomerFactory())->create([
            'email'   => 'linked-cust-' . uniqid('', true) . '@example.test',
            'user_id' => $existingUserId,
        ]);

        [, $body] = $I->post('/fluent-cart/v2/customers/' . (int) $customer->id . '/attachable-user', [
            'user_id' => (string) $newUserId,
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertNotEmpty($body['message'] ?? '', 'rejection response must include a non-empty message field. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsStringIgnoringCase('attach', (string) ($body['message'] ?? ''), 'rejection message must mention the attach failure');

        global $wpdb;
        $linkedUserId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}fct_customers WHERE id = %d",
            $customer->id
        ));
        Assert::assertSame($existingUserId, $linkedUserId, 'pre-existing link must remain unchanged after a rejected attach');
    }

    /**
     * Spec: Cases/Functional/customers.md [55]
     *   "Admin receives a clear \"not found\" error when attaching a user to a customer that does not exist."
     */
    public function attachingWpUserToMissingCustomerReturnsNotFound(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $userId = WPUserFactory::create(['user_email' => 'attach-to-missing-' . uniqid('', true) . '@example.test']);

        [, $body] = $I->post('/fluent-cart/v2/customers/9999999/attachable-user', [
            'user_id' => (string) $userId,
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertNotEmpty($body['message'] ?? '', 'rejection response must include a non-empty message field. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsStringIgnoringCase('not found', (string) ($body['message'] ?? ''), 'rejection message must indicate the customer is not found');
    }

    /**
     * Spec: Cases/Functional/customers.md [56]
     *   "Admin can detach the linked WordPress user from a customer."
     */
    public function adminCanDetachLinkedWpUserFromCustomer(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $userId = WPUserFactory::create(['user_email' => 'to-detach-' . uniqid('', true) . '@example.test']);
        $customer = (new CustomerFactory())->create([
            'email'   => 'detach-cust-' . uniqid('', true) . '@example.test',
            'user_id' => (string) $userId,
        ]);

        [, $body] = $I->post('/fluent-cart/v2/customers/' . (int) $customer->id . '/detach-user');

        $I->seeResponseCodeIs(200);
        Assert::assertNotEmpty($body['message'] ?? '', 'success response must include a confirmation message');
        Assert::assertStringContainsStringIgnoringCase('detach', (string) ($body['message'] ?? ''), 'success message must mention the detach action');

        global $wpdb;
        $linkedUserId = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}fct_customers WHERE id = %d",
            $customer->id
        ));
        Assert::assertSame(
            0,
            (int) $linkedUserId,
            'detached customer.user_id must persist as 0 (the contract this MySQL build writes when the controller assigns null to the BIGINT column). Got: ' . var_export($linkedUserId, true)
        );
    }

    /**
     * Spec: Cases/Functional/customers.md [57]
     *   "Admin receives a clear \"not found\" error when detaching the user from a customer that does not exist."
     */
    public function detachingWpUserFromMissingCustomerReturnsNotFound(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $body] = $I->post('/fluent-cart/v2/customers/9999999/detach-user');

        $I->seeResponseCodeIs(422);
        Assert::assertNotEmpty($body['message'] ?? '', 'rejection response must include a non-empty message field. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsStringIgnoringCase('not found', (string) ($body['message'] ?? ''), 'rejection message must indicate the customer is not found');
    }
}

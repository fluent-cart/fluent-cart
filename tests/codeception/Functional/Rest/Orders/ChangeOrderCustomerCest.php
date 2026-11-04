<?php

namespace Tests\Functional\Rest\Orders;

use FluentCart\App\Models\Order;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/orders.md → "Changing the customer on an order" section, items 36-38.
 */
class ChangeOrderCustomerCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/orders.md [36]
     *   "Admin can attach an existing customer to an order, replacing the previous one."
     */
    public function adminCanAttachExistingCustomerToOrderReplacingPreviousOne(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $oldCustomer = (new CustomerFactory())->create(['email' => 'old-attach@example.test']);
        $newCustomer = (new CustomerFactory())->create(['email' => 'new-attach@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id' => $oldCustomer->id,
            'invoice_no'  => 'INV-ATTACH-EXISTING',
        ]);

        global $wpdb;
        $beforeRow = $wpdb->get_row(
            $wpdb->prepare("SELECT customer_id FROM {$wpdb->prefix}fct_orders WHERE id = %d", (int) $order->id),
            ARRAY_A
        );
        Assert::assertSame((int) $oldCustomer->id, (int) $beforeRow['customer_id'], 'precondition: order must start linked to the old customer');

        [, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/change-customer', [
            'customer_id' => (int) $newCustomer->id,
        ]);

        $I->seeResponseCodeIs(200);
        $message = (string) ($body['message'] ?? '');
        Assert::assertNotEmpty($message, 'change-customer success body must carry a human-readable message. Body: ' . wp_json_encode($body));

        $afterRow = $wpdb->get_row(
            $wpdb->prepare("SELECT customer_id FROM {$wpdb->prefix}fct_orders WHERE id = %d", (int) $order->id),
            ARRAY_A
        );
        Assert::assertSame(
            (int) $newCustomer->id,
            (int) $afterRow['customer_id'],
            'order.customer_id must be updated to the newly attached customer id, replacing the previous one'
        );
        Assert::assertNotSame(
            (int) $oldCustomer->id,
            (int) $afterRow['customer_id'],
            'order.customer_id must no longer reference the previous customer after the change'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [37]
     *   "Admin can create a brand-new customer and attach them to an order in a single step."
     */
    public function adminCanCreateBrandNewCustomerAndAttachToOrderInSingleStep(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $oldCustomer = (new CustomerFactory())->create(['email' => 'old-singlestep@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id' => $oldCustomer->id,
            'invoice_no'  => 'INV-CREATE-AND-ATTACH',
        ]);

        global $wpdb;
        $customersBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_customers");
        Assert::assertSame(1, $customersBefore, 'precondition: only the old customer exists in fct_customers before the request');

        $newEmail = 'brand-new-' . uniqid('', true) . '@example.test';

        // Test environment's CheckoutFieldsSchema reports isFullNameRequired() = true,
        // so CustomerRequest::rules() requires full_name rather than first_name+last_name.
        [, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/create-and-change-customer', [
            'full_name'  => 'Brand New',
            'first_name' => 'Brand',
            'last_name'  => 'New',
            'email'      => $newEmail,
        ]);

        $I->seeResponseCodeIs(200);
        $message = (string) ($body['message'] ?? '');
        Assert::assertNotEmpty($message, 'create-and-change-customer success body must carry a human-readable message. Body: ' . wp_json_encode($body));

        $customersAfter = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_customers");
        Assert::assertSame(2, $customersAfter, 'request must create exactly one new customer row in fct_customers in addition to the old one');

        $newCustomerRow = $wpdb->get_row(
            $wpdb->prepare("SELECT id, email, first_name, last_name FROM {$wpdb->prefix}fct_customers WHERE email = %s", $newEmail),
            ARRAY_A
        );
        Assert::assertNotEmpty($newCustomerRow, 'new customer row must exist for the submitted email. Body: ' . wp_json_encode($body));
        Assert::assertSame('Brand', (string) $newCustomerRow['first_name'], 'new customer first_name must persist the submitted value');
        Assert::assertSame('New', (string) $newCustomerRow['last_name'], 'new customer last_name must persist the submitted value');

        $orderCustomerId = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT customer_id FROM {$wpdb->prefix}fct_orders WHERE id = %d", (int) $order->id)
        );
        Assert::assertSame(
            (int) $newCustomerRow['id'],
            $orderCustomerId,
            'order must now reference the freshly created customer id, not the old customer'
        );
        Assert::assertNotSame(
            (int) $oldCustomer->id,
            $orderCustomerId,
            'order must no longer reference the previous customer after the single-step create-and-attach'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [38]
     *   "Admin sees the customer change recorded in the order's activity log."
     */
    public function adminSeesCustomerChangeRecordedInOrderActivityLog(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $oldCustomer = (new CustomerFactory())->create(['email' => 'old-log@example.test', 'first_name' => 'Olivia', 'last_name' => 'Old']);
        $newCustomer = (new CustomerFactory())->create(['email' => 'new-log@example.test', 'first_name' => 'Nina', 'last_name' => 'New']);

        $order = (new OrderFactory())->create([
            'customer_id' => $oldCustomer->id,
            'invoice_no'  => 'INV-CUSTOMER-LOG',
        ]);

        global $wpdb;
        $logsBefore = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fct_activity WHERE module_type = %s AND module_id = %d AND log_type = %s",
                Order::class,
                (int) $order->id,
                'activity'
            )
        );
        Assert::assertSame(0, $logsBefore, 'precondition: no activity-log row may exist for the target order before the change');

        // Capture the event payload so we can also assert the side-effect dispatch was real,
        // not just the activity-log row that listeners write afterwards.
        $captured = [];
        $listener = function ($payload) use (&$captured) {
            $captured[] = $payload;
        };
        add_action('fluent_cart/order_customer_changed', $listener, 10, 1);

        try {
            [, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/change-customer', [
                'customer_id' => (int) $newCustomer->id,
            ]);
        } finally {
            remove_action('fluent_cart/order_customer_changed', $listener, 10);
        }

        $I->seeResponseCodeIs(200);

        Assert::assertCount(
            1,
            $captured,
            'fluent_cart/order_customer_changed action must fire exactly once per change-customer request. Captured: ' . count($captured)
        );
        $payload = $captured[0];
        Assert::assertIsArray($payload, 'event payload must be an array');
        Assert::assertArrayHasKey('order', $payload, 'event payload must include the order key');
        Assert::assertArrayHasKey('old_customer', $payload, 'event payload must include the old_customer key');
        Assert::assertArrayHasKey('new_customer', $payload, 'event payload must include the new_customer key');
        Assert::assertArrayHasKey('connected_order_ids', $payload, 'event payload must include the connected_order_ids key');
        Assert::assertSame((int) $order->id, (int) ($payload['order']->id ?? 0), 'event order id must match the target order');
        Assert::assertSame((int) $oldCustomer->id, (int) ($payload['old_customer']->id ?? 0), 'event old_customer id must match the previous customer');
        Assert::assertSame((int) $newCustomer->id, (int) ($payload['new_customer']->id ?? 0), 'event new_customer id must match the freshly attached customer');
        Assert::assertContains(
            (int) $order->id,
            array_map('intval', (array) $payload['connected_order_ids']),
            'event connected_order_ids must include the target order id'
        );

        $logRow = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT title, content, status, log_type, module_name FROM {$wpdb->prefix}fct_activity WHERE module_type = %s AND module_id = %d AND log_type = %s ORDER BY id DESC LIMIT 1",
                Order::class,
                (int) $order->id,
                'activity'
            ),
            ARRAY_A
        );
        Assert::assertNotEmpty(
            $logRow,
            'an activity-log row must be recorded against the order for the customer change. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            'order',
            (string) $logRow['module_name'],
            'activity row must be scoped to the order module'
        );
        Assert::assertSame(
            'success',
            (string) $logRow['status'],
            'customer-change activity must be recorded with success status'
        );
        Assert::assertStringContainsString(
            'Customer changed',
            (string) $logRow['title'],
            'activity title must describe the customer-change action so the order audit trail is human-readable'
        );
        Assert::assertStringContainsString(
            'Olivia Old',
            (string) $logRow['content'],
            'activity content must record the previous customer full name'
        );
        Assert::assertStringContainsString(
            'Nina New',
            (string) $logRow['content'],
            'activity content must record the new customer full name'
        );
    }
}

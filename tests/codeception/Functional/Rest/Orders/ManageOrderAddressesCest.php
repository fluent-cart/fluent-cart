<?php

namespace Tests\Functional\Rest\Orders;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerAddressFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderAddressFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/orders.md → "Managing addresses on an order" section, items 33-35.
 */
class ManageOrderAddressesCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/orders.md [33]
     *   "Admin can switch which saved customer address is used as an order's billing or shipping address."
     */
    public function adminCanSwitchSavedCustomerAddressUsedForOrderBillingOrShipping(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'switch-address@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'invoice_no'  => 'INV-SWITCH-ADDR',
        ]);

        // Existing billing + shipping OrderAddress rows; switching should mutate each in place.
        $existingBilling = (new OrderAddressFactory())->create((int) $order->id, [
            'type'      => 'billing',
            'name'      => 'Old Billing Name',
            'address_1' => '111 Old Billing St',
            'city'      => 'Old City',
            'state'     => 'NY',
            'postcode'  => '10001',
            'country'   => 'US',
        ]);
        $existingShipping = (new OrderAddressFactory())->create((int) $order->id, [
            'type'      => 'shipping',
            'name'      => 'Old Shipping Name',
            'address_1' => '222 Old Shipping St',
            'city'      => 'Old Ship City',
            'state'     => 'NY',
            'postcode'  => '10002',
            'country'   => 'US',
        ]);

        // Saved CustomerAddresses for billing and shipping. Saved country differs from the
        // existing OrderAddress country (US vs CA / GB) so a regression that fails to copy
        // country will diverge the assertion.
        $savedBilling = (new CustomerAddressFactory())->create((int) $customer->id, [
            'type'      => 'billing',
            'name'      => 'Saved Customer Billing',
            'address_1' => '500 Saved Customer Blvd',
            'city'      => 'Saved City',
            'state'     => 'ON',
            'postcode'  => 'M5H',
            'country'   => 'CA',
        ]);
        $savedShipping = (new CustomerAddressFactory())->create((int) $customer->id, [
            'type'      => 'shipping',
            'name'      => 'Saved Customer Shipping',
            'address_1' => '900 Saved Ship Way',
            'city'      => 'Saved Ship City',
            'state'     => 'LDN',
            'postcode'  => 'WC2N',
            'country'   => 'GB',
        ]);

        // -- Switch billing --
        [, $billingBody] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/update-address-id', [
            'address_id'   => (int) $savedBilling->id,
            'address_type' => 'billing',
        ]);
        $I->seeResponseCodeIs(200);

        global $wpdb;
        $billingRow = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, type, name, address_1, city, state, postcode, country FROM {$wpdb->prefix}fct_order_addresses WHERE id = %d",
                (int) $existingBilling->id
            ),
            ARRAY_A
        );
        Assert::assertNotEmpty($billingRow, 'order billing address row must still exist after the billing switch. Body: ' . wp_json_encode($billingBody));
        Assert::assertSame('billing', (string) $billingRow['type'], 'switched billing row must remain typed as billing');
        Assert::assertSame('Saved Customer Billing', (string) $billingRow['name'], 'order billing name must be replaced with the saved billing name');
        Assert::assertSame('500 Saved Customer Blvd', (string) $billingRow['address_1'], 'order billing address_1 must be replaced with the saved billing address_1');
        Assert::assertSame('Saved City', (string) $billingRow['city'], 'order billing city must be replaced with the saved billing city');
        Assert::assertSame('ON', (string) $billingRow['state'], 'order billing state must be replaced with the saved billing state');
        Assert::assertSame('M5H', (string) $billingRow['postcode'], 'order billing postcode must be replaced with the saved billing postcode');
        Assert::assertSame('CA', (string) $billingRow['country'], 'order billing country must be replaced with the saved billing country (US → CA)');

        $billingRowCount = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fct_order_addresses WHERE order_id = %d AND type = %s",
                (int) $order->id,
                'billing'
            )
        );
        Assert::assertSame(1, $billingRowCount, 'switching billing must mutate the existing row, not create a duplicate billing row');

        // Cross-check before the shipping switch: the existing shipping row must be untouched
        // by the billing switch — otherwise the later shipping switch could mask a regression
        // that bleeds the billing payload onto shipping.
        $shippingBeforeShippingSwitch = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT name, address_1, city, state, postcode, country FROM {$wpdb->prefix}fct_order_addresses WHERE id = %d",
                (int) $existingShipping->id
            ),
            ARRAY_A
        );
        Assert::assertNotEmpty($shippingBeforeShippingSwitch, 'shipping row must still exist after the billing-only switch');
        Assert::assertSame('Old Shipping Name', (string) $shippingBeforeShippingSwitch['name'], 'shipping name must remain at original value after a billing-only switch');
        Assert::assertSame('222 Old Shipping St', (string) $shippingBeforeShippingSwitch['address_1'], 'shipping address_1 must remain at original value after a billing-only switch');
        Assert::assertSame('Old Ship City', (string) $shippingBeforeShippingSwitch['city'], 'shipping city must remain at original value after a billing-only switch');
        Assert::assertSame('NY', (string) $shippingBeforeShippingSwitch['state'], 'shipping state must remain at original value after a billing-only switch');
        Assert::assertSame('10002', (string) $shippingBeforeShippingSwitch['postcode'], 'shipping postcode must remain at original value after a billing-only switch');
        Assert::assertSame('US', (string) $shippingBeforeShippingSwitch['country'], 'shipping country must remain at original value after a billing-only switch');

        // -- Switch shipping --
        [, $shippingBody] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/update-address-id', [
            'address_id'   => (int) $savedShipping->id,
            'address_type' => 'shipping',
        ]);
        $I->seeResponseCodeIs(200);

        $shippingRow = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, type, name, address_1, city, state, postcode, country FROM {$wpdb->prefix}fct_order_addresses WHERE id = %d",
                (int) $existingShipping->id
            ),
            ARRAY_A
        );
        Assert::assertNotEmpty($shippingRow, 'order shipping address row must still exist after the shipping switch. Body: ' . wp_json_encode($shippingBody));
        Assert::assertSame('shipping', (string) $shippingRow['type'], 'switched shipping row must remain typed as shipping');
        Assert::assertSame('Saved Customer Shipping', (string) $shippingRow['name'], 'order shipping name must be replaced with the saved shipping name');
        Assert::assertSame('900 Saved Ship Way', (string) $shippingRow['address_1'], 'order shipping address_1 must be replaced with the saved shipping address_1');
        Assert::assertSame('Saved Ship City', (string) $shippingRow['city'], 'order shipping city must be replaced with the saved shipping city');
        Assert::assertSame('LDN', (string) $shippingRow['state'], 'order shipping state must be replaced with the saved shipping state');
        Assert::assertSame('WC2N', (string) $shippingRow['postcode'], 'order shipping postcode must be replaced with the saved shipping postcode');
        Assert::assertSame('GB', (string) $shippingRow['country'], 'order shipping country must be replaced with the saved shipping country (US → GB)');

        $shippingRowCount = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fct_order_addresses WHERE order_id = %d AND type = %s",
                (int) $order->id,
                'shipping'
            )
        );
        Assert::assertSame(1, $shippingRowCount, 'switching shipping must mutate the existing row, not create a duplicate shipping row');

        // Cross-check: billing row must not have been mutated by the shipping switch.
        $billingAfterShippingSwitch = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT name, address_1 FROM {$wpdb->prefix}fct_order_addresses WHERE id = %d",
                (int) $existingBilling->id
            ),
            ARRAY_A
        );
        Assert::assertSame(
            'Saved Customer Billing',
            (string) $billingAfterShippingSwitch['name'],
            'billing row must remain at its post-billing-switch state — the shipping switch must not bleed into the billing row'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [34]
     *   "Admin receives a clear \"not found\" error when switching addresses on an order that does not exist."
     */
    public function adminReceivesNotFoundErrorWhenSwitchingAddressOnNonExistentOrder(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'switch-missing-order@example.test']);

        // Build a saved address so the request payload is otherwise valid; only the order id is missing.
        $savedAddress = (new CustomerAddressFactory())->create((int) $customer->id, [
            'type' => 'billing',
        ]);

        global $wpdb;
        $maxOrderId = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$wpdb->prefix}fct_orders");
        $missingOrderId = $maxOrderId + 9999;

        $orderAddressesBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_order_addresses");
        Assert::assertSame(0, $orderAddressesBefore, 'precondition: no order address rows exist before the rejected request');

        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . $missingOrderId . '/update-address-id', [
            'address_id'   => (int) $savedAddress->id,
            'address_type' => 'billing',
        ]);

        Assert::assertSame(
            404,
            $status,
            'switching address on a non-existent order must return 404. Body: ' . wp_json_encode($body)
        );
        $message = (string) ($body['message'] ?? '');
        Assert::assertNotEmpty(
            $message,
            'response must carry a human-readable error message identifying the missing resource. Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            'not found',
            strtolower($message),
            'error message must clearly identify a missing order. Body: ' . wp_json_encode($body)
        );

        // No address row may be created or mutated for the non-existent order.
        $orderAddressesAfter = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_order_addresses");
        Assert::assertSame(
            0,
            $orderAddressesAfter,
            'rejected request on a non-existent order must NOT create any fct_order_addresses row'
        );
        $missingOrderAddressCount = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fct_order_addresses WHERE order_id = %d",
                $missingOrderId
            )
        );
        Assert::assertSame(
            0,
            $missingOrderAddressCount,
            'rejected request must NOT create any fct_order_addresses row for the missing order id'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [35]
     *   "Admin can edit the details of an order's billing or shipping address directly."
     */
    public function adminCanEditDetailsOfOrderBillingOrShippingAddressDirectly(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'edit-address-directly@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'invoice_no'  => 'INV-EDIT-ADDR',
        ]);

        $billing = (new OrderAddressFactory())->create((int) $order->id, [
            'type'      => 'billing',
            'name'      => 'Original Billing',
            'address_1' => '100 Original Billing Ln',
            'city'      => 'Original Bill City',
            'state'     => 'TX',
            'postcode'  => '75001',
            'country'   => 'US',
        ]);
        $shipping = (new OrderAddressFactory())->create((int) $order->id, [
            'type'      => 'shipping',
            'name'      => 'Original Shipping',
            'address_1' => '500 Original Ship Ln',
            'city'      => 'Original Ship City',
            'state'     => 'TX',
            'postcode'  => '75002',
            'country'   => 'US',
        ]);

        // OrderResource::updateOrderAddress reads order_id and id from the request BODY, not the
        // path parameters, so the body must carry both identifiers for the row lookup to succeed.
        // The path parameters are still required to resolve the route, but they are not the
        // discriminator inside the resource layer.

        // -- Edit billing --
        // Original country is US; edit submits CA so a regression that fails to persist
        // country would diverge from this assertion.
        [, $billingBody] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/address/' . (int) $billing->id,
            [
                'order_id'  => (int) $order->id,
                'id'        => (int) $billing->id,
                'name'      => 'Edited Billing Name',
                'address_1' => '999 Edited Billing Way',
                'city'      => 'Edited Bill City',
                'state'     => 'ON',
                'postcode'  => 'M5H',
                'country'   => 'CA',
            ]
        );

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $billingRow = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT type, name, address_1, city, state, postcode, country FROM {$wpdb->prefix}fct_order_addresses WHERE id = %d",
                (int) $billing->id
            ),
            ARRAY_A
        );
        Assert::assertNotEmpty($billingRow, 'billing row must still exist after edit. Body: ' . wp_json_encode($billingBody));
        Assert::assertSame('billing', (string) $billingRow['type'], 'edit must not alter the billing address type');
        Assert::assertSame('Edited Billing Name', (string) $billingRow['name'], 'billing name must persist the edited value');
        Assert::assertSame('999 Edited Billing Way', (string) $billingRow['address_1'], 'billing address_1 must persist the edited value');
        Assert::assertSame('Edited Bill City', (string) $billingRow['city'], 'billing city must persist the edited value');
        Assert::assertSame('ON', (string) $billingRow['state'], 'billing state must persist the edited value');
        Assert::assertSame('M5H', (string) $billingRow['postcode'], 'billing postcode must persist the edited value');
        Assert::assertSame('CA', (string) $billingRow['country'], 'billing country must persist the edited value (US → CA)');

        // Cross-check before the shipping edit: the existing shipping row must be untouched by
        // the billing edit — otherwise the later shipping edit could mask a regression that
        // bleeds the billing payload onto shipping.
        $shippingBeforeShippingEdit = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT name, address_1, city, state, postcode, country FROM {$wpdb->prefix}fct_order_addresses WHERE id = %d",
                (int) $shipping->id
            ),
            ARRAY_A
        );
        Assert::assertNotEmpty($shippingBeforeShippingEdit, 'shipping row must still exist after the billing-only edit');
        Assert::assertSame('Original Shipping', (string) $shippingBeforeShippingEdit['name'], 'shipping name must remain at original value after a billing-only edit');
        Assert::assertSame('500 Original Ship Ln', (string) $shippingBeforeShippingEdit['address_1'], 'shipping address_1 must remain at original value after a billing-only edit');
        Assert::assertSame('Original Ship City', (string) $shippingBeforeShippingEdit['city'], 'shipping city must remain at original value after a billing-only edit');
        Assert::assertSame('TX', (string) $shippingBeforeShippingEdit['state'], 'shipping state must remain at original value after a billing-only edit');
        Assert::assertSame('75002', (string) $shippingBeforeShippingEdit['postcode'], 'shipping postcode must remain at original value after a billing-only edit');
        Assert::assertSame('US', (string) $shippingBeforeShippingEdit['country'], 'shipping country must remain at original value after a billing-only edit');

        // -- Edit shipping --
        // Original country is US; edit submits GB.
        [, $shippingBody] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/address/' . (int) $shipping->id,
            [
                'order_id'  => (int) $order->id,
                'id'        => (int) $shipping->id,
                'name'      => 'Edited Shipping Name',
                'address_1' => '777 Edited Ship Lane',
                'city'      => 'Edited Ship City',
                'state'     => 'LDN',
                'postcode'  => 'WC2N',
                'country'   => 'GB',
            ]
        );

        $I->seeResponseCodeIs(200);

        $shippingRow = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT type, name, address_1, city, state, postcode, country FROM {$wpdb->prefix}fct_order_addresses WHERE id = %d",
                (int) $shipping->id
            ),
            ARRAY_A
        );
        Assert::assertNotEmpty($shippingRow, 'shipping row must still exist after edit. Body: ' . wp_json_encode($shippingBody));
        Assert::assertSame('shipping', (string) $shippingRow['type'], 'edit must not alter the shipping address type');
        Assert::assertSame('Edited Shipping Name', (string) $shippingRow['name'], 'shipping name must persist the edited value');
        Assert::assertSame('777 Edited Ship Lane', (string) $shippingRow['address_1'], 'shipping address_1 must persist the edited value');
        Assert::assertSame('Edited Ship City', (string) $shippingRow['city'], 'shipping city must persist the edited value');
        Assert::assertSame('LDN', (string) $shippingRow['state'], 'shipping state must persist the edited value');
        Assert::assertSame('WC2N', (string) $shippingRow['postcode'], 'shipping postcode must persist the edited value');
        Assert::assertSame('GB', (string) $shippingRow['country'], 'shipping country must persist the edited value (US → GB)');

        // Cross-check: billing row must not have been mutated by the shipping edit.
        $billingAfter = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT name FROM {$wpdb->prefix}fct_order_addresses WHERE id = %d",
                (int) $billing->id
            )
        );
        Assert::assertSame(
            'Edited Billing Name',
            $billingAfter,
            'billing row must keep its post-billing-edit state — the shipping edit must not bleed into the billing row'
        );
    }
}

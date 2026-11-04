<?php

namespace Tests\Functional\Frontend\Customer;

use FluentCart\App\Models\OrderAddress;
use FluentCart\App\Models\OrderMeta;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/customers.md → "Customer order billing address" section,
 *       items 144-145.
 */
class CustomerOrderBillingAddressCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/customers.md [144]
     *   "A customer can save the billing address for a transaction on their own order."
     */
    public function customerCanSaveBillingAddressForOwnTransaction(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = (new CustomerFactory())->createForUser($userId);
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $transaction = (new OrderTransactionFactory())->create($order->id);

        [$status, $body] = $I->put(
            '/fluent-cart/v2/customer-profile/orders/' . (string) $transaction->uuid . '/billing-address',
            [
                'name'       => 'Billing Owner',
                'address_1'  => '40 Transaction Lane',
                'address_2'  => 'Suite 4',
                'city'       => 'Austin',
                'state'      => 'TX',
                'postcode'   => '78701',
                'country'    => 'US',
                'vat_tax_id' => 'US-TAX-144',
            ]
        );

        Assert::assertSame(200, $status, 'own transaction billing-address save must return HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString('created successfully', (string) ($body['message'] ?? ''), 'own transaction billing-address response must confirm creation');

        $address = OrderAddress::query()
            ->where('order_id', $order->id)
            ->where('type', 'billing')
            ->first();
        Assert::assertNotNull($address, 'own transaction billing-address save must create an fct_order_addresses row');
        Assert::assertSame('40 Transaction Lane', (string) $address->address_1, 'own transaction billing-address save must persist the submitted street');
        Assert::assertSame('US-TAX-144', (string) OrderMeta::query()
            ->where('order_id', $order->id)
            ->where('meta_key', 'vat_tax_id')
            ->value('meta_value'), 'own transaction billing-address save must persist VAT tax ID as order meta');
    }

    /**
     * Spec: Cases/Functional/customers.md [145]
     *   "A customer cannot save the billing address for a transaction on another customer's order."
     */
    public function customerCannotSaveBillingAddressForAnotherCustomersTransaction(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        (new CustomerFactory())->createForUser($userId);
        $foreign = (new CustomerFactory())->create(['email' => 'foreign-billing-order@example.test']);
        $foreignOrder = (new OrderFactory())->create(['customer_id' => $foreign->id]);
        $foreignTransaction = (new OrderTransactionFactory())->create($foreignOrder->id);

        [$status, $body] = $I->put(
            '/fluent-cart/v2/customer-profile/orders/' . (string) $foreignTransaction->uuid . '/billing-address',
            [
                'name'       => 'Unauthorized Billing',
                'address_1'  => '41 Foreign Lane',
                'city'       => 'Austin',
                'state'      => 'TX',
                'postcode'   => '78701',
                'country'    => 'US',
                'vat_tax_id' => 'US-TAX-145',
            ]
        );

        Assert::assertSame(422, $status, 'cross-customer transaction billing-address save must return HTTP 422. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString('Order not found', (string) ($body['message'] ?? ''), 'cross-customer transaction billing-address rejection must not disclose the foreign order');
        Assert::assertSame(0, OrderAddress::query()->where('order_id', $foreignOrder->id)->count(), 'cross-customer transaction billing-address rejection must not create a foreign order address');
        Assert::assertSame(0, OrderMeta::query()
            ->where('order_id', $foreignOrder->id)
            ->where('meta_key', 'vat_tax_id')
            ->count(), 'cross-customer transaction billing-address rejection must not create foreign VAT meta');
    }
}

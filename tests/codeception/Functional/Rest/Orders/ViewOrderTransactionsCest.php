<?php

namespace Tests\Functional\Rest\Orders;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/orders.md → "Viewing order transactions" section, items 18-19.
 */
class ViewOrderTransactionsCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/orders.md [18]
     *   "Admin can list the transactions belonging to an order."
     */
    public function adminCanListTransactionsBelongingToOrder(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'list-txns@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'invoice_no'  => 'INV-LIST-TXNS',
        ]);
        $otherOrder = (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'invoice_no'  => 'INV-OTHER-LIST-TXNS',
        ]);

        $txnA = (new OrderTransactionFactory())->create((int) $order->id, [
            'transaction_hash' => 'list-txn-a-' . uniqid('', true),
        ]);
        $txnB = (new OrderTransactionFactory())->create((int) $order->id, [
            'transaction_hash' => 'list-txn-b-' . uniqid('', true),
        ]);
        $foreignTxn = (new OrderTransactionFactory())->create((int) $otherOrder->id, [
            'transaction_hash' => 'list-foreign-txn-' . uniqid('', true),
        ]);

        [, $body] = $I->get('/fluent-cart/v2/orders/' . (int) $order->id . '/transactions');

        $I->seeResponseCodeIs(200);
        $payload = (array) ($body['order'] ?? []);
        Assert::assertSame((int) $order->id, (int) ($payload['id'] ?? 0), 'response must address the requested order');

        $txnRows = (array) ($payload['transactions'] ?? []);
        $txnIds  = array_map('intval', array_column($txnRows, 'id'));
        Assert::assertContains((int) $txnA->id, $txnIds, 'response transactions must include first seeded transaction');
        Assert::assertContains((int) $txnB->id, $txnIds, 'response transactions must include second seeded transaction');
        Assert::assertNotContains((int) $foreignTxn->id, $txnIds, 'response transactions must not include a transaction belonging to a different order');

        $orderIdsOnTxns = array_unique(array_map('intval', array_column($txnRows, 'order_id')));
        Assert::assertSame([(int) $order->id], $orderIdsOnTxns, 'every listed transaction must belong to the requested order');
    }

    /**
     * Spec: Cases/Functional/orders.md [19]
     *   "Admin can open a single transaction that belongs to a given order."
     */
    public function adminCanOpenSingleTransactionThatBelongsToGivenOrder(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'view-single-txn@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'invoice_no'  => 'INV-VIEW-SINGLE-TXN',
        ]);

        $requested = (new OrderTransactionFactory())->create((int) $order->id, [
            'transaction_hash' => 'view-single-txn-requested-' . uniqid('', true),
        ]);
        $sibling = (new OrderTransactionFactory())->create((int) $order->id, [
            'transaction_hash' => 'view-single-txn-sibling-' . uniqid('', true),
        ]);

        [, $body] = $I->get(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/transactions/' . (int) $requested->id
        );

        $I->seeResponseCodeIs(200);
        $payload = (array) ($body['order'] ?? []);
        Assert::assertSame((int) $order->id, (int) ($payload['id'] ?? 0), 'response must address the order containing the transaction');

        $txnRows = (array) ($payload['transactions'] ?? []);
        $txnIds  = array_map('intval', array_column($txnRows, 'id'));
        Assert::assertContains((int) $requested->id, $txnIds, 'response transactions must include the requested single transaction id');
        Assert::assertNotContains(
            (int) $sibling->id,
            $txnIds,
            'response must NOT include sibling transactions on the same order — the URL path identifies a single transaction, not the whole transactions list'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [19]
     *   A transaction id that belongs to a different order must not resolve through
     *   this order's path.
     */
    public function singleTransactionOfAnotherOrderIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'foreign-txn@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'invoice_no'  => 'INV-FOREIGN-TXN-HOST',
        ]);
        $otherOrder = (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'invoice_no'  => 'INV-FOREIGN-TXN-OWNER',
        ]);

        $foreignTxn = (new OrderTransactionFactory())->create((int) $otherOrder->id, [
            'transaction_hash' => 'foreign-txn-' . uniqid('', true),
        ]);

        [, $body] = $I->get(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/transactions/' . (int) $foreignTxn->id
        );

        $I->seeResponseCodeIs(404);
        Assert::assertArrayNotHasKey('order', (array) $body, 'a cross-order transaction request must not return order details');
    }

    /**
     * Spec: Cases/Functional/orders.md [19]
     */
    public function unknownSingleTransactionIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'unknown-txn@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'invoice_no'  => 'INV-UNKNOWN-TXN',
        ]);

        [, $body] = $I->get(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/transactions/999999'
        );

        $I->seeResponseCodeIs(404);
        Assert::assertArrayNotHasKey('order', (array) $body, 'an unknown transaction id must not return order details');
    }
}

<?php

namespace Tests\Functional\Rest\Orders;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\FunctionalTester;

class ZeroDecimalRefundCest
{
    public function adminCannotRefundFractionalYen(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $order = (new OrderFactory())->create([
            'customer_id' => (new CustomerFactory())->create()->id, 'currency' => 'JPY',
            'total_amount' => 20000, 'total_paid' => 20000, 'payment_status' => Status::PAYMENT_PAID,
        ]);
        $transaction = (new OrderTransactionFactory())->create($order->id, [
            'currency' => 'JPY', 'total' => 20000, 'status' => Status::TRANSACTION_SUCCEEDED,
        ]);
        [, $body] = $I->post('/fluent-cart/v2/orders/' . $order->id . '/refund', [
            'refund_info' => ['transaction_id' => $transaction->id, 'amount' => 10050],
        ]);
        $I->seeResponseCodeIs(422);
        Assert::assertStringContainsString('smallest unit', wp_json_encode($body), 'The response explains the currency constraint');
        Assert::assertSame(1, OrderTransaction::query()->where('order_id', $order->id)->count(), 'No refund transaction is recorded');
        Assert::assertEquals(0, $order->fresh()->total_refund, 'The order balance stays unchanged');
    }
}

<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Hooks\Handlers\ShortCodes\ReceiptHandler;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderAddressFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\FunctionalTester;

/**
 * An order whose billing address holds a smartcode is opened on the receipt
 * with no session: the code must render as typed, not resolve.
 *
 * Checkout strips typed smartcodes before validation (CheckoutApi::stripSmartCodes),
 * so the row is seeded directly; the receipt guard is what this exercises.
 */
class ReceiptSmartCodeInjectionCest
{
    private const TYPED = '{{order.customer.wpUser.user_pass}}';

    public function _after(FunctionalTester $I): void
    {
        App::request()->set('trx_hash', null);
    }

    public function guestTypedSmartCodeDoesNotResolveOnTheReceipt(FunctionalTester $I): void
    {
        $victim = (new CustomerFactory())->createWithUser();
        $hash = get_user_by('ID', $victim->user_id)->user_pass;
        Assert::assertNotEmpty($hash, 'fixture: the linked WP user must have a password hash');

        $order = (new OrderFactory())->create([
            'customer_id'    => $victim->id,
            'status'         => Status::ORDER_PROCESSING,
            'payment_status' => Status::PAYMENT_PAID,
            'total_paid'     => 1500,
            'total_amount'   => 1500,
        ]);
        (new OrderAddressFactory())->create($order->id, ['type' => 'billing', 'address_1' => self::TYPED]);
        $transaction = (new OrderTransactionFactory())->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 1500,
        ]);
        Assert::assertNotEmpty($transaction->uuid, 'fixture: the transaction must have a receipt hash');

        $I->logout();
        App::request()->set('trx_hash', (string) $transaction->uuid);
        ob_start();
        $returned = (new ReceiptHandler())->renderRedirectPage(['type' => 'receipt']);
        $html = ob_get_clean() . $returned;

        Assert::assertStringContainsString('Bill To', $html, 'the receipt itself must render');
        Assert::assertStringNotContainsString($hash, $html, 'the receipt must not disclose the password hash');
        Assert::assertStringContainsString(self::TYPED, $html, 'the typed text must render as typed');
    }
}

<?php

namespace Tests\Integration\Services;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Hooks\Handlers\ShortCodes\ReceiptHandler;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderAddress;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;

/**
 * The receipt and thank-you pages must not run the smartcode parser over
 * customer-typed text: a {{…}} in a billing field is shown as typed.
 */
class ReceiptSmartCodeReparseTest extends WPTestCase
{
    private const TYPED = '{{order.customer.wpUser.user_pass}}';

    public function tearDown(): void
    {
        App::request()->set('order_hash', null);
        parent::tearDown();
    }

    private function paidOrderWithTypedSmartCode(): Order
    {
        $customer = (new CustomerFactory())->createWithUser();
        $order = (new OrderFactory())->withAddresses()->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PAID,
        ]);

        (new OrderTransactionFactory())->create($order->id, ['status' => Status::TRANSACTION_SUCCEEDED]);

        OrderAddress::query()
            ->where('order_id', $order->id)
            ->where('type', 'billing')
            ->update(['address_1' => self::TYPED]);

        return Order::query()->with('customer')->find($order->id);
    }

    private function render(Order $order, string $type): string
    {
        App::request()->set('order_hash', $order->uuid);

        ob_start();
        $returned = (new ReceiptHandler())->renderRedirectPage(['type' => $type]);

        return ob_get_clean() . $returned;
    }

    public function test_receipt_shows_a_typed_smartcode_verbatim(): void
    {
        $order = $this->paidOrderWithTypedSmartCode();
        $hash = get_user_by('ID', $order->customer->user_id)->user_pass;

        $html = $this->render($order, 'receipt');

        $this->assertStringContainsString(self::TYPED, $html, 'the billing address must render as typed');
        $this->assertStringNotContainsString($hash, $html);
    }

    public function test_thank_you_page_shows_a_typed_smartcode_verbatim(): void
    {
        $order = $this->paidOrderWithTypedSmartCode();
        $hash = get_user_by('ID', $order->customer->user_id)->user_pass;

        $html = $this->render($order, '');

        $this->assertStringContainsString(self::TYPED, $html, 'the billing address must render as typed');
        $this->assertStringNotContainsString($hash, $html);
    }

    public function test_admin_thank_you_instructions_still_resolve_smartcodes(): void
    {
        $order = $this->paidOrderWithTypedSmartCode();
        $settings = GatewayManager::getInstance('offline_payment')->settings;
        $original = $settings->settings;
        $settings->settings['thank_you_page_instructions'] = '<p>Reference: #{{order.id}}</p>';

        try {
            $html = $this->render($order, '');
        } finally {
            $settings->settings = $original;
        }

        $this->assertStringContainsString('Reference: #' . $order->id, $html);
    }
}

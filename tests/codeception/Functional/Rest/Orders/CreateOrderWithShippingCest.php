<?php

namespace Tests\Functional\Rest\Orders;

use FluentCart\App\App;
use FluentCart\App\Models\Order;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ShippingMethodFactory;
use Tests\Support\Factory\ShippingZoneFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\BuildsManualShippingOrders;

class CreateOrderWithShippingCest
{
    use BuildsManualShippingOrders;

    private $mails = [];
    private $mailCatcher;
    private $gatewaySettings;

    public function _before(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $this->gatewaySettings = App::gateway('offline_payment')->settings->settings;
        App::gateway('offline_payment')->settings->settings['is_active'] = 'yes';
        $this->mails = [];
        $this->mailCatcher = function ($result, $mail) {
            if (in_array('manual-shipping@example.test', (array) $mail['to'], true)) {
                $this->mails[] = $mail;
            }
            return true;
        };
        add_filter('pre_wp_mail', $this->mailCatcher, PHP_INT_MAX, 2);
    }

    public function _after(FunctionalTester $I): void
    {
        remove_filter('pre_wp_mail', $this->mailCatcher, PHP_INT_MAX);
        App::gateway('offline_payment')->settings->settings = $this->gatewaySettings;
        $I->disarmTaxModule();
    }

    public function firstCustomerEmailIncludesTheCustomShippingCharge(FunctionalTester $I): void
    {
        [$status, $body] = $I->createOrder($this->manualShippingPayload(1999));
        Assert::assertSame(200, $status, wp_json_encode($body));
        $order = Order::find($body['order_id']);
        Assert::assertSame(1999, (int) $order->shipping_total, 'The order stores the shipping charge in cents.');
        Assert::assertSame(11999, (int) $order->total_amount, 'The total includes shipping.');
        Assert::assertCount(1, $this->mails, 'The create request sends one customer confirmation.');
        Assert::assertStringContainsString('19.99', $this->mails[0]['message'], 'The first email shows shipping.');
        Assert::assertStringContainsString('119.99', $this->mails[0]['message'], 'The first email shows the final total.');
    }

    public function customShippingIsTaxedBeforeTheFirstEmail(FunctionalTester $I): void
    {
        $this->enableManualShippingTax($I);
        $payload = $this->manualShippingPayload();
        [, $preview] = $I->post('/fluent-cart/v2/orders/price', $payload);
        Assert::assertSame('physical', $preview['order_items'][0]['fulfillment_type'] ?? '', wp_json_encode($preview));
        [$status, $body] = $I->createOrder($payload);
        Assert::assertSame(200, $status, wp_json_encode($body));
        $order = Order::find($body['order_id']);
        Assert::assertSame(100, (int) $order->shipping_tax, 'Shipping receives the applicable ten percent tax.');
        Assert::assertSame(12100, (int) $order->total_amount, 'The total includes products, shipping and both taxes.');
        Assert::assertCount(1, $this->mails, 'The first customer email is sent once.');
        Assert::assertStringContainsString('121.00', $this->mails[0]['message'], 'The first email includes shipping tax.');
    }

    public function zeroShippingKeepsTheProductTotal(FunctionalTester $I): void
    {
        [$status, $body] = $I->createOrder($this->manualShippingPayload(0));
        Assert::assertSame(200, $status, wp_json_encode($body));
        $order = Order::find($body['order_id']);
        Assert::assertSame(0, (int) $order->shipping_total, 'Zero shipping remains valid.');
        Assert::assertSame(10000, (int) $order->total_amount, 'No shipping is added.');
        Assert::assertCount(1, $this->mails, 'Free shipping does not suppress the confirmation.');
    }

    public function editingAFreeShippingItemKeepsTheManualShippingTax(FunctionalTester $I): void
    {
        $this->enableManualShippingTax($I);
        $payload = $this->manualShippingPayload(1000, ['free_shipping' => 'yes']);
        [$previewStatus, $preview] = $I->post('/fluent-cart/v2/orders/price', $payload);
        Assert::assertSame(200, $previewStatus, wp_json_encode($preview));
        $payload['quote_revision'] = $preview['quote_revision'];
        $payload['total_amount'] = $preview['total_amount'];
        [$status, $body] = $I->createOrder($payload);
        Assert::assertSame(200, $status, wp_json_encode($body));
        $order = Order::find($body['order_id']);
        Assert::assertSame(100, (int) $order->shipping_tax, 'Creation taxes the explicit manual charge.');
        Assert::assertSame(12100, (int) $order->total_amount, 'Creation includes product and shipping tax.');
        Assert::assertCount(1, $this->mails, 'Creation sends one confirmation.');
        Assert::assertStringContainsString('121.00', $this->mails[0]['message'], 'The first email shows the taxed total.');

        [$editStatus, $editBody] = $I->post('/fluent-cart/v2/orders/' . $order->id, [
            'customer_id' => $order->customer_id,
            'order_items' => $order->order_items->toArray(),
            'shipping_total' => 1000,
            'subtotal' => 10000,
            'total_amount' => 12100,
            'note' => 'Reviewed shipping',
            'config' => [],
        ]);
        Assert::assertSame(200, $editStatus, wp_json_encode($editBody));
        $order->refresh();
        Assert::assertSame('Reviewed shipping', $order->note, 'The edit is saved on the same order.');
        Assert::assertSame(1000, (int) $order->shipping_total, 'Editing preserves the manual charge.');
        Assert::assertSame(100, (int) $order->shipping_tax, 'Editing keeps the shipping tax from creation.');
        Assert::assertSame(12100, (int) $order->total_amount, 'The saved total still matches the first email.');
        Assert::assertSame('manual', $order->config['shipping_source'] ?? '', 'The shipping source survives a partial config update.');
    }

    public function selectingAZoneMethodReplacesTheManualShippingSource(FunctionalTester $I): void
    {
        [$status, $body] = $I->createOrder($this->manualShippingPayload());
        Assert::assertSame(200, $status, wp_json_encode($body));
        $order = Order::find($body['order_id']);
        Assert::assertSame('manual', $order->config['shipping_source'] ?? '', 'Creation records the manual charge source.');
        $zone = (new ShippingZoneFactory())->create(['region' => 'US']);
        $method = (new ShippingMethodFactory())->create(['zone_id' => $zone->id, 'amount' => 5]);

        [$editStatus, $editBody] = $I->post('/fluent-cart/v2/orders/' . $order->id, [
            'customer_id' => $order->customer_id,
            'order_items' => $order->order_items->toArray(),
            'shipping_total' => 500,
            'shipping' => ['id' => $method->id],
            'subtotal' => 10000,
            'total_amount' => $order->total_amount,
        ]);
        Assert::assertSame(200, $editStatus, wp_json_encode($editBody));
        $order->refresh();
        Assert::assertSame('zone', $order->config['shipping_source'] ?? '', 'The selected zone replaces the manual override.');
        Assert::assertSame(500, (int) $order->shipping_total, 'The zone charge is saved.');
        Assert::assertSame(10500, (int) $order->total_amount, 'The total uses the replacement charge.');
    }
}

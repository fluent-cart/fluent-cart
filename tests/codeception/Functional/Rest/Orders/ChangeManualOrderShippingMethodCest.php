<?php

namespace Tests\Functional\Rest\Orders;

use FluentCart\App\App;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\Renderer\Receipt\ReceiptRenderer;
use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\BuildsManualShippingOrders;

class ChangeManualOrderShippingMethodCest
{
    use BuildsManualShippingOrders;

    private $gatewaySettings;

    public function _before(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $this->gatewaySettings = App::gateway('offline_payment')->settings->settings;
        App::gateway('offline_payment')->settings->settings['is_active'] = 'yes';
        add_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);
    }

    public function _after(FunctionalTester $I): void
    {
        remove_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);
        App::gateway('offline_payment')->settings->settings = $this->gatewaySettings;
    }

    public function changingTheMethodUpdatesEveryShippingIdentity(FunctionalTester $I): void
    {
        $first = $this->manualShippingMethod(['title' => 'Original delivery']);
        $second = $this->manualShippingMethod(['title' => 'Express delivery', 'amount' => 15]);
        $payload = $this->manualShippingPayload();
        $payload['shipping_method_id'] = $first->id;
        [$status, $body] = $I->createOrder($payload);
        Assert::assertSame(200, $status, wp_json_encode($body));
        $order = Order::find($body['order_id']);

        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . $order->id, [
            'customer_id' => $order->customer_id,
            'order_items' => $order->order_items->toArray(),
            'shipping' => ['id' => $second->id, 'title' => $first->title],
            'shipping_total' => 1500,
            'subtotal' => $order->subtotal,
            'total_amount' => $order->total_amount,
            'config' => $order->config,
        ]);
        Assert::assertSame(200, $status, wp_json_encode($body));
        $order->refresh();
        Assert::assertSame((int) $second->id, (int) $order->getMeta('order_shipping')['id'], 'Metadata stores the replacement.');
        Assert::assertSame((int) $second->id, (int) $order->config['shipping_method_id'], 'Config stores the replacement.');
        Assert::assertSame('Express delivery', $order->config['shipping_method_title'], 'Config stores the replacement title.');
        Assert::assertSame('manual', $order->config['shipping_carrier'], 'The selected zone method uses the manual carrier.');
        Assert::assertSame('zone', $order->config['shipping_source'], 'The replacement keeps zone tax allocation.');
        Assert::assertSame(1500, (int) $order->shipping_total, 'The replacement charge is stored.');
        Assert::assertSame(11500, (int) $order->total_amount, 'The total includes the replacement charge.');
        [$status, $details] = $I->get('/fluent-cart/v2/orders/' . $order->id);
        Assert::assertSame(200, $status, wp_json_encode($details));
        Assert::assertSame('Express delivery', $details['checkout_shipping']['method_title'], 'Admin details identify the replacement.');

        ob_start();
        (new ReceiptRenderer(['order' => $order]))->renderShipping();
        $receipt = ob_get_clean();
        Assert::assertStringContainsString('Express delivery', $receipt, 'The receipt identifies the replacement.');
        Assert::assertStringNotContainsString('Original delivery', $receipt, 'The receipt no longer names the original method.');

        $userId = $I->loginAs('customer');
        $order->customer->update(['user_id' => $userId, 'email' => get_userdata($userId)->user_email]);
        [$status, $details] = $I->get('/fluent-cart/v2/customer-profile/orders/' . $order->uuid);
        Assert::assertSame(200, $status, wp_json_encode($details));
        Assert::assertSame('Express delivery', $details['order']['shipping_method_title'], 'Customer details identify the replacement.');
    }

    public function explicitlyClearingTheMethodRemovesItsIdentity(FunctionalTester $I): void
    {
        $method = $this->manualShippingMethod();
        $payload = $this->manualShippingPayload();
        $payload['shipping_method_id'] = $method->id;
        [$status, $body] = $I->createOrder($payload);
        Assert::assertSame(200, $status, wp_json_encode($body));
        $order = Order::find($body['order_id']);
        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . $order->id, [
            'customer_id' => $order->customer_id,
            'order_items' => $order->order_items->toArray(),
            'shipping' => ['id' => 0],
            'shipping_total' => 700,
            'subtotal' => $order->subtotal,
            'total_amount' => $order->total_amount,
            'config' => $order->config,
        ]);
        Assert::assertSame(200, $status, wp_json_encode($body));
        $order->refresh();
        Assert::assertEmpty($order->config['shipping_method_id'] ?? null, 'The method ID is cleared.');
        Assert::assertEmpty($order->config['shipping_method_title'] ?? null, 'The method title is cleared.');
        Assert::assertEmpty($order->config['shipping_carrier'] ?? null, 'The carrier is cleared.');
        Assert::assertSame('manual', $order->config['shipping_source'], 'An explicit charge uses manual tax allocation.');
        Assert::assertEmpty($order->getMeta('order_shipping')['id'] ?? null, 'Metadata no longer names the previous method.');
        Assert::assertSame(700, (int) $order->shipping_total, 'The explicit charge remains.');
        [$status, $details] = $I->get('/fluent-cart/v2/orders/' . $order->id);
        Assert::assertSame(200, $status, wp_json_encode($details));
        Assert::assertNull($details['checkout_shipping'], 'Admin details do not restore the old method.');
    }

    public function anEditWithoutShippingKeepsTheSelectedMethod(FunctionalTester $I): void
    {
        $method = $this->manualShippingMethod();
        $payload = $this->manualShippingPayload();
        $payload['shipping_method_id'] = $method->id;
        [$status, $body] = $I->createOrder($payload);
        Assert::assertSame(200, $status, wp_json_encode($body));
        $order = Order::find($body['order_id']);
        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . $order->id, [
            'customer_id' => $order->customer_id,
            'order_items' => $order->order_items->toArray(),
            'note' => 'Reviewed',
            'config' => [],
        ]);
        Assert::assertSame(200, $status, wp_json_encode($body));
        $order->refresh();
        Assert::assertSame((int) $method->id, (int) $order->config['shipping_method_id'], 'An unrelated edit keeps the method.');
        Assert::assertSame('Standard delivery', $order->config['shipping_method_title'], 'An unrelated edit keeps the title.');
        Assert::assertSame('manual', $order->config['shipping_carrier'], 'An unrelated edit keeps the carrier.');
        Assert::assertSame(1000, (int) $order->shipping_total, 'An unrelated edit keeps the charge.');
    }

    public function anUnknownReplacementDoesNotChangeTheOrder(FunctionalTester $I): void
    {
        $method = $this->manualShippingMethod();
        $payload = $this->manualShippingPayload();
        $payload['shipping_method_id'] = $method->id;
        [$status, $body] = $I->createOrder($payload);
        Assert::assertSame(200, $status, wp_json_encode($body));
        $order = Order::find($body['order_id']);
        [$status] = $I->post('/fluent-cart/v2/orders/' . $order->id, [
            'customer_id' => $order->customer_id,
            'order_items' => $order->order_items->toArray(),
            'shipping' => ['id' => 999999],
            'shipping_total' => 1500,
            'total_amount' => $order->total_amount,
        ]);
        Assert::assertSame(422, $status, 'Unknown methods are rejected before saving.');
        $order->refresh();
        Assert::assertSame((int) $method->id, (int) $order->config['shipping_method_id'], 'The original method remains.');
        Assert::assertSame((int) $method->id, (int) $order->getMeta('order_shipping')['id'], 'The original metadata remains.');
        Assert::assertSame(1000, (int) $order->shipping_total, 'The original charge remains.');
    }

    public function aMethodDisabledAfterPricingCannotReplaceTheSavedMethod(FunctionalTester $I): void
    {
        $first = $this->manualShippingMethod();
        $second = $this->manualShippingMethod(['title' => 'Express delivery', 'amount' => 15]);
        $payload = $this->manualShippingPayload();
        $payload['shipping_method_id'] = $first->id;
        [$status, $body] = $I->createOrder($payload);
        Assert::assertSame(200, $status, wp_json_encode($body));
        $order = Order::find($body['order_id']);
        [$status, $quote] = $I->post('/fluent-cart/v2/orders/calculate-shipping', [
            'shipping_id' => $second->id,
            'order_items' => [['id' => $payload['order_items'][0]['object_id'], 'quantity' => 1]],
        ]);
        Assert::assertSame(200, $status, wp_json_encode($quote));
        $second->update(['is_enabled' => 0]);
        [$status] = $I->post('/fluent-cart/v2/orders/' . $order->id, [
            'customer_id' => $order->customer_id,
            'order_items' => $order->order_items->toArray(),
            'shipping' => ['id' => $second->id],
            'shipping_total' => $quote['shipping_charge'],
            'total_amount' => $order->total_amount,
            'note' => 'Must not save',
        ]);
        Assert::assertSame(422, $status, 'A disabled replacement must be rejected even after a successful preview.');
        $order->refresh();
        Assert::assertSame((int) $first->id, (int) $order->config['shipping_method_id'], 'The original identity remains.');
        Assert::assertSame((int) $first->id, (int) $order->getMeta('order_shipping')['id'], 'The original metadata remains.');
        Assert::assertSame(1000, (int) $order->shipping_total, 'The original charge remains.');
        Assert::assertSame(11000, (int) $order->total_amount, 'The original total remains.');
        Assert::assertNotSame('Must not save', $order->note, 'The rejected request saves no other changes.');
    }

    public function anUnchangedDisabledMethodDoesNotBlockAnOrdinaryEdit(FunctionalTester $I): void
    {
        $method = $this->manualShippingMethod();
        $payload = $this->manualShippingPayload();
        $payload['shipping_method_id'] = $method->id;
        [$status, $body] = $I->createOrder($payload);
        Assert::assertSame(200, $status, wp_json_encode($body));
        $order = Order::find($body['order_id']);
        $originalConfig = $order->config;
        $originalMeta = $order->getMeta('order_shipping');
        $method->update(['is_enabled' => 0, 'title' => 'Retired delivery']);
        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . $order->id, [
            'customer_id' => $order->customer_id,
            'order_items' => $order->order_items->toArray(),
            'shipping' => ['id' => $method->id],
            'shipping_total' => $order->shipping_total,
            'total_amount' => $order->total_amount,
            'note' => 'Reviewed',
        ]);
        Assert::assertSame(200, $status, wp_json_encode($body));
        $order->refresh();
        Assert::assertSame('Reviewed', $order->note, 'An ordinary edit still succeeds.');
        Assert::assertSame($originalConfig, $order->config, 'The saved shipping identity is preserved.');
        Assert::assertSame($originalMeta, $order->getMeta('order_shipping'), 'The saved shipping metadata is preserved.');
        Assert::assertSame(1000, (int) $order->shipping_total, 'The saved charge is preserved.');
    }

    public function aDisabledCurrentMethodCannotAuthorizeAChangedCharge(FunctionalTester $I): void
    {
        $method = $this->manualShippingMethod();
        $payload = $this->manualShippingPayload();
        $payload['shipping_method_id'] = $method->id;
        [$status, $body] = $I->createOrder($payload);
        Assert::assertSame(200, $status, wp_json_encode($body));
        $order = Order::find($body['order_id']);
        $method->update(['is_enabled' => 0]);
        [$status] = $I->post('/fluent-cart/v2/orders/' . $order->id, [
            'customer_id' => $order->customer_id,
            'order_items' => $order->order_items->toArray(),
            'shipping' => ['id' => $method->id],
            'shipping_total' => 1500,
            'total_amount' => $order->total_amount,
        ]);
        Assert::assertSame(422, $status, 'The disabled-method exception only preserves an unchanged charge.');
        Assert::assertSame(1000, (int) $order->fresh()->shipping_total, 'The rejected charge is not saved.');
    }

}

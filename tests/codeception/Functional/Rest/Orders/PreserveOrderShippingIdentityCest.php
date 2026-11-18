<?php

namespace Tests\Functional\Rest\Orders;

use FluentCart\App\App;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\Renderer\Receipt\ReceiptRenderer;
use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\BuildsManualShippingOrders;

class PreserveOrderShippingIdentityCest
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

    public function quantityEditsKeepTheOriginalEnabledMethodName(FunctionalTester $I): void
    {
        foreach ([false, true] as $legacyMetadata) {
            $method = $this->manualShippingMethod(['title' => 'Original delivery']);
            $payload = $this->manualShippingPayload();
            $payload['shipping_method_id'] = $method->id;
            [$status, $body] = $I->createOrder($payload);
            Assert::assertSame(200, $status, wp_json_encode($body));
            $order = Order::find($body['order_id']);
            if ($legacyMetadata) {
                $config = $order->config;
                unset($config['shipping_method_id'], $config['shipping_method_title'], $config['shipping_carrier']);
                $order->update(['config' => $config]);
            }
            $originalConfig = $order->config;
            $originalMeta = $order->getMeta('order_shipping');
            $method->update(['title' => 'Renamed delivery']);
            $items = $order->order_items->toArray();
            $items[0]['quantity'] = 2;
            $items[0]['subtotal'] = 20000;
            $items[0]['line_total'] = 20000;
            [$status, $body] = $I->post('/fluent-cart/v2/orders/' . $order->id, [
                'customer_id' => $order->customer_id,
                'order_items' => $items,
                'shipping' => ['id' => (string) $method->id],
                'shipping_total' => 1000,
                'subtotal' => 20000,
                'total_amount' => 21000,
            ]);
            Assert::assertSame(200, $status, wp_json_encode($body));
            $order->refresh();
            Assert::assertSame(2, (int) $order->order_items->first()->quantity, 'The quantity edit is saved.');
            Assert::assertSame(21000, (int) $order->total_amount, 'The total reflects the quantity edit.');
            Assert::assertSame($originalConfig, $order->config, 'The saved shipping identity is preserved.');
            Assert::assertSame($originalMeta, $order->getMeta('order_shipping'), 'The saved shipping metadata is preserved.');
            [$status, $details] = $I->get('/fluent-cart/v2/orders/' . $order->id);
            Assert::assertSame(200, $status, wp_json_encode($details));
            Assert::assertSame('Original delivery', $details['checkout_shipping']['method_title'], 'Order details keep the original title.');
            ob_start();
            (new ReceiptRenderer(['order' => $order]))->renderShipping();
            $receipt = ob_get_clean();
            if (!$legacyMetadata) {
                Assert::assertStringContainsString('Original delivery', $receipt, 'The receipt keeps the original title.');
            }
            Assert::assertStringNotContainsString('Renamed delivery', $receipt, 'The receipt does not use the current catalog title.');
        }
    }

    public function ordinaryEditsPreserveLiveCarrierMetadata(FunctionalTester $I): void
    {
        foreach ([false, true] as $hasMetadata) {
            [$status, $body] = $I->createOrder($this->manualShippingPayload());
            Assert::assertSame(200, $status, wp_json_encode($body));
            $order = Order::find($body['order_id']);
            $carrierId = 'carrier:shippo:usps_priority';
            $order->update(['config' => array_merge($order->config, [
                'shipping_method_id' => $carrierId,
                'shipping_method_title' => 'Priority Mail',
                'shipping_carrier' => 'shippo',
            ])]);
            if ($hasMetadata) {
                $order->updateMeta('order_shipping', ['id' => $carrierId, 'title' => 'Priority Mail']);
            } else {
                $order->deleteMeta('order_shipping');
            }
            $originalConfig = $order->config;
            $originalMeta = $order->getMeta('order_shipping');
            [$status, $body] = $I->post('/fluent-cart/v2/orders/' . $order->id, [
                'customer_id' => $order->customer_id,
                'order_items' => $order->order_items->toArray(),
                'shipping' => ['id' => $carrierId],
                'shipping_total' => $order->shipping_total,
                'total_amount' => $order->total_amount,
                'note' => 'Reviewed',
            ]);
            Assert::assertSame(200, $status, wp_json_encode($body));
            $order->refresh();
            Assert::assertSame('Reviewed', $order->note, 'The ordinary edit is saved.');
            Assert::assertEquals($originalConfig, $order->config, 'The carrier identity is preserved.');
            Assert::assertSame($originalMeta, $order->getMeta('order_shipping'), 'Carrier metadata is neither created nor replaced.');
        }
    }
}

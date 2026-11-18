<?php

namespace Tests\Functional\Rest\Orders;

use FluentCart\App\App;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\ProductDetail;
use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;
use Tests\Support\Factory\ShippingClassFactory;
use Tests\Support\Traits\BuildsManualShippingOrders;
use Tests\Support\Traits\MakesMultiCurrencyAvailable;
use Tests\Support\RateFixture;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;

class CreateOrderWithShippingMethodCest
{
    use BuildsManualShippingOrders;
    use MakesMultiCurrencyAvailable;

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
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
    }

    public function selectedMethodIsPricedAndNamedInTheFirstEmail(FunctionalTester $I): void
    {
        $method = $this->manualShippingMethod();
        $payload = $this->manualShippingPayload(0);
        $payload['shipping_method_id'] = $method->id;
        $payload['order_items'][0]['fulfillment_type'] = 'digital';
        [$previewStatus, $preview] = $I->post('/fluent-cart/v2/orders/price', $payload);
        Assert::assertSame(200, $previewStatus, wp_json_encode($preview));
        Assert::assertSame(1000, $preview['shipping_total'], 'The server calculates the method charge.');
        Assert::assertSame('physical', $preview['order_items'][0]['fulfillment_type'], 'The catalog determines fulfillment.');
        Assert::assertSame('Standard delivery', $preview['shipping']['title'], 'The preview names the selected method.');
        $payload['order_items'][0] = array_merge($payload['order_items'][0], array_intersect_key(
            $preview['order_items'][0], array_flip(['unit_price', 'price', 'subtotal', 'discount_total', 'coupon_discount', 'manual_discount', 'other_info', 'shipping_charge'])
        ));
        $payload['shipping_total'] = $preview['shipping_total'];
        $payload['quote_revision'] = $preview['quote_revision'];
        $payload['total_amount'] = $preview['total_amount'];
        [$status, $body] = $I->createOrder($payload);
        Assert::assertSame(200, $status, wp_json_encode($body));
        $order = Order::find($body['order_id']);
        Assert::assertSame(1000, (int) $order->shipping_total, 'The save calculates shipping again.');
        Assert::assertSame(11000, (int) $order->total_amount, 'The total includes the selected rate.');
        Assert::assertSame((int) $method->id, (int) $order->config['shipping_method_id'], 'The selected method is stored.');
        Assert::assertSame('zone', $order->config['shipping_source'], 'Tax uses the zone allocation policy.');
        Assert::assertSame('Standard delivery', $order->getMeta('order_shipping')['title'], 'The edit screen can reload the method.');
        Assert::assertCount(1, $this->mails, 'The create request sends one confirmation.');
        Assert::assertStringContainsString('Standard delivery', $this->mails[0]['message'], 'The first email names the method.');
        Assert::assertStringContainsString('110.00', $this->mails[0]['message'], 'The first email includes the charge.');
    }

    public function changedMethodChargeRequiresANewPreviewBeforeSaving(FunctionalTester $I): void
    {
        $method = $this->manualShippingMethod();
        $payload = $this->manualShippingPayload(0);
        $payload['shipping_method_id'] = $method->id;
        [, $preview] = $I->post('/fluent-cart/v2/orders/price', $payload);
        $method->amount = 15;
        $method->save();
        [$status] = $I->post('/fluent-cart/v2/orders', array_merge($payload, [
            'quote_revision' => $preview['quote_revision'],
            'total_amount' => $preview['total_amount'],
        ]));
        Assert::assertSame(422, $status, 'A changed rate must be reviewed before an order is created.');
        Assert::assertSame(0, Order::query()->where('customer_id', $payload['customer_id'])->count(), 'No order is saved at the stale rate.');
        Assert::assertCount(0, $this->mails, 'No confirmation is sent for the rejected save.');
    }

    public function disabledMethodIsRejectedBeforeCreatingAnOrder(FunctionalTester $I): void
    {
        $method = $this->manualShippingMethod(['is_enabled' => 0]);
        $payload = $this->manualShippingPayload(0);
        $payload['shipping_method_id'] = $method->id;
        [$status] = $I->createOrder($payload);
        Assert::assertSame(422, $status, 'An unavailable method cannot be selected.');
        Assert::assertSame(0, Order::query()->where('customer_id', $payload['customer_id'])->count(), 'No order is created.');
        Assert::assertCount(0, $this->mails, 'No confirmation is sent.');
    }

    public function perItemMethodRecalculatesWhenQuantityChanges(FunctionalTester $I): void
    {
        $method = $this->manualShippingMethod(['amount' => 5, 'settings' => ['configure_rate' => 'per_item']]);
        $payload = $this->manualShippingPayload(0);
        $payload['shipping_method_id'] = $method->id;
        $payload['order_items'][0]['quantity'] = 3;
        [$status, $body] = $I->createOrder($payload);
        Assert::assertSame(200, $status, wp_json_encode($body));
        $order = Order::find($body['order_id']);
        Assert::assertSame(1500, (int) $order->shipping_total, 'Each of the three items is charged once.');
        Assert::assertSame(31500, (int) $order->total_amount, 'Products and shipping use the same quantity.');
    }

    public function percentageShippingUsesTheDiscountedProductTotal(FunctionalTester $I): void
    {
        $method = $this->manualShippingMethod(['amount' => 10, 'settings' => ['configure_rate' => 'per_price']]);
        $payload = $this->manualShippingPayload(0);
        $payload['shipping_method_id'] = $method->id;
        $payload['discount'] = ['type' => 'fixed', 'value' => 20];
        [$status, $body] = $I->createOrder($payload);
        Assert::assertSame(200, $status, wp_json_encode($body));
        $order = Order::find($body['order_id']);
        Assert::assertSame(800, (int) $order->shipping_total, 'Ten percent shipping applies to the discounted eighty-dollar subtotal.');
        Assert::assertSame(8800, (int) $order->total_amount, 'The discount and shipping are each included once.');
    }

    public function selectedMethodIncludesShippingTaxInTheFirstEmail(FunctionalTester $I): void
    {
        $this->enableManualShippingTax($I);
        $method = $this->manualShippingMethod();
        $payload = $this->manualShippingPayload(0);
        $payload['shipping_method_id'] = $method->id;
        [$status, $body] = $I->createOrder($payload);
        Assert::assertSame(200, $status, wp_json_encode($body));
        $order = Order::find($body['order_id']);
        Assert::assertSame(100, (int) $order->shipping_tax, 'The selected method is taxed once.');
        Assert::assertSame(12100, (int) $order->total_amount, 'The final total includes shipping tax.');
        Assert::assertSame(12100, (int) $order->latest_transaction->total, 'The payment transaction has the same final total.');
        Assert::assertCount(1, $this->mails, 'One confirmation is sent.');
        Assert::assertStringContainsString('121.00', $this->mails[0]['message'], 'The email includes the final taxed total.');
    }

    public function methodChargesUseTheSelectedCurrencyAfterACurrencyChange(FunctionalTester $I): void
    {
        $this->makeMultiCurrencyAvailable();
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'JPY']]]);
        RateFixture::set('JPY', '150');
        $method = $this->manualShippingMethod();
        $payload = $this->manualShippingPayload(1999);
        $payload['currency'] = 'JPY';
        $payload['shipping_method_id'] = $method->id;
        [$status, $body] = $I->createOrder($payload);
        Assert::assertSame(200, $status, wp_json_encode($body));
        $order = Order::find($body['order_id']);
        Assert::assertSame('JPY', $order->currency, 'The selected order currency is saved.');
        Assert::assertSame(150000, (int) $order->shipping_total, 'Ten dollars converts to fifteen hundred yen in stored cents.');
        Assert::assertSame(1650000, (int) $order->total_amount, 'Products and shipping use the same exchange rate.');
    }

    public function aFreeShippingMethodOverridesAnOldCharge(FunctionalTester $I): void
    {
        $method = $this->manualShippingMethod(['type' => 'free_shipping', 'amount' => 0]);
        $payload = $this->manualShippingPayload(1500);
        $payload['shipping_method_id'] = $method->id;
        [$status, $body] = $I->createOrder($payload);
        Assert::assertSame(200, $status, wp_json_encode($body));
        $order = Order::find($body['order_id']);
        Assert::assertSame(0, (int) $order->shipping_total, 'Free shipping removes the old charge.');
        Assert::assertSame(10000, (int) $order->total_amount, 'The total contains products only.');
    }

    public function freeShippingItemsAreExcludedBeforeAndAfterEditing(FunctionalTester $I): void
    {
        $this->enableManualShippingTax($I);
        $method = $this->manualShippingMethod();
        $payload = $this->manualShippingPayload(9999, ['free_shipping' => 'yes']);
        $payload['shipping_method_id'] = $method->id;
        [$status, $body] = $I->createOrder($payload);
        Assert::assertSame(200, $status, wp_json_encode($body));
        $order = Order::find($body['order_id']);
        Assert::assertSame(0, (int) $order->shipping_total);
        Assert::assertSame(0, (int) $order->shipping_tax);
        Assert::assertSame(11000, (int) $order->total_amount);

        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . $order->id, [
            'customer_id' => $order->customer_id,
            'order_items' => $order->order_items->toArray(),
            'shipping_total' => 0,
            'subtotal' => 10000,
            'total_amount' => 11000,
            'config' => [],
        ]);
        Assert::assertSame(200, $status, wp_json_encode($body));
        $order->refresh();
        Assert::assertSame(0, (int) $order->shipping_tax);
        Assert::assertSame(11000, (int) $order->total_amount);
        Assert::assertSame('zone', $order->config['shipping_source'] ?? '');
    }

    public function methodClassSurchargesAreStoredAndTaxedOnce(FunctionalTester $I): void
    {
        $this->enableManualShippingTax($I);
        $method = $this->manualShippingMethod();
        $payload = $this->manualShippingPayload(0);
        $class = (new ShippingClassFactory())->create(['cost' => 5]);
        $detail = ProductDetail::query()->where('post_id', $payload['order_items'][0]['post_id'])->first();
        $detail->other_info = array_merge($detail->other_info ?: [], ['shipping_class' => $class->id]);
        $detail->save();
        $payload['shipping_method_id'] = $method->id;
        [$previewStatus, $preview] = $I->post('/fluent-cart/v2/orders/price', $payload);
        Assert::assertSame(200, $previewStatus, wp_json_encode($preview));
        Assert::assertSame(1500, $preview['shipping_total']);
        Assert::assertSame(500, $preview['order_items'][0]['shipping_charge'], 'Only the class surcharge is stored on the item.');
        $payload['order_items'][0] = array_merge($payload['order_items'][0], array_intersect_key(
            $preview['order_items'][0], array_flip(['unit_price', 'price', 'subtotal', 'discount_total', 'coupon_discount', 'manual_discount', 'other_info', 'shipping_charge'])
        ));
        $payload['shipping_total'] = $preview['shipping_total'];
        $payload['quote_revision'] = $preview['quote_revision'];
        $payload['total_amount'] = $preview['total_amount'];
        [$status, $body] = $I->createOrder($payload);
        Assert::assertSame(200, $status, wp_json_encode($body));
        $order = Order::find($body['order_id']);
        Assert::assertSame(1500, (int) $order->shipping_total);
        Assert::assertSame(150, (int) $order->shipping_tax);
        Assert::assertSame(12650, (int) $order->total_amount);
    }
}

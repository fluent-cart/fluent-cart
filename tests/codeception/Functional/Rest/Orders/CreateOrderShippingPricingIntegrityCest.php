<?php

namespace Tests\Functional\Rest\Orders;

use FluentCart\App\App;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\ShippingClassFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\RateFixture;
use Tests\Support\Traits\BuildsManualShippingOrders;
use Tests\Support\Traits\MakesMultiCurrencyAvailable;

class CreateOrderShippingPricingIntegrityCest
{
    use BuildsManualShippingOrders;
    use MakesMultiCurrencyAvailable;

    private $gatewaySettings;
    private $mailCatcher;
    private $mails = [];

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
        RateFixture::clear();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
    }

    public function selectedShippingPreservesThePostedExchangeRate(FunctionalTester $I): void
    {
        $this->makeMultiCurrencyAvailable();
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'EUR']]]);
        RateFixture::set('EUR', '0.9');
        $method = $this->manualShippingMethod();
        $payload = $this->manualShippingPayload(800);
        $payload['shipping_method_id'] = $method->id;
        $payload['currency'] = 'EUR';
        $payload['rate'] = '0.8';
        $payload['order_items'][0]['unit_price'] = 8000;
        $payload['total_amount'] = 8800;

        [$status, $body] = $I->post('/fluent-cart/v2/orders', $payload);
        Assert::assertSame(200, $status, wp_json_encode($body));
        $order = Order::find($body['order_id']);
        Assert::assertSame('EUR', $order->currency, 'The requested currency is saved.');
        Assert::assertSame(0.8, (float) $order->rate, 'The order stores the posted exchange rate.');
        Assert::assertSame(0.8, (float) $order->config['fx']['rate'], 'The currency snapshot stores the posted exchange rate.');
        Assert::assertSame(8000, (int) $order->order_items->first()->unit_price, 'The item uses the posted rate.');
        Assert::assertSame(800, (int) $order->shipping_total, 'Shipping uses the same posted rate.');
        Assert::assertSame(8800, (int) $order->total_amount, 'The saved total matches the reviewed total.');
        Assert::assertCount(1, $this->mails, 'The accepted order sends one confirmation.');
        Assert::assertStringContainsString('88.00', $this->mails[0]['message'], 'The confirmation contains the reviewed total.');
    }

    public function selectedShippingRejectsInconsistentPostedCurrencyAmounts(FunctionalTester $I): void
    {
        $this->makeMultiCurrencyAvailable();
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'EUR']]]);
        RateFixture::set('EUR', '0.9');
        $method = $this->manualShippingMethod();
        $payload = $this->manualShippingPayload(800);
        $payload['shipping_method_id'] = $method->id;
        $payload['currency'] = 'EUR';
        $payload['rate'] = '0.8';
        $payload['order_items'][0]['unit_price'] = 8000;
        $payload['total_amount'] = 8800;

        foreach (['total', 'line', 'rate'] as $invalidField) {
            $invalid = $payload;
            if ($invalidField === 'total') {
                $invalid['total_amount'] = 8700;
            } elseif ($invalidField === 'line') {
                $invalid['order_items'][0]['unit_price'] = 7900;
            } else {
                $invalid['rate'] = 0;
            }
            [$status] = $I->post('/fluent-cart/v2/orders', $invalid);
            Assert::assertSame(422, $status, 'An invalid posted ' . $invalidField . ' must be rejected.');
            Assert::assertSame(0, Order::query()->where('customer_id', $payload['customer_id'])->count(), 'Rejected pricing creates no order.');
            Assert::assertCount(0, $this->mails, 'Rejected pricing sends no confirmation.');
        }
    }

    public function variationDeterminesTheProductAndItsShippingClass(FunctionalTester $I): void
    {
        $method = $this->manualShippingMethod();
        $payload = $this->manualShippingPayload(0);
        $productId = $payload['order_items'][0]['post_id'];
        $class = (new ShippingClassFactory())->create(['cost' => 5]);
        $detail = ProductDetail::query()->where('post_id', $productId)->first();
        $detail->other_info = array_merge($detail->other_info ?: [], ['shipping_class' => $class->id]);
        $detail->save();
        $otherClass = (new ShippingClassFactory())->create(['cost' => 20]);
        $otherProduct = (new ProductFactory())->withDetailAttributes([
            'fulfillment_type' => 'physical',
            'other_info' => ['shipping_class' => $otherClass->id],
        ])->create();
        $payload['shipping_method_id'] = $method->id;

        foreach ([null, $otherProduct->ID] as $postedProductId) {
            $submitted = $payload;
            if ($postedProductId === null) {
                unset($submitted['order_items'][0]['post_id']);
            } else {
                $submitted['order_items'][0]['post_id'] = $postedProductId;
            }
            [$status, $preview] = $I->post('/fluent-cart/v2/orders/price', $submitted);
            Assert::assertSame(200, $status, wp_json_encode($preview));
            Assert::assertSame(1500, $preview['shipping_total'], 'The actual product adds its five-dollar class charge.');
            Assert::assertSame((int) $productId, (int) $preview['order_items'][0]['post_id'], 'The preview uses the variation parent.');
            [$status, $body] = $I->post('/fluent-cart/v2/orders', $submitted);
            Assert::assertSame(200, $status, wp_json_encode($body));
            $order = Order::find($body['order_id']);
            Assert::assertSame((int) $productId, (int) $order->order_items->first()->post_id, 'The saved item uses the variation parent.');
            Assert::assertSame(1500, (int) $order->shipping_total, 'The saved shipping includes the actual class charge.');
            Assert::assertSame(11500, (int) $order->total_amount, 'The saved total includes the actual class charge.');
        }
    }

    public function classRemovedFromTheProductIsNotChargedOnTheNextRequest(FunctionalTester $I): void
    {
        $method = $this->manualShippingMethod();
        $payload = $this->manualShippingPayload(0);
        $payload['shipping_method_id'] = $method->id;
        $class = (new ShippingClassFactory())->create(['cost' => 5]);
        $detail = ProductDetail::query()->where('post_id', $payload['order_items'][0]['post_id'])->first();
        $detail->other_info = array_merge($detail->other_info ?: [], ['shipping_class' => $class->id]);
        $detail->save();

        [, $classed] = $I->post('/fluent-cart/v2/orders/price', $payload);
        Assert::assertSame(1500, $classed['shipping_total'], 'The class adds its five-dollar charge.');

        $detail->other_info = array_diff_key($detail->other_info, ['shipping_class' => true]);
        $detail->save();

        [, $unclassed] = $I->post('/fluent-cart/v2/orders/price', $payload);
        Assert::assertSame(1000, $unclassed['shipping_total'], 'A later request reads the product as it is now.');
    }
}

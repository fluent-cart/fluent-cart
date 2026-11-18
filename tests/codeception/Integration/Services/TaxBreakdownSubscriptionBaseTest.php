<?php

namespace Tests\Integration\Services;

use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTaxRate;
use FluentCart\App\Modules\StoreManagedRenewal\Services\RenewalService;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Modules\Tax\TaxModule;
use FluentCart\App\Services\Renderer\Receipt\TaxSummaryHelper;
use FluentCart\App\Services\Renderer\TaxRenderer;
use FluentCart\Framework\Support\Arr;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\SubscriptionFactory;
use Tests\Support\Factory\TaxClassFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\TaxRateFactory;
use Tests\Support\Traits\BuildsTaxFixtures;
use Tests\Support\Traits\ConfiguresStoreOrderMode;

/**
 * The tax breakdown's taxable base for subscriptions.
 *
 * A $69.00 subscription with a $15.00 signup fee and a $30.00 fee at 4.75%:
 * the base is $114.00. Worked back from the rounded tax it reads $114.11.
 */
class TaxBreakdownSubscriptionBaseTest extends DatabaseTestCase
{
    use BuildsTaxFixtures;
    use ConfiguresStoreOrderMode;

    public function setUp(): void
    {
        parent::setUp();

        $this->enableTax();
        $this->resetTaxRuntimeState();
        $this->setStoreOrderMode(Status::ORDER_MODE_LIVE);
        // Tax rates outlive a test here, so start each one from a clean country.
        \FluentCart\App\Models\TaxRate::query()->where('country', 'IS')->delete();
        (new TaxRateFactory())->create([
            'class_id' => (int) $this->ensureStandardTaxClass()->id,
            // A country no other test taxes, so a leftover rate cannot add to this one.
            'country'  => 'IS',
            'rate'     => '4.75',
            'name'     => 'VAT',
        ]);
    }

    public function tearDown(): void
    {
        delete_option('fluent_cart_tax_configuration_settings');

        parent::tearDown();
    }

    public function test_checkout_breakdown_counts_the_signup_fee_in_the_base(): void
    {
        $variation = (new ProductFactory())->withVariantAttributes([
            'item_price' => 6900,
            'other_info' => [
                'payment_type'     => 'subscription',
                'repeat_interval'  => 'monthly',
                'times'            => 0,
                'trial_days'       => 0,
                'manage_setup_fee' => 'yes',
                'signup_fee'       => 1500,
                'tax_class'        => 'standard',
            ],
        ])->create()->variants->first();

        $cart = (new CartFactory())->withCartItems([CartHelper::generateCartItemFromVariation($variation, 1)])->create([
            'checkout_data' => [
                'form_data' => ['billing_country' => 'IS'],
                'fees'      => [['key' => 'handling', 'label' => 'Handling', 'amount' => 3000, 'taxable' => true, 'source' => 'custom']],
            ],
        ]);

        $module = $this->makeTaxModule();
        $fill = $module->calculateCartTax(['cart_data' => $cart->cart_data, 'checkout_data' => $cart->checkout_data], $cart);
        // 328 subscription + 71 signup fee + 143 fee.
        $this->assertSame(542, (int) Arr::get($fill, 'checkout_data.tax_data.tax_total'), 'Fixture: every part is taxed');

        $cart->cart_data = $fill['cart_data'];
        $cart->checkout_data = $fill['checkout_data'];

        ob_start();
        (new TaxRenderer($module))->renderTaxSummaryBox($cart);
        $html = ob_get_clean();

        $this->assertStringContainsString('114.00', $html);
        $this->assertStringNotContainsString('114.11', $html);
    }

    public function test_a_renewal_leaves_out_the_tax_on_one_time_fees(): void
    {
        $subscription = $this->subscribe([], [['key' => 'handling', 'label' => 'Handling', 'amount' => 3000, 'taxable' => true, 'source' => 'custom']]);

        $this->assertRenewal(RenewalService::createRenewalOrders($subscription)[0], ['VAT' => [6900, 328]]);
    }

    public function test_a_renewal_leaves_out_the_tax_on_the_signup_fee(): void
    {
        $subscription = $this->subscribe(['manage_setup_fee' => 'yes', 'signup_fee' => 1500]);

        $this->assertRenewal(RenewalService::createRenewalOrders($subscription)[0], ['VAT' => [6900, 328]]);
    }

    public function test_a_renewal_keeps_the_compound_base(): void
    {
        (new TaxRateFactory())->create([
            'class_id'    => (int) $this->ensureStandardTaxClass()->id,
            'country'     => 'IS',
            'rate'        => '2',
            'name'        => 'Local',
            'priority'    => 2,
            'is_compound' => 1,
        ]);
        $subscription = $this->subscribe();

        // 2% on 6900 + 327.75 of earlier tax; the last rate takes the rounding cent.
        $this->assertRenewal(RenewalService::createRenewalOrders($subscription)[0], ['VAT' => [6900, 328], 'Local' => [7228, 144]]);
    }

    public function test_a_renewal_after_a_mixed_order_keeps_its_own_base(): void
    {
        // A one-time item priced with tax included shares the rate, so the parent row is mixed.
        $subscription = $this->subscribe([], [], [[2000, ['payment_type' => 'onetime', 'tax_class' => 'standard', 'tax_inclusion' => 'included']]]);
        $this->assertTrue((bool) OrderTaxRate::query()->where('order_id', $subscription->parent_order_id)->value('meta')['is_mixed_inclusive'], 'Fixture: the parent row is mixed');

        $this->assertRenewal(RenewalService::createRenewalOrders($subscription)[0], ['VAT' => [6900, 328]]);
    }

    public function test_a_gateway_renewal_leaves_out_the_tax_on_one_time_fees(): void
    {
        $subscription = $this->subscribe([], [['key' => 'handling', 'label' => 'Handling', 'amount' => 3000, 'taxable' => true, 'source' => 'custom']]);

        $transaction = SubscriptionService::recordRenewalPayment(['vendor_charge_id' => 'ch_' . wp_generate_password(8, false)], $subscription);

        $this->assertRenewal(Order::query()->find($transaction->order_id), ['VAT' => [6900, 328]]);
    }

    public function test_a_physical_renewal_keeps_its_shipping_tax_out_of_the_subtotal(): void
    {
        $subscription = $this->subscribe([], [], [], 1699);

        $renewal = RenewalService::createRenewalOrders($subscription)[0];

        // 6900 + 1699 shipping + 328 item tax + 81 shipping tax, charged as before.
        $this->assertRenewalColumns($renewal, ['subtotal' => 8599, 'tax_total' => 328, 'shipping_tax' => 81, 'total_amount' => 9008]);
    }

    public function test_a_physical_gateway_renewal_keeps_its_shipping_tax_out_of_the_subtotal(): void
    {
        $subscription = $this->subscribe([], [], [], 1699);

        $transaction = SubscriptionService::recordRenewalPayment(['vendor_charge_id' => 'ch_' . wp_generate_password(8, false)], $subscription);

        $this->assertRenewalColumns(Order::query()->find($transaction->order_id), ['subtotal' => 8599, 'tax_total' => 328, 'shipping_tax' => 81, 'total_amount' => 9008]);
    }

    public function test_a_discounted_physical_renewal_matches_its_tax_rows(): void
    {
        $subscription = $this->subscribe([], [], [], 1699);
        add_filter('fluent_cart/renewal/before_create_order', $lower = function ($pricing) {
            $pricing['discount_total'] = 1000;
            $pricing['tax_total'] = 300;

            return $pricing;
        });

        $renewal = RenewalService::createRenewalOrders($subscription)[0];
        remove_filter('fluent_cart/renewal/before_create_order', $lower);

        // 9008 - 1000 discount - 28 less item tax. The shipping tax is not discounted.
        $this->assertRenewalColumns($renewal, ['subtotal' => 8599, 'tax_total' => 300, 'shipping_tax' => 81, 'total_amount' => 7980]);
        $item = \FluentCart\App\Models\OrderItem::query()->where('order_id', $renewal->id)->first();
        $this->assertSame(300, (int) $item->tax_amount, 'The item tax matches the order and its rows');
    }

    public function test_an_inclusive_physical_renewal_keeps_shipping_tax_inside_the_price(): void
    {
        $this->enableTax('included');
        $this->resetTaxRuntimeState();
        $subscription = $this->subscribe([], [], [], 1699);

        $renewal = RenewalService::createRenewalOrders($subscription)[0];

        // 8599 charged. 313 item tax and 77 shipping tax are inside the prices.
        $this->assertRenewalColumns($renewal, ['subtotal' => 8286, 'tax_total' => 313, 'shipping_tax' => 77, 'total_amount' => 8599]);
    }

    public function test_an_inclusive_physical_gateway_renewal_keeps_shipping_tax_inside_the_price(): void
    {
        $this->enableTax('included');
        $this->resetTaxRuntimeState();
        $subscription = $this->subscribe([], [], [], 1699);

        $transaction = SubscriptionService::recordRenewalPayment(['vendor_charge_id' => 'ch_' . wp_generate_password(8, false)], $subscription);

        $this->assertRenewalColumns(Order::query()->find($transaction->order_id), ['subtotal' => 8286, 'tax_total' => 313, 'shipping_tax' => 77, 'total_amount' => 8599]);
    }

    public function test_a_discounted_inclusive_physical_renewal_adds_up(): void
    {
        $this->enableTax('included');
        $this->resetTaxRuntimeState();
        $subscription = $this->subscribe([], [], [], 1699);
        add_filter('fluent_cart/renewal/before_create_order', $lower = function ($pricing) {
            $pricing['discount_total'] = 1000;
            $pricing['tax_total'] = 300;

            return $pricing;
        });

        $renewal = RenewalService::createRenewalOrders($subscription)[0];
        remove_filter('fluent_cart/renewal/before_create_order', $lower);

        // 8599 - 1000 discount - 13 less tax.
        $this->assertRenewalColumns($renewal, ['subtotal' => 8286, 'tax_total' => 300, 'shipping_tax' => 77, 'total_amount' => 7586]);
    }

    public function test_an_admin_edit_keeps_a_pending_renewal_adding_up(): void
    {
        $subscription = $this->subscribe([], [], [], 1699);
        $renewal = RenewalService::createRenewalOrders($subscription)[0];

        SubscriptionService::updateSubscription($subscription->fresh(), ['recurring_total' => 120]);

        $this->assertRenewalColumns($renewal, ['subtotal' => 11591, 'tax_total' => 328, 'shipping_tax' => 81, 'total_amount' => 12000]);
    }

    public function test_a_renewal_after_a_price_change_uses_the_new_tax_and_base(): void
    {
        $subscription = $this->subscribe();
        $subscription->recurring_amount = 13800;
        $subscription->recurring_tax_total = 656;
        $subscription->recurring_total = 14456;
        $subscription->save();

        $this->assertRenewal(RenewalService::createRenewalOrders($subscription)[0], ['VAT' => [13800, 656]]);
    }

    public function test_a_zero_decimal_renewal_keeps_its_saved_base(): void
    {
        $subscription = $this->subscribe();
        $renewal = RenewalService::createRenewalOrders($subscription)[0];
        // Tax is rounded to whole yen, so it is further from the base than cents allow.
        Order::query()->where('id', $renewal->id)->update(['currency' => 'JPY']);
        OrderTaxRate::query()->where('order_id', $renewal->id)->update(['order_tax' => 300, 'total_tax' => 300]);

        $rows = TaxSummaryHelper::computeTaxSummary(Order::query()->find($renewal->id))['foldedRateLines'];

        $this->assertSame(6900, (int) $rows[0]['base']);
    }

    public function test_zero_decimal_rounding_lands_on_the_last_row(): void
    {
        (new TaxRateFactory())->create([
            'class_id' => (int) $this->ensureStandardTaxClass()->id, 'country' => 'IS', 'rate' => '2', 'name' => 'Local', 'priority' => 2, 'is_compound' => 1,
        ]);
        $subscription = $this->subscribe();
        $parent = Order::query()->find($subscription->parent_order_id);
        $item = \FluentCart\App\Models\OrderItem::query()->where('order_id', $parent->id)->first();
        $meta = $item->line_meta;
        $meta['tax_config']['rates'][0]['recurring_tax'] = 300;
        $meta['tax_config']['rates'][1]['recurring_tax'] = 100;
        $item->line_meta = $meta;

        // A whole-yen total one yen off the rows, as compound rounding leaves it.
        $rows = \FluentCart\App\Services\Tax\RenewalTaxRates::fromParent($parent, $item, 500, 'JPY');

        $this->assertSame([300, 200], array_column($rows, 'order_tax'));
    }

    public function test_rounding_larger_than_any_row_spreads_across_the_rows(): void
    {
        foreach ([2, 3, 4] as $priority) {
            (new TaxRateFactory())->create([
                'class_id' => (int) $this->ensureStandardTaxClass()->id, 'country' => 'IS', 'rate' => '6', 'name' => 'Rate ' . $priority, 'priority' => $priority,
            ]);
        }
        $subscription = $this->subscribe();
        $item = \FluentCart\App\Models\OrderItem::query()->where('order_id', $subscription->parent_order_id)->first();
        $meta = $item->line_meta;
        $this->assertCount(4, $meta['tax_config']['rates']);
        // A 10-cent price at four 6% rates: each rate rounds to 1 cent, the total to 2.
        foreach (array_keys($meta['tax_config']['rates']) as $i) {
            $meta['tax_config']['rates'][$i]['recurring_tax'] = 1;
            $meta['tax_config']['rates'][$i]['recurring_taxable_amount'] = 10;
        }
        $item->line_meta = $meta;
        $item->save();
        $subscription->recurring_amount = 10;
        $subscription->recurring_tax_total = 2;
        $subscription->recurring_total = 12;
        $subscription->save();

        $renewal = RenewalService::createRenewalOrders($subscription)[0];

        $this->assertSame(2, (int) Order::query()->find($renewal->id)->tax_total);
        $this->assertSame(2, (int) OrderTaxRate::query()->where('order_id', $renewal->id)->sum('order_tax'));
    }

    public function test_float_cents_round_instead_of_truncate(): void
    {
        $this->enableTax('excluded', 'billing', 'subtotal');
        $this->resetTaxRuntimeState();
        (new TaxRateFactory())->create([
            'class_id' => (int) $this->ensureStandardTaxClass()->id, 'country' => 'IS', 'rate' => '2', 'name' => 'Local', 'priority' => 2, 'is_compound' => 1,
        ]);
        $subscription = $this->subscribe();

        // 327.75 and 7227.75 stay floats under subtotal rounding; the last rate takes the cent.
        $this->assertRenewal(RenewalService::createRenewalOrders($subscription)[0], ['VAT' => [6900, 328], 'Local' => [7228, 144]]);
    }

    public function test_a_renewal_of_an_older_subscription_after_a_mixed_order_is_not_mixed(): void
    {
        $subscription = $this->subscribe([], [], [[2000, ['payment_type' => 'onetime', 'tax_class' => 'standard', 'tax_inclusion' => 'included']]], 0, true);

        $renewal = RenewalService::createRenewalOrders($subscription)[0];
        $meta = OrderTaxRate::query()->where('order_id', $renewal->id)->value('meta');

        $this->assertFalse($meta['is_mixed_inclusive']);
        $this->assertFalse($meta['inclusive']);
        // The first order's base covered the one-time item too, so it is not the renewal's.
        $this->assertArrayNotHasKey('taxable_amount', $meta);
    }

    public function test_a_renewal_of_an_older_subscription_keeps_a_matching_base(): void
    {
        $subscription = $this->subscribe([], [], [], 0, true);

        $this->assertRenewal(RenewalService::createRenewalOrders($subscription)[0], ['VAT' => [6900, 328]]);
    }

    public function test_a_subscription_without_rates_leaves_out_a_one_time_items_tax(): void
    {
        // The subscription's class has no rate here; only the one-time item is taxed.
        $reduced = (new TaxClassFactory())->create(['slug' => 'untaxed-class']);
        $subscription = $this->subscribe(['tax_class' => $reduced->slug], [], [[2000, ['payment_type' => 'onetime', 'tax_class' => 'standard']]]);
        $this->assertGreaterThan(0, (int) OrderTaxRate::query()->where('order_id', $subscription->parent_order_id)->sum('total_tax'), 'Fixture: the first order is taxed');

        $renewal = RenewalService::createRenewalOrders($subscription)[0];

        $this->assertSame(0, (int) OrderTaxRate::query()->where('order_id', $renewal->id)->sum('total_tax'));
        $this->assertSame(1, OrderTaxRate::query()->where('order_id', $renewal->id)->where('tax_rate_id', 0)->count(), 'The zero-tax row says tax ran');
    }

    public function test_a_reverse_charge_renewal_stays_at_zero_tax(): void
    {
        $this->enableTax('excluded', 'billing', 'item', ['eu_vat_settings' => ['local_reverse_charge' => 'yes', 'reverse_charge_price_mode' => 'fixed']]);
        $this->resetTaxRuntimeState();

        $subscription = $this->subscribe([], [], [], 0, false, [
            'tax_data' => ['valid' => true, 'vat_number' => 'IS123456', 'country' => 'IS'],
        ], ['reverse_charge_applied' => true]);

        $parentItem = \FluentCart\App\Models\OrderItem::query()->where('order_id', $subscription->parent_order_id)->first();
        $this->assertSame(328, (int) Arr::get($parentItem->line_meta, 'tax_config.rates.0.recurring_tax'), 'Fixture: the item keeps its recurring tax');
        $this->assertSame(0, (int) OrderTaxRate::query()->where('order_id', $subscription->parent_order_id)->sum('total_tax'), 'Fixture: the first order is reverse charged');

        $renewal = Order::query()->find(RenewalService::createRenewalOrders($subscription)[0]->id);

        $this->assertSame(0, (int) OrderTaxRate::query()->where('order_id', $renewal->id)->sum('total_tax'), 'The renewal rows stay at zero');
        $this->assertSame(6900, (int) $renewal->total_amount);
    }

    /**
     * The order columns match the renewal's tax rows, and the item matches the order.
     */
    private function assertRenewalColumns($renewal, array $expected): void
    {
        $renewal = Order::query()->find($renewal->id);
        $actual = [];
        foreach (array_keys($expected) as $column) {
            $actual[$column] = (int) $renewal->{$column};
        }
        $this->assertSame($expected, $actual);

        $rows = OrderTaxRate::query()->where('order_id', $renewal->id)->get();
        $this->assertSame((int) $renewal->tax_total, (int) $rows->sum('order_tax'), 'Item tax rows add up to tax_total');
        $this->assertSame((int) $renewal->shipping_tax, (int) $rows->sum('shipping_tax'), 'Shipping tax rows add up to shipping_tax');
        $item = \FluentCart\App\Models\OrderItem::query()->where('order_id', $renewal->id)->first();
        $this->assertSame((int) $renewal->subtotal, (int) $item->subtotal, 'The item subtotal matches the order');
        // Refunds rely on this: what the lines and shipping charged is the order total.
        $this->assertSame((int) $renewal->total_amount, $item->chargedTotal($renewal) + $renewal->shippingChargedTotal(), 'Charged amounts add up to the total');
    }

    /**
     * Each expected rate maps to [base, tax], and the rows add up to the renewal's tax.
     */
    private function assertRenewal($renewal, array $expected): void
    {
        $renewal = Order::query()->find($renewal->id);
        $rows = [];
        foreach (TaxSummaryHelper::computeTaxSummary($renewal)['foldedRateLines'] as $row) {
            $rows[preg_replace('/ \(.*$/', '', $row['label'])] = [(int) $row['base'], (int) $row['tax']];
        }

        $this->assertSame($expected, $rows);
        $this->assertSame((int) $renewal->tax_total, (int) OrderTaxRate::query()->where('order_id', $renewal->id)->sum('total_tax'), 'The rows add up to the renewal tax');
    }

    /**
     * A subscription bought with the checkout tax calculation, as placed orders store it.
     */
    private function subscribe(array $otherInfo = [], array $fees = [], array $extraProducts = [], int $shipping = 0, bool $legacy = false, array $checkout = [], array $rowMeta = [])
    {
        $fulfillment = $shipping ? 'physical' : 'digital';
        $variation = (new ProductFactory())->withDetailAttributes(['fulfillment_type' => $fulfillment])->withVariantAttributes([
            'fulfillment_type' => $fulfillment,
            'item_price' => 6900,
            'other_info' => array_merge([
                'payment_type'    => 'subscription',
                'repeat_interval' => 'monthly',
                'times'           => 0,
                'trial_days'      => 0,
                'tax_class'       => 'standard',
            ], $otherInfo),
        ])->create()->variants->first();

        $lines = [CartHelper::generateCartItemFromVariation($variation, 1)];
        $lines[0]['shipping_charge'] = $shipping;
        foreach ($extraProducts as list($price, $info)) {
            $extra = (new ProductFactory())->withVariantAttributes(['item_price' => $price, 'other_info' => $info])->create()->variants->first();
            $lines[] = CartHelper::generateCartItemFromVariation($extra, 1);
        }
        $cart = (new CartFactory())->withCartItems($lines)->create([
            'checkout_data' => array_merge(['form_data' => ['billing_country' => 'IS'], 'fees' => $fees], $checkout),
        ]);
        $fill = $this->makeTaxModule()->calculateCartTax(['cart_data' => $cart->cart_data, 'checkout_data' => $cart->checkout_data], $cart);
        $line = $fill['cart_data'][0];
        $taxData = $fill['checkout_data']['tax_data'];
        $taxConfig = $line['line_meta']['tax_config'];
        if ($legacy) {
            // Subscriptions bought before the recurring base was saved.
            foreach ($taxConfig['rates'] as $i => $rate) {
                unset($taxConfig['rates'][$i]['recurring_taxable_amount']);
            }
        }
        $shippingTax = (int) Arr::get($taxData, 'shipping_tax', 0);

        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->withAddresses()->create([
            'customer_id'  => $customer->id,
            'type'         => Status::ORDER_TYPE_SUBSCRIPTION,
            'tax_behavior'   => (int) Arr::get($taxData, 'tax_behavior', 1),
            'tax_total'      => (int) $taxData['tax_total'],
            'shipping_total' => $shipping,
            'shipping_tax'   => $shippingTax,
        ]);
        (new OrderItemFactory())->create($order->id, [
            'post_id'      => $variation->post_id,
            'object_id'    => $variation->id,
            'payment_type'     => Status::ORDER_TYPE_SUBSCRIPTION,
            'fulfillment_type' => $fulfillment,
            'subtotal'     => 6900,
            'line_total'   => 6900,
            'tax_amount'   => (int) $line['tax_amount'],
            'line_meta'    => ['tax_config' => $taxConfig],
            'other_info'   => $line['other_info'],
        ]);
        TaxModule::persistTaxRates($order->id, $taxData['tax_lines'], array_merge(['tax_country' => 'IS', 'shipping_inclusive' => (int) Arr::get($taxData, 'store_tax_behavior', 1) === 2], $rowMeta), $shippingTax, (array) Arr::get($taxData, 'shipping_tax_lines', []));

        $recurringTax = (int) Arr::get($line, 'other_info.recurring_tax', 0);

        return (new SubscriptionFactory())->create([
            'customer_id'         => $customer->id,
            'parent_order_id'     => $order->id,
            'product_id'          => $variation->post_id,
            'variation_id'        => $variation->id,
            'collection_method'   => 'manual',
            'billing_interval'    => 'daily',
            'recurring_amount'    => 6900 + $shipping,
            'recurring_tax_total' => $recurringTax,
            'recurring_total'     => 6900 + $shipping + ((int) Arr::get($taxData, 'tax_behavior', 1) === 1 ? $recurringTax + $shippingTax : 0),
            'next_billing_date'   => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
        ]);
    }
}

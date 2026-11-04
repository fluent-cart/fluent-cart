<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\Api\Resource\CouponResource;
use FluentCart\Api\Resource\OrderResource;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Modules\MultiCurrency\Services\CartCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySnapshot;
use FluentCart\App\Modules\MultiCurrency\Services\RoundingRules;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use FluentCart\App\Services\AdminOrderPricing;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CouponFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Traits\MakesMultiCurrencyAvailable;
use Tests\Support\RateFixture;

/**
 * The Create Order screen previews with AdminOrderPricing::price() and the
 * save prices again with it: the server validates what the screen showed,
 * then saves. Base USD, GBP 0.79, JPY 150.
 */
class AdminOrderPricingTest extends DatabaseTestCase
{
    use MakesMultiCurrencyAvailable;

    public function setUp(): void
    {
        parent::setUp();
        $this->makeMultiCurrencyAvailable();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [
            ['code' => 'GBP', 'rounding' => 'none', 'charm' => 'none', 'adjustment' => 0],
            ['code' => 'JPY', 'rounding' => 'none', 'charm' => 'none', 'adjustment' => 0],
        ]]);
        RateFixture::set('GBP', '0.79');
        RateFixture::set('JPY', '150');
    }

    public function tearDown(): void
    {
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        parent::tearDown();
    }

    public function test_a_catalog_line_is_priced_from_the_product_not_the_posted_price(): void
    {
        $payload = $this->payload('GBP', 5000);
        $payload['order_items'][0]['unit_price'] = 1;

        $pricing = AdminOrderPricing::price($payload);

        $this->assertIsArray($pricing);
        $this->assertSame(3950, $pricing['items'][0]['unit_price'], '5000 x 0.79');
        $this->assertSame(3950 + 500, $pricing['total'], 'shipping is typed in GBP');
        $this->assertNotSame(CartCurrency::revisionOfQuote($pricing['quote']), $pricing['quote_revision'], 'the revision covers the lines, not only the rate');
    }

    public function test_a_trials_recurring_price_change_after_the_preview_is_rejected(): void
    {
        $payload = $this->payload('GBP', 5000);
        $variant = ProductVariation::query()->find($payload['order_items'][0]['object_id']);
        $variant->other_info = array_merge((array) $variant->other_info, [
            'payment_type'    => 'subscription',
            'repeat_interval' => 'monthly',
            'trial_days'      => 14,
        ]);
        $variant->save();
        $payload['order_items'][0]['payment_type'] = 'subscription';
        $payload['order_items'][0]['other_info'] = ['payment_type' => 'subscription'];
        $pricing = AdminOrderPricing::price($payload);
        $this->assertIsArray($pricing, is_wp_error($pricing) ? $pricing->get_error_message() : '');

        $variant->item_price = 9000;
        $variant->save();
        $this->assertSame($pricing['total'], AdminOrderPricing::price($payload)['total'], 'the trial keeps the first charge the same');

        $this->assertPricesUpdated(array_merge($payload, [
            'quote_revision' => $pricing['quote_revision'],
            'total_amount'   => $pricing['total'],
        ]));
    }

    public function test_a_manual_discount_cannot_exceed_what_is_charged_now(): void
    {
        $payload = $this->trialPlusOnetimePayload();
        $payload['discount'] = ['value' => 10];

        $result = AdminOrderPricing::price($payload);

        $this->assertInstanceOf(\WP_Error::class, $result, 'the trial line charges nothing now, so only the £7.90 line has room');
        $this->assertSame('invalid_amount', $result->get_error_code());
        $this->assertSame('The discount cannot be more than the order subtotal.', $result->get_error_message());
    }

    public function test_a_trial_only_order_says_trials_take_no_manual_discount(): void
    {
        $payload = $this->trialPlusOnetimePayload();
        array_pop($payload['order_items']);
        $payload['discount'] = ['value' => 1];

        $result = AdminOrderPricing::price($payload);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_amount', $result->get_error_code());
        $this->assertSame('Trial items can\'t take a manual discount.', $result->get_error_message());
    }

    public function test_a_caller_without_a_revision_gets_the_trial_message_too(): void
    {
        $payload = $this->trialPlusOnetimePayload();
        array_pop($payload['order_items']);
        $payload['order_items'][0]['other_info']['trial_days'] = 14;
        $payload['discount'] = ['value' => 1];

        $result = AdminOrderPricing::asPosted($payload);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_amount', $result->get_error_code());
        $this->assertSame('Trial items can\'t take a manual discount.', $result->get_error_message());
    }

    public function test_a_caller_without_a_revision_still_discounts_a_mixed_trial_order(): void
    {
        $payload = $this->trialPlusOnetimePayload();
        $payload['order_items'][0]['other_info']['trial_days'] = 14;
        $payload['discount'] = ['value' => 5];

        $result = AdminOrderPricing::asPosted($payload);

        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertSame([0, 500], array_column($result['items'], 'manual_discount'));
    }

    public function test_a_free_order_without_a_trial_keeps_the_subtotal_message(): void
    {
        $payload = $this->payload('GBP', 5000);
        $payload['order_items'][0]['has_custom_price'] = true;
        $payload['order_items'][0]['unit_price'] = 0;
        $payload['order_items'][0]['subtotal'] = 0;
        $payload['discount'] = ['value' => 1];

        $result = AdminOrderPricing::price($payload);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('The discount cannot be more than the order subtotal.', $result->get_error_message());
    }

    public function test_a_manual_discount_skips_a_trial_line(): void
    {
        $payload = $this->trialPlusOnetimePayload();
        $payload['discount'] = ['value' => 5];

        $pricing = AdminOrderPricing::price($payload);

        $this->assertIsArray($pricing, is_wp_error($pricing) ? $pricing->get_error_message() : '');
        $this->assertSame([0, 500], array_column($pricing['items'], 'manual_discount'));
        $this->assertSame(790 - 500, $pricing['total']);
    }

    public function test_item_shipping_charges_must_add_up_to_the_shipping_total(): void
    {
        $payload = $this->payload('GBP', 5000);
        $payload['order_items'][0]['shipping_charge'] = 300;
        $this->assertInvalidAmount(AdminOrderPricing::price($payload), '£3 of £5 shipping');

        $payload['order_items'][0]['shipping_charge'] = 500;
        $this->assertSame(500, AdminOrderPricing::price($payload)['items'][0]['shipping_charge']);
    }

    public function test_an_order_saves_when_the_screen_showed_the_servers_price(): void
    {
        $payload = $this->payload('GBP', 5000);
        $pricing = AdminOrderPricing::price($payload);

        $order = OrderResource::updatedPlaceOrder(array_merge($payload, [
            'quote_revision' => $pricing['quote_revision'],
            'total_amount'   => $pricing['total'],
        ]));

        $this->assertInstanceOf(Order::class, $order, is_wp_error($order) ? $order->get_error_message() : '');
        $this->assertSame($pricing['total'], (int) Order::query()->find($order->id)->total_amount);
    }

    public function test_a_rate_change_after_the_preview_is_rejected_without_an_order(): void
    {
        $payload = $this->payload('GBP', 5000);
        $pricing = AdminOrderPricing::price($payload);

        RateFixture::set('GBP', '0.80');
        ExchangeRateService::flushCache();

        $this->assertPricesUpdated(array_merge($payload, [
            'quote_revision' => $pricing['quote_revision'],
            'total_amount'   => $pricing['total'],
        ]));
    }

    public function test_a_total_the_server_does_not_reach_is_rejected_without_an_order(): void
    {
        $payload = $this->payload('GBP', 5000);
        $pricing = AdminOrderPricing::price($payload);

        $this->assertPricesUpdated(array_merge($payload, [
            'quote_revision' => $pricing['quote_revision'],
            'total_amount'   => $pricing['total'] - 1,
        ]));
    }

    public function test_a_caller_without_a_revision_saves_the_posted_prices_in_the_store_currency(): void
    {
        $payload = $this->payload('', 5000);
        $payload['order_items'][0]['unit_price'] = 2500;
        $payload['total_amount'] = 1;

        $order = OrderResource::updatedPlaceOrder($payload);

        $this->assertInstanceOf(Order::class, $order, is_wp_error($order) ? $order->get_error_message() : '');
        $order = Order::query()->find($order->id);
        $this->assertSame('USD', $order->currency);
        $this->assertSame(2500, (int) $order->order_items->first()->unit_price);
        $this->assertSame(2500 + 500, (int) $order->total_amount);
    }

    public function test_a_caller_without_a_revision_is_not_held_to_the_shipping_sum(): void
    {
        $payload = $this->payload('USD', 5000);
        $payload['order_items'][0]['shipping_charge'] = 300;

        $order = OrderResource::updatedPlaceOrder($payload);

        $this->assertInstanceOf(Order::class, $order, is_wp_error($order) ? $order->get_error_message() : '');
    }

    public function test_a_caller_without_a_revision_gets_its_manual_discount_and_free_shipping(): void
    {
        $payload = $this->payload('USD', 5000);
        $payload['order_items'][0]['unit_price'] = 2500;
        $payload['discount'] = ['value' => 5];
        unset($payload['shipping_total']);

        $order = OrderResource::updatedPlaceOrder($payload);

        $this->assertInstanceOf(Order::class, $order, is_wp_error($order) ? $order->get_error_message() : '');
        $order = Order::query()->find($order->id);
        $this->assertSame(0, (int) $order->shipping_total);
        $this->assertSame(500, (int) $order->manual_discount_total);
        $this->assertSame(2000, (int) $order->total_amount);
    }

    public function test_a_caller_in_another_currency_saves_at_the_servers_price_and_rate(): void
    {
        $payload = $this->payload('GBP', 5000);
        $payload['order_items'][0]['unit_price'] = 3950;
        $payload['rate'] = '0.79';
        $payload['total_amount'] = 3950 + 500;

        $order = OrderResource::updatedPlaceOrder($payload);

        $this->assertInstanceOf(Order::class, $order, is_wp_error($order) ? $order->get_error_message() : '');
        $order = Order::query()->find($order->id);
        $this->assertSame('GBP', $order->currency);
        $this->assertSame('0.790000000000', (string) $order->rate);
        $this->assertSame(3950, (int) $order->order_items->first()->unit_price);
        $this->assertSame(3950 + 500, (int) $order->total_amount);
    }

    public function test_a_caller_in_another_currency_may_omit_rate_and_total(): void
    {
        $payload = $this->payload('GBP', 5000);
        $payload['order_items'][0]['unit_price'] = 3950;

        $order = OrderResource::updatedPlaceOrder($payload);

        $this->assertInstanceOf(Order::class, $order, is_wp_error($order) ? $order->get_error_message() : '');
        $this->assertSame('0.790000000000', (string) Order::query()->find($order->id)->rate);
    }

    public function test_a_caller_in_another_currency_posting_the_base_price_is_told_prices_changed(): void
    {
        $this->assertPricesUpdated($this->payload('GBP', 5000));
    }

    public function test_a_caller_in_another_currency_with_its_own_rate_saves_at_that_rate(): void
    {
        add_filter(RateFixture::FILTER, function ($rate) {
            return is_array($rate) ? array_merge($rate, ['margin' => '2']) : $rate;
        }, 10, 1);
        $payload = $this->payload('GBP', 5000);
        $payload['order_items'][0]['unit_price'] = 4000;
        $payload['rate'] = '0.80';
        $payload['total_amount'] = 4000 + 500;

        $order = OrderResource::updatedPlaceOrder($payload);

        $this->assertInstanceOf(Order::class, $order, is_wp_error($order) ? $order->get_error_message() : '');
        $order = Order::query()->find($order->id);
        $this->assertSame('GBP', $order->currency);
        $this->assertSame('0.800000000000', (string) $order->rate);
        $this->assertSame(4000, (int) $order->order_items->first()->unit_price);
        $snapshot = CurrencySnapshot::fromOrder($order);
        $this->assertSame(CurrencySnapshot::SOURCE_EXTERNAL, $snapshot['source']);
        $this->assertEquals(0, $snapshot['margin'], 'the store margin is not applied to a posted rate');
        $this->assertNull($snapshot['rate_id'] ?? null);
    }

    public function test_a_caller_with_its_own_rate_gets_no_store_rounding_on_top(): void
    {
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [
            ['code' => 'GBP', 'rounding' => 'up_1', 'charm' => 'minus_0_01', 'adjustment' => 5],
        ]]);
        MultiCurrencySettings::clearCache();

        $payload = $this->payload('GBP', 5001);
        $payload['order_items'][0]['unit_price'] = 4001;
        $payload['rate'] = '0.80';
        $payload['total_amount'] = 4001 + 500;

        $order = OrderResource::updatedPlaceOrder($payload);

        $this->assertInstanceOf(Order::class, $order, is_wp_error($order) ? $order->get_error_message() : '');
        $order = Order::query()->find($order->id);
        $this->assertSame(4001, (int) $order->order_items->first()->unit_price);
        $snapshot = CurrencySnapshot::fromOrder($order);
        $this->assertSame(RoundingRules::NONE, $snapshot['rounding']);
        $this->assertSame(RoundingRules::NONE, $snapshot['charm']);
        $this->assertEquals(0, $snapshot['adjustment']);
    }

    public function test_a_caller_with_its_own_rate_is_checked_at_that_rate(): void
    {
        $payload = $this->payload('GBP', 5000);
        $payload['order_items'][0]['unit_price'] = 3950;
        $payload['rate'] = '0.80';

        $this->assertPricesUpdated($payload);
    }

    public function test_a_caller_with_a_rate_that_is_not_above_zero_is_refused(): void
    {
        foreach (['0', '-0.79', 'abc', '0.0000000000001', '1000000000000'] as $rate) {
            $payload = $this->payload('GBP', 5000);
            $payload['order_items'][0]['unit_price'] = 3950;
            $payload['rate'] = $rate;
            $ordersBefore = Order::query()->count();

            $this->assertInvalidAmount(OrderResource::updatedPlaceOrder($payload), $rate);
            $this->assertSame($ordersBefore, Order::query()->count(), $rate);
        }
    }

    public function test_a_rate_posted_with_the_store_currency_is_ignored(): void
    {
        $payload = $this->payload('USD', 5000);
        $payload['rate'] = '0.5';

        $order = OrderResource::updatedPlaceOrder($payload);

        $this->assertInstanceOf(Order::class, $order, is_wp_error($order) ? $order->get_error_message() : '');
        $order = Order::query()->find($order->id);
        $this->assertSame('1.000000000000', (string) $order->rate);
        $this->assertSame(5000, (int) $order->order_items->first()->unit_price);
    }

    public function test_a_caller_in_another_currency_with_a_wrong_total_is_told_prices_changed(): void
    {
        $payload = $this->payload('GBP', 5000);
        $payload['order_items'][0]['unit_price'] = 3950;
        $payload['total_amount'] = 5500;

        $this->assertPricesUpdated($payload);
    }

    public function test_a_caller_in_another_currency_posting_a_zero_total_is_taken_as_no_total(): void
    {
        $payload = $this->payload('GBP', 5000);
        $payload['order_items'][0]['unit_price'] = 3950;
        $payload['total_amount'] = 0;

        $order = OrderResource::updatedPlaceOrder($payload);

        $this->assertInstanceOf(Order::class, $order, is_wp_error($order) ? $order->get_error_message() : '');
        $this->assertSame(3950 + 500, (int) Order::query()->find($order->id)->total_amount);
    }

    public function test_a_caller_in_another_currency_posting_an_out_of_range_price_is_told_prices_changed(): void
    {
        $payload = $this->payload('GBP', 5000);
        $payload['order_items'][0]['unit_price'] = 1e20;

        $this->assertPricesUpdated($payload);
    }

    public function test_a_caller_in_another_currency_is_checked_line_by_line(): void
    {
        $payload = $this->payload('GBP', 5000);
        $payload['order_items'][0]['unit_price'] = 3950;
        $second = (new ProductFactory())->withVariantAttributes(['item_price' => 2000])->create();
        $variant = $second->variants->first();
        $payload['order_items'][] = array_merge($payload['order_items'][0], [
            'id'           => $variant->id,
            'variation_id' => $variant->id,
            'post_id'      => $second->ID,
            'object_id'    => $variant->id,
            'unit_price'   => 2000,
            'quantity'     => 2,
        ]);

        $this->assertPricesUpdated($payload);

        $payload['order_items'][1]['unit_price'] = 1580;
        $order = OrderResource::updatedPlaceOrder($payload);
        $this->assertInstanceOf(Order::class, $order, is_wp_error($order) ? $order->get_error_message() : '');
    }

    public function test_a_caller_in_another_currency_keeps_its_custom_price(): void
    {
        $payload = $this->payload('GBP', 5000);
        $payload['order_items'][0]['unit_price'] = 1234;
        $payload['order_items'][0]['has_custom_price'] = true;

        $order = OrderResource::updatedPlaceOrder($payload);

        $this->assertInstanceOf(Order::class, $order, is_wp_error($order) ? $order->get_error_message() : '');
        $order = Order::query()->find($order->id);
        $this->assertSame('GBP', $order->currency);
        $this->assertSame(1234, (int) $order->order_items->first()->unit_price);
    }

    public function test_a_caller_in_a_currency_that_is_not_offered_is_refused(): void
    {
        $ordersBefore = Order::query()->count();

        $result = OrderResource::updatedPlaceOrder($this->payload('EUR', 5000));

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_currency', $result->get_error_code());
        $this->assertSame($ordersBefore, Order::query()->count(), 'no order is created');
    }

    public function test_a_caller_with_a_bad_rate_in_a_currency_not_offered_is_told_the_currency(): void
    {
        $payload = $this->payload('EUR', 5000);
        $payload['rate'] = '0';
        $ordersBefore = Order::query()->count();

        $result = OrderResource::updatedPlaceOrder($payload);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_currency', $result->get_error_code());
        $this->assertSame($ordersBefore, Order::query()->count(), 'no order is created');
    }

    public function test_a_caller_in_another_currency_is_refused_when_multi_currency_is_off(): void
    {
        $payload = $this->payload('GBP', 5000);
        $payload['order_items'][0]['unit_price'] = 3950;
        MultiCurrencySettings::save(['enabled' => 'no']);
        MultiCurrencySettings::clearCache();
        $ordersBefore = Order::query()->count();

        foreach ([null, '0'] as $rate) {
            $payload['rate'] = $rate;

            $result = OrderResource::updatedPlaceOrder($payload);

            $this->assertInstanceOf(\WP_Error::class, $result, (string) $rate);
            $this->assertSame('invalid_currency', $result->get_error_code(), (string) $rate);
        }
        $this->assertSame($ordersBefore, Order::query()->count(), 'no order is created');
    }

    public function test_a_rate_posted_on_a_line_is_ignored(): void
    {
        $payload = $this->payload('GBP', 5000);
        $payload['order_items'][0]['unit_price'] = 3950;
        $payload['order_items'][0]['rate'] = '0.5';

        $order = OrderResource::updatedPlaceOrder($payload);

        $this->assertInstanceOf(Order::class, $order, is_wp_error($order) ? $order->get_error_message() : '');
        $order = Order::query()->find($order->id);
        $this->assertSame('0.790000000000', (string) $order->rate);
        $this->assertSame(3950, (int) $order->order_items->first()->unit_price);
    }

    public function test_yen_amounts_the_admin_types_must_be_whole_yen(): void
    {
        $shipping = $this->payload('JPY', 5000);
        $shipping['shipping_total'] = 150;
        $this->assertInvalidAmount(AdminOrderPricing::price($shipping), 'shipping ¥1.50');

        $discount = $this->payload('JPY', 5000);
        $discount['discount'] = ['type' => 'amount', 'value' => 10.5];
        $this->assertInvalidAmount(AdminOrderPricing::price($discount), 'discount ¥10.50');

        $customPrice = $this->payload('JPY', 5000);
        $customPrice['order_items'][0]['has_custom_price'] = true;
        $customPrice['order_items'][0]['unit_price'] = 12345;
        $this->assertInvalidAmount(AdminOrderPricing::price($customPrice), 'custom price ¥123.45');
    }

    public function test_amounts_beyond_the_cents_range_are_rejected(): void
    {
        $discount = $this->payload('GBP', 5000);
        $discount['discount'] = ['type' => 'amount', 'value' => '1e308'];
        $this->assertInvalidAmount(AdminOrderPricing::price($discount), 'discount 1e308');

        $shipping = $this->payload('GBP', 5000);
        $shipping['shipping_total'] = '1e308';
        $this->assertInvalidAmount(AdminOrderPricing::price($shipping), 'shipping 1e308');

        $customPrice = $this->payload('GBP', 5000);
        $customPrice['order_items'][0]['has_custom_price'] = true;
        $customPrice['order_items'][0]['unit_price'] = '1e308';
        $this->assertInvalidAmount(AdminOrderPricing::price($customPrice), 'custom price 1e308');

        $quantity = $this->payload('GBP', 5000);
        $quantity['order_items'][0]['has_custom_price'] = true;
        $quantity['order_items'][0]['unit_price'] = 9000000000000000;
        $quantity['order_items'][0]['quantity'] = 2;
        $this->assertInvalidAmount(AdminOrderPricing::price($quantity), 'in-range price x 2');
    }

    public function test_a_posted_is_custom_flag_does_not_skip_catalog_pricing(): void
    {
        $payload = $this->payload('JPY', 5000);
        $payload['shipping_total'] = 0;
        $payload['order_items'][0]['is_custom'] = true;
        $payload['order_items'][0]['unit_price'] = 100;

        $pricing = AdminOrderPricing::price($payload);

        $this->assertIsArray($pricing, is_wp_error($pricing) ? $pricing->get_error_message() : '');
        $this->assertSame(750000, $pricing['items'][0]['unit_price'], '$50.00 x 150, not the posted ¥1');
    }

    public function test_a_coupon_applied_on_the_create_screen_is_priced_in_the_chosen_currency(): void
    {
        $coupon = (new CouponFactory())->create(['code' => 'SCREEN-' . wp_generate_password(6, false), 'amount' => 1000]);
        $item = $this->payload('GBP', 5000)['order_items'][0];

        $applied = CouponResource::applyCoupon([
            'coupon_code' => $coupon->code,
            'currency'    => 'GBP',
            'order_items' => [array_merge($item, [
                'unit_price'     => 3950,
                'subtotal'       => 3950,
                'line_total'     => 3950,
                'discount_total' => 0,
            ])],
        ]);

        $this->assertIsArray($applied, is_wp_error($applied) ? $applied->get_error_message() : '');
        $this->assertSame(790, (int) round($applied['applied_coupons'][$coupon->code]['discount']), '$10.00 off is £7.90');
    }

    public function test_all_lines_load_their_products_in_one_batch(): void
    {
        $payload = $this->payload('GBP', 5000);
        $payload['shipping_total'] = 0;
        foreach ([2000, 3000] as $price) {
            $payload['order_items'][] = $this->payload('GBP', $price)['order_items'][0];
        }
        $payload['order_items'][] = $payload['order_items'][0];

        global $wpdb;
        $loads = ['fct_product_variations' => 0, 'fct_product_details' => 0, 'fct_product_meta' => 0, 'posts' => 0];
        $count = function ($sql) use ($wpdb, &$loads) {
            foreach (array_keys($loads) as $table) {
                if (preg_match('/^select (?!.* as aggregate ).*? from `' . $wpdb->prefix . $table . '`/i', $sql)) {
                    $loads[$table]++;
                }
            }
            return $sql;
        };
        add_filter('query', $count);
        $pricing = AdminOrderPricing::price($payload);
        remove_filter('query', $count);

        $this->assertIsArray($pricing, is_wp_error($pricing) ? $pricing->get_error_message() : '');
        $this->assertSame([3950, 1580, 2370, 3950], array_column($pricing['items'], 'unit_price'), 'a repeated product prices on both lines');
        $this->assertSame(['fct_product_variations' => 1, 'fct_product_details' => 0, 'fct_product_meta' => 0, 'posts' => 1], $loads, 'four lines, one variation and one product load');
    }

    public function test_more_lines_run_no_more_queries(): void
    {
        AdminOrderPricing::price($this->payload('GBP', 1000));

        $one = $this->payload('GBP', 5000);
        $one['shipping_total'] = 0;
        $four = $this->payload('GBP', 5000);
        $four['shipping_total'] = 0;
        foreach ([2000, 3000, 4000] as $price) {
            $four['order_items'][] = $this->payload('GBP', $price)['order_items'][0];
        }

        $queries = 0;
        $count = function ($sql) use (&$queries) {
            $queries++;
            return $sql;
        };
        add_filter('query', $count);
        $this->assertIsArray(AdminOrderPricing::price($one));
        $forOne = $queries;
        $queries = 0;
        $this->assertIsArray(AdminOrderPricing::price($four));
        remove_filter('query', $count);

        $this->assertSame($forOne, $queries, 'four products cost the same queries as one');
    }

    public function test_a_line_whose_product_is_gone_is_rejected(): void
    {
        $payload = $this->payload('GBP', 5000);
        $payload['order_items'][] = array_merge($payload['order_items'][0], ['object_id' => PHP_INT_MAX]);

        $result = AdminOrderPricing::price($payload);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_product', $result->get_error_code());
    }

    private function assertPricesUpdated(array $payload): void
    {
        $ordersBefore = Order::query()->count();

        $result = OrderResource::updatedPlaceOrder($payload);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('fx_prices_updated', $result->get_error_code());
        $this->assertSame($ordersBefore, Order::query()->count(), 'no order is created');
    }

    private function assertInvalidAmount($result, string $message): void
    {
        $this->assertInstanceOf(\WP_Error::class, $result, $message);
        $this->assertSame('invalid_amount', $result->get_error_code(), $message);
    }

    private function trialPlusOnetimePayload(): array
    {
        $payload = $this->payload('GBP', 5000);
        $variant = ProductVariation::query()->find($payload['order_items'][0]['object_id']);
        $variant->other_info = array_merge((array) $variant->other_info, [
            'payment_type'    => 'subscription',
            'repeat_interval' => 'monthly',
            'trial_days'      => 14,
        ]);
        $variant->save();
        $payload['order_items'][0]['payment_type'] = 'subscription';
        $payload['order_items'][0]['other_info'] = ['payment_type' => 'subscription'];

        $payload['order_items'][] = $this->payload('GBP', 1000)['order_items'][0];
        $payload['shipping_total'] = 0;

        return $payload;
    }

    private function payload(string $currency, int $unitPrice): array
    {
        $customer = (new CustomerFactory())->create();
        $product = (new ProductFactory())->withVariantAttributes(['item_price' => $unitPrice])->create();
        $variant = $product->variants->first();

        return [
            'customer_id'    => $customer->id,
            'currency'       => $currency,
            'order_items'    => [[
                'id'           => $variant->id,
                'variation_id' => $variant->id,
                'type'         => 'product',
                'post_id'      => $product->ID,
                'object_id'    => $variant->id,
                'title'        => 'Default',
                'post_title'   => 'Product',
                'unit_price'   => $unitPrice,
                'quantity'     => 1,
                'payment_type' => 'onetime',
                'other_info'   => ['payment_type' => 'onetime'],
            ]],
            'shipping_total' => 500,
            'discount'       => [],
            'shipping'       => [],
            'labels'         => [],
            'user_tz'        => 'UTC',
        ];
    }
}

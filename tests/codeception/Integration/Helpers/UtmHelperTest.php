<?php

namespace Tests\Integration\Helpers;

use FluentCart\App\Helpers\CheckoutProcessor;
use FluentCart\App\Helpers\UtmHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderOperation;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\CustomerFactory;

/**
 * Traffic source persistence onto the order.
 *
 * The click that produced a sale is only ever held on the cart, in utm_data, and carts
 * are pruned on a schedule. Nothing copied it onto the order: addUtmToOrder() had no
 * callers, so gclid never reached fct_order_operations and revenue could not be matched
 * back to the ad that earned it.
 */
class UtmHelperTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private CartFactory $cartFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->cartFactory = new CartFactory();
    }

    private function makeItem(array $overrides = []): array
    {
        return array_merge([
            'object_id'        => 1,
            'post_id'          => 1,
            'quantity'         => 1,
            'unit_price'       => 5000,
            'price'            => 5000,
            'subtotal'         => 5000,
            'line_total'       => 5000,
            'discount_total'   => 0,
            'coupon_discount'  => 0,
            'manual_discount'  => 0,
            'tax_amount'       => 0,
            'shipping_charge'  => 0,
            'cost'             => 0,
            'post_title'       => 'Test Product',
            'product_title'    => 'Test Product',
            'title'            => 'Default',
            'variation_title'  => 'Default',
            'fulfillment_type' => 'digital',
            'other_info'       => ['payment_type' => 'onetime'],
        ], $overrides);
    }

    /** Run a checkout against a cart carrying the given source data. */
    private function checkoutWithUtmData(array $utmData)
    {
        $customer = $this->customerFactory->create();
        $cart = $this->cartFactory->create(['utm_data' => $utmData]);

        $processor = new CheckoutProcessor([$this->makeItem()], [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
            'cart_hash'      => $cart->cart_hash,
        ]);

        return [$processor->createDraftOrder(), $cart];
    }

    private function operationFor($orderId): ?OrderOperation
    {
        return OrderOperation::query()->where('order_id', $orderId)->first();
    }

    // =========================================================================
    // Checkout copies the source onto the order
    // =========================================================================

    public function test_checkout_stores_click_id_from_the_cart_on_the_order(): void
    {
        [$order] = $this->checkoutWithUtmData([
            'utm_source'   => 'google',
            'utm_medium'   => 'cpc',
            'utm_campaign' => 'ffppc0525',
            'gclid'        => 'Cj0KCQjwtest',
        ]);

        $operation = $this->operationFor($order->id);

        $this->assertNotNull($operation, 'checkout must record the order operation row');
        $this->assertEquals('google', $operation->utm_source);
        $this->assertEquals('ffppc0525', $operation->utm_campaign);
        $this->assertEquals('Cj0KCQjwtest', $operation->meta['gclid']);
    }

    public function test_checkout_records_the_cart_hash_on_the_order(): void
    {
        [$order, $cart] = $this->checkoutWithUtmData(['utm_source' => 'google']);

        $this->assertEquals($cart->cart_hash, $this->operationFor($order->id)->cart_hash);
    }

    /**
     * ReceiptHandler reads sales_recorded off this row to decide whether a receipt is
     * being seen for the first time, and that gates fluent_cart/after_receipt_first_time.
     * An order with no source must still get its row, or the purchase event never fires
     * for organic and direct traffic.
     */
    public function test_untracked_order_still_gets_its_operation_row(): void
    {
        [$order] = $this->checkoutWithUtmData([]);

        $operation = $this->operationFor($order->id);

        $this->assertNotNull($operation, 'an order with no source must still have its row');
        $this->assertEmpty($operation->utm_source);
    }

    // =========================================================================
    // addUtmToOrder directly
    // =========================================================================

    public function test_click_ids_go_to_meta_and_utm_fields_go_to_columns(): void
    {
        [$order] = $this->checkoutWithUtmData([]);

        UtmHelper::addUtmToOrder($order->id, [
            'utm_campaign' => 'spring',
            'gclid'        => 'AAA',
            'gbraid'       => 'GB1',
        ]);

        $operation = $this->operationFor($order->id);

        $this->assertEquals('spring', $operation->utm_campaign);
        $this->assertEquals('AAA', $operation->meta['gclid']);
        $this->assertEquals('GB1', $operation->meta['gbraid']);
    }

    public function test_unlisted_parameters_are_discarded(): void
    {
        [$order] = $this->checkoutWithUtmData([]);

        UtmHelper::addUtmToOrder($order->id, [
            'gclid'          => 'AAA',
            'evil_param'     => 'dropped',
        ]);

        $this->assertArrayNotHasKey('evil_param', $this->operationFor($order->id)->meta);
    }

    /**
     * Arr::only() kept keys that were present but blank, so a later call carrying an
     * empty utm_source overwrote a good one with ''.
     */
    public function test_empty_values_do_not_overwrite_a_recorded_source(): void
    {
        [$order] = $this->checkoutWithUtmData(['utm_source' => 'google', 'gclid' => 'AAA']);

        UtmHelper::addUtmToOrder($order->id, ['utm_source' => '', 'utm_campaign' => 'later']);

        $operation = $this->operationFor($order->id);

        $this->assertEquals('google', $operation->utm_source);
        $this->assertEquals('later', $operation->utm_campaign);
        $this->assertEquals('AAA', $operation->meta['gclid']);
    }

    public function test_calling_twice_updates_one_row_rather_than_adding_another(): void
    {
        [$order] = $this->checkoutWithUtmData(['utm_source' => 'google']);

        UtmHelper::addUtmToOrder($order->id, ['utm_campaign' => 'spring']);
        UtmHelper::addUtmToOrder($order->id, ['utm_campaign' => 'summer']);

        $rows = OrderOperation::query()->where('order_id', $order->id)->get();

        $this->assertCount(1, $rows);
        $this->assertEquals('summer', $rows->first()->utm_campaign);
    }

    public function test_nothing_to_record_creates_nothing(): void
    {
        [$order] = $this->checkoutWithUtmData([]);
        OrderOperation::query()->where('order_id', $order->id)->delete();

        $this->assertNull(UtmHelper::addUtmToOrder($order->id, []));
        $this->assertNull($this->operationFor($order->id));
    }

    // =========================================================================
    // The row exists for every order, whatever created it
    // =========================================================================

    /**
     * Renewals, subscription child orders, the admin and WP-CLI all build orders
     * directly, without a cart and without dispatching fluent_cart/order_created. They
     * must still get the companion row, or their receipts never fire the purchase event.
     */
    public function test_an_order_created_outside_the_checkout_still_gets_its_row(): void
    {
        $customer = $this->customerFactory->create();

        $order = Order::query()->create([
            'customer_id'    => $customer->id,
            'status'         => 'draft',
            'payment_status' => 'pending',
            'currency'       => 'USD',
            'total_amount'   => 5000,
            'mode'           => 'test',
        ]);

        $this->assertNotNull($this->operationFor($order->id));
    }

    public function test_the_companion_row_is_not_duplicated_by_the_checkout(): void
    {
        [$order] = $this->checkoutWithUtmData(['utm_source' => 'google', 'gclid' => 'AAA']);

        $rows = OrderOperation::query()->where('order_id', $order->id)->get();

        $this->assertCount(1, $rows);
        $this->assertEquals('AAA', $rows->first()->meta['gclid']);
    }

    /**
     * The browser resolves attribution before it posts: UTMManager replaces the
     * attribution block on a fresh touch and carries click identifiers forward on
     * their own window, so the posted block is already the finished answer. Merging
     * it with the cart column key by key can only re-introduce a touch the browser
     * deliberately discarded, because the cart row survives across visits.
     */
    public function test_the_posted_block_is_taken_whole_rather_than_merged_with_the_cart(): void
    {
        $resolved = UtmHelper::resolveUtmData(
            ['gclid' => 'NEW', 'utm_source' => 'newsletter'],
            ['gclid' => 'OLD', 'utm_source' => 'google', 'utm_campaign' => 'spring']
        );

        $this->assertEquals(['gclid' => 'NEW', 'utm_source' => 'newsletter'], $resolved, 'a campaign the visitor did not arrive on must not survive into the resolved block');
        $this->assertArrayNotHasKey('utm_campaign', $resolved, 'the stale campaign is exactly what a per-key merge would have kept');
    }

    public function test_the_cart_is_used_when_the_request_carries_no_attribution(): void
    {
        $cartData = ['utm_source' => 'google', 'gclid' => 'AAA'];

        $this->assertEquals($cartData, UtmHelper::resolveUtmData([], $cartData), 'a non-browser order creation has only the cart to fall back on');
    }

    public function test_nothing_is_resolved_when_neither_side_has_data(): void
    {
        $this->assertEquals([], UtmHelper::resolveUtmData([], []));
        $this->assertEquals([], UtmHelper::resolveUtmData([], null), 'a cart with a null utm_data column must not fatal');
    }
}

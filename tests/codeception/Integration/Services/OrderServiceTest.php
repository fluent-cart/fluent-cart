<?php

namespace Tests\Integration\Services;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Services\OrderService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductFactory;

class OrderServiceTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private OrderItemFactory $itemFactory;
    private ProductFactory $productFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
        $this->itemFactory = new OrderItemFactory();
        $this->productFactory = new ProductFactory();
    }

    // =========================================================================
    // Spec 115-117: Data Grouping & Sanitization
    // =========================================================================

    public function test_group_sanitized_data_separates_billing_and_shipping(): void
    {
        $data = [
            'billing_full_name' => 'John Doe',
            'billing_address_1' => '123 Main St',
            'billing_city'      => 'New York',
            'billing_country'   => 'US',
            'shipping_full_name' => 'Jane Doe',
            'shipping_address_1' => '456 Oak Ave',
            'shipping_city'      => 'LA',
            'shipping_country'   => 'US',
            'ship_to_different'  => 'yes',
        ];

        $result = OrderService::groupSanitizedData($data);

        $this->assertArrayHasKey('billing_address', $result);
        $this->assertArrayHasKey('shipping_address', $result);
        $this->assertSame('billing', $result['billing_address']['type']);
        $this->assertSame('shipping', $result['shipping_address']['type']);
        $this->assertStringContainsString('Main St', $result['billing_address']['address_1']);
        $this->assertStringContainsString('Oak Ave', $result['shipping_address']['address_1']);
    }

    public function test_group_sanitized_data_copies_billing_to_shipping_when_not_different(): void
    {
        $data = [
            'billing_full_name' => 'John Doe',
            'billing_address_1' => '123 Main St',
            'billing_city'      => 'New York',
            'billing_country'   => 'US',
            'ship_to_different'  => 'no',
        ];

        $result = OrderService::groupSanitizedData($data);

        $this->assertSame(
            $result['billing_address']['address_1'],
            $result['shipping_address']['address_1']
        );
    }

    public function test_sanitize_email_or_text_uses_correct_sanitizer(): void
    {
        $email = OrderService::sanitizeEmailOrText('email', 'TEST@EXAMPLE.COM');
        $text = OrderService::sanitizeEmailOrText('name', '<script>alert(1)</script>');

        // sanitize_email strips invalid chars, sanitize_text_field strips tags
        $this->assertSame(sanitize_email('TEST@EXAMPLE.COM'), $email);
        $this->assertStringNotContainsString('<script>', $text);
    }

    // =========================================================================
    // Spec 118-123: Product Validation
    // =========================================================================

    public function test_validate_products_throws_when_variation_not_found(): void
    {
        $this->expectException(\Exception::class);

        OrderService::validateProducts([
            [
                'object_id' => 999999,
                'title'     => 'Missing Product',
                'quantity'  => 1,
            ],
        ]);
    }

    public function test_validate_products_throws_when_product_not_published(): void
    {
        $product = $this->productFactory->create(['post_status' => 'draft']);
        $variant = $product->variants->first();

        $this->expectException(\Exception::class);

        OrderService::validateProducts([
            [
                'object_id' => $variant->id,
                'title'     => $product->post_title,
                'quantity'  => 1,
            ],
        ]);
    }

    public function test_validate_products_throws_for_subscription_with_quantity_gt_1(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('cannot purchase multiple quantities');

        OrderService::validateProducts([
            [
                'object_id'  => $variant->id,
                'title'      => $product->post_title,
                'quantity'   => 2,
                'other_info' => ['payment_type' => 'subscription'],
            ],
        ]);
    }

    public function test_validate_products_skips_custom_items(): void
    {
        // A custom item is validated at cart insertion, so an object_id of 0 must
        // not raise here. The WP test suite turns wp_die()/wp_send_json() into an
        // exception, so completing without one is the whole contract.
        $this->expectNotToPerformAssertions();
        OrderService::validateProducts([
            [
                'object_id'  => 0,
                'title'      => 'Custom Item',
                'quantity'   => 1,
                'is_custom'  => true,
            ],
        ]);
    }

    public function test_validate_products_throws_when_out_of_stock(): void
    {
        // Enable stock management module for this test
        $this->enableStockManagement();

        $product = $this->productFactory
            ->withVariantAttributes([
                'manage_stock' => 1,
                'stock_status' => 'out-of-stock',
                'total_stock'  => 0,
                'available'    => 0,
            ])
            ->create();
        $variant = $product->variants->first();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('out of stock');

        OrderService::validateProducts([
            [
                'object_id' => $variant->id,
                'title'     => $product->post_title,
                'quantity'  => 1,
            ],
        ]);
    }

    public function test_validate_products_throws_when_quantity_exceeds_stock(): void
    {
        $this->enableStockManagement();

        $product = $this->productFactory
            ->withVariantAttributes([
                'manage_stock' => 1,
                'stock_status' => 'in-stock',
                'total_stock'  => 2,
                'available'    => 2,
            ])
            ->create();
        $variant = $product->variants->first();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('out of stock');

        OrderService::validateProducts([
            [
                'object_id' => $variant->id,
                'title'     => $product->post_title,
                'quantity'  => 5,
            ],
        ]);
    }

    /**
     * Enable stock_management module and clear the static cache.
     */
    private function enableStockManagement(): void
    {
        $settings = get_option(\FluentCart\Api\ModuleSettings::MODULE_SETTINGS_OPTION, []);
        if (!is_array($settings)) {
            $settings = [];
        }
        $settings['stock_management'] = ['active' => 'yes'];
        update_option(\FluentCart\Api\ModuleSettings::MODULE_SETTINGS_OPTION, $settings);
        // Clear ModuleSettings static cache by fetching with $cached = false
        \FluentCart\Api\ModuleSettings::getAllSettings(false);
    }

    // =========================================================================
    // Spec 124-132: Amount Calculations
    // =========================================================================

    public function test_get_items_amount_without_discount_sums_unit_price_times_quantity(): void
    {
        $items = [
            ['unit_price' => 5000, 'quantity' => 2, 'payment_type' => 'onetime'],
            ['unit_price' => 3000, 'quantity' => 1, 'payment_type' => 'onetime'],
        ];

        $total = OrderService::getItemsAmountWithoutDiscount($items);
        $this->assertEquals(13000, $total);
    }

    public function test_get_items_amount_without_discount_includes_signup_fee_for_subscription(): void
    {
        $items = [
            [
                'unit_price'    => 5000,
                'quantity'      => 1,
                'payment_type'  => 'subscription',
                'other_info'    => [
                    'payment_type' => 'subscription',
                    'signup_fee'   => 1000,
                    'trial_days'   => 0,
                ],
            ],
        ];

        $total = OrderService::getItemsAmountWithoutDiscount($items);
        $this->assertEquals(6000, $total); // 5000 + 1000 signup fee
    }

    public function test_get_items_amount_without_discount_trial_returns_only_signup_fee(): void
    {
        $items = [
            [
                'unit_price'    => 5000,
                'quantity'      => 1,
                'payment_type'  => 'subscription',
                'other_info'    => [
                    'payment_type' => 'subscription',
                    'signup_fee'   => 1000,
                    'trial_days'   => 14,
                ],
            ],
        ];

        $total = OrderService::getItemsAmountWithoutDiscount($items);
        $this->assertEquals(1000, $total); // Only signup fee
    }

    public function test_get_items_amount_total_subtracts_discount(): void
    {
        $items = [
            ['subtotal' => 5000, 'discount_total' => 500, 'other_info' => []],
            ['subtotal' => 3000, 'discount_total' => 0, 'other_info' => []],
        ];

        $total = OrderService::getItemsAmountTotal($items, false, false);
        $this->assertEquals(7500, $total);
    }

    public function test_get_items_amount_total_trial_charges_signup_minus_discount(): void
    {
        $items = [
            [
                'subtotal'       => 5000,
                'discount_total' => 200,
                'other_info'     => [
                    'trial_days'  => 14,
                    'signup_fee'  => 1000,
                ],
            ],
        ];

        $total = OrderService::getItemsAmountTotal($items, false, false);
        $this->assertEquals(800, $total); // signup_fee(1000) - discount(200)
    }

    public function test_get_items_amount_total_adds_shipping(): void
    {
        $items = [
            ['subtotal' => 5000, 'discount_total' => 0, 'other_info' => []],
        ];

        $total = OrderService::getItemsAmountTotal($items, false, false, 1000);
        $this->assertEquals(6000, $total);
    }

    public function test_get_items_amount_total_clamps_to_zero(): void
    {
        $items = [
            ['subtotal' => 100, 'discount_total' => 500, 'other_info' => []],
        ];

        $total = OrderService::getItemsAmountTotal($items, false, false);
        $this->assertEquals(0, $total);
    }

    public function test_get_items_amount_total_formatted_returns_string(): void
    {
        $items = [
            ['subtotal' => 5000, 'discount_total' => 0, 'other_info' => []],
        ];

        $formatted = OrderService::getItemsAmountTotal($items, true, false);
        $this->assertIsString($formatted);
        $this->assertStringContainsString('50', $formatted, '5000 cents formats as 50 in the store currency');
    }

    public function test_get_coupon_discount_total_sums_discount(): void
    {
        $items = [
            ['discount_total' => 500],
            ['discount_total' => 300],
            ['discount_total' => 200],
        ];

        $this->assertEquals(1000, OrderService::getCouponDiscountTotal($items));
    }

    // =========================================================================
    // Spec 133-135: Line Item Preparation
    // =========================================================================

    public function test_make_line_items_separates_subscription_from_regular(): void
    {
        $items = [
            [
                'post_id'      => 1,
                'object_id'    => 10,
                'payment_type' => 'onetime',
                'unit_price'   => 5000,
                'quantity'     => 1,
                'line_total'   => 5000,
                'title'        => 'Regular Product',
                'post_title'   => 'Product A',
                'other_info'   => [],
            ],
            [
                'post_id'      => 2,
                'object_id'    => 20,
                'payment_type' => 'subscription',
                'unit_price'   => 3000,
                'quantity'     => 1,
                'line_total'   => 3000,
                'title'        => 'Sub Plan',
                'post_title'   => 'Product B',
                'other_info'   => [
                    'repeat_interval' => 'monthly',
                    'signup_fee'      => 500,
                    'times'           => 12,
                    'trial_days'      => 0,
                ],
            ],
        ];

        $result = OrderService::makeLineItemsFromOrderItems($items);

        $this->assertCount(1, $result['items']);
        $this->assertCount(1, $result['subscriptionItems']);

        $sub = $result['subscriptionItems'][0];
        $this->assertSame('monthly', $sub['billing_interval']);
        $this->assertSame(500, $sub['signup_fee']);
        $this->assertSame(12, $sub['bill_times']);
    }

    public function test_make_line_items_prepares_regular_item_fields(): void
    {
        $items = [
            [
                'post_id'      => 1,
                'object_id'    => 10,
                'payment_type' => 'onetime',
                'unit_price'   => 5000,
                'quantity'     => 2,
                'line_total'   => 10000,
                'title'        => 'Widget',
                'post_title'   => 'Widgets Inc',
                'other_info'   => [],
            ],
        ];

        $result = OrderService::makeLineItemsFromOrderItems($items);
        $regular = $result['items'][0];

        $this->assertSame(1, $regular['product_id']);
        $this->assertEquals(5000.0, $regular['unit_price']);
        $this->assertSame(2, $regular['quantity']);
        $this->assertEquals(10000.0, $regular['line_total']);
    }

    // =========================================================================
    // Spec 136-141: Utility Methods
    // =========================================================================

    public function test_get_next_receipt_number_increments(): void
    {
        $customer = $this->customerFactory->create();
        $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PAID,
        ]);

        $maxReceipt = Order::query()->max('receipt_number');
        $next = OrderService::getNextReceiptNumber();

        $this->assertSame($maxReceipt + 1, $next);
    }

    public function test_get_next_receipt_number_respects_min_receipt_filter(): void
    {
        // Use the filter to set a high minimum receipt number
        add_filter('fluent_cart/min_receipt_number', function () {
            return 500;
        });

        $next = OrderService::getNextReceiptNumber();
        $this->assertGreaterThanOrEqual(500, $next);
    }

    public function test_get_invoice_prefix_returns_configured_prefix(): void
    {
        $prefix = OrderService::getInvoicePrefix();
        $this->assertIsString($prefix);
        $this->assertNotEmpty($prefix);
    }

    public function test_get_root_order_id_follows_parent_chain(): void
    {
        $customer = $this->customerFactory->create();
        $root = $this->orderFactory->create(['customer_id' => $customer->id]);
        $child = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'parent_id'   => $root->id,
        ]);
        $grandchild = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'parent_id'   => $child->id,
        ]);

        $this->assertSame($root->id, OrderService::getRootOrderId($grandchild));
    }

    public function test_get_root_order_id_breaks_infinite_loop(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        // Self-referencing parent (edge case)
        Order::query()->where('id', $order->id)->update(['parent_id' => $order->id]);
        $order = Order::query()->find($order->id);

        // Should return null to break the cycle
        $result = OrderService::getRootOrderId($order);
        $this->assertNull($result);
    }

    public function test_pluck_product_ids_returns_post_ids(): void
    {
        $customer = $this->customerFactory->create();
        $product1 = $this->productFactory->create();
        $product2 = $this->productFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $this->itemFactory->create($order->id, ['post_id' => $product1->ID]);
        $this->itemFactory->create($order->id, ['post_id' => $product2->ID]);

        $order->load('order_items');
        $ids = OrderService::pluckProductIds($order);

        $this->assertCount(2, $ids);
        // array_column returns string values; compare loosely
        $this->assertContains((string) $product1->ID, array_map('strval', $ids));
        $this->assertContains((string) $product2->ID, array_map('strval', $ids));
    }
}

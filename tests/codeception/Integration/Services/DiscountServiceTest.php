<?php

namespace Tests\Integration\Services;

use FluentCart\App\Models\AppliedCoupon;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Coupon;
use FluentCart\App\Services\Coupon\DiscountService;
use FluentCart\Framework\Support\Arr;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\CouponFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

class DiscountServiceTest extends DatabaseTestCase
{
    private CartFactory $cartFactory;
    private CouponFactory $couponFactory;
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->cartFactory = new CartFactory();
        $this->couponFactory = new CouponFactory();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
    }

    // =========================================================================
    // Spec 29-30: Constructor
    // =========================================================================

    public function test_constructor_uses_cart_data_when_no_items_passed(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 5000, 'subtotal' => 5000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();

        $service = new DiscountService($cart);
        $items = $service->getCartItems();

        $this->assertCount(1, $items);
        $this->assertEquals(5000, $items[0]['unit_price']);
    }

    public function test_constructor_uses_explicit_items_over_cart_data(): void
    {
        $cartItem = CartFactory::makeCartItem(['unit_price' => 5000, 'subtotal' => 5000]);
        $cart = $this->cartFactory->withCartItems([$cartItem])->create();

        $explicitItem = CartFactory::makeCartItem(['unit_price' => 9000, 'subtotal' => 9000]);
        $service = new DiscountService($cart, [$explicitItem]);
        $items = $service->getCartItems();

        $this->assertCount(1, $items);
        $this->assertEquals(9000, $items[0]['unit_price']);
    }

    // =========================================================================
    // Spec 31: resetIndividualItemsDiscounts
    // =========================================================================

    public function test_reset_individual_items_discounts(): void
    {
        $item = CartFactory::makeCartItem([
            'unit_price'      => 5000,
            'subtotal'        => 5000,
            'coupon_discount' => 1000,
            'manual_discount' => 500,
            'discount_total'  => 1500,
            'line_total'      => 3500,
        ]);
        $cart = $this->cartFactory->withCartItems([$item])->create();

        $service = new DiscountService($cart);
        $service->resetIndividualItemsDiscounts();

        $items = $service->getCartItems();
        $this->assertSame(0, (int) $items[0]['coupon_discount']);
        $this->assertSame(500, (int) $items[0]['manual_discount']);
        $this->assertSame(500, (int) $items[0]['discount_total']); // only manual_discount
        $this->assertSame(4500, (int) $items[0]['line_total']);     // 5000 - 500
    }

    // =========================================================================
    // Spec 32: applyCouponCodes with valid coupon
    // =========================================================================

    public function test_apply_coupon_codes_with_valid_coupon(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 10000, 'subtotal' => 10000, 'line_total' => 10000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();
        $coupon = $this->couponFactory->create([
            'code' => 'SAVE10', 'type' => 'fixed', 'amount' => 1000,
        ]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['SAVE10']);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('applied_coupon_codes', $result);
        $this->assertArrayHasKey('coupon_results', $result);
        $this->assertArrayHasKey('cart_items', $result);
        $this->assertArrayHasKey('per_coupon_discounts', $result);
        $this->assertContains('SAVE10', $result['applied_coupon_codes']);
    }

    // =========================================================================
    // Spec 33: Merges existing cart coupons with new codes
    // =========================================================================

    public function test_apply_coupon_codes_merges_with_existing(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 10000, 'subtotal' => 10000, 'line_total' => 10000]);
        $this->couponFactory->create(['code' => 'EXISTING', 'type' => 'fixed', 'amount' => 500]);
        $this->couponFactory->create(['code' => 'NEW_ONE', 'type' => 'fixed', 'amount' => 300]);

        $cart = $this->cartFactory->withCartItems([$item])->create(['coupons' => ['EXISTING']]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['NEW_ONE']);

        $this->assertContains('EXISTING', $result['applied_coupon_codes']);
        $this->assertContains('NEW_ONE', $result['applied_coupon_codes']);
    }

    // =========================================================================
    // Spec 34: Returns WP_Error when no active coupons found
    // =========================================================================

    public function test_apply_coupon_codes_returns_error_for_nonexistent(): void
    {
        $item = CartFactory::makeCartItem();
        $cart = $this->cartFactory->withCartItems([$item])->create();

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['DOESNOTEXIST']);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // =========================================================================
    // Spec 35: Non-stackable coupon handling
    // =========================================================================

    public function test_non_stackable_coupon_invalidated_when_multiple(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 10000, 'subtotal' => 10000, 'line_total' => 10000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();

        $this->couponFactory->create(['code' => 'STACK', 'stackable' => 'yes', 'amount' => 500]);
        $this->couponFactory->create(['code' => 'NOSTACK', 'stackable' => 'no', 'amount' => 300]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['STACK', 'NOSTACK']);

        $this->assertContains('STACK', $result['applied_coupon_codes']);
        // NOSTACK should be in coupon_results as invalid
        $this->assertArrayHasKey('NOSTACK', $result['coupon_results']);
        $nostackResult = $result['coupon_results']['NOSTACK'];
        $this->assertFalse(Arr::get($nostackResult, 'success', false));
    }

    // =========================================================================
    // Spec 36: Priority ordering
    // =========================================================================

    public function test_stackable_coupons_applied_in_priority_order(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 10000, 'subtotal' => 10000, 'line_total' => 10000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();

        $this->couponFactory->create(['code' => 'LOW_PRI', 'priority' => 10, 'amount' => 500]);
        $this->couponFactory->create(['code' => 'HIGH_PRI', 'priority' => 1, 'amount' => 300]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['LOW_PRI', 'HIGH_PRI']);

        $appliedCodes = $result['applied_coupon_codes'];
        $highIdx = array_search('HIGH_PRI', $appliedCodes);
        $lowIdx = array_search('LOW_PRI', $appliedCodes);
        $this->assertLessThan($lowIdx, $highIdx, 'Higher priority (lower number) should be applied first');
    }

    // =========================================================================
    // Spec 38-41: Coupon date validation
    // =========================================================================

    public function test_rejects_coupon_not_yet_started(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 5000, 'subtotal' => 5000, 'line_total' => 5000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();
        $this->couponFactory->create([
            'code'       => 'FUTURE',
            'start_date' => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['FUTURE']);
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_accepts_scheduled_coupon_after_its_start_and_stores_active(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 5000, 'subtotal' => 5000, 'line_total' => 5000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();
        $coupon = $this->couponFactory->create([
            'code'       => 'STARTED',
            'status'     => 'scheduled',
            'start_date' => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
            'end_date'   => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS),
        ]);

        $result = (new DiscountService($cart))->applyCouponCodes(['STARTED']);

        $this->assertIsArray($result, 'The start time has passed: no admin has to open the coupon list first');
        $this->assertContains('STARTED', $result['applied_coupon_codes']);
        $this->assertSame('active', Coupon::query()->find($coupon->id)->status, 'The stored status follows');
    }

    public function test_rejects_a_scheduled_coupon_that_started_and_already_ended_without_a_write(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 5000, 'subtotal' => 5000, 'line_total' => 5000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();
        $coupon = $this->couponFactory->create([
            'code'       => 'OVER',
            'status'     => 'scheduled',
            'start_date' => gmdate('Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS),
            'end_date'   => gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS),
        ]);

        $result = (new DiscountService($cart))->applyCouponCodes(['OVER']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('coupon_expired', array_values($result->get_error_data())[0]['error_code']);
        $this->assertSame('scheduled', Coupon::query()->find($coupon->id)->status, 'An ended coupon is not marked active');
    }

    public function test_a_started_virtual_scheduled_coupon_is_active_for_later_filters_and_is_not_stored(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 5000, 'subtotal' => 5000, 'line_total' => 5000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();

        // An add-on resolves a code to an unsaved coupon, as a wallet or store-credit integration does
        $virtual = new Coupon();
        $virtual->forceFill([
            'code' => 'VIRTUAL', 'title' => 'Virtual', 'type' => 'fixed', 'amount' => 500, 'status' => 'scheduled',
            'stackable' => 'no', 'priority' => 1, 'conditions' => [],
            'start_date' => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
        ]);
        $resolve = function ($coupons) use ($virtual) {
            return $coupons->push($virtual);
        };
        $seen = null;
        $spy  = function ($canUse, $data) use (&$seen) {
            $seen = $data['coupon']->status;
            return $canUse;
        };
        add_filter('fluent_cart/coupon/resolve_coupons', $resolve);
        add_filter('fluent_cart/coupon/can_use_coupon', $spy, 10, 2);
        try {
            $result = (new DiscountService($cart))->applyCouponCodes(['VIRTUAL']);
        } finally {
            remove_filter('fluent_cart/coupon/resolve_coupons', $resolve);
            remove_filter('fluent_cart/coupon/can_use_coupon', $spy, 10);
        }

        $this->assertIsArray($result);
        $this->assertSame('active', $seen, 'The filter sees the status checkout used');
        $this->assertFalse(Coupon::query()->where('code', 'VIRTUAL')->exists(), 'A virtual coupon has no row to write');
    }

    public function test_rejects_scheduled_coupon_before_its_start(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 5000, 'subtotal' => 5000, 'line_total' => 5000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();
        $coupon = $this->couponFactory->create([
            'code'       => 'NOTYET',
            'status'     => 'scheduled',
            'start_date' => gmdate('Y-m-d H:i:s', time() + HOUR_IN_SECONDS),
        ]);

        $result = (new DiscountService($cart))->applyCouponCodes(['NOTYET']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('scheduled', Coupon::query()->find($coupon->id)->status);
    }

    public function test_rejects_expired_coupon(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 5000, 'subtotal' => 5000, 'line_total' => 5000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();
        $this->couponFactory->create([
            'code'     => 'EXPIRED',
            'end_date' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['EXPIRED']);
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_accepts_coupon_with_no_dates(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 5000, 'subtotal' => 5000, 'line_total' => 5000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();
        $this->couponFactory->create([
            'code'       => 'NODATES',
            'start_date' => null,
            'end_date'   => null,
        ]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['NODATES']);
        $this->assertIsArray($result);
        $this->assertContains('NODATES', $result['applied_coupon_codes']);
    }

    public function test_accepts_coupon_within_date_range(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 5000, 'subtotal' => 5000, 'line_total' => 5000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();
        $this->couponFactory->create([
            'code'       => 'INRANGE',
            'start_date' => date('Y-m-d H:i:s', strtotime('-7 days')),
            'end_date'   => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['INRANGE']);
        $this->assertIsArray($result);
        $this->assertContains('INRANGE', $result['applied_coupon_codes']);
    }

    // =========================================================================
    // Spec 44-46: Use count / max_uses
    // =========================================================================

    public function test_rejects_coupon_at_max_uses(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 5000, 'subtotal' => 5000, 'line_total' => 5000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();
        $this->couponFactory->create([
            'code'       => 'MAXED',
            'use_count'  => 5,
            'conditions' => ['max_uses' => 5],
        ]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['MAXED']);
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_accepts_coupon_below_max_uses(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 5000, 'subtotal' => 5000, 'line_total' => 5000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();
        $this->couponFactory->create([
            'code'       => 'BELOW',
            'use_count'  => 2,
            'conditions' => ['max_uses' => 5],
        ]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['BELOW']);
        $this->assertIsArray($result);
        $this->assertContains('BELOW', $result['applied_coupon_codes']);
    }

    public function test_accepts_coupon_with_no_max_uses(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 5000, 'subtotal' => 5000, 'line_total' => 5000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();
        $this->couponFactory->create([
            'code'       => 'UNLIMITED',
            'use_count'  => 999,
            'conditions' => ['max_uses' => 0],
        ]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['UNLIMITED']);
        $this->assertIsArray($result);
        $this->assertContains('UNLIMITED', $result['applied_coupon_codes'], 'max_uses 0 means unlimited, so the coupon applies');
    }

    // =========================================================================
    // Spec 50-51: Item filtering — excluded/included products
    // =========================================================================

    public function test_excludes_items_in_excluded_products(): void
    {
        $item1 = CartFactory::makeCartItem(['object_id' => 10, 'unit_price' => 5000, 'subtotal' => 5000, 'line_total' => 5000]);
        $item2 = CartFactory::makeCartItem(['object_id' => 20, 'unit_price' => 3000, 'subtotal' => 3000, 'line_total' => 3000]);
        $cart = $this->cartFactory->withCartItems([$item1, $item2])->create();

        $this->couponFactory->create([
            'code'       => 'EXCL',
            'type'       => 'fixed',
            'amount'     => 1000,
            'conditions' => ['excluded_products' => [10]],
        ]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['EXCL']);

        $this->assertIsArray($result);
        // Item 10 should have no discount, item 20 should have discount
        $items = $result['cart_items'];
        $item10 = \FluentCart\Framework\Support\Collection::make($items)->firstWhere('object_id', 10);
        $item20 = \FluentCart\Framework\Support\Collection::make($items)->firstWhere('object_id', 20);
        $this->assertSame(0, (int) Arr::get($item10, 'coupon_discount', 0));
        $this->assertGreaterThan(0, (int) Arr::get($item20, 'coupon_discount', 0));
    }

    public function test_includes_only_specified_products(): void
    {
        $item1 = CartFactory::makeCartItem(['object_id' => 10, 'unit_price' => 5000, 'subtotal' => 5000, 'line_total' => 5000]);
        $item2 = CartFactory::makeCartItem(['object_id' => 20, 'unit_price' => 3000, 'subtotal' => 3000, 'line_total' => 3000]);
        $cart = $this->cartFactory->withCartItems([$item1, $item2])->create();

        $this->couponFactory->create([
            'code'       => 'INCL',
            'type'       => 'fixed',
            'amount'     => 1000,
            'conditions' => ['included_products' => [10]],
        ]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['INCL']);

        $items = $result['cart_items'];
        $item10 = \FluentCart\Framework\Support\Collection::make($items)->firstWhere('object_id', 10);
        $item20 = \FluentCart\Framework\Support\Collection::make($items)->firstWhere('object_id', 20);
        $this->assertGreaterThan(0, (int) Arr::get($item10, 'coupon_discount', 0));
        $this->assertSame(0, (int) Arr::get($item20, 'coupon_discount', 0));
    }

    // =========================================================================
    // Spec 54: Skips locked items
    // =========================================================================

    public function test_skips_locked_items(): void
    {
        $lockedItem = CartFactory::makeCartItem([
            'object_id'  => 10,
            'unit_price' => 5000, 'subtotal' => 5000, 'line_total' => 5000,
            'other_info' => ['payment_type' => 'onetime', 'is_locked' => 'yes'],
        ]);
        $normalItem = CartFactory::makeCartItem([
            'object_id'  => 20,
            'unit_price' => 3000, 'subtotal' => 3000, 'line_total' => 3000,
        ]);
        $cart = $this->cartFactory->withCartItems([$lockedItem, $normalItem])->create();
        $this->couponFactory->create(['code' => 'LOCK', 'type' => 'fixed', 'amount' => 500]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['LOCK']);

        $items = $result['cart_items'];
        $locked = \FluentCart\Framework\Support\Collection::make($items)->firstWhere('object_id', 10);
        $normal = \FluentCart\Framework\Support\Collection::make($items)->firstWhere('object_id', 20);
        $this->assertSame(0, (int) Arr::get($locked, 'coupon_discount', 0));
        $this->assertGreaterThan(0, (int) Arr::get($normal, 'coupon_discount', 0));
    }

    // =========================================================================
    // Spec 55: will_skip_item filter
    // =========================================================================

    public function test_will_skip_item_filter_excludes_items(): void
    {
        $item = CartFactory::makeCartItem(['object_id' => 42, 'unit_price' => 5000, 'subtotal' => 5000, 'line_total' => 5000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();
        $this->couponFactory->create(['code' => 'SKIP', 'type' => 'fixed', 'amount' => 500]);

        $callback = function ($skip, $args) {
            return true; // skip all items
        };
        add_filter('fluent_cart/coupon/will_skip_item', $callback, 10, 2);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['SKIP']);

        remove_filter('fluent_cart/coupon/will_skip_item', $callback, 10);

        // All items skipped → no applicable items → error in coupon_results
        $this->assertIsArray($result);
        // The coupon should fail or have no discount
        $skipResult = Arr::get($result, 'coupon_results.SKIP');
        $this->assertFalse(Arr::get($skipResult, 'success', false));
    }

    // =========================================================================
    // Spec 56: Email restrictions
    // =========================================================================

    public function test_email_restrictions_filter_matching(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 5000, 'subtotal' => 5000, 'line_total' => 5000]);
        $cart = $this->cartFactory->withCartItems([$item])->create(['email' => 'user@company.com']);

        $this->couponFactory->create([
            'code'       => 'EMAILOK',
            'type'       => 'fixed',
            'amount'     => 500,
            'conditions' => ['email_restrictions' => '*@company.com'],
        ]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['EMAILOK']);
        $this->assertIsArray($result);
        $this->assertContains('EMAILOK', $result['applied_coupon_codes']);
    }

    public function test_email_restrictions_filter_non_matching(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 5000, 'subtotal' => 5000, 'line_total' => 5000]);
        $cart = $this->cartFactory->withCartItems([$item])->create(['email' => 'user@other.com']);

        $this->couponFactory->create([
            'code'       => 'EMAILNO',
            'type'       => 'fixed',
            'amount'     => 500,
            'conditions' => ['email_restrictions' => '*@company.com'],
        ]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['EMAILNO']);
        // Should fail — no applicable items match email
        $this->assertIsArray($result);
        $emailResult = Arr::get($result, 'coupon_results.EMAILNO');
        $this->assertFalse(Arr::get($emailResult, 'success', false));
    }

    // =========================================================================
    // Spec 58-60: Discount calculation
    // =========================================================================

    public function test_fixed_coupon_applies_exact_amount(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 10000, 'subtotal' => 10000, 'line_total' => 10000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();
        $this->couponFactory->create(['code' => 'FIX500', 'type' => 'fixed', 'amount' => 500]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['FIX500']);

        $this->assertSame(500, $result['per_coupon_discounts']['FIX500']);
    }

    public function test_percentage_coupon_calculates_correctly(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 10000, 'subtotal' => 10000, 'line_total' => 10000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();
        $this->couponFactory->createPercentage(['code' => 'PCT20', 'amount' => 20]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['PCT20']);

        $this->assertSame(2000, $result['per_coupon_discounts']['PCT20']);
    }

    public function test_fixed_coupon_caps_at_item_total(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 3000, 'subtotal' => 3000, 'line_total' => 3000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();
        $this->couponFactory->create(['code' => 'BIG', 'type' => 'fixed', 'amount' => 99999]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['BIG']);

        // Discount should not exceed item subtotal
        $this->assertLessThanOrEqual(3000, $result['per_coupon_discounts']['BIG']);
    }

    // =========================================================================
    // Spec 66: updateItemTotals line_total clamped to 0
    // =========================================================================

    public function test_line_total_clamped_to_zero(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 1000, 'subtotal' => 1000, 'line_total' => 1000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();
        $this->couponFactory->create(['code' => 'ZERO', 'type' => 'fixed', 'amount' => 99999]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['ZERO']);

        $items = $result['cart_items'];
        $this->assertGreaterThanOrEqual(0, (int) $items[0]['line_total']);
    }

    // =========================================================================
    // Spec 67-68: Subscription effective subtotal
    // =========================================================================

    public function test_subscription_with_trial_uses_signup_fee_as_subtotal(): void
    {
        // signup_fee=2000, subtotal=10000. A 50% coupon should discount 1000 (50% of 2000)
        // NOT 5000 (50% of 10000). This distinguishes the two branches.
        $subItem = CartFactory::makeSubscriptionItem([
            'unit_price' => 10000,
            'subtotal'   => 10000,
            'line_total' => 10000,
            'other_info' => [
                'payment_type' => 'subscription',
                'trial_days'   => 30,
                'signup_fee'   => 2000,
            ],
        ]);
        $cart = $this->cartFactory->withCartItems([$subItem])->create();
        $this->couponFactory->createPercentage(['code' => 'TRIALPCT', 'amount' => 50]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['TRIALPCT']);

        // 50% of signup_fee (2000) = 1000, NOT 50% of subtotal (10000) = 5000
        $discount = $result['per_coupon_discounts']['TRIALPCT'];
        $this->assertSame(1000, $discount);
    }

    // =========================================================================
    // Spec 69-70: apply() filters
    // =========================================================================

    public function test_apply_fires_pre_apply_filter(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 5000, 'subtotal' => 5000, 'line_total' => 5000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();
        $coupon = $this->couponFactory->create(['code' => 'PREAPPLY']);

        $filterFired = false;
        $callback = function ($items, $args) use (&$filterFired) {
            $filterFired = true;
            return $items;
        };
        add_filter('fluent_cart/discount/pre_apply', $callback, 10, 2);

        $service = new DiscountService($cart);
        $service->applyCouponCodes(['PREAPPLY']);

        remove_filter('fluent_cart/discount/pre_apply', $callback, 10);
        $this->assertTrue($filterFired);
    }

    public function test_apply_can_use_coupon_filter_blocks(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 5000, 'subtotal' => 5000, 'line_total' => 5000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();
        $this->couponFactory->create(['code' => 'BLOCKED']);

        $callback = function () {
            return new \WP_Error('blocked', 'Blocked by filter');
        };
        add_filter('fluent_cart/coupon/can_use_coupon', $callback, 10, 2);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['BLOCKED']);

        remove_filter('fluent_cart/coupon/can_use_coupon', $callback, 10);

        // Coupon should be in results as failed
        $this->assertIsArray($result);
        $blockedResult = Arr::get($result, 'coupon_results.BLOCKED');
        $this->assertFalse(Arr::get($blockedResult, 'success', false));
    }

    // =========================================================================
    // Spec 74-77: Accessors after apply
    // =========================================================================

    public function test_accessors_after_successful_apply(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 10000, 'subtotal' => 10000, 'line_total' => 10000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();
        $this->couponFactory->create(['code' => 'ACC', 'type' => 'fixed', 'amount' => 1500]);

        $service = new DiscountService($cart);
        $service->applyCouponCodes(['ACC']);

        $this->assertContains('ACC', $service->getAppliedCoupons());
        $this->assertSame(1500, $service->getPerCouponDiscounts()['ACC']);
        $this->assertCount(1, $service->getCartItems());
        $this->assertSame(1500, (int) $service->getCartItems()[0]['coupon_discount']);
    }

    // =========================================================================
    // Spec 125: Edge — Fixed coupon > subtotal caps at subtotal
    // =========================================================================

    public function test_fixed_coupon_exceeding_subtotal_caps(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 2000, 'subtotal' => 2000, 'line_total' => 2000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();
        $this->couponFactory->create(['code' => 'HUGE', 'type' => 'fixed', 'amount' => 50000]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['HUGE']);

        $items = $result['cart_items'];
        $this->assertSame(0, (int) $items[0]['line_total']);
        $this->assertLessThanOrEqual(2000, (int) $items[0]['coupon_discount']);
    }

    // =========================================================================
    // Spec 126: Edge — 100% percentage coupon
    // =========================================================================

    public function test_percentage_100_zeroes_line_total(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 5000, 'subtotal' => 5000, 'line_total' => 5000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();
        $this->couponFactory->createPercentage(['code' => 'FULL', 'amount' => 100]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['FULL']);

        $items = $result['cart_items'];
        $this->assertSame(0, (int) $items[0]['line_total']);
    }

    // =========================================================================
    // Spec 128: Edge — Two stackable coupons sequential
    // =========================================================================

    public function test_two_stackable_coupons_second_uses_remaining_total(): void
    {
        // Item=10000. FIRST fixed 2000 → remaining 8000. SECOND 50% of remaining = 4000.
        // If SECOND used the original total, it would be 50% of 10000 = 5000 (wrong).
        $item = CartFactory::makeCartItem(['unit_price' => 10000, 'subtotal' => 10000, 'line_total' => 10000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();
        $this->couponFactory->create(['code' => 'FIRST', 'type' => 'fixed', 'amount' => 2000, 'priority' => 1]);
        $this->couponFactory->createPercentage(['code' => 'SECOND', 'amount' => 50, 'priority' => 2]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['FIRST', 'SECOND']);

        $this->assertSame(2000, $result['per_coupon_discounts']['FIRST']);
        // 50% of remaining 8000 = 4000, NOT 50% of original 10000 = 5000
        $this->assertSame(4000, $result['per_coupon_discounts']['SECOND']);
        // Total discount = 6000, line_total = 4000
        $this->assertSame(4000, (int) $result['cart_items'][0]['line_total']);
    }

    // =========================================================================
    // Spec 133: Edge — Same coupon code twice is deduplicated
    // =========================================================================

    public function test_same_coupon_deduplicated(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 10000, 'subtotal' => 10000, 'line_total' => 10000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();
        $this->couponFactory->create(['code' => 'DUPL', 'type' => 'fixed', 'amount' => 500]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['DUPL', 'DUPL']);

        // Should only be applied once
        $appliedCount = array_count_values($result['applied_coupon_codes']);
        $this->assertSame(1, $appliedCount['DUPL']);
    }

    // =========================================================================
    // Spec 137-141: conditions.max_discount_amount cap at checkout
    // =========================================================================

    /**
     * Spec: Cases/Integration/coupons.md [137]
     *   "Percentage coupon discount at checkout is capped at the coupon's max discount amount."
     *
     * Reproduces the reported bug: 50% coupon with a $20 cap on a $750 cart
     * discounted $375 instead of $20 (cap stored as 2000 cents, never read).
     */
    public function test_percentage_coupon_discount_capped_at_max_discount_amount(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 75000, 'subtotal' => 75000, 'line_total' => 75000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();
        $this->couponFactory->createPercentage([
            'code'       => 'PCT50CAP',
            'amount'     => 50,
            'conditions' => ['max_discount_amount' => 2000],
        ]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['PCT50CAP']);

        $this->assertSame(2000, $result['per_coupon_discounts']['PCT50CAP'], '50% of 75000 must be capped at max_discount_amount (2000 cents), not 37500');
        $this->assertSame(2000, (int) $result['cart_items'][0]['coupon_discount'], 'item coupon_discount must carry the capped amount');
        $this->assertSame(73000, (int) $result['cart_items'][0]['line_total'], 'line_total must reflect the capped discount (75000 - 2000)');
    }

    /**
     * Spec: Cases/Integration/coupons.md [137] — cap is not 50%-specific.
     */
    public function test_other_percentage_amounts_also_capped_at_max_discount_amount(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 75000, 'subtotal' => 75000, 'line_total' => 75000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();
        $this->couponFactory->createPercentage([
            'code'       => 'PCT10CAP',
            'amount'     => 10,
            'conditions' => ['max_discount_amount' => 2000],
        ]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['PCT10CAP']);

        $this->assertSame(2000, $result['per_coupon_discounts']['PCT10CAP'], '10% of 75000 (7500) must also be capped at 2000 cents');
    }

    /**
     * Spec: Cases/Integration/coupons.md [138]
     *   "Percentage coupon whose computed discount is below the max discount cap is unaffected by the cap."
     */
    public function test_percentage_coupon_below_cap_is_unaffected(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 10000, 'subtotal' => 10000, 'line_total' => 10000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();
        $this->couponFactory->createPercentage([
            'code'       => 'PCT10OK',
            'amount'     => 10,
            'conditions' => ['max_discount_amount' => 2000],
        ]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['PCT10OK']);

        $this->assertSame(1000, $result['per_coupon_discounts']['PCT10OK'], '10% of 10000 (1000) is under the 2000 cap and must be applied in full');
    }

    /**
     * Spec: Cases/Integration/coupons.md [139]
     *   "Fixed coupon discount at checkout is capped at the coupon's max discount amount."
     */
    public function test_fixed_coupon_discount_capped_at_max_discount_amount(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 10000, 'subtotal' => 10000, 'line_total' => 10000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();
        $this->couponFactory->create([
            'code'       => 'FIXCAP',
            'type'       => 'fixed',
            'amount'     => 5000,
            'conditions' => ['max_discount_amount' => 2000],
        ]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['FIXCAP']);

        $this->assertSame(2000, $result['per_coupon_discounts']['FIXCAP'], 'fixed 5000 must be capped at max_discount_amount (2000 cents)');
        $this->assertSame(8000, (int) $result['cart_items'][0]['line_total'], 'line_total must reflect the capped discount (10000 - 2000)');
    }

    /**
     * Spec: Cases/Integration/coupons.md [140]
     *   "Max discount cap distributes the capped discount across multiple applicable
     *    items without exceeding the cap."
     */
    public function test_max_discount_cap_distributed_across_multiple_items(): void
    {
        $item1 = CartFactory::makeCartItem(['unit_price' => 50000, 'subtotal' => 50000, 'line_total' => 50000]);
        $item2 = CartFactory::makeCartItem(['unit_price' => 25000, 'subtotal' => 25000, 'line_total' => 25000]);
        $cart = $this->cartFactory->withCartItems([$item1, $item2])->create();
        $this->couponFactory->createPercentage([
            'code'       => 'MULTICAP',
            'amount'     => 50,
            'conditions' => ['max_discount_amount' => 3000],
        ]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['MULTICAP']);

        $this->assertSame(3000, $result['per_coupon_discounts']['MULTICAP'], 'total discount across items must equal the cap');

        $itemDiscounts = array_map(static function ($item) {
            return (int) Arr::get($item, 'coupon_discount', 0);
        }, $result['cart_items']);
        $this->assertSame(3000, array_sum($itemDiscounts), 'sum of per-item coupon_discount must equal the cap');
        $this->assertGreaterThan(0, min($itemDiscounts), 'every applicable item must retain a share of the capped discount (proportional distribution, not greedy trim)');
    }

    /**
     * Spec: Cases/Integration/coupons.md [141]
     *   "Recurring subscription discount is also capped at the coupon's max discount amount."
     */
    public function test_recurring_subscription_discount_capped_at_max_discount_amount(): void
    {
        $subItem = CartFactory::makeSubscriptionItem([
            'unit_price' => 75000,
            'subtotal'   => 75000,
            'line_total' => 75000,
        ]);
        $cart = $this->cartFactory->withCartItems([$subItem])->create();
        $this->couponFactory->createPercentage([
            'code'       => 'RECURCAP',
            'amount'     => 50,
            'conditions' => [
                'max_discount_amount' => 2000,
                'is_recurring'        => 'yes',
            ],
        ]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['RECURCAP']);

        $this->assertSame(2000, $result['per_coupon_discounts']['RECURCAP'], 'first-charge discount must be capped at 2000 cents');
        $this->assertSame(
            2000,
            (int) Arr::get($result['cart_items'][0], 'recurring_discounts.amount', 0),
            'per-renewal recurring discount must also be capped at 2000 cents, not 50% of 75000'
        );
    }

    // =========================================================================
    // Spec 134: Edge — max_uses=1, use_count=1 rejected
    // =========================================================================

    public function test_coupon_at_max_uses_rejected(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 5000, 'subtotal' => 5000, 'line_total' => 5000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();
        $this->couponFactory->create([
            'code'       => 'MAXONE',
            'use_count'  => 1,
            'conditions' => ['max_uses' => 1],
        ]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['MAXONE']);
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // =========================================================================
    // Spec 142-145: stacking honors "the first coupon applied stays"
    // (parity with the admin path, CanValidateCoupon::canBeStacked)
    // =========================================================================

    /**
     * Spec: Cases/Integration/coupons.md [142]
     * The realistic customer flow: a big non-stackable coupon is already on the
     * cart, then the customer tries a second code. The first coupon must stay;
     * the newcomer must be rejected — not the other way around.
     */
    public function test_first_applied_non_stackable_coupon_stays_when_stackable_added_later(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 10000, 'subtotal' => 10000, 'line_total' => 10000]);
        $this->couponFactory->create(['code' => 'NOSTK-FIRST', 'type' => 'fixed', 'amount' => 5000, 'stackable' => 'no']);
        $this->couponFactory->create(['code' => 'STK-LATER', 'type' => 'fixed', 'amount' => 1000, 'stackable' => 'yes']);

        $cart = $this->cartFactory->withCartItems([$item])->create(['coupons' => ['NOSTK-FIRST']]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['STK-LATER']);

        $this->assertContains('NOSTK-FIRST', $result['applied_coupon_codes'], 'the first-applied non-stackable coupon must stay');
        $this->assertNotContains('STK-LATER', $result['applied_coupon_codes'], 'a later code must not displace the first-applied non-stackable coupon');
        $laterResult = Arr::get($result, 'coupon_results.STK-LATER', []);
        $this->assertFalse(Arr::get($laterResult, 'success', true), 'the later coupon must be reported as rejected');
        $this->assertSame('coupon_not_stackable', Arr::get($laterResult, 'error_code'), 'rejection must carry the not-stackable error code');
    }

    /**
     * Spec: Cases/Integration/coupons.md [143]
     * Both codes submitted in one batch, non-stackable first: submission order
     * decides, so the non-stackable coupon survives and the stackable one is
     * rejected.
     */
    public function test_non_stackable_submitted_first_in_one_batch_stays(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 10000, 'subtotal' => 10000, 'line_total' => 10000]);
        $this->couponFactory->create(['code' => 'NOSTK-BATCH', 'type' => 'fixed', 'amount' => 5000, 'stackable' => 'no']);
        $this->couponFactory->create(['code' => 'STK-BATCH', 'type' => 'fixed', 'amount' => 1000, 'stackable' => 'yes']);

        $cart = $this->cartFactory->withCartItems([$item])->create();

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['NOSTK-BATCH', 'STK-BATCH']);

        $this->assertContains('NOSTK-BATCH', $result['applied_coupon_codes'], 'the first-submitted non-stackable coupon must survive the batch');
        $this->assertNotContains('STK-BATCH', $result['applied_coupon_codes'], 'the later stackable code must be rejected when a non-stackable coupon came first');
        $this->assertSame(
            'coupon_not_stackable',
            Arr::get($result, 'coupon_results.STK-BATCH.error_code'),
            'rejection must carry the not-stackable error code'
        );
    }

    /**
     * Spec: Cases/Integration/coupons.md [144]
     * Regression guard for the direction that already worked: a stackable
     * coupon applied first stays, and a later non-stackable coupon is rejected.
     */
    public function test_stackable_first_stays_and_later_non_stackable_is_rejected(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 10000, 'subtotal' => 10000, 'line_total' => 10000]);
        $this->couponFactory->create(['code' => 'STK-FIRST', 'type' => 'fixed', 'amount' => 1000, 'stackable' => 'yes']);
        $this->couponFactory->create(['code' => 'NOSTK-LATER', 'type' => 'fixed', 'amount' => 5000, 'stackable' => 'no']);

        $cart = $this->cartFactory->withCartItems([$item])->create(['coupons' => ['STK-FIRST']]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['NOSTK-LATER']);

        $this->assertContains('STK-FIRST', $result['applied_coupon_codes'], 'the first-applied stackable coupon must stay');
        $this->assertNotContains('NOSTK-LATER', $result['applied_coupon_codes'], 'a later non-stackable coupon must be rejected');
        $this->assertSame(
            'coupon_not_stackable',
            Arr::get($result, 'coupon_results.NOSTK-LATER.error_code'),
            'rejection must carry the not-stackable error code'
        );
    }

    /**
     * Spec: Cases/Integration/coupons.md [145]
     * Regression guard: stackable-on-stackable still stacks — the first-stays
     * rule must not narrow legitimate stacking.
     */
    public function test_stackable_coupons_still_stack_after_first_stays_rule(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 10000, 'subtotal' => 10000, 'line_total' => 10000]);
        $this->couponFactory->create(['code' => 'STK-ONE', 'type' => 'fixed', 'amount' => 1000, 'stackable' => 'yes']);
        $this->couponFactory->create(['code' => 'STK-TWO', 'type' => 'fixed', 'amount' => 500, 'stackable' => 'yes']);

        $cart = $this->cartFactory->withCartItems([$item])->create(['coupons' => ['STK-ONE']]);

        $service = new DiscountService($cart);
        $result = $service->applyCouponCodes(['STK-TWO']);

        $this->assertContains('STK-ONE', $result['applied_coupon_codes'], 'the first stackable coupon must stay');
        $this->assertContains('STK-TWO', $result['applied_coupon_codes'], 'a later stackable coupon must stack on a stackable first');
    }

    /**
     * Two lines of one variation share an `id`; the coupon must not copy the
     * first line over the second, or the shipping shares stop summing to 1250.
     */
    public function test_coupon_keeps_each_line_when_variation_is_on_two_lines(): void
    {
        $variation = CartFactory::makeCartItem(['unit_price' => 1000, 'fulfillment_type' => 'physical']);
        $first = $variation + ['itemwise_shipping_charge' => 417];
        $other = CartFactory::makeCartItem(['unit_price' => 1000, 'fulfillment_type' => 'physical', 'itemwise_shipping_charge' => 417]);
        $second = array_merge($variation, ['itemwise_shipping_charge' => 416]);
        $cart = $this->cartFactory->withCartItems([$first, $other, $second])->create();
        $this->couponFactory->createPercentage(['code' => 'PCT10DUP', 'amount' => 10]);

        $result = (new DiscountService($cart))->applyCouponCodes(['PCT10DUP']);

        $this->assertSame(1250, array_sum(array_column($result['cart_items'], 'itemwise_shipping_charge')));
    }
}

<?php

namespace Tests\Integration\Models;

use FluentCart\App\Models\AppliedCoupon;
use FluentCart\App\Models\Coupon;
use FluentCart\App\Models\Meta;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\DateTime\DateTime;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\AppliedCouponFactory;
use Tests\Support\Factory\CouponFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

class CouponTest extends DatabaseTestCase
{
    private CouponFactory $couponFactory;
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private AppliedCouponFactory $appliedCouponFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->couponFactory = new CouponFactory();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
        $this->appliedCouponFactory = new AppliedCouponFactory();
    }

    // =========================================================================
    // Spec 1: Table and primary key
    // =========================================================================

    public function test_table_and_primary_key(): void
    {
        $coupon = new Coupon();
        $this->assertSame('fct_coupons', $coupon->getTable());
        $this->assertSame('id', $coupon->getKeyName());
    }

    // =========================================================================
    // Spec 2: Fillable attributes
    // =========================================================================

    public function test_fillable_attributes(): void
    {
        $expected = [
            'parent', 'title', 'code', 'status', 'type', 'conditions',
            'amount', 'stackable', 'priority', 'use_count', 'notes',
            'show_on_checkout', 'start_date', 'end_date',
        ];

        $coupon = new Coupon();
        foreach ($expected as $field) {
            $this->assertContains($field, $coupon->getFillable(), "Expected '{$field}' to be fillable");
        }
    }

    // =========================================================================
    // Spec 4: conditions JSON accessor
    // =========================================================================

    public function test_conditions_json_round_trip(): void
    {
        $conditions = ['max_uses' => 10, 'min_purchase_amount' => 5000];
        $coupon = $this->couponFactory->create(['conditions' => $conditions]);

        $reloaded = Coupon::query()->find($coupon->id);
        $this->assertIsArray($reloaded->conditions);
        $this->assertSame(10, $reloaded->conditions['max_uses']);
    }

    public function test_conditions_returns_empty_array_when_null(): void
    {
        $coupon = new Coupon();
        $this->assertSame([], $coupon->conditions);
    }

    // =========================================================================
    // Spec 9: Coupon hasMany AppliedCoupon
    // =========================================================================

    public function test_coupon_has_many_applied_coupons(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $coupon = $this->couponFactory->create();

        $this->appliedCouponFactory->create([
            'order_id'  => $order->id,
            'coupon_id' => $coupon->id,
            'code'      => $coupon->code,
            'amount'    => 500,
        ]);

        $reloaded = Coupon::query()->with('appliedCoupons')->find($coupon->id);
        $this->assertCount(1, $reloaded->appliedCoupons);
        $this->assertInstanceOf(AppliedCoupon::class, $reloaded->appliedCoupons->first());
    }

    // =========================================================================
    // Spec 10: Coupon belongsToMany Order
    // =========================================================================

    public function test_coupon_belongs_to_many_orders(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $coupon = $this->couponFactory->create();

        $this->appliedCouponFactory->create([
            'order_id'  => $order->id,
            'coupon_id' => $coupon->id,
            'code'      => $coupon->code,
        ]);

        $reloaded = Coupon::query()->find($coupon->id);
        $orders = $reloaded->orders;
        $this->assertCount(1, $orders);
        $this->assertInstanceOf(Order::class, $orders->first());
    }

    // =========================================================================
    // Spec 11-13: scopeActive
    // =========================================================================

    public function test_scope_active_includes_active_with_no_end_date(): void
    {
        $this->couponFactory->create(['status' => 'active', 'end_date' => null]);
        $this->couponFactory->create(['status' => 'inactive']);

        $active = Coupon::active()->get();
        $this->assertCount(1, $active);
    }

    public function test_scope_active_excludes_expired_end_date(): void
    {
        $this->couponFactory->create([
            'status'   => 'active',
            'end_date' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]);

        $active = Coupon::active()->get();
        $this->assertCount(0, $active);
    }

    public function test_scope_active_includes_future_end_date(): void
    {
        $this->couponFactory->create([
            'status'   => 'active',
            'end_date' => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]);

        $active = Coupon::active()->get();
        $this->assertCount(1, $active);
    }

    // =========================================================================
    // Spec 14-17: Meta methods
    // =========================================================================

    public function test_get_meta_returns_value(): void
    {
        $coupon = $this->couponFactory->create();
        $coupon->updateMeta('test_key', 'test_value');

        $this->assertSame('test_value', $coupon->getMeta('test_key'));
    }

    public function test_get_meta_returns_default_when_not_found(): void
    {
        $coupon = $this->couponFactory->create();
        $this->assertSame('fallback', $coupon->getMeta('nonexistent', 'fallback'));
    }

    public function test_update_meta_creates_new_row(): void
    {
        $coupon = $this->couponFactory->create();
        $result = $coupon->updateMeta('new_key', 'new_value');

        $this->assertNotNull($result);
        $this->assertSame('new_value', $coupon->getMeta('new_key'));
    }

    public function test_update_meta_updates_existing_row(): void
    {
        $coupon = $this->couponFactory->create();
        $coupon->updateMeta('key1', 'original');
        $coupon->updateMeta('key1', 'updated');

        $this->assertSame('updated', $coupon->getMeta('key1'));

        // Ensure only one meta row exists
        $count = Meta::query()
            ->where('object_type', 'coupon')
            ->where('object_id', $coupon->id)
            ->where('meta_key', 'key1')
            ->count();
        $this->assertSame(1, $count);
    }

    // =========================================================================
    // Spec 18-19: isRecurringDiscount
    // =========================================================================

    public function test_is_recurring_discount_true(): void
    {
        $coupon = $this->couponFactory->create([
            'conditions' => ['is_recurring' => 'yes'],
        ]);
        $this->assertTrue($coupon->isRecurringDiscount());
    }

    public function test_is_recurring_discount_false(): void
    {
        $coupon = $this->couponFactory->create(['conditions' => []]);
        $this->assertFalse($coupon->isRecurringDiscount());

        $coupon2 = $this->couponFactory->create(['conditions' => ['is_recurring' => 'no']]);
        $this->assertFalse($coupon2->isRecurringDiscount());
    }

    // =========================================================================
    // Spec 20-21: getStatus / setStatus
    // =========================================================================

    public function test_get_status(): void
    {
        $coupon = $this->couponFactory->create(['status' => 'active']);
        $this->assertSame('active', $coupon->getStatus());
    }

    public function test_set_status(): void
    {
        $coupon = $this->couponFactory->create(['status' => 'active']);
        $coupon->setStatus('expired');
        $this->assertSame('expired', $coupon->getStatus());
    }

    // =========================================================================
    // Spec 22-28: AppliedCoupon model
    // =========================================================================

    public function test_applied_coupon_table_and_primary_key(): void
    {
        $ac = new AppliedCoupon();
        $this->assertSame('fct_applied_coupons', $ac->getTable());
        $this->assertSame('id', $ac->getKeyName());
    }

    public function test_applied_coupon_fillable(): void
    {
        $ac = new AppliedCoupon();
        foreach (['order_id', 'coupon_id', 'code', 'amount'] as $field) {
            $this->assertContains($field, $ac->getFillable());
        }
    }

    public function test_applied_coupon_belongs_to_order(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $coupon = $this->couponFactory->create();

        $ac = $this->appliedCouponFactory->create([
            'order_id'  => $order->id,
            'coupon_id' => $coupon->id,
            'code'      => $coupon->code,
        ]);

        $this->assertInstanceOf(Order::class, $ac->order);
        $this->assertEquals($order->id, $ac->order->id);
    }

    public function test_applied_coupon_other_info_returns_empty_array_when_null(): void
    {
        $ac = new AppliedCoupon();
        $this->assertSame([], $ac->other_info);
    }

    public function test_applied_coupon_categories_returns_empty_array_when_null(): void
    {
        $ac = new AppliedCoupon();
        $this->assertSame([], $ac->categories);
    }

    // Spec 26-28: AppliedCoupon JSON accessors (other_info, categories, products)
    // are defined on the model but the fct_applied_coupons table does not have
    // these columns in the base migration. Tested only via in-memory getter defaults above.
}

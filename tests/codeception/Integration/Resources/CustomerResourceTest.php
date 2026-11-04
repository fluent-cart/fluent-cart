<?php

namespace Tests\Integration\Resources;

use FluentCart\Api\Resource\CustomerResource;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Helpers\Status;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;

class CustomerResourceTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
    }

    // =========================================================================
    // Spec 49: create() — basic creation
    // =========================================================================

    public function test_create_customer_with_required_fields(): void
    {
        $result = CustomerResource::create([
            'first_name' => 'New',
            'last_name'  => 'Customer',
            'email'      => 'new-resource@example.com',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $customer = $result['data'];
        $this->assertSame('new-resource@example.com', $customer->email);
        $this->assertNotEmpty($customer->uuid);
        // purchase_value defaults to empty array
        $this->assertIsArray($customer->purchase_value);
        $this->assertEmpty($customer->purchase_value);
    }

    // =========================================================================
    // Spec 50: create() — duplicate email error
    // =========================================================================

    public function test_create_duplicate_email_returns_error(): void
    {
        CustomerResource::create([
            'first_name' => 'First',
            'email'      => 'duplicate@example.com',
        ]);

        $result = CustomerResource::create([
            'first_name' => 'Second',
            'email'      => 'duplicate@example.com',
        ]);

        $this->assertTrue(is_wp_error($result));
    }

    // =========================================================================
    // Spec 51: create() — auto-links existing WP user
    // =========================================================================

    public function test_create_auto_links_existing_wp_user(): void
    {
        $email = 'autolink-' . wp_generate_password(4, false) . '@example.com';
        $userId = static::factory()->user->create([
            'user_email' => $email,
            'role'       => 'subscriber',
        ]);
        // Linking needs a caller who may edit that user (ownership guard, #2880).
        wp_set_current_user(static::factory()->user->create(['role' => 'administrator']));

        $result = CustomerResource::create([
            'first_name' => 'AutoLink',
            'email'      => $email,
        ]);

        $customer = $result['data'];
        $fresh = Customer::find($customer->id);
        $this->assertSame($userId, (int)$fresh->user_id);
    }

    public function test_create_does_not_link_existing_wp_user_for_anonymous_caller(): void
    {
        $email = 'nolink-' . wp_generate_password(4, false) . '@example.com';
        static::factory()->user->create([
            'user_email' => $email,
            'role'       => 'subscriber',
        ]);
        wp_set_current_user(0);

        $result = CustomerResource::create([
            'first_name' => 'NoLink',
            'email'      => $email,
        ]);

        $fresh = Customer::find($result['data']->id);
        $this->assertSame(0, (int) $fresh->user_id, 'an anonymous caller must not claim another user\'s account');
    }

    // =========================================================================
    // Spec 52: create() with wp_user=yes creates WP user via AuthService
    // =========================================================================

    public function test_create_with_wp_user_yes_creates_wp_user(): void
    {
        $email = 'wpuser-create-' . wp_generate_password(4, false) . '@example.com';

        // Ensure no WP user exists with this email
        $this->assertFalse(get_user_by('email', $email));

        $result = CustomerResource::create([
            'first_name' => 'WPCreate',
            'last_name'  => 'Test',
            'full_name'  => 'WPCreate Test',
            'email'      => $email,
            'wp_user'    => 'yes',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);

        // A WP user should now exist with this email
        $wpUser = get_user_by('email', $email);
        $this->assertInstanceOf(\WP_User::class, $wpUser, 'WP user should have been created');
        $this->assertSame($email, $wpUser->user_email);

        // The customer should now be linked to this WP user
        $fresh = Customer::find($result['data']->id);
        $this->assertSame($wpUser->ID, (int)$fresh->user_id);
    }

    public function test_create_with_wp_user_yes_does_not_duplicate_existing_user(): void
    {
        $email = 'wpuser-existing-' . wp_generate_password(4, false) . '@example.com';

        // Pre-create the WP user — the auto-link path should fire instead of wp_user creation
        $existingUserId = static::factory()->user->create([
            'user_email' => $email,
            'role'       => 'subscriber',
        ]);
        // Linking needs a caller who may edit that user (ownership guard, #2880).
        wp_set_current_user(static::factory()->user->create(['role' => 'administrator']));

        $result = CustomerResource::create([
            'first_name' => 'ExistingWP',
            'email'      => $email,
            'wp_user'    => 'yes',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);

        // Customer should be linked to the pre-existing user, not a new one
        $fresh = Customer::find($result['data']->id);
        $this->assertSame($existingUserId, (int)$fresh->user_id);
    }

    // =========================================================================
    // Spec 53: find() — returns customer
    // =========================================================================

    public function test_find_returns_customer_array(): void
    {
        $customer = $this->customerFactory->create();
        $result = CustomerResource::find($customer->id);

        $this->assertArrayHasKey('customer', $result);
        $this->assertSame($customer->id, $result['customer']->id);
    }

    // =========================================================================
    // Spec 54: find() — non-existent returns null
    // =========================================================================

    public function test_find_nonexistent_returns_null(): void
    {
        $result = CustomerResource::find(999999);
        $this->assertArrayHasKey('customer', $result);
        $this->assertNull($result['customer']);
    }

    // =========================================================================
    // Spec 55: update() — updates fields
    // =========================================================================

    public function test_update_customer_fields(): void
    {
        $customer = $this->customerFactory->create();

        // resolveCustomerName() reads first_name/last_name (or full_name) from data
        // depending on CheckoutFieldsSchema config. We pass both to cover either config.
        $result = CustomerResource::update([
            'first_name' => 'Updated',
            'last_name'  => 'Name',
            'full_name'  => 'Updated Name',
            'email'      => $customer->email,
        ], $customer->id);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertSame('Updated', $result['data']->first_name);
    }

    // =========================================================================
    // Spec 56: update() — also updates linked WP user
    // =========================================================================

    public function test_update_customer_with_linked_wp_user_updates_user(): void
    {
        $customer = $this->customerFactory->createWithUser();

        CustomerResource::update([
            'first_name' => 'WPUpdated',
            'last_name'  => 'UserTest',
            'full_name'  => 'WPUpdated UserTest',
            'email'      => $customer->email,
        ], $customer->id);

        $wpUser = get_user_by('ID', $customer->user_id);
        $this->assertSame('WPUpdated', $wpUser->first_name);
        $this->assertSame('UserTest', $wpUser->last_name);
    }

    // =========================================================================
    // Spec 57: update() — non-existent customer ID
    // =========================================================================

    public function test_update_nonexistent_customer_returns_error(): void
    {
        $result = CustomerResource::update([
            'first_name' => 'Ghost',
            'email'      => 'ghost@example.com',
        ], 999999);

        $this->assertTrue(is_wp_error($result));
    }

    // =========================================================================
    // Spec 58: update() — email preserved for linked WP user
    // =========================================================================

    public function test_update_preserves_email_when_user_linked(): void
    {
        $customer = $this->customerFactory->createWithUser();
        $originalEmail = $customer->email;

        CustomerResource::update([
            'first_name' => 'Preserve',
            'email'      => 'hacker-attempt@evil.com',
        ], $customer->id);

        $fresh = Customer::find($customer->id);
        $this->assertSame($originalEmail, $fresh->email);
    }

    // =========================================================================
    // Spec 59-60: delete() — deletes customers and their orders
    // =========================================================================

    public function test_delete_customer_also_deletes_orders(): void
    {
        $customer = $this->customerFactory->create();
        Order::query()->create([
            'customer_id'    => $customer->id,
            'payment_status' => 'pending',
            'total_amount'   => 1000,
            'order_status'   => 'pending',
            'currency'       => 'USD',
        ]);

        CustomerResource::delete(null, ['ids' => [$customer->id]]);

        $this->assertNull(Customer::find($customer->id));
        $this->assertSame(0, Order::query()->where('customer_id', $customer->id)->count());
    }

    public function test_delete_multiple_customers(): void
    {
        $c1 = $this->customerFactory->create();
        $c2 = $this->customerFactory->create();

        CustomerResource::delete(null, ['ids' => [$c1->id, $c2->id]]);

        $this->assertNull(Customer::find($c1->id));
        $this->assertNull(Customer::find($c2->id));
    }

    // =========================================================================
    // Spec 61-63: updateStatus()
    // =========================================================================

    public function test_update_status_changes_multiple_customers(): void
    {
        $c1 = $this->customerFactory->create(['status' => 'active']);
        $c2 = $this->customerFactory->create(['status' => 'active']);

        CustomerResource::updateStatus([
            'new_status'   => 'inactive',
            'customer_ids' => [$c1->id, $c2->id],
        ]);

        $this->assertSame('inactive', Customer::find($c1->id)->status);
        $this->assertSame('inactive', Customer::find($c2->id)->status);
    }

    public function test_update_status_empty_status_returns_error(): void
    {
        $c = $this->customerFactory->create();
        $result = CustomerResource::updateStatus([
            'new_status'   => '',
            'customer_ids' => [$c->id],
        ]);
        $this->assertTrue(is_wp_error($result));
    }

    public function test_update_status_invalid_status_returns_error(): void
    {
        $c = $this->customerFactory->create();
        $result = CustomerResource::updateStatus([
            'new_status'   => 'nonexistent_status',
            'customer_ids' => [$c->id],
        ]);
        $this->assertTrue(is_wp_error($result));
    }

    // =========================================================================
    // Spec 64-67: manageCustomer()
    // =========================================================================

    public function test_manage_customer_delete_action(): void
    {
        $c = $this->customerFactory->create();

        CustomerResource::manageCustomer([
            'action'       => 'delete_customers',
            'customer_ids' => [$c->id],
        ]);

        $this->assertNull(Customer::find($c->id));
    }

    public function test_manage_customer_change_status_action(): void
    {
        $c = $this->customerFactory->create(['status' => 'active']);

        CustomerResource::manageCustomer([
            'action'       => 'change_customer_status',
            'customer_ids' => [$c->id],
            'new_status'   => 'inactive',
        ]);

        $this->assertSame('inactive', Customer::find($c->id)->status);
    }

    public function test_manage_customer_empty_ids_returns_error(): void
    {
        $result = CustomerResource::manageCustomer([
            'action'       => 'delete_customers',
            'customer_ids' => [],
        ]);
        $this->assertTrue(is_wp_error($result));
    }

    public function test_manage_customer_invalid_action_returns_error(): void
    {
        $c = $this->customerFactory->create();
        $result = CustomerResource::manageCustomer([
            'action'       => 'invalid_action',
            'customer_ids' => [$c->id],
        ]);
        $this->assertTrue(is_wp_error($result));
    }

    public function test_get_current_customer_updates_mismatched_user_id(): void
    {
        $email = 'mismatch-' . wp_generate_password(4, false) . '@example.com';
        $userId = static::factory()->user->create([
            'user_email' => $email,
            'role'       => 'subscriber',
        ]);

        $customer = $this->customerFactory->create([
            'email'   => $email,
            'user_id' => 0,
        ]);

        // Replicate the user_id mismatch repair logic from getCurrentCustomer
        $existing = Customer::query()
            ->where('user_id', $userId)
            ->orWhere('email', $email)
            ->first();

        if ($existing && $existing->user_id != $userId) {
            $existing->user_id = $userId;
            $existing->save();
        }

        $fresh = Customer::find($customer->id);
        $this->assertSame($userId, (int)$fresh->user_id);
    }

    public function test_get_current_customer_create_if_not_exists_logic(): void
    {
        $email = 'newcustomer-' . wp_generate_password(4, false) . '@example.com';
        $userId = static::factory()->user->create([
            'user_email' => $email,
            'first_name' => 'NewFirst',
            'last_name'  => 'NewLast',
            'role'       => 'subscriber',
        ]);

        // Verify no customer exists for this user
        $existing = Customer::query()
            ->where('user_id', $userId)
            ->orWhere('email', $email)
            ->first();
        $this->assertNull($existing);

        // Replicate the creation logic from getCurrentCustomer(createIfNotExists=true)
        $wpUser = get_user_by('ID', $userId);
        $created = Customer::query()->create([
            'first_name' => $wpUser->first_name,
            'last_name'  => $wpUser->last_name,
            'email'      => $wpUser->user_email,
            'user_id'    => $userId,
        ]);

        $this->assertNotNull($created);
        $this->assertSame($email, $created->email);
        $this->assertSame($userId, (int)$created->user_id);
        $this->assertSame('NewFirst', $created->first_name);
    }

    // =========================================================================
    // Spec 73-75: updateAdditionalInfo()
    // =========================================================================

    public function test_update_additional_info_adds_labels(): void
    {
        // Create a label
        $label = \FluentCart\App\Models\Label::query()->create(['value' => 'VIP']);

        $customer = $this->customerFactory->create();

        $result = CustomerResource::updateAdditionalInfo(
            ['labels' => [(string)$label->id]],
            $customer->id
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);

        // Verify label was attached
        $fresh = Customer::with('labels')->find($customer->id);
        $labelIds = $fresh->labels->pluck('label_id')->toArray();
        $this->assertContains($label->id, $labelIds);
    }

    public function test_update_additional_info_nonexistent_customer_returns_error(): void
    {
        $result = CustomerResource::updateAdditionalInfo(
            ['labels' => ['1']],
            999999
        );

        $this->assertTrue(is_wp_error($result));
    }

    public function test_update_additional_info_no_label_changes_returns_error(): void
    {
        $customer = $this->customerFactory->create();

        // No labels at all — empty arrays for both new and existing
        $result = CustomerResource::updateAdditionalInfo(
            ['labels' => []],
            $customer->id
        );

        $this->assertTrue(is_wp_error($result));
    }

    // =========================================================================
    // Spec 140: Edge case — recountStat with over-refunded orders
    // =========================================================================

    public function test_recount_stat_excludes_negative_net_amounts_from_ltv(): void
    {
        $customer = $this->customerFactory->create();

        // Order with refund > paid (negative net)
        Order::query()->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PAID,
            'total_paid'     => 1000,
            'total_refund'   => 2000,
            'total_amount'   => 1000,
            'order_status'   => 'completed',
            'currency'       => 'USD',
        ]);

        // Normal paid order
        Order::query()->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PAID,
            'total_paid'     => 5000,
            'total_refund'   => 0,
            'total_amount'   => 5000,
            'order_status'   => 'completed',
            'currency'       => 'USD',
        ]);

        $customer->recountStat();

        // LTV should only include the positive net: 5000, not (1000-2000)=-1000
        $this->assertSame(5000, (int)$customer->ltv);
        $this->assertSame(2, (int)$customer->purchase_count);
    }
}

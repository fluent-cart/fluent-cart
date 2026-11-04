<?php

namespace Tests\Integration\Models;

use FluentCart\App\Models\Customer;
use FluentCart\App\Models\CustomerAddresses;
use FluentCart\App\Models\CustomerMeta;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Models\LabelRelationship;
use FluentCart\App\Models\User;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\CustomerAddressFactory;

class CustomerTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private CustomerAddressFactory $addressFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->addressFactory = new CustomerAddressFactory();
    }

    // =========================================================================
    // Spec 1: Table & Columns
    // =========================================================================

    public function test_customers_table_has_expected_columns(): void
    {
        $expected = [
            'id', 'user_id', 'contact_id', 'email', 'first_name', 'last_name',
            'status', 'purchase_value', 'purchase_count', 'ltv',
            'first_purchase_date', 'last_purchase_date', 'aov', 'notes', 'uuid',
            'country', 'city', 'state', 'postcode', 'created_at', 'updated_at',
        ];
        $this->assertColumnsExist('fct_customers', $expected);
    }

    // =========================================================================
    // Spec 2-3: Boot & UUID Generation
    // =========================================================================

    public function test_creating_customer_auto_generates_uuid(): void
    {
        $customer = $this->customerFactory->create();

        $this->assertNotEmpty($customer->uuid);
        $this->assertSame(32, strlen($customer->uuid)); // md5 hash length
    }

    public function test_two_customers_with_different_emails_get_different_uuids(): void
    {
        $customer1 = $this->customerFactory->create(['email' => 'uuid-a@example.com']);
        $customer2 = $this->customerFactory->create(['email' => 'uuid-b@example.com']);

        $this->assertNotSame($customer1->uuid, $customer2->uuid);
    }

    // =========================================================================
    // Spec 4: Fillable & Mass Assignment
    // =========================================================================

    public function test_fillable_fields_are_mass_assignable(): void
    {
        $customer = $this->customerFactory->create([
            'first_name' => 'Mass',
            'last_name'  => 'Assign',
            'email'      => 'mass-assign@example.com',
            'status'     => 'active',
            'country'    => 'DE',
            'city'       => 'Berlin',
            'state'      => 'BE',
            'postcode'   => '10115',
            'notes'      => 'test notes',
        ]);

        $this->assertSame('Mass', $customer->first_name);
        $this->assertSame('Assign', $customer->last_name);
        $this->assertSame('mass-assign@example.com', $customer->email);
        $this->assertSame('DE', $customer->country);
        $this->assertSame('test notes', $customer->notes);
    }

    // =========================================================================
    // Spec 5: full_name accessor
    // =========================================================================

    public function test_full_name_accessor_concatenates_first_and_last_name(): void
    {
        $customer = $this->customerFactory->create([
            'first_name' => 'John',
            'last_name'  => 'Doe',
        ]);

        $this->assertSame('John Doe', $customer->full_name);
    }

    public function test_full_name_accessor_returns_first_name_when_last_name_empty(): void
    {
        $customer = $this->customerFactory->create([
            'first_name' => 'John',
            'last_name'  => '',
        ]);

        $this->assertSame('John', $customer->full_name);
    }

    // =========================================================================
    // Spec 6: purchase_value mutator/accessor
    // =========================================================================

    public function test_purchase_value_encodes_arrays_and_decodes_back(): void
    {
        $customer = $this->customerFactory->create();
        $customer->purchase_value = ['usd' => 5000, 'eur' => 3000];
        $customer->save();

        $fresh = Customer::find($customer->id);
        $this->assertIsArray($fresh->purchase_value);
        $this->assertSame(5000, $fresh->purchase_value['usd']);
    }

    public function test_purchase_value_empty_array_returns_empty(): void
    {
        $customer = $this->customerFactory->create();
        $customer->purchase_value = [];
        $customer->save();

        $fresh = Customer::find($customer->id);
        // The mutator JSON-encodes empty array to "[]", the getter decodes back
        // An empty array decodes to array, but getPurchaseValueAttribute returns null for empty
        $this->assertTrue($fresh->purchase_value === null || $fresh->purchase_value === []);
    }

    // =========================================================================
    // Spec 7: country_name accessor
    // =========================================================================

    public function test_country_name_accessor_returns_readable_name(): void
    {
        $customer = $this->customerFactory->create(['country' => 'US']);
        $this->assertSame(Helper::getCountryName('US'), $customer->country_name);
    }

    // =========================================================================
    // Spec 8: formatted_address accessor
    // =========================================================================

    public function test_formatted_address_returns_expected_keys(): void
    {
        $customer = $this->customerFactory->create([
            'country'    => 'US',
            'city'       => 'New York',
            'state'      => 'NY',
            'postcode'   => '10001',
            'first_name' => 'Jane',
            'last_name'  => 'Doe',
        ]);

        $address = $customer->formatted_address;
        $this->assertArrayHasKey('country', $address);
        $this->assertArrayHasKey('state', $address);
        $this->assertArrayHasKey('city', $address);
        $this->assertArrayHasKey('postcode', $address);
        $this->assertArrayHasKey('first_name', $address);
        $this->assertArrayHasKey('last_name', $address);
        $this->assertArrayHasKey('full_name', $address);
        $this->assertSame('New York', $address['city']);
    }

    // =========================================================================
    // Spec 9: user_link accessor
    // =========================================================================

    public function test_user_link_returns_admin_url_when_user_id_set(): void
    {
        $customer = $this->customerFactory->createWithUser();

        $this->assertStringContainsString('user-edit.php?user_id=', $customer->user_link);
        $this->assertStringContainsString((string)$customer->user_id, $customer->user_link);
    }

    public function test_user_link_returns_empty_string_when_no_user_id(): void
    {
        $customer = $this->customerFactory->create(['user_id' => null]);
        $this->assertSame('', $customer->user_link);
    }

    // =========================================================================
    // Spec 10: photo accessor
    // =========================================================================

    public function test_photo_accessor_returns_gravatar_by_default(): void
    {
        $customer = $this->customerFactory->create();
        $this->assertNotEmpty($customer->photo);
        // Gravatar URL should contain 'gravatar' or be a valid URL
        $this->assertStringContainsString('gravatar', $customer->photo);
    }

    public function test_photo_accessor_returns_custom_photo_when_set(): void
    {
        $customer = $this->customerFactory->createWithUser();
        $customUrl = 'https://example.com/photo.jpg';
        update_user_meta($customer->user_id, 'fc_customer_photo_url', $customUrl);

        // Re-fetch to clear any caching
        $fresh = Customer::find($customer->id);
        $this->assertSame($customUrl, $fresh->photo);
    }

    // =========================================================================
    // Spec 11-12: Scopes ofActive / ofArchived
    // =========================================================================

    public function test_scope_of_active_filters_to_active_only(): void
    {
        // Rows committed past rollback by earlier DDL-bearing tests would inflate a
        // global count; clearing them here happens inside this test's transaction
        // and is rolled back with it.
        Customer::query()->delete();
        $this->customerFactory->create(['email' => 'active@ex.com', 'status' => 'active']);
        $this->customerFactory->create(['email' => 'archived@ex.com', 'status' => 'archived']);

        $active = Customer::query()->ofActive()->get();
        $this->assertCount(1, $active);
        $this->assertSame('active', $active->first()->status);
    }

    public function test_scope_of_archived_filters_to_archived_only(): void
    {
        $this->customerFactory->create(['email' => 'active2@ex.com', 'status' => 'active']);
        $this->customerFactory->create(['email' => 'archived2@ex.com', 'status' => 'archived']);

        $archived = Customer::query()->ofArchived()->get();
        $this->assertCount(1, $archived);
        $this->assertSame('archived', $archived->first()->status);
    }

    // =========================================================================
    // Spec 13-14: scopeSearchBy
    // =========================================================================

    public function test_search_by_plain_text_matches_first_name(): void
    {
        $this->customerFactory->create(['email' => 'search1@ex.com', 'first_name' => 'Unique']);
        $this->customerFactory->create(['email' => 'search2@ex.com', 'first_name' => 'Other']);

        $results = Customer::query()->searchBy('Unique')->get();
        $this->assertCount(1, $results);
        $this->assertSame('Unique', $results->first()->first_name);
    }

    public function test_search_by_full_name_splits_first_and_last(): void
    {
        $this->customerFactory->create(['email' => 'fn1@ex.com', 'first_name' => 'Alice', 'last_name' => 'Wonder']);
        $this->customerFactory->create(['email' => 'fn2@ex.com', 'first_name' => 'Bob', 'last_name' => 'Builder']);

        $results = Customer::query()->searchBy('Alice Wonder')->get();
        $this->assertCount(1, $results);
        $this->assertSame('Alice', $results->first()->first_name);
    }

    // =========================================================================
    // Spec 15: scopeSearchBy operator syntax
    // =========================================================================

    public function test_search_by_operator_syntax_equals(): void
    {
        $c = $this->customerFactory->create(['email' => 'op1@ex.com']);

        $results = Customer::query()->searchBy('id=' . $c->id)->get();
        $this->assertCount(1, $results);
        $this->assertSame($c->id, $results->first()->id);
    }

    // =========================================================================
    // Spec 16: scopeSearchBy colon syntax
    // =========================================================================

    public function test_search_by_colon_syntax_on_email_column(): void
    {
        $this->customerFactory->create(['email' => 'colon-test@unique.com']);
        $this->customerFactory->create(['email' => 'other@example.com']);

        $results = Customer::query()->searchBy('email:colon-test')->get();
        $this->assertCount(1, $results);
        $this->assertStringContainsString('colon-test', $results->first()->email);
    }

    // =========================================================================
    // Spec 17: scopeApplyCustomFilters
    // =========================================================================

    public function test_apply_custom_filters_with_gt_operator(): void
    {
        $this->customerFactory->create(['email' => 'f1@ex.com', 'ltv' => 5000]);
        $this->customerFactory->create(['email' => 'f2@ex.com', 'ltv' => 1000]);

        $results = Customer::query()->applyCustomFilters([
            'ltv' => ['value' => '3000', 'operator' => 'gt'],
        ])->get();

        $this->assertCount(1, $results);
        $this->assertSame(5000, (int)$results->first()->ltv);
    }

    // =========================================================================
    // Spec 18: scopeSearchByFullName
    // =========================================================================

    public function test_search_by_full_name_contains(): void
    {
        $this->customerFactory->create(['email' => 'sfn1@ex.com', 'first_name' => 'Alice', 'last_name' => 'Wonder']);
        $this->customerFactory->create(['email' => 'sfn2@ex.com', 'first_name' => 'Bob', 'last_name' => 'Builder']);

        $results = Customer::query()->searchByFullName([
            'operator' => 'like_all',
            'value'    => 'Alice',
        ])->get();

        $this->assertCount(1, $results);
        $this->assertSame('Alice', $results->first()->first_name);
    }

    public function test_search_by_full_name_starts_with(): void
    {
        $this->customerFactory->create(['email' => 'sfn3@ex.com', 'first_name' => 'Alice', 'last_name' => 'Wonder']);

        $results = Customer::query()->searchByFullName([
            'operator' => 'starts_with',
            'value'    => 'Alice',
        ])->get();

        $this->assertCount(1, $results);
    }

    public function test_search_by_full_name_not_like(): void
    {
        // Same committed-leak guard as test_scope_of_active_filters_to_active_only:
        // not_like matches every leaked row, so the count must start from a clean
        // table; the delete stays inside this test's transaction.
        Customer::query()->delete();
        $this->customerFactory->create(['email' => 'sfn4@ex.com', 'first_name' => 'Alice', 'last_name' => 'Wonder']);
        $this->customerFactory->create(['email' => 'sfn5@ex.com', 'first_name' => 'Bob', 'last_name' => 'Builder']);

        $results = Customer::query()->searchByFullName([
            'operator' => 'not_like',
            'value'    => 'Alice',
        ])->get();

        $this->assertCount(1, $results);
        $this->assertSame('Bob', $results->first()->first_name);
    }

    public function test_shipping_address_returns_only_shipping_type(): void
    {
        $customer = $this->customerFactory->create();
        $this->addressFactory->create($customer->id, ['type' => 'shipping', 'email' => $customer->email]);
        $this->addressFactory->create($customer->id, ['type' => 'billing', 'email' => $customer->email]);

        $fresh = Customer::with('shipping_address')->find($customer->id);
        $this->assertCount(1, $fresh->shipping_address);
        $this->assertSame('shipping', $fresh->shipping_address->first()->type);
    }

    public function test_billing_address_returns_only_billing_type(): void
    {
        $customer = $this->customerFactory->create();
        $this->addressFactory->create($customer->id, ['type' => 'shipping', 'email' => $customer->email]);
        $this->addressFactory->create($customer->id, ['type' => 'billing', 'email' => $customer->email]);

        $fresh = Customer::with('billing_address')->find($customer->id);
        $this->assertCount(1, $fresh->billing_address);
        $this->assertSame('billing', $fresh->billing_address->first()->type);
    }

    public function test_primary_shipping_address_returns_primary_only(): void
    {
        $customer = $this->customerFactory->create();
        $this->addressFactory->create($customer->id, ['type' => 'shipping', 'is_primary' => 1, 'email' => $customer->email]);
        $this->addressFactory->create($customer->id, ['type' => 'shipping', 'is_primary' => 0, 'email' => $customer->email, 'address_1' => '456 Other St']);

        $fresh = Customer::with('primary_shipping_address')->find($customer->id);
        $this->assertNotNull($fresh->primary_shipping_address);
        $this->assertSame(1, (int)$fresh->primary_shipping_address->is_primary);
    }

    public function test_primary_billing_address_returns_primary_only(): void
    {
        $customer = $this->customerFactory->create();
        $this->addressFactory->create($customer->id, ['type' => 'billing', 'is_primary' => 1, 'email' => $customer->email]);
        $this->addressFactory->create($customer->id, ['type' => 'billing', 'is_primary' => 0, 'email' => $customer->email, 'address_1' => '456 Other St']);

        $fresh = Customer::with('primary_billing_address')->find($customer->id);
        $this->assertNotNull($fresh->primary_billing_address);
        $this->assertSame(1, (int)$fresh->primary_billing_address->is_primary);
    }

    public function test_wp_user_belongs_to_user(): void
    {
        $customer = $this->customerFactory->createWithUser();
        $this->assertNotNull($customer->wpUser);
        $this->assertSame((int)$customer->user_id, (int)$customer->wpUser->ID);
    }

    // =========================================================================
    // Spec 28-30: recountStat / recountStats
    // =========================================================================

    public function test_recount_stat_with_no_orders_sets_zeros(): void
    {
        $customer = $this->customerFactory->create();
        $customer->recountStat();

        $this->assertSame(0, (int)$customer->purchase_count);
        $this->assertSame(0, (int)$customer->ltv);
        $this->assertSame(0, (int)$customer->aov);
        $this->assertNull($customer->first_purchase_date);
        $this->assertNull($customer->last_purchase_date);
    }

    public function test_recount_stat_calculates_from_paid_orders(): void
    {
        $customer = $this->customerFactory->create();

        // Create two paid orders directly in the DB
        Order::query()->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PAID,
            'total_paid'     => 5000,
            'total_refund'   => 0,
            'total_amount'   => 5000,
            'order_status'   => 'completed',
            'currency'       => 'USD',
            'created_at'     => '2025-01-01 00:00:00',
        ]);
        Order::query()->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PAID,
            'total_paid'     => 3000,
            'total_refund'   => 1000,
            'total_amount'   => 3000,
            'order_status'   => 'completed',
            'currency'       => 'USD',
            'created_at'     => '2025-06-01 00:00:00',
        ]);

        $customer->recountStat();

        $this->assertSame(2, (int)$customer->purchase_count);
        // LTV = (5000-0) + (3000-1000) = 7000
        $this->assertSame(7000, (int)$customer->ltv);
        // AOV = 7000 / 2 = 3500
        $this->assertSame(3500, (int)$customer->aov);
        // Dates may be DateTime objects or strings depending on model casts
        $firstDate = $customer->first_purchase_date;
        $lastDate = $customer->last_purchase_date;
        $this->assertStringContainsString('2025-01-01', is_string($firstDate) ? $firstDate : $firstDate->format('Y-m-d H:i:s'));
        $this->assertStringContainsString('2025-06-01', is_string($lastDate) ? $lastDate : $lastDate->format('Y-m-d H:i:s'));
    }

    public function test_recount_stats_legacy_throws_due_to_missing_columns(): void
    {
        global $wpdb;

        $customer = $this->customerFactory->create();

        Order::query()->create([
            'customer_id'    => $customer->id,
            'payment_status' => 'pending',
            'total_paid'     => 0,
            'total_refund'   => 0,
            'total_amount'   => 2000,
            'order_status'   => 'pending',
            'currency'       => 'USD',
        ]);

        // recountStats() sets dynamic attributes total_order_count and total_order_value
        // then calls save() — but these columns don't exist in the DB schema.
        // This documents the bug: the method is broken and throws QueryException.
        $this->expectException(\FluentCart\Framework\Database\QueryException::class);

        $previousSuppressState = $wpdb->suppress_errors();
        $wpdb->suppress_errors(true);

        try {
            $customer->recountStats();
        } finally {
            $wpdb->suppress_errors($previousSuppressState);
        }
    }

    // =========================================================================
    // Spec 31-32: updateCustomerStatus
    // =========================================================================

    public function test_update_customer_status_changes_status_and_fires_hooks(): void
    {
        $customer = $this->customerFactory->create(['status' => 'active']);

        $specificFired = false;
        $genericFired = false;
        add_action('fluent_cart/customer_status_to_inactive', function () use (&$specificFired) {
            $specificFired = true;
        });
        add_action('fluent_cart/customer_status_updated', function () use (&$genericFired) {
            $genericFired = true;
        });

        $customer->updateCustomerStatus('inactive');

        $this->assertSame('inactive', $customer->status);
        $this->assertTrue($specificFired, 'fluent_cart/customer_status_to_inactive should fire');
        $this->assertTrue($genericFired, 'fluent_cart/customer_status_updated should fire');
    }

    public function test_update_customer_status_same_status_does_nothing(): void
    {
        $customer = $this->customerFactory->create(['status' => 'active']);

        $hookFired = false;
        add_action('fluent_cart/customer_status_updated', function () use (&$hookFired) {
            $hookFired = true;
        });

        $customer->updateCustomerStatus('active');

        $this->assertSame('active', $customer->status);
        $this->assertFalse($hookFired, 'Hook should not fire for same status');
    }

    // =========================================================================
    // Spec 33-34: getMeta / updateMeta
    // =========================================================================

    public function test_get_meta_returns_value_for_existing_key(): void
    {
        $customer = $this->customerFactory->create();
        CustomerMeta::query()->create([
            'customer_id' => $customer->id,
            'meta_key'    => 'test_key',
            'meta_value'  => 'test_value',
        ]);

        $this->assertSame('test_value', $customer->getMeta('test_key'));
    }

    public function test_get_meta_returns_default_for_nonexistent_key(): void
    {
        $customer = $this->customerFactory->create();
        $this->assertSame('fallback', $customer->getMeta('nonexistent', 'fallback'));
        $this->assertNull($customer->getMeta('nonexistent'));
    }

    public function test_update_meta_creates_new_meta_if_not_exists(): void
    {
        $customer = $this->customerFactory->create();
        $customer->updateMeta('new_key', 'new_value');

        $meta = CustomerMeta::query()
            ->where('customer_id', $customer->id)
            ->where('meta_key', 'new_key')
            ->first();

        $this->assertNotNull($meta);
        $this->assertSame('new_value', $meta->meta_value);
    }

    public function test_update_meta_updates_existing_meta(): void
    {
        $customer = $this->customerFactory->create();
        $customer->updateMeta('update_key', 'original');
        $customer->updateMeta('update_key', 'updated');

        $count = CustomerMeta::query()
            ->where('customer_id', $customer->id)
            ->where('meta_key', 'update_key')
            ->count();

        $this->assertSame(1, $count);
        $this->assertSame('updated', $customer->getMeta('update_key'));
    }

    // =========================================================================
    // Spec 35-36: getWpUser / getWpUserId
    // =========================================================================

    public function test_get_wp_user_returns_user_by_id(): void
    {
        $customer = $this->customerFactory->createWithUser();
        $wpUser = $customer->getWpUser();

        $this->assertInstanceOf(\WP_User::class, $wpUser);
        $this->assertSame((int)$customer->user_id, $wpUser->ID);
    }

    public function test_get_wp_user_does_not_fall_back_to_email_or_write_user_id(): void
    {
        $email = 'fallback-email-' . wp_generate_password(4, false) . '@example.com';
        static::factory()->user->create([
            'user_email' => $email,
            'role'       => 'subscriber',
        ]);

        // Unlinked customer whose email a WP user happens to hold
        $customer = $this->customerFactory->create([
            'email'   => $email,
            'user_id' => 0,
        ]);

        $wpUser = $customer->getWpUser();

        $this->assertFalse($wpUser, 'An unlinked customer has no WP user, whoever holds the email');
        // user_id must not have been written by a read
        $fresh = Customer::find($customer->id);
        $this->assertEmpty($fresh->user_id, 'A getter must never rebind the customer');
    }

    public function test_get_wp_user_id_recheck_returns_stored_link_without_writing(): void
    {
        $email = 'recheck-' . wp_generate_password(4, false) . '@example.com';
        static::factory()->user->create([
            'user_email' => $email,
            'role'       => 'subscriber',
        ]);

        $customer = $this->customerFactory->create([
            'email'   => $email,
            'user_id' => 0,
        ]);

        $returnedId = $customer->getWpUserId(true);

        $this->assertEmpty($returnedId, 'recheck answers from the stored link only');
        $this->assertEmpty(Customer::find($customer->id)->user_id, 'recheck does not write');
    }

    // =========================================================================
    // Spec 94-96: Advanced filter options (CustomerFilter)
    // =========================================================================

    public function test_customer_filter_advance_options_include_full_name(): void
    {
        $options = \FluentCart\App\Services\Filter\CustomerFilter::advanceFilterOptions();
        $customerFilters = $options['customer']['children'] ?? [];

        $fullNameFilter = null;
        foreach ($customerFilters as $filter) {
            if (($filter['value'] ?? '') === 'customer_full_name') {
                $fullNameFilter = $filter;
                break;
            }
        }

        $this->assertNotNull($fullNameFilter, 'Advance filters should include customer_full_name');
        $this->assertSame('custom', $fullNameFilter['filter_type']);
    }

    public function test_customer_filter_advance_options_include_email(): void
    {
        $options = \FluentCart\App\Services\Filter\CustomerFilter::advanceFilterOptions();
        $customerFilters = $options['customer']['children'] ?? [];

        $emailFilter = null;
        foreach ($customerFilters as $filter) {
            if (($filter['value'] ?? '') === 'email') {
                $emailFilter = $filter;
                break;
            }
        }

        $this->assertNotNull($emailFilter, 'Advance filters should include email');
        $this->assertSame('column', $emailFilter['filter_type']);
    }

    public function test_customer_filter_advance_options_include_ltv(): void
    {
        $options = \FluentCart\App\Services\Filter\CustomerFilter::advanceFilterOptions();
        $customerFilters = $options['customer']['children'] ?? [];

        $ltvFilter = null;
        foreach ($customerFilters as $filter) {
            if (($filter['value'] ?? '') === 'ltv') {
                $ltvFilter = $filter;
                break;
            }
        }

        $this->assertNotNull($ltvFilter, 'Advance filters should include ltv');
        $this->assertSame('numeric', $ltvFilter['type']);
    }

    // =========================================================================
    // Spec 143: Edge case — searchBy with invalid column in operator syntax
    // =========================================================================

    public function test_search_by_invalid_column_operator_falls_through_to_like(): void
    {
        // Create one customer whose first_name literally matches the search string
        $target = $this->customerFactory->create(['email' => 'fallthrough@ex.com', 'first_name' => 'invalid_col=5']);
        // Create another with a normal name that won't match
        $this->customerFactory->create(['email' => 'normal@ex.com', 'first_name' => 'NormalName']);

        // "invalid_col=5" has an = sign, but invalid_col is not in fillable,
        // so it should fall through to LIKE search on first_name, last_name, email.
        // The customer with first_name 'invalid_col=5' should be found via LIKE.
        $results = Customer::query()->searchBy('invalid_col=5')->get();

        $this->assertSame(1, $results->count(), 'Should find exactly the customer whose first_name matches via LIKE fallback');
        $this->assertSame($target->id, $results->first()->id);
    }
}

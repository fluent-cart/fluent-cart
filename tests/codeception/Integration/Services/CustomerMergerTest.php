<?php

namespace Tests\Integration\Services;

use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\CustomerAddresses;
use FluentCart\App\Models\CustomerMeta;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderDownloadPermission;
use FluentCart\App\Services\CustomerIdentity\CustomerMerger;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\CustomerAddressFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * Case 6 of the customer identity binding fix (dev/CUSTOMER_IDENTITY_BINDING_AUDIT.md §6.6).
 *
 * Recovery moves resources but retains the unlinked parent for late writes.
 */
class CustomerMergerTest extends WPTestCase
{
    protected function tearDown(): void
    {
        remove_all_actions('fluent_cart/customer_resources_moved');
        parent::tearDown();
    }

    public function test_guest_resources_move_while_the_source_remains_unlinked(): void
    {
        $target = (new CustomerFactory())->createWithUser();
        $source = (new CustomerFactory())->create(['user_id' => null]);
        $order = (new OrderFactory())->create(['customer_id' => $source->id]);
        OrderDownloadPermission::query()->create(['order_id' => $order->id, 'variation_id' => 1, 'customer_id' => $source->id, 'download_id' => 1, 'download_count' => 0, 'download_limit' => 0]);
        $address = (new CustomerAddressFactory())->create($source->id, ['type' => 'shipping', 'is_primary' => 1]);
        CustomerMeta::query()->create(['customer_id' => $source->id, 'meta_key' => 'only_on_guest', 'meta_value' => 'kept']);
        $cart = (new CartFactory())->create(['customer_id' => $source->id]);

        $absorbed = CustomerMerger::absorb($source, $target);

        $this->assertTrue($absorbed, 'An emptied record is absorbed');
        $this->assertSame((int) $target->id, (int) Order::query()->find($order->id)->customer_id, 'The order moved');
        $this->assertSame((int) $target->id, (int) OrderDownloadPermission::query()->where('order_id', $order->id)->value('customer_id'), 'The download permission moved');
        $this->assertSame((int) $target->id, (int) CustomerAddresses::query()->find($address->id)->customer_id, 'The address moved');
        $this->assertSame((int) $target->id, (int) CustomerMeta::query()->where('meta_key', 'only_on_guest')->value('customer_id'), 'The meta moved');
        $this->assertSame((int) $target->id, (int) Cart::query()->where('cart_hash', $cart->cart_hash)->value('customer_id'), 'The cart moved');
        $this->assertNotNull(Customer::query()->find($source->id), 'Keep a valid parent for late writes');
        $this->assertEmpty(Customer::query()->find($source->id)->user_id);
    }

    public function test_the_targets_primary_address_wins_over_a_moved_primary_of_the_same_type(): void
    {
        $target = (new CustomerFactory())->createWithUser();
        $targetBilling = (new CustomerAddressFactory())->create($target->id, ['type' => 'billing', 'is_primary' => 1]);
        $source = (new CustomerFactory())->create(['user_id' => null]);
        $sourceBilling = (new CustomerAddressFactory())->create($source->id, ['type' => 'billing', 'is_primary' => 1]);

        CustomerMerger::absorb($source, $target);

        $this->assertSame(1, (int) CustomerAddresses::query()->find($targetBilling->id)->is_primary, 'The account keeps its primary billing address');
        $this->assertSame(0, (int) CustomerAddresses::query()->find($sourceBilling->id)->is_primary, 'The moved one is demoted rather than making two primaries');
        $this->assertSame((int) $target->id, (int) CustomerAddresses::query()->find($sourceBilling->id)->customer_id, 'But it is still moved');
    }

    public function test_the_target_keeps_its_own_value_for_a_meta_key_both_records_carry(): void
    {
        $target = (new CustomerFactory())->createWithUser();
        CustomerMeta::query()->create(['customer_id' => $target->id, 'meta_key' => 'shared_key', 'meta_value' => 'account']);
        $source = (new CustomerFactory())->create(['user_id' => null]);
        CustomerMeta::query()->create(['customer_id' => $source->id, 'meta_key' => 'shared_key', 'meta_value' => 'guest']);

        $absorbed = CustomerMerger::absorb($source, $target);

        $this->assertTrue($absorbed, 'The conflicting guest meta does not block the merge');
        $this->assertSame('account', CustomerMeta::query()->where('customer_id', $target->id)->where('meta_key', 'shared_key')->value('meta_value'), 'The account value stays');
        $this->assertSame(1, CustomerMeta::query()->where('meta_key', 'shared_key')->count(), 'The guest copy is dropped, not duplicated');
    }

    public function test_a_record_that_still_owns_something_after_the_handoff_is_kept(): void
    {
        $target = (new CustomerFactory())->createWithUser();
        $source = (new CustomerFactory())->create(['user_id' => null]);
        // An extension that cannot move its rows leaves something behind — simulated by re-attaching an order during the hook.
        add_action('fluent_cart/customer_resources_moved', function () use ($source) {
            (new OrderFactory())->create(['customer_id' => $source->id]);
        });

        $absorbed = CustomerMerger::absorb($source, $target);

        $this->assertFalse($absorbed, 'The merge reports it could not finish');
        $this->assertNotNull(Customer::query()->find($source->id), 'A record with data still attached is never deleted');
    }

    public function test_late_writes_keep_their_parent_and_can_be_recovered_again(): void
    {
        $target = (new CustomerFactory())->createWithUser();
        $source = (new CustomerFactory())->create(['user_id' => null]);
        $sourceId = (int) $source->id;

        $this->assertTrue(CustomerMerger::absorb($source, $target));
        // A writer selected this ID before the merge and finishes after its final check.
        $lateOrder = (new OrderFactory())->create(['customer_id' => $sourceId]);
        $lateMeta = CustomerMeta::query()->create([
            'customer_id' => $sourceId, 'meta_key' => 'extension_late_write', 'meta_value' => 'kept'
        ]);

        $retained = Customer::query()->find($sourceId);
        $this->assertNotNull($retained, 'Late core and extension writes must still reference a real customer');
        $this->assertEmpty($retained->user_id, 'Retaining the source must not grant account ownership');
        $this->assertTrue(CustomerMerger::absorb($retained, $target));
        $this->assertSame((int) $target->id, (int) Order::query()->find($lateOrder->id)->customer_id);
        $this->assertSame((int) $target->id, (int) CustomerMeta::query()->find($lateMeta->id)->customer_id);
    }

    public function test_a_linked_record_is_never_absorbed(): void
    {
        $target = (new CustomerFactory())->createWithUser();
        $linked = (new CustomerFactory())->createWithUser();

        $this->assertFalse(CustomerMerger::absorb($linked, $target), 'Another account\'s record is not something a claim can take');
        $this->assertNotNull(Customer::query()->find($linked->id), 'It is untouched');
    }
}

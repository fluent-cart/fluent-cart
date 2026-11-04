<?php

namespace Tests\Integration\Services\Filter;

use FluentCart\App\Models\Product;
use FluentCart\App\Services\Filter\AttrGroupFilter;
use FluentCart\App\Services\Filter\AttrTermFilter;
use FluentCart\App\Services\Filter\CouponFilter;
use FluentCart\App\Services\Filter\CustomerFilter;
use FluentCart\App\Services\Filter\LabelFilter;
use FluentCart\App\Services\Filter\LogFilter;
use FluentCart\App\Services\Filter\OrderFilter;
use FluentCart\App\Services\Filter\ProductFilter;
use FluentCart\App\Services\Filter\TaxFilter;
use FluentCart\App\Services\Filter\VariationFilter;
use FluentCart\App\Modules\Shipping\Services\Filter\ShippingClassFilter;
use FluentCart\App\Modules\Shipping\Services\Filter\ShippingZoneFilter;
use FluentCart\App\Modules\Subscriptions\Services\Filter\SubscriptionFilter;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\ActivityFactory;
use Tests\Support\Factory\AppliedCouponFactory;
use Tests\Support\Factory\CouponFactory;
use Tests\Support\Factory\CustomerAddressFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTaxRateFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\ShippingMethodFactory;
use Tests\Support\Factory\ShippingZoneFactory;
use Tests\Support\Factory\SubscriptionFactory;
use Tests\Support\Factory\TaxClassFactory;
use Tests\Support\Factory\TaxRateFactory;
use Tests\Support\Factory\UserRoleFactory;

/**
 * Phase 2 of the `with`/`scopes` allow-list: the per-filter MAPS.
 *
 * BaseFilterWithAllowlistTest and BaseFilterScopeAllowlistTest pin the ENGINE
 * against purpose-built test filters. This file pins the DECLARATIONS the
 * shipping filters make — that each production list still eager-loads what its
 * screen renders, that permission-gated relations really are gated, and that
 * the relations we deliberately refused stay refused.
 *
 * Every map has two tiers and both are pinned here:
 *
 *   SCREEN keys — one per calling screen, loading exactly what that screen
 *   renders, with each relation gated inside the callback.
 *
 *   PUBLIC keys — the plain relation names that were accepted request values
 *   before this allow-list existed. They are first-class entry points, each
 *   individually gated. Dropping or renaming one narrows a published response
 *   for every external consumer already sending it, silently and with no error
 *   anywhere — which is why "no consumer in our repos" is not a reason to drop
 *   one, and why each is pinned to RESOLVE as well as to carry its gate.
 *
 * Permission note: `PermissionManager::getUserPermissions()` returns every
 * permission for a `manage_options` user and, with Pro absent, an empty array
 * for everyone else. So the two constructible actors here are "holds
 * everything" (administrator) and "holds nothing" (subscriber) — a
 * "customers/view but not orders/view" actor cannot be built in this suite.
 * Each gated relation is therefore proved at both reachable ends.
 */
class FilterAllowlistMapsTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private ProductFactory $productFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
        $this->productFactory = new ProductFactory();
    }

    public function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function asPermittedActor(): void
    {
        wp_set_current_user(UserRoleFactory::createAdmin());
    }

    private function asUnpermittedActor(): void
    {
        wp_set_current_user(UserRoleFactory::createSubscriber());
    }

    // =========================================================================
    // OrderFilter — serves both `GET /orders` and `GET /customers/{id}/orders`
    // =========================================================================

    public function test_order_list_eager_loads_items_and_customer_for_a_permitted_actor()
    {
        $this->asPermittedActor();
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        (new OrderItemFactory())->create((int)$order->id);

        $loaded = OrderFilter::make([
            'with' => ['admin_order_list'],
        ])->get()->first();

        $this->assertTrue(
            $loaded->relationLoaded('order_items'),
            'The orders list renders line items and must still eager load them'
        );
        $this->assertTrue(
            $loaded->relationLoaded('customer'),
            'The orders list renders the customer popover and must still eager load the customer'
        );
    }

    /**
     * The screen key gates each relation INSIDE the callback, so a refusal is
     * scoped to the half that failed its check. An actor with no permissions at
     * all still gets the line items — the orders route already authorised those —
     * and gets no customer row.
     */
    public function test_order_list_customer_half_is_dropped_for_an_actor_without_customers_view()
    {
        $this->asUnpermittedActor();
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        (new OrderItemFactory())->create((int)$order->id);

        $loaded = OrderFilter::make([
            'with' => ['admin_order_list'],
        ])->get()->first();

        $this->assertFalse(
            $loaded->relationLoaded('customer'),
            'A customer row is customer data even when reached from an order — it needs customers/view'
        );
        $this->assertTrue(
            $loaded->relationLoaded('order_items'),
            'Refusing the gated half must not take the route-authorised half down with it'
        );
    }

    /**
     * The customer-screen key deliberately carries NO permission bar: its route
     * is `GET /customers/{id}/orders`, gated on `customers/view` alone. Adding
     * `orders/view` would silently blank the order history for a role that holds
     * only `customers/view`, which is the behaviour this pins.
     */
    public function test_customer_orders_key_carries_no_extra_permission_bar()
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        (new OrderItemFactory())->create((int)$order->id);

        $this->asUnpermittedActor();

        $loaded = OrderFilter::make(['with' => ['admin_customer_orders']])->get()->first();

        $this->assertTrue(
            $loaded->relationLoaded('order_items'),
            'SingleCustomer.vue must keep rendering line items for a customers/view-only role'
        );
        $this->assertFalse(
            $loaded->relationLoaded('customer'),
            'The customer detail screen is already the customer — the key loads only what it renders'
        );
    }

    public function test_order_list_key_loads_both_customer_path_segments()
    {
        $this->asPermittedActor();
        $customer = $this->customerFactory->create();
        (new CustomerAddressFactory())->create((int)$customer->id, [
            'type'       => 'billing',
            'is_primary' => 1,
        ]);
        $this->orderFactory->create(['customer_id' => $customer->id]);

        $loaded = OrderFilter::make([
            'with' => ['admin_order_list'],
        ])->get()->first();

        $this->assertTrue(
            $loaded->relationLoaded('customer'),
            'One key loads its whole subtree, so the parent is eager loaded, not lazily queried'
        );
        $this->assertNotNull(
            $loaded->customer->primary_billing_address,
            'OrderTable.js renders the billing phone from this nested relation'
        );
    }

    /**
     * The bypass the one-key-one-subtree rule exists to close.
     *
     * Builder::with() auto-injects an unlisted parent segment with an EMPTY
     * closure, so a nested path that reached the parent without the parent's
     * check would be a way around the gate. It cannot: the public
     * `customer.primary_billing_address` key routes to the SAME callback as
     * `customer`, and that callback states the customers/view bar before it
     * loads anything.
     */
    public function test_order_customer_subtree_is_refused_whole_for_an_unpermitted_actor()
    {
        $customer = $this->customerFactory->create();
        (new CustomerAddressFactory())->create((int)$customer->id, [
            'type'       => 'billing',
            'is_primary' => 1,
        ]);
        $this->orderFactory->create(['customer_id' => $customer->id]);

        $this->asUnpermittedActor();

        $loaded = OrderFilter::make([
            'with' => ['admin_order_list', 'customer', 'customer.primary_billing_address'],
        ])->get()->first();

        $this->assertFalse(
            $loaded->relationLoaded('customer'),
            'An actor without customers/view must get NO customer row — neither through the screen key '
            . 'nor through either public key'
        );
        $this->assertArrayNotHasKey(
            'customer',
            $loaded->getRelations(),
            'Nothing customer-shaped may be attached to the order at all'
        );
    }

    /**
     * The three public entry points on `GET /orders`. These are relation names,
     * and they resolve on purpose: they were accepted request values before this
     * allow-list existed, so renaming them to screen keys would have silently
     * narrowed the response for every external consumer already sending them.
     */
    public function test_order_public_entry_points_resolve()
    {
        $this->asPermittedActor();
        $customer = $this->customerFactory->create();
        (new CustomerAddressFactory())->create((int)$customer->id, [
            'type'       => 'billing',
            'is_primary' => 1,
        ]);
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        (new OrderItemFactory())->create((int)$order->id);

        $loaded = OrderFilter::make([
            'with' => ['order_items', 'customer', 'customer.primary_billing_address'],
        ])->get()->first();

        $this->assertTrue($loaded->relationLoaded('order_items'), 'with[]=order_items must resolve');
        $this->assertTrue($loaded->relationLoaded('customer'), 'with[]=customer must resolve');
        $this->assertNotNull(
            $loaded->customer->primary_billing_address,
            'with[]=customer.primary_billing_address must resolve the whole path'
        );
    }

    /**
     * `order_items` was UNGATED before this allow-list existed, and it stays
     * ungated. Adding a bar under a name consumers already send would be a
     * silent tightening — the same class of breakage as a silent narrowing.
     */
    public function test_order_items_public_key_stays_ungated()
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        (new OrderItemFactory())->create((int)$order->id);

        $this->asUnpermittedActor();

        $loaded = OrderFilter::make(['with' => ['order_items']])->get()->first();

        $this->assertTrue(
            $loaded->relationLoaded('order_items'),
            'The line items belong to the order the route already authorised'
        );
    }

    /**
     * The public tier is a named list, not a licence for relation names in
     * general. Everything not on it stays refused at any depth.
     *
     * @dataProvider refusedOrderKeyProvider
     */
    public function test_order_relations_outside_the_public_tier_are_refused(string $requested)
    {
        $this->asPermittedActor();
        $customer = $this->customerFactory->createWithUser();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        (new OrderItemFactory())->create((int)$order->id);

        $query = OrderFilter::make(['with' => [$requested]])->buildQuery();

        $this->assertSame(
            [],
            $query->getEagerLoads(),
            $requested . ' is not a request key on GET /orders, even for a super admin'
        );
    }

    public function refusedOrderKeyProvider(): array
    {
        return [
            // Reaches the WordPress users row through a path whose first
            // segment IS a public key — the depth is what makes it refused.
            'customer wp user' => ['customer.wpUser'],
            'transactions'     => ['transactions'],
            'order meta'       => ['orderMeta'],
        ];
    }

    // =========================================================================
    // CustomerFilter
    // =========================================================================

    /**
     * The customer autocomplete on the order screen prefills both address blocks,
     * so ONE key has to carry both. Splitting them across two entries would mean
     * two separate `with()` calls array_merging into $eagerLoad, and the second
     * would clobber any constraint the first put on a shared segment.
     */
    public function test_customer_search_key_eager_loads_both_address_sets()
    {
        $this->asPermittedActor();
        $customer = $this->customerFactory->create();
        $addressFactory = new CustomerAddressFactory();
        $addressFactory->create((int)$customer->id, ['type' => 'billing']);
        $addressFactory->create((int)$customer->id, ['type' => 'shipping']);

        $loaded = CustomerFilter::make([
            'with' => ['admin_customer_search'],
        ])->get()->first();

        $this->assertTrue($loaded->relationLoaded('billing_address'), 'billing_address is eager loaded');
        $this->assertTrue($loaded->relationLoaded('shipping_address'), 'shipping_address is eager loaded');
        $this->assertSame(1, $loaded->billing_address->count(), 'Exactly the owned billing row');
        $this->assertSame(1, $loaded->shipping_address->count(), 'Exactly the owned shipping row');
    }

    /**
     * `wpUser` is off the map on its own merit: it is a BelongsTo onto the
     * WordPress users table, and no permission on this route grants that. So are
     * `orders` and `subscriptions`, which are billing rows behind other
     * permissions.
     *
     * @dataProvider refusedCustomerKeyProvider
     */
    public function test_customer_relations_outside_the_public_tier_are_refused(string $requested)
    {
        $this->asPermittedActor();
        $this->customerFactory->createWithUser();

        $query = CustomerFilter::make(['with' => [$requested]])->buildQuery();

        $this->assertSame(
            [],
            $query->getEagerLoads(),
            $requested . ' is not a request key on GET /customers, even for a super admin'
        );
    }

    public function refusedCustomerKeyProvider(): array
    {
        return [
            'wp user'       => ['wpUser'],
            'orders'        => ['orders'],
            'subscriptions' => ['subscriptions'],
        ];
    }

    /**
     * `primary_shipping_address`, `primary_billing_address` and `labels` were
     * briefly dropped from this map on the grounds that no client in this repo
     * sends them. That reasoning was wrong and the review rejected it: they were
     * ACCEPTED request values, so a consumer outside this repo may have been
     * sending them, and dropping one hands that consumer a narrower response with
     * nothing anywhere to notice. They are restored as public entry points.
     *
     * @dataProvider publicCustomerRelationProvider
     */
    public function test_customer_public_entry_points_resolve(string $relation)
    {
        $this->asPermittedActor();
        $customer = $this->customerFactory->create();
        (new CustomerAddressFactory())->create((int)$customer->id, [
            'type'       => 'billing',
            'is_primary' => 1,
        ]);
        (new CustomerAddressFactory())->create((int)$customer->id, [
            'type'       => 'shipping',
            'is_primary' => 1,
        ]);

        $loaded = CustomerFilter::make(['with' => [$relation]])->get()->first();

        $this->assertTrue(
            $loaded->relationLoaded($relation),
            'with[]=' . $relation . ' is a published request key on GET /customers and must resolve'
        );
    }

    public function publicCustomerRelationProvider(): array
    {
        return [
            'shipping addresses'       => ['shipping_address'],
            'billing addresses'        => ['billing_address'],
            'primary shipping address' => ['primary_shipping_address'],
            'primary billing address'  => ['primary_billing_address'],
            'labels'                   => ['labels'],
        ];
    }

    /**
     * The addresses need no bar beyond the route's own `customers/view` — they
     * are the customer's own rows, which is exactly what that permission grants.
     * `labels` is different: labels are their own resource with their own
     * `labels/view` bar, the same one CustomerController::allowedEagerLoads()
     * applies on the detail route. Both directions are pinned so the two doors
     * cannot drift apart.
     */
    public function test_customer_labels_key_is_gated_on_labels_view()
    {
        $this->customerFactory->create();

        $this->asUnpermittedActor();
        $denied = CustomerFilter::make(['with' => ['labels']])->get()->first();
        $this->assertFalse(
            $denied->relationLoaded('labels'),
            'Labels are their own resource behind labels/view'
        );

        $this->asPermittedActor();
        $allowed = CustomerFilter::make(['with' => ['labels']])->get()->first();
        $this->assertTrue(
            $allowed->relationLoaded('labels'),
            'An actor holding labels/view still gets the labels'
        );
    }

    // =========================================================================
    // ProductFilter
    // =========================================================================

    public function test_product_list_eager_loads_detail_and_variants()
    {
        $this->asPermittedActor();
        $this->productFactory->create();

        $loaded = ProductFilter::make([
            'with' => ['admin_product_list'],
        ])->get()->first();

        $this->assertTrue($loaded->relationLoaded('detail'), 'ProductTable.js renders the detail image and price');
        $this->assertTrue($loaded->relationLoaded('variants'), 'ProductTable.js sums variants[].available for the stock column');
    }

    /**
     * The public tier on `GET /products` is exactly two names. A dotted path is
     * not one of them: `with('a.b')` auto-injects `a`, so allowing depth here
     * would be a way to reach a subtree no screen key grants.
     */
    public function test_product_relations_outside_the_public_tier_are_refused()
    {
        $this->asPermittedActor();
        $this->productFactory->create();

        $query = ProductFilter::make([
            'with' => ['detail.variants', 'variants.media', 'categories', 'orderItems'],
        ])->buildQuery();

        $this->assertSame(
            [],
            $query->getEagerLoads(),
            'Only the four screen keys and the two public entry points are request keys'
        );
    }

    /**
     * `detail` and `variants` are the filter's public entry points — the plain,
     * unnested version of the two relations an external consumer can reasonably
     * ask a product endpoint for. The screen keys serve the four internal
     * screens that each need a different depth of the same relations.
     */
    public function test_product_public_entry_points_resolve()
    {
        $this->asPermittedActor();
        $this->productFactory->create();

        $loaded = ProductFilter::make(['with' => ['detail', 'variants']])->get()->first();

        $this->assertTrue(
            $loaded->relationLoaded('detail'),
            'with[]=detail is a public entry point and must resolve'
        );
        $this->assertTrue(
            $loaded->relationLoaded('variants'),
            'with[]=variants is a public entry point and must resolve'
        );
    }

    /**
     * The public entry points must not be a way around the gate. They carry the
     * same products/view bar as the context keys they share callbacks with.
     */
    public function test_product_public_entry_points_carry_the_same_permission_gate()
    {
        $this->asUnpermittedActor();
        $this->productFactory->create();

        $loaded = ProductFilter::make(['with' => ['detail', 'variants']])->get()->first();

        $this->assertFalse(
            $loaded->relationLoaded('detail'),
            'A public entry point is not a way around the products/view check'
        );
        $this->assertFalse($loaded->relationLoaded('variants'));
    }

    /**
     * `categories` is a three-join hasMany per row that three clients used to
     * request and none of them read. It is off the map on purpose; this pins
     * that so it cannot drift back in as "harmless".
     */
    public function test_product_categories_is_refused()
    {
        $this->asPermittedActor();
        $this->productFactory->create();

        $loaded = ProductFilter::make(['with' => ['categories']])->get()->first();

        $this->assertFalse(
            $loaded->relationLoaded('categories'),
            'No client renders product categories — the relation stays off the map'
        );
    }

    /**
     * The block and Elementor pickers render a per-variant thumbnail, which is
     * the `thumbnail` append on ProductVariation reading `media`. `media` rides
     * in the SAME with() call as `variants`: a later call naming `variants.*`
     * would inject an empty closure over `variants` and drop whatever the first
     * one constrained.
     *
     * `detail` is loaded in its own right rather than as an ORM-injected parent
     * of a dotted path — without it `elementor/VariationSelector/ListItem.vue`
     * reads `product.detail.stock_availability` / `min_price` / `max_price` as
     * undefined, a silently blank picker rather than an error.
     *
     * @dataProvider pickerKeyProvider
     */
    public function test_picker_keys_carry_the_whole_subtree_in_one_call(string $pickerKey)
    {
        $this->asPermittedActor();
        $this->productFactory->create();

        $loaded = ProductFilter::make(['with' => [$pickerKey]])->get()->first();

        $this->assertTrue($loaded->relationLoaded('detail'), 'the picker renders detail price and stock fields');
        $this->assertNotNull($loaded->detail, 'the detail row is really hydrated, not an empty relation');
        $this->assertTrue($loaded->relationLoaded('variants'), 'the picker lists the variation rows');

        $variant = $loaded->variants->first();

        $this->assertNotNull($variant, 'the picker needs the variant rows it renders');
        $this->assertTrue(
            $variant->relationLoaded('media'),
            'getVariantThumbnail() reads variant.thumbnail, which is backed by media'
        );
    }

    public function pickerKeyProvider(): array
    {
        return [
            'gutenberg picker' => ['block_picker'],
            'elementor picker' => ['elementor_picker'],
        ];
    }

    /**
     * The exact payload Orders/Modals/AddProductItemModal.vue sends when picking
     * a product to add to an order. This regressed once: `bundleChildren` was
     * missing from the map, productService.js read `variant.bundle_children` into
     * `bundle_items` as undefined, and the template called `.length` on it with no
     * optional chaining — a TypeError on any bundle product rather than a blank.
     */
    public function test_product_list_serves_the_add_product_modal_payload()
    {
        $this->asPermittedActor();
        $this->productFactory->create();

        $loaded = ProductFilter::make([
            'with' => ['admin_order_add_product'],
        ])->get()->first();

        $this->assertTrue($loaded->relationLoaded('detail'), 'the parent segment still loads');

        $variant = $loaded->detail->variants->first();

        $this->assertNotNull($variant, 'the modal needs the variant rows it renders');
        $this->assertTrue(
            $variant->relationLoaded('bundleChildren'),
            'AddProductItemModal.vue calls .length on bundle_items without a guard'
        );
        $this->assertTrue($variant->relationLoaded('media'), 'the modal renders the variant image');
    }

    /**
     * Every entry gates on products/view. An actor without it gets nothing at
     * all — not a partially hydrated tree, which would be the worse outcome.
     */
    public function test_product_withs_are_gated_on_products_view()
    {
        $this->productFactory->create();
        $this->asUnpermittedActor();

        $query = ProductFilter::make([
            'with' => [
                'admin_product_list',
                'block_picker',
                'elementor_picker',
                'admin_order_add_product',
                'detail',
                'variants',
            ],
        ])->buildQuery();

        $this->assertSame(
            [],
            $query->getEagerLoads(),
            'Holding no products/view must yield no catalogue relations at all'
        );
    }

    public function test_product_downloadable_files_is_refused()
    {
        $this->asPermittedActor();
        $this->productFactory->create();

        $loaded = ProductFilter::make(['with' => ['downloadable_files']])->get()->first();

        $this->assertFalse(
            $loaded->relationLoaded('downloadable_files'),
            'Protected download rows are not catalogue data and stay off the map'
        );
    }

    public function test_product_cartable_scope_applies_and_delete_is_refused()
    {
        $this->asPermittedActor();
        $this->productFactory
            ->withVariantAttributes(['payment_type' => 'subscription'])
            ->create();
        $oneTime = $this->productFactory->create();

        $countBefore = Product::query()->count();

        $results = ProductFilter::make(['scopes' => ['cartable', 'delete']])->get();

        $this->assertSame(
            $countBefore,
            Product::query()->count(),
            'scopes[]=delete must never reach Orm\Builder::delete() — no row may disappear'
        );

        $ids = $results->pluck('ID')->all();
        $this->assertContains(
            (int)$oneTime->ID,
            array_map('intval', $ids),
            'The cartable scope keeps the one-time product'
        );
        $this->assertLessThan(
            $countBefore,
            $results->count(),
            'The cartable scope actually narrowed the result set — it was applied, not silently dropped'
        );
    }

    // =========================================================================
    // LogFilter — the activity feed
    // =========================================================================

    /**
     * There is no screen key here because there is no screen: LogTable.js does
     * not override `Table::with()`. `user` is the public entry point, and it is
     * also what the Phase 16 throughput guard (`throughput-activity-feed`)
     * sends — that guard materializes `$activity->user` for every row inside its
     * query-count boundary, so without an eager-load path its N+1 assertion
     * cannot pass at all.
     */
    public function test_activity_feed_loads_the_actor_and_refuses_the_morph_relation()
    {
        $this->asPermittedActor();
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        (new ActivityFactory())->createForOrder((int)$order->id, [
            'user_id' => get_current_user_id(),
        ]);

        $loaded = LogFilter::make([
            'with' => ['user', 'activity'],
        ])->get()->first();

        $this->assertTrue(
            $loaded->relationLoaded('user'),
            'The throughput guard materializes the actor per row and must keep eager loading it'
        );
        $this->assertFalse(
            $loaded->relationLoaded('activity'),
            'Activity::activity() is a morphTo — its target is row data, so one with[] could hydrate any logged model'
        );
    }

    // =========================================================================
    // CouponFilter — a public count with no screen behind it, and the scope map
    // =========================================================================

    /**
     * No admin screen reads this: `applied_coupons_count` appears nowhere under
     * `resources/`, and AllCoupons.vue and CouponsTableMobile.vue both render
     * the real `use_count` column. CouponTable.js therefore sends nothing.
     *
     * The KEY still resolves, because it was an accepted request value before
     * the allow-list existed and "no consumer in our repos" is not evidence of
     * no consumer. It needs no permission bar: what comes back is a number, and
     * the coupon row's own `use_count` column already says the same thing.
     *
     * The ROWS behind it stay refused on their own merit — they say which orders
     * used which coupon, which is order data.
     */
    public function test_coupon_count_is_a_public_key_and_the_rows_behind_it_are_not()
    {
        $this->asPermittedActor();
        $coupon = (new CouponFactory())->create();
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        (new AppliedCouponFactory())->create([
            'order_id'  => $order->id,
            'coupon_id' => $coupon->id,
            'code'      => $coupon->code,
        ]);

        $requested = CouponFilter::make([
            'with' => ['appliedCouponsCount', 'appliedCoupons'],
        ])->buildQuery();

        $this->assertSame(
            [],
            $requested->getEagerLoads(),
            'The rows behind the count are order data and are not on the with map'
        );
        $this->assertStringContainsString(
            'applied_coupons_count',
            $requested->toSql(),
            'The count itself is a published request key and must still resolve'
        );

        $loaded = CouponFilter::make(['with' => ['appliedCouponsCount']])->get()->first();

        $this->assertArrayHasKey(
            'applied_coupons_count',
            $loaded->getAttributes(),
            'with[]=appliedCouponsCount returns the aggregate it names'
        );
    }

    /**
     * The count is deliberately ungated: it is a number, not the orders. An
     * actor with no permissions at all still gets it, which is what stops the
     * restoration from being a tightening under a familiar name.
     */
    public function test_coupon_count_needs_no_permission()
    {
        (new CouponFactory())->create();

        $this->asUnpermittedActor();

        $loaded = CouponFilter::make(['with' => ['appliedCouponsCount']])->get()->first();

        $this->assertArrayHasKey(
            'applied_coupons_count',
            $loaded->getAttributes(),
            'A redemption count reveals nothing the coupon row does not already carry as use_count'
        );
    }

    public function test_coupon_active_scope_is_applied()
    {
        $this->asPermittedActor();
        $couponFactory = new CouponFactory();
        $active = $couponFactory->create(['status' => 'active']);
        $draft = $couponFactory->create(['status' => 'draft']);

        $ids = array_map('intval', CouponFilter::make(['scopes' => ['active']])->get()->pluck('id')->all());

        $this->assertContains((int)$active->id, $ids, 'Orders/Coupon.vue suggests only active coupons');
        $this->assertNotContains((int)$draft->id, $ids, 'The scope really ran — a non-active coupon is excluded');
    }

    // =========================================================================
    // TaxFilter — server-side select and scope
    // =========================================================================

    public function test_tax_list_order_relation_returns_exactly_the_declared_columns()
    {
        $this->asPermittedActor();
        $taxClass = (new TaxClassFactory())->create();
        $taxRate = (new TaxRateFactory())->create(['class_id' => $taxClass->id]);
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'payment_status' => 'paid',
        ]);
        (new OrderTaxRateFactory())->create([
            'order_id'    => $order->id,
            'tax_rate_id' => $taxRate->id,
        ]);

        $loaded = TaxFilter::make([
            'with' => ['admin_tax_report'],
        ])->get()->first();

        $this->assertTrue($loaded->relationLoaded('tax_rate'), 'The tax report renders the rate');
        $this->assertTrue($loaded->relationLoaded('order'), 'The tax report renders the order currency');

        $attributes = $loaded->order->getAttributes();
        $this->assertSame(
            ['id', 'currency'],
            array_keys($attributes),
            'The select moved from TaxesTable.js into TaxFilter::adminTaxReport() unchanged — '
            . 'exactly id and currency, and applied to the RELATION rather than the tax-rate query'
        );
    }

    /**
     * `tax_rate` and `order` are the public entry points. They give the PLAIN
     * relation: the two-column select above is a property of the report screen,
     * not of the endpoint's published shape, so narrowing a public key to it
     * would silently shrink what a consumer already receives.
     */
    public function test_tax_public_entry_points_resolve_the_unnarrowed_relation()
    {
        $this->asPermittedActor();
        $taxClass = (new TaxClassFactory())->create();
        $taxRate = (new TaxRateFactory())->create(['class_id' => $taxClass->id]);
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'payment_status' => 'paid',
        ]);
        (new OrderTaxRateFactory())->create([
            'order_id'    => $order->id,
            'tax_rate_id' => $taxRate->id,
        ]);

        $loaded = TaxFilter::make(['with' => ['tax_rate', 'order']])->get()->first();

        $this->assertTrue($loaded->relationLoaded('tax_rate'), 'with[]=tax_rate must resolve');
        $this->assertTrue($loaded->relationLoaded('order'), 'with[]=order must resolve');
        $this->assertArrayHasKey(
            'status',
            $loaded->order->getAttributes(),
            'The public key hands back the whole order row, not the two columns the report screen selects'
        );
    }

    public function test_tax_valid_order_scope_is_applied()
    {
        $this->asPermittedActor();
        $taxClass = (new TaxClassFactory())->create();
        $taxRate = (new TaxRateFactory())->create(['class_id' => $taxClass->id]);
        $customer = $this->customerFactory->create();
        $orderTaxRateFactory = new OrderTaxRateFactory();

        $paidOrder = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'payment_status' => 'paid',
        ]);
        $paidRow = $orderTaxRateFactory->create([
            'order_id'    => $paidOrder->id,
            'tax_rate_id' => $taxRate->id,
        ]);

        $pendingOrder = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'payment_status' => 'pending',
        ]);
        $pendingRow = $orderTaxRateFactory->create([
            'order_id'    => $pendingOrder->id,
            'tax_rate_id' => $taxRate->id,
        ]);

        $ids = array_map('intval', TaxFilter::make(['scopes' => ['validOrder']])->get()->pluck('id')->all());

        $this->assertContains((int)$paidRow->id, $ids, 'A paid order contributes to the tax report');
        $this->assertNotContains((int)$pendingRow->id, $ids, 'The scope really ran — an unpaid order is excluded');
    }

    // =========================================================================
    // ShippingZoneFilter — count only
    // =========================================================================

    public function test_shipping_zone_list_gets_a_method_count()
    {
        $this->asPermittedActor();
        $zone = (new ShippingZoneFactory())->create();
        (new ShippingMethodFactory())->create(['zone_id' => $zone->id]);

        $loaded = null;
        $requested = ['admin_shipping_zone_list', 'methods'];
        foreach (ShippingZoneFilter::make(['with' => $requested])->get() as $row) {
            if ((int)$row->id === (int)$zone->id) {
                $loaded = $row;
                break;
            }
        }

        $this->assertNotNull($loaded, 'The seeded zone is returned');
        $this->assertSame(
            1,
            (int)$loaded->getAttributes()['methods_count'],
            'ShippingZoneTable.js renders the method count'
        );
        $this->assertFalse(
            $loaded->relationLoaded('methods'),
            'Only the count is declared — the method ROWS themselves are not on the with map'
        );
    }

    /**
     * `methodsCount` is the name the client sent before this allow-list existed,
     * so it stays an accepted value for anybody else who was already sending it.
     * It resolves to the same withCount() the screen key does.
     */
    public function test_shipping_zone_public_count_key_resolves()
    {
        $this->asPermittedActor();
        $zone = (new ShippingZoneFactory())->create();
        (new ShippingMethodFactory())->create(['zone_id' => $zone->id]);

        $loaded = null;
        foreach (ShippingZoneFilter::make(['with' => ['methodsCount']])->get() as $row) {
            if ((int)$row->id === (int)$zone->id) {
                $loaded = $row;
                break;
            }
        }

        $this->assertNotNull($loaded, 'The seeded zone is returned');
        $this->assertSame(
            1,
            (int)$loaded->getAttributes()['methods_count'],
            'with[]=methodsCount is a published request key and must still produce the aggregate'
        );
    }

    // =========================================================================
    // SubscriptionFilter
    // =========================================================================

    public function test_subscription_customer_is_gated_on_customers_view()
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $product = $this->productFactory->create();
        (new SubscriptionFactory())->create([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => $product->ID,
            'variation_id'    => $product->variants->first()->id,
        ]);

        $this->asUnpermittedActor();
        $denied = SubscriptionFilter::make([
            'with' => ['admin_subscription_list'],
        ])->get()->first();
        $this->assertFalse(
            $denied->relationLoaded('customer'),
            'subscriptions/view says nothing about customers'
        );

        // The public key is not a way around the same bar.
        $deniedPublic = SubscriptionFilter::make(['with' => ['customer']])->get()->first();
        $this->assertFalse(
            $deniedPublic->relationLoaded('customer'),
            'The public entry point carries the same customers/view check as the screen key'
        );

        $this->asPermittedActor();
        $allowed = SubscriptionFilter::make([
            'with' => ['admin_subscription_list'],
        ])->get()->first();
        $this->assertTrue(
            $allowed->relationLoaded('customer'),
            'SubscriptionTable.js renders the customer and must still get it'
        );

        $allowedPublic = SubscriptionFilter::make(['with' => ['customer']])->get()->first();
        $this->assertTrue(
            $allowedPublic->relationLoaded('customer'),
            'with[]=customer is a published request key on GET /subscriptions and must resolve'
        );
    }

    /**
     * The public tier here is one name. The relations that carry gateway ids,
     * licence keys and arbitrary stored meta stay refused.
     *
     * @dataProvider refusedSubscriptionKeyProvider
     */
    public function test_subscription_relations_outside_the_public_tier_are_refused(string $requested)
    {
        $this->asPermittedActor();

        $query = SubscriptionFilter::make(['with' => [$requested]])->buildQuery();

        $this->assertSame(
            [],
            $query->getEagerLoads(),
            $requested . ' is not a request key on GET /subscriptions, even for a super admin'
        );
    }

    public function refusedSubscriptionKeyProvider(): array
    {
        return [
            'transactions' => ['transactions'],
            'license'      => ['license'],
            'meta'         => ['meta'],
        ];
    }

    // =========================================================================
    // The filters whose maps are deliberately empty
    // =========================================================================

    /**
     * These five declare `allowedWiths(): []` on purpose — three because no
     * client sends `with` at all, two (LabelFilter, VariationFilter) because
     * their only caller hand-builds its arguments and cannot forward one.
     *
     * Asserted through the real path: applyWith() registers eager loads on the
     * builder, so an empty map means the builder ends up with none. That also
     * covers counts, which applyWith() registers as `withCount` aggregates.
     *
     * @dataProvider emptyMapFilterProvider
     */
    public function test_filters_with_an_empty_map_register_no_eager_load(string $filterClass, string $relation)
    {
        $this->asPermittedActor();

        $query = $filterClass::make(['with' => [$relation]])->buildQuery();

        $this->assertSame(
            [],
            $query->getEagerLoads(),
            $filterClass . ' declares an empty map on purpose — ' . $relation . ' must stay refused'
        );

        // Counts are registered as aggregate sub-selects rather than eager
        // loads, so compare against the same filter built with no `with` at
        // all. Some of these filters add their own server-side counts in
        // customQuery(); what matters is that the request added nothing.
        $this->assertSame(
            $filterClass::make([])->buildQuery()->toSql(),
            $query->toSql(),
            $filterClass . ' declares no counts either, so the request may not change the SQL at all'
        );
    }

    public function emptyMapFilterProvider(): array
    {
        return [
            // The first four are REAL relations on that filter's model, so the
            // refusal is the allow-list's doing and not a typo. Label declares
            // no relations at all, so `orders` there stands in for "any name".
            'shipping classes' => [ShippingClassFilter::class, 'zones'],
            'attribute groups' => [AttrGroupFilter::class, 'terms'],
            'attribute terms'  => [AttrTermFilter::class, 'group'],
            'variations'       => [VariationFilter::class, 'downloadable_files'],
            'labels'           => [LabelFilter::class, 'orders'],
        ];
    }

}

<?php

namespace Tests\Integration\Services\Filter;

use FluentCart\App\Modules\Shipping\Services\Filter\ShippingZoneFilter;
use FluentCart\App\Modules\Subscriptions\Services\Filter\SubscriptionFilter;
use FluentCart\App\Services\Filter\CouponFilter;
use FluentCart\App\Services\Filter\CustomerFilter;
use FluentCart\App\Services\Filter\LogFilter;
use FluentCart\App\Services\Filter\OrderFilter;
use FluentCart\App\Services\Filter\ProductFilter;
use FluentCart\App\Services\Filter\TaxFilter;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\UserRoleFactory;

/**
 * The allow-list is deny-by-default, so a request key the map does not carry is
 * dropped SILENTLY — no error, no warning, just a relation that stops loading.
 * In Vue that surfaces as a blank cell or an `undefined`, usually noticed days
 * later. It has already happened once on this branch:
 * `detail.variants.bundleChildren` was missing from ProductFilter's map, and
 * AddProductItemModal.vue called `.length` on the resulting undefined.
 *
 * This test is the net. It pins the EXACT `with` and `scopes` payload every
 * shipped client sends, so that dropping one turns a silent breakage into a red
 * test. Each case names the client file:line it mirrors — when a client changes
 * its payload, this file changes in the same commit or the net has a hole.
 *
 * It asserts on REGISTRATION (did the payload survive the allow-list), not on
 * hydrated rows, so it needs no per-model fixtures and stays robust to schema
 * churn. Relation loading itself is covered by FilterAllowlistMapsTest.
 */
class ClientPayloadContractTest extends DatabaseTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // Every permission-gated callback must pass, otherwise this test would
        // report a permission denial as a missing map entry.
        wp_set_current_user(UserRoleFactory::createAdmin());
    }

    /**
     * Every client payload that reaches a BaseFilter, and the relation each
     * SCREEN key must end up registering.
     *
     * One key per calling screen. A screen that used to send two or three keys
     * now sends one, and that one loads the whole subtree its screen renders —
     * so if the collapse dropped a segment, the expectations below still name it
     * and this goes red.
     *
     * [ label, filter class, request args, expected eager-loaded relations ]
     */
    public function clientWithPayloads(): array
    {
        return [
            // Nothing to send: `applied_coupons_count` appears nowhere in
            // resources/; the list renders the real `use_count` column instead.
            // The count survives as a PUBLIC key (see publicWithPayloads) — it
            // was an accepted request value and stays one.
            'CouponTable.js:99 — coupon list sends nothing' => [
                CouponFilter::class,
                ['with' => []],
                [],
                [],
            ],
            'OrderTable.js:99 — order list' => [
                OrderFilter::class,
                ['with' => ['admin_order_list']],
                ['customer', 'customer.primary_billing_address', 'order_items'],
                [],
            ],
            'SingleCustomer.vue:315 — customer detail order list' => [
                OrderFilter::class,
                ['with' => ['admin_customer_orders']],
                ['order_items'],
                [],
            ],
            'ProductTable.js:98 — product list' => [
                ProductFilter::class,
                ['with' => ['admin_product_list']],
                ['detail', 'variants'],
                [],
            ],
            'AddProductItemModal.vue:447 — add product to order' => [
                ProductFilter::class,
                ['with' => ['admin_order_add_product']],
                ['detail', 'detail.variants', 'detail.variants.media', 'detail.variants.bundleChildren'],
                [],
            ],
            'elementor SelectorModel.js:55 — variation picker' => [
                ProductFilter::class,
                ['with' => ['elementor_picker']],
                ['detail', 'variants', 'variants.media'],
                [],
            ],
            'elementor PricingTable/App.vue:215 — pricing table' => [
                ProductFilter::class,
                ['with' => ['elementor_picker']],
                ['detail', 'variants', 'variants.media'],
                [],
            ],
            'ProductDropdownPicker.jsx:25 — block product picker' => [
                ProductFilter::class,
                ['with' => ['block_picker']],
                ['detail', 'variants', 'variants.media'],
                [],
            ],
            'ProductSelector.jsx:59 — block product selector' => [
                ProductFilter::class,
                ['with' => ['block_picker']],
                ['detail', 'variants', 'variants.media'],
                [],
            ],
            'VariationSelector.jsx:128 — block variation selector' => [
                ProductFilter::class,
                ['with' => ['block_picker']],
                ['detail', 'variants', 'variants.media'],
                [],
            ],
            'SearchableSelect.jsx:65 — block searchable select' => [
                ProductFilter::class,
                ['with' => ['block_picker']],
                ['detail', 'variants', 'variants.media'],
                [],
            ],
            'TaxesTable.js:77 — tax list' => [
                TaxFilter::class,
                ['with' => ['admin_tax_report']],
                ['tax_rate', 'order'],
                [],
            ],
            'SubscriptionTable.js:94 — subscription list' => [
                SubscriptionFilter::class,
                ['with' => ['admin_subscription_list']],
                ['customer'],
                [],
            ],
            'ShippingZoneTable.js:88 — shipping zone list' => [
                ShippingZoneFilter::class,
                ['with' => ['admin_shipping_zone_list']],
                [],
                ['methods_count'],
            ],
            'OrderCustomerInformation.vue:90 — customer search' => [
                CustomerFilter::class,
                ['with' => ['admin_customer_search']],
                ['shipping_address', 'billing_address'],
                [],
            ],
        ];
    }

    /**
     * @dataProvider clientWithPayloads
     */
    public function test_shipped_client_with_payloads_still_resolve(
        string $filterClass,
        array $args,
        array $expectedRelations,
        array $expectedCountAliases
    ) {
        $query = $filterClass::make($args)->buildQuery();
        $eagerLoads = array_keys($query->getEagerLoads());

        foreach ($expectedRelations as $relation) {
            $this->assertContains(
                $relation,
                $eagerLoads,
                "A shipped client asks for `{$relation}` and the allow-list dropped it. "
                . 'That is a silent blank in the UI, not an error — add the entry back or '
                . 'update the client in the same commit.'
            );
        }

        $sql = $query->toSql();

        foreach ($expectedCountAliases as $alias) {
            $this->assertStringContainsString(
                $alias,
                $sql,
                "A shipped client asks for the `{$alias}` aggregate and it is not in the query."
            );
        }
    }

    /**
     * The PUBLIC tier. These keys have no client in this repo, and that is
     * exactly why they are pinned: they are plain relation names that were
     * accepted request values before the allow-list existed, so an external
     * consumer may already be sending them. Renaming one to a screen key would
     * hand that consumer a silently narrower response with no error to notice —
     * which is the regression this whole tier exists to prevent.
     *
     * The permission gate each one carries is asserted in FilterAllowlistMapsTest;
     * here the actor holds everything, so this proves only that the key RESOLVES.
     *
     * [ label, filter class, request args, expected relations, expected aggregates ]
     */
    public function publicWithPayloads(): array
    {
        return [
            'orders — with[]=order_items' => [
                OrderFilter::class,
                ['with' => ['order_items']],
                ['order_items'],
                [],
            ],
            'orders — with[]=customer' => [
                OrderFilter::class,
                ['with' => ['customer']],
                ['customer', 'customer.primary_billing_address'],
                [],
            ],
            'orders — with[]=customer.primary_billing_address' => [
                OrderFilter::class,
                ['with' => ['customer.primary_billing_address']],
                ['customer', 'customer.primary_billing_address'],
                [],
            ],
            'customers — with[]=shipping_address' => [
                CustomerFilter::class,
                ['with' => ['shipping_address']],
                ['shipping_address'],
                [],
            ],
            'customers — with[]=billing_address' => [
                CustomerFilter::class,
                ['with' => ['billing_address']],
                ['billing_address'],
                [],
            ],
            'customers — with[]=primary_shipping_address' => [
                CustomerFilter::class,
                ['with' => ['primary_shipping_address']],
                ['primary_shipping_address'],
                [],
            ],
            'customers — with[]=primary_billing_address' => [
                CustomerFilter::class,
                ['with' => ['primary_billing_address']],
                ['primary_billing_address'],
                [],
            ],
            'customers — with[]=labels' => [
                CustomerFilter::class,
                ['with' => ['labels']],
                ['labels'],
                [],
            ],
            'taxes — with[]=tax_rate' => [
                TaxFilter::class,
                ['with' => ['tax_rate']],
                ['tax_rate'],
                [],
            ],
            'taxes — with[]=order' => [
                TaxFilter::class,
                ['with' => ['order']],
                ['order'],
                [],
            ],
            'subscriptions — with[]=customer' => [
                SubscriptionFilter::class,
                ['with' => ['customer']],
                ['customer'],
                [],
            ],
            'shipping zones — with[]=methodsCount' => [
                ShippingZoneFilter::class,
                ['with' => ['methodsCount']],
                [],
                ['methods_count'],
            ],
            'activity — with[]=user' => [
                LogFilter::class,
                ['with' => ['user']],
                ['user'],
                [],
            ],
            'coupons — with[]=appliedCouponsCount' => [
                CouponFilter::class,
                ['with' => ['appliedCouponsCount']],
                [],
                ['applied_coupons_count'],
            ],
            'products — with[]=detail' => [
                ProductFilter::class,
                ['with' => ['detail']],
                ['detail'],
                [],
            ],
            'products — with[]=variants' => [
                ProductFilter::class,
                ['with' => ['variants']],
                ['variants'],
                [],
            ],
        ];
    }

    /**
     * @dataProvider publicWithPayloads
     */
    public function test_public_with_keys_resolve(
        string $filterClass,
        array $args,
        array $expectedRelations,
        array $expectedCountAliases
    ) {
        $query = $filterClass::make($args)->buildQuery();
        $eagerLoads = array_keys($query->getEagerLoads());

        foreach ($expectedRelations as $relation) {
            $this->assertContains(
                $relation,
                $eagerLoads,
                "`{$relation}` is a published request key on this endpoint. Dropping or renaming it "
                . 'narrows the response for every external consumer already sending it, with no error '
                . 'anywhere to notice.'
            );
        }

        $sql = $query->toSql();

        foreach ($expectedCountAliases as $alias) {
            $this->assertStringContainsString(
                $alias,
                $sql,
                "`{$alias}` is a published aggregate on this endpoint and it is not in the query."
            );
        }
    }

    /**
     * Scopes are silently dropped the same way, and a dropped scope is worse
     * than a dropped relation: the screen renders fine, with the wrong rows.
     *
     * [ label, filter class, request args, SQL fragment the scope must add ]
     */
    public function clientScopePayloads(): array
    {
        return [
            'TaxesTable.js:83 — tax list, paid orders only' => [
                TaxFilter::class,
                ['scopes' => ['validOrder']],
            ],
            'Orders/Coupon.vue:163 — active coupon suggestions' => [
                CouponFilter::class,
                ['scopes' => ['active']],
            ],
            'VariationSelector.jsx:139 — cartable products only' => [
                ProductFilter::class,
                ['scopes' => ['cartable']],
            ],
        ];
    }

    /**
     * @dataProvider clientScopePayloads
     */
    public function test_shipped_client_scope_payloads_still_apply(string $filterClass, array $args)
    {
        $withScope = $filterClass::make($args)->buildQuery()->toSql();
        $withoutScope = $filterClass::make([])->buildQuery()->toSql();

        $this->assertNotSame(
            $withoutScope,
            $withScope,
            'The scope a shipped client sends was dropped by the allow-list. The screen '
            . 'still renders — with the wrong rows, which is worse than a blank.'
        );
    }
}

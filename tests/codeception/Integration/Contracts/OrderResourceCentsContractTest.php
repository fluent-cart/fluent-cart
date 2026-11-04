<?php

namespace Tests\Integration\Contracts;

use FluentCart\Api\Resource\OrderResource;
use FluentCart\App\Helpers\Helper;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\ProductFactory;

/**
 * Cross-layer cents invariant for the order Resource API.
 *
 * OrderResource is a PUBLIC Resource API: controllers delegate to it, but a
 * direct adapter, CLI command, or integration calls it without ever touching
 * OrderRequest. So the cents normalization at
 * api/Resource/OrderResource.php:262 is a second boundary, not a duplicate of
 * the request sanitizer — and it cannot be observed from the REST suite, where
 * OrderRequest::sanitize() has already normalized the value before the resource
 * runs.
 *
 * These tests therefore call updatedPlaceOrder() DIRECTLY with a raw payload.
 * Delete the Helper::roundCent() call in the resource and these fail while every
 * Functional shipping test still passes.
 */
class OrderResourceCentsContractTest extends WPTestCase
{
    /**
     * Spec: Cases/Integration/orders.md [261]
     *   "Calling OrderResource::updatedPlaceOrder() directly without a shipping_total
     *    stores zero shipping, not NULL — the resource is a public API and callers that
     *    bypass OrderRequest still get a valid cents column."
     */
    public function test_direct_place_order_without_shipping_total_stores_zero_not_null(): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'contract-no-shipping@example.test']);
        $product  = (new ProductFactory())
            ->withVariantAttributes(['item_price' => Helper::toCent(10.00)])
            ->create();
        $variant = $product->variants->first();

        // shipping_total absent entirely, and no OrderRequest in the call path, so
        // nothing upstream can normalize it. fct_orders.shipping_total is NOT NULL.
        $order = OrderResource::updatedPlaceOrder([
            'customer_id' => $customer->id,
            'type'        => 'payment',
            'order_items' => [
                [
                    'post_id'          => (int) $product->ID,
                    'object_id'        => (int) $variant->id,
                    'quantity'         => 1,
                    'unit_price'       => Helper::toCent(10.00),
                    'fulfillment_type' => 'digital',
                    'payment_type'     => 'onetime',
                    'other_info'       => ['payment_type' => 'onetime'],
                ],
            ],
        ]);

        $this->assertNotEmpty($order->id ?? null, 'a direct updatedPlaceOrder() call with no shipping_total must create an order, not error: ' . wp_json_encode($order));

        global $wpdb;
        $stored = $wpdb->get_var($wpdb->prepare(
            "SELECT shipping_total FROM {$wpdb->prefix}fct_orders WHERE id = %d",
            (int) $order->id
        ));

        $this->assertNotNull($stored, 'a direct caller omitting shipping_total must not persist NULL into the NOT NULL cents column');
        $this->assertSame(0, (int) $stored, 'an omitted shipping_total must normalize to 0 cents for direct Resource API callers, exactly as it does through REST');
    }

    /**
     * Spec: Cases/Integration/orders.md [262]
     *   "Calling OrderResource::updatedPlaceOrder() directly with a fractional
     *    shipping_total rounds it to the NEAREST cent — neither truncating down nor
     *    ceiling up — matching what the request sanitizer does for REST callers."
     */
    public function test_direct_place_order_rounds_fractional_shipping_total_to_nearest(): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'contract-fractional@example.test']);
        $product  = (new ProductFactory())
            ->withVariantAttributes(['item_price' => Helper::toCent(10.00)])
            ->create();
        $variant = $product->variants->first();

        // The matrix straddles an integer on purpose. A single below-integer artifact
        // cannot tell rounding apart from an unconditional ceiling — both yield 1999
        // for 1998.9999999999998 — so each of truncate, ceil and round must be
        // distinguishable by at least one row here.
        //
        //   input                  round  trunc  ceil   kills
        //   1998.9999999999998     1999   1998   1999   truncation
        //   7.000000000000001      7      7      8      ceiling
        //   1999.4                 1999   1999   2000   ceiling (below half)
        //   1999.5                 2000   1999   2000   truncation (half up)
        //
        // Both artifacts are ordinary JavaScript output: 19.99 * 100 and 0.07 * 100.
        $cases = [
            [1998.9999999999998, 1999, 'a below-integer artifact (19.99 * 100 in JS) must round UP to 1999 — truncating undercharges by a cent'],
            [7.000000000000001, 7, 'an above-integer artifact (0.07 * 100 in JS) must round DOWN to 7 — a ceiling would overcharge a 7-cent shipping fee to 8'],
            [1999.4, 1999, 'below half must round down to 1999, which a ceiling would push to 2000'],
            [1999.5, 2000, 'half must round up to 2000, which truncation would leave at 1999'],
            [2000.5, 2001, "an even-base half must round UP to 2001 — banker's rounding would keep 2000 and lose a cent"],
            // EXPLICIT zero is legitimate free shipping under OrderRequest's min:0
            // contract and must SUCCEED: it pins the sign guard at strictly-less-than —
            // a <= 0 off-by-one would reject it here while the omitted-key tests stay
            // green (null early-returns before the comparison).
            [0, 0, 'an explicit zero shipping_total is valid free shipping and must persist as 0, not be rejected by the sign guard'],
        ];

        global $wpdb;

        foreach ($cases as $case) {
            list($supplied, $expected, $why) = $case;

            $order = OrderResource::updatedPlaceOrder([
                'customer_id'    => $customer->id,
                'type'           => 'payment',
                'shipping_total' => $supplied,
                'order_items'    => [
                    [
                        'post_id'          => (int) $product->ID,
                        'object_id'        => (int) $variant->id,
                        'quantity'         => 1,
                        'unit_price'       => Helper::toCent(10.00),
                        'fulfillment_type' => 'digital',
                        'payment_type'     => 'onetime',
                        'other_info'       => ['payment_type' => 'onetime'],
                    ],
                ],
            ]);

            $this->assertNotEmpty(
                $order->id ?? null,
                'a direct updatedPlaceOrder() call supplying shipping_total ' . var_export($supplied, true)
                    . ' must create an order: ' . wp_json_encode($order)
            );

            // The DB row alone cannot prove the resource normalized anything: MySQL
            // itself rounds a raw float into a BIGINT column, so a mutant that
            // passes the fractional value straight through still lands the same
            // stored integer. The RETURNED model's raw attribute is the
            // discriminator — with roundCent it is the exact int; with a
            // passthrough it is still the float artifact that downstream int
            // casts and event listeners would then truncate.
            $this->assertSame(
                $expected,
                $order->getAttributes()['shipping_total'],
                $why . ' — the returned order must carry the normalized INT in memory, not the raw float (supplied '
                    . var_export($supplied, true) . ', got '
                    . var_export($order->getAttributes()['shipping_total'], true) . ')'
            );

            // Each order is read back by its own causal id, never by "the last row".
            $stored = $wpdb->get_var($wpdb->prepare(
                "SELECT shipping_total FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                (int) $order->id
            ));

            $this->assertSame(
                $expected,
                (int) $stored,
                $why . ' (supplied ' . var_export($supplied, true) . ', order ' . (int) $order->id
                    . '). No REST test can observe this: OrderRequest::sanitize() normalizes before the resource is reached.'
            );
        }
    }

    /**
     * Spec: Cases/Integration/orders.md [264]
     *   "Both direct cents boundaries REJECT magnitudes beyond float's
     *    exact-integer range by throwing InvalidArgumentException instead of
     *    letting the int cast wrap 1e19 into a negative-but-valid BIGINT."
     */
    public function test_direct_place_order_rejects_out_of_range_shipping_total(): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'contract-out-of-range@example.test']);
        $product  = (new ProductFactory())
            ->withVariantAttributes(['item_price' => Helper::toCent(10.00)])
            ->create();
        $variant = $product->variants->first();

        global $wpdb;
        $ordersBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders");

        // -500 is in range for roundCent but has no meaning for a charge column:
        // REST negatives die at OrderRequest's min:0 rule, so the resource boundary
        // must reject them itself for direct callers. -0.4 and -1e-9 pin the ORDER
        // of the guards: the sign must be checked on the RAW value, because rounding
        // first would turn a fractional negative into 0 and wave it through as free
        // shipping.
        foreach ([1e19, 1e100, -1e19, -500, -0.4, -1e-9] as $supplied) {
            try {
                OrderResource::updatedPlaceOrder([
                    'customer_id'    => $customer->id,
                    'type'           => 'payment',
                    'shipping_total' => $supplied,
                    'order_items'    => [
                        [
                            'post_id'          => (int) $product->ID,
                            'object_id'        => (int) $variant->id,
                            'quantity'         => 1,
                            'unit_price'       => Helper::toCent(10.00),
                            'fulfillment_type' => 'digital',
                            'payment_type'     => 'onetime',
                            'other_info'       => ['payment_type' => 'onetime'],
                        ],
                    ],
                ]);
                $this->fail(
                    'an out-of-range shipping_total must throw at the resource boundary, not persist a wrapped value (supplied '
                        . var_export($supplied, true) . ')'
                );
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString(
                    'cents',
                    $e->getMessage(),
                    'the resource rejection must identify itself as a cents-range violation (supplied '
                        . var_export($supplied, true) . ')'
                );
            }
        }

        $ordersAfter = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders");
        $this->assertSame(
            $ordersBefore,
            $ordersAfter,
            'a rejected out-of-range shipping_total must not leave a partial order row behind'
        );
    }

    /**
     * Spec: Cases/Integration/orders.md [264]
     *   "A PRESENT non-numeric shipping_total is rejected at the resource
     *    boundary — only an absent or null value defaults to 0."
     *
     * roundCent() itself maps every non-numeric to 0, which is exactly right for
     * the null that pickKeys() injects for an omitted key — but for a direct
     * Resource API caller a PRESENT malformed value is a caller bug, and mapping
     * it to 0 silently grants free shipping and understates the order total.
     */
    public function test_direct_place_order_rejects_present_non_numeric_shipping_total(): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'contract-malformed@example.test']);
        $product  = (new ProductFactory())
            ->withVariantAttributes(['item_price' => Helper::toCent(10.00)])
            ->create();
        $variant = $product->variants->first();

        global $wpdb;
        $ordersBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders");

        foreach ([false, [], '', 'invalid'] as $supplied) {
            try {
                OrderResource::updatedPlaceOrder([
                    'customer_id'    => $customer->id,
                    'type'           => 'payment',
                    'shipping_total' => $supplied,
                    'order_items'    => [
                        [
                            'post_id'          => (int) $product->ID,
                            'object_id'        => (int) $variant->id,
                            'quantity'         => 1,
                            'unit_price'       => Helper::toCent(10.00),
                            'fulfillment_type' => 'digital',
                            'payment_type'     => 'onetime',
                            'other_info'       => ['payment_type' => 'onetime'],
                        ],
                    ],
                ]);
                $this->fail(
                    'a present non-numeric shipping_total must throw at the resource boundary, not become free shipping (supplied '
                        . var_export($supplied, true) . ')'
                );
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString(
                    'cents',
                    $e->getMessage(),
                    'the malformed-shipping rejection must identify itself as a cents violation (supplied '
                        . var_export($supplied, true) . ')'
                );
            }
        }

        $ordersAfter = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders");
        $this->assertSame(
            $ordersBefore,
            $ordersAfter,
            'a rejected malformed shipping_total must not leave an order row behind'
        );
    }
}

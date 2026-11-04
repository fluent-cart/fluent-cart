<?php

namespace Tests\Integration\Listeners;

use FluentCart\App\Models\Cart;
use FluentCart\App\Modules\Shipping\ShippingModule;
use FluentCart\App\Modules\Tax\TaxModule;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CartFactory;

/**
 * The `fluent_cart/cart/cart_data_items_updated` listener contract.
 *
 * A listener recalculates; it does not decide that a cart belongs in the
 * database. Every core dispatch site saves the cart immediately before firing
 * the action, so the guards these tests pin are a no-op on the existing paths —
 * which is exactly what cases 2 and 4 assert. What they buy is that a caller
 * holding an in-memory cart (rendering totals for a page that may never be
 * bought from) does not get an `fct_carts` row written behind its back.
 */
class CartItemsUpdatedPersistenceTest extends DatabaseTestCase
{
    /**
     * Spec: Cases/cart.md
     *   "Recalculating tax on an unsaved cart does not write a cart row."
     */
    public function test_tax_listener_does_not_persist_an_unsaved_cart()
    {
        $before = Cart::query()->count();

        $cart = new Cart([
            'cart_hash'     => md5('unsaved_tax_' . microtime(true)),
            'stage'         => 'draft',
            'cart_group'    => 'global',
            'cart_data'     => [CartFactory::makeCartItem()],
            'checkout_data' => [],
            'coupons'       => [],
        ]);

        (new TaxModule())->recalculateTax(['cart' => $cart]);

        $this->assertFalse(
            $cart->exists,
            'Recalculating tax must leave an in-memory cart unsaved.'
        );
        $this->assertSame(
            $before,
            Cart::query()->count(),
            'Recalculating tax on an unsaved cart must not write an fct_carts row.'
        );
    }

    /**
     * Spec: Cases/cart.md
     *   "Recalculating tax on a saved cart still persists the recalculated totals."
     */
    public function test_tax_listener_still_persists_a_saved_cart()
    {
        $cart = (new CartFactory())->create([
            'cart_data' => [CartFactory::makeCartItem()],
        ]);

        $cart->checkout_data = array_merge(
            is_array($cart->checkout_data) ? $cart->checkout_data : [],
            ['__persistence_probe' => 'yes']
        );

        (new TaxModule())->recalculateTax(['cart' => $cart]);

        $stored = Cart::query()->where('cart_hash', $cart->cart_hash)->first();

        $this->assertNotNull($stored, 'The saved cart must still exist after recalculation.');
        $this->assertSame(
            'yes',
            $stored->checkout_data['__persistence_probe'] ?? null,
            'Recalculating tax on a saved cart must still write the pending changes through.'
        );
    }

    /**
     * Spec: Cases/cart.md
     *   "Recalculating shipping on an unsaved cart does not write a cart row."
     */
    public function test_shipping_listener_does_not_persist_an_unsaved_cart()
    {
        $before = Cart::query()->count();

        $cart = new Cart([
            'cart_hash'     => md5('unsaved_shipping_' . microtime(true)),
            'stage'         => 'draft',
            'cart_group'    => 'global',
            'cart_data'     => [CartFactory::makeCartItem(['fulfillment_type' => 'physical'])],
            'checkout_data' => [],
            'coupons'       => [],
        ]);

        $this->assertTrue(
            $cart->requireShipping(),
            'The fixture must reach the shipping branch, otherwise the guard is never exercised.'
        );

        (new ShippingModule())->handleItemsChanges(['cart' => $cart, 'scope' => 'items_updated']);

        $this->assertFalse(
            $cart->exists,
            'Recalculating shipping must leave an in-memory cart unsaved.'
        );
        $this->assertSame(
            $before,
            Cart::query()->count(),
            'Recalculating shipping on an unsaved cart must not write an fct_carts row.'
        );
    }

    /**
     * Spec: Cases/cart.md
     *   "Recalculating shipping on a saved cart still persists the recalculated totals."
     */
    public function test_shipping_listener_still_persists_a_saved_cart()
    {
        $cart = (new CartFactory())->create([
            'cart_data' => [CartFactory::makeCartItem(['fulfillment_type' => 'physical'])],
        ]);

        $cart->checkout_data = array_merge(
            is_array($cart->checkout_data) ? $cart->checkout_data : [],
            ['__persistence_probe' => 'yes']
        );

        (new ShippingModule())->handleItemsChanges(['cart' => $cart, 'scope' => 'items_updated']);

        $stored = Cart::query()->where('cart_hash', $cart->cart_hash)->first();

        $this->assertNotNull($stored, 'The saved cart must still exist after recalculation.');
        $this->assertSame(
            'yes',
            $stored->checkout_data['__persistence_probe'] ?? null,
            'Recalculating shipping on a saved cart must still write the pending changes through.'
        );
    }
}

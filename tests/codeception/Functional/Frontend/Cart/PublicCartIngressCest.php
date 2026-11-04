<?php

namespace Tests\Functional\Frontend\Cart;

use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\Cart;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/cart.md → "Public cart ingress" section, items 1-8.
 */
class PublicCartIngressCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    public function _after(FunctionalTester $I): void
    {
        $I->disarmStockListeners();
    }

    /**
     * Spec: Cases/Functional/cart.md [1]
     *   "A visitor can add a published product variation to the cart through the public cart ingress."
     */
    public function visitorCanAddPublishedVariation(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create();
        $variation = $product->variants->first();
        $cart = (new CartFactory())->create();

        [$status, $body] = $I->checkoutAjax([
            'fc_checkout_action' => 'fluent_cart_cart_update',
            'fct_cart_hash'      => $cart->cart_hash,
            'item_id'            => $variation->id,
            'quantity'           => 2,
        ]);

        Assert::assertSame(200, $status, 'cart add ingress must return HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertSame('Cart updated successfully', (string) ($body['message'] ?? ''), 'cart add response must confirm the mutation');
        Assert::assertSame(2, (int) ($body['data']['cart']['cart_data'][0]['quantity'] ?? 0), 'cart add response must expose the persisted quantity');

        $stored = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        Assert::assertCount(1, $stored->cart_data, 'cart add must persist exactly one cart line');
        Assert::assertSame((int) $variation->id, (int) $stored->cart_data[0]['object_id'], 'persisted cart line must reference the requested variation');
        Assert::assertSame(2, (int) $stored->cart_data[0]['quantity'], 'persisted cart line must retain the requested quantity');
    }

    /**
     * Spec: Cases/Functional/cart.md [2]
     *   "Adding a variation preserves the server-owned unit price in cents instead of trusting a caller-supplied price."
     */
    public function addPreservesServerOwnedCentPrice(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->withVariantAttributes(['item_price' => 4321])->create();
        $variation = $product->variants->first();
        $cart = (new CartFactory())->create();

        [$status, $body] = $I->checkoutAjax([
            'fc_checkout_action' => 'fluent_cart_cart_update',
            'fct_cart_hash'      => $cart->cart_hash,
            'item_id'            => $variation->id,
            'quantity'           => 2,
            'unit_price'         => 1,
            'price'              => 1,
        ]);

        Assert::assertSame(200, $status, 'priced cart add must return HTTP 200. Body: ' . wp_json_encode($body));
        $stored = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        Assert::assertSame(4321, (int) $stored->cart_data[0]['unit_price'], 'cart must use the variation price in cents, not caller input');
        Assert::assertSame(8642, (int) $stored->cart_data[0]['subtotal'], 'cart subtotal must be server price times quantity in cents');
    }

    /**
     * Spec: Cases/Functional/cart.md [3]
     *   "A visitor can replace an existing cart item's quantity through the public cart ingress."
     */
    public function visitorCanReplaceExistingQuantity(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create();
        $variation = $product->variants->first();
        $cart = (new CartFactory())->withCartItems([
            CartHelper::generateCartItemFromVariation($variation, 1),
        ])->create();

        [$status, $body] = $I->checkoutAjax([
            'fc_checkout_action' => 'fluent_cart_cart_update',
            'fct_cart_hash'      => $cart->cart_hash,
            'item_id'            => $variation->id,
            'quantity'           => 4,
            'by_input'           => true,
        ]);

        Assert::assertSame(200, $status, 'cart quantity replacement must return HTTP 200. Body: ' . wp_json_encode($body));
        $stored = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        Assert::assertCount(1, $stored->cart_data, 'quantity replacement must not duplicate the cart line');
        Assert::assertSame(4, (int) $stored->cart_data[0]['quantity'], 'by-input cart update must replace quantity rather than increment it');
    }

    /**
     * Spec: Cases/Functional/cart.md [4]
     *   "Updating an item to zero removes it from the cart."
     */
    public function zeroQuantityRemovesItem(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create();
        $variation = $product->variants->first();
        $cart = (new CartFactory())->withCartItems([
            CartHelper::generateCartItemFromVariation($variation, 1),
        ])->create();

        [$status, $body] = $I->checkoutAjax([
            'fc_checkout_action' => 'fluent_cart_cart_update',
            'fct_cart_hash'      => $cart->cart_hash,
            'item_id'            => $variation->id,
            'quantity'           => 0,
        ]);

        Assert::assertSame(200, $status, 'zero-quantity cart update must return HTTP 200. Body: ' . wp_json_encode($body));
        $stored = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        Assert::assertSame([], $stored->cart_data, 'zero-quantity update must persist an empty cart');
    }

    /**
     * Spec: Cases/Functional/cart.md [5]
     *   "Adding an unknown variation is rejected without changing the cart."
     */
    public function unknownVariationIsRejected(FunctionalTester $I): void
    {
        $cart = (new CartFactory())->create();

        [$status, $body] = $I->checkoutAjax([
            'fc_checkout_action' => 'fluent_cart_cart_update',
            'fct_cart_hash'      => $cart->cart_hash,
            'item_id'            => 999999,
            'quantity'           => 1,
        ]);

        Assert::assertSame(422, $status, 'unknown variation must be rejected with HTTP 422. Body: ' . wp_json_encode($body));
        $stored = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        Assert::assertSame([], $stored->cart_data, 'unknown variation rejection must leave cart data unchanged');
    }

    /**
     * Spec: Cases/Functional/cart.md [6]
     *   "Adding more units than a stock-managed variation has available is rejected without changing the cart."
     */
    public function quantityBeyondAvailableStockIsRejected(FunctionalTester $I): void
    {
        $I->armStockListeners();
        $product = (new ProductFactory())->withDetailAttributes([
            'manage_stock' => 1,
        ])->withVariantAttributes([
            'manage_stock' => 1,
            'total_stock'  => 2,
            'available'    => 2,
        ])->create();
        $variation = $product->variants->first();
        $cart = (new CartFactory())->create();

        [$status, $body] = $I->checkoutAjax([
            'fc_checkout_action' => 'fluent_cart_cart_update',
            'fct_cart_hash'      => $cart->cart_hash,
            'item_id'            => $variation->id,
            'quantity'           => 3,
        ]);

        Assert::assertSame(422, $status, 'over-stock cart add must return HTTP 422. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString('out of stock', (string) ($body['message'] ?? ''), 'over-stock response must explain the stock limit');
        $stored = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        Assert::assertSame([], $stored->cart_data, 'over-stock rejection must leave cart data unchanged');
    }

    /**
     * Spec: Cases/Functional/cart.md [7]
     *   "The public cart status ingress returns the current device cart without mutating it."
     */
    public function statusReturnsCurrentDeviceCart(FunctionalTester $I): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 1789, 'price' => 1789]);
        $cart = (new CartFactory())->withCartItems([$item])->create();

        [$status, $body] = $I->checkoutAjax([
            'fc_checkout_action' => 'fluent_cart_cart_status',
            'fct_cart_hash'      => $cart->cart_hash,
        ]);

        Assert::assertSame(200, $status, 'cart status ingress must return HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertSame($cart->cart_hash, (string) ($body['cart_hash'] ?? ''), 'cart status must identify the current device cart');
        Assert::assertSame(1789, (int) ($body['cart_data'][0]['unit_price'] ?? 0), 'cart status must expose stored unit price in cents');
        Assert::assertSame(1, Cart::query()->where('cart_hash', $cart->cart_hash)->count(), 'cart status must not create or delete the current cart');
    }

    /**
     * Spec: Cases/Functional/cart.md [8]
     *   "Cart ingress requests without a valid security token are rejected without mutating a cart."
     */
    public function missingSecurityTokenIsRejected(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create();
        $variation = $product->variants->first();
        $cart = (new CartFactory())->create();

        [$status, $body] = $I->checkoutAjax([
            'fc_checkout_action' => 'fluent_cart_cart_update',
            'fct_cart_hash'      => $cart->cart_hash,
            'item_id'            => $variation->id,
            'quantity'           => 1,
        ], false);

        Assert::assertSame(403, $status, 'missing cart nonce must return HTTP 403. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString('Invalid nonce', (string) ($body['message'] ?? ''), 'missing cart nonce response must name the security failure');
        $stored = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        Assert::assertSame([], $stored->cart_data, 'nonce rejection must leave cart data unchanged');
    }
}

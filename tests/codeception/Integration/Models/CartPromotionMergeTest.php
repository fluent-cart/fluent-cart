<?php

namespace Tests\Integration\Models;

use FluentCart\App\Models\Cart;
use FluentCart\App\Models\ProductVariation;
use FluentCart\Framework\Support\Arr;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\ProductFactory;

/**
 * A normal add merging into an order-bump line must not inherit the bump price or perks.
 */
class CartPromotionMergeTest extends DatabaseTestCase
{
    private const PRICE = 5000;
    private const BUMP_PRICE = 1000;

    private function variation(): ProductVariation
    {
        $product = (new ProductFactory())->withVariantAttributes(['item_price' => self::PRICE])->create();

        return ProductVariation::query()->find($product->variants->first()->id);
    }

    private function bumpOtherInfo(): array
    {
        return [
            'promotion_id'         => 77,
            'promotion_type'       => 'order_bump',
            'promo_price_original' => self::PRICE,
            'is_locked'            => 'yes',
            'free_shipping'        => 'yes',
        ];
    }

    // Same as OrderBumpBoot::applyOrderBump(): lowered price + promotion other_info.
    private function cartWithBumpLine(ProductVariation $variation): Cart
    {
        $cart = (new CartFactory())->create();
        $bumpVariation = ProductVariation::query()->find($variation->id);
        $bumpVariation->item_price = self::BUMP_PRICE;
        $cart->addByVariation($bumpVariation, ['other_info' => $this->bumpOtherInfo(), 'quantity' => 1]);

        return $cart;
    }

    private function line(Cart $cart): array
    {
        $reloaded = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        $this->assertCount(1, $reloaded->cart_data);

        return $reloaded->cart_data[0];
    }

    public function test_normal_add_turns_bump_line_into_normal_line_at_real_price(): void
    {
        $variation = $this->variation();
        $cart = $this->cartWithBumpLine($variation);

        $result = $cart->addByVariation(ProductVariation::query()->find($variation->id), [
            'quantity'      => 2,
            'will_validate' => true,
        ]);

        $this->assertInstanceOf(Cart::class, $result);
        $line = $this->line($cart);
        $this->assertSame(3, (int) $line['quantity']);
        $this->assertSame(self::PRICE, (int) $line['unit_price']);
        $this->assertSame(self::PRICE * 3, (int) $line['subtotal']);
        foreach (array_keys($this->bumpOtherInfo()) as $key) {
            $this->assertArrayNotHasKey($key, (array) Arr::get($line, 'other_info', []), $key);
        }
    }

    public function test_bump_reapply_keeps_bump_price_and_promotion(): void
    {
        $variation = $this->variation();
        $cart = $this->cartWithBumpLine($variation);

        $cart->addByVariation(ProductVariation::query()->find($variation->id), [
            'other_info' => $this->bumpOtherInfo(),
            'quantity'   => 1,
        ]);

        $line = $this->line($cart);
        $this->assertSame(2, (int) $line['quantity']);
        $this->assertSame(self::BUMP_PRICE, (int) $line['unit_price']);
        $this->assertSame(77, (int) Arr::get($line, 'other_info.promotion_id'));
        $this->assertSame('yes', Arr::get($line, 'other_info.free_shipping'));
    }

    public function test_normal_add_onto_normal_line_keeps_real_price(): void
    {
        $variation = $this->variation();
        $cart = (new CartFactory())->create();

        $cart->addByVariation($variation, ['quantity' => 1]);
        $cart->addByVariation(ProductVariation::query()->find($variation->id), ['quantity' => 2]);

        $line = $this->line($cart);
        $this->assertSame(3, (int) $line['quantity']);
        $this->assertSame(self::PRICE, (int) $line['unit_price']);
    }

    public function test_normal_add_onto_price_locked_line_keeps_locked_price(): void
    {
        $variation = $this->variation();
        $cart = (new CartFactory())->create();
        $locked = ProductVariation::query()->find($variation->id);
        $locked->item_price = 2500;
        $cart->addByVariation($locked, ['other_info' => ['is_price_locked' => 'yes'], 'quantity' => 1]);

        $cart->addByVariation(ProductVariation::query()->find($variation->id), ['quantity' => 1]);

        $line = $this->line($cart);
        $this->assertSame(2, (int) $line['quantity']);
        $this->assertSame(2500, (int) $line['unit_price']);
        $this->assertSame('yes', Arr::get($line, 'other_info.is_price_locked'));
    }
}

<?php

namespace Tests\Support\Factory;

use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Helpers\Helper;

class ProductFactory
{
    private array $attributes = [];
    private array $detailAttributes = [];
    private array $variantAttributes = [];
    private bool $withVariant = true;

    private static int $counter = 0;

    public function withAttributes(array $attributes): self
    {
        $clone = clone $this;
        $clone->attributes = array_merge($clone->attributes, $attributes);
        return $clone;
    }

    public function withDetailAttributes(array $attributes): self
    {
        $clone = clone $this;
        $clone->detailAttributes = array_merge($clone->detailAttributes, $attributes);
        return $clone;
    }

    public function withVariantAttributes(array $attributes): self
    {
        $clone = clone $this;
        $clone->variantAttributes = array_merge($clone->variantAttributes, $attributes);
        return $clone;
    }

    public function withoutVariant(): self
    {
        $clone = clone $this;
        $clone->withVariant = false;
        return $clone;
    }

    protected function defaults(): array
    {
        self::$counter++;
        return [
            'post_title'  => 'Test Product ' . self::$counter,
            'post_status' => 'publish',
            'post_type'   => FluentProducts::CPT_NAME,
        ];
    }

    protected function detailDefaults(int $postId): array
    {
        return [
            'post_id'            => $postId,
            'fulfillment_type'   => 'digital',
            'variation_type'     => 'simple',
            'stock_availability' => 'in-stock',
            'manage_stock'       => 0,
            'manage_downloadable' => 0,
            'other_info'         => [],
            'default_media'      => [],
        ];
    }

    protected function variantDefaults(int $postId): array
    {
        return [
            'post_id'          => $postId,
            'variation_title'  => 'Default',
            'serial_index'     => 0,
            'payment_type'     => 'onetime',
            'fulfillment_type' => 'digital',
            'item_status'      => 'active',
            'item_price'       => Helper::toCent(29.99),
            'compare_price'    => 0,
            'item_cost'        => 0,
            'manage_stock'     => 0,
            'stock_status'     => 'in-stock',
            'total_stock'      => 0,
            'available'        => 0,
            'committed'        => 0,
            'on_hold'          => 0,
            'backorders'       => 0,
            'other_info'       => ['payment_type' => 'onetime'],
        ];
    }

    public function create(array $overrides = []): Product
    {
        $postData = array_merge($this->defaults(), $this->attributes, $overrides);
        $postId = wp_insert_post($postData);

        if (is_wp_error($postId)) {
            throw new \RuntimeException('Failed to create product post: ' . $postId->get_error_message());
        }

        $detailData = array_merge($this->detailDefaults($postId), $this->detailAttributes);
        ProductDetail::query()->create($detailData);

        if ($this->withVariant) {
            $variantData = array_merge($this->variantDefaults($postId), $this->variantAttributes);
            $variant = ProductVariation::query()->create($variantData);

            // Update detail min/max prices
            ProductDetail::query()->where('post_id', $postId)->update([
                'min_price' => $variant->item_price,
                'max_price' => $variant->item_price,
            ]);
        }

        return Product::query()->with(['detail', 'variants'])->find($postId);
    }

    /**
     * Create a product with multiple variants.
     */
    public function createWithVariants(array $variants, array $postOverrides = []): Product
    {
        $factory = $this->withoutVariant();
        $product = $factory->create($postOverrides);

        $minPrice = PHP_INT_MAX;
        $maxPrice = 0;

        foreach ($variants as $index => $variantOverrides) {
            $variantData = array_merge($this->variantDefaults($product->ID), [
                'serial_index' => $index,
            ], $variantOverrides);
            $variant = ProductVariation::query()->create($variantData);
            if ($variant->item_price < $minPrice) $minPrice = $variant->item_price;
            if ($variant->item_price > $maxPrice) $maxPrice = $variant->item_price;
        }

        ProductDetail::query()->where('post_id', $product->ID)->update([
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
        ]);

        return Product::query()->with(['detail', 'variants'])->find($product->ID);
    }

    /**
     * Create a bundle product with child variations.
     */
    public function createBundle(array $childVariantIds, array $postOverrides = []): Product
    {
        $product = $this
            ->withDetailAttributes([
                'other_info' => ['is_bundle_product' => 'yes'],
            ])
            ->withVariantAttributes([
                'other_info' => [
                    'payment_type'      => 'onetime',
                    'is_bundle_product' => 'yes',
                    'bundle_child_ids'  => $childVariantIds,
                ],
            ])
            ->create($postOverrides);

        return $product;
    }
}

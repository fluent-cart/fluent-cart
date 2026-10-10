<?php

use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Services\Renderer\CartItemRenderer;

return [
    [
        'id' => 'catalogue-display-hooks-defaults',
        'name' => 'catalogue display accessors preserve original values without listeners',
        'phase' => 30,
        'targets' => [
            Product::class . '::getPostTitleAttribute',
            ProductVariation::class . '::getVariationTitleAttribute',
            OrderItem::class . '::getTitleAttribute',
        ],
        'boundaries' => ['unfiltered reads', 'null values', 'canonical attributes'],
        'run' => function () {
            foreach ([
                [new Product(), ['post_title' => 'Source', 'post_excerpt' => '', 'post_content' => null]],
                [new ProductVariation(), ['variation_title' => 'Large']],
                [new OrderItem(), ['post_title' => 'Purchased product', 'title' => 'Purchased variant']],
            ] as [$model, $attributes]) {
                $model->setRawAttributes($attributes, true);
                foreach ($attributes as $field => $value) {
                    FcTest::assertSame($value, $model->$field, 'unfiltered ' . get_class($model) . ':' . $field);
                }
                FcTest::assertSame($attributes, $model->getAttributes(), 'reads leave stored attributes intact');
                FcTest::assertSame(false, $model->isDirty(), 'reads do not dirty the model');
            }
        },
    ],
    [
        'id' => 'catalogue-display-hooks-context',
        'name' => 'catalogue display filters receive the field and actual model without mutating attributes',
        'phase' => 30,
        'targets' => [
            Product::class . '::getPostTitleAttribute',
            Product::class . '::getPostExcerptAttribute',
            Product::class . '::getPostContentAttribute',
            ProductVariation::class . '::getVariationTitleAttribute',
            OrderItem::class . '::getPostTitleAttribute',
            OrderItem::class . '::getTitleAttribute',
        ],
        'boundaries' => ['model identity', 'all display fields', 'raw snapshots', 'listener removal'],
        'run' => function () {
            $calls = [];
            $display = function ($value, $field, $model) use (&$calls) {
                $calls[] = [$value, $field, $model];
                return 'Display: ' . $value;
            };
            $variantDisplay = function ($value, $model) use ($display) {
                return $display($value, 'variation_title', $model);
            };
            add_filter('fluent_cart/product/display_field', $display, 10, 3);
            add_filter('fluent_cart/order_item/display_field', $display, 10, 3);
            add_filter('fluent_cart/variation/display_title', $variantDisplay, 10, 2);
            try {
                foreach ([
                    [new Product(), ['post_title' => 'Product', 'post_excerpt' => 'Summary', 'post_content' => 'Body']],
                    [new ProductVariation(), ['variation_title' => 'Large']],
                    [new OrderItem(), ['post_title' => 'Snapshot product', 'title' => 'Snapshot variant']],
                ] as [$model, $attributes]) {
                    $model->setRawAttributes($attributes, true);
                    foreach ($attributes as $field => $value) {
                        FcTest::assertSame('Display: ' . $value, $model->$field, 'filtered ' . $field);
                        FcTest::assertSame([$value, $field, $model], end($calls), 'original value, field and model');
                    }
                    FcTest::assertSame($attributes, $model->getAttributes(), 'canonical attributes are untouched');
                    FcTest::assertSame(false, $model->isDirty(), 'filtered reads do not dirty the model');
                }
            } finally {
                remove_filter('fluent_cart/product/display_field', $display, 10);
                remove_filter('fluent_cart/order_item/display_field', $display, 10);
                remove_filter('fluent_cart/variation/display_title', $variantDisplay, 10);
            }
            FcTest::assertSame('Snapshot variant', $model->title, 'removing the listener restores the source');
        },
    ],
    [
        'id' => 'catalogue-display-hooks-cart-copy',
        'name' => 'cart display filter changes only the renderer copy of an item',
        'phase' => 30,
        'targets' => [CartItemRenderer::class . '::__construct'],
        'boundaries' => ['local copy', 'unchanged price and identity', 'no listener'],
        'run' => function () {
            $item = ['post_id' => 12, 'title' => 'Large', 'quantity' => 2, 'unit_price' => 1500];
            $property = new ReflectionProperty(CartItemRenderer::class, 'item');
            $property->setAccessible(true);
            FcTest::assertSame($item, $property->getValue(new CartItemRenderer($item)), 'default renderer copy');
            $display = function ($copy) {
                $copy['title'] = 'Display label';
                return $copy;
            };
            add_filter('fluent_cart/cart/display_item', $display);
            try {
                $renderer = new CartItemRenderer($item);
                FcTest::assertSame(
                    array_merge($item, ['title' => 'Display label']),
                    $property->getValue($renderer),
                    'only the renderer label changes'
                );
                FcTest::assertSame('Large', $item['title'], 'caller item remains canonical');
            } finally {
                remove_filter('fluent_cart/cart/display_item', $display);
            }
        },
    ],
];

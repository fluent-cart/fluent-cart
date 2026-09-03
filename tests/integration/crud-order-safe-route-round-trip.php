<?php
/**
 * Phase 9 safe Order REST read/update/delete legs.
 *
 * The create route is excluded because it resolves a payment gateway. This
 * case seeds an inert test-mode, pending-payment Order by exact model ID.
 */

return [
    [
        'id'            => 'crud-order-safe-route-round-trip',
        'name'          => 'Safe Order routes preserve every non-note physical column',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            $updatedNote = FcCrudFixture::marker('order-updated-note');

            try {
                $currency = (string) \FluentCart\App\Helpers\Helper::shopConfig('currency');
                $product = FcDomainFixture::product([
                    'manage_stock' => 0,
                    'fulfillment_type' => 'physical',
                    'item_price' => 1000,
                ]);
                $productId = (int) $product['post']->ID;
                $variantId = (int) $product['variation']->id;
                $order = FcDomainFixture::orderWithItem(
                    $productId,
                    $variantId,
                    1,
                    [
                        'type' => 'refund',
                        'fulfillment_type' => 'physical',
                        'currency' => $currency,
                        'subtotal' => 1000,
                        'tax_total' => 0,
                        'total_amount' => 1000,
                        'config' => ['fixture_case' => 'phase9-order-crud'],
                    ]
                );
                $orderId = (int) $order->id;
                $item = \FluentCart\App\Models\OrderItem::query()
                    ->where('order_id', $orderId)
                    ->first();
                if (!$item) {
                    throw new RuntimeException('Order route fixture has no exact OrderItem.');
                }
                $itemId = (int) $item->id;

                $read = FcTest::rest('GET', '/orders/' . $orderId);
                FcCrudFixture::requireHealthy($read, 'GET /orders/{order_id}');
                FcTest::assertSame(
                    $orderId,
                    (int) ($read['data']['order']['id'] ?? 0),
                    'Order read returns the exact model-seeded ID'
                );

                $beforeOrder = FcCrudFixture::snapshot('order', $orderId);
                $beforeItem = FcCrudFixture::snapshot('order_item', $itemId);
                if ($beforeOrder === null || $beforeItem === null) {
                    throw new RuntimeException('Order full-row snapshot is incomplete.');
                }

                $update = FcTest::rest('POST', '/orders/' . $orderId, [
                    'id' => $orderId,
                    'status' => 'processing',
                    'invoice_no' => '',
                    'fulfillment_type' => 'physical',
                    'type' => 'refund',
                    'customer_id' => (int) $order->customer_id,
                    'payment_method' => '',
                    'payment_method_title' => '',
                    'payment_status' => 'pending',
                    'currency' => $currency,
                    'subtotal' => 1000,
                    'discount_tax' => 0,
                    'manual_discount_total' => 0,
                    'coupon_discount_total' => 0,
                    'shipping_tax' => 0,
                    'shipping_total' => 0,
                    'tax_total' => 0,
                    'tax_behavior' => 0,
                    'total_amount' => 1000,
                    'rate' => 1,
                    'note' => $updatedNote,
                    'uuid' => (string) $order->uuid,
                    'ip_address' => '192.0.2.1',
                    'order_items' => [
                        [
                            'id' => $itemId,
                            'order_id' => $orderId,
                            'post_id' => $productId,
                            'object_id' => $variantId,
                            'fulfillment_type' => 'physical',
                            'payment_type' => 'onetime',
                            'quantity' => 1,
                            'post_title' => (string) $item->post_title,
                            'title' => (string) $item->title,
                            'unit_price' => 1000,
                            'item_cost' => 0,
                            'item_total' => 1000,
                            'tax_amount' => 0,
                            'discount_total' => 0,
                            'total' => 1000,
                            'line_total' => 1000,
                            'cart_index' => (int) $item->cart_index,
                            'rate' => 1,
                            'line_meta' => [],
                            'other_info' => [
                                'payment_type' => 'onetime',
                            ],
                        ],
                    ],
                    'deletedItems' => [],
                    'discount' => [],
                    'shipping' => [],
                    'tax_lines' => [],
                ]);
                FcCrudFixture::requireHealthy($update, 'POST /orders/{order_id}');

                $afterOrder = FcCrudFixture::snapshot('order', $orderId);
                $afterItem = FcCrudFixture::snapshot('order_item', $itemId);
                if ($afterOrder === null || $afterItem === null) {
                    throw new RuntimeException('Order disappeared after route update.');
                }
                FcCrudFixture::assertOnlyFieldChanged(
                    $beforeOrder,
                    $afterOrder,
                    'note',
                    $updatedNote,
                    'Order one-field route update'
                );

                $expectedItem = $beforeItem;
                if (
                    array_key_exists('updated_at', $beforeItem)
                    && array_key_exists('updated_at', $afterItem)
                ) {
                    FcTest::assert(
                        (string) $afterItem['updated_at'] >= (string) $beforeItem['updated_at'],
                        'OrderItem automatic updated_at never moves backwards'
                    );
                    $expectedItem['updated_at'] = $afterItem['updated_at'];
                }
                FcTest::assertSame(
                    $expectedItem,
                    $afterItem,
                    'Order one-field update preserves every child OrderItem value'
                );

                $delete = FcTest::rest('DELETE', '/orders/' . $orderId);
                FcCrudFixture::requireHealthy($delete, 'DELETE /orders/{order_id}');
                FcTest::assertSame(
                    null,
                    FcCrudFixture::snapshot('order', $orderId),
                    'Order exact model-seeded row is absent after delete'
                );
                FcTest::assertSame(
                    null,
                    FcCrudFixture::snapshot('order_item', $itemId),
                    'OrderItem exact child row is absent after Order delete'
                );
            } finally {
                FcCrudFixture::cleanupAll();
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];

<?php

namespace FluentCartPro\App\Modules\StockManagement;


use FluentCart\Api\ModuleSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use FluentCart\Framework\Support\Arr;

class StockManagement
{

    public function register($app)
    {
        $app->addFilter('fluent_cart/module_setting/fields', function ($fields, $args) {
            $fields['stock_management'] = [
                'title'       => __('Stock Management', 'fluent-cart-pro'),
                'description' => __('Manage stock of your products easier than ever!', 'fluent-cart-pro'),
                'type'        => 'component',
                'component'   => 'ModuleSettings',
            ];
            return $fields;
        }, 10, 2);


        $app->addFilter('fluent_cart/module_setting/default_values', function ($values, $args) {
            if (empty($values['stock_management']['active'])) {
                $values['stock_management']['active'] = 'no';
            }
            return $values;
        }, 10, 2);

        add_filter('fluent_cart/shop_query', [$this, 'filterShopQuery'], 10, 2);


        if (!ModuleSettings::isActive('stock_management')) {
            return;
        }

        add_filter('fluent_cart/variation/can_purchase_bundle', function ($result, $data) {
            $variation = $data['variation'];
            $quantity = (int)$data['quantity'];

            if ($variation->product && method_exists($variation->product, 'isBundleProduct') && $variation->product->isBundleProduct()) {
                $children = $variation->bundleChildren()->get();
                if ($children && $children->count()) {
                    foreach ($children as $child) {
                        $childDetail = $child->product_detail;
                        if (!$childDetail) {
                            return new \WP_Error('unpublished', __('This product is not available for purchase', 'fluent-cart'));
                        }
                        if (($childDetail->manage_stock && $child->manage_stock) && $quantity > $child->available) {
                            return new \WP_Error('insufficient_stock', __('Sorry, this product is currently out of stock.', 'fluent-cart'));
                        }
                    }
                }
            }

            return true;
        }, 10, 2);

        // Manage stock on order created
        $app->addAction('fluent_cart/order_created', [$this, 'manageStockOnOrderCreated']);
        $app->addAction('fluent_cart/shipping_status_changed', [$this, 'manageStockOnShippingStatusChanged']);
        $app->addAction('fluent_cart/order_status_changed', [$this, 'manageStockOnOrderStatusChanged']);
        $app->addAction('fluent_cart/order_refunded', [$this, 'manageStockOnOrderRefunded']);
        $app->addAction('fluent_cart/order_paid', [$this, 'manageStockOnOrderPaid']);
        $app->addAction('fluent_cart/order_updated', [$this, 'manageStockOnOrderUpdated']);

    }

    public function filterShopQuery($query, $params)
    {
        $query = $query->when(Arr::get($params, 'selected_status'), function ($query) use ($params) {
            $status = Arr::get($params, 'status');
            $allowOutOfStock = Arr::get($params, 'allow_out_of_stock', false);

            return $query->where(function ($query) use ($status, $allowOutOfStock) {
                $query->search($status);

                if (ModuleSettings::isActive('stock_management') && !$allowOutOfStock) {
                    $query->whereHas('detail', function ($query) {
                        return $query->search([
                            "stock_availability" => [
                                "column" => "stock_availability",
                                "value" => Helper::IN_STOCK
                            ]
                        ]);
                    });
                }
            });
        });

        return $query;
    }

    public function manageStockOnOrderCreated($event)
    {
        $order = Arr::get($event, 'order');
        $prevOrder = Arr::get($event, 'prev_order');
        $orderItems = $order->order_items;

        if ($prevOrder) {
            return;
        }

        if (!$order || !$orderItems) {
            return;
        }

        $orderItems = $orderItems->filter(function ($item) {
            return $item->payment_type !== 'signup_fee';
        });

        $pluckVariationIds = $orderItems->pluck('object_id')->toArray();
        $orderItems = $orderItems->keyBy('object_id')->toArray();

        if (empty($pluckVariationIds)) {
            return;
        }

        $variations = ProductVariation::query()
            ->select('id', 'post_id', 'available', 'committed', 'on_hold', 'manage_stock')
            ->with('product_detail')
            ->whereIn('id', $pluckVariationIds)
            ->where('manage_stock', 1)
            ->get()
            ->keyBy('id');

        if ($variations->isEmpty()) {
            return;
        }

        // Get existing meta for this order
        $existingMeta = OrderMeta::where('order_id', $order->id)
            ->where('meta_key', 'stock_movement')
            ->value('meta_value');

        $stockMovement = $existingMeta ? $existingMeta : [];

        $updatedVariants = [];
        $affectedProductIds = [];

        foreach ($variations as $item) {
            $orderItemId = Arr::get($orderItems, $item->id . '.id');
            $quantity = (int)Arr::get($orderItems, $item->id . '.quantity', 0);

            if ($quantity <= 0) {
                continue;
            }

            $newAvailable = $item->available - $quantity;
            $updatedData = [
                'id'           => $item->id,
                'on_hold'      => ['+', $quantity],
                'available'    => $newAvailable <= 0 ? 0 : $newAvailable,
                'stock_status' => $newAvailable <= 0 ? 'out-of-stock' : 'in-stock',
            ];

            $updatedVariants[] = $updatedData;
            $affectedProductIds[] = $item->post_id;

            // Add to stock_movement array
            $stockMovement[$orderItemId] = [
                'on_hold' => $quantity
            ];

            if ($item->product_detail && Arr::get($item->product_detail->other_info, 'is_bundle_product') === 'yes') {
                $childVariations = $item->bundleChildren()->get();
                if ($childVariations && $childVariations->count()) {
                    foreach ($childVariations as $child) {
                        if ((int)$child->manage_stock !== 1) {
                            continue;
                        }
                        $childAvailable = (int)$child->available - $quantity;
                        $updatedVariants[] = [
                            'id'           => $child->id,
                            'on_hold'      => ['+', $quantity],
                            'available'    => $childAvailable <= 0 ? 0 : $childAvailable,
                            'stock_status' => $childAvailable <= 0 ? 'out-of-stock' : 'in-stock',
                        ];
                        $affectedProductIds[] = $child->post_id;
                    }
                }
            }
        }

        if (!empty($updatedVariants)) {
            ProductVariation::query()->batchUpdate($updatedVariants);
        }

        if (!empty($affectedProductIds)) {
            $affectedProductIds = array_unique($affectedProductIds);

            $updatedProducts = [];
            foreach ($affectedProductIds as $productId) {
                // check all variations of this product
                $hasInStock = ProductVariation::query()
                    ->where('post_id', $productId)
                    ->where('stock_status', 'in-stock')
                    ->exists();

                // get product details by $productId and get only id from it
                $detail = ProductDetail::query()->where('post_id', $productId)->select('id')->first();

                if (!$detail->id) {
                    continue;
                }
                $updatedProducts[] = [
                    'id'                 => $detail->id,
                    'stock_availability' => $hasInStock ? 'in-stock' : 'out-of-stock'
                ];
            }

            if (!empty($updatedProducts)) {
                ProductDetail::query()->batchUpdate($updatedProducts);
            }
        }

        // Save merged data back to order_meta
        OrderMeta::updateOrCreate(
            [
                'order_id' => $order->id,
                'meta_key' => 'stock_movement',
            ],
            [
                'meta_value' => json_encode($stockMovement),
            ]
        );
    }

    public function manageStockOnShippingStatusChanged($event)
    {
        $order = Arr::get($event, 'order');
        $orderItems = $order->order_items;
        $status = Arr::get($event, 'new_status');
        $oldStatus = Arr::get($event, 'old_status');

        if (!$order || !$orderItems) {
            return;
        }

        $orderItems = $orderItems->filter(function ($item) {
            return $item->payment_type !== 'signup_fee';
        });

        $pluckVariationIds = $orderItems->pluck('object_id')->toArray();
        $orderItems = $orderItems->keyBy('object_id')->toArray();

        if (empty($pluckVariationIds)) {
            return;
        }

        $variations = ProductVariation::query()
            ->select('id', 'post_id', 'available', 'committed', 'on_hold', 'manage_stock', 'fulfillment_type')
            ->with('product_detail')
            ->whereIn('id', $pluckVariationIds)
            ->where('manage_stock', 1)
            ->where('fulfillment_type', 'physical')
            ->get()
            ->keyBy('id');

        if ($variations->isEmpty()) {
            return;
        }

        // Get existing meta for this order
        $existingMeta = OrderMeta::where('order_id', $order->id)
            ->where('meta_key', 'stock_movement')
            ->value('meta_value');

        $stockMovement = $existingMeta ? $existingMeta : [];

        $updatedVariants = [];

        // Handle stock updates
        $isDelivered = ($status === 'delivered');
        $wasDelivered = ($oldStatus === 'delivered');

        foreach ($variations as $variation) {
            $orderItemId = Arr::get($orderItems, $variation->id . '.id');
            $quantity = (int)Arr::get($orderItems, $variation->id . '.quantity', 0);

            if ($quantity <= 0) {
                continue;
            }

            if ($variation->product_detail && Arr::get($variation->product_detail->other_info, 'is_bundle_product') === 'yes') {
                $childVariations = $variation->bundleChildren()->get();
                if ($childVariations && $childVariations->count()) {
                    foreach ($childVariations as $child) {
                        if ((int)$child->manage_stock !== 1) {
                            continue;
                        }
                        if ($isDelivered) {
                            $updatedVariants[] = [
                                'id'        => $child->id,
                                'committed' => ['+', $quantity],
                                'on_hold'   => ['-', $quantity],
                            ];
                        } elseif ($wasDelivered && !$isDelivered) {
                            $updatedVariants[] = [
                                'id'        => $child->id,
                                'committed' => ['-', $quantity],
                                'on_hold'   => ['+', $quantity],
                            ];
                        }
                    }
                }
            }

            $newDecreaseStock = ['-', $quantity];
            if ($isDelivered) {
                // Order delivered: move stock from on_hold → committed
                $updatedVariants[] = [
                    'id'        => $variation->id,
                    'committed' => ['+', $quantity],
                    'on_hold'   => $newDecreaseStock,
                ];

                // Add to stock_movement array
                $stockMovement[$orderItemId] = [
                    'committed' => $quantity,
                    'on_hold'   => 0,
                ];
            } elseif ($wasDelivered && !$isDelivered) {
                // Status changed away from delivered: revert changes
                $updatedVariants[] = [
                    'id'        => $variation->id,
                    'committed' => $newDecreaseStock,
                    'on_hold'   => ['+', $quantity],
                ];

                // Add to stock_movement array
                $stockMovement[$orderItemId] = [
                    'committed' => 0,
                    'on_hold'   => $quantity,
                ];
            }
        }

        if (!empty($updatedVariants)) {
            ProductVariation::query()->batchUpdate($updatedVariants);
        }

        // Save merged data back to order_meta
        OrderMeta::updateOrCreate(
            [
                'order_id' => $order->id,
                'meta_key' => 'stock_movement',
            ],
            [
                'meta_value' => json_encode($stockMovement),
            ]
        );
    }

    public function manageStockOnOrderStatusChanged($event)
    {
        $newStatus = Arr::get($event, 'new_status');
        $order = Arr::get($event, 'order');
        $orderItems = $order->order_items;
        if ($newStatus !== 'canceled') {
            return;
        }

        if (!$order || !$orderItems) {
            return;
        }


        $orderItems = $orderItems->filter(function ($item) {
            return $item->payment_type !== 'signup_fee';
        });

        $pluckVariationIds = $orderItems->pluck('object_id')->toArray();
        $orderItems = $orderItems->keyBy('object_id')->toArray();
        if (empty($pluckVariationIds)) {
            return;
        }

        // Get existing meta for this order
        $existingMeta = OrderMeta::where('order_id', $order->id)
            ->where('meta_key', 'stock_movement')
            ->value('meta_value');

        $stockMovement = $existingMeta ? $existingMeta : [];

        $variations = ProductVariation::query()
            ->select('id', 'post_id', 'available', 'committed', 'on_hold', 'manage_stock', 'fulfillment_type')
            ->with('product_detail')
            ->whereIn('id', $pluckVariationIds)
            ->where('manage_stock', 1)
            ->get()
            ->keyBy('id');

        if ($variations->isEmpty()) {
            return;
        }
        $updatedVariants = [];
        $affectedProductIds = [];

        // get shipping_status from $order
        $shippingStatus = $order->shipping_status;

        foreach ($variations as $item) {
            $quantity = (int)Arr::get($orderItems, $item->id . '.quantity', 0); // refund request qty
            $orderItemId = Arr::get($orderItems, $item->id . '.id');

            if ($quantity <= 0) {
                continue;
            }

            $oldOnHold = (int)Arr::get($stockMovement, $orderItemId . '.on_hold', 0);
            $oldCommitted = (int)Arr::get($stockMovement, $orderItemId . '.committed', 0);

            $removedOnHold = 0;
            $removedCommitted = 0;
            $remainingRefund = $quantity;

            // Priority: delivered/digital → committed first, else → on_hold first
            if ($shippingStatus === 'delivered' || ($item->fulfillment_type === 'digital' && $order->payment_status === 'paid')) {
                $orderOfRemoval = ['committed', 'on_hold'];
            } else {
                $orderOfRemoval = ['on_hold', 'committed'];
            }

            if ($item->product_detail && Arr::get($item->product_detail->other_info, 'is_bundle_product') === 'yes') {
                $childVariations = $item->bundleChildren()->get();
                if ($childVariations && $childVariations->count()) {
                    foreach ($childVariations as $child) {
                        if ((int)$child->manage_stock !== 1) {
                            continue;
                        }
                        $childOldOnHold = (int)$child->on_hold;
                        $childOldCommitted = (int)$child->committed;
                        $childRemovedOnHold = 0;
                        $childRemovedCommitted = 0;
                        $childRemaining = $quantity;
                        foreach ($orderOfRemoval as $place) {
                            if ($childRemaining <= 0) {
                                break;
                            }
                            if ($place === 'on_hold' && $childOldOnHold > 0) {
                                $remove = min($childOldOnHold, $childRemaining);
                                $childRemovedOnHold = $remove;
                                $childRemaining -= $remove;
                            }
                            if ($place === 'committed' && $childOldCommitted > 0) {
                                $remove = min($childOldCommitted, $childRemaining);
                                $childRemovedCommitted = $remove;
                                $childRemaining -= $remove;
                            }
                        }
                        $childAvailable = (int)$child->available + $quantity;
                        $childUpdate = [
                            'id'           => $child->id,
                            'available'    => $childAvailable <= 0 ? 0 : $childAvailable,
                            'stock_status' => $childAvailable <= 0 ? 'out-of-stock' : 'in-stock',
                        ];
                        if ($childRemovedOnHold > 0) {
                            $childUpdate['on_hold'] = ['-', $childRemovedOnHold];
                        }
                        if ($childRemovedCommitted > 0) {
                            $childUpdate['committed'] = ['-', $childRemovedCommitted];
                        }
                        $updatedVariants[] = $childUpdate;
                        $affectedProductIds[] = $child->post_id;
                    }
                }
            }

            // Deduct according to priority
            foreach ($orderOfRemoval as $place) {
                if ($remainingRefund <= 0) {
                    break;
                }

                if ($place === 'on_hold' && $oldOnHold > 0) {
                    $remove = min($oldOnHold, $remainingRefund);
                    $removedOnHold = $remove;
                    $remainingRefund -= $remove;

                    $stockMovement[$orderItemId]['on_hold'] = $oldOnHold - $remove;
                    if ($stockMovement[$orderItemId]['on_hold'] <= 0) {
//                        unset($stockMovement[$orderItemId]['on_hold']);
                    }
                }

                if ($place === 'committed' && $oldCommitted > 0) {
                    $remove = min($oldCommitted, $remainingRefund);
                    $removedCommitted = $remove;
                    $remainingRefund -= $remove;

                    $stockMovement[$orderItemId]['committed'] = $oldCommitted - $remove;
                    if ($stockMovement[$orderItemId]['committed'] <= 0) {
//                        unset($stockMovement[$orderItemId]['committed']);
                    }
                }
            }

            // Update stock back
            $newAvailable = $item->available + $quantity;
            $update = [
                'id'           => $item->id,
                'available'    => $newAvailable <= 0 ? 0 : $newAvailable,
                'stock_status' => $newAvailable <= 0 ? 'out-of-stock' : 'in-stock',
            ];

            if ($removedOnHold > 0) {
                $update['on_hold'] = ['-', $removedOnHold];
            }
            if ($removedCommitted > 0) {
                $update['committed'] = ['-', $removedCommitted];
            }

            $updatedVariants[] = $update;
            $affectedProductIds[] = $item->post_id;
        }

        if (empty($updatedVariants)) {
            return;
        }

        ProductVariation::query()->batchUpdate($updatedVariants);

        if (empty($affectedProductIds)) {
            return;
        }

        $affectedProductIds = array_unique($affectedProductIds);

        $updatedProducts = [];
        foreach ($affectedProductIds as $productId) {
            // check all variations of this product
            $hasInStock = ProductVariation::query()
                ->where('post_id', $productId)
                ->where('stock_status', 'in-stock')
                ->exists();

            // get product details by $productId and get only id from it
            $detail = ProductDetail::query()->where('post_id', $productId)->select('id')->first();

            $updatedProducts[] = [
                'id'                 => $detail->id,
                'stock_availability' => $hasInStock ? 'in-stock' : 'out-of-stock'
            ];
        }

        if (!empty($updatedProducts)) {
            ProductDetail::query()->batchUpdate($updatedProducts);
        }

        // Save merged data back to order_meta
        OrderMeta::updateOrCreate(
            [
                'order_id' => $order->id,
                'meta_key' => 'stock_movement',
            ],
            [
                'meta_value' => json_encode($stockMovement),
            ]
        );
    }

    public function manageStockOnOrderRefunded($data)
    {
        $manageStock = Arr::get($data, 'manage_stock', false);
        if (!$manageStock) {
            return;
        }

        $order = Arr::get($data, 'order');
        $refundedItems = Arr::get($data, 'new_refunded_items');

        $pluckVariationIds = [];
        $mappedRestockItems = [];
        foreach ($refundedItems as $orderItem) {
            $pluckVariationIds[] = $orderItem['variation_id'];
            $mappedRestockItems[$orderItem['variation_id']] = [
                'quantity' => $orderItem['restore_quantity'],
                'id'       => $orderItem['id']
            ];
        }

        if (empty ($pluckVariationIds)) {
            return;
        }

        $variations = ProductVariation::query()
            ->select('id', 'post_id', 'available', 'committed', 'on_hold', 'manage_stock', 'fulfillment_type', 'other_info')
            ->with('product_detail')
            ->whereIn('id', $pluckVariationIds)
            ->where('manage_stock', 1)
            ->get()
            ->keyBy('id');

        if ($variations->isEmpty()) {
            return;
        }

        // Get existing meta for this order
        $existingMeta = OrderMeta::where('order_id', $order->id)
            ->where('meta_key', 'stock_movement')
            ->value('meta_value');

        $stockMovement = $existingMeta ? $existingMeta : [];


        $updatedVariants = [];
        $affectedProductIds = [];

        $shippingStatus = $order->shipping_status;

        $restockedItems = [];

        foreach ($variations as $item) {
            $orderItemId = Arr::get($mappedRestockItems, $item->id . '.id', 0);

            $quantity = (int)Arr::get($mappedRestockItems, $item->id . '.quantity', 0);
//            $oldQuantity = (int)Arr::get($stockMovement, $orderItemId . '.committed', 0);

            if ($quantity <= 0) {
                continue;
            }


            $oldOnHold = (int)Arr::get($stockMovement, $orderItemId . '.on_hold', 0);
            $oldCommitted = (int)Arr::get($stockMovement, $orderItemId . '.committed', 0);

            // Track how much refunded from each place
            $removedOnHold = 0;
            $removedCommitted = 0;

            $remainingRefund = $quantity;

            if ($shippingStatus === 'delivered' || ($item->fulfillment_type === 'digital' && $order->payment_status === 'paid')) {
                $orderOfRemoval = ['committed', 'on_hold'];
            } else {
                $orderOfRemoval = ['on_hold', 'committed'];
            }

            foreach ($orderOfRemoval as $place) {
                if ($remainingRefund <= 0) {
                    break;
                }

                if ($place === 'on_hold' && $oldOnHold > 0) {
                    $remove = min($oldOnHold, $remainingRefund);
                    $removedOnHold = $remove;
                    $remainingRefund -= $remove;

                    $stockMovement[$orderItemId]['on_hold'] = $oldOnHold - $remove;
                }

                if ($place === 'committed' && $oldCommitted > 0) {
                    $remove = min($oldCommitted, $remainingRefund);
                    $removedCommitted = $remove;
                    $remainingRefund -= $remove;

                    $stockMovement[$orderItemId]['committed'] = $oldCommitted - $remove;
                }
            }

            $newAvailable = $item->available + $quantity;

            $restockedItems[$item->id] = $quantity;

            $update = [
                'id'           => $item->id,
                'available'    => $newAvailable <= 0 ? 0 : $newAvailable,
                'stock_status' => $newAvailable <= 0 ? 'out-of-stock' : 'in-stock',
            ];

//            $newDecreaseStock = ['-', $quantity];
//            if ($shippingStatus === 'delivered') {
//                $update['committed'] = $newDecreaseStock;
//            } else {
//                $newOnHold = $newDecreaseStock;
//                $update['on_hold'] = $newOnHold;
//            }

            if ($removedOnHold > 0) {
                $update['on_hold'] = ['-', $removedOnHold];
            }
            if ($removedCommitted > 0) {
                $update['committed'] = ['-', $removedCommitted];
            }

            $updatedVariants[] = $update;
            $affectedProductIds[] = $item->post_id;

            if ($item->product_detail && Arr::get($item->product_detail->other_info, 'is_bundle_product') === 'yes') {
                $childVariations = $item->bundleChildren()->get();
                if ($childVariations && $childVariations->count()) {
                    foreach ($childVariations as $child) {
                        if ((int)$child->manage_stock !== 1) {
                            continue;
                        }

                        $childOldOnHold = (int)$child->on_hold;
                        $childOldCommitted = (int)$child->committed;

                        $childRemovedOnHold = 0;
                        $childRemovedCommitted = 0;
                        $childRemaining = $quantity;

                        foreach ($orderOfRemoval as $place) {
                            if ($childRemaining <= 0) {
                                break;
                            }
                            if ($place === 'on_hold' && $childOldOnHold > 0) {
                                $remove = min($childOldOnHold, $childRemaining);
                                $childRemovedOnHold = $remove;
                                $childRemaining -= $remove;
                            }
                            if ($place === 'committed' && $childOldCommitted > 0) {
                                $remove = min($childOldCommitted, $childRemaining);
                                $childRemovedCommitted = $remove;
                                $childRemaining -= $remove;
                            }
                        }

                        $childAvailable = (int)$child->available + $quantity;

                        $childUpdate = [
                            'id'           => $child->id,
                            'available'    => $childAvailable <= 0 ? 0 : $childAvailable,
                            'stock_status' => $childAvailable <= 0 ? 'out-of-stock' : 'in-stock',
                        ];
                        if ($childRemovedOnHold > 0) {
                            $childUpdate['on_hold'] = ['-', $childRemovedOnHold];
                        }
                        if ($childRemovedCommitted > 0) {
                            $childUpdate['committed'] = ['-', $childRemovedCommitted];
                        }
                        $updatedVariants[] = $childUpdate;
                        $affectedProductIds[] = $child->post_id;
                    }
                }
            }
        }

        if (empty($updatedVariants)) {
            return;
        }

        ProductVariation::query()->batchUpdate($updatedVariants);


        // Save merged data back to order_meta
        OrderMeta::updateOrCreate(
            [
                'order_id' => $order->id,
                'meta_key' => 'stock_movement',
            ],
            [
                'meta_value' => json_encode($stockMovement),
            ]
        );


        $orderItems = $order->order_items;
        $deleteableOrderItems = [];
        $orderItemsUpdateData = [];

        foreach ($orderItems as $item) {
            $quantity = (int)Arr::get($restockedItems, $item->object_id, 0);
            $newQuantity = $item->quantity - $quantity;
            if ($newQuantity <= 0) {
                $deleteableOrderItems[] = $item->id;
            } else {
                $orderItemsUpdateData[] = [
                    'id'       => $item->id,
                    'quantity' => $newQuantity
                ];
            }
        }


        //delete order items
        if (!empty($deleteableOrderItems)) {
            OrderItem::query()->whereIn('id', $deleteableOrderItems)->delete();
        }

        //update order items quantity
        if (!empty($orderItemsUpdateData)) {
            OrderItem::query()->batchUpdate($orderItemsUpdateData);
        }

        if (empty($affectedProductIds)) {
            return;
        }

        $affectedProductIds = array_unique($affectedProductIds);

        $updatedProducts = [];
        foreach ($affectedProductIds as $productId) {
            // check all variations of this product
            $hasInStock = ProductVariation::query()
                ->where('post_id', $productId)
                ->where('stock_status', 'in-stock')
                ->exists();

            // get product details by $productId and get only id from it
            $detail = ProductDetail::query()->where('post_id', $productId)->select('id')->first();

            $updatedProducts[] = [
                'id'                 => $detail->id,
                'stock_availability' => $hasInStock ? 'in-stock' : 'out-of-stock'
            ];
        }

        if (!empty($updatedProducts)) {
            ProductDetail::query()->batchUpdate($updatedProducts);
        }
    }

    public function manageStockOnOrderPaid($event)
    {
        $order = Arr::get($event, 'order');
        $orderItems = $order->order_items;

        if (!$order || !$orderItems) {
            return;
        }

        $orderItems = $orderItems->filter(function ($item) {
            return $item->payment_type !== 'signup_fee';
        });

        $pluckVariationIds = $orderItems->pluck('object_id')->toArray();
        $orderItems = $orderItems->keyBy('object_id')->toArray();

        if (empty($pluckVariationIds)) {
            return;
        }

        $variations = ProductVariation::query()
            ->select('id', 'post_id', 'available', 'committed', 'on_hold', 'manage_stock', 'fulfillment_type')
            ->with('product_detail')
            ->whereIn('id', $pluckVariationIds)
            ->where('manage_stock', 1)
            ->where('fulfillment_type', 'digital')
            ->get()
            ->keyBy('id');

        if ($variations->isEmpty()) {
            return;
        }

        // Get existing meta for this order
        $existingMeta = OrderMeta::where('order_id', $order->id)
            ->where('meta_key', 'stock_movement')
            ->value('meta_value');

        $stockMovement = $existingMeta ? $existingMeta : [];

        $updatedVariants = [];

        foreach ($variations as $item) {
            $orderItemId = Arr::get($orderItems, $item->id . '.id');

            // get quantity from $stockMovement
            $quantity = (int)Arr::get($orderItems, $item->id . '.quantity', 0);


            if ($quantity <= 0) {
                continue;
            }

            $updatedVariants[] = [
                'id'        => $item->id,
                'on_hold'   => ['-', $quantity],
                'committed' => ['+', $quantity],
            ];

            $stockMovement[$orderItemId] = [
                'committed' => $quantity,
                'on_hold'   => 0,
            ];

            if ($item->product_detail && Arr::get($item->product_detail->other_info, 'is_bundle_product') === 'yes') {
                $childVariations = $item->bundleChildren()->get();
                if ($childVariations && $childVariations->count()) {
                    foreach ($childVariations as $child) {
                        if ((int)$child->manage_stock !== 1) {
                            continue;
                        }
                        $updatedVariants[] = [
                            'id'        => $child->id,
                            'on_hold'   => ['-', $quantity],
                            'committed' => ['+', $quantity],
                        ];
                    }
                }
            }
        }

        if (empty($updatedVariants)) {
            return;
        }

        ProductVariation::query()->batchUpdate($updatedVariants);

        // Save merged data back to order_meta
        OrderMeta::updateOrCreate(
            [
                'order_id' => $order->id,
                'meta_key' => 'stock_movement',
            ],
            [
                'meta_value' => json_encode($stockMovement),
            ]
        );
    }

    public function manageStockOnOrderUpdated($event)
    {
        $order         = Arr::get($event, 'order');
        $orderItems    = $order->order_items;
        $oldOrder      = Arr::get($event, 'old_order');
        $oldOrderItems = $oldOrder->order_items;

        if (!$order || !$orderItems) {
            return;
        }

        $orderItems = $orderItems->filter(function ($item) {
            return $item->payment_type !== 'signup_fee';
        });

        // Map by object_id for easier comparison
        $newItems = $orderItems->keyBy('object_id');
        $oldItems = $oldOrderItems->keyBy('object_id');

        $mergedIds = $newItems->keys()
            ->merge($oldItems->keys())
            ->unique();

        // Get existing meta
        $existingMeta = OrderMeta::where('order_id', $order->id)
            ->where('meta_key', 'stock_movement')
            ->value('meta_value');

        $stockMovement = $existingMeta ? $existingMeta : [];

        $updatedVariants    = [];
        $affectedProductIds = [];

        foreach ($mergedIds as $variationId) {
            $oldQuantity = (int)Arr::get($oldItems, $variationId . '.quantity', 0);
            $newQuantity = (int)Arr::get($newItems, $variationId . '.quantity', 0);
            $diff        = $newQuantity - $oldQuantity;

            // No change
            if ($diff === 0) {
                continue;
            }


            // Get order item ID (prefer new item, fallback to old item)
            $orderItemId = Arr::get($newItems, $variationId . '.id')
                ?? Arr::get($oldItems, $variationId . '.id');

            // Fetch variation
            $item = ProductVariation::query()
                ->select('id', 'post_id', 'available', 'on_hold', 'committed', 'manage_stock', 'fulfillment_type')
                ->where('id', $variationId)
                ->first();

            if (!$item || !$item->manage_stock) {
                continue;
            }

            $newAvailable = $item->available - $diff;

            $update = [
                'id'           => $item->id,
                'available'    => $newAvailable <= 0 ? 0 : $newAvailable,
                'stock_status' => $newAvailable <= 0 ? 'out-of-stock' : 'in-stock',
            ];

            // Decide whether it affects on_hold or committed
            if ($order->shipping_status === 'delivered' ||
                ($item->fulfillment_type === 'digital' && $order->payment_status === 'paid')) {
                // Committed
                if ($diff > 0) {
                    $update['committed'] = ['+', $diff];
                } else {
                    $update['committed'] = ['-', abs($diff)];
                }

                $stockMovement[$orderItemId]['committed'] =
                    max(0, (int)Arr::get($stockMovement, $orderItemId . '.committed', 0) + $diff);
                if ($stockMovement[$orderItemId]['committed'] === 0) {
                }
            } else {
                if ($diff > 0) {
                    $update['on_hold'] = ['+', $diff];
                } else {
                    $update['on_hold'] = ['-', abs($diff)];
                }

                $stockMovement[$orderItemId]['on_hold'] =
                    max(0, (int)Arr::get($stockMovement, $orderItemId . '.on_hold', 0) + $diff);
                if ($stockMovement[$orderItemId]['on_hold'] === 0) {
                }
            }

            if (empty($stockMovement[$orderItemId])) {
            }

            $updatedVariants[]    = $update;
            $affectedProductIds[] = $item->post_id;

            if ($item->product_detail && Arr::get($item->product_detail->other_info, 'is_bundle_product') === 'yes') {
                $childVariations = $item->bundleChildren()->get();
                if ($childVariations && $childVariations->count()) {
                    foreach ($childVariations as $child) {
                        if ((int)$child->manage_stock !== 1) {
                            continue;
                        }
                        $childAvailable = (int)$child->available - $diff;
                        $childUpdate = [
                            'id'           => $child->id,
                            'available'    => $childAvailable <= 0 ? 0 : $childAvailable,
                            'stock_status' => $childAvailable <= 0 ? 'out-of-stock' : 'in-stock',
                        ];
                        if ($order->shipping_status === 'delivered' ||
                            ($child->fulfillment_type === 'digital' && $order->payment_status === 'paid')) {
                            if ($diff > 0) {
                                $childUpdate['committed'] = ['+', $diff];
                            } else {
                                $childUpdate['committed'] = ['-', abs($diff)];
                            }
                        } else {
                            if ($diff > 0) {
                                $childUpdate['on_hold'] = ['+', $diff];
                            } else {
                                $childUpdate['on_hold'] = ['-', abs($diff)];
                            }
                        }
                        $updatedVariants[]    = $childUpdate;
                        $affectedProductIds[] = $child->post_id;
                    }
                }
            }
        }

        if (!empty($updatedVariants)) {
            ProductVariation::query()->batchUpdate($updatedVariants);
        }

        if (!empty($affectedProductIds)) {
            $affectedProductIds = array_unique($affectedProductIds);
            $updatedProducts = [];

            foreach ($affectedProductIds as $productId) {
                $hasInStock = ProductVariation::query()
                    ->where('post_id', $productId)
                    ->where('stock_status', 'in-stock')
                    ->exists();

                $detail = ProductDetail::query()
                    ->where('post_id', $productId)
                    ->select('id')
                    ->first();

                if ($detail) {
                    $updatedProducts[] = [
                        'id'                => $detail->id,
                        'stock_availability'=> $hasInStock ? 'in-stock' : 'out-of-stock'
                    ];
                }
            }

            if (!empty($updatedProducts)) {
                ProductDetail::query()->batchUpdate($updatedProducts);
            }
        }

        OrderMeta::updateOrCreate(
            [
                'order_id' => $order->id,
                'meta_key' => 'stock_movement',
            ],
            [
                'meta_value' => json_encode($stockMovement),
            ]
        );
    }

}

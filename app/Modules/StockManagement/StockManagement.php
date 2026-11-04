<?php

namespace FluentCart\App\Modules\StockManagement;


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
                'title'       => __('Stock Management', 'fluent-cart'),
                'description' => __('Manage stock of your products easier than ever!', 'fluent-cart'),
                'type'        => 'component',
                'component'   => 'ModuleSettings',
                'settings'    => [
                    'enable_advanced_inventory' => [
                        'label'   => __('Enable Advanced Inventory', 'fluent-cart'),
                        'default' => 'no'
                    ]
                ]
            ];
            return $fields;
        }, 10, 2);


        $app->addFilter('fluent_cart/module_setting/default_values', function ($values, $args) {
            if (empty($values['stock_management']['active'])) {
                $values['stock_management']['active'] = 'no';
            }

            if (empty($values['stock_management']['enable_advanced_inventory'])) {
                $values['stock_management']['enable_advanced_inventory'] = 'no';
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
            $this->adjustStockOnRetry($order, $prevOrder);
            return;
        }

        if (!$order || !$orderItems) {
            return;
        }

        $orderItems = $orderItems->filter(function ($item) {
            return !in_array($item->payment_type, ['signup_fee', 'fee']);
        });

        $pluckVariationIds = $orderItems->pluck('object_id')->toArray();
        $orderItems = $orderItems->keyBy('object_id')->toArray();

        if (empty($pluckVariationIds)) {
            return;
        }

        $variations = ProductVariation::query()
            ->select('id', 'post_id', 'available', 'committed', 'on_hold', 'manage_stock', 'other_info')
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

        $this->syncProductAvailability($affectedProductIds);

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

    /**
     * Units an order holds on hold per variation, read from its stock_movement ledger.
     *
     * @param iterable $orderItems the order's item rows the ledger was written for
     * @param array|null $stockMovement
     * @return array<int, int> variation id => units on hold
     */
    public static function heldUnitsByVariation($orderItems, $stockMovement): array
    {
        $held = [];
        if (!is_array($stockMovement) || !$orderItems) {
            return $held;
        }

        foreach ($orderItems as $item) {
            if (in_array($item->payment_type, ['signup_fee', 'fee'])) {
                continue;
            }
            $units = (int)Arr::get($stockMovement, $item->id . '.on_hold', 0);
            if ($units > 0) {
                $held[$item->object_id] = Arr::get($held, $item->object_id, 0) + $units;
            }
        }

        return $held;
    }

    /**
     * A checkout retry reuses the order and may change its lines: hold exactly what the
     * order now asks for by reserving or releasing only the difference.
     */
    private function adjustStockOnRetry($order, $prevOrder)
    {
        $stockMovement = OrderMeta::where('order_id', $order->id)
            ->where('meta_key', 'stock_movement')
            ->value('meta_value');

        // No ledger means the earlier attempt held nothing; lines added now still need a reservation.
        if (!is_array($stockMovement)) {
            $stockMovement = [];
        }

        // shortcut: a retryable (unpaid) order normally has nothing committed; if it does, keep the old no-op.
        foreach ($stockMovement as $entry) {
            if ((int)Arr::get($entry, 'committed', 0) > 0) {
                return;
            }
        }

        // prev_order's items were loaded before the retry rewrote the rows, so they still
        // name the variations the ledger was written for.
        $held = static::heldUnitsByVariation($prevOrder->order_items, $stockMovement);

        $wanted = [];
        $itemIds = [];
        $items = OrderItem::query()->where('order_id', $order->id)->get();
        foreach ($items as $item) {
            if (in_array($item->payment_type, ['signup_fee', 'fee'])) {
                continue;
            }
            $wanted[$item->object_id] = Arr::get($wanted, $item->object_id, 0) + (int)$item->quantity;
            if (!isset($itemIds[$item->object_id])) {
                $itemIds[$item->object_id] = $item->id;
            }
        }

        $variationIds = array_unique(array_merge(array_keys($held), array_keys($wanted)));
        if (!$variationIds) {
            return;
        }

        $variations = ProductVariation::query()
            ->select('id', 'post_id', 'available', 'committed', 'on_hold', 'manage_stock', 'other_info')
            ->with('product_detail')
            ->whereIn('id', $variationIds)
            ->where('manage_stock', 1)
            ->get();

        $newMovement = [];
        $updatedVariants = [];
        $affectedProductIds = [];

        foreach ($variations as $variation) {
            $quantity = (int)Arr::get($wanted, $variation->id, 0);
            if ($quantity > 0) {
                $newMovement[$itemIds[$variation->id]] = ['on_hold' => $quantity];
            }

            $delta = $quantity - (int)Arr::get($held, $variation->id, 0);
            if ($delta === 0) {
                continue;
            }

            $holdTargets = [$variation];
            if ($variation->product_detail && Arr::get($variation->product_detail->other_info, 'is_bundle_product') === 'yes') {
                foreach ($variation->bundleChildren()->get() as $child) {
                    if ((int)$child->manage_stock === 1) {
                        $holdTargets[] = $child;
                    }
                }
            }

            foreach ($holdTargets as $target) {
                $newAvailable = (int)$target->available - $delta;
                $updatedVariants[] = [
                    'id'           => $target->id,
                    'on_hold'      => [$delta > 0 ? '+' : '-', abs($delta)],
                    'available'    => $newAvailable <= 0 ? 0 : $newAvailable,
                    'stock_status' => $newAvailable <= 0 ? 'out-of-stock' : 'in-stock',
                ];
                $affectedProductIds[] = $target->post_id;
            }
        }

        if ($updatedVariants) {
            ProductVariation::query()->batchUpdate($updatedVariants);
        }

        $this->syncProductAvailability($affectedProductIds);

        OrderMeta::updateOrCreate(
            [
                'order_id' => $order->id,
                'meta_key' => 'stock_movement',
            ],
            [
                'meta_value' => json_encode($newMovement),
            ]
        );
    }

    private function syncProductAvailability($productIds)
    {
        $updatedProducts = [];
        foreach (array_unique($productIds) as $productId) {
            $hasInStock = ProductVariation::query()
                ->where('post_id', $productId)
                ->where('stock_status', 'in-stock')
                ->exists();

            $detail = ProductDetail::query()->where('post_id', $productId)->select('id')->first();

            if (!$detail || !$detail->id) {
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
            return !in_array($item->payment_type, ['signup_fee', 'fee']);
        });

        $pluckVariationIds = $orderItems->pluck('object_id')->toArray();
        $orderItems = $orderItems->keyBy('object_id');

        if (empty($pluckVariationIds)) {
            return;
        }

        $variations = ProductVariation::query()
            ->select('id', 'post_id', 'available', 'committed', 'on_hold', 'manage_stock', 'fulfillment_type', 'other_info')
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
            $orderItemId = $orderItems->get($variation->id)->id;
            $quantity = (int) $orderItems->get($variation->id)->unitsLeft();

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

            // Move only these units; the record may still hold refunded units the restock has yet to release.
            $recordedOnHold = (int) Arr::get($stockMovement, $orderItemId . '.on_hold', 0);
            $recordedCommitted = (int) Arr::get($stockMovement, $orderItemId . '.committed', 0);

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
                    'committed' => $recordedCommitted + $quantity,
                    'on_hold'   => max(0, $recordedOnHold - $quantity),
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
                    'committed' => max(0, $recordedCommitted - $quantity),
                    'on_hold'   => $recordedOnHold + $quantity,
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
            return !in_array($item->payment_type, ['signup_fee', 'fee']);
        });

        $pluckVariationIds = $orderItems->pluck('object_id')->toArray();
        $orderItems = $orderItems->keyBy('object_id');
        if (empty($pluckVariationIds)) {
            return;
        }

        // Get existing meta for this order
        $existingMeta = OrderMeta::where('order_id', $order->id)
            ->where('meta_key', 'stock_movement')
            ->value('meta_value');

        $stockMovement = $existingMeta ? $existingMeta : [];

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
        $updatedVariants = [];
        $affectedProductIds = [];

        // get shipping_status from $order
        $shippingStatus = $order->shipping_status;

        foreach ($variations as $item) {
            $orderItemId = $orderItems->get($item->id)->id;
            $oldOnHold = (int)Arr::get($stockMovement, $orderItemId . '.on_hold', 0);
            $oldCommitted = (int)Arr::get($stockMovement, $orderItemId . '.committed', 0);

            $quantity = (int) $orderItems->get($item->id)->unitsLeft();
            // Never return more than this order recorded taking off the shelf.
            if (is_array($stockMovement) && array_key_exists($orderItemId, $stockMovement)) {
                $quantity = min($quantity, $oldOnHold + $oldCommitted);
            }

            if ($quantity <= 0) {
                continue;
            }

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
                }

                if ($place === 'committed' && $oldCommitted > 0) {
                    $remove = min($oldCommitted, $remainingRefund);
                    $removedCommitted = $remove;
                    $remainingRefund -= $remove;

                    $stockMovement[$orderItemId]['committed'] = $oldCommitted - $remove;
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

            if ($detail) {
                $updatedProducts[] = [
                    'id'                 => $detail->id,
                    'stock_availability' => $hasInStock ? 'in-stock' : 'out-of-stock'
                ];
            }
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
        $manageStock = (bool) Arr::get($data, 'manage_stock', false);

        $order = Arr::get($data, 'order');
        // Cancelling already returned every unit not refunded before it.
        if ($order->status === Status::ORDER_CANCELED) {
            return;
        }

        $refundedItems = Arr::get($data, 'new_refunded_items');

        $pluckVariationIds = [];
        $mappedRestockItems = [];
        foreach ($refundedItems as $orderItem) {
            // A signup-fee row shares its parent's variation and restores 0; mapping it would overwrite the parent.
            if ((int) Arr::get($orderItem, 'restore_quantity', 0) <= 0 && (int) Arr::get($orderItem, 'shipped_qty', 0) <= 0) {
                continue;
            }
            // Without restock, only unshipped physical units are released from the hold.
            if (!$manageStock && !Arr::has($orderItem, 'shipped_qty')) {
                continue;
            }

            $pluckVariationIds[] = $orderItem['variation_id'];
            $mappedRestockItems[$orderItem['variation_id']] = [
                'quantity'     => $orderItem['restore_quantity'],
                'id'           => $orderItem['id'],
                'restock_type' => Arr::get($orderItem, 'restock_type', null),
                'shipped_qty' => Arr::get($orderItem, 'shipped_qty', null),
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

            $oldOnHold = (int)Arr::get($stockMovement, $orderItemId . '.on_hold', 0);
            $oldCommitted = (int)Arr::get($stockMovement, $orderItemId . '.committed', 0);

            $quantity = (int)Arr::get($mappedRestockItems, $item->id . '.quantity', 0);
            // Never return more than this order recorded taking off the shelf.
            if (is_array($stockMovement) && array_key_exists($orderItemId, $stockMovement)) {
                $quantity = min($quantity, $oldOnHold + $oldCommitted);
            }

            $restockType = Arr::get($mappedRestockItems, $item->id . '.restock_type', null);
            $shippedQty = Arr::get($mappedRestockItems, $item->id . '.shipped_qty', null);

            if ($quantity <= 0 && (int) $shippedQty <= 0) {
                continue;
            }

            if ($restockType === 'return' && $shippedQty !== null) {
                // Shipped units were committed; the unshipped rest are still on hold.
                $shippedQty = min((int) $shippedQty, $quantity);
                $removalSteps = [
                    [$shippedQty, ['committed', 'on_hold']],
                    [$quantity - $shippedQty, ['on_hold', 'committed']],
                ];
            } elseif ($restockType === 'return') {
                $removalSteps = [[$quantity, ['committed', 'on_hold']]];
            } elseif ($restockType === 'cancel') {
                $removalSteps = [[$quantity, ['on_hold', 'committed']]];
            } elseif ($shippingStatus === 'delivered' || ($item->fulfillment_type === 'digital' && $order->payment_status === 'paid')) {
                $removalSteps = [[$quantity, ['committed', 'on_hold']]];
            } else {
                $removalSteps = [[$quantity, ['on_hold', 'committed']]];
            }

            [$removedOnHold, $removedCommitted] = self::drainStock($oldOnHold, $oldCommitted, $removalSteps);

            // Shipped units have left the store, so the hold they still sit in becomes committed.
            $toCommit = $restockType === 'cancel' ? min(max(0, (int) $shippedQty), $oldOnHold - $removedOnHold) : 0;

            if ($removedOnHold > 0 || $toCommit > 0) {
                $stockMovement[$orderItemId]['on_hold'] = $oldOnHold - $removedOnHold - $toCommit;
            }
            if ($removedCommitted > 0 || $toCommit > 0) {
                $stockMovement[$orderItemId]['committed'] = $oldCommitted - $removedCommitted + $toCommit;
            }

            $restock = $manageStock && $quantity > 0;
            if (!$restock && !$removedOnHold && !$removedCommitted && !$toCommit) {
                continue;
            }

            $newAvailable = $item->available + $quantity;

            $restockedItems[$item->id] = $quantity;

            $update = ['id' => $item->id];
            if ($restock) {
                $update['available'] = $newAvailable <= 0 ? 0 : $newAvailable;
                $update['stock_status'] = $newAvailable <= 0 ? 'out-of-stock' : 'in-stock';
            }

            if ($removedOnHold + $toCommit > 0) {
                $update['on_hold'] = ['-', $removedOnHold + $toCommit];
            }
            if ($toCommit !== $removedCommitted) {
                $update['committed'] = $toCommit > $removedCommitted ? ['+', $toCommit - $removedCommitted] : ['-', $removedCommitted - $toCommit];
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

                        [$childRemovedOnHold, $childRemovedCommitted] = self::drainStock((int)$child->on_hold, (int)$child->committed, $removalSteps);
                        $childToCommit = min($toCommit, (int)$child->on_hold - $childRemovedOnHold);

                        if (!$restock && !$childRemovedOnHold && !$childRemovedCommitted && !$childToCommit) {
                            continue;
                        }

                        $childAvailable = (int)$child->available + $quantity;

                        $childUpdate = ['id' => $child->id];
                        if ($restock) {
                            $childUpdate['available'] = $childAvailable <= 0 ? 0 : $childAvailable;
                            $childUpdate['stock_status'] = $childAvailable <= 0 ? 'out-of-stock' : 'in-stock';
                        }
                        if ($childRemovedOnHold + $childToCommit > 0) {
                            $childUpdate['on_hold'] = ['-', $childRemovedOnHold + $childToCommit];
                        }
                        if ($childToCommit !== $childRemovedCommitted) {
                            $childUpdate['committed'] = $childToCommit > $childRemovedCommitted ? ['+', $childToCommit - $childRemovedCommitted] : ['-', $childRemovedCommitted - $childToCommit];
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

            if ($detail) {
                $updatedProducts[] = [
                    'id'                 => $detail->id,
                    'stock_availability' => $hasInStock ? 'in-stock' : 'out-of-stock'
                ];
            }
        }

        if (!empty($updatedProducts)) {
            ProductDetail::query()->batchUpdate($updatedProducts);
        }
    }

    /**
     * A refund counted these units as shipped. The shipment is gone, so they never left:
     * release them from the order without restocking; the admin counts them back by hand.
     * Runs inside the shipment delete transaction, so a failure rolls the delete back.
     */
    public function releaseUnshippedRefunds($order, array $unshippedRefunds)
    {
        $unshippedRefunds = array_filter($unshippedRefunds);
        if (!$unshippedRefunds) {
            return;
        }

        $stockMovement = OrderMeta::where('order_id', $order->id)
            ->where('meta_key', 'stock_movement')
            ->value('meta_value');
        if (!is_array($stockMovement)) {
            return;
        }

        $orderItems = OrderItem::query()
            ->where('order_id', $order->id)
            ->whereIn('id', array_keys($unshippedRefunds))
            ->get();

        $updatedVariants = [];
        foreach ($orderItems as $orderItem) {
            if (!array_key_exists($orderItem->id, $stockMovement)) {
                continue;
            }

            $variation = ProductVariation::query()
                ->with('product_detail')
                ->where('id', $orderItem->object_id)
                ->where('manage_stock', 1)
                ->first();
            if (!$variation) {
                continue;
            }

            $oldOnHold = (int) Arr::get($stockMovement, $orderItem->id . '.on_hold', 0);
            $oldCommitted = (int) Arr::get($stockMovement, $orderItem->id . '.committed', 0);
            $steps = [[(int) $unshippedRefunds[$orderItem->id], ['committed', 'on_hold']]];

            [$removedOnHold, $removedCommitted] = self::drainStock($oldOnHold, $oldCommitted, $steps);
            if (!$removedOnHold && !$removedCommitted) {
                continue;
            }

            $stockMovement[$orderItem->id]['on_hold'] = $oldOnHold - $removedOnHold;
            $stockMovement[$orderItem->id]['committed'] = $oldCommitted - $removedCommitted;
            $updatedVariants[] = self::releaseUpdate($variation->id, $removedOnHold, $removedCommitted);
            $childSteps = [
                [$removedCommitted, ['committed', 'on_hold']],
                [$removedOnHold, ['on_hold', 'committed']],
            ];

            if ($variation->product_detail && Arr::get($variation->product_detail->other_info, 'is_bundle_product') === 'yes') {
                foreach ($variation->bundleChildren()->get() as $child) {
                    if ((int) $child->manage_stock !== 1) {
                        continue;
                    }
                    [$childOnHold, $childCommitted] = self::drainStock((int) $child->on_hold, (int) $child->committed, $childSteps);
                    if ($childOnHold || $childCommitted) {
                        $updatedVariants[] = self::releaseUpdate($child->id, $childOnHold, $childCommitted);
                    }
                }
            }
        }

        if (!$updatedVariants) {
            return;
        }

        // Batch writes through raw $wpdb and never throws; the caller's rollback needs one.
        if (ProductVariation::query()->batchUpdate($updatedVariants) === false) {
            throw new \RuntimeException('Stock release failed.');
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

    private static function releaseUpdate($variationId, $onHold, $committed): array
    {
        $update = ['id' => $variationId];
        if ($onHold > 0) {
            $update['on_hold'] = ['-', $onHold];
        }
        if ($committed > 0) {
            $update['committed'] = ['-', $committed];
        }

        return $update;
    }

    /**
     * Units to take off on_hold and committed, step by step: each step is [units, places in order].
     *
     * @return int[] [removed on_hold, removed committed]
     */
    private static function drainStock($onHold, $committed, array $steps): array
    {
        $removed = ['on_hold' => 0, 'committed' => 0];
        $left = ['on_hold' => $onHold, 'committed' => $committed];

        foreach ($steps as $step) {
            [$units, $places] = $step;
            foreach ($places as $place) {
                $take = max(0, min($left[$place], $units));
                $removed[$place] += $take;
                $left[$place] -= $take;
                $units -= $take;
            }
        }

        return [$removed['on_hold'], $removed['committed']];
    }

    public function manageStockOnOrderPaid($event)
    {
        $order = Arr::get($event, 'order');
        $orderItems = $order->order_items;

        if (!$order || !$orderItems) {
            return;
        }

        $orderItems = $orderItems->filter(function ($item) {
            return !in_array($item->payment_type, ['signup_fee', 'fee']);
        });

        $pluckVariationIds = $orderItems->pluck('object_id')->toArray();
        $orderItems = $orderItems->keyBy('object_id')->toArray();

        if (empty($pluckVariationIds)) {
            return;
        }

        $variations = ProductVariation::query()
            ->select('id', 'post_id', 'available', 'committed', 'on_hold', 'manage_stock', 'fulfillment_type', 'other_info')
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
            return !in_array($item->payment_type, ['signup_fee', 'fee']);
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
                ->select('id', 'post_id', 'available', 'on_hold', 'committed', 'manage_stock', 'fulfillment_type', 'other_info')
                ->with('product_detail')
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

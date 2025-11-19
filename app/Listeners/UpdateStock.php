<?php

namespace FluentCart\App\Listeners;

use FluentCart\App\Events\Order\OrderCreated as OrderCreatedEvent;
use FluentCart\App\Events\Order\OrderDeleted as OrderDeletedEvent;
use FluentCart\App\Events\Order\OrderUpdated as OrderUpdatedEvent;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use FluentCart\Framework\Support\Arr;

class UpdateStock
{
    /**
     * @param $event OrderUpdatedEvent | OrderCreatedEvent | OrderDeletedEvent
     */

    public static function handle($event)
    {

        $orderItems = Arr::get($event->order, 'order_items', []);
        $orderItems = $orderItems->toArray();
        $pluckVariationIds = array_column($orderItems, 'object_id');
        $fetchProductVariation = ProductVariation::query()->select('id', 'post_id', 'total_stock', 'available', 'committed', 'on_hold', 'manage_stock')->with('product_detail')->whereIn('id', $pluckVariationIds)->get()->keyBy('id');
        $shouldUpdateStock = true;
        $action = '';
        $status = '';

        $productVariants = [];
        if ($event->hook === 'fluent_cart/order_updated') {
            $status = $event->order->shipping_status;
            if ($status === 'Status::SHIPPING_UNSHIPPABLE') {
                return;
            }
            $oldOrderItems = Arr::get($event->oldOrder, 'order_items', []);
            $oldOrderItems = $oldOrderItems->toArray();

            $pluckOldVariationIds = array_column($oldOrderItems, 'object_id');
            $fetchOldProductVariation = ProductVariation::query()->select('id', 'post_id', 'total_stock', 'available', 'committed', 'on_hold', 'manage_stock')->with('product_detail')->whereIn('id', $pluckOldVariationIds)->get()->keyBy('id');


            $orderItemLists = [];

            foreach ($oldOrderItems as $index => $oldItem) {

                $oldItem['variants'] = $fetchOldProductVariation[$oldItem['object_id']]->toArray();
                if (!in_array($oldItem['object_id'], $pluckVariationIds)) {
                    $orderItemLists['deletedOrderItems'][] = $oldItem;
                } else {
                    $findVariant = array_search($oldItem['object_id'], array_column($orderItems, 'object_id'));
                    if ($findVariant !== false) {
                        $oldItem['new_quantity'] = $orderItems[$findVariant]['quantity'];
                    }
                    $orderItemLists['existingOrderItems'][] = $oldItem;
                }
            }

            foreach ($orderItems as $index => $item) {
                if (!in_array($item['object_id'], $pluckOldVariationIds)) {
                    $item['variants'] = $fetchProductVariation[$item['object_id']]->toArray();
                    $orderItemLists['newOrderItems'][] = $item;
                }
            }

            $productVariants = [];

            foreach ($orderItemLists as $key => $orderItemList) {
                $action = ($key === 'deletedOrderItems') ? 'delete' : (($key === 'newOrderItems') ? 'add' : 'edit');

                foreach ($orderItemList as $item) {
                    $prepared = self::prepareProductVariantsArray($item, $action, $status);
                    if (!empty($prepared) && $prepared != null) {
                        $productVariants[] = $prepared;
                    }
                }
            }
        } else {
            if ($event->hook === 'fluent_cart/order_created') {
                $status = $event->order->shipping_status;
            }

            if ($event->hook === 'fluent_cart/order_status_updated') {

                if ($event->manageStock != true) {
                    $shouldUpdateStock = false;
                }

                // if($event->oldStatus === 'unshipped' && $event->newStatus === 'unshippable') {
                //     $status = 'unshipped';
                //     $action = 'delete';
                // }
                // if(($event->oldStatus === 'shipped' || $event->oldStatus === 'delivered') && $event->newStatus === 'unshippable') {
                //     $status = 'shipped';
                //     $action = 'delete';
                // }
                // if($event->oldStatus === 'unshippable' && $event->newStatus === 'unshipped') {
                //     $status = 'unshipped';
                //     $action = 'add';
                // }
                // if($event->oldStatus === 'unshippable' && ($event->newStatus === 'shipped' || $event->newStatus === 'delivered')) {
                //     $status = 'shipped';
                //     $action = 'add';
                // }
                if ($event->oldStatus === Status::SHIPPING_UNSHIPPED && $event->newStatus === Status::ORDER_CANCELED) {
                    $status = Status::SHIPPING_UNSHIPPED;
                    $action = 'delete';
                }
                if (($event->oldStatus === 'shipped' || $event->oldStatus === Status::SHIPPING_DELIVERED) && $event->newStatus === Status::ORDER_CANCELED) {
                    $status = 'shipped';
                    $action = 'delete';
                }
                // if($event->oldStatus === 'unshipped' && $event->newStatus === 'unshipped') {
                //     $status = 'unshipped';
                // }
                if ($event->oldStatus === Status::SHIPPING_UNSHIPPED && ($event->newStatus === 'shipped' || $event->newStatus === Status::SHIPPING_DELIVERED)) {
                    $status = Status::SHIPPING_SHIPPED;
                }
                if (($event->oldStatus === 'shipped' || $event->oldStatus === Status::SHIPPING_DELIVERED) && $event->newStatus === Status::SHIPPING_UNSHIPPED) {
                    $status = 'restore';
                }
            }

            if ($event->hook === 'fluent_cart/order_refunded') {
                // $shouldUpdateStock = $event->manageStock == 'true' ? true : false;

                if ($event->manageStock !== true) {
                    $shouldUpdateStock = false;
                } else {
                    $action = 'refund';
                    $status = 'refunded';
                }
            }

            // if($event->hook === 'fluent_cart/order_deleted') {
            //     if($event->order->shipping_status === 'unshippable') {
            //         return;
            //     }
            //     $action = 'delete';
            //     $status = $event->order->shipping_status;
            // }

            if ($shouldUpdateStock === true) {
                foreach ($orderItems as $index => $orderItem) {
                    if (!isset($fetchProductVariation[$orderItem['object_id']])) {
                        continue;
                    }
                    $orderItem['variants'] = $fetchProductVariation[$orderItem['object_id']]->toArray();
                    $prepared = self::prepareProductVariantsArray($orderItem, $action, $status);
                    if (!empty($prepared) && $prepared != null) {
                        $productVariants[] = $prepared;
                    }
                }
            }
        }

        if (!empty($productVariants) && $shouldUpdateStock === true) {
            ProductVariation::query()->batchUpdate($productVariants);
        }

        $productIds = $event->order['order_items']->pluck('post_id')->toArray();
        $products = Product::query()
            ->whereIn('ID', $productIds)
            ->whereHas('variants', function ($query) {
                $query->where('manage_stock', 1)
                    ->where('available', '>', 0);
            })->get()->pluck('ID');

        $productDetails = ProductDetail::query()->whereIn('post_id', $products)->get()->keyBy('post_id');
        $detailsData = [];
        foreach ($productDetails as $productDetail) {
            $detailsData[] = [
                'id'                 => $productDetail->id,
                'stock_availability' => Helper::IN_STOCK
            ];
        }

        ProductDetail::batchUpdate($detailsData);


    }

    private static function shouldSkipManageStock($variation): bool
    {
        // $manageStock = Arr::get($variation, 'product_detail.manage_stock', 0);
        $manageStock = Arr::get($variation, 'manage_stock', 0);

        return $manageStock == 0;
    }

    private static function prepareProductVariantsArray($item, $action, $status)
    {
        $itemId = Arr::get($item, 'object_id', null);
        $quantity = (int)Arr::get($item, 'quantity', 0);
        $newQuantity = (int)Arr::get($item, 'new_quantity', 0);

        if (!empty($itemId) && !empty($quantity)) {
            $productVariation = Arr::get($item, 'variants', null);
            $shouldSkipManageStock = static::shouldSkipManageStock($productVariation);

            $available = (int)Arr::get($productVariation, 'available', 0);
            $totalStock = (int)Arr::get($productVariation, 'total_stock', 0);
            $committed = (int)Arr::get($productVariation, 'committed', 0);
            $onHold = (int)Arr::get($productVariation, 'on_hold', 0);

            if (!empty($productVariation) && $shouldSkipManageStock == false) {
                if ($action === '') {
                    if ($status === Status::SHIPPING_UNSHIPPED) {
                        $available = ($totalStock - $committed - ($onHold + $quantity));
                        return [
                            'id'           => $itemId,
                            'stock_status' => $available > 0 ? Helper::IN_STOCK : Helper::OUT_OF_STOCK,
                            'on_hold'      => ['+', $quantity],
                            'available'    => $available,
                        ];
                    }
                    if ($status === Status::SHIPPING_SHIPPED || $status === Status::SHIPPING_DELIVERED) {
                        return [
                            'id'        => $itemId,
                            'on_hold'   => ['-', $quantity],
                            'committed' => ['+', $quantity],
                        ];
                    }
                    if ($status === 'restore') {
                        return [
                            'id'        => $itemId,
                            'on_hold'   => ['+', $quantity],
                            'committed' => ['-', $quantity],
                        ];
                    }
                } else {
                    $operation = ($action === 'add' ? '+' : ($action === 'delete' ? '-' : ''));
                    $column = ($status === Status::SHIPPING_UNSHIPPED) ? 'on_hold' : 'committed';
                    if ($action === 'edit') {
                        $updatedQuantity = 0;
                        $operation = '+';
                        if ($newQuantity > $quantity) {
                            $updatedQuantity = $newQuantity - $quantity;
                            $operation = '+';
                        } else if ($newQuantity < $quantity) {
                            $updatedQuantity = $quantity - $newQuantity;
                            $operation = '-';
                        }
                        $quantity = $updatedQuantity;
                    }

                    if ($action === 'refund') {
                        return [
                            'id'           => $itemId,
                            'committed'    => ['-', $quantity],
                            'available'    => $available + $quantity,
                            'stock_status' => ($available + $quantity) > 0 ? Helper::IN_STOCK : Helper::OUT_OF_STOCK,
                        ];
                    }

                    $available = ($status === Status::SHIPPING_UNSHIPPED) ? ($totalStock - $committed - ($onHold + ($operation === '+' ? $quantity : -$quantity))) : ($totalStock - ($committed + ($operation === '+' ? $quantity : -$quantity)) - $onHold);

                    if ($quantity != null) {
                        return [
                            'id'           => $itemId,
                            'stock_status' => $available > 0 ? Helper::IN_STOCK : Helper::OUT_OF_STOCK,
                            $column        => [$operation, $quantity],
                            'available'    => $available,
                        ];
                    }
                }
            }
        }

        return null;
    }
}

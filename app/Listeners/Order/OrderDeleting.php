<?php

namespace FluentCart\App\Listeners\Order;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Events\Order\OrderDeleting as OrderDeletingEvent;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use FluentCart\Framework\Support\Arr;

class OrderDeleting
{
    public static function handle(OrderDeletingEvent $event)
    {
        if (!ModuleSettings::isActive('stock_management')) {
            return;
        }

        $orderIds = array_filter(array_map('intval', $event->connectedOrderIds));
        if (!$event->order || empty($orderIds)) {
            return;
        }

        if (!static::shouldRestoreStockOnOrderDelete($event)) {
            return;
        }

        $orders = Order::query()
            ->whereIn('id', $orderIds)
            ->with('order_items')
            ->get();

        if ($orders->isEmpty()) {
            return;
        }

        $stockMetaByOrderId = OrderMeta::query()
            ->whereIn('order_id', $orderIds)
            ->where('meta_key', 'stock_movement')
            ->get()
            ->keyBy('order_id');

        $restoreMap = static::buildDeleteRestoreMap($orders, $stockMetaByOrderId);
        if (empty($restoreMap)) {
            return;
        }

        $variations = ProductVariation::query()
            ->select('id', 'post_id', 'available', 'committed', 'on_hold', 'manage_stock', 'other_info')
            ->with('product_detail')
            ->whereIn('id', array_keys($restoreMap))
            ->where('manage_stock', 1)
            ->get();

        if ($variations->isEmpty()) {
            return;
        }

        $updatedVariants = [];
        $affectedProductIds = [];

        foreach ($variations as $variation) {
            $restoreQty = (int)Arr::get($restoreMap, $variation->id . '.quantity', 0);
            $variantUpdate = static::buildDeleteRestoreVariantUpdate($variation, $restoreQty);
            if (empty($variantUpdate)) {
                continue;
            }

            $updatedVariants[] = $variantUpdate;
            $affectedProductIds[] = (int)$variation->post_id;

            static::appendBundleChildRestoreUpdates($updatedVariants, $affectedProductIds, $variation, $restoreQty);
        }

        if (empty($updatedVariants)) {
            return;
        }

        ProductVariation::query()->batchUpdate($updatedVariants);

        static::updateProductAvailabilityFromVariationStock($affectedProductIds);
    }

    protected static function shouldRestoreStockOnOrderDelete(OrderDeletingEvent $event): bool
    {
        return apply_filters(
            'fluent_cart/order/should_restore_stock_on_delete',
            $event->isTestMode === true,
            $event->order,
            $event->toArray()
        );
    }

    protected static function buildDeleteRestoreMap($orders, $stockMetaByOrderId): array
    {
        $restoreMap = [];

        foreach ($orders as $deletingOrder) {
            $orderItems = $deletingOrder->order_items->filter(function ($item) {
                return !in_array($item->payment_type, ['signup_fee', 'fee']);
            });

            if ($orderItems->isEmpty()) {
                continue;
            }

            $stockMovement = Arr::get($stockMetaByOrderId, $deletingOrder->id . '.meta_value', []);
            if (!is_array($stockMovement) || empty($stockMovement)) {
                continue;
            }

            foreach ($orderItems as $orderItem) {
                $movement = Arr::get($stockMovement, $orderItem->id, []);
                if (!is_array($movement) || empty($movement)) {
                    continue;
                }

                $restoreQty = (int)Arr::get($movement, 'on_hold', 0) + (int)Arr::get($movement, 'committed', 0);
                $variationId = (int)$orderItem->object_id;

                if ($restoreQty <= 0 || !$variationId) {
                    continue;
                }

                if (!isset($restoreMap[$variationId])) {
                    $restoreMap[$variationId] = [
                        'quantity' => 0,
                        'post_id'  => (int)$orderItem->post_id
                    ];
                }

                $restoreMap[$variationId]['quantity'] += $restoreQty;
            }
        }

        return $restoreMap;
    }

    protected static function buildDeleteRestoreVariantUpdate($variation, int $restoreQty): array
    {
        if ($restoreQty <= 0) {
            return [];
        }

        $removedOnHold = min((int)$variation->on_hold, $restoreQty);
        $removedCommitted = min((int)$variation->committed, max(0, $restoreQty - $removedOnHold));
        $newAvailable = (int)$variation->available + $removedOnHold + $removedCommitted;

        $update = [
            'id'           => $variation->id,
            'available'    => $newAvailable <= 0 ? 0 : $newAvailable,
            'stock_status' => $newAvailable <= 0 ? Helper::OUT_OF_STOCK : Helper::IN_STOCK,
        ];

        if ($removedOnHold > 0) {
            $update['on_hold'] = ['-', $removedOnHold];
        }

        if ($removedCommitted > 0) {
            $update['committed'] = ['-', $removedCommitted];
        }

        return $update;
    }

    protected static function appendBundleChildRestoreUpdates(array &$updatedVariants, array &$affectedProductIds, $variation, int $restoreQty): void
    {
        if (!$variation->product_detail || Arr::get($variation->product_detail->other_info, 'is_bundle_product') !== 'yes') {
            return;
        }

        $childVariations = $variation->bundleChildren()->get();
        if (!$childVariations || !$childVariations->count()) {
            return;
        }

        foreach ($childVariations as $child) {
            if ((int)$child->manage_stock !== 1) {
                continue;
            }

            $childUpdate = static::buildDeleteRestoreVariantUpdate($child, $restoreQty);
            if (empty($childUpdate)) {
                continue;
            }

            $updatedVariants[] = $childUpdate;
            $affectedProductIds[] = (int)$child->post_id;
        }
    }

    protected static function updateProductAvailabilityFromVariationStock(array $affectedProductIds): void
    {
        $affectedProductIds = array_values(array_unique(array_filter($affectedProductIds)));
        if (empty($affectedProductIds)) {
            return;
        }

        $updatedProducts = [];
        foreach ($affectedProductIds as $productId) {
            $hasInStock = ProductVariation::query()
                ->where('post_id', $productId)
                ->where('stock_status', Helper::IN_STOCK)
                ->exists();

            $detail = ProductDetail::query()->where('post_id', $productId)->select('id')->first();
            if (!$detail || !$detail->id) {
                continue;
            }

            $updatedProducts[] = [
                'id'                 => $detail->id,
                'stock_availability' => $hasInStock ? Helper::IN_STOCK : Helper::OUT_OF_STOCK
            ];
        }

        if (!empty($updatedProducts)) {
            ProductDetail::query()->batchUpdate($updatedProducts);
        }
    }
}

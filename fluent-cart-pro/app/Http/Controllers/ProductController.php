<?php

namespace FluentCartPro\App\Http\Controllers;

use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use FluentCart\Framework\Http\Request\Request;
use FluentCartPro\App\Models\User;

class ProductController extends Controller
{
    public function updateInventory(Request $request, $postId, $variantId)
    {

        $variant = ProductVariation::query()->find($variantId);

        if (!$variant) {
            return $this->response->sendError([
                'message' => __('Variant not found', 'fluent-cart-pro')
            ]);
        }

        $detail = ProductDetail::query()->where('post_id', $postId)->first();

        // get variations by post_id
        $variations = ProductVariation::query()->where('post_id', $postId)->where('id', '!=', $variantId)->get();
        $updateData = [];
        foreach ($variations as $variation) {
            $updateData[] = [
                'id'           => $variation->id,
                'manage_stock' => 1,
                'total_stock'  => $variation->total_stock,
                'available'    => $variation->available,
                'stock_status' => $variation->stock_status
            ];
        }
        $updateData[] = [
            'id'          => $variantId,
            'total_stock' => sanitize_text_field($request->get('total_stock')),
            'available'   => sanitize_text_field($request->get('available')),
            'manage_stock' => 1,
            'stock_status' => $request->get('available') > 0 ? 'in-stock' : 'out-of-stock'
        ];
        // update variations
        $isUpdated = ProductVariation::query()->batchUpdate($updateData);


        if ($detail) {
            $hasAvailableStock = ProductVariation::query()->where('post_id', $postId)->where('available', '>', 0)->exists();
            $detail->stock_availability = $hasAvailableStock ? 'in-stock' : 'out-of-stock';
            $detail->manage_stock = 1;
            $detail->save();
        }


        if (is_wp_error($isUpdated)) {
            return $this->response->sendError([
                'message' => __('Inventory update failed', 'fluent-cart-pro')
            ]);
        }

        return $this->response->sendSuccess([
            'message' => __('Inventory updated successfully', 'fluent-cart-pro')
        ]);
    }

    public function updateManageStock(Request $request, $postId)
    {
        $manageStock = sanitize_text_field($request->get('manage_stock'));

        $detail = ProductDetail::query()->where('post_id', $postId)->first();

        $updateData = [
            'manage_stock' => $manageStock,
//            'total_stock'  => 1,
//            'available'    => 1
        ];
        if ($manageStock == 0) {
            $updateData['stock_status'] = 'in-stock';
        }

        $updatedVariations = ProductVariation::query()->where('post_id', $postId)->update($updateData);

        $hasAvailableStock = ProductVariation::query()->where('post_id', $postId)->where('available', '>', 0)->exists();
        $detail->manage_stock = $manageStock;
        $detail->stock_availability = $hasAvailableStock || $manageStock == 0 ? 'in-stock' : 'out-of-stock';
        $updatedProductDetails = $detail->save();

        if (is_wp_error($updatedProductDetails)) {
            return $this->response->sendError([
                'message' => __('Manage stock update failed', 'fluent-cart-pro')
            ]);
        }

        if (is_wp_error($updatedVariations)) {
            return $this->response->sendError([
                'message' => __('Manage stock update failed', 'fluent-cart-pro')
            ]);
        }

        return $this->response->sendSuccess([
            'message' => __('Manage stock updated successfully', 'fluent-cart-pro')
        ]);
    }
}

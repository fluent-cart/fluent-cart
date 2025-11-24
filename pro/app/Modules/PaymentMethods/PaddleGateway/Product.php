<?php

namespace FluentCartPro\App\Modules\PaymentMethods\PaddleGateway;

use FluentCart\Framework\Support\Arr;
use FluentCartPro\App\Modules\PaymentMethods\PaddleGateway\API\API;

class Product
{
    /**
     * Create Paddle product for order item
     */
    public static function createOrGetPaddleProduct($data = [])
    {
        $fctProductId = Arr::get($data, 'fct_product_id');
        $fctProduct   = \FluentCart\App\Models\Product::find($fctProductId);

        $productId = Arr::get($data, 'product_id');
        $mode = Arr::get($data, 'mode', 'live');
        $type = Arr::get($data, 'type', 'standard');


        $paddleProductId = $fctProduct->getProductMeta($productId);

        if ($paddleProductId && $type === 'standard') {
            $paddleProduct = API::getPaddleObject("products/{$paddleProductId}", [], $mode);

            if (!is_wp_error($paddleProduct) && Arr::get($paddleProduct, 'data.status') == 'active') {
                return $paddleProduct;
            }
        }

        if (strlen(Arr::get($data, 'name')) > 145) {
            $data['name'] = substr(Arr::get($data, 'name'), 0, 145) . '...';
        }

        $productData = [
            'name' => Arr::get($data, 'name'),
            'type' => Arr::get($data, 'type'),
            'tax_category' => apply_filters('fluent_cart/paddle_product_tax_category', 'standard', [
                'product' => $fctProduct,
                'variation_id' => Arr::get($data, 'variation_id')
            ])
        ];

        $paddleProduct =  API::createPaddleObject('products', $productData, $mode);

        if (is_wp_error($paddleProduct)) {
            return $paddleProduct;
        }

        if ($type === 'standard') {
            $fctProduct->updateProductMeta($productId, Arr::get($paddleProduct, 'data.id'));
        }
        return $paddleProduct;
    }

    public static function getOrCreateAddOnProduct($data)
    {
        $productId = Arr::get($data, 'product_id');
        $paddleProductId = (string) fluent_cart_get_option($productId);
        $type = Arr::get($data, 'type', 'custom');

        if ($paddleProductId && $type === 'standard') {
            $paddleProduct = API::getPaddleObject("products/{$paddleProductId}", [], $data['mode']);
            if (!is_wp_error($paddleProduct) && Arr::get($paddleProduct, 'data.status') == 'active') {
                return $paddleProduct;
            }
        }


        if (strlen(Arr::get($data, 'name')) > 145) {
            $data['name'] = substr(Arr::get($data, 'name'), 0, 145) . '...';
        }

        $productData = [
            'name' => Arr::get($data, 'name'),
            'type' => Arr::get($data, 'type'),
            'tax_category' => 'standard',
        ];

        $paddleProduct =  API::createPaddleObject('products', $productData, $data['mode']);

        if (is_wp_error($paddleProduct)) {
            return $paddleProduct;
        }

        if ($type === 'standard') {
            fluent_cart_update_option($productId, Arr::get($paddleProduct, 'data.id'));
        }
        return $paddleProduct;
    }
}
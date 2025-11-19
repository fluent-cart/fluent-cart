<?php

namespace FluentCart\App\Modules\Data;

use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Models\Product;

class ProductDataSetup
{

    protected static $productsCache = [];

    public function boot()
    {
        static $booted = false;

        if ($booted) {
            return;
        }

        add_action('the_post', [$this, 'maybeSetupProductData'], 1);
        $booted = true;
    }

    public function maybeSetupProductData($post)
    {
        unset($GLOBALS['fct_product']);

        if (is_int($post)) {
            $post = get_post($post);
        }

        if (empty($post->post_type) || $post->post_type !== FluentProducts::CPT_NAME) {
            return $post;
        }

        $GLOBALS['fct_product'] = self::getProductModel($post->ID);

        return $post;
    }

    public static function getProductModel($postId)
    {
        if (isset(self::$productsCache[$postId])) {
            return self::$productsCache[$postId];
        }

        $product = Product::query()->find($postId);

        if ($product) {
            self::$productsCache[$postId] = $product;
        }

        return $product;
    }

    public static function setProductsCache($products)
    {
        foreach ($products as $product) {
            self::$productsCache[$product->ID] = $product;
        }
    }
}

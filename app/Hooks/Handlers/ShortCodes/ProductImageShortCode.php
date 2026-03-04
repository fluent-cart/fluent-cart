<?php

namespace FluentCart\App\Hooks\Handlers\ShortCodes;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Product;
use FluentCart\App\Services\Renderer\ProductCardRender;
use FluentCart\Framework\Support\Arr;

class ProductImageShortCode extends ShortCode
{
    protected static string $shortCodeName = 'fluent_cart_product_image';


    public function render(?array $viewData = null)
    {
        $data = $viewData ?? $this->shortCodeAttributes ?? [];

        $product = null;
        $isDefault = Helper::toBool(Arr::get($data, 'is_default', false));

        if ($isDefault) {
            $product = fluent_cart_get_current_product();
        } else {
            $productId = Arr::get($data, 'product_id', false);
            if ($productId) {
                $product = Product::query()->find($productId);
            }
        }

        if (!$product) {
            return;
        }

        (new ProductCardRender($product))->renderProductImage();
    }
}


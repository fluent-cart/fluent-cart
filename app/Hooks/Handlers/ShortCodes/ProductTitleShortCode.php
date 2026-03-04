<?php

namespace FluentCart\App\Hooks\Handlers\ShortCodes;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Product;
use FluentCart\App\Services\Renderer\ProductCardRender;
use FluentCart\Framework\Support\Arr;

class ProductTitleShortCode extends ShortCode
{
    protected static string $shortCodeName = 'fluent_cart_product_title';


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

        $data['isLink'] = Helper::toBool(Arr::get($data, 'is_link', true));
        $data['target'] = Arr::get($data, 'link_target', '_self');

        (new ProductCardRender($product))->renderTitle('', $data);
    }
}


<?php

namespace FluentCart\App\Services\Async;

use FluentCart\App\Models\Product;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;

class DummyProductService
{
    public static function create(string $category, $index)
    {
        $instance = new self();
        $filePath = $instance->getFilePath($category);
        if (!file_exists($filePath)) {
            return new \WP_Error(
                400,
                __('Products for this category is not found', 'fluent-cart'),
            );
        }
        $json = file_get_contents($filePath);
        try {
            $products = json_decode($json, true);

            $product = Arr::get($products, $index);
            if (empty($product)) {
                return new \WP_Error(
                    404,
                    __('Product Not Found', 'fluent-cart'),
                );
            }
            $instance->insert($product);

            return true;

        } catch (\Exception $exception) {
            return new \WP_Error(
                400,
                __('Products for this category is not found', 'fluent-cart'),
            );
        }
    }

    public static function createAll(string $category)
    {
        $instance = new self();
        $filePath = $instance->getFilePath($category);
        if (!file_exists($filePath)) {
            return new \WP_Error(
                400,
                __('Products for this category is not found', 'fluent-cart'),
            );
        }
        $json = file_get_contents($filePath);
        try {
            $products = json_decode($json, true);
            foreach ($products as $product) {
                $instance->insert($product);
            }
            return true;

        } catch (\Exception $exception) {
            return new \WP_Error(
                400,
                __('Products for this category is not found', 'fluent-cart'),
            );
        }
    }


    protected function insert($product)
    {

        $productName = Str::slug($product['post_title'], '-', null);
        $now = DateTime::gmtNow();
        $createdDate = $now->format('Y-m-d H:i:s');
        $productNameSuffix = $now->format('d-m-Y-H-i-s');

        $data = [
            'post_author'           => get_current_user_id(),
            'post_date'             => $createdDate,
            'post_date_gmt'         => $createdDate,
            'post_content_filtered' => '',
            'post_status'           => 'publish',
            'post_type'             => 'fluent-products',
            'comment_status'        => 'open',
            'ping_status'           => 'closed',
            'post_password'         => '',
            'post_name'             => $productName . '-' . $productNameSuffix,
            'to_ping'               => '',
            'pinged'                => '',
            'post_modified'         => $createdDate,
            'post_modified_gmt'     => $createdDate,
            'post_parent'           => 0,
            'menu_order'            => 0,
            'post_mime_type'        => '',
            'guid'                  => get_site_url() . '/?items=' . $productName . '-' . $productNameSuffix
        ];
        $product = array_merge($product, $data);
        $detail = $product['detail'];
        $variantData = $product['variants'];

        $galleryImages = [];


        if (isset($product['gallery']) && is_array($product['gallery'])) {
            $galleryImages = $product['gallery'];
        }
        $categories = Arr::get($product, 'categories');

        $product = Product::query()->create(
            Arr::except($product, ['detail', 'variants', 'gallery'])
        );

        $this->attachTerms($categories, $product->ID);

        /**
         * @var Product $product
         */

        if (!empty($galleryImages)) {
            ImageAttachService::attachImageToProduct($product->toArray(), $galleryImages);

            $galleryImageWithId = get_post_meta($product->ID, 'fluent-products-gallery-image', true);
            if (isset($galleryImageWithId[0])) {
                set_post_thumbnail($product->ID, Arr::get($galleryImageWithId, '0.id'));
            }
        }
        $variants = $product->variants()->createMany($variantData);

        foreach ($variants as $index => $variant) {
            $images = Arr::get($variantData, $index . '.images', []);
            if (is_array($images) && count($images)) {
                ImageAttachService::attachImageToVariant($variant->id, $images);
            }
        }

        $detail['post_id'] = $product->ID;
        $detail['default_variation_id'] = $variants->first()->id;
        $product->detail()->create($detail);

    }

    public function attachTerms($categories, $postId)
    {
        if (!function_exists('wp_create_term')) {
            require_once(ABSPATH . 'wp-admin/includes/taxonomy.php');
        }


        if (is_string($categories)) {
            $categories = explode(',', $categories);
        }

        $termIds = [];

        if (is_array($categories)) {
            foreach ($categories as $category) {
                $term = wp_create_term($category, 'product-categories');
                $termIds[] = $term['term_id'];
            }
        }
        wp_set_post_terms($postId, $termIds, 'product-categories');
    }


    protected function getFilePath(string $category): string
    {
        return FLUENTCART_PLUGIN_PATH . 'dummies' . DIRECTORY_SEPARATOR . $category . '.json';
    }

}
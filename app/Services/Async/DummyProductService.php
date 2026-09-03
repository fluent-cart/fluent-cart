<?php

namespace FluentCart\App\Services\Async;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\AttributeGroup;
use FluentCart\App\Models\AttributeTerm;
use FluentCart\App\Models\Product;
use FluentCart\App\Services\AdvancedVariationService;
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
        ];
        $product = array_merge($product, $data);

        $detail             = $product['detail'];
        $variantData        = Arr::get($product, 'variants', []);
        $attributeOptions   = Arr::get($product, 'attribute_options', []);
        $variantImages      = Arr::get($product, 'variant_images', []);
        $imageKeyGroups     = Arr::get($product, 'image_key_groups', []);
        $defaultVariantData = Arr::get($product, 'default_variant_data', []);
        $priceKeyGroups     = Arr::get($product, 'price_key_groups', []);
        $variantPrices      = Arr::get($product, 'variant_prices', []);
        $variationType = Arr::get($detail, 'variation_type', 'simple');
        $isAdvanced    = $variationType === Helper::PRODUCT_TYPE_ADVANCE_VARIATION;

        $galleryImages = [];
        if (isset($product['gallery']) && is_array($product['gallery'])) {
            $galleryImages = $product['gallery'];
        }
        $categories = Arr::get($product, 'categories');

        // Validate and resolve advanced variation inputs BEFORE writing any product
        // rows — invalid JSON must not leave a published product without variants.
        $resolved = [];
        if ($isAdvanced) {
            if (empty($attributeOptions)) {
                throw new \Exception(__('No attribute options defined for advanced variation.', 'fluent-cart'));
            }
            $resolved = $this->resolveAttributeOptions($attributeOptions);
            if (empty($resolved['options'])) {
                throw new \Exception(__('Failed to resolve attribute options for advanced variation.', 'fluent-cart'));
            }
        }

        $product = Product::query()->create(
            Arr::except($product, ['detail', 'variants', 'gallery', 'attribute_options', 'variant_images', 'image_key_groups', 'default_variant_data', 'price_key_groups', 'variant_prices'])
        );

        $product->update(['guid' => get_permalink($product->ID)]);

        $this->attachTerms($categories, $product->ID);

        if (!empty($galleryImages)) {
            ImageAttachService::attachImageToProduct($product->toArray(), $galleryImages, $isAdvanced);

            $galleryImageWithId = get_post_meta($product->ID, 'fluent-products-gallery-image', true);
            $firstImageId = Arr::get($galleryImageWithId, '0.id');
            if (!empty($firstImageId)) {
                set_post_thumbnail($product->ID, $firstImageId);
            }
        }

        if ($isAdvanced) {
            try {
                $this->insertAdvancedVariation($product, $detail, $resolved, $variantImages, $imageKeyGroups, $defaultVariantData, $variantPrices, $priceKeyGroups);
            } catch (\Exception $exception) {
                wp_delete_post($product->ID, true);
                throw $exception;
            }
            return;
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

    private function insertAdvancedVariation(Product $product, array $detail, array $resolved, array $variantImages, array $imageKeyGroups, array $defaultVariantData, array $variantPrices = [], array $priceKeyGroups = []): void
    {
        $optionsForSync    = $resolved['options'];
        $termIdToSlug      = $resolved['termIdToSlug'];
        $termIdToGroupSlug = $resolved['termIdToGroupSlug'];

        $detail['post_id'] = $product->ID;
        $productDetail = $product->detail()->create($detail);

        try {
            $result   = AdvancedVariationService::syncVariantOption($product->ID, ['options' => $optionsForSync]);
            $variants = Arr::get($result, 'data');

            if (empty($variants) || $variants->isEmpty()) {
                throw new \Exception(Arr::get($result, 'message', __('Failed to generate variants.', 'fluent-cart')));
            }
        } catch (\Exception $exception) {
            $productDetail->delete();
            throw $exception;
        }

        $productDetail->update(['default_variation_id' => $variants->first()->id]);

        foreach ($variants as $variant) {
            $needsTermResolution = (!empty($variantImages) && !empty($imageKeyGroups))
                || (!empty($variantPrices) && !empty($priceKeyGroups));

            $termsByGroupSlug = [];
            if ($needsTermResolution) {
                foreach (explode('_', (string) $variant->variation_identifier) as $termIdStr) {
                    $termId    = (int) $termIdStr;
                    $groupSlug = Arr::get($termIdToGroupSlug, $termId);
                    $termSlug  = Arr::get($termIdToSlug, $termId);
                    if ($groupSlug && $termSlug) {
                        $termsByGroupSlug[$groupSlug] = $termSlug;
                    }
                }
            }

            $variantUpdate = [];
            if (!empty($defaultVariantData)) {
                $variantUpdate = [
                    'item_price'    => Arr::get($defaultVariantData, 'item_price', 0),
                    'compare_price' => Arr::get($defaultVariantData, 'compare_price', 0),
                    'total_stock'   => Arr::get($defaultVariantData, 'total_stock', 1),
                    'available'     => Arr::get($defaultVariantData, 'available', 1),
                    'stock_status'  => Arr::get($defaultVariantData, 'stock_status', 'in-stock'),
                ];
            }

            if (!empty($variantPrices) && !empty($priceKeyGroups)) {
                $priceParts = [];
                foreach ($priceKeyGroups as $groupSlug) {
                    $priceParts[] = Arr::get($termsByGroupSlug, $groupSlug, '');
                }
                $priceOverride = Arr::get($variantPrices, implode('/', $priceParts), []);
                if (!empty($priceOverride)) {
                    $variantUpdate['item_price']    = Arr::get($priceOverride, 'item_price', $variantUpdate['item_price'] ?? 0);
                    $variantUpdate['compare_price'] = Arr::get($priceOverride, 'compare_price', $variantUpdate['compare_price'] ?? 0);
                }
            }

            if (!empty($variantUpdate)) {
                $variant->update($variantUpdate);
            }

            if (empty($variantImages) || empty($imageKeyGroups)) {
                continue;
            }

            $keyParts = [];
            foreach ($imageKeyGroups as $groupSlug) {
                $keyParts[] = Arr::get($termsByGroupSlug, $groupSlug, '');
            }
            $imageKey = implode('/', $keyParts);

            $images = Arr::get($variantImages, $imageKey, []);
            if (!empty($images)) {
                ImageAttachService::attachImageToVariant($variant->id, $images, true);
            }
        }
    }

    private function resolveAttributeOptions(array $attributeOptions): array
    {
        $optionsForSync    = [];
        $termIdToSlug      = [];
        $termIdToGroupSlug = [];

        foreach ($attributeOptions as $groupConfig) {
            $groupDef   = Arr::get($groupConfig, 'group', []);
            $groupSlug  = Arr::get($groupDef, 'slug', '');
            $groupTitle = Arr::get($groupDef, 'title', '');
            $groupType  = Arr::get($groupDef, 'type', '');
            if (empty($groupSlug)) {
                continue;
            }

            $group = AttributeGroup::query()->where('slug', $groupSlug)->first();
            if (empty($group)) {
                if (empty($groupTitle)) {
                    continue;
                }
                $createData = [
                    'slug'   => $groupSlug,
                    'title'  => $groupTitle,
                    'serial' => 10,
                ];
                if (!empty($groupType)) {
                    $createData['settings'] = ['type' => $groupType];
                }
                $group = AttributeGroup::query()->create($createData);
            }

            $termIds = [];
            foreach (Arr::get($groupConfig, 'terms', []) as $termData) {
                $termSlug = Arr::get($termData, 'slug', '');
                if (empty($termSlug)) {
                    continue;
                }

                $term = AttributeTerm::query()
                    ->where('group_id', $group->id)
                    ->where('slug', $termSlug)
                    ->first();

                if (empty($term)) {
                    $termCreate = [
                        'group_id' => $group->id,
                        'slug'     => $termSlug,
                        'title'    => Arr::get($termData, 'title', $termSlug),
                        'serial'   => 10,
                    ];
                    $termSettings = array_filter([
                        'color' => Arr::get($termData, 'color', ''),
                        'url'   => Arr::get($termData, 'url', ''),
                    ]);
                    if (!empty($termSettings)) {
                        $termCreate['settings'] = $termSettings;
                    }
                    $term = AttributeTerm::query()->create($termCreate);
                }

                $termIds[]                        = $term->id;
                $termIdToSlug[$term->id]          = $termSlug;
                $termIdToGroupSlug[$term->id]     = $groupSlug;
            }

            if (!empty($termIds)) {
                $optionsForSync[] = [
                    'group_id' => $group->id,
                    'variants' => $termIds,
                ];
            }
        }

        return [
            'options'           => $optionsForSync,
            'termIdToSlug'      => $termIdToSlug,
            'termIdToGroupSlug' => $termIdToGroupSlug,
        ];
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
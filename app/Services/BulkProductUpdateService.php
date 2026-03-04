<?php

namespace FluentCart\App\Services;

use FluentCart\Api\Resource\ProductResource;
use FluentCart\Api\Resource\ProductVariationResource;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Services\Filter\ProductFilter;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Validator\Validator;

class BulkProductUpdateService
{
    /**
     * Fetch products formatted for bulk editing.
     * Returns products with decimal prices and category terms.
     */
    public function fetchForBulkEdit(Request $request): array
    {
        $products = ProductFilter::fromRequest($request)->paginate();

        $formatted = $products->getCollection()->map(function ($product) {
            return $this->formatProductForEdit($product);
        });

        return [
            'products' => $formatted->values()->toArray(),
            'total'    => $products->total(),
            'per_page' => $products->perPage(),
            'page'     => $products->currentPage(),
        ];
    }

    /**
     * Format a single product for the bulk edit spreadsheet.
     * Converts prices from cents to decimal and attaches categories.
     */
    protected function formatProductForEdit(Product $product): array
    {
        $product->load([
            'detail',
            'variants' => function ($query) {
                $query->orderBy('serial_index', 'ASC');
            },
            'variants.media',
        ]);

        $data = [
            'ID'           => $product->ID,
            'post_title'   => $product->post_title,
            'post_content' => $product->post_content,
            'post_excerpt' => $product->post_excerpt,
            'post_status'  => $product->post_status,
            'view_url'     => get_permalink($product->ID),
        ];

        // Gallery images
        $gallery = get_post_meta($product->ID, 'fluent-products-gallery-image', true);
        $data['gallery'] = (!empty($gallery) && is_array($gallery)) ? $gallery : [];

        // Detail
        if ($product->detail) {
            $data['detail'] = [
                'variation_type'   => $product->detail->variation_type,
                'fulfillment_type' => $product->detail->fulfillment_type,
                'manage_stock'     => (int) $product->detail->manage_stock,
            ];
        }

        // Variants — convert prices from cents to decimal
        $data['variants'] = [];
        if ($product->variants) {
            foreach ($product->variants as $variant) {
                $variantMedia = [];
                if ($variant->media && is_array($variant->media->meta_value)) {
                    $variantMedia = $variant->media->meta_value;
                }

                $variantData = [
                    'id'              => $variant->id,
                    'post_id'         => $variant->post_id,
                    'variation_title' => $variant->variation_title,
                    'sku'             => $variant->sku,
                    'item_price'      => $variant->item_price / 100,
                    'compare_price'   => $variant->compare_price / 100,
                    'payment_type'    => $variant->payment_type,
                    'manage_stock'    => (int) $variant->manage_stock,
                    'total_stock'     => (int) $variant->total_stock,
                    'available'       => (int) $variant->available,
                    'stock_status'    => $variant->stock_status,
                    'serial_index'    => (int) $variant->serial_index,
                    'fulfillment_type' => $variant->fulfillment_type,
                    'other_info'      => $this->formatOtherInfoForEdit($variant->other_info ?? []),
                    'media'           => $variantMedia,
                ];
                $data['variants'][] = $variantData;
            }
        }

        // Categories
        $terms = get_the_terms($product->ID, 'product-categories');
        $data['category_terms'] = [];
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $data['category_terms'][] = [
                    'term_id' => $term->term_id,
                    'name'    => $term->name,
                    'slug'    => $term->slug,
                    'parent'  => $term->parent,
                ];
            }
        }

        // Build category path strings for the frontend el-select
        $data['categories'] = $this->buildCategoryPaths($product->ID);

        return $data;
    }

    /**
     * Build category path strings like ["Clothing > T-Shirts", "Sale"]
     */
    protected function buildCategoryPaths(int $postId): array
    {
        $terms = get_the_terms($postId, 'product-categories');
        if (!$terms || is_wp_error($terms)) {
            return [];
        }

        $paths = [];
        foreach ($terms as $term) {
            $paths[] = $this->getTermPath($term);
        }

        return $paths;
    }

    /**
     * Get the full hierarchical path for a term.
     */
    protected function getTermPath($term): string
    {
        $parts = [$term->name];
        $parentId = $term->parent;

        while ($parentId > 0) {
            $parent = get_term($parentId, 'product-categories');
            if (!$parent || is_wp_error($parent)) {
                break;
            }
            array_unshift($parts, $parent->name);
            $parentId = $parent->parent;
        }

        return implode(' > ', $parts);
    }

    /**
     * Validate product data before update using the framework Validator.
     *
     * @param array $data
     * @return array|null Field-keyed error messages if invalid, null if valid
     */
    protected function validateProduct(array $data): ?array
    {
        $variationType = Arr::get($data, 'detail.variation_type', 'simple');
        $postId = absint(Arr::get($data, 'ID', 0));

        $rules = [
            'post_title'                 => 'required|sanitizeText|maxLength:200',
            'post_status'                => 'required|sanitizeText|in:publish,draft',
            'variants.*.variation_title' => ($variationType === 'simple_variations')
                ? 'required|sanitizeText|maxLength:200'
                : 'nullable|sanitizeText|maxLength:200',
            'variants.*.sku'             => 'nullable|sanitizeText|maxLength:100',
            'variants.*.item_price'      => 'nullable|numeric|min:0',
            'variants.*.compare_price'   => [
                'nullable',
                'numeric',
                function ($attribute, $value, $rules, $allData) {
                    $index = explode('.', $attribute)[1];
                    $itemPrice = Arr::get($allData, "variants.$index.item_price", 0);
                    if (empty($itemPrice)) {
                        $itemPrice = 0;
                    }
                    if ($value !== null && $value < $itemPrice) {
                        return __('Compare price must be greater than or equal to item price.', 'fluent-cart');
                    }
                    return null;
                },
            ],
            'variants.*.other_info'                  => 'required|array',
            'variants.*.other_info.payment_type'     => 'required|sanitizeText|in:onetime,subscription',
            'variants.*.other_info.repeat_interval'  => 'nullable|required_if:variants.*.other_info.payment_type,subscription|sanitizeText|in:yearly,half_yearly,quarterly,monthly,weekly,daily',
            'variants.*.other_info.trial_days'        => 'nullable|numeric|min:0|max:365',
            'variants.*.other_info.manage_setup_fee' => 'nullable|required_if:variants.*.other_info.payment_type,subscription|sanitizeText|in:no,yes',
            'variants.*.other_info.signup_fee'       => 'nullable|required_if:variants.*.other_info.manage_setup_fee,yes|numeric|min:0',
            'variants.*.other_info.signup_fee_name'  => 'nullable|required_if:variants.*.other_info.manage_setup_fee,yes|sanitizeText|maxLength:100',
        ];

        $messages = [
            'post_title.required'                                => __('Title is required.', 'fluent-cart'),
            'post_title.maxLength'                               => __('Title may not be greater than 200 characters.', 'fluent-cart'),
            'post_status.required'                               => __('Status is required.', 'fluent-cart'),
            'post_status.in'                                     => __('Status must be published or draft.', 'fluent-cart'),
            'variants.*.variation_title.required'                => __('Variant title is required.', 'fluent-cart'),
            'variants.*.variation_title.maxLength'               => __('Variant title may not be greater than 200 characters.', 'fluent-cart'),
            'variants.*.sku.maxLength'                           => __('SKU may not be greater than 100 characters.', 'fluent-cart'),
            'variants.*.item_price.numeric'                      => __('Price must be a number.', 'fluent-cart'),
            'variants.*.item_price.min'                          => __('Price must be a positive number.', 'fluent-cart'),
            'variants.*.other_info.payment_type.required'        => __('Payment Type is required.', 'fluent-cart'),
            'variants.*.other_info.payment_type.in'              => __('Payment Type must be onetime or subscription.', 'fluent-cart'),
            'variants.*.other_info.repeat_interval.required_if'  => __('Interval is required for subscriptions.', 'fluent-cart'),
            'variants.*.other_info.repeat_interval.in'           => __('Interval must be a valid frequency.', 'fluent-cart'),
            'variants.*.other_info.trial_days.numeric'           => __('Trial days must be a number.', 'fluent-cart'),
            'variants.*.other_info.trial_days.min'               => __('Trial days must be 0 or more.', 'fluent-cart'),
            'variants.*.other_info.trial_days.max'               => __('Trial days may not be greater than 365.', 'fluent-cart'),
            'variants.*.other_info.manage_setup_fee.in'          => __('Setup fee option must be yes or no.', 'fluent-cart'),
            'variants.*.other_info.signup_fee.required_if'       => __('Setup Fee Amount is required.', 'fluent-cart'),
            'variants.*.other_info.signup_fee.numeric'           => __('Setup Fee must be a number.', 'fluent-cart'),
            'variants.*.other_info.signup_fee_name.required_if'  => __('Setup Fee Name is required.', 'fluent-cart'),
        ];

        $validator = Validator::make($data, $rules, $messages);

        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors() as $field => $ruleMessages) {
                $errors[$field] = is_array($ruleMessages) ? reset($ruleMessages) : $ruleMessages;
            }
            return $errors;
        }

        // Check for duplicate SKUs within the same product's variants and against DB
        $variants = Arr::get($data, 'variants', []);
        $skus = [];
        $skuErrors = [];
        foreach ($variants as $i => $v) {
            $sku = trim(Arr::get($v, 'sku', ''));
            if (!empty($sku)) {
                if (in_array($sku, $skus, true)) {
                    $skuErrors["variants.$i.sku"] = sprintf(
                        __('Duplicate SKU "%s" within this product.', 'fluent-cart'),
                        $sku
                    );
                } else {
                    // Check DB for SKU used by other products
                    $query = ProductVariation::query()->where('sku', $sku);
                    if ($postId) {
                        $query->where('post_id', '!=', $postId);
                    }
                    if ($query->first()) {
                        $skuErrors["variants.$i.sku"] = sprintf(
                            __('SKU "%s" is already in use by another product.', 'fluent-cart'),
                            $sku
                        );
                    }
                }
                $skus[] = $sku;
            }
        }

        return !empty($skuErrors) ? $skuErrors : null;
    }

    /**
     * Update a chunk of products (max 10).
     *
     * @param array $products
     * @return array { updated: int[], errors: array[] }
     */
    public function updateChunk(array $products): array
    {
        global $wpdb;

        $updated = [];
        $errors = [];

        $wpdb->query('START TRANSACTION');

        try {
            foreach ($products as $index => $productData) {
                $fieldErrors = $this->validateProduct($productData);
                if ($fieldErrors) {
                    $errors[] = [
                        'index'   => $index,
                        'post_id' => Arr::get($productData, 'ID', ''),
                        'title'   => Arr::get($productData, 'post_title', ''),
                        'message' => reset($fieldErrors),
                        'fields'  => $fieldErrors,
                    ];
                    continue;
                }

                try {
                    $postId = $this->updateSingleProduct($productData);
                    $updated[] = $postId;
                } catch (\Throwable $e) {
                    $errors[] = [
                        'index'   => $index,
                        'post_id' => Arr::get($productData, 'ID', ''),
                        'title'   => Arr::get($productData, 'post_title', ''),
                        'message' => $e->getMessage(),
                    ];
                }
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        return [
            'updated' => $updated,
            'errors'  => $errors,
        ];
    }

    /**
     * Update a single product with its variants and categories.
     */
    protected function updateSingleProduct(array $productData): int
    {
        $postId = absint(Arr::get($productData, 'ID', 0));

        if (!$postId) {
            throw new \RuntimeException(__('Product ID is required', 'fluent-cart'));
        }

        $product = Product::query()->find($postId);
        if (!$product) {
            throw new \RuntimeException(__('Product not found', 'fluent-cart'));
        }

        // Use ProductResource::update for variants/detail (handles price * 100)
        $updatePayload = [];

        // Detail
        if (Arr::has($productData, 'detail')) {
            $detail = Arr::get($productData, 'detail', []);
            // Only pass through fields we allow editing
            $updatePayload['detail'] = [
                'id'                   => $product->detail->id ?? null,
                'default_variation_id' => $product->detail->default_variation_id ?? null,
                'variation_type'       => Arr::get($detail, 'variation_type', $product->detail->variation_type ?? 'simple'),
                'fulfillment_type'     => Arr::get($detail, 'fulfillment_type', $product->detail->fulfillment_type ?? 'physical'),
                'manage_stock'         => Arr::get($detail, 'manage_stock', $product->detail->manage_stock ?? 0),
            ];
        }

        // Variants — separate existing (with id) from new (without id)
        // New variants are created directly; existing ones go through ProductResource::update
        if (Arr::has($productData, 'variants')) {
            $allVariants = Arr::get($productData, 'variants', []);
            $existingVariants = [];
            $newVariants = [];

            foreach ($allVariants as $v) {
                if (!empty($v['id'])) {
                    $existingVariants[] = Arr::except($v, ['media']);
                } else {
                    $newVariants[] = $v;
                }
            }

            $updatePayload['variants'] = $existingVariants;

            // Create new variants (e.g. from variation duplication)
            foreach ($newVariants as $newVariant) {
                $this->createVariantForProduct($postId, $product, $newVariant);
            }
        }

        // Post-level fields
        $postFields = ['post_title', 'post_content', 'post_excerpt', 'post_status'];
        foreach ($postFields as $field) {
            if (Arr::has($productData, $field)) {
                $updatePayload[$field] = Arr::get($productData, $field);
            }
        }

        // Map 'published' status to 'publish' for WordPress
        if (Arr::get($updatePayload, 'post_status') === 'published') {
            $updatePayload['post_status'] = 'publish';
        }

        // Use ProductResource::update which handles price conversion and variant updates
        if (!empty($updatePayload['variants']) || !empty($updatePayload['detail'])) {
            ProductResource::update($updatePayload, $postId);
        }

        // Update wp_post fields
        if (array_intersect_key($updatePayload, array_flip($postFields))) {
            $wpPostData = ['ID' => $postId];
            if (Arr::has($updatePayload, 'post_title')) {
                $wpPostData['post_title'] = sanitize_text_field(Arr::get($updatePayload, 'post_title'));
                $wpPostData['post_name'] = sanitize_title(Arr::get($updatePayload, 'post_title'));
            }
            if (Arr::has($updatePayload, 'post_content')) {
                $wpPostData['post_content'] = wp_kses_post(Arr::get($updatePayload, 'post_content'));
            }
            if (Arr::has($updatePayload, 'post_excerpt')) {
                $wpPostData['post_excerpt'] = sanitize_textarea_field(Arr::get($updatePayload, 'post_excerpt'));
            }
            if (Arr::has($updatePayload, 'post_status')) {
                $wpPostData['post_status'] = sanitize_text_field(Arr::get($updatePayload, 'post_status'));
            }
            wp_update_post($wpPostData);
        }

        // Update manage_stock on product detail
        if (Arr::has($productData, 'detail.manage_stock')) {
            $manageStock = Arr::get($productData, 'detail.manage_stock', 0) ? 1 : 0;
            $detail = ProductDetail::query()->where('post_id', $postId)->first();
            if ($detail) {
                $detail->update(['manage_stock' => $manageStock]);

                // Also update all variants' manage_stock
                ProductVariation::query()->where('post_id', $postId)->update([
                    'manage_stock' => $manageStock,
                ]);
            }
        }

        // Update gallery images
        $gallery = Arr::get($productData, 'gallery', null);
        if (is_array($gallery)) {
            $galleryMedia = array_map(function ($img) {
                return [
                    'id'    => absint(Arr::get($img, 'id', 0)),
                    'url'   => esc_url_raw(Arr::get($img, 'url', '')),
                    'title' => sanitize_text_field(Arr::get($img, 'title', '')),
                ];
            }, $gallery);
            $galleryMedia = array_filter($galleryMedia, function ($img) {
                return !empty($img['url']);
            });
            update_post_meta($postId, 'fluent-products-gallery-image', array_values($galleryMedia));
        }

        // Update variant media
        $variants = Arr::get($productData, 'variants', []);
        foreach ($variants as $variantData) {
            $variantId = absint(Arr::get($variantData, 'id', 0));
            $variantMedia = Arr::get($variantData, 'media', null);
            if ($variantId && is_array($variantMedia)) {
                $normalized = array_map(function ($img) {
                    return [
                        'id'    => absint(Arr::get($img, 'id', 0)),
                        'url'   => esc_url_raw(Arr::get($img, 'url', '')),
                        'title' => sanitize_text_field(Arr::get($img, 'title', '')),
                    ];
                }, $variantMedia);
                $normalized = array_values(array_filter($normalized, function ($img) {
                    return !empty($img['url']);
                }));
                ProductVariationResource::setImage($normalized, $variantId);
            }
        }

        // Sync categories
        $categories = Arr::get($productData, 'categories', null);
        if (is_array($categories)) {
            $this->syncCategories($postId, $categories);
        }

        return $postId;
    }

    /**
     * Create a new variant for an existing product (used when duplicating a variant in bulk edit).
     */
    protected function createVariantForProduct(int $postId, Product $product, array $variantData): void
    {
        $priceColumns = ['item_price', 'compare_price', 'item_cost'];
        foreach ($priceColumns as $column) {
            if (Arr::has($variantData, $column)) {
                $variantData[$column] = floatval(Arr::get($variantData, $column, 0)) * 100;
            }
        }

        $otherInfo = Arr::get($variantData, 'other_info', []);
        $media = Arr::get($variantData, 'media', []);

        $maxSerial = ProductVariation::query()->where('post_id', $postId)->max('serial_index');

        $createData = [
            'post_id'         => $postId,
            'variation_title' => sanitize_text_field(Arr::get($variantData, 'variation_title', '')),
            'sku'             => Arr::get($variantData, 'sku') ? sanitize_text_field($variantData['sku']) : null,
            'item_price'      => (int) Arr::get($variantData, 'item_price', 0),
            'compare_price'   => (int) Arr::get($variantData, 'compare_price', 0),
            'payment_type'    => Arr::get($otherInfo, 'payment_type', 'onetime'),
            'manage_stock'    => (int) ($product->detail->manage_stock ?? 0),
            'total_stock'     => (int) Arr::get($variantData, 'available', 0),
            'available'       => (int) Arr::get($variantData, 'available', 0),
            'stock_status'    => Arr::get($variantData, 'stock_status', 'in-stock'),
            'serial_index'    => ($maxSerial ?? 0) + 1,
            'fulfillment_type' => Arr::get($variantData, 'fulfillment_type', $product->detail->fulfillment_type ?? 'physical'),
            'other_info'      => $otherInfo,
        ];

        $newVariant = ProductVariation::query()->create($createData);

        // Set variant media if provided
        if ($newVariant && !empty($media) && is_array($media)) {
            $normalized = array_map(function ($img) {
                return [
                    'id'    => absint(Arr::get($img, 'id', 0)),
                    'url'   => esc_url_raw(Arr::get($img, 'url', '')),
                    'title' => sanitize_text_field(Arr::get($img, 'title', '')),
                ];
            }, $media);
            $normalized = array_values(array_filter($normalized, function ($img) {
                return !empty($img['url']);
            }));
            ProductVariationResource::setImage($normalized, $newVariant->id);
        }
    }

    /**
     * Sync categories for a product.
     * Accepts mixed input: term ID integers, objects with term_id, or path strings.
     */
    public function syncCategories(int $postId, array $categories): void
    {
        if (!function_exists('wp_create_term')) {
            require_once(ABSPATH . 'wp-admin/includes/taxonomy.php');
        }

        $termIds = [];

        foreach ($categories as $category) {
            if (is_numeric($category)) {
                $termIds[] = (int) $category;
            } elseif (is_array($category) && isset($category['term_id'])) {
                $termIds[] = (int) $category['term_id'];
            } elseif (is_string($category) && !empty($category)) {
                // Path string like "Clothing > T-Shirts"
                $resolvedId = $this->resolveTermPath($category);
                if ($resolvedId) {
                    $termIds[] = $resolvedId;
                }
            }
        }

        $termIds = array_unique(array_filter($termIds));
        wp_set_post_terms($postId, $termIds, 'product-categories');
    }

    /**
     * Convert cents-based fields in other_info to dollars for frontend display.
     */
    protected function formatOtherInfoForEdit(array $otherInfo): array
    {
        if (!empty($otherInfo['signup_fee']) && is_numeric($otherInfo['signup_fee'])) {
            $otherInfo['signup_fee'] = (float) $otherInfo['signup_fee'] / 100;
        }

        return $otherInfo;
    }

    /**
     * Resolve a category path string to a term ID, creating terms as needed.
     * Reuses the same pattern as BulkProductInsertService::assignCategories.
     */
    protected function resolveTermPath(string $path): int
    {
        $path = sanitize_text_field($path);
        if (empty($path)) {
            return 0;
        }

        $segments = array_map('trim', explode('>', $path));
        $segments = array_filter($segments);
        $parentId = 0;

        foreach ($segments as $name) {
            $existing = term_exists($name, 'product-categories', $parentId ?: null);
            if ($existing) {
                $parentId = (int) (is_array($existing) ? $existing['term_id'] : $existing);
            } else {
                $args = $parentId ? ['parent' => $parentId] : [];
                $created = wp_insert_term($name, 'product-categories', $args);
                if (!is_wp_error($created)) {
                    $parentId = (int) $created['term_id'];
                }
            }
        }

        return $parentId;
    }
}

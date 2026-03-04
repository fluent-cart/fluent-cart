<?php

namespace FluentCart\App\Services;

use FluentCart\Api\Resource\ProductVariationResource;
use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Validator\Validator;

class BulkProductInsertService
{
    /**
     * Insert a chunk of products within a database transaction.
     * Each product is validated before insert — valid ones are inserted,
     * invalid ones are skipped with an error keyed by _cid.
     *
     * @param array $products
     * @return array { created: array[], errors: array[] }
     */
    public function insertChunk(array $products): array
    {
        global $wpdb;

        $created = [];
        $errors = [];

        $wpdb->query('START TRANSACTION');

        try {
            foreach ($products as $index => $productData) {
                $cid = sanitize_text_field(Arr::get($productData, '_cid', ''));

                $fieldErrors = $this->validateProduct($productData);
                if ($fieldErrors) {
                    $errors[] = [
                        '_cid'    => $cid,
                        'title'   => Arr::get($productData, 'post_title', ''),
                        'message' => reset($fieldErrors),
                        'fields'  => $fieldErrors,
                    ];
                    continue;
                }

                try {
                    $productId = $this->insertSingleProduct($productData);
                    $created[] = ['_cid' => $cid, 'id' => $productId, 'view_url' => get_permalink($productId)];
                } catch (\Throwable $e) {
                    $errors[] = [
                        '_cid'    => $cid,
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
            'created' => $created,
            'errors'  => $errors,
        ];
    }

    /**
     * Validate product data before insertion using the framework Validator.
     *
     * @param array $data
     * @return array|null Field-keyed error messages if invalid, null if valid
     */
    protected function validateProduct(array $data): ?array
    {
        $variationType = Arr::get($data, 'detail.variation_type', 'simple');

        $rules = [
            'post_title'                 => 'required|sanitizeText|maxLength:200',
            'post_status'                => 'required|sanitizeText|in:published,draft',
            'detail.fulfillment_type'    => 'required|sanitizeText|in:physical,digital',
            'detail.variation_type'      => 'required|sanitizeText|in:simple,simple_variations',
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
            'detail.fulfillment_type.required'                   => __('Product Type is required.', 'fluent-cart'),
            'detail.fulfillment_type.in'                         => __('Product Type must be physical or digital.', 'fluent-cart'),
            'detail.variation_type.required'                     => __('Pricing Type is required.', 'fluent-cart'),
            'detail.variation_type.in'                           => __('Pricing Type must be simple or simple_variations.', 'fluent-cart'),
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
            // Flatten: ['field' => ['rule' => 'msg', ...]] → ['field' => 'first msg']
            $errors = [];
            foreach ($validator->errors() as $field => $ruleMessages) {
                $errors[$field] = is_array($ruleMessages) ? reset($ruleMessages) : $ruleMessages;
            }
            return $errors;
        }

        // Check for duplicate SKUs within the same product's variants
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
                }
                $skus[] = $sku;
            }
        }

        return !empty($skuErrors) ? $skuErrors : null;
    }

    /**
     * Insert a single product with its detail and all variants.
     *
     * @param array $productData
     * @return int The created post ID
     */
    protected function insertSingleProduct(array $productData): int
    {
        $postTitle = sanitize_text_field(Arr::get($productData, 'post_title', ''));
        $postStatus = sanitize_text_field(Arr::get($productData, 'post_status', 'draft'));
        $postContent = wp_kses_post(Arr::get($productData, 'post_content', ''));
        $postExcerpt = sanitize_textarea_field(Arr::get($productData, 'post_excerpt', ''));

        if (empty($postTitle)) {
            throw new \RuntimeException(__('Product title is required', 'fluent-cart'));
        }

        // Map 'published' status to 'publish' for WordPress
        if ($postStatus === 'published') {
            $postStatus = 'publish';
        }

        $postData = [
            'post_title'   => $postTitle,
            'post_name'    => sanitize_title($postTitle),
            'post_content' => $postContent,
            'post_excerpt' => $postExcerpt,
            'post_status'  => $postStatus,
            'post_type'    => FluentProducts::CPT_NAME,
            'post_author'  => get_current_user_id(),
        ];

        $createdPostId = wp_insert_post($postData);

        if (is_wp_error($createdPostId)) {
            throw new \RuntimeException($createdPostId->get_error_message());
        }

        $detail = Arr::get($productData, 'detail', []);
        $fulfillmentType = sanitize_text_field(Arr::get($detail, 'fulfillment_type', 'physical'));
        $variationType = sanitize_text_field(Arr::get($detail, 'variation_type', 'simple'));
        $manageStock = Arr::get($detail, 'manage_stock', 0) ? 1 : 0;

        $createdDetail = ProductDetail::query()->create([
            'post_id'            => $createdPostId,
            'fulfillment_type'   => $fulfillmentType,
            'variation_type'     => $variationType,
            'manage_stock'       => $manageStock,
            'stock_availability' => 'in-stock',
        ]);

        if (!$createdDetail) {
            throw new \RuntimeException(__('Failed to create product detail', 'fluent-cart'));
        }

        $variants = Arr::get($productData, 'variants', []);
        $firstVariantId = null;

        // For simple products the frontend stores manage_stock / total_stock in
        // 'detail' — sync them into the first variant so createVariant() picks
        // them up instead of using the dummy defaults.
        if ($variationType === 'simple' && !empty($variants) && is_array($variants)) {
            $variants[0]['manage_stock'] = $manageStock;
            if ($manageStock) {
                $detailStock = absint(Arr::get($detail, 'total_stock', 100));
                $variants[0]['total_stock'] = $detailStock;
                $variants[0]['available']   = $detailStock;
            }
        }

        try {
            if (!empty($variants) && is_array($variants)) {
                foreach ($variants as $variantIndex => $variantData) {
                    $variantId = $this->createVariant($createdPostId, $variantData, $variantIndex + 1, $fulfillmentType, $postTitle);
                    if (!$firstVariantId) {
                        $firstVariantId = $variantId;
                    }
                }
            } else {
                // Create one default variant (same as ProductController::create)
                $defaultVariantData = [
                    'item_price'    => $this->sanitizePrice(Arr::get($detail, 'item_price', 0)),
                    'compare_price' => $this->sanitizePrice(Arr::get($detail, 'compare_price', 0)),
                ];

                if ($manageStock) {
                    $totalStock = absint(Arr::get($detail, 'total_stock', 100));
                    $defaultVariantData['total_stock'] = $totalStock;
                    $defaultVariantData['available']   = $totalStock;
                }

                $firstVariantId = $this->createDefaultVariant($createdPostId, $postTitle, $fulfillmentType, $defaultVariantData);
            }
        } catch (\Throwable $e) {
            // Clean up the orphaned post and detail so we don't leave
            // products without variants in the database.
            ProductVariation::query()->where('post_id', $createdPostId)->delete();
            $createdDetail->delete();
            wp_delete_post($createdPostId, true);
            throw $e;
        }

        $detailUpdate = [];
        if ($firstVariantId) {
            $detailUpdate['default_variation_id'] = $firstVariantId;
        }

        // Derive stock_availability: if manage_stock is on, check variant availability
        if ($manageStock) {
            $totalAvailable = ProductVariation::query()
                ->where('post_id', $createdPostId)
                ->sum('available');
            $detailUpdate['stock_availability'] = $totalAvailable > 0 ? 'in-stock' : 'out-of-stock';
        }

        // Calculate min_price / max_price from created variants
        $variantPriceRange = ProductVariation::query()
            ->where('post_id', $createdPostId)
            ->selectRaw('MIN(item_price) as min_price, MAX(item_price) as max_price')
            ->first();

        if ($variantPriceRange) {
            $detailUpdate['min_price'] = $variantPriceRange->min_price ?: 0;
            $detailUpdate['max_price'] = $variantPriceRange->max_price ?: 0;
        }

        if (!empty($detailUpdate)) {
            $createdDetail->update($detailUpdate);
        }

        // Handle categories
        $categories = Arr::get($productData, 'categories', []);
        if (!empty($categories) && is_array($categories)) {
            $this->assignCategories($createdPostId, $categories);
        }

        // Handle product gallery — store all media (uploaded with real id, external URLs with id 0)
        $gallery = Arr::get($productData, 'gallery', []);
        if (!empty($gallery) && is_array($gallery)) {
            $galleryMedia = $this->normalizeMedia($gallery);
            if (!empty($galleryMedia)) {
                update_post_meta($createdPostId, 'fluent-products-gallery-image', $galleryMedia);
            }
        }

        return (int) $createdPostId;
    }

    /**
     * Create a product variant from import data.
     */
    protected function createVariant(int $postId, array $variantData, int $serialIndex, string $fulfillmentType, string $productTitle = ''): int
    {
        $variationTitle = sanitize_text_field(Arr::get($variantData, 'variation_title', ''));
        if (empty($variationTitle)) {
            $variationTitle = $productTitle;
        }
        $itemPrice = $this->sanitizePrice(Arr::get($variantData, 'item_price', 0));
        $comparePrice = $this->sanitizePrice(Arr::get($variantData, 'compare_price', 0));
        $sku = sanitize_text_field(Arr::get($variantData, 'sku', ''));
        $otherInfo = Arr::get($variantData, 'other_info', []);
        $paymentType = sanitize_text_field(Arr::get($otherInfo, 'payment_type', 'onetime'));
        $manageStock = Arr::get($variantData, 'manage_stock', 0) ? 1 : 0;

        if ($manageStock) {
            $totalStock = absint(Arr::get($variantData, 'total_stock', 0));
            $available = absint(Arr::get($variantData, 'available', $totalStock));
            $stockStatus = $available > 0 ? 'in-stock' : 'out-of-stock';
        } else {
            $totalStock = 0;
            $available = 0;
            $stockStatus = 'in-stock';
        }

        // Check SKU uniqueness before inserting to avoid raw DB constraint errors
        if (!empty($sku)) {
            $existingSku = ProductVariation::query()->where('sku', $sku)->first();
            if ($existingSku) {
                throw new \RuntimeException(
                    sprintf(__('SKU "%s" is already in use.', 'fluent-cart'), $sku)
                );
            }
        }

        $variant = ProductVariation::query()->create([
            'post_id'          => $postId,
            'serial_index'     => $serialIndex,
            'variation_title'  => $variationTitle,
            'sku'              => !empty($sku) ? $sku : null,
            'item_price'       => $itemPrice,
            'compare_price'    => $comparePrice,
            'stock_status'     => $stockStatus,
            'payment_type'     => $paymentType,
            'manage_stock'     => $manageStock,
            'total_stock'      => $totalStock,
            'available'        => $available,
            'fulfillment_type' => $fulfillmentType,
            'other_info'       => [
                'description'        => sanitize_text_field(Arr::get($otherInfo, 'description', '')),
                'payment_type'       => $paymentType,
                'installment'        => sanitize_text_field(Arr::get($otherInfo, 'installment', 'no')),
                'times'              => sanitize_text_field(Arr::get($otherInfo, 'times', '')),
                'repeat_interval'    => sanitize_text_field(Arr::get($otherInfo, 'repeat_interval', '')),
                'trial_days'         => sanitize_text_field(Arr::get($otherInfo, 'trial_days', '')),
                'billing_summary'    => '',
                'manage_setup_fee'   => sanitize_text_field(Arr::get($otherInfo, 'manage_setup_fee', 'no')),
                'signup_fee_name'    => sanitize_text_field(Arr::get($otherInfo, 'signup_fee_name', '')),
                'signup_fee'         => $this->sanitizePrice(Arr::get($otherInfo, 'signup_fee', '')),
                'setup_fee_per_item' => sanitize_text_field(Arr::get($otherInfo, 'setup_fee_per_item', 'no')),
            ],
        ]);

        // Handle variant media — store all media (uploaded with real id, external URLs with id 0)
        $media = Arr::get($variantData, 'media', []);
        if (!empty($media) && is_array($media)) {
            $variantMedia = $this->normalizeMedia($media);
            if (!empty($variantMedia)) {
                ProductVariationResource::setImage($variantMedia, $variant->id);
            }
        }

        return (int) $variant->id;
    }

    /**
     * Create a default variant for a product (when no variants are provided).
     */
    protected function createDefaultVariant(int $postId, string $title, string $fulfillmentType, array $extra = []): int
    {
        $sku = sanitize_text_field(Arr::get($extra, 'sku', ''));

        // Check SKU uniqueness before inserting to avoid raw DB constraint errors
        if (!empty($sku)) {
            $existingSku = ProductVariation::query()->where('sku', $sku)->first();
            if ($existingSku) {
                throw new \RuntimeException(
                    sprintf(__('SKU "%s" is already in use.', 'fluent-cart'), $sku)
                );
            }
        }

        $variant = ProductVariation::query()->create(array_merge([
            'post_id'          => $postId,
            'serial_index'     => 1,
            'variation_title'  => $title,
            'sku'              => !empty($sku) ? $sku : null,
            'stock_status'     => 'in-stock',
            'payment_type'     => 'onetime',
            'total_stock'      => 0,
            'available'        => 0,
            'fulfillment_type' => $fulfillmentType,
            'other_info'       => [
                'description'        => '',
                'payment_type'       => 'onetime',
                'times'              => '',
                'repeat_interval'    => '',
                'trial_days'         => '',
                'billing_summary'    => '',
                'manage_setup_fee'   => 'no',
                'signup_fee_name'    => '',
                'signup_fee'         => '',
                'setup_fee_per_item' => 'no',
            ],
        ], $extra));

        return (int) $variant->id;
    }

    /**
     * Assign categories to a product, supporting hierarchy via ">" syntax.
     * e.g. ["Clothing", "Clothing > T-Shirts", "Sale"]
     */
    protected function assignCategories(int $postId, array $categoryPaths): void
    {
        if (!function_exists('wp_create_term')) {
            require_once(ABSPATH . 'wp-admin/includes/taxonomy.php');
        }

        $termIds = [];

        foreach ($categoryPaths as $path) {
            $path = sanitize_text_field($path);
            if (empty($path)) {
                continue;
            }

            // Support hierarchy: "Parent > Child > Grandchild"
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

            // Assign the leaf term (deepest in the chain)
            if ($parentId) {
                $termIds[] = $parentId;
            }
        }

        $termIds = array_unique($termIds);
        if (!empty($termIds)) {
            wp_set_post_terms($postId, $termIds, 'product-categories');
        }
    }

    /**
     * Sanitize a price value to ensure it's a valid integer (cents).
     */
    protected function sanitizePrice($value): int
    {
        if (is_numeric($value)) {
            return absint(round(floatval($value) * 100));
        }

        return 0;
    }

    /**
     * Normalize media array — uploaded attachments keep their id, external URLs get id 0.
     *
     * @return array
     */
    protected function normalizeMedia(array $media): array
    {
        $result = [];

        foreach ($media as $item) {
            if (empty($item) || !is_array($item)) {
                continue;
            }

            $url = sanitize_url(Arr::get($item, 'url', ''));
            if (empty($url)) {
                continue;
            }

            $result[] = [
                'id'    => absint(Arr::get($item, 'id', 0)),
                'url'   => $url,
                'title' => sanitize_text_field(Arr::get($item, 'title', '')),
            ];
        }

        return $result;
    }
}

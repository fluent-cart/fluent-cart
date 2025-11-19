<?php

namespace FluentCart\App\Modules\WooCommerceMigrator;

use FluentCart\Framework\Support\Arr;
use FluentCart\App\CPT\FluentProducts;
use WP_CLI;

/**
 * WooCommerceMigratorCli
 *
 * This class handles the migration of WooCommerce products, categories, attachments, and downloadable files to FluentCart.
 * It provides CLI commands for bulk migration and ensures data is mapped and transformed to match FluentCart's structure and logic.
 *
 * Major responsibilities:
 * - Migrate product posts, variations, categories, and attachments
 * - Map WooCommerce product types, stock, downloadable, and virtual flags to FluentCart equivalents
 * - Copy downloadable files to FluentCart's upload directory
 * - Ensure all product meta, images, and downloadable assets are correctly linked
 */
class WooCommerceMigratorCli
{
    private $attachmentMap = [];
    private $migrationSteps = [];
    private $categoryMap = [];

    public function __construct()
    {
        $this->migrationSteps = get_option('__fluent_cart_wc_migration_steps', [
            'attachments' => 'no',
            'products' => 'no',
            'variations' => 'no',
            'categories' => 'no'
        ]);
        
        $this->categoryMap = get_option('__fluent_cart_wc_category_map', []);
    }

    private function checkWooCommerceDependencies()
    {
        if (!class_exists('WooCommerce')) {
            return new \WP_Error('wc_migrator_error', 'WooCommerce is not installed or activated.');
        }
        return true;
    }

    /**
     * Migrate all WooCommerce attachments (media files) to FluentCart.
     *
     * This method finds all WooCommerce attachments and copies them to the FluentCart media library if needed.
     * It also copies attachment meta and ensures images are available for migrated products and variations.
     */
    public function migrateAttachments()
    {
        if ($this->migrationSteps['attachments'] == 'yes') {
            $this->attachmentMap = get_option('__fluent_cart_wc_attachment_map', []);
            return $this->attachmentMap;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $attachments = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'all',
        ]);

        foreach ($attachments as $attachment) {
            $newAttachmentId = $this->migrateSingleAttachment($attachment);
            if (!is_wp_error($newAttachmentId)) {
                $this->attachmentMap[$attachment->ID] = $newAttachmentId;
            }
        }

        update_option('__fluent_cart_wc_attachment_map', $this->attachmentMap);
        $this->migrationSteps['attachments'] = 'yes';
        update_option('__fluent_cart_wc_migration_steps', $this->migrationSteps);

        return $this->attachmentMap;
    }

    private function migrateSingleAttachment($attachment)
    {
        // Check if attachment already exists by GUID
        $existingAttachment = get_posts([
            'post_type' => 'attachment',
            'guid' => $attachment->guid,
            'posts_per_page' => 1
        ]);

        if ($existingAttachment) {
            return $existingAttachment[0]->ID;
        }

        // Get attachment file path and URL
        $uploadDir = wp_upload_dir();
        $filePath = get_attached_file($attachment->ID);
        $fileUrl = wp_get_attachment_url($attachment->ID);

        if (!$filePath || !file_exists($filePath)) {
            return new \WP_Error('attachment_not_found', 'Attachment file not found: ' . $filePath);
        }

        // Copy file to new location if needed
        $fileName = basename($filePath);
        $newFilePath = $uploadDir['path'] . '/' . $fileName;
        
        if ($filePath !== $newFilePath && !file_exists($newFilePath)) {
            copy($filePath, $newFilePath);
        }

        // Prepare attachment data
        $attachmentData = [
            'post_mime_type' => $attachment->post_mime_type,
            'post_title' => $attachment->post_title,
            'post_content' => $attachment->post_content,
            'post_excerpt' => $attachment->post_excerpt,
            'post_status' => 'inherit',
            'guid' => $fileUrl
        ];

        // Insert attachment
        $newAttachmentId = wp_insert_attachment($attachmentData, $newFilePath);
        if (is_wp_error($newAttachmentId)) {
            return $newAttachmentId;
        }

        // Generate attachment metadata
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        $attachmentData = wp_generate_attachment_metadata($newAttachmentId, $newFilePath);
        wp_update_attachment_metadata($newAttachmentId, $attachmentData);

        // Copy attachment meta
        $meta = get_post_meta($attachment->ID);
        if ($meta) {
            foreach ($meta as $key => $values) {
                foreach ($values as $value) {
                    update_post_meta($newAttachmentId, $key, maybe_unserialize($value));
                }
            }
        }

        return $newAttachmentId;
    }

    /**
     * Migrate all WooCommerce products to FluentCart.
     *
     * This is the main entry point for product migration. It migrates categories first, then all products.
     * For each product, it handles variations, images, downloadable files, stock, and meta mapping.
     *
     * @param bool $willUpdate Whether to update existing FluentCart products
     * @return array|\WP_Error Migration results or error
     */
    public function migrate_products($willUpdate = false)
    {
        $check = $this->checkWooCommerceDependencies();
        if (is_wp_error($check)) {
            return $check;
        }

        // Migrate categories and brands first
        $this->migrateCategories();
        $this->migrateBrands();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wooProducts = get_posts([
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'fields'         => 'all',
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'no_found_rows'  => true,
        ]);

        if (empty($wooProducts)) {
            return new \WP_Error('no_products', 'No WooCommerce products found to migrate.');
        }

        $results = [
            'success' => 0,
            'failed' => 0,
            'failed_ids' => []
        ];

        foreach ($wooProducts as $wooProduct) {
            $result = $this->migrateProduct($wooProduct, $willUpdate);
            if (is_wp_error($result)) {
                $results['failed']++;
                $results['failed_ids'][$wooProduct->ID] = $result->get_error_message();
            } else {
                $results['success']++;
            }
        }

        return $results;
    }

    /**
     * Migrate all WooCommerce product categories to FluentCart.
     *
     * Ensures all categories and their hierarchy are recreated in FluentCart, including meta and thumbnails.
     * Maintains a mapping between WooCommerce and FluentCart category IDs for later use.
     *
     * Fix: Two-pass migration to ensure parent-child relationships are set correctly.
     */
    private function migrateCategories()
    {
        // Ensure the product-categories taxonomy is registered
        if (!taxonomy_exists('product-categories')) {
            $fluentProducts = new \FluentCart\App\CPT\FluentProducts();
            $fluentProducts->registerProductTaxonomies();
        }

        $wooCategories = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false
        ]);

        if (is_wp_error($wooCategories)) {
            return $wooCategories;
        }

        // If categories step is marked as completed, verify that all mapped categories still exist
        if ($this->migrationSteps['categories'] == 'yes' && !empty($this->categoryMap)) {
            if ($this->verifyCategoryMappingIntegrity()) {
                return $this->categoryMap;
            } else {
                $this->categoryMap = []; // Reset mapping to force recreation
            }
        }

        // First pass: create all categories without parents
        foreach ($wooCategories as $wooCat) {
            $newCatId = null;
            $existingCat = get_term_by('slug', $wooCat->slug, 'product-categories');
            if ($existingCat) {
                $newCatId = $existingCat->term_id;
            } else {
                $result = wp_insert_term(
                    $wooCat->name,
                    'product-categories',
                    [
                        'description' => $wooCat->description,
                        'slug' => $wooCat->slug,
                        'parent' => 0
                    ]
                );
                if (!is_wp_error($result)) {
                    $newCatId = $result['term_id'];
                }
            }
            if ($newCatId) {
                $this->categoryMap[$wooCat->term_id] = $newCatId;
            }
        }

        // Second pass: update parents
        foreach ($wooCategories as $wooCat) {
            if ($wooCat->parent && isset($this->categoryMap[$wooCat->parent]) && isset($this->categoryMap[$wooCat->term_id])) {
                wp_update_term($this->categoryMap[$wooCat->term_id], 'product-categories', ['parent' => $this->categoryMap[$wooCat->parent]]);
            }
        }

        update_option('__fluent_cart_wc_category_map', $this->categoryMap);
        $this->migrationSteps['categories'] = 'yes';
        update_option('__fluent_cart_wc_migration_steps', $this->migrationSteps);

        return $this->categoryMap;
    }

    /**
     * Verify that all mapped categories still exist in the database
     * @return bool True if all categories exist, false if any are missing
     */
    private function verifyCategoryMappingIntegrity()
    {
        if (empty($this->categoryMap)) {
            return false;
        }

        foreach ($this->categoryMap as $wooCatId => $fluentCatId) {
            $fluentCat = get_term($fluentCatId, 'product-categories');
            if (!$fluentCat || is_wp_error($fluentCat)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Migrate all WooCommerce brands to FluentCart brands taxonomy.
     *
     * Ensures all brands are created and mapped, and mapping is available for product assignment.
     */
    private function migrateBrands()
    {
        return $this->migrateTaxonomy('product_brand', 'product-brands', '__fluent_cart_wc_brand_map');
    }

    /**
     * Migrate a single WooCommerce product (and its variations, images, downloads) to FluentCart.
     *
     * Handles mapping of product type, fulfillment type, stock, downloadable/virtual flags, images, gallery, and meta.
     * For variable products, processes each variation and ensures correct mapping of downloadable files and stock.
     *
     * @param object $wooProduct The WooCommerce product post object
     * @param bool $willUpdate Whether to update existing FluentCart product
     * @return int|\WP_Error The new FluentCart product ID or error
     */
    private function migrateProduct($wooProduct, $willUpdate = false)
    {
        try {
            $productMeta = get_post_meta($wooProduct->ID);
            $productType = get_post_meta($wooProduct->ID, '_product_type', true);
            $productType = $productType ?: 'simple';

            // Check for child variations regardless of _product_type meta
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $variationCount = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation'",
                $wooProduct->ID
            ));
            if ($variationCount > 0) {
                $productType = 'variable';
            }

            // Clean up price values first
            $price = floatval(Arr::get($productMeta, '_price', [0])[0]);
            $regularPrice = floatval(Arr::get($productMeta, '_regular_price', [$price])[0]);
            $salePrice = floatval(Arr::get($productMeta, '_sale_price', [0])[0]);
            $mainPrice = $salePrice > 0 ? $salePrice : $price;
            $minPriceCents = $this->convertToCents($mainPrice);
            $maxPriceCents = $this->convertToCents($mainPrice);
            $comparePriceCents = $this->convertToCents($regularPrice);

            $data = [
                'post_title' => $wooProduct->post_title,
                'post_content' => $wooProduct->post_content,
                'post_excerpt' => $wooProduct->post_excerpt,
                'post_status' => $wooProduct->post_status,
                'post_type' => 'fluent-products',
                'post_name' => $wooProduct->post_name,
                'post_parent' => $wooProduct->post_parent,
                'menu_order' => $wooProduct->menu_order,
                'post_date' => $wooProduct->post_date,
                'post_date_gmt' => $wooProduct->post_date_gmt,
                'post_modified' => $wooProduct->post_modified,
                'post_modified_gmt' => $wooProduct->post_modified_gmt
            ];

            $existingProduct = get_posts([
                'post_type' => 'fluent-products',
                'name' => $wooProduct->post_name,
                'post_status' => 'any',
                'numberposts' => 1
            ]);

            $createdPostId = 0;
            if ($existingProduct && $willUpdate) {
                $data['ID'] = $existingProduct[0]->ID;
                $createdPostId = wp_update_post($data);
            } elseif (!$existingProduct) {
                $createdPostId = wp_insert_post($data);
            } else {
                return new \WP_Error('product_exists', 'Product already exists with slug: ' . $wooProduct->post_name);
            }

            if (is_wp_error($createdPostId)) {
                return $createdPostId;
            }

            // Handle featured image - use existing image ID
            $thumbnailId = get_post_thumbnail_id($wooProduct->ID);
            if ($thumbnailId) {
                set_post_thumbnail($createdPostId, $thumbnailId);
            }

            // Prepare gallery array for FluentCart - include featured image FIRST, then gallery images
            $galleryArr = [];
            
            // Add featured image first (will be the main product image)
            if ($thumbnailId) {
                $imgUrl = wp_get_attachment_url($thumbnailId);
                $imgTitle = get_the_title($thumbnailId);
                if ($imgUrl) {
                    $galleryArr[] = [
                        'id' => (int)$thumbnailId,
                        'url' => $imgUrl,
                        'title' => $imgTitle
                    ];
                }
            }
            
            // Add gallery images (additional images)
            $galleryIds = get_post_meta($wooProduct->ID, '_product_image_gallery', true);
            $galleryIdArr = $galleryIds ? array_filter(array_map('trim', explode(',', $galleryIds))) : [];
            foreach ($galleryIdArr as $galleryId) {
                // Skip if this gallery image is the same as featured image
                if ($galleryId == $thumbnailId) {
                    continue;
                }
                $imgUrl = wp_get_attachment_url($galleryId);
                $imgTitle = get_the_title($galleryId);
                if ($imgUrl) {
                    $galleryArr[] = [
                        'id' => (int)$galleryId,
                        'url' => $imgUrl,
                        'title' => $imgTitle
                    ];
                }
            }
            if ($galleryArr) {
                update_post_meta($createdPostId, 'fluent-products-gallery-image', $galleryArr);
            }
            if ($galleryIds) {
                update_post_meta($createdPostId, '_product_image_gallery', $galleryIds);
            }

            // Handle categories
            $productCategories = wp_get_post_terms($wooProduct->ID, 'product_cat');
            if (!is_wp_error($productCategories)) {
                $newCatIds = [];
                $catMap = get_option('__fluent_cart_wc_category_map', []);
                foreach ($productCategories as $cat) {
                    if (isset($catMap[$cat->term_id])) {
                        $newCatIds[] = intval($catMap[$cat->term_id]);
                    }
                }
                if ($newCatIds) {
                    wp_set_object_terms($createdPostId, $newCatIds, 'product-categories');
                }
            }

            // Brands
            $productBrands = wp_get_post_terms($wooProduct->ID, 'product_brand');
            if (!is_wp_error($productBrands)) {
                $newBrandIds = [];
                $brandMap = get_option('__fluent_cart_wc_brand_map', []);
                foreach ($productBrands as $brand) {
                    if (isset($brandMap[$brand->term_id])) {
                        $newBrandIds[] = intval($brandMap[$brand->term_id]);
                    }
                }
                if ($newBrandIds) {
                    wp_set_object_terms($createdPostId, $newBrandIds, 'product-brands');
                }
            }

            $fulfillmentType = 'physical';
            if (Arr::get($productMeta, '_downloadable', ['no'])[0] === 'yes') {
                $fulfillmentType = 'digital';
            } elseif (Arr::get($productMeta, '_virtual', ['no'])[0] === 'yes') {
                $fulfillmentType = 'service';
            }

            // --- Variable Product Logic ---
            if ($productType === 'variable') {
                global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $variations = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation'",
                    $wooProduct->ID
                ));
                if (!empty($variations)) {
                    $variationPrices = [];
                    $variationIds = [];
                    $firstVariationId = null;
                    $variationDownloadMap = []; // Collect downloadable files for all variations
                    $hasDownloadableVariations = false; // Track if any variations have downloads
                    $hasStockManagement = false; // Track if any variation has stock management enabled
                    
                    // First pass: Check if any variations have downloadable files and stock management
                    foreach ($variations as $variation) {
                        $variationDownloadableFiles = get_post_meta($variation->ID, '_downloadable_files', true);
                        if (!empty($variationDownloadableFiles)) {
                            $hasDownloadableVariations = true;
                        }
                        $variationMeta = get_post_meta($variation->ID);
                        if (Arr::get($variationMeta, '_manage_stock', ['no'])[0] === 'yes') {
                            $hasStockManagement = true;
                        }
                        if ($hasDownloadableVariations && $hasStockManagement) {
                            break;
                        }
                    }
                    
                    // Override fulfillment type if variations have downloads
                    if ($hasDownloadableVariations) {
                        $fulfillmentType = 'digital';
                    }
                    
                    foreach ($variations as $variation) {
                        $variationMeta = get_post_meta($variation->ID);
                        $variationSalePrice = floatval(Arr::get($variationMeta, '_sale_price', [0])[0]);
                        $variationPrice = floatval(Arr::get($variationMeta, '_price', [0])[0]);
                        $variationRegularPrice = floatval(Arr::get($variationMeta, '_regular_price', [$variationPrice])[0]);
                        $mainVariationPrice = $variationSalePrice > 0 ? $variationSalePrice : $variationPrice;
                        $variationPrices[] = $mainVariationPrice;
                        $attributes = [];
                        foreach ($variationMeta as $key => $value) {
                            if (strpos($key, 'attribute_') === 0) {
                                $attributeName = str_replace('attribute_', '', $key);
                                $attributes[$attributeName] = $value[0];
                            }
                        }
                        $variationTitle = [];
                        foreach ($attributes as $name => $value) {
                            $term = get_term_by('slug', $value, $name);
                            $variationTitle[] = $term ? $term->name : $value;
                        }
                        $manageStock = $hasStockManagement ? true : (Arr::get($variationMeta, '_manage_stock', ['no'])[0] === 'yes');
                        $stockStatus = get_post_meta($wooProduct->ID, '_stock_status', true) ?: 'instock';
                        if ($stockStatus === 'instock') {
                            $stockStatus = 'in-stock';
                        }
                        $stockQuantity = (int) Arr::get($variationMeta, '_stock', [0])[0];
                        $backorders = Arr::get($variationMeta, '_backorders', ['no'])[0] === 'yes' ? 1 : 0;
                        // Variation-specific image
                        $variationImageId = isset($variationMeta['_thumbnail_id'][0]) ? (int)$variationMeta['_thumbnail_id'][0] : null;
                        // Variation-specific downloads
                        $variationDownloadableFiles = get_post_meta($variation->ID, '_downloadable_files', true);
                        // Set fulfillment_type based on variation's _virtual property
                        $variationFulfillmentType = 'physical';
                        if (Arr::get($variationMeta, '_virtual', ['no'])[0] === 'yes') {
                            $variationFulfillmentType = 'digital';
                        }
                        // Set stock_status to 'out-of-stock' if stock is zero
                        $variationStockStatus = $stockStatus;
                        if ($stockQuantity === 0) {
                            $variationStockStatus = 'out-of-stock';
                        }
                        $variationId = $this->createOrUpdateProductVariations($createdPostId, [
                            'media_id' => $variationImageId,
                            'variation_title' => implode(' - ', $variationTitle) ?: $wooProduct->post_title,
                            'variation_identifier' => $variation->ID,
                            'payment_type' => 'onetime',
                            'fulfillment_type' => $variationFulfillmentType,
                            'item_status' => 'active',
                            'item_price' => $this->convertToCents($mainVariationPrice),
                            'compare_price' => $this->convertToCents($variationRegularPrice),
                            'downloadable' => !empty($variationDownloadableFiles) ? 1 : 0,
                            'manage_stock' => $manageStock ? 1 : 0,
                            'stock_status' => $variationStockStatus,
                            'total_stock' => $stockQuantity,
                            'available' => $stockQuantity,
                            'backorders' => $backorders,
                            'created_at' => current_time('mysql'),
                            'updated_at' => current_time('mysql'),
                            'other_info' => json_encode([
                                'description' => '',
                                'payment_type' => 'onetime',
                                'attributes' => $attributes,
                                'variation_image_id' => $variationImageId
                            ])
                        ]);
                        if (!$firstVariationId) {
                            $firstVariationId = $variationId;
                        }
                        $variationIds[] = $variationId;
                        // Collect downloadable files for variations
                        if (!empty($variationDownloadableFiles)) {
                            $variationDownloadMap[$variationId] = $variationDownloadableFiles;
                        }
                    }
                    // Set product details for variable product
                    $detail = [
                        'post_id' => $createdPostId,
                        'fulfillment_type' => $fulfillmentType,
                        'variation_type' => 'simple_variations',
                        'min_price' => $this->convertToCents(min($variationPrices)),
                        'max_price' => $this->convertToCents(max($variationPrices)),
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql'),
                        'manage_stock' => $hasStockManagement ? 1 : 0,
                        'manage_downloadable' => ($fulfillmentType === 'digital') ? 1 : 0,
                        'stock_availability' => 'in-stock',
                        'other_info' => json_encode([
                            'group_pricing_by' => 'payment_type',
                            'use_pricing_table' => 'no'
                        ]),
                        'default_variation_id' => $firstVariationId,
                        'default_media' => null
                    ];
                    $this->updateProductDetails($createdPostId, $detail);
                    
                    // Handle downloadable files for all variations
                    if (!empty($variationDownloadMap)) {
                        $this->migrateDownloadableFiles($createdPostId, $variationDownloadMap);
                    }
                }
            } else {
                // --- Simple Product Logic ---
                $manageStock = get_post_meta($wooProduct->ID, '_manage_stock', true) === 'yes';
                $stockStatus = get_post_meta($wooProduct->ID, '_stock_status', true) ?: 'instock';
                if ($stockStatus === 'instock') {
                    $stockStatus = 'in-stock';
                }
                $stockQuantity = (int) get_post_meta($wooProduct->ID, '_stock', true);
                $backorders = get_post_meta($wooProduct->ID, '_backorders', true) === 'yes' ? 1 : 0;
                $variationId = $this->createOrUpdateProductVariations($createdPostId, [
                    'variation_title' => $wooProduct->post_title,
                    'variation_identifier' => '0',
                    'payment_type' => 'onetime',
                    'fulfillment_type' => $fulfillmentType,
                    'item_status' => 'active',
                    'item_price' => $minPriceCents,
                    'compare_price' => $comparePriceCents,
                    'downloadable' => $fulfillmentType === 'digital' ? 1 : 0,
                    'manage_stock' => $manageStock ? 1 : 0,
                    'stock_status' => $stockStatus,
                    'total_stock' => $stockQuantity,
                    'available' => $stockQuantity,
                    'backorders' => $backorders,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                    'other_info' => json_encode([
                        'description' => '',
                        'payment_type' => 'onetime'
                    ])
                ]);
                $detail = [
                    'post_id' => $createdPostId,
                    'fulfillment_type' => $fulfillmentType,
                    'variation_type' => 'simple',
                    'min_price' => $minPriceCents,
                    'max_price' => $maxPriceCents,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                    'manage_stock' => $manageStock ? 1 : 0,
                    'manage_downloadable' => ($fulfillmentType === 'digital') ? 1 : 0,
                    'stock_availability' => $stockStatus === 'instock' ? 'in-stock' : 'out-of-stock',
                    'other_info' => json_encode([
                        'group_pricing_by' => 'repeat_interval',
                        'use_pricing_table' => 'yes'
                    ]),
                    'default_variation_id' => $variationId,
                    'default_media' => null
                ];
                $this->updateProductDetails($createdPostId, $detail);
                
                // Handle downloadable files for simple product
                if ($fulfillmentType === 'digital') {
                    $simpleDownloadableFiles = get_post_meta($wooProduct->ID, '_downloadable_files', true);
                    if ($simpleDownloadableFiles) {
                        $this->migrateDownloadableFiles($createdPostId, [$variationId => $simpleDownloadableFiles]);
                    }
                }
            }
            return $createdPostId;
        } catch (\Exception $e) {
            return new \WP_Error('migration_failed', $e->getMessage());
        }
    }

    /**
     * Create or update a FluentCart product variation.
     *
     * Ensures the variation is created or updated with the correct price, stock, fulfillment type, downloadable flag, and meta.
     * Also creates product meta for variation images if present.
     *
     * @param int $productId The FluentCart product ID
     * @param array $data The variation data
     * @return int The FluentCart variation ID
     */
    private function createOrUpdateProductVariations($productId, $data)
    {
        global $wpdb;
        
        // Check if variation exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $existingVariation = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fct_product_variations WHERE post_id = %d AND variation_identifier = %s",
                $productId,
                $data['variation_identifier']
            )
        );

        // Prices should already be converted to cents before reaching this function
        
        $variationId = 0;
        
        if ($existingVariation) {
            // Update existing variation
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $wpdb->prefix . 'fct_product_variations',
                array_merge($data, ['post_id' => $productId]),
                ['id' => $existingVariation->id]
            );
            $variationId = $existingVariation->id;
        } else {
            // Insert new variation
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->insert(
                $wpdb->prefix . 'fct_product_variations',
                array_merge($data, ['post_id' => $productId])
            );
            $variationId = $wpdb->insert_id;
        }

        // Create product meta for variation image if media_id is set
        if (!empty($data['media_id'])) {
            $this->createVariationImageMeta($variationId, $data['media_id'], $data['variation_title']);
        }
        
        return $variationId;
    }

    /**
     * Create or update product meta for a variation image.
     *
     * Links a media attachment to a variation for use as its thumbnail in FluentCart.
     *
     * @param int $variationId The variation ID
     * @param int $mediaId The media/attachment ID
     * @param string $variationTitle The variation title
     */
    private function createVariationImageMeta($variationId, $mediaId, $variationTitle)
    {
        global $wpdb;
        
        // Get image details
        $imageUrl = wp_get_attachment_url($mediaId);
        $imageTitle = get_the_title($mediaId);
        
        if (!$imageUrl) {
            return;
        }
        
        // Prepare the meta value array (same structure as manually created)
        $metaValue = [
            [
                'id' => (int)$mediaId,
                'title' => $imageTitle ?: $variationTitle,
                'url' => $imageUrl
            ]
        ];
        
        // Check if meta already exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $existingMeta = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fct_product_meta WHERE object_id = %d AND object_type = 'product_variant_info' AND meta_key = 'product_thumbnail'",
            $variationId
        ));
        
        if ($existingMeta) {
            // Update existing meta
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $wpdb->prefix . 'fct_product_meta',
                [
                    //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                    'meta_value' => serialize($metaValue),
                    'updated_at' => current_time('mysql')
                ],
                [
                    'object_id' => $variationId,
                    'object_type' => 'product_variant_info',
                    //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                    'meta_key' => 'product_thumbnail'
                ]
            );
        } else {
            // Insert new meta
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->insert(
                $wpdb->prefix . 'fct_product_meta',
                [
                    'object_id' => $variationId,
                    'object_type' => 'product_variant_info',
                    //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                    'meta_key' => 'product_thumbnail',
                    //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                    'meta_value' => serialize($metaValue),
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ]
            );
        }

    }

    /**
     * Update or insert product details for a FluentCart product.
     *
     * Handles the main product details row, including fulfillment type, stock, downloadable flag, and meta.
     *
     * @param int $createdPostId The FluentCart product ID
     * @param array $detail The product details data
     * @return int The FluentCart product details ID
     */
    private function updateProductDetails($createdPostId, $detail)
    {
        global $wpdb;
        
        // Check if product details exist
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $existingDetails = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fct_product_details WHERE post_id = %d",
            $createdPostId
        ));

        if ($existingDetails) {
            // Update existing details
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $wpdb->prefix . 'fct_product_details',
                $detail,
                ['post_id' => $createdPostId]
            );
            return $existingDetails->id;
        }

        // Insert new details
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert(
            $wpdb->prefix . 'fct_product_details',
            $detail
        );
        
        return $wpdb->insert_id;
    }

    /**
     * Convert a price to cents (integer) for FluentCart storage.
     *
     * Ensures all prices are stored as integer cents, not floats.
     *
     * @param float|string $price The price value
     * @return int The price in cents
     */
    private function convertToCents($price)
    {
        if (empty($price)) {
            return 0;
        }
        // Just cast to float and multiply by 100
        return round(floatval($price) * 100);
    }

    /**
     * Migrate downloadable files from WooCommerce to FluentCart.
     *
     * For each downloadable file, copies it to the FluentCart uploads directory, creates a download entry,
     * and links it to the correct product variations. Handles file path resolution for WooCommerce's storage format.
     *
     * @param int $productId The FluentCart product ID
     * @param array $variationDownloadMap Array mapping variation IDs to their downloadable files
     * @return bool True on success
     */
    private function migrateDownloadableFiles($productId, $variationDownloadMap = [])
    {
        if (empty($variationDownloadMap)) {
            return true;
        }

        global $wpdb;

        // Group variations by their downloadable files
        $downloadGroups = [];
        foreach ($variationDownloadMap as $variationId => $downloadableFiles) {
            if (empty($downloadableFiles)) {
                continue;
            }
            
            // For each file, create a download group
            foreach ($downloadableFiles as $fileId => $file) {
                $fileKey = $file['file']; // Use file path as key to group identical files
                
                if (!isset($downloadGroups[$fileKey])) {
                    $downloadGroups[$fileKey] = [
                        'file' => $file,
                        'variation_ids' => []
                    ];
                }
                $downloadGroups[$fileKey]['variation_ids'][] = $variationId;
            }
        }

        // Delete existing downloads for this product
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete(
            $wpdb->prefix . 'fct_product_downloads',
            ['post_id' => $productId]
        );

        // Create download entries for each unique file
        foreach ($downloadGroups as $fileKey => $downloadGroup) {
            $file = $downloadGroup['file'];
            $variationIds = $downloadGroup['variation_ids'];
            
            // Get file details
            $fileName = basename($file['file']);
            $fileSize = $this->getFileSize($file['file']);
            $fileType = $this->getFileType($fileName);

            // Copy file to FluentCart uploads directory if not already present
            $uploadDir = wp_upload_dir();
            $sourceFile = $file['file'];

            // If it's a URL, convert to local path
            if (filter_var($sourceFile, FILTER_VALIDATE_URL)) {
                $uploadsBaseUrl = $uploadDir['baseurl'];
                $uploadsBaseDir = $uploadDir['basedir'];
                if (strpos($sourceFile, $uploadsBaseUrl) === 0) {
                    $sourceFile = $uploadsBaseDir . substr($sourceFile, strlen($uploadsBaseUrl));
                }
            }

            // If it's a relative path, prepend uploads basedir
            if (!file_exists($sourceFile) && strpos($sourceFile, '/') !== 0 && strpos($sourceFile, ':') === false) {
                $possibleSource = $uploadDir['basedir'] . '/' . ltrim($sourceFile, '/');
                if (file_exists($possibleSource)) {
                    $sourceFile = $possibleSource;
                }
            }

            $destDir = $uploadDir['basedir'] . '/fluent-cart/';
            if (!file_exists($destDir)) {
                wp_mkdir_p($destDir);
            }
            $destFile = $destDir . $fileName;
            // Only copy if source exists and destination doesn't
            if (file_exists($sourceFile) && !file_exists($destFile)) {
                copy($sourceFile, $destFile);
            }
            
            // Generate unique download identifier
            $downloadIdentifier = wp_generate_uuid4();
            
            // Prepare settings
            $settings = json_encode([
                'download_limit' => '',
                'download_expiry' => '',
                'bucket' => ['400' => ['Invalid Credential']]
            ]);
            
            $downloadData = [
                'post_id' => $productId,
                'product_variation_id' => json_encode($variationIds),
                'download_identifier' => $downloadIdentifier,
                'title' => $file['name'] ?: $fileName,
                'type' => $fileType,
                'driver' => 'local',
                'file_name' => $fileName,
                'file_path' => $fileName, // Store only filename, not full path
                'file_url' => $fileName,  // Store only filename, not full path
                'file_size' => $fileSize,
                'settings' => $settings,
                'serial' => 1,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ];
            
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->insert(
                $wpdb->prefix . 'fct_product_downloads',
                $downloadData
            );
            
            $downloadId = $wpdb->insert_id;
        }

        return true;
    }

    /**
     * Get file size in bytes for a given file path or URL.
     *
     * Used for populating the file_size field in FluentCart's downloads table.
     *
     * @param string $filePath File path or URL
     * @return string File size
     */
    private function getFileSize($filePath)
    {
        // If it's a URL, try to get file size
        if (filter_var($filePath, FILTER_VALIDATE_URL)) {
            $headers = get_headers($filePath, 1);
            if (isset($headers['Content-Length'])) {
                return $headers['Content-Length'];
            }
        }
        
        // If it's a local file path
        if (file_exists($filePath)) {
            return filesize($filePath);
        }
        
        // Default size
        return '102713';
    }

    /**
     * Get file type based on file extension.
     *
     * Used for populating the type field in FluentCart's downloads table.
     *
     * @param string $fileName File name
     * @return string File type
     */
    private function getFileType($fileName)
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        $typeMap = [
            'pdf' => 'pdf',
            'doc' => 'doc',
            'docx' => 'docx',
            'txt' => 'txt',
            'zip' => 'zip',
            'rar' => 'rar',
            'mp3' => 'mp3',
            'mp4' => 'mp4',
            'jpg' => 'jpg',
            'jpeg' => 'jpeg',
            'png' => 'png',
            'gif' => 'gif'
        ];
        
        return isset($typeMap[$extension]) ? $typeMap[$extension] : 'file';
    }

    /**
     * Generalized taxonomy migration from WooCommerce to FluentCart.
     *
     * @param string $sourceTaxonomy WooCommerce taxonomy (e.g., 'product_cat', 'product_brand')
     * @param string $destTaxonomy FluentCart taxonomy (e.g., 'product-categories', 'product-brands')
     * @param string $optionMapKey Option key for storing the term ID map
     * @return array Term ID map
     */
    private function migrateTaxonomy($sourceTaxonomy, $destTaxonomy, $optionMapKey)
    {
        if (!taxonomy_exists($destTaxonomy)) {
            // Register taxonomy if needed
            // $fluentProducts = new \FluentCart\App\CPT\FluentProducts();
            // $fluentProducts->registerProductTaxonomies();
        }

        $terms = get_terms([
            'taxonomy' => $sourceTaxonomy,
            'hide_empty' => false
        ]);

        if (is_wp_error($terms)) {
            return $terms;
        }

        $termMap = get_option($optionMapKey, []);

        foreach ($terms as $term) {
            $existing = get_term_by('slug', $term->slug, $destTaxonomy);
            if ($existing) {
                $termMap[$term->term_id] = $existing->term_id;
                continue;
            }
            $result = wp_insert_term(
                $term->name,
                $destTaxonomy,
                [
                    'description' => $term->description,
                    'slug' => $term->slug,
                    'parent' => 0 // Add parent mapping if needed
                ]
            );
            if (!is_wp_error($result)) {
                $termMap[$term->term_id] = $result['term_id'];
            }
        }

        update_option($optionMapKey, $termMap);
        return $termMap;
    }
}

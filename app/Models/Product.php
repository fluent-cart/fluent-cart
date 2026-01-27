<?php

namespace FluentCart\App\Models;

use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Concerns\CanSearch;
use FluentCart\App\Models\WpModels\PostMeta;
use FluentCart\App\Models\WpModels\Term;
use FluentCart\App\Models\WpModels\TermRelationship;
use FluentCart\App\Models\WpModels\TermTaxonomy;
use FluentCart\App\Vite;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Database\Orm\Relations\hasOne;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;

/**
 *  Product Model - DB Model for Products
 *
 *  Database Model
 *
 * This model is intended to be use for relationships and DB query
 * For insert update we will use WordPress's native functions
 *
 * @package FluentCart\App\Models
 *
 * @version 1.0.0
 */
class Product extends Model
{
    use CanSearch;

    protected $table = 'posts';

    protected $primaryKey = 'ID';

    protected $hidden = [
        'post_content_filtered',
        'post_password',
        'post_author',
        'to_ping',
        'pinged',
        'post_parent',
        'menu_order',
        'post_mime_type',
        'comment_count',
    ];

    protected $fillable = [
        'post_content',
        'post_title',
        'post_excerpt',
        'post_author',
        'post_date',
        'post_date_gmt',
        'post_content_filtered',
        'post_status',
        'post_type',
        'comment_status',
        'ping_status',
        'post_password',
        'post_name',
        'to_ping',
        'pinged',
        'post_modified',
        'post_modified_gmt',
        'post_parent',
        'menu_order',
        'post_mime_type',
        'guid',
    ];
    const UPDATED_AT = null;
    const CREATED_AT = null;

    protected $appends = [
        'thumbnail',
    ];

    protected $searchable = [
        'post_title',
        'post_status'
    ];

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->post_type = FluentProducts::CPT_NAME;
        });

        static::addGlobalScope('post_type', function (Builder $builder) {
            $builder->where('post_type', '=', FluentProducts::CPT_NAME)->whereNot('post_status', 'auto-draft');
        });
    }

    public function scopePublished($query)
    {
        return $query->where('post_status', 'publish');
    }

    public function scopeStatusOf($query, $status)
    {
        return $query->where('post_status', $status);
    }


    public function scopeAdminAll($query)
    {
        return $query->whereIn('post_status', Status::productAdminAllStatuses());
    }


    /**
     * One2One: Product Details belongs to one Product
     * @return HasOne
     */
    public function detail(): HasOne
    {
        return $this->hasOne(ProductDetail::class, 'post_id', 'ID');
    }

    public function variants(): \FluentCart\Framework\Database\Orm\Relations\HasMany
    {
        return $this->hasMany(ProductVariation::class, 'post_id', 'ID');
    }

    public function getHasSubscriptionAttribute()
    {
        // Ensure the variants relationship is loaded
        $variants = $this->variants;

        foreach ($variants as $variation) {
            if (isset($variation->other_info['payment_type']) &&
                $variation->other_info['payment_type'] === 'subscription') {
                return true;
            }
        }

        return false;
    }

    public function downloadable_files(): \FluentCart\Framework\Database\Orm\Relations\HasMany
    {
        return $this->hasMany(ProductDownload::class, 'post_id', 'ID');
    }

    /**
     * One2One: Product belongs to one Post meta which is : Gallery Image
     * @return hasOne
     */
    public function postmeta(): hasOne
    {
        return $this->hasOne(PostMeta::class, 'post_id', 'ID')
            ->where('postmeta.meta_key', 'fluent-products-gallery-image');
    }

    public function wp_terms(): \FluentCart\Framework\Database\Orm\Relations\HasMany
    {
        return $this->hasMany(
            TermRelationship::class,
            'object_id',
            'ID',
        );
    }

    public function orderItems(): \FluentCart\Framework\Database\Orm\Relations\HasMany
    {
        return $this->hasMany(OrderItem::class, 'post_id', 'ID');
    }

    public function getCategories()
    {
        return get_the_terms($this->ID, 'product-categories');
    }


    public function getTags()
    {
        return get_the_terms($this->ID, 'product-tags');
    }


    public function getMediaUrl($size = 'thumbnail')
    {
        return get_the_post_thumbnail_url($this->ID, $size);
    }


    /*
     * Transforming old getters with accessor
     * Todo check
     */
    public function getTagsAttribute($value)
    {
        return get_the_terms($this->ID, 'product-tags');
    }


    public function getCategoriesAttribute($value)
    {
        return get_the_terms($this->ID, 'product-categories');
    }


    public function getThumbnailAttribute()
    {
        if (empty($this->detail) || empty($this->detail->featured_media)) {
            return Vite::getAssetUrl('images/placeholder.svg');
        }
        return Arr::get($this->detail->featured_media, 'url');
    }


    public function getViewUrlAttribute()
    {
        return get_permalink($this->ID);
    }


    public function getEditUrlAttribute()
    {
        return admin_url('post.php?post=' . $this->ID . '&action=edit');
    }


    public function wpTerms()
    {
        return $this->hasManyThrough(
            TermTaxonomy::class,
            TermRelationship::class,
            'object_id', // Product ID In TermRelationShip Table
            'term_taxonomy_id',
            'ID',
            'term_taxonomy_id',
        );
    }

    public function getTermByType($type)
    {
        return $this
            ->hasMany(TermRelationship::class, 'object_id')
            ->whereHas('taxonomy', function ($query) use ($type) {
                return $query->where('taxonomy', $type);
            })
            ->join('term_taxonomy', 'term_taxonomy.term_taxonomy_id', '=', 'term_relationships.term_taxonomy_id')
            ->join('terms', 'terms.term_id', '=', 'term_taxonomy.term_id')
            ->addSelect('terms.*', 'term_relationships.*');
    }


    /*
    // Get Category Relationship
    */
    public function categories()
    {
        return $this->getTermByType('product-categories');
    }


    public function tags()
    {
        return $this->getTermByType('product-tags');
    }


    /*
     * Todo: Discuss on below relation
     */
    public function thumbUrl(): HasOne
    {
        return $this
            ->hasOne(PostMeta::class, 'post_id')
            ->where('postmeta.meta_key', '_thumbnail_id')
            ->leftJoin('postmeta as image_table', function ($join) {
                $join->on('postmeta.meta_value', '=', 'image_table.post_id')
                    ->where('image_table.meta_key', '=', '_wp_attached_file');
            })
            ->addSelect('postmeta.*', 'image_table.meta_value as image');
    }

    public function licensesMeta(): HasOne
    {
        return $this->hasOne(ProductMeta::class, 'object_id', 'ID')
            ->where('meta_key', 'license_settings');
    }

    public function scopeCartable(Builder $query): Builder
    {
        return $query->whereDoesntHave('licensesMeta')
            ->withWhereHas('variants', function ($query) {
                $query->where('payment_type', '!=', 'subscription')
                    ->with('media');
            });
    }

    public function getProductMeta($metaKey, $objectType = null, $default = null)
    {
        $query = ProductMeta::query()
            ->where('object_id', $this->ID)
            ->where('meta_key', $metaKey);

        if (!is_null($objectType)) {
            $query->where('object_type', $objectType);
        }

        $meta = $query->first();

        if ($meta) {
            return $meta->meta_value;
        }

        return $default;
    }

    public function updateProductMeta($metaKey, $metaValue, $objectType = null)
    {
        $query = ProductMeta::query()
            ->where('object_id', $this->ID)
            ->where('meta_key', $metaKey);


        if (!is_null($objectType)) {
            $query->where('object_type', $objectType);
        }

        $exist = $query->first();

        if ($exist) {
            $exist->meta_value = $metaValue;
            $exist->save();
            return $exist;
        }


        $meta = new ProductMeta();
        $meta->object_id = $this->ID;
        $meta->meta_key = $metaKey;
        $meta->meta_value = $metaValue;
        $meta->object_type = $objectType;
        $meta->save();

        return $meta;
    }

    public function scopeApplyCustomSortBy($query, $sortKey, $sortType = 'DESC')
    {
        //id|date|title|price
        $validKeys = [
            'id'    => 'ID',
            'date'  => 'post_date',
            'title' => 'post_title',
            'price' => 'item_price',
        ];
        $sortBy = Arr::get($validKeys, $sortKey, 'ID');
        $sortType = in_array($sortType, ['ASC', 'DESC']) ? $sortType : 'DESC';

        if ($sortBy === 'item_price') {
            return $query->leftJoin('fct_product_details as pd', 'posts.ID', '=', 'pd.post_id')
                ->orderBy("pd.min_price", $sortType);
        }
        return $query->orderBy($sortBy, $sortType);
    }

    public function scopeByVariantTypes($query, $type = null)
    {
        $validTypes = ['physical', 'digital', 'subscription', 'onetime', 'simple', 'variations'];
        if (!$type || !in_array($type, $validTypes)) {
            return $query;
        }
        if ($type === 'physical' || $type === 'digital') {
            return $query->whereHas('variants', function ($query) use ($type) {
                $query->where('fulfillment_type', $type);
            });
        }
        if ($type === 'subscription' || $type === 'onetime') {
            return $query->whereHas('variants', function ($query) use ($type) {
                $query->where('payment_type', $type);
            });
        }

        if ($type === 'simple') {
            //search from details
            return $query->whereHas('detail', function ($query) {
                $query->where('variation_type', Helper::PRODUCT_TYPE_SIMPLE);
            });
        }
        if ($type === 'variations') {
            return $query->whereHas('detail', function ($query) {
                $query->whereIn('variation_type', [
                    Helper::PRODUCT_TYPE_SIMPLE_VARIATION,
                    Helper::PRODUCT_TYPE_ADVANCE_VARIATION
                ]);
            });
        }

        return $query;
    }

    public function scopeFilterByTaxonomy($query, $taxonomies)
    {

        //example $taxonomies
//        $taxonomies = [
//            'product-categories' => [1, 2, 3],
//            'product-brands' => [4, 5, 6]
//        ];
        $taxonomies = array_filter($taxonomies, function ($taxonomy) {
            return !empty($taxonomy) && is_array($taxonomy);
        });

        if (empty($taxonomies)) {
            return $query;
        }

        foreach ($taxonomies as $taxonomy => $terms) {
            $query->whereHas('wpTerms', function ($query) use ($terms) {
                return $query->search(["term_id" => ["column" => "term_id", "operator" => "in", "value" => $terms]]);
            });
        }

        return $query;
    }

    public function soldIndividually()
    {
        if ($this->detail->other_info && Arr::get($this->detail->other_info, 'sold_individually') === 'yes') {
            return true;
        }

        return false;
    }

    public function isStock(): bool
    {
        $detail = $this->detail;
        if (!$detail) {
            return true;
        }

        $isBundle = $this->isBundleProduct();

        if (!$detail->manage_stock) {
            if ($isBundle) {
                $variation = $detail->default_variation_id
                    ? $this->variants->firstWhere('id', $detail->default_variation_id)
                    : $this->variants->first();

                $childIds = $variation ? Arr::get($variation->other_info, 'bundle_child_ids', []) : [];
                if (!empty($childIds)) {
                    $children = ProductVariation::query()
                        ->whereIn('id', $childIds)
                        ->get(['manage_stock', 'available', 'stock_status']);

                    foreach ($children as $child) {
                        if ((int)$child->manage_stock === 1) {
                            if ((int)$child->available <= 0 || $child->stock_status !== Helper::IN_STOCK) {
                                return false;
                            }
                        }
                    }
                }
            }
            return true;
        }

        $parentInStock = ($detail->stock_availability === Helper::IN_STOCK);
        if (!$isBundle) {
            return $parentInStock;
        }
        if (!$parentInStock) {
            return false;
        }

        $variation = $detail->default_variation_id
            ? $this->variants->firstWhere('id', $detail->default_variation_id)
            : $this->variants->first();

        if (!$variation) {
            return $parentInStock;
        }

        $childIds = Arr::get($variation->other_info, 'bundle_child_ids', []);
        if (empty($childIds)) {
            return $parentInStock;
        }

        $children = ProductVariation::query()
            ->whereIn('id', $childIds)
            ->get(['manage_stock', 'available', 'stock_status']);

        foreach ($children as $child) {
            if ((int)$child->manage_stock === 1) {
                if ((int)$child->available <= 0 || $child->stock_status !== Helper::IN_STOCK) {
                    return false;
                }
            }
        }

        return true;
    }


    public function images(): array
    {
        $images = [];
        $thumbnailImage = $this->thumbnail ?? Vite::getAssetUrl('images/placeholder.svg');

        $galleryImages = get_post_meta(1110, 'fluent-products-gallery-image', true);


        if (!empty($galleryImages)) {
            foreach ($galleryImages as $image) {
                $images[] = [
                    'type'          => 'gallery_image',
                    'url'           => $image['url'],
                    'alt'           => $image['title'],
                    'product_title' => $this->post_title,
                    'attachment_id' => $image['id'],
                ];
            }
        } else {
            $images[] = [
                'type'          => 'thumbnail',
                'url'           => $thumbnailImage,
                'alt'           => $this->post_title,
                'product_title' => $this->post_title,
                'attachment_id' => null,
            ];
        }

        foreach ($this->variants as $variant) {
            if (!empty($variant['media']['meta_value'])) {
                foreach ($variant['media']['meta_value'] as $image) {
                    $images[] = [
                        'type'            => 'variation_image',
                        'url'             => $image['url'],
                        'alt'             => $image['title'],
                        'variation_title' => $variant->variation_title,
                        'variation_id'    => $variant->id,
                        'attachment_id'   => $image['id'],
                    ];
                }

            }
        }
        return $images;
    }

    public function isBundleProduct(): bool
    {
        return $this->detail->other_info && Arr::get($this->detail->other_info, 'is_bundle_product') === 'yes';
    }



    public function scopeBundle($query)
    {
        return $query->whereHas('detail', function ($q) {
            $q->whereNotNull('other_info')
                ->whereRaw("JSON_EXTRACT(other_info, '$.is_bundle_product') = 'yes'");
        });
    }


    public function scopeNonBundle($query)
    {
        return $query->whereHas('detail', function ($q) {
            $q->where(function ($subQuery) {
                $subQuery->whereNull('other_info')
                    ->orWhereRaw("JSON_EXTRACT(other_info, '$.is_bundle_product') != 'yes'")
                    ->orWhereRaw("JSON_EXTRACT(other_info, '$.is_bundle_product') IS NULL");
            });
        });
    }

    public static function duplicateProduct($productId, array $options = []): int
    {
        $originalProduct = static::with([
            'detail',
            'variants' => function ($query) {
                $query->with(['media'])->orderBy('serial_index', 'ASC');
            },
            'downloadable_files'
        ])->find($productId);

        if (!$originalProduct) {
            throw new \RuntimeException(\__('Product not found', 'fluent-cart'), 404);
        }

        return $originalProduct->performDuplicate($options);
    }

    protected function performDuplicate(array $options = []): int
    {
        $importStockManagement = (bool)Arr::get($options, 'import_stock_management', false);
        $importLicenseSettings = (bool)Arr::get($options, 'import_license_settings', false);
        $importDownloadableFiles = (bool)Arr::get($options, 'import_downloadable_files', false);

        $productId = (int)$this->ID;

        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            $newPostData = [
                'post_title'   => $this->post_title . ' (' . \__('Copy', 'fluent-cart') . ')',
                'post_name'    => \sanitize_title($this->post_title . '-copy-' . time()),
                'post_content' => $this->post_content,
                'post_excerpt' => $this->post_excerpt,
                'post_status'  => 'draft',
                'post_type'    => FluentProducts::CPT_NAME,
                'post_author'  => \get_current_user_id(),
            ];

            $newProductId = \wp_insert_post($newPostData);

            if (\is_wp_error($newProductId)) {
                throw new \RuntimeException($newProductId->get_error_message());
            }

            if ($this->detail) {
                $detailData = $this->detail->toArray();

                unset($detailData['id'], $detailData['created_at'], $detailData['updated_at']);
                $detailData['post_id'] = $newProductId;

                if (!$importStockManagement) {
                    $detailData['manage_stock'] = 0;
                    $detailData['stock_status'] = 'in-stock';

                    if (isset($detailData['other_info'])) {
                        $otherInfo = $detailData['other_info'];
                        $detailData['other_info'] = $otherInfo;
                    }
                }

                if ($importLicenseSettings) {
                    $licenseSettings = ProductMeta::query()
                        ->where('object_id', $productId)
                        ->where('object_type', null)
                        ->where('meta_key', 'license_settings')
                        ->first();

                    if ($licenseSettings) {
                        ProductMeta::query()->create([
                            'object_id'   => $newProductId,
                            'object_type' => null,
                            'meta_key'    => 'license_settings',
                            'meta_value'  => $licenseSettings->meta_value
                        ]);
                    }
                }

                if (!$importDownloadableFiles) {
                    $detailData['manage_downloadable'] = 0;
                }

                ProductDetail::query()->create($detailData);
            }

            $variationIdMap = [];
            if ($this->variants) {
                foreach ($this->variants as $originalVariant) {
                    $variantData = $originalVariant->toArray();

                    unset($variantData['id'], $variantData['created_at'], $variantData['updated_at']);
                    $variantData['post_id'] = $newProductId;

                    if (!$importStockManagement) {
                        $variantData['manage_stock'] = 0;
                        $variantData['stock_status'] = 'in-stock';
                        $variantData['total_stock'] = 0;
                        $variantData['available'] = 0;
                        $variantData['on_hold'] = 0;
                        $variantData['committed'] = 0;
                    }

                    $newVariant = ProductVariation::query()->create($variantData);
                    $variationIdMap[$originalVariant->id] = $newVariant->id;

                    if ($originalVariant->media && $newVariant) {
                        foreach ($originalVariant->media as $media) {
                            \wp_set_object_terms(
                                $newVariant->id,
                                $media->term_id ?? $media->id,
                                'product_media'
                            );
                        }
                    }
                }
            }

            if ($importDownloadableFiles && $this->downloadable_files) {
                foreach ($this->downloadable_files as $file) {
                    $fileData = $file->toArray();

                    unset($fileData['id'], $fileData['created_at'], $fileData['updated_at']);
                    $fileData['post_id'] = $newProductId;
                    $fileData['download_identifier'] = Str::uuid();

                    if (!empty($fileData['product_variation_id'])) {
                        $productVariationIds = [];
                        foreach ($fileData['product_variation_id'] as $variationId) {
                            $productVariationIds[] = Arr::get($variationIdMap, $variationId);
                        }
                        $fileData['product_variation_id'] = $productVariationIds;
                    }

                    ProductDownload::query()->create($fileData);
                }
            }

            $featuredImageId = \get_post_thumbnail_id($productId);
            if ($featuredImageId) {
                \set_post_thumbnail($newProductId, $featuredImageId);
            }

            $taxonomies = \get_object_taxonomies(FluentProducts::CPT_NAME);
            foreach ($taxonomies as $taxonomy) {
                $terms = \wp_get_object_terms($productId, $taxonomy, ['fields' => 'ids']);
                if (!empty($terms) && !\is_wp_error($terms)) {
                    \wp_set_object_terms($newProductId, $terms, $taxonomy);
                }
            }

            $postMeta = \get_post_meta($productId);
            if ($postMeta) {
                foreach ($postMeta as $key => $values) {
                    $skipKeys = ['_edit_lock', '_edit_last'];
                    if (in_array($key, $skipKeys)) {
                        continue;
                    }

                    foreach ($values as $value) {
                        \add_post_meta($newProductId, $key, \maybe_unserialize($value));
                    }
                }
            }

            $wpdb->query('COMMIT');

            \do_action('fluent_cart/product_duplicated', [
                'original_product_id' => $productId,
                'new_product_id'      => $newProductId,
                'options'             => [
                    'import_stock_management'   => $importStockManagement,
                    'import_license_settings'   => $importLicenseSettings,
                    'import_downloadable_files' => $importDownloadableFiles,
                ]
            ]);

            return (int)$newProductId;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }
}

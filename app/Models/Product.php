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

        $exist  = $query->first();

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

}

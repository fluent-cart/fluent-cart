<?php

namespace FluentCart\App\Models;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Concerns\CanSearch;
use FluentCart\App\Models\Concerns\CanUpdateBatch;
use FluentCart\Framework\Database\Orm\Relations\BelongsTo;
use FluentCart\Framework\Database\Orm\Relations\HasMany;
use FluentCart\Framework\Database\Orm\Relations\hasOne;
use FluentCart\App\Models\WpModels\PostMeta;
use FluentCart\Framework\Support\Arr;

/**
 *  Product Details Model - DB Model for Product Details
 *
 *  Database Model
 *
 *
 * @package FluentCart\App\Models
 *
 * @version 1.0.0
 */
class ProductDetail extends Model
{

    use CanSearch, CanUpdateBatch;

    protected $table = 'fct_product_details';

    protected $guarded = [
        'id',
    ];

    protected $fillable = [
        'post_id',
        'fulfillment_type',
        'min_price',
        'max_price',
        'default_variation_id',
        'variation_type',
        'stock_availability',
        'other_info',
        'default_media',
        'manage_stock',
        'manage_downloadable'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'post_id'   => 'integer',
        'min_price' => 'double',
        'max_price' => 'double',
    ];

    protected $appends = ['featured_media', 'formatted_min_price', 'formatted_max_price'];

    public function setOtherInfoAttribute($value)
    {
        if (is_array($value) || is_object($value)) {
            $this->attributes['other_info'] = json_encode($value);
        } else {
            $this->attributes['other_info'] = $value;
        }
    }

    public function getOtherInfoAttribute($value)
    {
        return !empty($value) ? json_decode($value, true) : null;
    }


    protected function getFormattedMinPriceAttribute()
    {
        return Helper::toDecimal($this->min_price);
    }

    protected function getFormattedMaxPriceAttribute()
    {
        return Helper::toDecimal($this->max_price);
    }


    /**
     * One2One: Product Details belongs to one Product
     *
     * @return BelongsTo
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'post_id', 'ID');
    }

    /**
     * One2One: Product Details belongs to one Gallery Image
     * @return HasOne
     */
    public function galleryImage(): HasOne
    {
        return $this->hasOne(PostMeta::class, 'post_id', 'post_id')
            ->where('postmeta.meta_key', 'fluent-products-gallery-image');
    }

    // First element of Gallery Image which is considered as featured image
    public function getFeaturedMediaAttribute()
    {
        $meta = $this->galleryImage;

        if ($meta && is_array($meta->meta_value) && !empty($meta->meta_value)) {
            return Arr::first($meta->meta_value); // Return the first element
        }

        return null;
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariation::class, 'post_id', 'post_id')->orderBy('serial_index', 'asc');
    }

    public function attrMap(): HasMany
    {
        return $this->hasMany(AttributeRelation::class, 'object_id', 'id');
    }


    public static function boot()
    {
        parent::boot();
        static::deleting(function ($model) {
            $model->attrMap()->delete();
        });
    }

    public function setDefaultMediaAttribute($value)
    {
        if (is_array($value) || is_object($value)) {
            $this->attributes['default_media'] = json_encode($value);
        } else {
            $this->attributes['default_media'] = $value;
        }
    }

    public function getDefaultMediaAttribute($value)
    {
        return !empty($value) ? json_decode($value, true) : null;
    }

    public function hasPriceVariation()
    {
        return $this->variation_type === 'simple' && $this->max_price !== $this->min_price;
    }

    public function getStockAvailability($variationId = null)
    {
        if (!$this->manage_stock) {
            $availability = [
                'manage_stock'       => false,
                'availability'       => __('In Stock', 'fluent-cart'),
                'class'              => 'in-stock',
                'available_quantity' => null
            ];
        } else if ($this->stock_availability) {
            $availability = [
                'manage_stock'       => true,
                'availability'       => __('In Stock', 'fluent-cart'),
                'class'              => 'in-stock',
                'available_quantity' => $this->stock_availability
            ];
        } else {
            $availability = [
                'manage_stock'       => true,
                'availability'       => __('Out of Stock', 'fluent-cart'),
                'class'              => 'out-of-stock',
                'available_quantity' => $this->stock_availability
            ];
        }

        return apply_filters('fluent_cart/product_stock_availability', $availability, [
            'detail'       => $this,
            'variation_id' => $variationId
        ]);
    }
}

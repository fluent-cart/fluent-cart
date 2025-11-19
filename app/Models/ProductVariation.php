<?php

namespace FluentCart\App\Models;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Concerns\CanSearch;
use FluentCart\App\Models\Concerns\CanUpdateBatch;
use FluentCart\App\Services\PlanUpgradeService;
use FluentCart\App\Services\URL;
use FluentCart\Framework\Database\Orm\Builder;
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
class ProductVariation extends Model
{

    use CanSearch, CanUpdateBatch;

    protected $table = 'fct_product_variations';

    protected $fillable = [
        'post_id',
        'media_id',
        'serial_index',
        'sold_individually',
        'variation_title',
        'variation_identifier',
        'manage_stock',
        'payment_type',
        'stock_status',
        'backorders',
        'total_stock',
        'available',
        'committed',
        'on_hold',
        'fulfillment_type',
        'item_status',
        'manage_cost',
        'item_price',
        'item_cost',
        'compare_price',
        'other_info',
        'downloadable',
        'shipping_class'
    ];


    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'post_id'           => 'integer',
        'media_id'          => 'integer',
        'item_cost'         => 'double',
        'item_price'        => 'double',
        'compare_price'     => 'double',
        'backorders'        => 'integer',
        'total_stock'       => 'integer',
        'available'         => 'integer',
        'committed'         => 'integer',
        'on_hold'           => 'integer',
        'sold_individually' => 'integer',
        'serial_index'      => 'integer',
        'other_info'        => 'array',
    ];

    protected $appends = ['thumbnail'];


    public function getOtherInfoAttribute($value)
    {
        $value = !empty($value) ? json_decode($value, true) : [];

        if($this->payment_type === 'subscription'){
            $isInstallment = (Arr::get($value, 'installment', 'no') === 'yes' && App::isProActive())?
                'yes':'no';
            $value['payment_type'] = 'subscription';
            $value['installment'] = $isInstallment;
            $value['repeat_interval'] = Arr::get($value, 'repeat_interval', 'yearly');
            $value['times'] = Arr::get($value, 'times', 0);
            $value['trial_days'] = Arr::get($value, 'trial_days', 0);
            $value['manage_setup_fee'] = Arr::get($value, 'manage_setup_fee', 'no');
        }
        return $value;
    }



    protected static function booted()
    {
        static::retrieved(function ($product) {
            $product->append('formatted_total');
        });
    }

    protected function getFormattedTotalAttribute()
    {
        return Helper::toDecimal($this->item_price);
    }


    /**
     * One2One: Product Variation belongs to one Product
     *
     * @return \FluentCart\Framework\Database\Orm\Relations\BelongsTo
     */
    public function product(): \FluentCart\Framework\Database\Orm\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class, 'post_id', 'ID');
    }

    public function shippingClass(): \FluentCart\Framework\Database\Orm\Relations\BelongsTo
    {
        return $this->belongsTo(ShippingClass::class, 'shipping_class', 'id');
    }

    /**
     * One2One: Product Variation belongs to one Product detail
     *
     * @return \FluentCart\Framework\Database\Orm\Relations\BelongsTo
     */
    public function product_detail()
    {
        return $this->belongsTo(ProductDetail::class, 'post_id', 'post_id');
    }

    public function media()
    {
        return $this->hasOne(ProductMeta::class, 'object_id', 'id')->select('id', 'object_id', 'meta_value')->where('meta_key', 'product_thumbnail');
    }

    public function product_downloads(): \FluentCart\Framework\Database\Orm\Relations\HasMany
    {
        return $this
            ->hasMany(ProductDownload::class, 'post_id', 'post_id')
            ->where('product_variation_id', 'like', '%' . $this->id . '%')
            ->orWhereNull('product_variation_id')
            ->orWhere('product_variation_id', '[]');

    }

    public function order_items()
    {
        return $this->hasMany(OrderItem::class, 'object_id', 'id');
    }

    public function downloadable_files()
    {
        return $this->hasMany(ProductDownload::class, 'product_variation_id', 'id');
    }

    public function upgrade_paths(): \FluentCart\Framework\Database\Orm\Relations\HasMany
    {
        return $this->hasMany(Meta::class, 'object_id', 'id')
            ->where('object_type', PlanUpgradeService::$metaType)
            ->where('meta_key', PlanUpgradeService::$metaKey);
    }

    public function attrMap()
    {
        return $this->hasMany(AttributeRelation::class, 'object_id', 'id');
    }

    public function getThumbnailAttribute()
    {
        if (empty($this->media) || !is_array($this->media->meta_value)) {
            return null;
        }
        // Ensure the first element exists and has a non-empty 'url' key
        if (empty($this->media->meta_value[0]['url'])) {
            return null;
        }

        return $this->media->meta_value[0]['url'];
    }

    public function scopeGetWithShippingClass(Builder $query)
    {
        $variations = $query->get();

        $shippingMethodIds = $variations->pluck('other_info.shipping_class')->filter(function ($item) {
            return !empty($item);
        })->toArray();


        $shippingMethods = ShippingMethod::query()->whereIn('id', $shippingMethodIds)->get()->keyBy('id');

        $variations->map(function ($variation) use ($shippingMethods) {
            $shippingClassId = Arr::get($variation, 'other_info.shipping_class');
            if (!$shippingClassId) {
                return $variation;
            }
            $method = $shippingMethods->get($shippingClassId);

            if (!$method) {
                return $variation;
            }
            $variation->attributes['shipping_method'] = $method;
            return $variation;
        });


        return $query->get();
    }


    public static function boot()
    {
        parent::boot();
        static::deleting(function ($model) {
            \FluentCart\Api\Meta::deleteVariationMedia($model->id);
            $model->attrMap()->delete();
        });
    }

    /**
     * Check if the product variation can be purchased
     *
     * @param int $quantity
     * @return bool|\WP_Error
     */
    public function canPurchase($quantity = 1)
    {
        if ($this->item_status !== 'active' ||
            $this->product->post_status !== 'publish') {
            return new \WP_Error('unpublished', __('This product is not available for purchase.', 'fluent-cart'));
        }

        if ($this->payment_type === 'subscription' && $quantity > 1) {
            return new \WP_Error('invalid_subscription_quantity', __('You cannot purchase more than one subscription at a time.', 'fluent-cart'));
        }

        $productDetail = $this->product_detail;
        if (!$productDetail) {
            return new \WP_Error('unpublished', __('This product is not available for purchase', 'fluent-cart'));
        }

        if (ModuleSettings::isActive('stock_management')) {
            if (($productDetail->manage_stock && $this->manage_stock) && $quantity > $this->available) {
                return new \WP_Error('insufficient_stock', __('Sorry, this product is currently out of stock.', 'fluent-cart'));
            }
        }

        return true;
    }

    public function getSubscriptionTermsText($withComparePrice = false)
    {
        if ($this->payment_type !== 'subscription') {
            return '';
        }

        $otherInfo = $this->other_info;

        $formattedData = [
            'trial_days'       => Arr::get($otherInfo, 'trial_days', 0),
            'interval'         => Arr::get($otherInfo, 'repeat_interval', 'yearly'),
            'times'            => Arr::get($otherInfo, 'times', 0), // 0 means infinite
            'signup_fee'       => Arr::get($otherInfo, 'signup_fee', 0) ? Helper::toDecimal(Arr::get($otherInfo, 'signup_fee', 0)) : 0,
            'signup_fee_label' => Arr::get($otherInfo, 'signup_fee_name', ''),
            'price'            => Helper::toDecimal($this->item_price),
            'compare_price'    => ($withComparePrice && $this->compare_price > $this->item_price) ? Helper::toDecimal($this->compare_price) : 0,
        ];

        return Helper::getSubscriptionTermText($formattedData);
    }

    public function getPurchaseUrl()
    {
        return site_url('?fluent-cart=instant_checkout&item_id=' . $this->id . '&quantity=1');
    }

    public function soldIndividually()
    {
        if ($this->product) {
            return $this->product->soldIndividually();
        }
        return false;
    }
}

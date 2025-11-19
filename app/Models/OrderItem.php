<?php

namespace FluentCart\App\Models;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\OrderItemHelper;
use FluentCart\App\Models\Concerns\CanSearch;
use FluentCart\App\Models\Concerns\CanUpdateBatch;
use FluentCart\App\Models\WpModels\PostMeta;
use FluentCart\Framework\Database\Orm\Relations\HasOne;

/**
 *  OrderItem Model - DB Model for Order Items
 *
 *  Database Model
 *
 * @package FluentCart\App\Models
 *
 * @version 1.0.0
 */
class OrderItem extends Model
{
    use CanSearch, CanUpdateBatch;

    protected $table = 'fct_order_items';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'order_id',
        'post_id',
        'fulfillment_type',
        'fulfilled_quantity',
        'post_title',
        'title',
        'object_id',
        'cart_index',
        'quantity',
        'unit_price',
        'cost',
        'subtotal',
        'tax_amount',
        'discount_total',
        'refund_total',
        'line_total',
        'rate',
        'other_info',
        'line_meta',
        'referrer',
        'object_type',
        'payment_type',
        'created_at',
//        'shipping_charge'
    ];
    protected $appends = ['payment_info', 'setup_info'];

    protected $casts = [
        'unit_price'         => 'double',
        'cost'               => 'double',
        'subtotal'           => 'double',
        'tax_amount'         => 'double',
        'shipping_charge'    => 'double',
        'discount_total'     => 'double',
        'line_total'         => 'double',
        'refund_total'       => 'double',
    ];

    protected static function booted()
    {
        static::retrieved(function ($product) {
            $product->append('formatted_total');
        });
    }

    protected function getFormattedTotalAttribute()
    {
        return Helper::toDecimal($this->subtotal);
    }

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
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return $decoded ?: [];
        }

        return [];
    }

    public function setLineMetaAttribute($value)
    {
        if (is_array($value) || is_object($value)) {
            $this->attributes['line_meta'] = json_encode($value);
        } else {
            $this->attributes['line_meta'] = [];
        }
    }

    public function getLineMetaAttribute($value)
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if ($decoded) {
                return $decoded;
            }
        }

        return [];
    }

    public function getFullNameAttribute($value)
    {
        return $this->title . ' ' . $this->post_title;
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    public function product()

    {
        return $this->belongsTo(Product::class, 'post_id', 'ID');
    }


    public function variants()
    {
        return $this->belongsTo(ProductVariation::class, 'object_id', 'id');
    }

    public function product_downloads()
    {
        return $this->belongsTo(ProductDownload::class, 'post_id', 'post_id');
    }

    public function createItem($orderItems)
    {
        return $this->belongsTo(ProductVariation::class, 'variation_id', 'id');
    }

    public function processCustom($product, $orderId)
    {
        return (new OrderItemHelper())->processCustom($product, $orderId);
    }

    /**
     * Get subscription payment info if available
     *
     * @return string
     */
    public function getPaymentInfoAttribute(): string
    {
        return $this->getSubscriptionInfo('payment');
    }

    /**
     * Get subscription setup info if available
     *
     * @return string
     */
    public function getSetupInfoAttribute(): string
    {
        return $this->getSubscriptionInfo('setup');
    }

    /**
     * Helper method to get subscription info
     *
     * @param string $type 'payment' or 'setup'
     * @return string
     */

    private function getSubscriptionInfo(string $type): string
    {
        if ($this->payment_type !== 'subscription') {
            return '';
        }

        $otherInfo = $this->other_info ?? [];
        $unitPrice = $this->unit_price ?? 0;

        if ($type === 'payment') {
            return Helper::generateSubscriptionInfo($otherInfo, $unitPrice) ?? '';
        }

        if ($type === 'setup') {
            return Helper::generateSetupFeeInfo($otherInfo) ?? '';
        }

        return '';
    }

    public function productImage(): HasOne
    {
        return $this->hasOne(PostMeta::class, 'post_id', 'post_id')
            ->where('postmeta.meta_key', 'fluent-products-gallery-image');
    }

    public function variantImages(): HasOne
    {
        return $this->hasOne(ProductMeta::class, 'object_id', 'object_id')
            ->where('object_type', 'product_variant_info')
            ->where('meta_key', 'product_thumbnail');
    }

}

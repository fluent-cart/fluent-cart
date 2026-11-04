<?php

namespace FluentCart\App\Models;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\OrderItemHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Concerns\CanSearch;
use FluentCart\App\Models\Concerns\CanUpdateBatch;
use FluentCart\App\Models\WpModels\PostMeta;
use FluentCart\Framework\Database\Orm\Relations\HasOne;
use FluentCart\Framework\Support\Arr;

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
    protected $appends = ['payment_info', 'setup_info', 'is_custom', 'variation_display_title'];

    protected $casts = [
        'unit_price'      => 'double',
        'cost'            => 'double',
        'subtotal'        => 'double',
        'tax_amount'      => 'double',
        'shipping_charge' => 'double',
        'discount_total'  => 'double',
        'line_total'      => 'double',
        'refund_total'    => 'double',
    ];

    protected static function booted()
    {
        static::retrieved(function ($product) {
            $product->append('formatted_total');
        });
    }

    protected function getFormattedTotalAttribute()
    {
        $amount = ($this->line_total == 0 && $this->discount_total == 0) ? $this->subtotal : $this->line_total;
        if (!is_numeric($amount)) {
            return $amount;
        }

        return Helper::toDecimal($amount, true, $this->orderCurrency());
    }

    private function orderCurrency()
    {
        static $currencies = [];

        if ($this->relationLoaded('order') && $this->order) {
            return $this->order->currency;
        }

        $orderId = (int) $this->order_id;
        if (!array_key_exists($orderId, $currencies)) {
            $currencies[$orderId] = Order::query()->where('id', $orderId)->value('currency');
        }

        return $currencies[$orderId];
    }

    public function getCouponDiscountAttribute()
    {
        return (int)($this->line_meta['coupon_discount'] ?? 0);
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
            return is_array($decoded) ? $decoded : [];
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

    /**
     * All reviews of the product this line item points to — keyed on
     * post_id, not on the item itself, so whereDoesntHave() over it
     * expresses "the customer never reviewed this product".
     */
    public function product_reviews()
    {
        return $this->hasMany(ProductReview::class, 'post_id', 'post_id');
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
            return Helper::generateSubscriptionInfo($otherInfo, $unitPrice, $this->orderCurrency()) ?? '';
        }

        if ($type === 'setup') {
            return Helper::generateSetupFeeInfo($otherInfo, false, $this->orderCurrency()) ?? '';
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

    /**
     * What the customer was charged for this line in cents: line_total, plus tax_amount when the tax was
     * added on top, or 0 for a trial line on its first order. Refunds are recorded against this in refund_total.
     */
    public function chargedTotal(Order $order)
    {
        // A trial line's first order charges only its signup fee, which sits on its own line.
        if ($this->isTrialLineOnFirstOrder($order)) {
            return 0;
        }

        $inclusive = Arr::get($this->line_meta, 'tax_config.inclusive', null);
        if ($inclusive !== null) {
            $inclusive = filter_var($inclusive, FILTER_VALIDATE_BOOLEAN);
        } else {
            $taxInclusion = Arr::get($this->other_info, 'tax_inclusion');
            if ($taxInclusion === 'included' || $taxInclusion === 'excluded') {
                $inclusive = ($taxInclusion === 'included');
            } elseif ($this->isRenewalLineWithTax($order)) {
                $inclusive = true;
            } else {
                $inclusive = ((int) $order->tax_behavior === 2);
            }
        }

        return (int) $this->line_total + ($inclusive ? 0 : (int) $this->tax_amount);
    }

    /**
     * Simulated trials keep trial_days off the line, and renewal lines that keep it were charged.
     */
    private function isTrialLineOnFirstOrder(Order $order)
    {
        return $this->payment_type === 'subscription'
            && (int) Arr::get($this->other_info, 'trial_days', 0) > 0
            && $order->type !== Status::ORDER_TYPE_RENEWAL;
    }

    /**
     * Renewal lines store line_total with the tax already in it and no tax_config.
     */
    private function isRenewalLineWithTax(Order $order)
    {
        $tax = (int) $this->tax_amount;

        return $order->type === Status::ORDER_TYPE_RENEWAL
            && $tax > 0
            && (int) $this->line_total === (int) $this->subtotal - (int) $this->discount_total + $tax;
    }

    public function unitsLeft()
    {
        return max(0, (int) $this->quantity - (int) Arr::get($this->line_meta, 'refunded_quantity', 0));
    }

    /**
     * Shipped units the customer still has: shipped, minus those refunded after shipping.
     */
    public function shippedUnitsKept()
    {
        $shippedRefunded = (int) Arr::get($this->line_meta, 'refunded_shipped_quantity', 0);

        return min(max(0, (int) $this->fulfilled_quantity - $shippedRefunded), $this->unitsLeft());
    }

    public function getIsCustomAttribute()
    {
        return $this->other_info['is_custom'] ?? false;
    }

    public function getViewUrlAttribute()
    {
        return $this->is_custom
            ? ($this->other_info['view_url'] ?? '')
            : '';
    }

    public function getDisplayTitle()
    {
        if ($this->post_title == $this->title) {
            return $this->title;
        }

        return $this->post_title . ' - ' . $this->title;

    }

    /**
     * Labeled attribute combination resolved from the frozen
     * other_info['item_attributes'] snapshot, e.g. "Color: Red | Size: XS".
     * Falls back to the stored variation title when no attribute snapshot
     * resolves (custom/simple items, or attrs whose groups were removed).
     *
     * @return string
     */
    public function getVariationDisplayTitleAttribute()
    {
        // Order items carry the item_attributes snapshot (written at order
        // creation), so resolve directly — getDisplayAttributesString renders
        // the labeled combination and falls back to the variation title.
        return \FluentCart\App\Helpers\AttributeHelper::getDisplayAttributesString(
            Arr::get($this->other_info, 'item_attributes', []),
            $this,
            'order_item'
        );
    }
}

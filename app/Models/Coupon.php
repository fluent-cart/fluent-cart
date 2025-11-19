<?php

namespace FluentCart\App\Models;


use FluentCart\App\Models\Concerns\CanSearch;
use FluentCart\App\Models\Concerns\HasActivity;
use FluentCart\App\Services\DateTime\DateTime;

class Coupon extends Model
{
    use CanSearch, HasActivity;

    protected $table = 'fct_coupons';

    protected $primaryKey = 'id';

    protected $guarded = ['id'];

    protected $fillable = [
        'parent',
        'title',
        'code',
        'status',
        'type',
        'conditions',
        'amount',
        'stackable',
        'priority',
        'use_count',
        'notes',
        'show_on_checkout',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'max_uses' => 'integer',
    ];


    public function setConditionsAttribute($value)
    {

        if ($value) {
            $decoded = \json_encode($value, true);
            if (!($decoded)) {
                $decoded = '[]';
            }
        } else {
            $decoded = '[]';
        }

        $this->attributes['conditions'] = $decoded;
    }

    public function getConditionsAttribute($value)
    {
        if (!$value) {
            return [];
        }

        return \json_decode($value, true);
    }

    // public function setMaxPerCustomer($value){
    // 	//dd($value);
    // 	return empty($value)? $value: ((intval)($value));
    // }

    // public function getMaxPerCustomer($value){
    // 	return empty($value)? ((intval)($value)): $value;
    // }

    public function appliedCoupons()
    {
        return $this->hasMany(AppliedCoupon::class, 'coupon_id', 'id');
    }

    public function orders(): \FluentCart\Framework\Database\Orm\Relations\BelongsToMany
    {
        return $this->belongsToMany(Order::class, 'fct_applied_coupons', 'coupon_id', 'order_id');
    }

    public function setSettingsAttribute($value)
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $this->attributes['settings'] = $value;
    }

    public function getSettingsAttribute($value)
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return $decoded ?: $value;
        }

        return $value;
    }

    public function setOtherInfoAttribute($value)
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (is_array($value) || is_object($value)) {
            // Ensure `buy_products` and `get_products` are arrays before mapping
            if (isset($value['buy_products']) && is_array($value['buy_products'])) {
                $value['buy_products'] = array_map('intval', $value['buy_products']);
            }

            if (isset($value['get_products']) && is_array($value['get_products'])) {
                $value['get_products'] = array_map('intval', $value['get_products']);
            }

            // Encode the modified object or array back to JSON
            $this->attributes['other_info'] = json_encode($value);
        } else {
            // Handle non-array or non-object cases
            $this->attributes['other_info'] = json_encode([]);
        }

    }

    public function getOtherInfoAttribute($value)
    {
        return !empty($value) ? json_decode($value, true) : [];
    }

    public function setCategoriesAttribute($value)
    {
        if (is_array($value) || is_object($value)) {
            $this->attributes['categories'] = json_encode($value);
        } else {
            $this->attributes['categories'] = json_encode([]);
        }
    }

    public function getCategoriesAttribute($value)
    {
        return !empty($value) ? json_decode($value, true) : [];
    }

    public function setProductsAttribute($value)
    {
        if (is_array($value) || is_object($value)) {
            // Convert each item to integer
            $value = array_map('intval', $value);
            $this->attributes['products'] = json_encode($value);
        } else {
            $this->attributes['products'] = json_encode([]);
        }
    }

    public function getProductsAttribute($value)
    {
        return !empty($value) ? json_decode($value, true) : [];
    }

    public function getEndDate()
    {
        return $this->end_date;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function setStatus($value)
    {
        $this->status = $value;
    }

    public function getMeta($metaKey, $default = null)
    {
        $exist = Meta::query()
            ->where('object_type', 'coupon')
            ->where('object_id', $this->id)
            ->where('meta_key', $metaKey)->first();

        if ($exist) {
            return $exist->meta_value;
        }

        return $default;
    }

    public function updateMeta($metaKey, $metaValue)
    {
        $exist = Meta::query()
            ->where('object_type', 'coupon')
            ->where('object_id', $this->id)
            ->where('meta_key', $metaKey)->first();

        if ($exist) {
            $exist->meta_value = $metaValue;
            $exist->save();
        } else {
            $exist = Meta::query()->create([
                'object_id'   => $this->id,
                'object_type' => 'coupon',
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_key'    => $metaKey,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'meta_value'  => $metaValue,
            ]);
        }

        return $exist;
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '=', '0000-00-00 00:00:00')
                    ->orWhere('end_date', '>', DateTime::gmtNow());
            });
    }

}

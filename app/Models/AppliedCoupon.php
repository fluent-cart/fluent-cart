<?php

namespace FluentCart\App\Models;
use FluentCart\App\Models\Concerns\CanUpdateBatch;

/**
 * AppliedCoupon Model - DB Model for Applied Coupons table
 *
 * Database Model
 *
 * @package FluentCart\App\Models
 *
 * @version 1.0.0
 */
class AppliedCoupon extends Model
{
    use CanUpdateBatch;
    
    protected $table = 'fct_applied_coupons';

    protected $primaryKey = 'id';

    protected $guarded = ['id'];

    protected $fillable = [
        'order_id',
        'coupon_id',
        'code',
        'amount',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    public function coupon()
    {
        return $this->belongsTo(Coupon::class, 'code', 'id');
    }

    public function setSettingsAttribute($value)
	{
		if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $this->attributes['meta_value'] = $value;
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
}

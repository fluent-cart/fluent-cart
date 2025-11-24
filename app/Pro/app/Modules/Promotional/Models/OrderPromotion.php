<?php

namespace FluentCartPro\App\Modules\Promotional\Models;

use FluentCart\App\Models\Model;

/**
 *
 *  Database Model for Order Promotions
 *
 * @package FluentCartPro\App\Modules\Promotional
 *
 * @version 1.0.0
 */
class OrderPromotion extends Model
{
    protected $table = 'fct_order_promotions';

    protected $primaryKey = 'id';

    protected $guarded = ['id'];

    protected $fillable = [
        'hash',
        'parent_id',
        'type',
        'status',
        'src_object_id',
        'src_object_type',
        'title',
        'description',
        'conditions',
        'config',
        'priority',
    ];

    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->hash)) {
                $model->hash = md5('fct_promotion_' . wp_generate_uuid4() . time());
            }

            if (empty($model->conditions)) {
                $model->conditions = json_encode([]);
            }

            if (empty($model->config)) {
                $model->config = json_encode([]);
            }

        });
    }

    public function setConditionsAttribute($value)
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $this->attributes['conditions'] = $value;
    }

    public function getConditionsAttribute($value)
    {
        if (is_string($value)) {
            return json_decode($value, true);
        }
        return $value;
    }

    public function setConfigAttribute($value)
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $this->attributes['config'] = $value;
    }

    public function getConfigAttribute($value)
    {
        if (is_string($value)) {
            return json_decode($value, true);
        }

        return $value;
    }

    public function product_variant()
    {
        return $this->belongsTo(\FluentCart\App\Models\ProductVariation::class, 'src_object_id', 'id');
    }
}



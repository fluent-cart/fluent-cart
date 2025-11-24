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
class OrderPromotionStat extends Model
{
    protected $table = 'fct_order_promotion_stats';

    protected $primaryKey = 'id';

    protected $guarded = ['id'];

    protected $fillable = [
        'promotion_id',
        'order_id',
        'object_id',
        'amount',
        'status'
    ];

    public static function boot()
    {
        parent::boot();
    }

    public function order()
    {
        return $this->belongsTo(\FluentCart\App\Models\Order::class, 'order_id', 'id');
    }

    public function promotion()
    {
        return $this->belongsTo(OrderPromotion::class, 'promotion_id', 'id');
    }

}



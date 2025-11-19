<?php

namespace FluentCart\App\Models;

/**
 *  Meta Model - DB Model for Meta table
 *
 *  Database Model
 *
 * @package FluentCart\App\Models
 *
 * @version 1.0.0
 */
class OrderOperation extends Model
{
    protected $table = 'fct_order_operations';

    protected $primaryKey = 'id';

    protected $guarded = ['id'];

    protected $fillable = [
        'order_id',
        'created_via',
        'has_tax',
        'has_discount',
        'coupons_counted',
        'emails_sent',
        'sales_recorded',
        'utm_campaign',
        'utm_term',
        'utm_source',
        'utm_content',
        'utm_medium',
        'utm_id',
        'cart_hash',
        'refer_url',
    ];


    public function setMetaAttribute($value)
    {

        if ($value) {
            $decoded = \json_encode($value, true);
            if (!($decoded)) {
                $decoded = '[]';
            }
        } else {
            $decoded = '[]';
        }

        $this->attributes['meta'] = $decoded;
    }

    public function getMetaAttribute($value)
    {
        if (!$value) {
            return [];
        }

        return \json_decode($value, true);
    }

    public function order(): \FluentCart\Framework\Database\Orm\Relations\BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }
}

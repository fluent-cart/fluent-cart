<?php

namespace FluentCart\App\Models;

/**
 *  Meta Model - DB Model for Meta table
 *
 *  Database Model
 *
 *
 * @version 1.0.0
 */
class OrderTaxRate extends Model
{
    protected $table = 'fct_order_tax_rate';

    protected $primaryKey = 'id';

    protected $guarded = ['id'];

    protected $fillable = [
        'order_id',
        'tax_rate_id',
        'shipping_tax',
        'order_tax',
        'total_tax',
        'meta',
        'filed_at',
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

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    public function tax_rate()
    {
        return $this->belongsTo(TaxRate::class, 'tax_rate_id', 'id');
    }

    public function scopeValidOrder($query)
    {
        return $query->whereHas('order', fn ($o) => $o->where('status', 'completed'));
    }
}

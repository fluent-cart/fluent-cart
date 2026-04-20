<?php

namespace FluentCart\App\Models;

use FluentCart\App\Models\Concerns\CanSearch;

/**
 * Shipping Class Model - DB Model for Shipping Classes
 *
 * @package FluentCart\App\Models
 * @version 1.0.0
 */
class ShippingClass extends Model
{
    use CanSearch;

    protected $table = 'fct_shipping_classes';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'description',
        'cost',
        'type',
        'per_item'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'cost' => 'float'
    ];

    public function zones(): \FluentCart\Framework\Database\Orm\Relations\HasMany
    {
        return $this->hasMany(ShippingZone::class, 'shipping_class_id', 'id');
    }
}

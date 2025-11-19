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

    /**
     * Get products that belong to this shipping class
     * This relationship will need to be implemented once you add the shipping_class_id to products
     */
    // public function products()
    // {
    //     return $this->hasMany(Product::class, 'shipping_class_id', 'id');
    // }
}

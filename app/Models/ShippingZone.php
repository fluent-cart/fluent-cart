<?php

namespace FluentCart\App\Models;

use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Models\Concerns\CanSearch;
use FluentCart\App\Services\Localization\LocalizationManager;
use FluentCart\Framework\Database\Orm\Relations\HasMany;

/**
 * Shipping Zone Model - DB Model for Shipping Zones
 *
 * @package FluentCart\App\Models
 * @version 1.0.0
 */
class ShippingZone extends Model
{
    use CanSearch;

    protected $table = 'fct_shipping_zones';

    protected $appends = ['formatted_region'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'region',
        'order'
    ];

    /**
     * Get the shipping methods for this zone.
     */
    public function methods(): HasMany
    {
        return $this->hasMany(ShippingMethod::class, 'zone_id', 'id')
            ->orderBy('id', 'DESC');
    }

    public function getFormattedRegionAttribute()
    {
        if ($this->region === 'all') {
            return __('Whole World', 'fluent-cart');
        }
        if (!empty($this->region)) {
            return AddressHelper::getCountryNameByCode($this->region);
        }
        return '';
    }
}

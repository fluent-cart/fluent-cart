<?php

namespace FluentCart\App\Models;

use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Models\Concerns\CanSearch;
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
        'order',
        'shipping_class_id',
        'meta'
    ];

    /**
     * Get the shipping methods for this zone.
     */
    public function methods(): HasMany
    {
        return $this->hasMany(ShippingMethod::class, 'zone_id', 'id')
            ->orderBy('id', 'DESC');
    }

    public function shippingClass(): \FluentCart\Framework\Database\Orm\Relations\BelongsTo
    {
        return $this->belongsTo(ShippingClass::class, 'shipping_class_id', 'id');
    }

    /**
     * Check if this zone applies to a given country.
     *
     * @param string $country
     * @return bool
     */
    public function appliesToCountry(string $country): bool
    {
        if ($this->region === 'all') {
            return true;
        }

        if ($this->region === 'selection') {
            $meta = $this->meta;
            $countries = isset($meta['countries']) ? $meta['countries'] : [];
            $selectionType = isset($meta['selection_type']) ? $meta['selection_type'] : 'included';

            if ($selectionType === 'excluded') {
                return !in_array($country, $countries);
            }

            return in_array($country, $countries);
        }

        return $this->region === $country;
    }

    public function setMetaAttribute($value)
    {
        if ($value && is_array($value)) {
            $this->attributes['meta'] = \json_encode($value);
        } else {
            $this->attributes['meta'] = '{}';
        }
    }

    public function getMetaAttribute($value)
    {
        if (!$value) {
            return [];
        }
        return \json_decode($value, true) ?: [];
    }

    public function getFormattedRegionAttribute()
    {
        if ($this->region === 'all') {
            return __('Whole World', 'fluent-cart');
        }
        if ($this->region === 'selection') {
            $meta = $this->meta;
            $countries = $meta['countries'] ?? [];
            $type = $meta['selection_type'] ?? 'included';
            $count = count($countries);
            if ($type === 'excluded') {
                /* translators: %d is the number of excluded countries */
                return sprintf(__('All except %d countries', 'fluent-cart'), $count);
            }
            /* translators: %d is the number of selected countries */
            return sprintf(_n('%d country', '%d countries', $count, 'fluent-cart'), $count);
        }
        if (!empty($this->region)) {
            return AddressHelper::getCountryNameByCode($this->region);
        }
        return '';
    }
}

<?php

namespace FluentCart\App\Models;


use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Models\Concerns\CanSearch;
use FluentCart\Framework\Database\Orm\Relations\BelongsTo;

/**
 * Shipping Method Model - DB Model for Shipping Methods
 *
 * @package FluentCart\App\Models
 * @version 1.0.0
 */
class ShippingMethod extends Model
{
    use CanSearch;

    protected $table = 'fct_shipping_methods';

    protected $appends = ['formatted_states'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'zone_id',
        'title',
        'type',
        'settings',
        'amount',
        'is_enabled',
        'order',
        'states',
        'meta'
    ];

    protected $attributes = [
        'states' => '[]',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'settings'   => 'array',
        'states'     => 'array',
        'is_enabled' => 'boolean'
    ];

    /**
     * Get the zone that this method belongs to.
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'zone_id', 'id');
    }

    public function scopeApplicableToCountry($query, $country, $state)
    {

        $query = $query->whereHas('zone', function ($query) use ($country) {
            $query->whereIn('region', [$country, 'all']);
        });

        $query = $query->where(function ($q) use ($state) {
            $q->whereJsonLength('states', 0);

            if ($state) {
                $q->orWhereJsonContains('states', $state);
            }
        });
        $query->orderBy('amount', 'DESC');

        return $query->where('is_enabled', 1);
    }

    public function getFormattedStatesAttribute()
    {

        if (is_array($this->states)) {
            $states = array_map(function ($region) {
                return AddressHelper::getStateNameByCode($region, $this->zone->region);
            }, $this->states);
            return $states;
        }
        return [];
    }

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
}

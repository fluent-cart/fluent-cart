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
            $query->where(function ($q) use ($country) {
                $q->whereIn('region', [$country, 'all'])
                  ->orWhere('region', 'selection');
            });
            $query->where(function ($q) {
                $q->whereNull('shipping_class_id')
                  ->orWhere('shipping_class_id', 0);
            });
        });

        // SQLite-compatible substring extraction
        $isSqlite = defined('DB_ENGINE') && DB_ENGINE === 'sqlite';
        // SQLite: Use simple string search instead of JSON functions
        if ($isSqlite) {
            $query = $query->where(function ($q) use ($state) {
            // Handle empty states
            $q->where('states', '[]')
                ->orWhereNull('states'); // fallback if states column is NULL

            if ($state) {
                // Check if the state exists in the array (simple string search)
                // Escape LIKE wildcards to prevent wildcard injection on public endpoint
                $escapedState = str_replace(['%', '_'], ['\\%', '\\_'], $state);
                $q->orWhere('states', 'LIKE', '%"' . $escapedState . '"%');
            }
        });
        } else {
            // MySQL: Use JSON functions for filtering
            $query = $query->where(function ($q) use ($state) {
                $q->whereJsonLength('states', 0);

                if ($state) {
                    // Shipping methods containing the state
                    $q->orWhereJsonContains('states', $state);
                }
            });
        }
        $query->orderBy('amount', 'DESC');

        return $query->where('is_enabled', 1);
    }

    /**
     * Get shipping methods applicable to a country, with post-filtering for multi-country selection zones.
     *
     * @param string $country
     * @param string|null $state
     * @return \FluentCart\Framework\Database\Orm\Collection
     */
    public static function getApplicableForCountry(string $country, $state = null)
    {
        $methods = static::applicableToCountry($country, $state)
            ->with('zone')
            ->get();

        // Post-filter 'selection' zones that don't actually match this country
        return $methods->filter(function ($method) use ($country) {
            if (!$method->zone || $method->zone->region !== 'selection') {
                return true;
            }
            return $method->zone->appliesToCountry($country);
        });
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
            $decoded = \json_encode($value);
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

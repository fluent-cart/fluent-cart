<?php

namespace FluentCart\App\Models;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Models\Concerns\CanSearch;

/**
 *  Attributes Group Model - DB Model for Attributes Group eg: color, size etc
 *
 *  Database Model
 *
 * @package FluentCart\App\Models
 *
 * @version 1.0.0
 */
class AttributeGroup extends Model
{
    use CanSearch;

    public static function boot()
    {
        parent::boot();
        static::deleting(function ($model) {
            $model->terms()->delete();
        });
    }
    
    protected $table = 'fct_atts_groups';

    protected $fillable = [
        'title',
        'slug',
        'description',
        'settings',
    ];

    public function setSettingsAttribute($value)
    {
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $this->attributes['settings'] = $value;

    }

    public function getSettingsAttribute($value)
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return $decoded ?: $value;
        }

        return $value;
    }

    /**
     * hasMany: Group has many Terms
     *
     * @return \FluentCart\Framework\Database\Orm\Relations\hasMany
     */
    public function terms()
    {
        return $this->hasMany(AttributeTerm::class, 'group_id', 'id');
    }

    public function usedTerms()
    {
        return $this->hasMany(AttributeRelation::class, 'group_id', 'id');
    }



    public function scopeApplyCustomFilters( $query, $filters ) {
		if ( ! $filters ) {
			return $query;
		}

		$acceptedKeys = $this->fillable;

		foreach ( $filters as $filterKey => $filter ) {

			if ( ! in_array( $filterKey, $acceptedKeys ) &&  $filterKey !== 'terms_count') {
				continue;
			}

			$value    = Arr::get( $filter, 'value', '' );
			$operator = Arr::get( $filter, 'operator', '' ); 

			if ( ! $value || ! $operator || is_array( $value ) ) {
				continue;
			}

			switch (strtolower($operator)) {
				case 'includes':
					$operator = "like_all";
					break;
				case 'not_includes':
					$operator = "not_like";
					break;
				case 'gt':
					$operator = ">";
					break;
				case 'lt':
					$operator = "<";
					break;
					
				default:
					
			}

            if ( $filterKey === 'terms_count' ) {
                return $query->havingRaw($filterKey.' '.$operator.' '.$value);
			}

			$param = [ $filterKey => [ "column" =>  $filterKey, "operator" => $operator, "value" => trim( $value ) ] ];
			$query->when($param, function ($query) use ($param) {
				return $query->search($param);
			});
		}

		return $query;
	}

}

<?php

namespace FluentCart\App\Models;
use FluentCart\App\Models\Concerns\CanSearch;
use FluentCart\Framework\Support\Arr;

/**
 *  Attributes Terms Model - DB Model for Attributes Terms eg for Size: Small, Medium, Large
 *
 *  Database Model
 *
 * @package FluentCart\App\Models
 *
 * @version 1.0.0
 */
class AttributeTerm extends Model
{
	use CanSearch;
	
	protected $table = 'fct_atts_terms';

	protected $fillable = [
		'group_id',
		'serial',
		'title',
		'slug',
		'description',
		'settings',
	];

	public function setSettingsAttribute($value)
	{
        if (is_array($value) || is_object($value)) {
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

	public function group()
	{
		return $this->belongsTo(AttributeGroup::class, 'group_id', 'id');
	}

	public function scopeApplyCustomFilters( $query, $filters ) {
		if ( ! $filters ) {
			return $query;
		}

		$acceptedKeys = $this->fillable;

		foreach ( $filters as $filterKey => $filter ) {

			if ( ! in_array( $filterKey, $acceptedKeys ) ) {
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

			$param = [ $filterKey => [ "column" =>  $filterKey, "operator" => $operator, "value" => trim( $value ) ] ];
			$query->when($param, function ($query) use ($param) {
				return $query->search($param);
			});
		}

		return $query;
	}
}

<?php

namespace FluentCart\App\Models;

/**
 *  Label Model - DB Model for Label table
 *
 *  Database Model
 *
 * @package FluentCart\App\Models
 *
 * @version 1.0.0
 */
class Label extends Model
{
	protected $table = 'fct_label';

	protected $fillable = [
		'value',
	];

	protected $casts = [
        'id'   => 'integer',
    ];

	public function setValueAttribute( $value ) {
		$this->attributes['value'] = maybe_serialize($value);
	}


	public function getValueAttribute( $value ) {
        return maybe_unserialize($value);
	}
}

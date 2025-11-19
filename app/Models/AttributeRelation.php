<?php

namespace FluentCart\App\Models;

/**
 *  Attributes Relations Model - DB Model for Attributes Terms to Specific Variations
 * Maybe we don't need this model. For now, it's here
 *
 *  Database Model
 *
 * @package FluentCart\App\Models
 *
 * @version 1.0.0
 */
class AttributeRelation extends Model
{
	protected $table = 'fct_atts_relations';

	protected $fillable = [
		'group_id',
		'term_id',
		'object_id',
	];

	public function group()
	{
		return $this->belongsTo(AttributeGroup::class, 'group_id', 'id');
	}

	public function term()
	{
		return $this->belongsTo(AttributeTerm::class, 'term_id', 'id');
	}

	public function productDetails()
	{
		return $this->belongsTo(ProductDetail::class, 'object_id', 'id');
	}
}

<?php

namespace FluentCart\App\Models;

use FluentCart\App\Helpers\AddressHelper;

/**
 *  Meta Model - DB Model for Meta table
 *
 *  Database Model
 *
 * @package FluentCart\App\Models
 *
 * @version 1.0.0
 */
class TaxRate extends Model
{
	protected $table = 'fct_tax_rates';

    public $timestamps = false;

	protected $primaryKey = 'id';

	protected $guarded = [ 'id' ];

	protected $fillable = [
		'class_id',
		'country',
		'state',
		'postcode',
		'city',
		'rate',
		'name',
        'group',
		'priority',
		'is_compound',
		'for_shipping',
		'for_order',
		'class_id'
	];

    protected $appends = ['formatted_state'];

	public function tax_class() {
		return $this->belongsTo( TaxClass::class, 'class_id', 'id' );
	}

    public function getFormattedStateAttribute()
    {
        if (!empty($this->state)) {
            return AddressHelper::getStateNameByCode($this->state, $this->country);
        }
        return '';
    }

}

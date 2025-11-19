<?php

namespace FluentCart\App\Models;

/**
 *  Meta Model - DB Model for Meta table
 *
 *  Database Model
 *
 * @package FluentCart\App\Models
 *
 * @version 1.0.0
 */
class CustomerMeta extends Model
{
	protected $table = 'fct_customer_meta';

	protected $primaryKey = 'id';

	protected $guarded = [ 'id' ];

	protected $fillable = [
		'customer_id',
		'meta_key',
		'meta_value',
	];

	public function setMetaValueAttribute( $value ) {
        //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        if (is_array( $value ) || is_object( $value ) ) {
            $value = json_encode( $value , JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        }
        //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $this->attributes['meta_value'] = $value;
	}

	public function getMetaValueAttribute( $value ) {
        if (is_string( $value )) {
            $decoded = json_decode($value, true);
            return $decoded ?: $value;
        }
        return $value;
	}

	public function customer() {
		return $this->belongsTo( Customer::class, 'customer_id', 'id' );
	}
}

<?php

namespace FluentCartPro\App\Modules\Licensing\Models;

use FluentCart\App\Models\Model;

/**
 *  Meta Model - DB Model for Meta table
 *
 *  Database Model
 *
 * @package FluentCart\App\Models
 *
 * @version 1.0.0
 */
class LicenseMeta extends Model
{
	protected $table = 'fct_license_meta';

	protected $primaryKey = 'id';

	protected $guarded = [ 'id' ];

	protected $fillable = [
		'object_id',
		'object_type',
		'meta_key',
		'meta_value',
	];

	public function setMetaValueAttribute( $value ) {
		if (is_array( $value ) || is_object( $value ) ) {
            $value = json_encode( $value , JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $this->attributes['meta_value'] = $value;
	}

	public function getMetaValueAttribute( $value ) {
		if (is_string( $value )) {
            return json_decode( $value, true );
        }
        return $value;
	}
}



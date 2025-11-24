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
class LicenseActivation extends Model
{
	protected $table = 'fct_license_activations';

	protected $primaryKey = 'id';

	protected $guarded = [ 'id' ];

	protected $fillable = [
		'site_id',
		'license_id',
		'status',
		'is_local',
		'product_id',
        'last_update_date',
        'last_update_version',
		'variation_id',
		'activation_method',
        'activation_hash'
	];


	public function license() {
		return $this->belongsTo( License::class, 'license_id', 'id' );
	}

    public function site() {
        return $this->belongsTo( LicenseSite::class, 'site_id', 'id' );
    }

    public function updateStatus($newStatus)
    {
        $oldStatus = $this->status;
        $this->status = $newStatus;
        $this->save();

        do_action('fluent_cart_sl/license_activation_status_updated', [
                'license' => $this,
                'old_status' => $oldStatus,
                'new_status' => $newStatus
        ]);
        do_action('fluent_cart_sl/license_activation_status_updated_to_'.$newStatus, [
                'license' => $this,
                'old_status' => $oldStatus,
                'new_status' => $newStatus
            ]);

        return $this;
    }
}

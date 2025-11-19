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
class OrderDownloadPermission extends Model
{
	protected $table = 'fct_order_download_permissions';

	protected $primaryKey = 'id';

	protected $guarded = [ 'id' ];

	protected $fillable = [
		'order_id',
		'variation_id',
		'customer_id',
		'download_id',
		'download_count',
		'download_limit',
		'access_expires',
	];

	public function order() {
		return $this->belongsTo( Order::class, 'order_id', 'id' );
	}

	public function customer() {
		return $this->belongsTo( Customer::class, 'customer_id', 'id' );
	}
}

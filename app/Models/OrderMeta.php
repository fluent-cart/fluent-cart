<?php

namespace FluentCart\App\Models;

use FluentCart\App\Models\Concerns\CanSearch;

/**
 *  Order Meta Model - DB Model for Order Meta table
 *
 *  Database Model
 *
 * @package FluentCart\App\Models
 *
 * @version 1.0.0
 */
class OrderMeta extends Model
{
    use CanSearch;

    protected $table = 'fct_order_meta';

    protected $fillable = [
        'order_id',
        'meta_key',
        'meta_value',
    ];

    public function setMetaValueAttribute($value)
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $this->attributes['meta_value'] = $value;
    }


    public function getMetaValueAttribute($value)
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return $decoded ?: $value;
        }

        return $value;
    }


	/**
	 * One2One: OrderTransaction belongs to one Order
	 *
	 * @return \FluentCart\Framework\Database\Orm\Relations\BelongsTo
	 */
	public function order() {
		return $this->belongsTo( Order::class, 'order_id', 'id' );
	}

    public function updateMeta($metaKey, $metaValue)
    {
        $exist = OrderMeta::query()->where('order_id', $this->id)
            ->where('meta_key', $metaKey)
            ->first();

        if ($exist) {
            $exist->meta_value = $metaValue;
            $exist->save();
        } else {
            $exist = OrderMeta::query()->create([
                'order_id' => $this->id,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_key'    => $metaKey,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'meta_value'  => $metaValue
            ]);
        }

        return $exist;
    }
}

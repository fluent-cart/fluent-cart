<?php

namespace FluentCart\App\Models;

/**
 *  Order Meta Model - DB Model for Order Meta table
 *
 *  Database Model
 *
 * @package FluentCart\App\Models
 *
 * @version 1.0.0
 */
class ProductMeta extends Model
{
    protected $table = 'fct_product_meta';

    protected $primaryKey = 'id';

    protected $guarded = ['id'];

    protected $fillable = [
        'object_id',
        'object_type',
        'meta_key',
        'meta_value',
    ];

    public function setMetaValueAttribute($meta_value)
    {
        if (is_array($meta_value) || is_object($meta_value)) {
            $meta_value = json_encode($meta_value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $this->attributes['meta_value'] = $meta_value;
    }

    public function getMetaValueAttribute($value)
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return $decoded ?: $value;
        }
        return $value;
    }
}

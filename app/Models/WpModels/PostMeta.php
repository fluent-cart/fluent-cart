<?php

namespace FluentCart\App\Models\WpModels;

use FluentCart\App\Models\Model;

class PostMeta extends Model
{
    protected $table = 'postmeta';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'post_id';

    protected $fillable = [
        'post_id',
        'meta_key',
        'meta_value',
    ];

    public function setMetaValueAttribute($value)
    {
        //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $this->attributes['meta_value'] = maybe_serialize($value);
    }

    public function getMetaValueAttribute($value)
    {
        return maybe_unserialize($value);
    }
}

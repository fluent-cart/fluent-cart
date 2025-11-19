<?php

namespace FluentCart\App\Models;

use FluentCart\App\Services\PlanUpgradeService;
use FluentCart\Framework\Database\Orm\Builder;

/**
 *  Meta Model - DB Model for Meta table
 *
 *  Database Model
 *
 * @package FluentCart\App\Models
 *
 * @version 1.0.0
 */
class Meta extends Model
{
    protected $table = 'fct_meta';

    protected $primaryKey = 'id';

    protected $guarded = ['id'];

    protected $fillable = [
        'object_type',
        'object_id',
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



    public function scopeUserTheme(Builder $query)
    {
        return $query
            ->where('object_type', User::class)
            ->where('object_id', get_current_user_id())
            ->where('meta_key', 'theme');
    }

    public function scopeUpgradeablePath($query, $productId)
    {
        return $this->whereHas('upgradeableVariants', function ($query) use ($productId) {
            if (!empty($productId)) {
                return $query->where('post_id', $productId);
            }
        })
            ->where('object_type', PlanUpgradeService::$metaType)
            ->where('meta_key', PlanUpgradeService::$metaKey);
    }

    public function upgradeableVariants()
    {
        return $this->hasMany(ProductVariation::class, 'id', 'object_id');
    }

}

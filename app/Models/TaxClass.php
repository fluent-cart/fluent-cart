<?php

namespace FluentCart\App\Models;
use FluentCart\Framework\Support\Str;

/**
 *  Meta Model - DB Model for Meta table
 *
 *  Database Model
 *
 * @package FluentCart\App\Models
 *
 * @version 1.0.0
 */
class TaxClass extends Model
{
	protected $table = 'fct_tax_classes';

	protected $primaryKey = 'id';

	protected $guarded = [ 'id' ];

	protected $fillable = [
		'title',
        'description',
        'meta',
		'slug'
	];

    protected static function booted()
    {
        static::creating(function ($model) {
            $model->slug = static::generateUniqueSlug($model->title);
        });

        static::updating(function ($model) {
            if ($model->isDirty('title')) {
                $model->slug = static::generateUniqueSlug($model->title, $model->id);
            }
        });
    }

    protected static function generateUniqueSlug($title, $ignoreId = null)
    {
        $base = Str::slug($title);
        if (!$base) {
            $base = 'tax-class';
        }

        $slug = $base;
        $suffix = 2;

        while (static::query()
            ->when($ignoreId, function ($q) use ($ignoreId) {
                $q->where('id', '!=', $ignoreId);
            })
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }
	
	public function setMetaAttribute($value)
    {
        if ($value) {
            $decoded = \json_encode($value, true);
            if (!($decoded)) {
                $decoded = '[]';
            }
        } else {
            $decoded = '[]';
        }

        $this->attributes['meta'] = $decoded;
    }

    public function getMetaAttribute($value)
    {
        if (!$value) {
            return [];
        }

        return \json_decode($value, true);
    }
}

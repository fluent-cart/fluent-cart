<?php

namespace FluentCart\App\Models;

use FluentCart\App\Models\Concerns\CanSearch;
use FluentCart\App\Services\FileSystem\DownloadService;

/**
 *  Product Download Model - DB Model for Product Downloads
 *
 *  Database Model
 *
 *
 * @package FluentCart\App\Models
 *
 * @version 1.0.0
 */
class ProductDownload extends Model
{
    use CanSearch;

    protected $table = 'fct_product_downloads';

    protected $fillable = [
        'post_id',
        'product_variation_id',
        'download_identifier',
        'title',
        'type',
        'driver',
        'file_name',
        'file_path',
        'file_url',
        'file_size', // size in bytes
        'settings',
        'serial',
    ];


    public function setSettingsAttribute($settings)
    {
        if (is_array($settings) || is_object($settings)) {
            $settings = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $this->attributes['settings'] = $settings;
    }

    public function getSettingsAttribute($settings)
    {
        if (is_string($settings)) {
            $decoded = json_decode($settings, true);
            return $decoded ?: $settings;
        }
        return $settings;
    }

    public function setProductVariationIdAttribute($variations)
    {
        if (is_array($variations)) {
            $value = json_encode($variations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $value = [];
        }
        $this->attributes['settings'] = $value;
    }

    public function getProductVariationIdAttribute($value)
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return $decoded ?: [];
        }
        return [];
    }

    /**
     * One2One: Dwonloadable Files belongs to one product
     *
     * @return \FluentCart\Framework\Database\Orm\Relations\BelongsTo
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'post_id', 'ID');
    }

    public function download_permissions()
    {
        return $this->hasMany(OrderDownloadPermission::class, 'download_id', 'id');
    }

    public function getSignedDownloadUrl(): string
    {
        return DownloadService::getDownloadableUrlFromDownload($this->toArray());
    }

}

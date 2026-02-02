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
class LicenseSite extends Model
{
    protected $table = 'fct_license_sites';

    protected $primaryKey = 'id';

    protected $guarded = ['id'];

    protected $fillable = [
        'site_url',
        'server_version',
        'platform_version',
        'other'
    ];


    public function activations()
    {
        return $this->hasMany(LicenseActivation::class, 'site_id', 'id');
    }

    public function setOtherAttribute($value)
    {
        if (is_array( $value ) || is_object( $value ) ) {
            $value = json_encode( $value , JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $this->attributes['other'] = $value;
    }

    public function getOtherAttribute($value)
    {
        if (is_string( $value )) {
            $decoded = json_decode( $value, true );
            if($decoded && is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    public function isLocalSite()
    {
        $url = $this->url??'';
        // if the domain extension is .lab / .local / .test / .localhost
        // or is a subdomain of staging / dev / development / test / testing
        $isLocal = false;
        if (preg_match('/\.(lab|local|test|localhost)$/', $url) || preg_match('/(staging|dev|development|test|testing)\./', $url)) {
            $isLocal = true;
        }

        return apply_filters('fluent_cart_sl/is_local_site', $isLocal, [
            'url' => $url,
            'site' => $this
        ]);
    }
}

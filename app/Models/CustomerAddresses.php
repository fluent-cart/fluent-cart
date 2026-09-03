<?php

namespace FluentCart\App\Models;

use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Models\Concerns\CanSearch;
use FluentCart\App\Services\Localization\LocalizationManager;
use FluentCart\Framework\Support\Arr;

/**
 *  Order Model - DB Model for Orders
 *
 *  Database Model
 *
 * @package FluentCart\App\Models
 *
 * @version 1.0.0
 */
class CustomerAddresses extends Model
{
    use CanSearch;

    protected $table = 'fct_customer_addresses';

    protected $appends = ['formatted_address', 'company_name', 'vat_number', 'legal_registration_id'];


    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'customer_id',
        'is_primary',
        'type',
        'status',
        'label',
        'name',
        'address_1',
        'address_2',
        'city',
        'state',
        'postcode',
        'country',
        'phone',
        'email',
        'meta',
    ];


    public function setMetaAttribute($value)
    {
        if ($value) {
            $decoded = \json_encode($value);
            if (!$decoded) {
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

    public function setCompanyNameAttribute($value)
    {
        $meta = is_array($this->meta) ? $this->meta : [];

        if ($value) {
            Arr::set($meta, 'other_data.company_name', $value);
        } else {
            unset($meta['other_data']['company_name']);
        }

        $this->attributes['meta'] = json_encode($meta);
    }

    public function getCompanyNameAttribute()
    {
        return Arr::get($this->meta, 'other_data.company_name', '');
    }

    public function setLegalRegistrationIdAttribute($value)
    {
        $meta = is_array($this->meta) ? $this->meta : [];

        if ($value) {
            Arr::set($meta, 'other_data.legal_registration_id', $value);
        } else {
            unset($meta['other_data']['legal_registration_id']);
        }

        $this->attributes['meta'] = json_encode($meta);
    }

    public function getLegalRegistrationIdAttribute()
    {
        return Arr::get($this->meta, 'other_data.legal_registration_id', '');
    }

    public function setVatNumberAttribute($value)
    {
        $meta = is_array($this->meta) ? $this->meta : [];

        if ($value) {
            Arr::set($meta, 'other_data.vat_number', $value);
        } else {
            unset($meta['other_data']['vat_number']);
        }

        $this->attributes['meta'] = json_encode($meta);
    }

    public function getVatNumberAttribute()
    {
        return Arr::get($this->meta, 'other_data.vat_number', '');
    }

    public function scopeOfActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeOfArchived($query)
    {
        return $query->where('status', 'archived');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    public function getFormattedAddressAttribute(): array
    {
        $formattedAddress = [
            'country' => AddressHelper::getCountryNameByCode($this->country),
            'state' => AddressHelper::getStateNameByCode($this->state, $this->country),
            'city' => $this->city,
            'postcode' => $this->postcode,
            'address_1' => $this->address_1,
            'address_2' => $this->address_2,
            'type' => $this->type,
            'name' => $this->name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'company_name' => $this->company_name,
            'vat_number' => $this->vat_number,
            'legal_registration_id' => $this->legal_registration_id,
            'label' => $this->label,
            'phone' => $this->phone
        ];

        $addressParts = [
            trim(Arr::get($formattedAddress, 'company_name') ?? ''),
            trim(Arr::get($formattedAddress, 'address_1') ?? ''),
            trim(Arr::get($formattedAddress, 'address_2') ?? ''),
            trim(Arr::get($formattedAddress, 'city') ?? ''),
            trim(Arr::get($formattedAddress, 'state') ?? ''),
            trim(Arr::get($formattedAddress, 'country') ?? ''),
        ];
        $addressParts = array_filter($addressParts, function ($part) {
            return $part !== '';
        });
        $fullAddress = implode(', ', $addressParts);

        $formattedAddress['full_address'] = $fullAddress;

        return $formattedAddress;
    }

    public function getFormattedDataForCheckout($prefix = 'billing_')
    {
        $data = [
            '' . $prefix . 'full_name' => $this->name,
            '' . $prefix . 'address_1' => $this->address_1,
            '' . $prefix . 'address_2' => $this->address_2,
            '' . $prefix . 'city' => $this->city,
            '' . $prefix . 'state' => $this->state,
            '' . $prefix . 'phone' => $this->phone,
            '' . $prefix . 'postcode' => $this->postcode,
            '' . $prefix . 'country' => $this->country,
            '' . $prefix . 'company_name' => $this->company_name,
            '' . $prefix . 'vat_number' => $this->vat_number,
            '' . $prefix . 'legal_registration_id' => $this->legal_registration_id,
        ];

        // if ($prefix === 'billing_') {
            // unset($data['billing_full_name']);
        // }

        return $data;
    }
}

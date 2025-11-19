<?php

namespace FluentCart\App\Models;

use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Services\Localization\LocalizationManager;
use FluentCart\Framework\Support\Collection;

/**
 *  OrderItem Model - DB Model for Order Items
 *
 *  Database Model
 *
 * @package FluentCart\App\Models
 *
 * @version 1.0.0
 */
class OrderAddress extends Model
{
    protected $table = 'fct_order_addresses';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'order_id',
        'type',
        'name',
        'address_1',
        'address_2',
        'city',
        'state',
        'postcode',
        'country',
        'meta'
    ];
    protected $appends = [
        'email', 'first_name', 'last_name', 'full_name', 'formatted_address'
    ];

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

    public function getFullNameAttribute(): ?string
    {
        return $this->name;
    }

    public function getFirstNameAttribute(): ?string
    {
        return explode(" ", $this->name ?? '')[0];
    }

    public function getLastNameAttribute(): ?string
    {
        $nameParts = explode(" ", $this->name ?? '');
        return end($nameParts);
    }

    public function order(): \FluentCart\Framework\Database\Orm\Relations\belongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    public function getEmailAttribute(): ?string
    {
        $order = $this->order;
        if ($order && $order->customer) {
            return $order->customer->email ?? null;
        }
        return null;
    }

    public function getFormattedAddressAttribute(): array
    {
        return $this->getFormattedAddress();
    }

    public function getFormattedAddress($filtered = false): array
    {
        $address = [
            'country'    => AddressHelper::getCountryNameByCode($this->country),
            'state'      => AddressHelper::getStateNameByCode($this->state, $this->country),
            'city'       => $this->city,
            'postcode'   => $this->postcode,
            'address_1'  => $this->address_1,
            'address_2'  => $this->address_2,
            'type'       => $this->type,
            'name'       => $this->name,
            'first_name' => $this->first_name,
            'last_name'  => $this->last_name,
            'full_name'  => $this->full_name,
            'email'      => $this->email
        ];

        if ($filtered) {
            $address = array_filter($address);
        }

        return $address;
    }


    public function getAddressAsText($isHtml = false, $includeName = true, $separator = ', '): string
    {
        $address = $this->formatted_address;

        $formatted = array_filter([
            $includeName ? $address['name'] : '',
            $address['address_1'],
            $address['address_2'],
            $address['city'],
            $address['state'],
            $address['postcode'],
            $address['country']
        ]);

        return implode($separator, $formatted);
    }

}

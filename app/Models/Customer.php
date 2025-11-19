<?php

namespace FluentCart\App\Models;

use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Concerns\CanSearch;
use FluentCart\App\Models\Concerns\CanUpdateBatch;
use FluentCart\App\Services\Localization\LocalizationManager;
use FluentCart\Framework\Database\Orm\Relations\BelongsTo;
use FluentCart\Framework\Database\Orm\Relations\hasOne;
use FluentCart\Framework\Database\Orm\Relations\MorphMany;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Helpers\Helper;

/**
 *  Order Model - DB Model for Orders
 *
 *  Database Model
 *
 * @package FluentCart\App\Models
 *
 * @version 1.0.0
 */
class Customer extends Model
{
    use CanSearch, CanUpdateBatch;

    protected $table = 'fct_customers';
    protected $appends = ['full_name', 'photo', 'country_name', 'formatted_address', 'user_link'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'contact_id',
        'email',
        'first_name',
        'last_name',
        'status',
        'purchase_value',
        'purchase_count',
        'ltv',
        'first_purchase_date',
        'last_purchase_date',
        'aov',
        'notes',
        'uuid',
        'country',
        'city',
        'state',
        'postcode',
    ];

    protected $searchable = [
        'first_name',
        'last_name',
        'email',
    ];

    public function setPurchaseValueAttribute($value)
    {
        if (is_array($value) || is_object($value)) {
            $this->attributes['purchase_value'] = json_encode($value);
        } else {
            $this->attributes['purchase_value'] = $value;
        }
    }

    public function getPurchaseValueAttribute($value)
    {
        return !empty($value) ? json_decode($value,true) : null;
    }

    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->uuid = md5($model->email . '_' . wp_generate_uuid4());
        });
    }

    public function scopeOfActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeOfArchived($query)
    {
        return $query->where('status', 'archived');
    }

    /**
     * todo - contact_id ? - do we need it anymore?
     */

    public function orders()
    {
        return $this->hasMany(Order::class, 'customer_id', 'id');
    }

    public function success_order_items()
    {
        return $this->hasManyThrough(OrderItem::class, Order::class, 'customer_id', 'order_id', 'id', 'id')
            ->whereHas('order', function ($q) {
                $q->whereIn('payment_status', Status::getOrderPaymentSuccessStatuses());
            });
    }


    public function subscriptions()
    {
        return $this->hasMany(Subscription::class, 'customer_id', 'id');
    }

    public function shipping_address()
    {
        return $this->hasMany(CustomerAddresses::class, 'customer_id', 'id')->where('type', 'shipping');
    }

    public function billing_address()
    {
        return $this->hasMany(CustomerAddresses::class, 'customer_id', 'id')->where('type', 'billing');
    }

    public function primary_shipping_address(): hasOne
    {
        return $this->hasOne(CustomerAddresses::class, 'customer_id', 'id')->where('type', 'shipping')->where('is_primary', 1);
    }

    public function primary_billing_address(): hasOne
    {
        return $this->hasOne(CustomerAddresses::class, 'customer_id', 'id')->where('type', 'billing')->where('is_primary', 1);
    }


    /**
     * Accessor to get dynamic full_name attribute
     *
     * @return string
     */
    public function getFullNameAttribute()
    {
        $fname = isset($this->attributes['first_name']) ? $this->attributes['first_name'] : '';
        $lname = isset($this->attributes['last_name']) ? $this->attributes['last_name'] : '';

        return trim("{$fname} {$lname}");
    }

    /**
     * Accessor method to get the user's avatar URL using their email,
     * with a size of 100x100 pixels.
     *
     * @return string
     */
    public function getPhotoAttribute()
    {
        // Get the custom photo URL from user meta using the user_id of this instance
        $customPhotoUrl = get_user_meta($this->user_id, 'fc_customer_photo_url', true);

        // Sanitize the customer photo URL
        $customPhotoUrl = esc_url($customPhotoUrl ?? '');

        // Return the custom photo URL if it exists, otherwise fallback to Gravatar
        if (!empty($customPhotoUrl)) {
            return $customPhotoUrl;
        }

        // Fallback to Gravatar if no customer avatar is set and sanitize the Gravatar URL
        return esc_url(get_avatar_url($this->email, ['size' => 100]));
    }

    /**
     * Accessor method to get the country's name with country code,
     *
     * @return string
     */
    public function getCountryNameAttribute(): string
    {
        return Helper::getCountryName($this->country);
    }

    public function recountStats()
    {
        $this->total_order_count = Order::query()->where('customer_id', $this->id)
            // ->whereIn('order_status', Status::getOrderSuccessStatuses())
            ->count();

        $this->total_order_value = Order::query()->where('customer_id', $this->id)
            // ->whereIn('order_status', Status::getOrderSuccessStatuses())
            ->sum('total_amount');

        $this->save();

        return $this;
    }

    public function recountStat()
    {

        $orders = \FluentCart\App\Models\Order::query()->where('customer_id', $this->id)
            ->whereIn('payment_status', Status::getOrderPaymentSuccessStatuses())
            ->get();

        $totalPayments = [];
        $ltv = 0;
        foreach ($orders as $order) {
            $netPaid = $order->total_paid - $order->total_refund;
            if ($netPaid > 0) {
                $ltv += $netPaid;
            }
        }

        $this->purchase_count = $orders->count();
        $this->first_purchase_date = $orders->min('created_at') ?? null;
        $this->last_purchase_date = $orders->max('created_at') ?? null;
        $this->ltv = $ltv;
        $this->aov = $this->purchase_count ? $ltv / $this->purchase_count : 0;
        $this->save();


        return $this;
    }

    /**
     * Local scope to filter subscribers by search/query string
     *
     * @param \FluentCart\Framework\Database\Query\Builder $query
     * @param string $search
     *
     * @return \FluentCart\Framework\Database\Query\Builder $query
     */
    public function scopeSearchBy($query, $search)
    {
        if ($search) {

            $fields = $this->searchable;

            // maybe operator based search
            $operators = ['=', '!=', '>', '<'];

            // check if search has an operator with regexp
            $operatorPattern = '/\s*(' . implode('|', $operators) . ')\s*/';

            $search = trim($search);
            if (preg_match($operatorPattern, $search, $matches)) {
                $operator = $matches[1];
                $searchParts = explode($operator, $search);
                if (count($searchParts) >= 2) {
                    $column = trim($searchParts[0]);
                    $value = trim($searchParts[1]);

                    // Check if the column is valid
                    $validColumns = $this->fillable;
                    $validColumns[] = 'id';

                    if (in_array($column, $validColumns)) {
                        return $query->where($column, $operator, $value);
                    }
                }
            }

            $maybeColumnSearch = explode(':', $search);

            if (count($maybeColumnSearch) >= 2) {
                $column = $maybeColumnSearch[0];
                $validColumns = $this->fillable;
                $validColumns[] = 'id';
                if (in_array($column, $validColumns)) {
                    return $query->where($column, 'LIKE', '%%' . trim($maybeColumnSearch[1]) . '%%');
                }
            }

            $maybeExactSearch = explode('=', $search);
            if (count($maybeExactSearch) >= 2) {
                $column = $maybeExactSearch[0];
                $validColumns = $this->fillable;
                $validColumns[] = 'id';
                if (in_array($column, $validColumns)) {
                    return $query->where($column, trim($maybeExactSearch[1]));
                }
            }

            $query->where(function ($query) use ($fields, $search) {
                $query->where(array_shift($fields), 'LIKE', "%$search%");

                $nameArray = explode(' ', $search);
                if (count($nameArray) >= 2) {
                    $query->orWhere(function ($q) use ($nameArray) {
                        $fname = array_shift($nameArray);
                        $lastName = implode(' ', $nameArray);
                        $q->where('first_name', 'LIKE', "$fname%");
                        $q->where('last_name', 'LIKE', "$lastName%");
                    });
                }

                foreach ($fields as $field) {
                    $query->orWhere($field, 'LIKE', "%$search%");
                }
            });
        }

        return $query;
    }

    public function scopeApplyCustomFilters($query, $filters)
    {
        if (!$filters) {
            return $query;
        }

        $acceptedKeys = $this->fillable;

        foreach ($filters as $filterKey => $filter) {

            if (!in_array($filterKey, $acceptedKeys)) {
                continue;
            }

            $value = Arr::get($filter, 'value', '');
            $operator = Arr::get($filter, 'operator', '');
            if (!$value || !$operator || is_array($value)) {
                continue;
            }

            switch (strtolower($operator)) {
                case 'includes':
                    $operator = "like_all";
                    break;
                case 'not_includes':
                    $operator = "not_like";
                    break;
                case 'gt':
                    $operator = ">";
                    break;
                case 'lt':
                    $operator = "<";
                    break;

                default:

            }
            $param = [$filterKey => ["column" => $filterKey, "operator" => $operator, "value" => trim($value)]];
            $query->when($param, function ($query) use ($param) {
                return $query->search($param);
            });
        }

        return $query;
    }

    public function updateCustomerStatus($newStatus)
    {
        $oldStatus = $this->status;

        if ($newStatus == $oldStatus) {
            return $this;
        }

        $this->status = $newStatus;
        $this->save();

        do_action('fluent_cart/customer_status_to_' . $newStatus, [
            'customer'   => $this,
            'old_status' => $oldStatus,
            'new_status' => $newStatus
        ]);
        do_action('fluent_cart/customer_status_updated', [
            'customer'   => $this,
            'old_status' => $oldStatus,
            'new_status' => $newStatus
        ]);

        return $this;
    }

    /**
     * Get the customer's label.
     */
    public function labels(): MorphMany
    {
        return $this->morphMany(LabelRelationship::class, 'labelable');
    }

    /**
     * Define the relationship with the User model.
     */
    public function wpUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getWpUserId($recheck = false)
    {
        if ($recheck) {
            $user = get_user_by('email', $this->email);
            if ($user) {
                if ($user->ID != $this->user_id) {
                    $this->user_id = $user->ID;
                    unset($this->preventsLazyLoading);
                    $this->save();
                }
            }
        }

        return $this->user_id;
    }

    public function getFormattedAddressAttribute(): array
    {

        return [
            'country'    => $this->country ?  AddressHelper::getCountryNameByCode($this->country): '',
            'state'      => AddressHelper::getStateNameByCode($this->state, $this->country),
            'city'       => $this->city,
            'postcode'   => $this->postcode,
            'first_name' => $this->first_name,
            'last_name'  => $this->last_name,
            'full_name'  => $this->full_name
        ];
    }

    public function getUserLinkAttribute()
    {
        if ($this->user_id) {
            return admin_url('user-edit.php?user_id=' . $this->user_id);
        }
        return '';
    }

    public function getMeta($metaKey, $default = null)
    {
        $exist = CustomerMeta::query()->where('customer_id', $this->id)
            ->where('meta_key', $metaKey)
            ->first();

        if ($exist) {
            return $exist->meta_value;
        }

        return $default;
    }

    public function updateMeta($metaKey, $metaValue)
    {
        $exist = CustomerMeta::query()->where('customer_id', $this->id)
            ->where('meta_key', $metaKey)
            ->first();

        if ($exist) {
            $exist->meta_value = $metaValue;
            $exist->save();
        } else {
            $exist = CustomerMeta::query()->create([
                'customer_id' => $this->id,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_key'    => $metaKey,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'meta_value'  => $metaValue
            ]);
        }

        return $exist;
    }


    public function getWpUser()
    {
        if ($this->user_id) {
            $user = get_user_by('ID', $this->user_id);
            if ($user) {
                return $user;
            }
        }
        
        $user = get_user_by('email', $this->email);

        if ($user) {
            if ($user->ID != $this->user_id) {
                $this->user_id = $user->ID;
                unset($this->preventsLazyLoading);
                $this->save();
            }
        }

        return $user;
    }

    public function scopeSearchByFullName ($query, $data) {

        $operator = Arr::get($data, 'operator', 'like_all');

        $search = Arr::get($data, 'value');
        $search = sanitize_text_field(trim($search));

        $fullName = \FluentCart\App\App::db()->raw("CONCAT(first_name, ' ', last_name)");

        switch ($operator) {
            case 'starts_with':
                $pattern = "{$search}%";
                break;
            case 'ends_with':
                $pattern = "%{$search}";
                break;
            case 'not_like':
                return $query->where($fullName, 'NOT LIKE', "%{$search}%");
            default: // contains
                $pattern = "%{$search}%";
        }

        return $query->where($fullName, 'LIKE', $pattern);

    }
}

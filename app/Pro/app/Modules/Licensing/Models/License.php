<?php

namespace FluentCartPro\App\Modules\Licensing\Models;

use FluentCart\App\Models\Concerns\CanSearch;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\LabelRelationship;
use FluentCart\App\Models\Meta;
use FluentCart\App\Models\Model;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductDownload;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Database\Orm\Relations\MorphMany;
use FluentCart\Framework\Support\Arr;
use FluentCartPro\App\Modules\Licensing\Services\LicenseHelper;
use FluentCartPro\App\Modules\Licensing\Services\UUID;
use FluentCart\App\Helpers\Helper;

/**
 *  Meta Model - DB Model for Meta table
 *
 *  Database Model
 *
 * @package FluentCart\App\Models
 *
 * @version 1.0.0
 */
class License extends Model
{
    use CanSearch;

    protected $table = 'fct_licenses';

    protected $primaryKey = 'id';

    protected $guarded = ['id'];

    protected $fillable = [
        'status',
        'limit',
        'activation_count',
        'license_key',
        'product_id',
        'variation_id',
        'order_id',
        'parent_id',
        'customer_id',
        'expiration_date',
        'last_reminder_sent',
        'last_reminder_type',
        'subscription_id',
        'config'
    ];

    public function labels(): MorphMany
    {
        return $this->morphMany(LabelRelationship::class, 'labelable');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'ID');
    }

    public function variation()
    {
        return $this->belongsTo(ProductVariation::class, 'variation_id', 'id');
    }

    public function productDetails()
    {
        return $this->belongsTo(ProductDetail::class, 'product_detail_id', 'id');
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class, 'subscription_id', 'id');
    }

    public function activations()
    {
        return $this->hasMany(LicenseActivation::class, 'license_id', 'id');
    }

    public function setConfigAttribute($value)
    {
        if (!$value || !is_array($value)) {
            $value = [];
        }

        $this->attributes['config'] = \json_encode($value);
    }

    public function getConfigAttribute($value)
    {
        if (!$value) {
            return [];
        }

        $decoded = \json_decode($value, true);

        if (!$decoded || !is_array($decoded)) {
            $decoded = [];
        }

        return $decoded;
    }

    public function scopeSearch($query, $search)
    {
        // Ensure $search is always a string
        $search = is_array($search) ? implode(' ', $search) : $search;

        // If search is empty or null, return the query
        if (empty($search)) {
            return $query;
        }

        // Split search terms by space

        $query->where(function ($query) use ($search) {
            $query->where('license_key', 'like', '%' . $search . '%')
                ->orWhere('order_id', 'like', '%' . $search . '%')
                ->orWhereHas('product', function ($query) use ($search) {
                    $query->where('post_title', 'like', '%' . $search . '%');
                })
                ->orWhereHas('customer', function ($query) use ($search) {
                    $query
                        ->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"])
                        ->orWhere('email', 'like', '%' . $search . '%');
                });
        });

        return $query;
    }

    public function scopeStatus($query, $status)
    {
        if (!$status || $status == 'all') {
            return $query;
        }

        $validStatuses = [
            'active',
            'expired',
            'disabled',
        ];

        if (in_array($status, $validStatuses)) {
            if ($status == 'expired') {
                $query->where('expiration_date', '<', DateTime::gmtNow());
            } else if ($status == 'active') {
                $query->where(function ($query) {
                    $query->where('expiration_date', '>', DateTime::gmtNow())
                        ->orWhereNull('expiration_date');
                })
                    ->where('status', 'active');
            } else {
                $query->where('status', $status);
            }
        } else if ($status == 'inactive') {
            $query->where('status', 'active')
                ->whereDoesntHave('activations');
        }

        return $query;
    }

    public function scopeProducts($query, $productIds)
    {
        if (!$productIds) {
            return $query;
        }

        $query->whereIn('product_id', $productIds);

        return $query;
    }

    public function updateLicenseStatus($newStatus)
    {
        if ($this->status == $newStatus) {
            return $this;
        }

        $oldStatus = $this->status;

        $this->status = $newStatus;
        $this->save();

        do_action('fluent_cart_sl/license_status_updated', [
            'license'    => $this,
            'old_status' => $oldStatus,
            'new_status' => $newStatus
        ]);
        do_action('fluent_cart_sl/license_status_updated_to_' . $newStatus, [
            'license'    => $this,
            'old_status' => $oldStatus,
            'new_status' => $newStatus
        ]);

        return $this;
    }

    public function increaseActivationCount()
    {
        $oldCount = $this->activation_count;
        $this->increment('activation_count');
        do_action('fluent_cart_sl/license_limit_increased', [
            'license'   => $this,
            'old_count' => $oldCount
        ]);
        return $this;

    }

    public function decreaseActivationCount()
    {
        if ($this->activation_count <= 0) {
            return $this;
        }
        $oldCount = $this->activation_count;
        $this->decrement('activation_count', 1);
        do_action('fluent_cart_sl/license_limit_decreased', [
            'license'   => $this,
            'old_count' => $oldCount
        ]);
        return $this;
    }

    public function increaseLimit($newLimit)
    {
        $oldLimit = $this->limit;
        $this->limit = $newLimit;

        if ($newLimit === 'unlimited' || $newLimit === 0) {
            $this->limit = 0;
        } else {
            $this->limit = $newLimit;
        }

        $this->save();

        do_action('fluent_cart_sl/license_limit_increased', [
            'license'   => $this,
            'old_limit' => $oldLimit
        ]);

        return $this;
    }

    public function isExpired()
    {

        if ($this->status == 'expired') {
            return true;
        }

        if (!$this->expiration_date) {
            return false;
        }

        $gracePeriodDays = LicenseHelper::getLicenseGracePeriodDays();

        if ((strtotime($this->expiration_date) + intval($gracePeriodDays) * DAY_IN_SECONDS) < time()) { // Allow a 15-day grace period for expiration
            return true;
        }

        return false;
    }

    public function getActivationLimit()
    {
        if (!$this->limit) {
            return 'unlimited';
        }

        $remainingActivations = $this->limit - $this->activation_count;
        return $remainingActivations > 0 ? $remainingActivations : 0;
    }

    public function hasActivationLeft(): bool
    {
        $limit = $this->getActivationLimit();
        if ($limit === 'unlimited') {
            return true;
        }

        return is_numeric($limit) && $limit > 0;
    }

    public function regenerateKey()
    {
        $oldKey = $this->license_key;
        $this->license_key = UUID::licensesKey([
            'product_id'   => $this->product_id,
            'variation_id' => $this->variation_id,
            'order_id'     => $this->order_id,
            'customer_id'  => $this->customer_id,
        ]);

        $this->save();
        do_action('fluent_cart_sl/license_key_regenerated', [
            'license' => $this,
            'old_key' => $oldKey
        ]);

        return $this;
    }

    public function extendValidity($newDate)
    {
        $oldStatus = $this->status;
        $oldDate = $this->expiration_date;

        if ($oldDate == $newDate) {
            return $this;
        }

        if ($newDate == 'lifetime' || $newDate === null) {
            $this->expiration_date = null;
        } else {
            $this->expiration_date = $newDate;
        }
        $this->save();

        $license = $this;

        if (!in_array($this->status, ['active', 'inactive'])) {
            $license = $this->updateLicenseStatus('active');
        }

        do_action('fluent_cart_sl/license_validity_extended', [
            'license'  => $license,
            'old_date' => $oldDate,
            'new_date' => $newDate
        ]);

        return $license;
    }

    public function productVariant()
    {
        return $this->belongsTo(ProductVariation::class, 'variation_id', 'id');
    }

    public function getDownloads()
    {
        if (!$this->product_id) {
            return [];
        }

        $variationId = $this->variation_id;
        $productId = $this->product_id;
        $orderId = $this->order_id;
        $variationTitles = ProductVariation::pluck('variation_title', 'id');
        $productTitles = Product::pluck('post_title', 'ID');
        $downloads = ProductDownload::where('post_id', $productId)->get();
        $matchedDownloads = [];

        // If variation_id is set, find downloads that match the variation_id
        if (!empty($variationId)) {
            $matchedDownloads = ProductDownload::query()
                ->where('post_id', $productId)
                ->where(function ($q) use ($variationId) {
                    $q->where('product_variation_id', '=', $variationId)
                        ->orWhere('product_variation_id', 'like', $variationId . ',%')
                        ->orWhere('product_variation_id', 'like', '%,' . $variationId . ',%')
                        ->orWhere('product_variation_id', 'like', '%,' . $variationId);
                })
                ->get();
        }

        // Use matched downloads if found, otherwise use default downloads that match the product_id
        $finalDownloads = $matchedDownloads->isNotEmpty() ? $matchedDownloads : $downloads;

        return $finalDownloads->map(function ($download) use ($variationTitles, $productTitles, $orderId) {
            $variationIds = $download->product_variation_id;

            if (!is_array($variationIds)) {
                $variationIds = json_decode((string)$variationIds, true) ?? [];
            }

            $download->product_title = $productTitles[$download->post_id] ?? '';
            $download->variation_titles = array_map(
                fn($id) => $variationTitles[$id] ?? null,
                $variationIds
            );
            $download->download_url = Helper::generateDownloadFileLink($download, $orderId);

            unset($download->product_variation_id);

            return $download;
        });
    }

    public function getPreviousOrders()
    {
        $prevOrderIds = Arr::get($this->config, 'prev_order_ids', []);

        if (!$prevOrderIds) {
            return [];
        }

        return Order::whereIn('id', $prevOrderIds)
            ->orderBy('id', 'DESC')
            ->get();
    }

    public function isValid()
    {
        return !$this->isExpired() && $this->isActive();
    }

    public function isActive()
    {
        return ($this->status === 'active' || $this->status === 'inactive');
    }

    public function getPublicStatus()
    {
        if ($this->isValid()) {
            return 'valid';
        }

        if ($this->isExpired()) {
            return 'expired';
        }

        return 'invalid';
    }

    public function recountActivations()
    {
        $activations = LicenseActivation::query()
            ->where('license_id', $this->id)
            ->where('status', 'active')
            ->where('is_local', '!=', 1)
            ->count();

        $this->activation_count = $activations;
        if ($this->status == 'inactive') {
            $this->status = 'active';
        }

        $this->save();

        return $this;
    }

    public function getRenewalUrl()
    {

        if (!$this->isExpired() || !$this->subscription_id) {
            return '';
        }

        $subscription = Subscription::find($this->subscription_id);

        if ($subscription) {
            return $subscription->getReactivateUrl();
        }

        return '';
    }

    public function hasUpgrades()
    {
        if ($this->getHumanReadableStatus() !== 'active') {
            return false;
        }

        return Meta::query()->where('meta_key', 'variant_upgrade_path')
            ->where('object_id', $this->variation_id)
            ->exists();
    }

    public function getHumanReadableStatus()
    {
        $validStatuses = ['active', 'inactive'];

        if (in_array($this->status, $validStatuses)) {
            return 'active';
        }

        return $this->status;
    }

}

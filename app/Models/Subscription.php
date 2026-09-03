<?php

namespace FluentCart\App\Models;

use FluentCart\Api\CurrencySettings;
use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Helpers\AttributeHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Modules\PaymentMethods\Core\PaymentGatewayInterface;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Models\Concerns\CanUpdateBatch;
use FluentCart\App\Models\Concerns\HasActivity;
use FluentCart\App\Services\Payments\SubscriptionHelper;
use FluentCart\App\Services\TemplateService;
use FluentCart\Framework\Database\Orm\Relations\BelongsTo;
use FluentCart\Framework\Database\Orm\Relations\HasMany;
use FluentCart\Framework\Database\Orm\Relations\HasOne;
use FluentCart\Framework\Database\Orm\Relations\MorphMany;
use FluentCart\Framework\Support\Arr;
use FluentCartPro\App\Modules\Licensing\Models\License;

/**
 *  Meta Model - DB Model for Meta table
 *
 *  Database Model
 *
 * @property string $uuid
 *
 * @package FluentCart\App\Models
 *
 * @version 1.0.0
 */
class Subscription extends Model
{
    use HasActivity, CanUpdateBatch;

    protected $table = 'fct_subscriptions';

    protected $primaryKey = 'id';

    protected $appends = ['url', 'payment_info', 'billingInfo', 'overridden_status', 'currency', 'reactivate_url', 'permissions', 'display_item_name', 'system_charge_state'];

    protected $guarded = ['id'];

    protected $fillable = [
        'customer_id',
        'parent_order_id',
        'product_id',
        'item_name',
        'variation_id',
        'billing_interval',
        'signup_fee',
        'quantity',
        'recurring_amount',
        'recurring_tax_total',
        'recurring_total',
        'bill_times',
        'bill_count',
        'expire_at',
        'trial_ends_at',
        'canceled_at',
        'restored_at',
        'collection_method',
        'trial_days',
        'vendor_customer_id',
        'vendor_plan_id',
        'vendor_subscription_id',
        'next_billing_date',
        'status',
        'original_plan',
        'vendor_response',
        'current_payment_method',
        'config'
    ];

    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = md5(time() . wp_generate_uuid4());
            }
        });
    }

    public function getNextBillingDateAttribute($value)
    {
        if (empty($value) || $value === '0000-00-00 00:00:00' || $value === '0000-00-00') {
            return null;
        }
        return $value;
    }

    public function getCanceledAtAttribute($value)
    {
        if (empty($value) || $value === '0000-00-00 00:00:00' || $value === '0000-00-00') {
            return null;
        }
        return $value;
    }

    public function getExpireAtAttribute($value)
    {
        if (empty($value) || $value === '0000-00-00 00:00:00' || $value === '0000-00-00') {
            return null;
        }
        return $value;
    }

    public function meta()
    {
        return $this->hasMany(SubscriptionMeta::class, 'subscription_id', 'id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'ID');
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class, 'variation_id');
    }

    public function labels(): MorphMany
    {
        return $this->morphMany(LabelRelationship::class, 'labelable');
    }

    public function license(): ?HasOne
    {
        if (!class_exists(License::class)) {
            return null;
        }
        return $this->hasOne(License::class, 'subscription_id', 'id');
    }

    public function licenses(): ?HasMany
    {
        if (!class_exists(License::class)) {
            return null;
        }
        return $this->hasMany(License::class, 'subscription_id', 'id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(OrderTransaction::class, 'subscription_id', 'id');
    }

    public function billing_addresses(): HasMany
    {
        return $this->hasMany(CustomerAddresses::class, 'customer_id', 'customer_id')->where('type', 'billing');
    }

    public function getConfigAttribute($value)
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : $value;
        }
        return $value ?: [];
    }

    public function setConfigAttribute($value)
    {
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $value = '[]';
        }

        $this->attributes['config'] = $value;
    }

    /**
     * Merge keys into the config blob under a row lock.
     *
     * Every writer of this column must go through here. `config` is a single JSON
     * document written by the cancel path, both Stripe paths and both PayPal paths;
     * a plain read-merge-write loses whichever concurrent write commits first, and a
     * renewal landing during a payment-method switch is not a rare pairing.
     *
     * @param array $values keys to set; existing keys not named here survive
     * @return array the merged config as committed
     */
    public function mergeConfig(array $values): array
    {
        $current = $this->config;
        $current = is_array($current) ? $current : [];

        if (!$values) {
            return $current;
        }

        $db = static::query()->getConnection();
        $db->beginTransaction();

        try {
            $locked = static::query()
                ->where('id', $this->getKey())
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                $db->rollBack();
                return $current;
            }

            $stored = $locked->config;
            $stored = is_array($stored) ? $stored : [];
            $merged = array_merge($stored, $values);

            // Query-builder update bypasses setConfigAttribute, so encode with the
            // same flags the mutator uses.
            static::query()
                ->where('id', $this->getKey())
                ->update([
                    'config' => json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ]);

            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }

        // Only `config` was written, so only `config` is clean now — a bare
        // syncOriginal() would also mark the caller's unsaved edits as persisted
        // and their next save() would drop them.
        $this->setAttribute('config', $merged);
        $this->syncOriginalAttribute('config');

        return $merged;
    }

    /**
     * Customer-facing display name. When the config['item_attributes'] snapshot
     * resolves it returns the product name with the labeled combination
     * ("Cake - Flavor: Vanilla | Weight: 500 g"); otherwise the raw item_name
     * (simple / pre-snapshot subscriptions).
     *
     * Presentation-only — it does NOT override the item_name column, so internal
     * and payment-gateway reads of $subscription->item_name keep the raw stored
     * value. Use this only at customer-facing display sites.
     *
     * The model is passed to the resolver so attribute-display filters (e.g. for
     * simple-variation / third-party attributes) get the item context they need.
     *
     * @return string
     */
    public function getDisplayItemNameAttribute()
    {
        $itemAttributes = Arr::get($this->config, 'item_attributes', []);

        if (!$itemAttributes) {
            return $this->item_name;
        }

        $attributeDisplayTitleString = AttributeHelper::getDisplayAttributesString($itemAttributes, $this, 'subscription');

        if ($attributeDisplayTitleString === '') {
            return $this->item_name;
        }

        // Standalone label has no separate product line, so prefix the product
        // name: "<product> - <attributes>".
        $postTitle = $this->product ? $this->product->post_title : '';

        return $postTitle !== '' ? $postTitle . ' - ' . $attributeDisplayTitleString : $attributeDisplayTitleString;
    }

    public function getUrlAttribute($value)
    {
        return apply_filters('fluent_cart/subscription/url_' . $this->current_payment_method, '', [
            'vendor_subscription_id' => $this->vendor_subscription_id,
            'payment_mode'           => (new StoreSettings())->get('order_mode'),
            'subscription'           => $this
        ]);

    }


    // use this to override the status of the subscription for any custom use case

    /**
     * current use case: If the orignal plan(product variation) has no trial days but the subscription status is 'trialing'
     * it can happens upon discount applied / proration on plan change,
     * use overriden status to show the correct status for customer
     */
    public function getOverriddenStatusAttribute($value)
    {
        if (Arr::get($this->config, 'is_trial_days_simulated', 'no') == 'yes' && $this->status == Status::SUBSCRIPTION_TRIALING) {
            return Status::SUBSCRIPTION_ACTIVE;
        }

        if (Arr::get($this->config, 'is_trial_days_simulated', 'no') !== 'yes' && $this->status == Status::SUBSCRIPTION_ACTIVE && $this->trial_days && (strtotime($this->created_at) + ($this->trial_days * 86400)) > time()) {
            return Status::SUBSCRIPTION_TRIALING;
        }

        return $this->status;
    }

    /**
     * Auto-charge bookkeeping for system subscriptions (attempt count, next retry,
     * last error, processing marker). Null for every other collection method —
     * guarded before the meta lookup so manual/automatic subscriptions pay nothing.
     */
    public function getSystemChargeStateAttribute()
    {
        if ($this->collection_method !== 'system') {
            return null;
        }

        $meta = $this->meta->where('meta_key', 'system_charge_state')->first();

        if (!$meta) {
            return null;
        }

        return is_string($meta->meta_value) ? json_decode($meta->meta_value, true) : $meta->meta_value;
    }

    public function getHasPendingSkipAttribute(): bool
    {
        return $this->hasPendingSkip();
    }

    public function getLastSkippedPeriodAttribute()
    {
        $skipped = $this->getMeta('skipped_periods', []);

        if (!is_array($skipped) || empty($skipped)) {
            return null;
        }

        return end($skipped) ?: null;
    }

    public function getBillingInfoAttribute($value)
    {
        $billingInfo = '';
        $metaKey = 'active_payment_method';
        $meta = $this->meta->where('meta_key', $metaKey)->first();
        $billingInfo = $meta ? (is_string($meta->meta_value) ? json_decode($meta->meta_value, true) : $meta->meta_value) : [];
        return $billingInfo;
    }


    public function getPaymentMethodText()
    {
        $info = Arr::get($this->billingInfo, 'details');
        if (Arr::get($info, 'brand') && Arr::get($info, 'last_4')) {
            return sprintf('%1$s ***%2$s', esc_html($info['brand']), esc_html($info['last_4']));
        }

        return Arr::get($info, 'method', '');
    }

    public function product_detail(): BelongsTo
    {
        return $this->belongsTo(ProductDetail::class, 'variation_id', 'id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'parent_order_id', 'id');
    }

    public function getBusinessInfoAttribute(): array
    {
        if ($this->relationLoaded('order') && $this->order) {
            return $this->order->getBusinessInfo();
        }
        return [];
    }

    public function getIsReverseChargeTaxOrderAttribute(): bool
    {
        if ($this->relationLoaded('order') && $this->order) {
            return $this->order->isReverseChargeTaxOrder();
        }
        return false;
    }

    /**
     * Get the currency for the subscription
     *
     * @return string
     */
    public function getCurrencyAttribute(): string
    {
        $currency = '';

        if (empty($this->config)) {
            // get from store settings
            $currency = CurrencySettings::get('currency');
            return strtoupper($currency);
        }

        $definedCurrency = Arr::get($this->config, 'currency', '');

        if(empty($definedCurrency)) {
            $currency = CurrencySettings::get('currency');
            return strtoupper($currency);
        }

        return strtoupper($definedCurrency);
    }

    /**
     * Get subscription payment info if available
     *
     * @return string
     */
    public function getPaymentInfoAttribute(): string
    {
        return $this->getSubscriptionInfo();
    }

    /**
     * Get subscription permissions for the current user
     * Returns what actions can be performed on this subscription
     *
     * @return array
     */
    public function getPermissionsAttribute(): array
    {
        $status = strtolower($this->status);
        $hasVendorId = !empty($this->vendor_subscription_id);
        $terminalStatuses = [
            Status::SUBSCRIPTION_CANCELED,
            Status::SUBSCRIPTION_EXPIRED,
            Status::SUBSCRIPTION_COMPLETED,
        ];

        $canEdit = $this->usesRenewalEngine() && !in_array($status, $terminalStatuses);
        $canCancel = !in_array($status, $terminalStatuses);

        // One open-invoice lookup shared by the invoice actions below. Only runs
        // for store-billed subscriptions in states where any of them can apply.
        $hasOpenInvoice = false;
        $chargeableStatuses = [
            Status::SUBSCRIPTION_ACTIVE,
            Status::SUBSCRIPTION_TRIALING,
            Status::SUBSCRIPTION_PAST_DUE,
            Status::SUBSCRIPTION_EXPIRED,
        ];
        if ($this->usesRenewalEngine() && in_array($status, $chargeableStatuses) && $this->parent_order_id) {
            $hasOpenInvoice = Order::query()
                ->where('parent_id', $this->parent_order_id)
                ->where('type', Status::ORDER_TYPE_RENEWAL)
                ->whereIn('payment_status', [Status::PAYMENT_PENDING, Status::PAYMENT_SCHEDULED])
                ->exists();
        }

        $canManageRenewal = $this->usesRenewalEngine()
            && in_array($status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING])
            && $this->next_billing_date
            && !$hasOpenInvoice;

        // Admin "Charge Now": system subscription with an open invoice whose charge
        // is not currently settling at the gateway (processing marker).
        $chargeState = $this->isSystem() ? ($this->system_charge_state ?: []) : [];
        $canChargeNow = $this->isSystem()
            && $hasOpenInvoice
            && in_array($status, $chargeableStatuses)
            && Arr::get($chargeState, 'status') !== 'processing';

        return [
            'canEdit'          => $canEdit,
            'canEditVendorIds' => $this->canEditVendorIds(),
            'canVerifyVendorIds' => $this->canVerifyVendorIds(),
            'canPause'         => $this->canPause(),
            'canResume'        => $this->canResume(),
            'canFetch'         => !$this->usesRenewalEngine() && $hasVendorId,
            'canCancel'        => $canCancel,
            // Admin one-click reactivate is for store-billed subscriptions only (the REST
            // endpoint rejects automatic); automatic reactivation runs through the gateway
            // URL flow, gated by canReactivate().
            'canAdminReactivate' => $this->usesRenewalEngine() && $this->canReactivate(),
            'canCreateRenewal' => $canManageRenewal,
            'canSkipRenewal'   => $canManageRenewal && !$this->hasPendingSkip(),
            'canChargeNow'     => $canChargeNow,
            // Surfaced in the Edit modal: an already-issued renewal invoice is
            // re-synced to the edited amount when it exists.
            'hasPendingRenewal' => $hasOpenInvoice,
        ];
    }

    /**
     * Check if this is a manual subscription
     *
     * @return bool
     */
    public function isManual(): bool
    {
        return $this->collection_method === 'manual';
    }

    /**
     * Check if this is a system (auto-charged, store-billed) subscription
     *
     * @return bool
     */
    public function isSystem(): bool
    {
        return $this->collection_method === 'system';
    }

    /**
     * Check if this is a gateway-billed (automatic) subscription
     *
     * @return bool
     */
    public function isAutomatic(): bool
    {
        return $this->collection_method === Status::SUBSCRIPTION_METHOD_AUTOMATIC;
    }

    /**
     * Manual and system subscriptions are both billed by FluentCart's invoice
     * engine (renewal invoices, overdue escalation, admin invoice actions).
     * System additionally auto-charges a stored token per invoice.
     *
     * @return bool
     */
    public function usesRenewalEngine(): bool
    {
        return in_array($this->collection_method, ['manual', 'system'], true);
    }

    /**
     * Store-billed (manual/system) with a future due date has nothing to charge yet —
     * reactivation should flip the subscription active locally instead of checkout.
     *
     * @return bool
     */
    public function shouldSubscriptionActiveLocally(): bool
    {
        return $this->usesRenewalEngine() && $this->next_billing_date && strtotime($this->next_billing_date) > time();
    }

    /**
     * Helper method to get subscription info
     *
     * @return string
     */
    private function getSubscriptionInfo(): string
    {
        $subscriptionInfo = '';

        $otherInfo = [
            'repeat_interval' => $this->billing_interval ?? '',
            'times'           => $this->bill_times ?? 0,
            'recurring_total' => $this->recurring_total ?? 0,
            'trial_days'      => $this->trial_days ?? 0,
        ];

        $recurringTotal = $this->recurring_total ?? 0;

        if ($schedule = SubscriptionHelper::getBillingSchedule($this)) {
            return Helper::generateScheduleSubscriptionInfo($schedule, $otherInfo, $recurringTotal, $this->currency) ?? '';
        }

        return Helper::generateSubscriptionInfo($otherInfo, $recurringTotal, $this->currency) ?? '';
    }

    public function addLog($title, $description = '', $type = 'info', $by = '')
    {
        $logData = [
            'module_type' => 'FluentCart\App\Models\Subscription',
            'module_id'   => $this->id,
            'module_name' => 'subscription',
        ];

        if ($by) {
            $logData['created_by'] = $by;
        }

        fluent_cart_add_log($title, $description, $type, $logData);
    }

    public function getDownloads()
    {
        if (!$this->variation_id || $this->status !== Status::SUBSCRIPTION_ACTIVE) {
            return [];
        }

        $variationTitles = ProductVariation::pluck('variation_title', 'id');
        $productTitles = Product::pluck('post_title', 'ID');

        $downloads = ProductDownload::query()->where('post_id', $this->product_id)->get();

        $downloads->filter(function ($download) {
            if (empty($download->product_variation_id)) {
                return true;
            }
            $ids = $download->product_variation_id;

            if (!is_array($ids)) {
                return true;
            }
            return empty($ids) || in_array($this->variation_id, $ids);
        });

        return $downloads
            ->map(function ($download) use ($variationTitles, $productTitles) {
                $variationIds = $download->product_variation_id;

                $download->product_title = $productTitles[$download->post_id] ?? '';
                $download->variation_ids = $variationIds;
                $download->variation_titles = array_map(
                    fn($id) => $variationTitles[$id] ?? null,
                    $variationIds
                );
                unset($download->product_variation_id);
                return $download;
            });
    }

    public function getMeta($metaKey, $default = null)
    {
        $exist = SubscriptionMeta::query()
            ->where('subscription_id', $this->id)
            ->where('meta_key', $metaKey)
            ->first();

        if ($exist) {
            return $exist->meta_value;
        }

        return $default;
    }

    public function updateMeta($metaKey, $metaValue)
    {
        $exist = SubscriptionMeta::query()
            ->where('subscription_id', $this->id)
            ->where('meta_key', $metaKey)
            ->first();

        if ($exist) {
            $exist->meta_value = $metaValue;
            $exist->save();
        } else {
            SubscriptionMeta::query()->create([
                'subscription_id' => $this->id,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_key'        => $metaKey,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'meta_value'      => $metaValue
            ]);
        }

        return true;
    }

    public function deleteMeta($metaKey)
    {
        return SubscriptionMeta::query()
            ->where('subscription_id', $this->id)
            ->where('meta_key', $metaKey)
            ->delete();
    }

    public function getLatestTransaction()
    {
        return OrderTransaction::query()
            ->where('subscription_id', $this->id)
            ->orderBy('id', 'DESC')
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->first();
    }

    public function canUpgrade()
    {
        return Meta::query()->where('meta_key', 'variant_upgrade_path')
                ->where('object_id', $this->variation_id)
                ->exists() && in_array($this->status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING]);
    }

    /**
     * The gateway backing this subscription, or null when there is not one.
     *
     * `App::gateway()` returns the GatewayManager when its argument is null —
     * that is how `App::gateway()` with no argument is meant to work, but
     * `current_payment_method` is nullable, so a subscription with no payment
     * method resolves to the manager too. The manager is a truthy object, so
     * every `if (!$gateway)` guard in this class waved it through, and the next
     * line read `$gateway->supportedFeatures` as null.
     *
     * `in_array($needle, null)` is a TypeError on PHP 8, thrown from
     * `getPermissionsAttribute()` — an `$appends` entry — so it fires while
     * SERIALIZING. One subscription row with a blank payment method therefore
     * took down the entire subscriptions list response, not just its own row.
     *
     * Resolve through here rather than calling `App::gateway()` directly.
     *
     * The instanceof is against PaymentGatewayInterface — the manager's
     * registration contract — NOT AbstractPaymentGateway, so a third-party
     * gateway implementing the interface directly still resolves. The only
     * object it rejects is the GatewayManager itself, which does not implement
     * the interface.
     *
     * @return PaymentGatewayInterface|null
     */
    private function resolveGateway(): ?PaymentGatewayInterface
    {
        if (empty($this->current_payment_method)) {
            return null;
        }

        // The one direct App::gateway() call in this class.
        $gateway = App::gateway($this->current_payment_method);

        return $gateway instanceof PaymentGatewayInterface ? $gateway : null;
    }

    /**
     * The `switch_payment_method` entry of `supportedFeatures`, or [] when the
     * gateway does not declare one.
     *
     * Unlike the flat feature flags this is a KEYED entry carrying config
     * (`supported_gateways`), so `has()` cannot answer it — it needs the raw
     * `supportedFeatures` property, which only AbstractPaymentGateway carries.
     * An interface-only gateway therefore reports no switch support rather
     * than triggering an undefined-property read.
     *
     * @return array
     */
    private function switchPaymentConfig(): array
    {
        $gateway = $this->resolveGateway();

        if (!$gateway instanceof AbstractPaymentGateway) {
            return [];
        }

        return (array) Arr::get($gateway->supportedFeatures, 'switch_payment_method', []);
    }

    public function canUpdatePaymentMethod()
    {
        $gateway = $this->resolveGateway();
        if (!$gateway || !$gateway->has('card_update')) {
            return false;
        }

        return in_array($this->status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING, Status::SUBSCRIPTION_PAUSED, Status::SUBSCRIPTION_INTENDED, Status::SUBSCRIPTION_PAST_DUE, Status::SUBSCRIPTION_FAILING, Status::SUBSCRIPTION_EXPIRING]); // past_due, is fallback for existing subscriptions, on new subscriptions update it will be expiring
    }

    public function canSwitchPaymentMethod()
    {
        // Switching moves the subscription onto ANOTHER gateway's vendor subscription
        // (see PayPal SubscriptionManager::switchPaymentMethod — it creates a live
        // PayPal subscription). A store-billed subscription is already owned by the
        // invoice engine, so a vendor subscription would bill it a second time. The
        // customer changes the card on file instead (canUpdatePaymentMethod).
        if ($this->usesRenewalEngine()) {
            return false;
        }

        if (!$this->switchPaymentConfig()) {
            return false;
        }

        return in_array($this->status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING, Status::SUBSCRIPTION_PAUSED]);
    }

    public function switchablePaymentMethods()
    {
        if (!$this->canSwitchPaymentMethod()) {
            return [];
        }

        return Arr::get($this->switchPaymentConfig(), 'supported_gateways', []);
    }

    public function canPause()
    {
        // Store-billed (manual/system) subscriptions can always be paused
        // (unless already paused/canceled/expired)
        if ($this->usesRenewalEngine()) {
            return in_array($this->status, [
                Status::SUBSCRIPTION_ACTIVE,
                Status::SUBSCRIPTION_TRIALING,
                Status::SUBSCRIPTION_PAST_DUE,
                Status::SUBSCRIPTION_EXPIRING
            ]);
        }

        // Automatic subscriptions require gateway support
        $gateway = $this->resolveGateway();

        if (!$gateway) {
            return false;
        }

        // Check if gateway supports pause
        if (!$gateway->has('pause_subscription')) {
            return false;
        }

        // Default behavior for automatic subscriptions
        return in_array($this->status, [
            Status::SUBSCRIPTION_ACTIVE,
            Status::SUBSCRIPTION_TRIALING
        ]) && !in_array($this->status, [
            Status::SUBSCRIPTION_PAUSED,
            Status::SUBSCRIPTION_CANCELED,
            Status::SUBSCRIPTION_EXPIRED,
            Status::SUBSCRIPTION_COMPLETED
        ]);
    }

    /**
     * A skip is pending when the current upcoming period was reached by an admin
     * skip that has not yet elapsed — next_billing_date still equals the value the
     * last skip set. Blocks stacking another skip onto the same pending window.
     *
     * @return bool
     */
    public function hasPendingSkip(): bool
    {
        if (!$this->next_billing_date) {
            return false;
        }

        $skippedTo = $this->getMeta('pending_skip_until');

        if (!$skippedTo) {
            return false;
        }

        return $skippedTo === $this->next_billing_date
            && strtotime($this->next_billing_date) > time();
    }

    public function canResume()
    {
        // Store-billed (manual/system) subscriptions can be resumed from paused state
        if ($this->usesRenewalEngine()) {
            return $this->status === Status::SUBSCRIPTION_PAUSED;
        }


        $gateway = $this->resolveGateway();

        if (!$gateway) {
            return false;
        }

        if (!$gateway->has('resume_subscription')) {
            return false;
        }

        // Default behavior
        return $this->status === Status::SUBSCRIPTION_PAUSED;
    }

    public function pauseSubscription($reason = '')
    {
        return SubscriptionService::pauseSubscription($this, $reason);
    }

    public function resumeSubscription($reason = '')
    {
        return SubscriptionService::resumeSubscription($this, $reason);
    }

    public function canUpdateDetails()
    {
        // Only store-billed (manual/system) subscriptions can be fully edited by
        // admin — edits to a system subscription take effect on its next invoice.
        return $this->usesRenewalEngine();
    }

    /**
     * Vendor identifiers are the inverse case of canUpdateDetails(): only a
     * gateway-billed subscription has them, and correcting them is the one
     * admin write an automatic subscription accepts. Billing fields stay
     * gateway-owned.
     *
     * Off by default — this is a migration/support repair tool, and the column it
     * writes is what gateway webhooks resolve on. Enable with:
     *
     *   add_filter('fluent_cart/subscription/vendor_id_editing_enabled', '__return_true');
     *
     * @return bool
     */
    public function canEditVendorIds(): bool
    {
        if (!apply_filters('fluent_cart/subscription/vendor_id_editing_enabled', false)) {
            return false;
        }

        if (!$this->isAutomatic() || !$this->current_payment_method) {
            return false;
        }

        // `expired` and `canceled` stay editable: a subscription usually lands there
        // *because* the id was wrong (webhooks resolved to nothing), so those are the
        // states the repair is needed in most. Sync from gateway has no status gate
        // either. `completed` is a real end of term, not a lookup failure.
        return strtolower($this->status) !== Status::SUBSCRIPTION_COMPLETED;
    }

    /**
     * Whether the gateway backing this subscription can look a candidate id up
     * before it is saved. Editing does not depend on this — a gateway with no
     * lookup still accepts a correction, it just cannot preview it.
     *
     * @return bool
     */
    public function canVerifyVendorIds(): bool
    {
        if (!$this->canEditVendorIds()) {
            return false;
        }

        $gateway = App::gateway($this->current_payment_method);

        return $gateway && $gateway->has('subscriptions') && $gateway->has('verify_vendor_ids');
    }

    /**
     * Update subscription details (for manual subscriptions)
     *
     * Allowed fields for manual subscriptions:
     * - recurring_total: Update the next invoice/payment amount (in cents)
     * - bill_times: Update the number of billing cycles (0 = unlimited)
     * - billing_interval: Change billing frequency (daily, weekly, monthly, etc.)
     * - expire_at: Update expiration date
     * - trial_days: Update trial period
     * - next_billing_date: Update next billing date
     *
     * @param array $data
     * @return true|\WP_Error
     */
    public function updateSubscription(array $data)
    {
        return SubscriptionService::updateSubscription($this, $data);
    }

    /**
     * Whether this subscription can be reactivated.
     *
     * Status-based for BOTH manual and automatic subscriptions — no gateway
     * supportedFeatures branch on purpose. Manual reactivation is a local status
     * flip; automatic reactivation runs through the Pro re-checkout flow
     * (SubscriptionRenewalHandler builds an instant cart and the customer pays
     * again), which works with any gateway. Gating on a gateway feature here
     * would hide the customer-facing reactivate URL for Stripe/PayPal/etc.
     *
     * @return bool
     */
    public function canReactivate()
    {
        if (!App::isProActive()) {
            return false;
        }

        if (isset($this->config['upgraded_to_sub_id']) || $this->recurring_amount <= 0) {
            return false;
        }

        // Paused is intentionally excluded — a paused subscription resumes (see
        // canResume()); reactivation is for terminal/lapsed states only.
        $canReactivate = in_array($this->status, [
            Status::SUBSCRIPTION_CANCELED,
            Status::SUBSCRIPTION_FAILING,
            Status::SUBSCRIPTION_EXPIRED,
            Status::SUBSCRIPTION_EXPIRING,
            Status::SUBSCRIPTION_PAST_DUE,
        ]);

        return (bool) apply_filters('fluent_cart/subscription/can_reactivate', $canReactivate, [
            'subscription' => $this
        ]);
    }

    /**
     * @deprecated Use canReactivate(). Kept as a backward-compatible alias.
     * @return bool
     */
    public function canReactive()
    {
        return $this->canReactivate();
    }

    /**
     * These links are minted in email and webhook contexts, where there is no
     * current user. A wp_create_nonce() token bound to that user-less request
     * stops verifying the moment the recipient logs in to act on it, so the link
     * broke for the one journey it exists to serve. Authorization for the
     * endpoint is the subscription-ownership check on the handling side, which
     * a nonce never provided; the uuid alone is inert to anyone else.
     */
    public function getReactivateUrl()
    {
        if (!$this->canReactivate()) {
            return '';
        }

        return add_query_arg([
            'fluent-cart'       => 'reactivate-subscription',
            'subscription_hash' => $this->uuid,
        ], home_url('/'));
    }

    public function getReactivateUrlAttribute()
    {
        return $this->getReactivateUrl();
    }

    public function getViewUrl($type = 'customer')
    {
        if ($type == 'customer') {
            return TemplateService::getCustomerProfileUrl('subscription/' . $this->uuid);
        }

        return TemplateService::getAdminUrl('subscriptions/' . $this->id . '/view');

    }

    public function hasAccessValidity()
    {
        $validAccessStatuses = [
            Status::SUBSCRIPTION_ACTIVE,
            Status::SUBSCRIPTION_TRIALING,
            Status::SUBSCRIPTION_COMPLETED
        ];

        if (in_array($this->status, $validAccessStatuses)) {
            return true;
        }

        // Past-due keeps access while the unpaid invoice is inside its dunning
        // grace window; the expiry crons flip it to expired past that.
        if ($this->status === Status::SUBSCRIPTION_PAST_DUE) {
            $dueTimestamp = $this->next_billing_date ? strtotime($this->next_billing_date) : 0;
            $graceDays = SubscriptionHelper::getGracePeriodDaysForInterval((string) $this->billing_interval);

            return $dueTimestamp && time() < $dueTimestamp + ($graceDays * DAY_IN_SECONDS);
        }

        $invalidStatuses = [
            Status::SUBSCRIPTION_EXPIRED,
            Status::SUBSCRIPTION_INTENDED,
            Status::SUBSCRIPTION_PENDING
        ];

        if (in_array($this->status, $invalidStatuses)) {
            return false;
        }

        $nextBillingDate = $this->next_billing_date;

        if (!$nextBillingDate) {
            $nextBillingDate = $this->guessNextBillingDate();
        }

        // now check the dates
        if (strtotime($nextBillingDate) > time()) {
            return true;
        }

        return false;
    }

    public function reSyncFromRemote()
    {
        if ($gateway = $this->resolveGateway()) {
            if ($gateway->has('subscriptions')) {
                return $gateway->subscriptions->reSyncSubscriptionFromRemote($this);
            }
        }

        return new \WP_Error('invalid_payment_method', __('This payment method does not support remote resync', 'fluent-cart'));
    }

    public function cancelRemoteSubscription($args = [])
    {
        $args = wp_parse_args($args, [
            'reason'     => '',
            'fire_hooks' => true,
            'note'       => '',
            'effective_from' => ''
        ]);

        if ($this->status === Status::SUBSCRIPTION_CANCELED) {
            return new \WP_Error('subscription_already_cancelled', __('This subscription is already cancelled.', 'fluent-cart'));
        }

        $gateway = $this->resolveGateway();

        // No vendor subscription (store-billed, or a vendor id that never landed) —
        // nothing to cancel at the gateway.
        if (!$this->vendor_subscription_id) {
            $vendorCanceled = null;
            $updateData = [
                'canceled_at' => gmdate('Y-m-d H:i:s', time())
            ];
        } elseif ($gateway && $gateway->has('subscriptions')) {
            $cancelArgs = [
                'subscription_id' => $this->id,
                'parent_order_id' => $this->parent_order_id,
                'mode'            => $this->order->mode,
            ];
            $effectiveFrom = Arr::get($args, 'effective_from', '');
            if ($effectiveFrom) {
                $cancelArgs['effective_from'] = $effectiveFrom;
            }
            $vendorCanceled = $gateway->subscriptions->cancel($this->vendor_subscription_id, $cancelArgs);

            if (is_wp_error($vendorCanceled)) {
                return $vendorCanceled;
            }

            $updateData = array_filter($vendorCanceled);
        } else {
            // Vendor subscription exists but this gateway cannot cancel it — it stays live.
            $vendorCanceled = new \WP_Error('invalid_payment_method', __('This payment method does not support remote subscription cancel', 'fluent-cart'));
            $updateData = [
                'canceled_at' => gmdate('Y-m-d H:i:s', time())
            ];
        }

        $updateData['status'] = Status::SUBSCRIPTION_CANCELED;

        if (empty($updateData['canceled_at']) && !$this->canceled_at) {
            $updateData['canceled_at'] = gmdate('Y-m-d H:i:s', time());
        }

        if ($this->status === Status::SUBSCRIPTION_COMPLETED) {
            $updateData['status'] = Status::SUBSCRIPTION_COMPLETED;
            $updateData['canceled_at'] = NULL;
        }

        if (Arr::get($args, 'effective_from') === 'immediately' && $updateData['status'] !== Status::SUBSCRIPTION_COMPLETED) {
            $updateData['next_billing_date'] = gmdate('Y-m-d H:i:s', time());
        }

        // A completed (EOT) subscription has no upcoming billing — the immediate-cancel
        // date above must not resurrect one (SubscriptionEOT cancels remote subscriptions
        // with effective_from=immediately after syncSubscriptionStates nulled the date).
        if (Arr::get($updateData, 'status') === Status::SUBSCRIPTION_COMPLETED) {
            $updateData['next_billing_date'] = NULL;
        }

        $this->fill($updateData);
        $this->save();

        if ($args['reason']) {
            $this->mergeConfig(['cancellation_reason' => $args['reason']]);
        }

        $note = $args['note'];

        if (!$note) {
            $note = 'on customer request';
        }

        // Single cancel chokepoint — void open renewals, clear reminders, email once.
        if ($this->status === Status::SUBSCRIPTION_CANCELED) {
            SubscriptionService::finalizeCancellation($this, $note, (bool) $args['fire_hooks']);
        }

        if ($args['note']) {
            $this->order->note = $note;
            $this->order->save();
        }

        return [
            'subscription'  => $this,
            'vendor_result' => $vendorCanceled
        ];
    }


    public function getCurrentRenewalAmount()
    {
        $currentRecurringAmount = (int)Arr::get($this->config, 'current_renewal_amount');
        if ($currentRecurringAmount) {
            return $currentRecurringAmount;
        }

        return $this->recurring_total;
    }

    /**
     * Cycles the remote (vendor) plan must bill at INITIAL checkout.
     * With a simulated trial the first installment is already collected outside
     * the remote recurring cycles (one-time charge, paid/free trial cycle), so
     * the remote plan only needs bill_times - 1.
     *
     * Only valid at initial checkout — do NOT use for renewals/reactivation
     * (payment-method switching also sets is_trial_days_simulated; renewal flows
     * must use getRequiredBillTimes() which is bill_count based).
     *
     * @return int 0 means unlimited
     */
    public function getInitialRemoteBillTimes()
    {
        $billTimes = (int)$this->bill_times;

        if (!$billTimes) {
            return 0;
        }

        if (Arr::get($this->config, 'is_trial_days_simulated', 'no') === 'yes') {
            // never return 0 here — 0 means unlimited to the gateways
            $billTimes = max(1, $billTimes - 1);
        }

        return $billTimes;
    }

    public function getRequiredBillTimes()
    {
        $billTimes = (int)$this->bill_times;

        if ($billTimes > 0) {
            $billTimes = $billTimes - $this->bill_count;
            if ($billTimes <= 0) {
                $transacactionsCount = $this->calculateBillCount();

                if ($transacactionsCount != $this->bill_count) {
                    $this->bill_count = $transacactionsCount;
                    $this->save();
                }

                $revisedBillTimes = $this->bill_times - $this->bill_count;
                if ($revisedBillTimes <= 0) {
                    return -1;
                }

                return $revisedBillTimes;
            }
        }

        return $billTimes;
    }

    /**
     * Canonical bill_count formula. Every writer of bill_count must go through
     * this — a separate ad hoc count (e.g. StripeGateway\SubscriptionsManager
     * previously) silently drops the offset/deduction corrections below and
     * reports a wrong count until the next recompute.
     *
     * total > 0 CHARGE transactions linked to this subscription, adjusted for
     * the two one-time corrections decided at creation (see
     * CheckoutProcessor::syncInitialCycleCounting):
     * - billed_cycles_offset: free simulated-trial first cycle consumed a
     *   cycle without producing a total > 0 transaction.
     * - billed_cycles_deduction: real-trial signup-fee-only charge is a
     *   total > 0 transaction but isn't a billed cycle.
     */
    public function calculateBillCount()
    {
        $transacactionsCount = OrderTransaction::query()
            ->where('subscription_id', $this->id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->where('status', Status::TRANSACTION_SUCCEEDED)
            ->where('total', '>', 0)
            ->count();

        $earlyPaymentHistory = $this->getMeta('early_payment_history', []);
        foreach ((array)$earlyPaymentHistory as $earlyPayment) {
            $paidCount = (int) Arr::get($earlyPayment, 'count', 1);
            if ($paidCount > 1) {
                $transacactionsCount += ($paidCount - 1);
            }
        }

        $transacactionsCount += (int) $this->getMeta('billed_cycles_offset', 0);
        $transacactionsCount -= (int) $this->getMeta('billed_cycles_deduction', 0);

        return $transacactionsCount;
    }

    /**
     * Installment / split-pay plan: a finite-term subscription (a lifetime
     * license paid off in a fixed number of charges), as opposed to an
     * open-ended recurring subscription. The canonical structural signal is
     * bill_times > 0 (0 = infinite/open-ended). Reused across analytics,
     * filters and lifecycle handling — do NOT reintroduce title-string
     * ("Split") matching, which the data does not reliably carry.
     *
     * @return bool
     */
    public function isInstallment()
    {
        return (int) $this->bill_times > 0;
    }

    /**
     * Installments still owed: 0 for open-ended plans, or once the term is
     * fully paid.
     *
     * @return int
     */
    public function installmentsRemaining()
    {
        if (!$this->isInstallment()) {
            return 0;
        }

        return max(0, (int) $this->bill_times - (int) $this->bill_count);
    }

    /**
     * Has a finite installment plan collected every scheduled charge (end of
     * term)? Open-ended plans never reach term end.
     *
     * @return bool
     */
    public function hasReachedTermEnd()
    {
        return $this->isInstallment() && (int) $this->bill_count >= (int) $this->bill_times;
    }

    /**
     * Full committed price of an installment contract: recurring_total x
     * bill_times, in cents. 0 for open-ended plans (no fixed total). This is
     * the per-row form of the SUM(recurring_total * bill_times) used by the
     * subscription analytics aggregate.
     *
     * @return int
     */
    public function totalContractValue()
    {
        if (!$this->isInstallment()) {
            return 0;
        }

        return (int) $this->recurring_total * (int) $this->bill_times;
    }

    /**
     * Filter by plan type: 'installment' (finite term, bill_times > 0),
     * 'recurring' (open-ended, bill_times = 0) or anything else (no filter).
     * The bill_times threshold is kept identical to isInstallment() so the SQL
     * and PHP definitions never drift apart.
     */
    public function scopeOfPlanType($query, $planType)
    {
        if ($planType === 'installment') {
            return $query->where('bill_times', '>', 0);
        }
        if ($planType === 'recurring') {
            return $query->where('bill_times', '<=', 0);
        }

        return $query;
    }

    public function getReactivationTrialDays()
    {
        if (!$this->hasAccessValidity()) {
            return 0;
        }

        $lastPaidTransaction = OrderTransaction::query()
            ->where('subscription_id', $this->id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->where('status', Status::TRANSACTION_SUCCEEDED)
            ->where('total', '>', 0)
            ->orderBy('id', 'DESC')
            ->first();

        if ($lastPaidTransaction && $lastPaidTransaction->getMaxRefundableAmount() === 0) {
            return 0;
        }

        $nextBillingDate = $this->guessNextBillingDate(true);

        // @todo: Temporary fix for next billing date mismatch issue from migration

//        $nextBillingDate = $this->next_billing_date;
//
//        if (!$nextBillingDate) {
//            $nextBillingDate = $this->guessNextBillingDate(true);
//        }

        $nextBillingDate = strtotime($nextBillingDate);

        $currentDate = time();
        $trialDays = floor(($nextBillingDate - $currentDate) / DAY_IN_SECONDS); // Convert seconds to days

        if ($trialDays <= 1) {
            $trialDays = 0; // Ensure trial days are not negative
        }

        return $trialDays;
    }


    public function guessNextBillingDate($forced = false)
    {
        if ($this->next_billing_date && !$forced) {
            return $this->next_billing_date;
        }

        // preserve it during reactivation to maintain the billing cycle
        if ($this->next_billing_date && $this->status === Status::SUBSCRIPTION_CANCELED) {
            return $this->next_billing_date;
        }

        // we have to create a next billing date somehow!!
        $theLastOrder = Order::query()
            ->where(function ($q) {
                $q->where('parent_id', $this->parent_order_id)
                    ->orWhere('id', $this->parent_order_id);
            })
            ->orderBy('id', 'DESC')
            ->whereIn('payment_status', Status::getOrderPaymentSuccessStatuses())
            ->first();

        if ($theLastOrder) {
            $paidAnchor = SubscriptionHelper::resolvePaidAnchor($theLastOrder);

            if ($theLastOrder->type == 'renewal') {
                $nextBillingDate = gmdate('Y-m-d H:i:s', SubscriptionHelper::addBillingInterval($paidAnchor, $this->billing_interval, SubscriptionHelper::getBillingSchedule($this)));
            } else {
                if ($this->trial_days) {
                    $nextBillingDate = gmdate('Y-m-d H:i:s', strtotime($paidAnchor) + (int)($this->trial_days) * DAY_IN_SECONDS);
                } else {
                    $nextBillingDate = gmdate('Y-m-d H:i:s', SubscriptionHelper::addBillingInterval($paidAnchor, $this->billing_interval, SubscriptionHelper::getBillingSchedule($this)));
                }
            }
        } else {
            $nextBillingDate = gmdate('Y-m-d H:i:s', strtotime($this->created_at) + (int)($this->trial_days) * DAY_IN_SECONDS);
        }

        return $nextBillingDate;
    }

    /**
     * Check and expire subscriptions past their grace period
     *
     * This method is called by the hourly scheduler to automatically expire
     * subscriptions that have missed payments and are past their grace period.
     *
     * Processes all candidates in batches to avoid memory issues.
     * The query example works as follows:
     * SELECT * FROM subscriptions WHERE
            status IN ('active', 'trialing', 'canceled', 'expiring', 'past_due')
            AND next_billing_date IS NOT NULL
            AND id > 0                          -- last processed ID for batch cursor
            AND next_billing_date < DATE_SUB(
                '2026-02-17 10:00:00',
                INTERVAL (
                    CASE billing_interval
                        WHEN 'daily'       THEN 1
                        WHEN 'weekly'      THEN 3
                        WHEN 'monthly'     THEN 7
                        WHEN 'quarterly'   THEN 15
                        WHEN 'half_yearly' THEN 15
                        WHEN 'yearly'      THEN 15
                        ELSE 7
                    END
                ) DAY
            )
        ORDER BY id ASC
        LIMIT 100;
     *
     * @param int $batchSize Number of subscriptions to process per batch
     * @return array Statistics about processed subscriptions
     */
   public static function checkAndExpireSubscriptions($batchSize = 100)
    {
        $stats = [
            'checked'           => 0,
            'validity_expired'  => 0,
            'batches'           => 0,
            'expired_ids'       => [],
        ];

        $lastId = 0;

        do {
            $currentTime = time();
            $now = gmdate('Y-m-d H:i:s', $currentTime);

            $gracePeriodDays = SubscriptionHelper::getSubscriptionsGracePeriodDays();

            $cutoffDates = [];
            foreach ($gracePeriodDays as $interval => $days) {
                $cutoffDates[$interval] = gmdate('Y-m-d H:i:s', $currentTime - ((int)$days * DAY_IN_SECONDS));
            }

            // Fallback cutoff for unknown/null billing intervals.
            $defaultGraceDays = 7;
            $defaultCutoff = gmdate('Y-m-d H:i:s', $currentTime - ($defaultGraceDays * DAY_IN_SECONDS));
            $knownIntervals = array_keys($cutoffDates);

            // Include canceled subscriptions to check if validity is yet to expired
            // Exclude store-billed (manual/system) subscriptions — their expiry is
            // handled by the invoice-based overdue flow
            $subscriptions = Subscription::query()
                ->whereIn('status', [
                    Status::SUBSCRIPTION_ACTIVE,
                    Status::SUBSCRIPTION_TRIALING,
                    Status::SUBSCRIPTION_CANCELED,
                    Status::SUBSCRIPTION_EXPIRING,
                    Status::SUBSCRIPTION_PAST_DUE
                ])
                ->whereNotIn('collection_method', ['manual', 'system'])
                ->whereNotNull('next_billing_date')
                ->where('next_billing_date', '>', '0000-00-00 00:00:00')
                ->where('id', '>', $lastId)
                ->where(function ($query) use ($now, $cutoffDates, $knownIntervals, $defaultCutoff) {
                    $query->where(function ($subQuery) use ($cutoffDates, $knownIntervals, $defaultCutoff) {
                        $subQuery->whereIn('status', [
                            Status::SUBSCRIPTION_ACTIVE,
                            Status::SUBSCRIPTION_TRIALING,
                            Status::SUBSCRIPTION_EXPIRING,
                            Status::SUBSCRIPTION_PAST_DUE,
                        ])->where(function ($dateQuery) use ($cutoffDates, $knownIntervals, $defaultCutoff) {
                            $index = 0;

                            // OR together one (interval + its cutoff) clause per known interval.
                            foreach ($cutoffDates as $interval => $cutoff) {
                                $method = $index === 0 ? 'where' : 'orWhere';

                                $dateQuery->{$method}(function ($intervalQuery) use ($interval, $cutoff) {
                                    $intervalQuery->where('billing_interval', $interval)
                                        ->where('next_billing_date', '<', $cutoff);
                                });

                                $index++;
                            }

                            // Unknown/null intervals fall back to the default cutoff.
                            $dateQuery->orWhere(function ($intervalQuery) use ($knownIntervals, $defaultCutoff) {
                                $intervalQuery->where(function ($unknownIntervalQuery) use ($knownIntervals) {
                                    $unknownIntervalQuery->whereNotIn('billing_interval', $knownIntervals)
                                        ->orWhereNull('billing_interval');
                                })->where('next_billing_date', '<', $defaultCutoff);
                            });
                        });
                    // Branch B: canceled subs expire the moment their paid period ends (no grace).
                    })->orWhere(function ($subQuery) use ($now) {
                        $subQuery->where('status', Status::SUBSCRIPTION_CANCELED)
                            ->where('next_billing_date', '<', $now);
                    });
                })
                ->orderBy('id', 'ASC')
                ->limit($batchSize)
                ->with(['order', 'customer'])
                ->get();

            if ($subscriptions->isEmpty()) {
                break;
            }

            $stats['batches']++;
            $stats['checked'] += $subscriptions->count();

            foreach ($subscriptions as $subscription) {
                $nextBillingTimestamp = strtotime($subscription->next_billing_date);

                // Skip unparseable/invalid dates.
                if (!$nextBillingTimestamp || $nextBillingTimestamp <= 0) {
                    continue;
                }

                // Re-validate in PHP (SQL was a coarse filter) and derive the exact cutoff used as a write guard below.
                if ($subscription->status === Status::SUBSCRIPTION_CANCELED) {
                    // Superseded by an upgrade -> the new sub owns validity, leave this one alone.
                    if (isset($subscription->config['upgraded_to_sub_id'])) {
                        continue;
                    }

                    // Already processed in a prior run.
                    if ($subscription->getMeta('validity_expired_at')) {
                        continue;
                    }

                    // Paid period not over yet.
                    if ($nextBillingTimestamp >= $currentTime) {
                        continue;
                    }

                    $cutoff = $now;
                } else {
                    $graceDays = $gracePeriodDays[$subscription->billing_interval] ?? $defaultGraceDays;
                    $graceDays = max(0, (int)$graceDays);
                    $cutoffTimestamp = $currentTime - ($graceDays * DAY_IN_SECONDS);

                    // Still inside the grace window.
                    if ($nextBillingTimestamp >= $cutoffTimestamp) {
                        continue;
                    }

                    $cutoff = gmdate('Y-m-d H:i:s', $cutoffTimestamp);
                }

                // Null out next_billing_date so the row can't be re-selected/re-processed.
                $updateData = [
                    'next_billing_date' => NULL,
                    'updated_at'        => gmdate('Y-m-d H:i:s', $currentTime),
                ];

                // Canceled subs keep their status; only billing statuses flip to EXPIRED.
                if ($subscription->status !== Status::SUBSCRIPTION_CANCELED) {
                    $updateData['status'] = Status::SUBSCRIPTION_EXPIRED;
                }

                // Optimistic-lock write: only apply if status + past-cutoff still hold, so a concurrent
                // renewal/cancel between SELECT and UPDATE can't be overwritten with a stale decision.
                $updated = Subscription::query()
                    ->where('id', $subscription->id)
                    ->where('status', $subscription->status)
                    ->where('next_billing_date', '<', $cutoff)
                    ->update($updateData);

                if (!$updated) {
                    continue;
                }

                $subscription = Subscription::query()
                    ->with(['order', 'customer'])
                    ->find($subscription->id);

                if (!$subscription) {
                    continue;
                }

                // Idempotency marker + audit timestamp for this expiry.
                $subscription->updateMeta('validity_expired_at', gmdate('Y-m-d H:i:s', $currentTime));

                $event = new \FluentCart\App\Events\Subscription\SubscriptionValidityExpired(
                    $subscription,
                    $subscription->order,
                    $subscription->customer
                );

                $event->dispatch();

                $stats['validity_expired']++;
                $stats['expired_ids'][] = $subscription->id;
            }

            $lastId = $subscriptions->last()->id;

            unset($subscriptions);
        } while (true);

        if ($stats['checked'] > 0) {
            $expiredList = !empty($stats['expired_ids']) ? ' (IDs: ' . implode(', ', $stats['expired_ids']) . ')' : '';
            fluent_cart_add_log(
                'Subscription Validity Expiration Check',
                sprintf(
                    'Checked: %d subscriptions, Status changed to Expired: %d, Batches: %d%s',
                    $stats['checked'],
                    $stats['validity_expired'],
                    $stats['batches'],
                    $expiredList
                ),
                'info',
                $stats
            );
        }

        return $stats;
    }

}

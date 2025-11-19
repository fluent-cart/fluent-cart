<?php

namespace FluentCart\App\Models;

use FluentCart\Api\CurrencySettings;
use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Events\Subscription\SubscriptionCanceled;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Concerns\CanUpdateBatch;
use FluentCart\App\Models\Concerns\HasActivity;
use FluentCart\App\Services\Payments\PaymentHelper;
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
 * @package FluentCart\App\Models
 *
 * @version 1.0.0
 */
class Subscription extends Model
{
    use HasActivity, CanUpdateBatch;

    protected $table = 'fct_subscriptions';

    protected $primaryKey = 'id';

    protected $appends = ['url', 'payment_info', 'billingInfo', 'overridden_status', 'currency', 'reactivate_url'];

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
            return $decoded ?: $value;
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
        $variation = ProductVariation::find($this->variation_id);
        if (Arr::get($this->config, 'is_trial_days_simulated', 'no') == 'yes' && $this->status == Status::SUBSCRIPTION_TRIALING) {
            return Status::SUBSCRIPTION_ACTIVE;
        }

        if (Arr::get($this->config, 'is_trial_days_simulated', 'no') !== 'yes' && $this->status == Status::SUBSCRIPTION_ACTIVE && $this->trial_days && (strtotime($this->created_at) + ($this->trial_days * 86400)) > time()) {
            return Status::SUBSCRIPTION_TRIALING;
        }

        return $this->status;
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

        if(!$definedCurrency) {
            return $definedCurrency;
        }

        $currency = CurrencySettings::get('currency');
        return strtoupper($currency);
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

        return Helper::generateSubscriptionInfo($otherInfo, $recurringTotal) ?? '';
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

    public function canUpdatePaymentMethod()
    {
        $gateway = App::gateway($this->current_payment_method);
        if ($gateway && !in_array('card_update', $gateway->supportedFeatures)) {
            return false;
        }

        return in_array($this->status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING, Status::SUBSCRIPTION_PAUSED, Status::SUBSCRIPTION_INTENDED, Status::SUBSCRIPTION_PAST_DUE, Status::SUBSCRIPTION_FAILING, Status::SUBSCRIPTION_EXPIRING]); // past_due, is fallback for existing subscriptions, on new subscriptions update it will be expiring
    }

    public function canSwitchPaymentMethod()
    {
        $gateway = App::gateway($this->current_payment_method);
        if ($gateway && !in_array('switch_payment_method', $gateway->supportedFeatures)) {
            return false;
        }

        return in_array($this->status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING, Status::SUBSCRIPTION_PAUSED]);
    }

    public function canReactive()
    {
        if (isset($this->config['upgraded_to_sub_id']) || $this->recurring_amount <= 0) {
            return '';
        }

        if (isset($this->config['cancellation_reason']) && $this->config['cancellation_reason'] === 'refunded') {
            return '';
        }

        $canReactivate = in_array($this->status, [Status::SUBSCRIPTION_CANCELED, Status::SUBSCRIPTION_FAILING, Status::SUBSCRIPTION_EXPIRED, Status::SUBSCRIPTION_PAUSED, Status::SUBSCRIPTION_EXPIRING, Status::SUBSCRIPTION_PAST_DUE]);

        return apply_filters('fluent_cart/subscription/can_reactivate', $canReactivate, [
            'subscription' => $this
        ]);
    }

    public function getReactivateUrl()
    {
        if (!$this->canReactive()) {
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

        $invalidStatuses = [
            Status::SUBSCRIPTION_EXPIRED,
            Status::SUBSCRIPTION_PAST_DUE,
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
        if ($gateway = App::gateway($this->current_payment_method)) {
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
            'note'       => ''
        ]);

        if ($this->status === Status::SUBSCRIPTION_CANCELED) {
            return new \WP_Error('subscription_already_cancelled', __('This subscription is already cancelled.', 'fluent-cart'));
        }

        $gateway = App::gateway($this->current_payment_method);

        if ($gateway && $gateway->has('subscriptions')) {
            $vendorCanceled = $gateway->subscriptions->cancel($this->vendor_subscription_id, [
                'subscription_id' => $this->id,
                'parent_order_id' => $this->parent_order_id,
                'mode'            => $this->order->mode
            ]);

            if (!is_wp_error($vendorCanceled)) {
                $updateData = array_filter($vendorCanceled);
            }
        } else {
            $vendorCanceled = new \WP_Error('invalid_payment_method', __('This payment method does not support remote subscription cancel', 'fluent-cart'));
            $updateData = [
                'canceled_at' => gmdate('Y-m-d H:i:s', time())
            ];
        }

        if ($this->status !== Status::SUBSCRIPTION_COMPLETED) {
            $updateData['status'] = Status::SUBSCRIPTION_CANCELED;
        }

        if (empty($updateData['canceled_at']) && !$this->canceled_at) {
            $updateData['canceled_at'] = gmdate('Y-m-d H:i:s', time());
        }

        $config = $this->config;
        if ($args['reason']) {
            $config['cancellation_reason'] = $args['reason'];
        }
        $updateData['config'] = $config;

        $this->fill($updateData);
        $this->save();

        $note = $args['note'];

        if (!$note) {
            $note = 'on customer request';
        }

        if ($args['fire_hooks'] && $this->status !== Status::SUBSCRIPTION_COMPLETED) {
            (new SubscriptionCanceled($this, $this->order, $this->order->customer, $note))->dispatch();
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

    public function getRequiredBillTimes()
    {
        $billTimes = (int)$this->bill_times;

        if ($billTimes > 0) {
            $billTimes = $billTimes - $this->bill_count;
            if ($billTimes <= 0) {
                $transacactionsCount = OrderTransaction::query()
                    ->where('subscription_id', $this->id)
                    ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
                    ->where('status', Status::TRANSACTION_SUCCEEDED)
                    ->count();

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

    public function getReactivationTrialDays()
    {
        if (!$this->hasAccessValidity()) {
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
            $days = PaymentHelper::getIntervalDays($this->billing_interval);
            if ($theLastOrder->type == 'renewal') {
                $nextBillingDate = gmdate('Y-m-d H:i:s', strtotime($theLastOrder->created_at) + $days * DAY_IN_SECONDS);
            } else {
                if ($this->trial_days) {
                    $nextBillingDate = gmdate('Y-m-d H:i:s', strtotime($theLastOrder->created_at) + (int)($this->trial_days) * DAY_IN_SECONDS);
                } else {
                    $nextBillingDate = gmdate('Y-m-d H:i:s', strtotime($theLastOrder->created_at) + $days * DAY_IN_SECONDS);
                }
            }
        } else {
            $nextBillingDate = gmdate('Y-m-d H:i:s', strtotime($this->created_at) + (int)($this->trial_days) * DAY_IN_SECONDS);
        }

        return $nextBillingDate;
    }

}

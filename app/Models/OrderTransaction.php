<?php

namespace FluentCart\App\Models;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Models\Concerns\CanSearch;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\Framework\Database\Orm\Relations\HasOne;
use FluentCart\Framework\Support\Arr;

/**
 *  OrderTransaction Model - DB Model for Transactions
 *
 *  Database Model
 *
 * @package FluentCart\App\Models
 *
 * @version 1.0.0
 */
class OrderTransaction extends Model
{
    use CanSearch;

    protected $table = 'fct_order_transactions';

    protected $appends = ['url', 'refundable'];
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'order_id',
        'order_type',
        'vendor_charge_id',
        'payment_method',
        'payment_mode',
        'payment_method_type',
        'currency',
        'transaction_type',
        'subscription_id',
        'card_last_4',
        'card_brand',
        'status',
        'total',
        'rate',
        'meta',
        'uuid',
        'created_at'
    ];

    protected $searchable = [
        'id',
        'total',
        'status',
        'payment_method',
        'currency',
        'created_at',
        'updated_at',
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

    public function subscription()
    {
        return $this->hasOne(Subscription::class, 'id', 'subscription_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }


    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = md5(time() . wp_generate_uuid4());
            }
        });

        // created_at is the checkout time, which can be weeks before the money
        // moves (payment links, delayed webhooks) — the settlement moment is
        // only observable at this transition, so record it here. Gateways that
        // know the exact remote charge time set meta.settled_at themselves;
        // this fallback never overwrites it.
        static::saving(function ($model) {
            if ($model->status === Status::TRANSACTION_SUCCEEDED && $model->isDirty('status')) {
                $meta = $model->meta;
                if (empty($meta['settled_at'])) {
                    $meta['settled_at'] = DateTime::gmtNow()->format('Y-m-d H:i:s');
                    $model->meta = $meta;
                }
            }
        });
    }

    public function getUrlAttribute($value)
    {

        return apply_filters('fluent_cart/transaction/url_' . $this->getAttribute('payment_method'), '', [
            'transaction'      => $this,
            'payment_mode'     => $this->payment_mode,
            'vendor_charge_id' => $this->vendor_charge_id,
            'transaction_type' => $this->transaction_type
        ]);
    }

    public function scopeOfStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeOfPaymentMethod($query, $methodName)
    {
        return $query->where('payment_method', $methodName);
    }

    public function updateStatus($newStatus, $otherData = [])
    {
        $oldStatus = $this->status;

        if ($newStatus == $oldStatus) {
            return $this;
        }

        $this->status = $newStatus;

        if ($otherData) {
            $this->fill($otherData);
        }

        $this->save();

        return $this;
    }

    public static function bulkDeleteByOrderIds($ids, $params = [])
    {
        return static::getQuery()->whereIn('order_id', $ids)->delete();
    }

    public function orders(): HasOne
    {
        return $this->hasOne(Order::class, 'id', 'order_id');
    }

    public function getRefundableAttribute(): int
    {
        return $this->getMaxRefundableAmount();
    }

    public function getMaxRefundableAmount()
    {
        if ($this->status !== Status::TRANSACTION_SUCCEEDED) {
            return 0;
        }
        $refundAmount = (int)(Arr::get($this->meta, 'refunded_total', 0));
        $baseAmount   = $this->total - $refundAmount;
        $filtered     = apply_filters('fluent_cart/transaction/max_refundable_amount', $baseAmount, $this);
        return max(0, min((int) $filtered, $baseAmount));
    }

    public function getPaymentMethodText()
    {
        if ($this->card_brand && $this->card_last_4) {
            return sprintf('%1$s ***%2$s', esc_html($this->card_brand), esc_html($this->card_last_4));
        }

        return $this->payment_method;
    }

    /**
     * $filtered defaults to true because every caller redirects the customer and
     * therefore wants the filter. It defaulted to false, so a caller that omitted
     * the argument silently got an unfilterable URL — which is how the Stripe
     * confirmation redirect lost its filter in a refactor. The parameter is kept
     * so an explicit `false` can still ask for the raw URL.
     */
    public function getReceiptPageUrl($filtered = true)
    {
        $url = add_query_arg([
            'trx_hash' => $this->uuid
        ], (new StoreSettings())->getReceiptPage());

        if ($filtered) {
            $context = [
                'transaction' => $this,
                'order'       => $this->order,
            ];
            $url = apply_filters('fluent_cart/transaction/receipt_page_url', $url, $context);
        }

        return $url;
    }

    /**
     * Canonical post-payment redirect URL for this transaction.
     * Always fires the fluent_cart/payment/success_url filter.
     *
     * @param array $args Extra query args merged into the URL
     * @return string
     */
    public function getSuccessUrl($args = [])
    {
        return (new PaymentHelper((string)$this->payment_method))->successUrl($this, $args);
    }

    public function syncPendingTransaction()
    {
        if ($this->transaction_type !== Status::TRANSACTION_TYPE_CHARGE) {
            return new \WP_Error('invalid_transaction_type', __('Only charge transactions can be synced from the payment gateway.', 'fluent-cart'));
        }

        if ($this->status !== Status::TRANSACTION_PENDING) {
            return new \WP_Error('invalid_transaction_status', __('Only pending transactions can be synced from the payment gateway.', 'fluent-cart'));
        }

        if (!$this->vendor_charge_id) {
            return new \WP_Error('missing_vendor_charge_id', __('This transaction has no gateway charge ID to sync against.', 'fluent-cart'));
        }

        $gateway = GatewayManager::getInstance($this->payment_method);
        if ($gateway instanceof AbstractPaymentGateway) {
            return $gateway->syncRemoteTransaction($this);
        }

        return new \WP_Error('invalid_payment_method', __('This payment method does not support remote transaction sync', 'fluent-cart'));
    }

    public function acceptDispute($args = [])
    {
        if ($this->transaction_type !== Status::TRANSACTION_TYPE_DISPUTE) {
            return new \WP_Error('No dispute found!', __('The selected transaction is not a dispute', 'fluent-cart'));
        }

        $gateway = GatewayManager::getInstance($this->payment_method);
        
        if ($gateway && $gateway->has('dispute_handler')) {
             $handleRemoteDispute = $gateway->acceptRemoteDispute($this, $args);
             if (is_wp_error($handleRemoteDispute)) {
                return $handleRemoteDispute;
             }

             $this->status = Status::TRANSACTION_DISPUTE_LOST;
             $this->meta = array_merge($this->meta, [
                'is_dispute_actionable' => false,
                'is_charge_refundable' => false
             ]);
             $this->save();

             $newPaidAmount = intval($this->order->total_paid - $this->total);
             $this->order->update([
                'total_paid' => max($newPaidAmount, 0),
                'payment_status' => $newPaidAmount > 0 ? Status::PAYMENT_PARTIALLY_PAID : Status::PAYMENT_FAILED,
             ]);

             if (Arr::get($args, 'dispute_note')) {
                $this->meta = array_merge($this->meta, [
                    'dispute_note' => $args['dispute_note'],
                ]);
                $this->save();
             }

             fluent_cart_add_log(
                'Dispute accepted on ' . $this->payment_method, 
                'Dispute accepted! ' . $args['dispute_note'] ?? 'Note: ' . Arr::get($args, 'dispute_note'), 'success', [
                    'module_id' => $this->order->id,
                    'module_name' => 'order',
                ]
            );
        } else {
            return new \WP_Error('invalid_payment_method', __('This payment method does not support remote dispute management', 'fluent-cart'));
        }
    }

    public function scopeSearchByPayerEmail ($query, $data) {

        $operator = Arr::get($data, 'operator', 'contains');

        $search = Arr::get($data, 'value');
        $search = sanitize_text_field(trim($search));

        switch ($operator) {
            case 'starts_with':
                $pattern = $search . '%';
                break;
            case 'ends_with':
                $pattern = '%' . $search;
                break;
            case 'equals':
                $pattern = $search;
                break;
            case 'not_like':
                return $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.payer.email_address')) NOT LIKE ?", ['%' . $search . '%']);
            default: // contains
                $pattern = '%' . $search . '%';
                break;
        }

        return $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.payer.email_address')) LIKE ?", [$pattern]);

    }

}

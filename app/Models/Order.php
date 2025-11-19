<?php

namespace FluentCart\App\Models;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Concerns\CanSearch;
use FluentCart\App\Models\Concerns\CanUpdateBatch;
use FluentCart\App\Models\Concerns\HasActivity;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\OrderService;
use FluentCart\App\Services\TemplateService;
use FluentCart\App\Services\URL;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Database\Orm\Relations\BelongsTo;
use FluentCart\Framework\Database\Orm\Relations\HasMany;
use FluentCart\Framework\Database\Orm\Relations\HasManyThrough;
use FluentCart\Framework\Database\Orm\Relations\HasOne;
use FluentCart\Framework\Database\Orm\Relations\MorphMany;
use FluentCart\Framework\Support\Arr;
use FluentCartPro\App\Modules\Licensing\Models\License;

/**
 *  Order Model - DB Model for Orders
 *
 *  Database Model
 *
 * @package FluentCart\App\Models
 *
 * @version 1.0.0
 */
class Order extends Model
{
    use CanSearch, HasActivity, CanUpdateBatch;

    protected $table = 'fct_orders';

    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = md5(time() . wp_generate_uuid4());
            }

            if (!isset($model->config)) {
                $model->config = [];
            }

            if ($model->payment_status === 'paid' || apply_filters('fluent_cart/create_receipt_number_on_order_create', false)) {
                $model->receipt_number = OrderService::getNextReceiptNumber();
                $model->invoice_no = OrderService::getInvoicePrefix() . $model->receipt_number;
            }
        });

        static::created(function ($model) {
            if ($model->invoice_no) {
                do_action('fluent_cart/order/invoice_number_added', [
                    'order' => $model
                ]);
            }
        });
    }

    protected $fillable = [
        'status',
        'parent_id',
        'invoice_no',
        'receipt_number',
        'fulfillment_type',
        'type',
        'customer_id',
        'payment_method',
        'payment_method_title',
        'payment_status',
        'currency',
        'subtotal',
        'discount_tax',
        'manual_discount_total',
        'coupon_discount_total',
        'shipping_tax',
        'shipping_total',
        'tax_total',
        'tax_behavior',
        'total_amount',
        'rate',
        'note',
        'ip_address',
        'completed_at',
        'refunded_at',
        'total_refund',
        'uuid',
        'created_at',
        'refunded_at',
        'total_paid',
        'mode',
        'shipping_status',
        'config'
    ];

    protected $searchable = [
        'id',
        'total_amount',
        'status',
        'payment_method',
        'payment_status',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'subtotal'              => 'double',
        'discount_tax'          => 'double',
        'manual_discount_total' => 'double',
        'coupon_discount_total' => 'double',
        'shipping_tax'          => 'double',
        'shipping_total'        => 'double',
        'tax_total'             => 'double',
        'total_amount'          => 'double',
    ];

    public function parentOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'parent_id', 'id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Order::class, 'parent_id', 'id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(OrderTransaction::class, 'order_id', 'id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'parent_order_id', 'id');
    }

    public function order_items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id', 'id');
    }

    public function setConfigAttribute($value)
    {

        if ($value) {
            $decoded = \json_encode($value, true);
            if (!($decoded)) {
                $decoded = '[]';
            }
        } else {
            $decoded = '[]';
        }

        $this->attributes['config'] = $decoded;
    }

    public function getConfigAttribute($value)
    {
        if (!$value) {
            return [];
        }

        return \json_decode($value, true);
    }

    /**
     * Retrieves a filtered list of `order_items` based on priority rules for `payment_type`.
     *
     * The function applies the following logic in descending order of precedence:
     *
     * 1. **Priority 1: Onetime Items**
     *    - If `order_items` contain `payment_type` as `onetime`, return only those items.
     *
     * 2. **Priority 2: Subscription Items**
     *    - If there are no `onetime` items, return `subscription` items only if:
     *      - There is no `signup_fee` or `adjustment` for the same order.
     *    - This ensures `subscription` items are returned only when no other higher priority types are present.
     *
     * 3. **Priority 3: Adjustment Items**
     *    - If there are no `onetime` or `subscription` items, return `adjustment` items only if:
     *      - `subscription` items exist for the same order.
     *    - This prioritizes `adjustment` items when both `adjustment` and `subscription` are present.
     *
     * The function uses `whereExists` and `whereNotExists` subqueries to apply these priority rules.
     * - `whereExists` checks for the presence of certain `payment_type` values in the `order_items` table.
     * - `whereNotExists` ensures exclusion of specific `payment_type` values if higher priority types are present.
     *
     * @return HasMany
     *      The filtered `order_items` relationship, ordered by the specified priority rules.
     */

    public function filteredOrderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id', 'id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    public function orderMeta(): HasMany
    {
        return $this->hasMany(OrderMeta::class, 'order_id', 'id');
    }

    public function orderTaxRates(): HasMany
    {
        return $this->hasMany(OrderTaxRate::class, 'order_id', 'id');
    }


    public function appliedCoupons(): HasMany
    {
        return $this->hasMany(AppliedCoupon::class, 'order_id', 'id');
    }

    public function usedCoupons(): HasManyThrough
    {

        return $this->hasManyThrough(
            Coupon::class,         // Final model
            AppliedCoupon::class,  // Intermediate model
            'order_id',            // Foreign key on applied_coupons table
            'id',                  // Foreign key on coupons table
            'id',                  // Local key on orders table
            'coupon_id'            // Local key on applied_coupons table
        );
    }

    public function shipping_address(): HasOne
    {
        return $this->hasOne(OrderAddress::class, 'order_id', 'id')->where('type', 'shipping');
    }

    public function billing_address(): HasOne
    {
        return $this->hasOne(OrderAddress::class, 'order_id', 'id')->where('type', 'billing');
    }

    public function order_addresses(): HasMany
    {
        return $this->hasMany(OrderAddress::class, 'order_id', 'id');
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(License::class, 'order_id', 'id');
    }

    public function scopeSearchBy($query, $search)
    {
        $search = trim($search);

        if (!$search) {
            return $query;
        }

        $searchTerms = explode(' ', $search);

        return $query->where(function (Builder $q) use ($searchTerms) {
            $q->where('id', 'LIKE', "%{$searchTerms[0]}%")
                ->orWhere('status', 'LIKE', "%{$searchTerms[0]}%")
                ->when(is_numeric($searchTerms[0]), function ($q) use ($searchTerms) {
                    $q->orWhere('total_amount', Helper::toCent($searchTerms[0]));
                })
                //->orWhere('total_amount', Helper::toCent($searchTerms[0]))
                ->orWhere('payment_status', 'LIKE', "%{$searchTerms[0]}%")
                ->orWhere('payment_method', 'LIKE', "%{$searchTerms[0]}%")
                ->orWhere('invoice_no', 'LIKE', "%{$searchTerms[0]}%")
                ->orWhereHas('order_items', function ($orderItemQuery) use ($searchTerms) {
                    $orderItemQuery->where('post_title', 'LIKE', "%{$searchTerms[0]}%")
                        ->orWhere('title', 'LIKE', "%{$searchTerms[0]}%");
                })
                ->orWhereHas('customer', function ($customerQuery) use ($searchTerms) {
                    foreach ($searchTerms as $term) {
                        $customerQuery->where(function ($q) use ($term) {
                            $q->where('email', 'LIKE', "%{$term}%")
                                ->orWhere('first_name', 'LIKE', "%{$term}%")
                                ->orWhere('last_name', 'LIKE', "%{$term}%");
                        });
                    }
                });
        });
    }

    public function scopeOfPaymentStatus($query, $status)
    {
        return $query->where('payment_status', $status);
    }

    public function scopeOfOrderStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeOfShippingStatus($query, $status)
    {
        return $query->where('shipping_status', $status);
    }

    public function scopeOfOrderType($query, $type)
    {
        return $query->where('order_type', $type);
    }

    public function scopeOfPaymentMethod($query, $methodName)
    {
        return $query->where('payment_method', $methodName);
    }

    public function scopeApplyCustomFilters($query, $filters)
    {
        $acceptedKeys = $this->fillable;
        foreach ($filters as $filterKey => $filterValues) {
            $values = Arr::get($filterValues, 'value', []);
            if (!empty($values) && $filterKey && in_array($filterKey, $acceptedKeys)) {
                $query->search([$filterKey => ["column" => $filterKey, "operator" => "in", "value" => $values]]);
            }
        }

        return $query;
    }

    public function updateStatus($key, $newStatus)
    {
        $oldStatus = $this->$key;

        if ($newStatus == $oldStatus) {
            return $this;
        }

        if ($key === 'status' && $newStatus === Status::ORDER_COMPLETED) {
            $this->completed_at = DateTime::gmtNow();
        }

        if ($key === 'payment_status' && $newStatus === Status::PAYMENT_REFUNDED) {
            $this->refunded_at = DateTime::gmtNow();
        }

        $this->$key = $newStatus;
        $this->save();

        return $this;
    }

    public function updatePaymentStatus($newStatus)
    {
        $oldStatus = $this->payment_status;

        if ($newStatus == $oldStatus) {
            return $this;
        }

        if ($newStatus === Status::PAYMENT_REFUNDED) {
            $this->refunded_at = DateTime::gmtNow();
        }

        $this->payment_status = $newStatus;
        $this->save();

//        do_action('fluent_cart/order_status_to_' . $newStatus, [
//            'order' => $this,
//            'new_status' => $newStatus,
//            'old_status' => $oldStatus
//        ]);
//        do_action('fluent_cart/order_status_updated', [
//            'order' => $this,
//            'new_status' => $newStatus,
//            'old_status' => $oldStatus
//        ]);

        return $this;
    }

    public function getMeta($metaKey, $defaultValue = false)
    {
        $meta = OrderMeta::query()->where('order_id', $this->id)
            ->where('meta_key', $metaKey)
            ->first();

        if ($meta) {
            return $meta->meta_value;
        }

        return $defaultValue;
    }

    public function updateMeta($metaKey, $value)
    {
        $meta = OrderMeta::query()->where('order_id', $this->id)
            ->where('meta_key', $metaKey)
            ->first();

        if ($meta) {
            $meta->meta_value = $value;
            $meta->save();

            return $meta;
        }

        return OrderMeta::create([
            'order_id'   => $this->id,
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_key'   => $metaKey,
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'meta_value' => $value,
        ]);
    }

    public function deleteMeta($metaKey)
    {
        return OrderMeta::where('order_id', $this->id)
            ->where('meta_key', $metaKey)
            ->delete();
    }

    public function getTotalPaidAmount()
    {
        return $this->transactions()->where('status', Status::TRANSACTION_SUCCEEDED)->sum('total');
    }

    public function getTotalRefundAmount()
    {
        return $this->transactions()->where('status', Status::TRANSACTION_REFUNDED)->sum('total');
    }

    public function recountTotalPaidAndRefund()
    {
        $totalPaid = $this->getTotalPaidAmount();
        $totalRefunded = $this->getTotalRefundAmount();

        $this->total_refund = $totalRefunded;

        if (floatval($totalRefunded) >= floatval($totalPaid)) {
            $this->payment_status = Status::PAYMENT_REFUNDED;
        } elseif ($totalPaid > $totalRefunded) {
            $this->payment_status = Status::PAYMENT_PARTIALLY_REFUNDED;
        }

        $this->save();

        return $this;
    }

    public function syncOrderAfterRefund($type, $refundedAmount)
    {
        $paymentStatus = $type == 'full' ? Status::PAYMENT_REFUNDED : Status::PAYMENT_PARTIALLY_REFUNDED;
        $this->total_refund += $refundedAmount;
        $this->payment_status = $paymentStatus;

        $this->save();

        return $this;
    }

    public function updateRefundedItems($refundedItemIds, $refundedAmount)
    {
        // these are order item ids
        $totalItems = count($refundedItemIds);

        if ($totalItems === 1) {
            $orderItem = OrderItem::find($refundedItemIds[0]);
            $orderItem->refund_total += $refundedAmount;
            $orderItem->save();
            return;
        }

        if ($totalItems === 0) {
            // get all order items
            $refundedItemIds = $this->order_items->pluck('id')->toArray();
            $totalItems = count($refundedItemIds);
        }


        // Calculate remaining amount for each item
        $items = [];
        $totalRemain = 0;
        foreach ($refundedItemIds as $itemId) {
            $orderItem = OrderItem::find($itemId);
            $remain = max(0, $orderItem->line_total - $orderItem->refund_total);
            $items[] = [
                'model'  => $orderItem,
                'remain' => $remain
            ];
            $totalRemain += $remain;
        }

        if ($totalRemain == 0) {
            // nothing to refund
            return;
        }

        if ($totalRemain < $refundedAmount) {
            $refundedAmount = $totalRemain;
        }

        // Distribute refund proportionally
        $distributed = 0;
        foreach ($items as $index => $item) {
            if ($index === count($items) - 1) {
                // Assign the rest to the last item to avoid rounding issues
                $amount = $refundedAmount - $distributed;
            } else {
                $amount = round($refundedAmount * ($item['remain'] / $totalRemain), 2);
                $distributed += $amount;
            }
            $item['model']->refund_total += $amount;
            $item['model']->save();
        }
    }

    public function recountTotalPaid()
    {
        $totalPaid = $this->getTotalPaidAmount();
        $totalRefunded = $this->getTotalRefundAmount();

        $this->total_paid = ($totalPaid - $totalRefunded) < 0 ? 0 : $totalPaid - $totalRefunded;
        $this->save();
        return $this;
    }

    /**
     * Get the order's label.
     */
    public function labels(): MorphMany
    {
        return $this->morphMany(LabelRelationship::class, 'labelable');
    }

    public function getLatestTransactionAttribute()
    {
        return OrderTransaction::query()->where('order_id', $this->id)
            ->where('transaction_type', '!=', Status::TRANSACTION_TYPE_REFUND)
            ->where('status', '!=', Status::TRANSACTION_REFUNDED)
            ->orderBy('id', 'DESC')
            ->first();
    }

    public function renewals(): HasMany
    {
        return $this
            ->hasMany(Order::class, 'parent_id', 'id')
            ->where('type', 'renewal')
            ->wherenotIn('status', [
                Status::ORDER_CANCELED,
                Status::ORDER_FAILED,
                Status::ORDER_ON_HOLD
            ]);
    }

    public function isSubscription(): bool
    {
        return $this->order_items->where('payment_type', 'subscription')->count() > 0;
    }


    public function getViewUrl($type = 'customer')
    {

        if ($type === 'admin') {
            return URL::getDashboardUrl('orders/' . $this->id . '/view');
        }

        return TemplateService::getCustomerProfileUrl('order/' . $this->uuid);
    }

    public function getLatestTransaction()
    {
        return OrderTransaction::query()
            ->where('order_id', $this->id)
            ->where('transaction_type', '!=', Status::TRANSACTION_TYPE_REFUND)
            ->orderBy('id', 'DESC')
            ->first();
    }

    public function currentSubscription(): ?Subscription
    {
        return Subscription::query()
            ->where('parent_order_id', $this->id)
            ->where('status', 'active')
            ->orderBy('id', 'DESC')
            ->first();
    }

    public function getDownloads($scope = 'email'): array
    {
        if (!in_array($this->status, Status::getOrderSuccessStatuses())) {
            return [];
        }

        $order = $this->load('order_items');

        if ($order->order_items->isEmpty()) {
            return [];
        }

        $productIds = $order->order_items->pluck('post_id')->unique()->values();
        $productDownloads = ProductDownload::query()->whereIn('post_id', $productIds)->get()->keyBy('id');

        $groupedDownload = $productDownloads->groupBy('post_id');

        $downloadData = [];

        $alreadyAdded = [];

        foreach ($order->order_items as $item) {
            if ($item->payment_type === 'signup_fee') {
                continue;
            }

            $availableDownloads = Arr::get($groupedDownload, $item->post_id, []);

            $authorizedDownloads = [];

            foreach ($availableDownloads as $download) {

                $ids = $download->product_variation_id;

                if(in_array($download->id,$alreadyAdded)){
                    continue;
                }
                if (!is_array($ids) || empty($ids) || in_array($item->object_id, $ids)) {
                    
                    $authorizedDownloads [] =
                        [
                            'download_url'        => Helper::generateDownloadFileLink($download, $order->id),
                            'title'               => $download->title,
                            'file_size'           => $download->file_size,
                            'formatted_file_size' => Helper::readableFileSize($download['file_size']),
                        ];

                    $alreadyAdded[]= $download->id;
                }
            }

            if (!empty($authorizedDownloads)) {
                $downloadData[] = [
                    'title'           => $item->post_title . ' - ' . $item->title, // 'product name - variation title',
                    'product_id'      => $item->post_id,
                    'variation_id'    => $item->object_id,
                    'additional_html' => '',
                    'downloads'       => $authorizedDownloads
                ];
            }
        }


        return apply_filters('fluent_cart/single_order_downloads', $downloadData, [
            'order' => $order,
            'scope' => $scope
        ]);
    }

    public function getLicenses($with = ['product','productVariant'])
    {
        if (!ModuleSettings::isActive('license') || !App::isProActive()) {
            return null;
        }

        return License::query()->where('order_id', $this->id)
            ->with($with)
            ->get();
    }

    public function getDownloadsById($orderId): array
    {
        if (empty($orderId)) {
            return [];
        }

        $order = Order::query()->with('order_items')->find($orderId);

        if (empty($order)) {
            return [];
        }

        return $order->getDownloads();
    }

    public function getReceiptUrl()
    {
        return add_query_arg([
            'fluent-cart' => 'receipt',
            'order_hash'  => $this->uuid,
            'download'    => 1
        ], home_url());
    }

    public function addLog($title, $description = '', $type = 'info', $by = '')
    {

        fluent_cart_add_log(
            $title,
            $description,
            $type,
            [
                'module_type' => 'FluentCart\App\Models\Order',
                'module_id'   => $this->id,
                'module_name' => 'Order',
                'created_by'  => $by
            ]
        );
    }

    public function canBeRefunded(): bool
    {
        $config = $this->config;
        $upgradeTo = Arr::get($config, 'upgraded_to', 0);
        if (!empty($upgradeTo)) {
            return false;
        }
        return true;
    }

    public function generateReceiptNumber()
    {
        if ($this->receipt_number) {
            return $this;
        }

        $this->receipt_number = OrderService::getNextReceiptNumber();
        $this->invoice_no = OrderService::getInvoicePrefix() . $this->receipt_number;
        $this->save();

        do_action('fluent_cart/order/invoice_number_added', [
            'order' => $this
        ]);

        return $this;
    }

    public function orderOperation(): HasOne
    {
        return $this->hasOne(OrderOperation::class, 'order_id', 'id');
    }

    public function canBeDeleted()
    {
        $canBeDeleted = true;

        // Only canceled orders can be deleted
        if ($this->status === Status::ORDER_CANCELED) {

            $isFreeOrder = ((int)$this->total_amount) === 0;
            $isPaidOrder = in_array($this->payment_status, Status::getOrderPaymentSuccessStatuses(), true);

            // Free orders OR canceled unpaid orders can be deleted
            if ($isPaidOrder && !$isFreeOrder) {
                $canBeDeleted = new \WP_Error(
                    'order_cannot_be_deleted',
                    sprintf(
                        /* translators: 1: order/invoice number, 2: payment status */
                        __('Order %1$s cannot be deleted due to its current payment status: %2$s.', 'fluent-cart'),
                        $this->invoice_no,
                        $this->payment_status
                    )
                );
            }

        } else {
            $canBeDeleted = new \WP_Error(
                'order_cannot_be_deleted',
                sprintf(
                    /* translators: 1: order/invoice number, 2: order status */
                    __('Order %1$s cannot be deleted due to its current order status: %2$s.', 'fluent-cart'),
                    $this->invoice_no,
                    $this->status
                )
            );
        }

        if (!is_wp_error($canBeDeleted)) {
            // Handle subscription relationship
            $parentOrderId = $this->parent_id ? $this->parent_id : $this->id;

            $subscription = Subscription::query()
                ->where('parent_order_id', $parentOrderId)
                ->first();

            // If subscription is active, prevent deletion
            if (
                $subscription &&
                $subscription->status === Status::SUBSCRIPTION_ACTIVE &&
                $this->type === 'subscription'
            ) {
                $canBeDeleted = new \WP_Error(
                    'order_cannot_be_deleted',
                    sprintf(
                        /* translators: %s is the order/invoice number */
                        __('Order %s cannot be deleted as it has an active subscription.', 'fluent-cart'),
                        $this->invoice_no
                    )
                );
            }
        }


        return apply_filters('fluent_cart/order_can_be_deleted', $canBeDeleted, [
            'order' => $this
        ]);
    }


}

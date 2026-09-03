<?php

namespace FluentCart\App\Modules\StoreManagedRenewal\Services;

use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderTaxRate;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\Payments\SubscriptionHelper;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Modules\Subscriptions\Services\SystemChargeService;
use FluentCart\Api\StoreSettings;
use FluentCart\Framework\Support\Arr;
use WP_Error;

class RenewalService
{
    /**
     * Create renewal invoice for a subscription with manual payment method.
     * Invoices are created in advance (before the billing date) so customers
     * have time to pay before their period ends.
     *
     * @param Subscription $subscription
     * @return array|WP_Error Array with created order or empty array
     */
    public static function createRenewalOrders(Subscription $subscription)
    {
        $parentOrder = $subscription->order;

        if (!$parentOrder) {
            return new WP_Error('parent_order_not_found', __('Parent order not found for this subscription.', 'fluent-cart'));
        }

        if (!SubscriptionHelper::canProcessInMode($parentOrder->mode)) {
            return new WP_Error('store_mode_mismatch', sprintf(
                /* translators: 1: the subscription's payment mode (live/test), 2: the store's current mode (live/test) */
                __('Renewal skipped — this subscription is in %1$s mode but the store is currently in %2$s mode.', 'fluent-cart'),
                $parentOrder->mode,
                (new StoreSettings())->get('order_mode')
            ));
        }

        // Get original order item — use eager-loaded collection if available, otherwise query
        if ($parentOrder->relationLoaded('order_items')) {
            $parentOrderItem = $parentOrder->order_items->filter(function ($item) {
                return $item->payment_type === Status::ORDER_TYPE_SUBSCRIPTION;
            })->first();
        } else {
            $parentOrderItem = OrderItem::query()
                ->where('order_id', $parentOrder->id)
                ->where('payment_type', Status::ORDER_TYPE_SUBSCRIPTION)
                ->first();
        }

        if (!$parentOrderItem) {
            return new WP_Error('order_item_not_found', __('Original order item not found for this subscription.', 'fluent-cart'));
        }

        // Acquire a MySQL advisory lock to prevent concurrent duplicate invoice creation
        global $wpdb;
        $lockName = 'fc_renewal_' . $subscription->id;
        $acquired = (bool) $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 5)", $lockName));
        if (!$acquired) {
            return new WP_Error('lock_failed', __('Renewal creation already in progress for this subscription.', 'fluent-cart'));
        }

        // One subscription per parent order — an open renewal under this parent
        // is this subscription's renewal.
        $hasUnresolvedInvoice = Order::query()
            ->where('parent_id', $parentOrder->id)
            ->where('type', Status::ORDER_TYPE_RENEWAL)
            ->whereIn('payment_status', [
                Status::PAYMENT_PENDING,
                Status::PAYMENT_SCHEDULED,
                Status::PAYMENT_AUTHORIZED,
                Status::PAYMENT_PARTIALLY_PAID,
            ])
            ->exists();

        if ($hasUnresolvedInvoice) {
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lockName));
            return [];
        }

        // The due date is the original next_billing_date — the date the customer must pay by
        $dueDate = $subscription->next_billing_date;

        // Get product and variation information
        $product = $subscription->product;
        $variation = $subscription->variation;

        $taxTotal = $subscription->recurring_tax_total;
        if (!$taxTotal && $parentOrder->tax_behavior == 2 && $parentOrderItem) {
            $taxTotal = (int) Arr::get($parentOrderItem->other_info, 'recurring_tax', 0);
        }

        $total    = $subscription->recurring_total;
        $subtotal = $taxTotal ? ($total - $taxTotal) : $subscription->recurring_amount;

        // unit_price is the catalog (gross) per-unit price for inclusive tax (behavior 2) and the
        // net per-unit price for exclusive tax — the same convention initial orders use
        // (OrderService stores recurring_amount = unit_price * qty). The re-pay checkout feeds this
        // unit_price back as item_price; using the net $subtotal for an inclusive-tax subscription
        // would make the checkout total drop the included tax (e.g. $100 renders as $90.91).
        $unitPriceBase = ($parentOrder->tax_behavior == 2) ? $total : $subtotal;

        $fulfillmentType = $parentOrderItem->fulfillment_type;

        // System (auto-charged) subscriptions: the invoice waits in payment_scheduled
        // for its due-date off-session charge — no pay-now email. Everything else is
        // identical to a manual invoice, so a failed charge degrades cleanly to
        // pending + the normal dunning flow.
        $isSystemInvoice = $subscription->isSystem();

        // Create invoice (child order) — created_at is now, due date stored as meta
        $childOrderData = [
            'parent_id'        => $parentOrder->id,
            'fulfillment_type' => $fulfillmentType,
            'status'           => 'pending',
            'type'             => Status::ORDER_TYPE_RENEWAL,
            'mode'             => $parentOrder->mode,
            'shipping_status'  => $fulfillmentType === 'physical' ? Status::SHIPPING_UNSHIPPED : '',
            'customer_id'      => $subscription->customer_id,
            'payment_method'   => $subscription->current_payment_method,
            'payment_status'   => $isSystemInvoice ? Status::PAYMENT_SCHEDULED : Status::PAYMENT_PENDING,
            'currency'         => $parentOrder->currency,
            'tax_behavior'     => $parentOrder->tax_behavior,
            'subtotal'         => $subtotal,
            'tax_total'        => $taxTotal,
            'total_amount'     => $total,
            'total_paid'       => 0,
            'config'           => [
                'is_invoice'     => true,
                'invoice_source' => 'automatic_renewal'
            ]
        ];

        $wpdb->query('START TRANSACTION');

        try {
            $childOrder = Order::query()->create($childOrderData);
            if (!$childOrder) {
                throw new \RuntimeException(__('Failed to create child order for subscription renewal.', 'fluent-cart'));
            }

            // Store due_date as meta — used for overdue calculations and reminder timing
            $childOrder->updateMeta('due_date', $dueDate);

            self::copyParentOrderSnapshot($parentOrder, $childOrder);

            // Copy tax-rate rows from parent so invoice rendering has the breakdown
            foreach ($parentOrder->orderTaxRates as $taxRate) {
                OrderTaxRate::query()->create([
                    'order_id'    => $childOrder->id,
                    'tax_rate_id' => $taxRate->tax_rate_id,
                    'shipping_tax' => $taxRate->shipping_tax,
                    'order_tax'   => $taxRate->order_tax,
                    'total_tax'   => $taxRate->total_tax,
                    'meta'        => $taxRate->meta,
                ]);
            }

            // Create order item
            $orderItemData = [
                'order_id' => $childOrder->id,
                'post_id' => $subscription->product_id,
                'object_id' => $subscription->variation_id,
                'payment_type' => Status::ORDER_TYPE_SUBSCRIPTION,
                'post_title' => $product && $product->post_title ? $product->post_title : $subscription->item_name,
                'title' => $product && $variation ? $variation->variation_title : '',
                'quantity' => $subscription->quantity,
                'fulfillment_type' => $fulfillmentType,
                'unit_price' => $subscription->quantity > 1 ? (int)round($unitPriceBase / $subscription->quantity) : $unitPriceBase,
                'subtotal' => $subtotal,
                'tax_amount' => $taxTotal,
                'line_total' => $total,
                'line_meta' => [],
                'other_info' => []
            ];
            $orderItem = OrderItem::query()->create($orderItemData);
            if (!$orderItem) {
                throw new \RuntimeException(__('Failed to create order item for renewal order.', 'fluent-cart'));
            }

            // Create a pending transaction record
            $transaction = OrderTransaction::query()->create([
                'order_id' => $childOrder->id,
                'subscription_id' => $subscription->id,
                'order_type' => Status::ORDER_TYPE_RENEWAL,
                'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
                'payment_method' => $subscription->current_payment_method,
                'payment_mode' => $parentOrder->mode,
                'status' => Status::TRANSACTION_PENDING,
                'currency' => $parentOrder->currency,
                'total' => $total,
                'meta' => [
                    'is_invoice' => true,
                    'invoice_source' => 'automatic_renewal'
                ]
            ]);
            if (!$transaction) {
                throw new \RuntimeException(__('Failed to create transaction record for renewal order.', 'fluent-cart'));
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lockName));
            return new WP_Error('invoice_creation_failed', $e->getMessage());
        }

        $childOrder->addLog(
            'Renewal order created automatically',
            sprintf(
                'Subscription renewal order created. Due date: %s',
                $dueDate
            ),
            'info'
        );

        $subscription->addLog(
            'Upcoming renewal order created',
            sprintf(
                'Renewal order #%s created. Due date: %s',
                $childOrder->invoice_no ?: $childOrder->id,
                $dueDate
            ),
            'info'
        );

        if ($isSystemInvoice) {
            // No pay-now email — the charge is coming automatically on the due date.
            do_action('fluent_cart/subscriptions/system_renewal_scheduled', [
                'subscription' => $subscription,
                'order'        => $childOrder,
                'parent_order' => $parentOrder,
                'customer'     => $childOrder->customer,
                'transaction'  => $transaction
            ]);

            SystemChargeService::scheduleCharge($childOrder, $subscription);
        } else {
            do_action('fluent_cart/renewal_created', [
                'subscription' => $subscription,
                'order' => $childOrder,
                'parent_order' => $parentOrder,
                'customer' => $childOrder->customer,
                'transaction' => $transaction
            ]);
        }

        $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lockName));

        return [$childOrder];
    }

    private static function copyParentOrderSnapshot(Order $parentOrder, Order $childOrder): void
    {
        $billingAddress = $parentOrder->billing_address;
        $shippingAddress = $parentOrder->shipping_address;
        $customer = $parentOrder->customer;

        $fullName = '';
        $email = '';
        $firstName = '';
        $lastName = '';

        if ($customer) {
            $fullName = trim($customer->first_name . ' ' . $customer->last_name);
            $email = $customer->email;
            $firstName = $customer->first_name;
            $lastName = $customer->last_name;
        }

        $billingAddressData = $billingAddress ? [
            'type' => 'billing',
            'full_name' => $fullName,
            'address_1' => $billingAddress->address_1,
            'address_2' => $billingAddress->address_2,
            'city' => $billingAddress->city,
            'state' => $billingAddress->state,
            'postcode' => $billingAddress->postcode,
            'country' => $billingAddress->country,
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName
        ] : [];

        $shippingAddressData = $shippingAddress ? [
            'type' => 'shipping',
            'full_name' => $fullName,
            'address_1' => $shippingAddress->address_1,
            'address_2' => $shippingAddress->address_2,
            'city' => $shippingAddress->city,
            'state' => $shippingAddress->state,
            'postcode' => $shippingAddress->postcode,
            'country' => $shippingAddress->country,
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName
        ] : [];

        AddressHelper::insertOrderAddresses(
            $childOrder->id,
            $billingAddressData,
            $shippingAddressData
        );

        AddressHelper::copyOrderAddressMeta($childOrder->id, 'billing', $billingAddress);
        AddressHelper::copyOrderAddressMeta($childOrder->id, 'shipping', $shippingAddress);

        foreach (['tax_id', 'vat_tax_id', 'business_info', 'store_business_info'] as $metaKey) {
            $metaValue = $parentOrder->getMeta($metaKey, null);

            if ($metaValue !== null && $metaValue !== '' && $metaValue !== []) {
                $childOrder->updateMeta($metaKey, $metaValue);
            }
        }
    }

    /**
     * Calculate how many invoices are needed for a subscription
     *
     * This method determines how many billing cycles have been missed
     * and returns the number of invoices that need to be created
     *
     * @param Subscription $subscription
     * @return int Number of invoices needed
     */
    /**
     * Process multiple subscriptions and create renewal orders
     *
     * @param int $limit Maximum number of subscriptions to process
     * @return array Results with processed and failed subscriptions
     */
    /**
     * Cheap guard for the store-managed crons: does this site have any manual/system
     * subscription at all? Skips the heavy renewal/overdue scans on automatic-only stores.
     */
    private static function hasStoreManagedSubscriptions(): bool
    {
        return Subscription::query()
            ->whereIn('collection_method', ['manual', 'system'])
            ->exists();
    }

    public static function processDueSubscriptions($limit = 50)
    {
        $results = [
            'processed' => 0,
            'failed' => 0,
            'errors' => []
        ];

        // No store-managed subscriptions on this site — skip the expensive scan entirely.
        if (!self::hasStoreManagedSubscriptions()) {
            return $results;
        }

        $query = Subscription::query()
            ->with(['order.order_items', 'product', 'variation']);

        if (SubscriptionHelper::isModeGuardEnabled()) {
            $storeMode = (new StoreSettings())->get('order_mode');
            $query->whereHas('order', function ($query) use ($storeMode) {
                $query->where('mode', $storeMode);
            });
        }

        $subscriptions = $query
            ->whereIn('collection_method', ['manual', 'system'])
            ->whereNotIn('status', [
                Status::SUBSCRIPTION_COMPLETED,
                Status::SUBSCRIPTION_CANCELED,
                Status::SUBSCRIPTION_EXPIRED,
                Status::SUBSCRIPTION_PAUSED,
            ])
            ->whereNotNull('next_billing_date')
            ->where('next_billing_date', '>', '0000-00-00 00:00:00')
            ->where(function ($query) {
                self::applyRenewalCreationReadiness($query);
            })
            ->whereDoesntHave('order.children', function ($query) {
                $query->where('type', Status::ORDER_TYPE_RENEWAL)
                    ->whereIn('payment_status', [
                        Status::PAYMENT_PENDING,
                        Status::PAYMENT_SCHEDULED,
                        Status::PAYMENT_AUTHORIZED,
                        Status::PAYMENT_PARTIALLY_PAID,
                    ]);
            })
            ->orderBy('next_billing_date', 'ASC')
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->get();

        foreach ($subscriptions as $subscription) {
            try {
                $invoices = self::createRenewalOrders($subscription);

                if (is_wp_error($invoices)) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'subscription_id' => $subscription->id,
                        'error' => $invoices->get_error_message()
                    ];

                    fluent_cart_error_log(
                        'Renewal order creation failed for subscription: ' . $subscription->id,
                        $invoices->get_error_message()
                    );
                } else {
                    $results['processed'] += count($invoices);

                    fluent_cart_error_log(
                        'Renewal order creation success',
                        sprintf(
                            'Created %d renewal order(s) for subscription: %d',
                            count($invoices),
                            $subscription->id
                        )
                    );
                }
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage()
                ];
                
                fluent_cart_error_log(
                    'Exception during invoice creation for subscription: ' . $subscription->id,
                    $e->getMessage()
                );
            }
        }

        return $results;
    }

    private static function applyRenewalCreationReadiness($query): void
    {
        $now = gmdate('Y-m-d H:i:s');

        // `trialing` is live state cleared by the first paid renewal — historical
        // trial_days must not route a post-trial subscription down the trial branch.
        $query->where(function ($trialQuery) use ($now) {
            $trialQuery->where('status', Status::SUBSCRIPTION_TRIALING)
                ->where('next_billing_date', '<=', $now);
        })->orWhere(function ($standardQuery) {
            $standardQuery->where('status', '!=', Status::SUBSCRIPTION_TRIALING)
                ->where(function ($windowQuery) {
                    self::applyAdvanceCreationWindow($windowQuery);
                });
        });
    }

    private static function applyAdvanceCreationWindow($query): void
    {
        $advanceDaysMap = self::getAdvanceCreationDaysMap();
        $knownIntervals = array_keys($advanceDaysMap);
        $index = 0;

        foreach ($advanceDaysMap as $interval => $days) {
            $method = $index === 0 ? 'where' : 'orWhere';
            $threshold = gmdate('Y-m-d H:i:s', time() + ((int) $days * DAY_IN_SECONDS));

            $query->{$method}(function ($intervalQuery) use ($interval, $threshold) {
                $intervalQuery->where('billing_interval', $interval)
                    ->where('next_billing_date', '<=', $threshold);
            });

            $index++;
        }

        $defaultThreshold = gmdate('Y-m-d H:i:s', time() + (7 * DAY_IN_SECONDS));
        $query->orWhere(function ($intervalQuery) use ($knownIntervals, $defaultThreshold) {
            $intervalQuery->where(function ($unknownIntervalQuery) use ($knownIntervals) {
                $unknownIntervalQuery->whereNotIn('billing_interval', $knownIntervals)
                    ->orWhereNull('billing_interval');
            })->where('next_billing_date', '<=', $defaultThreshold);
        });
    }

    /**
     * Handle invoice payment - sync subscription state after manual invoice is paid.
     *
     * - Paid on or before due_date: next_billing_date = due_date + interval (cadence preserved)
     * - Paid after due_date (late): next_billing_date = paid_at + interval
     *
     * Uses syncSubscriptionStates() to derive bill_count from actual succeeded transactions
     * and handle EOT/status transitions consistently with automatic subscriptions.
     *
     * @param array $data Event data containing order, transaction, customer
     */
    public static function handleRenewalPaid(array $data): void
    {
        $order = $data['order'] ?? null;

        if (!$order || $order->type !== Status::ORDER_TYPE_RENEWAL || !$order->parent_id) {
            return;
        }

        $subscription = Subscription::query()
            ->where('parent_order_id', $order->parent_id)
            ->whereIn('collection_method', ['manual', 'system'])
            ->whereIn('status', [
                Status::SUBSCRIPTION_ACTIVE,
                Status::SUBSCRIPTION_TRIALING,
                Status::SUBSCRIPTION_PAST_DUE,
                Status::SUBSCRIPTION_EXPIRED,
                Status::SUBSCRIPTION_CANCELED,
                Status::SUBSCRIPTION_FAILING,
                Status::SUBSCRIPTION_EXPIRING,
                Status::SUBSCRIPTION_PAUSED,
            ])
            ->first();

        if (!$subscription) {
            return;
        }

        // Idempotency: skip if subscription renewal was already processed for this invoice
        if ($order->getMeta('renewal_processed')) {
            return;
        }

        $dueDate = $order->getMeta('due_date');
        $paidAt = time();
        $schedule = SubscriptionHelper::getBillingSchedule($subscription);

        if ($dueDate && $paidAt <= strtotime($dueDate)) {
            $nextBillingDate = gmdate('Y-m-d H:i:s', SubscriptionHelper::addBillingInterval($dueDate, $subscription->billing_interval, $schedule));
        } else {
            $nextBillingDate = gmdate('Y-m-d H:i:s', SubscriptionHelper::addBillingInterval($paidAt, $subscription->billing_interval, $schedule));
        }

        // syncSubscriptionStates derives bill_count from DB transactions, handles EOT
        // (fires SubscriptionEOT internally when bill_count >= bill_times), and saves
        $subscription = SubscriptionService::syncSubscriptionStates($subscription, [
            'status'                 => Status::SUBSCRIPTION_ACTIVE,
            'next_billing_date'      => $nextBillingDate,
            'current_payment_method' => $order->payment_method,
            'canceled_at'            => null,
        ]);

        $subscription->deleteMeta('pending_skip_until');

        // Mark this invoice as processed — prevents duplicate handling if called again
        $order->updateMeta('renewal_processed', 1);

        // If EOT was reached, syncSubscriptionStates already dispatched SubscriptionEOT
        if ($subscription->status === Status::SUBSCRIPTION_COMPLETED) {
            return;
        }

        $subscription->addLog(
            'Subscription renewed',
            sprintf('Renewal order #%s paid — subscription renewed', $order->invoice_no ?: $order->id),
            'success'
        );

        (new \FluentCart\App\Events\Subscription\SubscriptionRenewed(
            $subscription,
            $order,
            $subscription->order,
            $order->customer
        ))->dispatch();
    }

    /**
     * Process overdue invoices and transition subscription statuses
     *
     * Thresholds are anchored to the invoice due_date, not a multiple of the billing
     * interval (a fixed 1×/2× interval would give a yearly plan a 365/730-day limbo):
     * - Due date passed: active/trialing → past_due
     * - Past the per-interval grace period: past_due → expired
     *
     * The grace period reuses SubscriptionHelper::getSubscriptionsGracePeriodDays()
     * (filter fluent_cart/subscription/grace_period_days) — the same map the automatic
     * expiry cron uses, so manual and automatic subscriptions expire on one contract.
     *
     * @param int $limit Maximum number of invoices to process
     * @return array Results with past_due and expired counts
     */
    public static function processOverdueRenewals(int $limit = 50): array
    {
        $results = [
            'past_due' => 0,
            'expired'  => 0,
            'errors'   => []
        ];

        // No store-managed subscriptions on this site — skip the expensive scan entirely.
        if (!self::hasStoreManagedSubscriptions()) {
            return $results;
        }

        $invoiceQuery = Order::query()
            ->where('type', Status::ORDER_TYPE_RENEWAL);

        if (SubscriptionHelper::isModeGuardEnabled()) {
            $invoiceQuery->where('mode', (new StoreSettings())->get('order_mode'));
        }

        $pendingInvoices = $invoiceQuery
            ->whereIn('payment_status', [
                Status::PAYMENT_PENDING,
                Status::PAYMENT_SCHEDULED,
                Status::PAYMENT_AUTHORIZED,
                Status::PAYMENT_PARTIALLY_PAID,
            ])
            ->whereHas('parentOrder.subscriptions', function ($query) {
                $query->whereIn('collection_method', ['manual', 'system'])
                    ->whereNotIn('status', [
                        Status::SUBSCRIPTION_COMPLETED,
                        Status::SUBSCRIPTION_CANCELED,
                        Status::SUBSCRIPTION_EXPIRED,
                    ]);
            })
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->get();

        $now = time();

        foreach ($pendingInvoices as $order) {
            try {
                if (!$order->parent_id) {
                    continue;
                }

                $subscription = Subscription::query()
                    ->where('parent_order_id', $order->parent_id)
                    ->whereIn('collection_method', ['manual', 'system'])
                    ->whereNotIn('status', [
                        Status::SUBSCRIPTION_COMPLETED,
                        Status::SUBSCRIPTION_CANCELED,
                        Status::SUBSCRIPTION_EXPIRED,
                    ])
                    ->first();

                if (!$subscription) {
                    continue;
                }

                $chargeState = $subscription->getMeta('system_charge_state', []) ?: [];
                $chargeQueued = $subscription->isSystem() && SystemChargeService::hasQueuedCharge($order, $chargeState);

                if ($order->payment_status === Status::PAYMENT_SCHEDULED && $subscription->isSystem()) {
                    $isSettling = Arr::get($chargeState, 'status') === 'processing'
                        && (int) Arr::get($chargeState, 'order_id') === (int) $order->id;

                    if ($isSettling || $chargeQueued) {
                        continue;
                    }
                }

                $graceDays = SubscriptionHelper::getGracePeriodDaysForInterval($subscription->billing_interval);
                $dueDate = $order->getMeta('due_date') ?: $order->created_at;
                $invoiceAge = ($now - strtotime($dueDate)) / 86400;

                // A failed charge flips its invoice to `pending`, so a retrying system
                // subscription goes past_due like any other unpaid one — but it must not
                // EXPIRE while an attempt is still queued, or the retry fires against a
                // dead subscription. Retries are spaced inside the grace period, so this
                // only bites when a filter pushes an offset past it.
                if ($chargeQueued && $invoiceAge >= $graceDays) {
                    continue;
                }

                // Past the per-interval grace period: past_due → expired
                if ($invoiceAge >= $graceDays && $subscription->status === Status::SUBSCRIPTION_PAST_DUE ) {
                    // CAS the past_due → expired write inside syncSubscriptionStates; if a
                    // concurrent renewal payment reactivated the row, it returns null and we skip.
                    $expired = SubscriptionService::syncSubscriptionStates($subscription, [
                        'status'            => Status::SUBSCRIPTION_EXPIRED,
                        'next_billing_date' => null,
                    ], Status::SUBSCRIPTION_PAST_DUE);

                    if (!$expired) {
                        continue;
                    }

                    $subscription->addLog(
                        'Subscription expired',
                        sprintf(
                            'Unpaid invoice #%s exceeded the %d-day grace period. Subscription expired.',
                            $order->invoice_no ?: $order->id,
                            $graceDays
                        ),
                        'error'
                    );

                    $results['expired']++;
                    continue;
                }

                // Due date passed: active/trialing → past_due
                if ($invoiceAge >= 0 && in_array($subscription->status, [
                    Status::SUBSCRIPTION_ACTIVE,
                    Status::SUBSCRIPTION_TRIALING,
                ])) {
                    $updated = Subscription::query()
                        ->where('id', $subscription->id)
                        ->whereIn('status', [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING])
                        ->update(['status' => Status::SUBSCRIPTION_PAST_DUE]);

                    if (!$updated) {
                        continue;
                    }

                    $subscription->status = Status::SUBSCRIPTION_PAST_DUE;

                    $subscription->addLog(
                        'Subscription marked as past due',
                        sprintf(
                            'Unpaid invoice #%s is past its due date',
                            $order->invoice_no ?: $order->id
                        ),
                        'warning'
                    );

                    do_action('fluent_cart/subscription_past_due', [
                        'subscription' => $subscription,
                        'order'        => $order,
                        'customer'     => $order->customer,
                    ]);

                    $results['past_due']++;
                }
            } catch (\Exception $e) {
                $results['errors'][] = [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage()
                ];

                fluent_cart_error_log(
                    'Overdue invoice processing failed for order: ' . $order->id,
                    $e->getMessage()
                );
            }
        }

        return $results;
    }

    /**
     * Advance next_billing_date one (or more, if overdue) whole intervals ahead,
     * keeping day-of-cycle alignment. Pure — no persistence, no side effects.
     *
     * @return string|null Advanced GMT datetime, or null when there is no billing date
     *   or the interval cannot be resolved (unknown billing_interval).
     */
    public static function computeSkippedDate(Subscription $subscription): ?string
    {
        $oldDate = $subscription->next_billing_date;

        if (!$oldDate) {
            return null;
        }

        $schedule = SubscriptionHelper::getBillingSchedule($subscription);
        $newTs = SubscriptionHelper::addBillingInterval($oldDate, $subscription->billing_interval, $schedule);

        // Overdue dates: advance whole intervals until in the future. Guard the loop
        // so a zero-progress interval (broken day-count filter) can never spin.
        $now = time();
        while ($newTs <= $now) {
            $advanced = SubscriptionHelper::addBillingInterval($newTs, $subscription->billing_interval, $schedule);
            if ($advanced <= $newTs) {
                break;
            }
            $newTs = $advanced;
        }

        $newDate = gmdate('Y-m-d H:i:s', $newTs);

        return $newDate === $oldDate ? null : $newDate;
    }

    /**
     * Admin "Skip Next Period" for a store-billed subscription. Advances
     * next_billing_date by one interval without creating an invoice.
     *
     * Atomic: the date advance is a compare-and-swap on the original
     * next_billing_date, so two racing skips cannot both advance the same period,
     * and the void, marker, and audit commit as one unit. The no-stacked-skip
     * invariant is enforced here so the button and the API share one guard.
     *
     * @param string $note Optional admin reason for the skip, recorded in the audit trail and log.
     * @return array{skipped: bool, old_next_billing_date?: string, new_next_billing_date?: string, reason?: string}
     */
    public static function skipNextPeriod(Subscription $subscription, string $note = ''): array
    {
        global $wpdb;

        $oldDate = $subscription->next_billing_date;

        if (!$oldDate) {
            return ['skipped' => false, 'reason' => 'no_billing_date'];
        }

        // No stacking: a period already skipped-ahead cannot be skipped again until it elapses.
        if ($subscription->hasPendingSkip()) {
            return ['skipped' => false, 'reason' => 'already_pending'];
        }

        $newDate = self::computeSkippedDate($subscription);

        if (!$newDate) {
            // Interval could not be resolved (e.g. unknown billing_interval) — nothing to advance.
            return ['skipped' => false, 'reason' => 'no_advance'];
        }

        $wpdb->query('START TRANSACTION');

        try {
            // Compare-and-swap: advance only while next_billing_date is still what we read.
            // A concurrent skip that already moved it makes this a no-op (0 rows affected).
            $affected = Subscription::query()
                ->where('id', $subscription->id)
                ->where('next_billing_date', $oldDate)
                ->update(['next_billing_date' => $newDate]);

            if (!$affected) {
                $wpdb->query('ROLLBACK');
                return ['skipped' => false, 'reason' => 'raced'];
            }

            // Keep the in-memory model in sync for callers that save() afterwards.
            $subscription->fill(['next_billing_date' => $newDate]);

            SubscriptionService::voidPendingRenewals($subscription, 'Billing period skipped by admin');

            // Marker for hasPendingSkip(): while next_billing_date still equals this
            // skipped-to date, the skip is pending and cannot be stacked again.
            $subscription->updateMeta('pending_skip_until', $newDate);

            // Append to skipped_periods meta — permanent audit trail of every skipped period
            $actor = wp_get_current_user();
            $skippedPeriods = $subscription->getMeta('skipped_periods', []) ?: [];
            $skippedPeriods[] = [
                'date'         => $oldDate,
                'skipped_at'   => gmdate('Y-m-d H:i:s'),
                'resumes_at'   => $newDate,
                'actor_id'     => $actor->exists() ? $actor->ID : 0,
                'actor_name'   => $actor->exists() ? ($actor->display_name ?: 'FCT-BOT') : 'FCT-BOT',
                'reason'       => $note,
            ];
            $subscription->updateMeta('skipped_periods', $skippedPeriods);

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        $logBody = sprintf(
            'Billing period skipped. next_billing_date advanced from %s to %s.',
            $oldDate,
            $newDate
        );

        if ($note !== '') {
            $logBody .= sprintf(' Reason: %s', $note);
        }

        $subscription->addLog('Billing period skipped by admin', $logBody, 'info');

        // New tag fluent_cart/subscription_period_skipped — no prior contract.
        SubscriptionService::dispatchStatusEvent($subscription, 'period_skipped', [
            'old_next_billing_date' => $oldDate,
            'new_next_billing_date' => $newDate,
        ]);

        // Same contract as syncSubscriptionStates()'s no-status-change branch —
        // Pro's license-extension listener only reacts to this hook.
        do_action('fluent_cart/subscription/data_updated', [
            'subscription' => $subscription,
            'updated_data' => ['next_billing_date' => $newDate]
        ]);

        return [
            'skipped'               => true,
            'old_next_billing_date' => $oldDate,
            'new_next_billing_date' => $newDate,
        ];
    }

    /**
     * Advance a manual subscription's next_billing_date past a voided invoice's period.
     *
     * Voiding alone only cancels the invoice row; the subscription's next_billing_date
     * is unchanged, so processDueSubscriptions regenerates the same invoice within the
     * hour. Moving the billing date one interval past the voided period makes the void
     * genuinely skip that period (same end state as skipNextPeriod, for a single
     * admin-voided invoice).
     *
     * @param Order $voidedInvoice The renewal order that was just voided.
     * @return void
     */
    public static function advanceAfterVoid(Order $voidedInvoice): void
    {
        if (!$voidedInvoice->parent_id) {
            return;
        }

        $subscription = Subscription::query()
            ->where('parent_order_id', $voidedInvoice->parent_id)
            ->whereIn('collection_method', ['manual', 'system'])
            ->first();

        if (!$subscription || !$subscription->next_billing_date) {
            return;
        }

        $dueDate = $voidedInvoice->getMeta('due_date') ?: $voidedInvoice->created_at;

        // Only advance when the void targets the current/next billing period. A stale
        // invoice from an already-passed period must not push the date forward again.
        if (strtotime($subscription->next_billing_date) > strtotime($dueDate)) {
            return;
        }

        $newDate = gmdate('Y-m-d H:i:s', SubscriptionHelper::addBillingInterval($dueDate, $subscription->billing_interval, SubscriptionHelper::getBillingSchedule($subscription)));

        $subscription->update(['next_billing_date' => $newDate]);
    }

    /**
     * How many days before its due date a renewal invoice is generated, per billing
     * interval. Single source of truth for both the scheduler and the admin info display.
     * Filterable so developers can tune the advance window per interval.
     *
     * @param string $interval Billing interval being looked up ('' when the full map is wanted).
     * @return array<string,int>
     */
    public static function getAdvanceCreationDaysMap(string $interval = ''): array
    {
        return apply_filters('fluent_cart/renewal/advance_creation_days', [
            'daily'       => 0,
            'weekly'      => 3,
            'monthly'     => 7,
            'quarterly'   => 15,
            'half_yearly' => 15,
            'yearly'      => 15,
        ], $interval);
    }

}

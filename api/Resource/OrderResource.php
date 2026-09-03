<?php

namespace FluentCart\Api\Resource;

use FluentCart\Api\Orders;
use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Events\Order\OrderDeleting;
use FluentCart\App\Events\Order\OrderDeleted;
use FluentCart\App\Events\Order\RenewalOrderDeleted;
use FluentCart\App\Events\Order\OrderStatusUpdated;
use FluentCart\App\Events\Order\OrderUpdated;
use FluentCart\App\Events\StockChanged;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\AdminOrderProcessor;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Activity;
use FluentCart\App\Models\AppliedCoupon;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Coupon;
use FluentCart\App\Models\CustomerAddresses;
use FluentCart\App\Models\LabelRelationship;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderAddress;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderDownloadPermission;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\OrderOperation;
use FluentCart\App\Models\OrderTaxRate;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Query\QueryParser;
use FluentCart\App\Models\Query\Sort;
use FluentCart\App\Models\ShippingMethod;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Models\SubscriptionMeta;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\OrderService;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Services\Tax\AdminOrderTaxService;
use FluentCart\App\Modules\Tax\TaxModule;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Database\Orm\Collection;
use FluentCart\Framework\Support\Arr;


class OrderResource extends BaseResourceApi
{
    public static function getQuery(): Builder
    {
        return Order::query();
    }

    /**
     * Retrieve orders with additional data based on specified parameters.
     *
     * @param array $params Optional. Additional parameters for order retrieval.
     *        $params = [
     *           'search'     => ( string ) Optional. Search Order.
     *               [
     *                  'column name(e.g., first_name|last_name|email|id)' => [
     *                      column => 'column name(e.g., first_name|last_name|email|id)',
     *                      operator => 'operator (e.g., like_all|rlike|or_rlike|or_like_all)',
     *                      value => 'value' ]
     * ],
     *            'filters'   => ( string ) Optional. Filters order.
     *               [
     *                  'column name(e.g., status|payment_status|payment_method)' => [
     *                      column => 'column name(e.g., status|payment_status|payment_method)',
     *                      operator => 'operator (e.g., in)',
     *                      value => 'value' ]
     * ],
     *            'order_by'     => ( string ) Optional. Column to order by,
     *            'order_type'   => ( string ) Optional. Order type for sorting ( ASC or DESC ),
     *            'per_page'     => ( int ) Optional. Number of items for per page,
     *            'page'         => ( int ) Optional. Page number for pagination
     * ]
     *
     */
    public static function get(array $params = [])
    {
        $query = static::getQuery();
        $dynamicConditions = Arr::get($params, 'dynamic_filters') ?? [];
        QueryParser::make()->parse($query, $dynamicConditions);
        $sortCriteria = Arr::get($params, 'sort_criteria', []);
        Sort::make()->apply($query, $sortCriteria);


        $with = array_merge(['customer', 'filteredOrderItems'], Arr::get($params, 'with', []));

        return $query->with($with)
            ->whereHas('customer', function ($query) use ($params) {
                $query->when(Arr::get($params, 'search'), function ($query) use ($params) {
                    return $query->search(Arr::get($params, 'search', ''));
                });
            })
            ->applyCustomFilters(Arr::get($params, 'filters', []))
            ->when(!count($sortCriteria), function ($query) use ($params) {
                $query->orderBy(
                    sanitize_sql_orderby(Arr::get($params, 'order_by', 'id')),
                    sanitize_sql_orderby(Arr::get($params, 'order_type', 'DESC'))
                );
            })
            ->paginate(Arr::get($params, 'per_page'), ['*'], 'page', Arr::get($params, 'page'));
    }


    /**
     * Find an order by ID with associated customer and address details.
     *
     * @param string $id Required. The UUID of the order to find.
     * @param array $params Optional. Additional parameters for order retrieval.
     *        [
     *              // Include optional parameters, if any.
     * ]
     *
     */
    public static function find($id, $params = [])
    {
        $with = Arr::get($params, 'with', []);
        return static::getQuery()
            ->with($with)
            ->with([
                'customer' => function ($query) {
                    $query->with([
                        'billing_address' => function ($query) {
                            $query->where('is_primary', '1');
                        }
                    ]);
                    $query->with([
                        'shipping_address' => function ($query) {
                            $query->where('is_primary', '1');
                        }
                    ]);
                }
            ])
            ->where('uuid', $id)
            ->first();
    }

    /**
     * Create an order with the provided data.
     *
     * @param array $data Required. Array containing the necessary parameters for order creation.
     *        $data = [
     *            'status'     => ( string )    Required. The status of the order,
     *            // Include additional parameters, if any.
     * ]
     * @param array $params Optional. Additional parameters for order creation.
     *        [
     *            // Include optional parameters, if any.
     * ]
     *
     */
    public static function create($data, $params = [])
    {
        $order = $data;
        $orderItems = Arr::except(Arr::get($order, 'order_items', []), ['*']);
        $hasPhysicalProduct = false;

        foreach ($orderItems as $item) {
            if (isset($item['trial_days']) && $item['trial_days'] > 0) {
                continue;
            }
            if (Arr::get($item, 'fulfillment_type') == 'physical') {
                $hasPhysicalProduct = true;
            }
        }

        $subtotal = OrderService::getItemsAmountWithoutDiscount($orderItems); //get order total without a discount

        // because of decimal issue commented this below line, using OrderService::getCouponDiscountTotal instead
        // $subtotalWithDiscount = OrderService::getItemsAmountTotal($orderItems, false, false); //get order total with discount
        $couponDiscountTotal = OrderService::getCouponDiscountTotal($orderItems);

        $totalAmount = floatVal($subtotal + Arr::get($order, 'tax_total', 0) + Arr::get($order, 'shipping_total', 0) - Arr::get($order, 'manual_discount_total', 0) - $couponDiscountTotal);

        $latestOrder = static::getQuery()->latest()->first();
        $latestOrderId = Arr::get($latestOrder, 'id', 0);

        $fulfillmentType = $hasPhysicalProduct ? 'physical' : 'digital';
        $storeSettings = new StoreSettings();

        $shipping_total = Arr::get($order, 'shipping_total', 0);
        $userTz = Arr::get($order, 'user_tz');
        $config = [];

        if (!empty($userTz)) {
            $config['user_tz'] = $userTz;
        }
        $orderData = [
            'subtotal'              => $subtotal,
            'total_amount'          => $totalAmount,
            'payment_status'        => $totalAmount == 0 ? Status::PAYMENT_PAID : Status::PAYMENT_PENDING,
            'status'                => Status::ORDER_ON_HOLD,
            'currency'              => Helper::shopConfig('currency'),
            'mode'                  => Helper::shopConfig('order_mode'),
            'receipt_number'        => ($latestOrderId + 1),
            'invoice_no'            => $storeSettings->getInvoicePrefix() . ($latestOrderId + 1) . $storeSettings->getInvoiceSuffix(),
            'ip_address'            => AddressHelper::getIpAddress(),
            'fulfillment_type'      => $fulfillmentType,
            'manual_discount_total' => Arr::get($order, 'manual_discount_total', 0),
            'coupon_discount_total' => $couponDiscountTotal,
            'shipping_total'        => $shipping_total,
            'config'                => $config
        ];

        $isPlanChange = Arr::get($params, 'is_plan_change', 'no');
        $discountApplied = Arr::get($params, 'discount_applied', 'no');
        if ('yes' == $isPlanChange && 'yes' == $discountApplied) {
            $orderData['subtotal'] = $subtotal + Arr::get($params, 'discount_amount', 0);
            $orderData['manual_discount_total'] = Arr::get($params, 'discount_amount', 0);
        }
        $orderData += $order;

        $orderData['created_at'] = DateTime::gmtNow();
        $orderData['updated_at'] = DateTime::gmtNow();

        try {
            $res = static::getQuery()->create($orderData);;
            if (!$res || !$res->id) {
                throw new \Exception(__('Order creation failed.', 'fluent-cart'));
            }
            return $res;
        } catch (\Exception $e) {
            return static::makeErrorResponse([
                ['code' => 400, 'message' => $e->getMessage()]
            ]);
        }
    }

    /**
     * @throws \Exception
     */
    /**
     * Validate a shipping cents value for the DIRECT Resource API boundary.
     * REST callers can reach neither branch (OrderRequest's numeric/min:0 rules
     * 422 them first); both exist purely for direct callers.
     *
     * - Only absent/null may default to zero — that is the omitted-key shape
     *   REST produces (pickKeys null-fill). A present non-numeric is a caller
     *   bug, and coercing it to 0 would silently grant free shipping.
     * - The sign is checked on the RAW value, BEFORE rounding: roundCent(-0.4)
     *   is 0, so a post-rounding check would wave fractional negatives through
     *   as free shipping instead of rejecting them.
     *
     * @param mixed $value
     * @return mixed the value, unchanged, when null or a non-negative numeric
     */
    protected static function assertShippingCents($value)
    {
        if ($value === null) {
            return null;
        }

        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(
                'Shipping total must be a numeric cents amount or omitted, got: ' . gettype($value)
            );
        }

        if ((float) $value < 0) {
            throw new \InvalidArgumentException(
                'Shipping total cannot be a negative cents amount: ' . var_export($value, true)
            );
        }

        return $value;
    }

    public static function updatedPlaceOrder($data, $params = [])
    {
        $order = $data;
        $discount = Arr::get($data, 'discount');
        $shipping = Arr::get($data, 'shipping');
        $newLabelIds = Arr::get($data, 'labels');
        $paymentMethod = sanitize_text_field('offline_payment');

        $items = Arr::except(Arr::get($order, 'order_items'), ['*']);
        OrderService::validateProducts($items);

        $customer = static::getCustomer($data);

        if (Arr::get($discount, 'value', 0) > 0) {
            static::distributeManualDiscount($items, Helper::toCent(Arr::get($discount, 'value', 0)));
        }

        // admin order processor
        $adminOrderProcessor = new AdminOrderProcessor($items, [
            'customer_id'               => $customer->id,
            'payment_method'            => $paymentMethod,
            'applied_coupons'           => Arr::get($data, 'applied_coupon', []),
            // Normalized here as well as in OrderRequest::sanitize(): this is a public
            // Resource API, and a direct caller never passes through the request layer. The
            // shared helper also absorbs the null that pickKeys() injects for an omitted key
            // AFTER Sanitizer::sanitize() has run, which no sanitizer can reach. Negative
            // and PRESENT-but-malformed shipping are rejected here too, on the RAW value
            // and BEFORE rounding — see assertShippingCents().
            'shipping_total'            => Helper::roundCent(static::assertShippingCents(Arr::get($data, 'shipping_total'))),
            'billing_address'           => Arr::get($customer, 'billing_address', []),
            'shipping_address'          => Arr::get($customer, 'shipping_address', []),
            'user_tz'                   => Arr::get($data, 'user_tz', ''),
        ]);

        $order = $adminOrderProcessor->createDraftOrder();

        $data = Arr::except($data, ['order_items', 'customer', 'discount', 'shipping']);

        try {
            if ($paymentMethod) {
                static::addOrderMeta($order->id, $discount, $shipping, $newLabelIds);

                static::commitEvents($order);

                static::createOrderAddresses($order->id, $data, $order->customer_id);

                static::triggerStockChangedEvents($order);

                // Calculate and persist tax for admin-created orders
                static::applyAdminOrderTax($order, $items, $customer, $data);

                if ($gateway = App::gateway($paymentMethod)) {
                    $paymentInstance = new PaymentInstance($order);

                    if ($paymentInstance->subscription && $paymentInstance->subscription->status === Status::SUBSCRIPTION_PENDING) {
                        $paymentInstance->subscription->status = Status::SUBSCRIPTION_INTENDED;
                        $paymentInstance->subscription->save();
                    }

                    $gateway->makePaymentFromPaymentInstance($paymentInstance);
                }

                return $order;
            } else {
                return static::makeErrorResponse([
                    ['code' => 423, 'message' => __('Please select a payment method first!', 'fluent-cart')]
                ]);
            }
        } catch (\Exception $e) {
            return static::makeErrorResponse([
                ['code' => 400, 'message' => $e->getMessage()]
            ]);
        }
    }

    /**
     * Calculate tax for an admin-created order and persist it to fct_order_tax_rate.
     * Updates order.tax_total and order.shipping_tax. Never throws — tax failure must
     * not block order creation.
     *
     * @param \FluentCart\App\Models\Order    $order    The freshly created order.
     * @param array                           $items    Raw order_items from the create-order request.
     * @param \FluentCart\App\Models\Customer $customer Customer with primary_billing_address loaded.
     * @param array                           $data     Raw request data (may include billing_address_id).
     */
    private static function applyAdminOrderTax($order, $items, $customer, $data = [])
    {
        try {
            // Resolve billing address: prefer the address explicitly selected in the
            // admin UI (billing_address_id), fall back to customer's primary address.
            $billingAddress = null;
            $billingAddressId = (int) Arr::get($data, 'billing_address_id', 0);
            if ($billingAddressId > 0) {
                $addr = CustomerAddresses::query()
                    ->where('customer_id', $order->customer_id)
                    ->find($billingAddressId);
                if ($addr) {
                    $billingAddress = [
                        'country'  => $addr->country ?: '',
                        'state'    => $addr->state ?: '',
                        'city'     => $addr->city ?: '',
                        'postcode' => $addr->postcode ?: '',
                    ];
                }
            }
            $billingFallbackAddress = null;
            if (!$billingAddress && $customer && $customer->primary_billing_address) {
                $addr = $customer->primary_billing_address;
                $billingFallbackAddress = $addr;
                $billingAddress = [
                    'country'  => $addr->country ?: '',
                    'state'    => $addr->state ?: '',
                    'city'     => $addr->city ?: '',
                    'postcode' => $addr->postcode ?: '',
                ];
            }

            // Resolve shipping address for basis=shipping
            $shippingAddress = null;
            $shippingAddressId = (int) Arr::get($data, 'shipping_address_id', 0);
            if ($shippingAddressId > 0) {
                $addr = CustomerAddresses::query()
                    ->where('customer_id', $order->customer_id)
                    ->find($shippingAddressId);
                if ($addr) {
                    $shippingAddress = [
                        'country'  => $addr->country ?: '',
                        'state'    => $addr->state ?: '',
                        'city'     => $addr->city ?: '',
                        'postcode' => $addr->postcode ?: '',
                    ];
                }
            }
            $shippingFallbackAddress = null;
            if (!$shippingAddress && $customer && $customer->primary_shipping_address) {
                $addr = $customer->primary_shipping_address;
                $shippingFallbackAddress = $addr;
                $shippingAddress = [
                    'country'  => $addr->country ?: '',
                    'state'    => $addr->state ?: '',
                    'city'     => $addr->city ?: '',
                    'postcode' => $addr->postcode ?: '',
                ];
            }

            $taxSettings = (new TaxModule())->getSettings();
            $basis       = Arr::get($taxSettings, 'tax_calculation_basis', 'shipping');
            $taxAddress  = AdminOrderTaxService::resolveAddressForBasis($basis, $billingAddress, $shippingAddress);

            if (empty($taxAddress['country'])) {
                // No address — can't calculate tax. Still write the zero-tax
                // sentinel row so every order records "tax ran, no address"
                // (same guarantee checkout gives via persistTaxRates).
                TaxModule::persistTaxRates($order->id, [], [
                    'tax_country' => '',
                    'source'      => 'admin_order',
                    'note'        => 'no_tax_address',
                ], 0);
                return;
            }

            // Build line items from raw order_items
            $taxItems = [];
            foreach ($items as $item) {
                $unitPrice = (int) Arr::get($item, 'unit_price', 0);
                $qty       = max(1, (int) Arr::get($item, 'quantity', 1));
                $subtotal  = $unitPrice * $qty;

                // Include manual_discount (set by distributeManualDiscount) so tax is
                // calculated on the after-discount amount, not the full subtotal.
                $taxItems[] = [
                    'id'             => (int) Arr::get($item, 'id', 0),
                    'post_id'        => (int) Arr::get($item, 'post_id', 0),
                    'object_id'      => (int) Arr::get($item, 'object_id', 0),
                    'subtotal'       => $subtotal,
                    'discount_total' => (int) Arr::get($item, 'discount_total', 0) + (int) Arr::get($item, 'manual_discount', 0),
                    'shipping_charge'=> (int) Arr::get($item, 'shipping_charge', 0),
                    'quantity'       => $qty,
                    'other_info'     => Arr::get($item, 'other_info', []),
                ];
            }

            $taxResult = AdminOrderTaxService::calculate($taxItems, $taxAddress, $taxSettings);

            if ($taxResult === null) {
                return; // Tax disabled or no result
            }

            $taxTotal           = (int) Arr::get($taxResult, 'tax_total', 0);
            $exclusiveTaxTotal   = (int) Arr::get($taxResult, 'exclusive_tax_total', 0);
            $storeTaxBehavior    = (int) Arr::get($taxResult, 'store_tax_behavior', 0);
            $feeTax              = (int) Arr::get($taxResult, 'fee_tax', 0);
            $shippingTax         = (int) Arr::get($taxResult, 'shipping_tax', 0);
            $shippingTaxLines    = Arr::get($taxResult, 'shipping_tax_lines', []);
            $taxLines            = Arr::get($taxResult, 'tax_lines', []);
            $taxCountry          = Arr::get($taxResult, 'tax_country', $taxAddress['country']);

            // Always persist tax fields for reporting, even when amounts are zero
            $taxBehavior = (int) Arr::get($taxResult, 'tax_behavior', 0);
            $order->tax_behavior = $taxBehavior;
            $order->tax_total    = $taxTotal;
            $order->shipping_tax = $shippingTax;

            // Calculate total_amount based on tax behavior
            if ($taxBehavior === 1) {
                // Pure exclusive: all tax (product + fee) is on top of subtotals.
                $order->total_amount = $order->total_amount + $taxTotal + $shippingTax;
            } elseif ($taxBehavior === 3) {
                // Mixed: only exclusive product tax + store-exclusive fee/shipping on top.
                $order->total_amount = $order->total_amount + $exclusiveTaxTotal;
                if ($storeTaxBehavior === 1) {
                    $order->total_amount = $order->total_amount + $feeTax + $shippingTax;
                }
            }
            // behavior=2 (inclusive) or 0 (reverse charge): tax already in item prices

            $DB = App::db();
            $DB->beginTransaction();

            $order->save();

            // When tax was calculated from the customer's primary address (no address
            // explicitly attached to the order), persist that address onto the order —
            // the edit path reads fct_order_addresses, and without this row the next
            // save would hit the no-country branch and clear the tax charged here.
            if ($billingFallbackAddress) {
                static::createOrderAddress($billingFallbackAddress->toArray(), $order->id);
            }
            if ($shippingFallbackAddress) {
                static::createOrderAddress($shippingFallbackAddress->toArray(), $order->id);
            }

            // Always persist these meta keys so a later recalculation that returns
            // zero values does not leave stale non-zero data from a prior edit.
            $order->updateMeta('exclusive_tax_total', $exclusiveTaxTotal);
            $order->updateMeta('store_tax_behavior', $storeTaxBehavior);
            $order->updateMeta('fee_tax', $feeTax);

            // Patch per-item tax_amount and line_meta so tax badges display correctly.
            $lineItemsFromTax = Arr::get($taxResult, 'line_items', []);
            if (!empty($lineItemsFromTax)) {
                $savedItems = OrderItem::query()
                    ->where('order_id', $order->id)
                    ->whereNotIn('payment_type', ['fee', 'signup_fee'])
                    ->get()
                    ->toArray();
                static::patchOrderItemTaxMeta($savedItems, $lineItemsFromTax);
                static::patchSignupFeeTaxMeta($order->id, $lineItemsFromTax);
                static::patchSubscriptionTax($order, $lineItemsFromTax, $taxBehavior);
            }

            // Persist tax-rate rows
            $taxMeta = [
                'tax_country'        => $taxCountry,
                'tax_behavior'       => $taxBehavior,
                'inclusive'          => $taxBehavior === 2,
                'shipping_inclusive' => $storeTaxBehavior === 2,
                'source'             => 'admin_order',
            ];

            TaxModule::persistTaxRates($order->id, $taxLines, $taxMeta, $shippingTax, $shippingTaxLines);

            // Sync the pending charge transaction total so it matches the tax-adjusted order total.
            $pendingTx = OrderTransaction::query()
                ->where('order_id', $order->id)
                ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
                ->where('status', 'pending')
                ->first();
            if ($pendingTx) {
                $pendingTx->total = $order->total_amount;
                $pendingTx->save();
            }

            $DB->commit();

        } catch (\Exception $e) {
            if (isset($DB)) {
                $DB->rollBack();
            }
            // Log but never block order creation — tax calculation is non-critical
            fluent_cart_warning_log(
                'Admin order tax calculation failed',
                get_class($e) . ': ' . wp_strip_all_tags($e->getMessage()),
                ['module_name' => 'tax', 'module_id' => $order->id, 'log_type' => 'api']
            );
        }
    }

    /**
     * Rebuild an order's item-derived totals from the rows actually in
     * fct_order_items, then let the tax pass derive total_amount from the new
     * subtotal.
     *
     * The whole-order save posts client-computed totals alongside the items, so
     * it does not need this. A caller that writes a single line item on its own
     * does — without it the order keeps the subtotal it had before the line
     * existed. Same aggregation as AdminOrderProcessor: fee lines live in
     * fee_total, and trial lines are not billed now.
     */
    public static function syncItemDerivedTotals(Order $order)
    {
        $order->load('order_items');

        // The tax pass early-returns for these before reaching the pending
        // charge transaction sync, so a total written here would go stale
        // against the recorded charge. Refuse instead of desynchronizing.
        if ($order->isSubscription() || $order->type === 'refund') {
            throw new \Exception(esc_html__('Order Not valid!', 'fluent-cart'));
        }

        $subtotal = 0;

        foreach ($order->order_items as $item) {
            if (in_array($item->payment_type, ['fee', 'signup_fee'], true)) {
                continue;
            }

            if (Arr::get($item->other_info, 'trial_days', 0) > 0) {
                continue;
            }

            $subtotal += (int) $item->subtotal;
        }

        $order->subtotal = $subtotal;

        // The parent's fulfillment fields are item-derived too — creation sets
        // them from whether any line is physical (AdminOrderProcessor). A
        // physical line added to a digital order must pull the order into the
        // shipping workflow. Upgrade only: a rebuild must never downgrade the
        // type or reset shipping progress already recorded.
        $hasPhysical = $order->order_items
            ->where('fulfillment_type', Status::FULFILLMENT_TYPE_PHYSICAL)
            ->isNotEmpty();

        if ($hasPhysical) {
            if ($order->fulfillment_type !== Status::FULFILLMENT_TYPE_PHYSICAL) {
                $order->fulfillment_type = Status::FULFILLMENT_TYPE_PHYSICAL;
            }
            if (!$order->shipping_status) {
                $order->shipping_status = 'unshipped';
            }
        }

        // Tax-free baseline; the tax pass recomputes it with tax on every path
        // it completes.
        $order->total_amount = max(0, $subtotal
            + (int) $order->shipping_total
            + (int) $order->fee_total
            - (int) $order->coupon_discount_total
            - (int) $order->manual_discount_total);

        $order->save();

        // The tax pass swallows its own failures so a whole-order save is never
        // blocked, but this caller has nothing else persisting the order — a
        // swallowed failure here would commit the new subtotal beside stale tax
        // fields and rate rows. Escalate so the caller's transaction rolls the
        // item and totals back together.
        if (!static::reapplyTaxAfterUpdate($order->id, $order->refresh())) {
            throw new \Exception(esc_html__('Order totals could not be recalculated. Please try again.', 'fluent-cart'));
        }

        return $order->refresh();
    }

    /**
     * Recalculate and persist tax for an existing order after create or update.
     * Reads saved items + billing address from the DB, runs AdminOrderTaxService,
     * recomputes total_amount from scratch, and rewrites fct_order_tax_rate rows.
     * Never throws — tax failure must not block the save.
     *
     * @return bool false when the order was left carrying tax data the current
     *              items no longer justify (transient calculator failure or a
     *              rolled-back write); true when it reached a coherent state.
     */
    private static function reapplyTaxAfterUpdate($orderId, $order)
    {
        try {
            if (!$order->relationLoaded('order_items')) {
                $order->load('order_items');
            }

            if ($order->isSubscription()) {
                return true;
            }

            if ($order->type === 'refund') {
                return true;
            }

            // Query addresses directly — ORM relation load() does not reliably apply
            // the type WHERE constraint, so we query fct_order_addresses ourselves.
            $billingAddr  = OrderAddress::query()->where('order_id', $orderId)->where('type', 'billing')->first();
            $shippingAddr = OrderAddress::query()->where('order_id', $orderId)->where('type', 'shipping')->first();

            $billingAddress  = null;
            $shippingAddress = null;

            if ($billingAddr) {
                $billingAddress = [
                    'country'  => $billingAddr->country ?: '',
                    'state'    => $billingAddr->state ?: '',
                    'city'     => $billingAddr->city ?: '',
                    'postcode' => $billingAddr->postcode ?: '',
                ];
            }
            if ($shippingAddr) {
                $shippingAddress = [
                    'country'  => $shippingAddr->country ?: '',
                    'state'    => $shippingAddr->state ?: '',
                    'city'     => $shippingAddr->city ?: '',
                    'postcode' => $shippingAddr->postcode ?: '',
                ];
            }

            $taxSettings = (new TaxModule())->getSettings();
            $basis       = Arr::get($taxSettings, 'tax_calculation_basis', 'shipping');
            $taxAddress  = AdminOrderTaxService::resolveAddressForBasis($basis, $billingAddress, $shippingAddress);

            if (empty($taxAddress['country'])) {
                return static::clearOrderTax($orderId, $order);
            }

            $productItems = $order->order_items->filter(function ($item) {
                return !in_array($item->payment_type, ['fee', 'signup_fee'], true);
            })->values();

            $taxItems = [];
            foreach ($productItems as $item) {
                $unitPrice = (int) Arr::get($item, 'unit_price', 0);
                $qty       = max(1, (int) Arr::get($item, 'quantity', 1));
                $taxItems[] = [
                    'id'              => (int) Arr::get($item, 'id', 0),
                    'post_id'         => (int) Arr::get($item, 'post_id', 0),
                    'object_id'       => (int) Arr::get($item, 'object_id', 0),
                    'subtotal'        => $unitPrice * $qty,
                    'discount_total'  => (int) Arr::get($item, 'discount_total', 0),
                    'shipping_charge' => (int) Arr::get($item, 'shipping_charge', 0),
                    'quantity'        => $qty,
                    'other_info'      => Arr::get($item, 'other_info', []),
                ];
            }

            if (empty($taxItems)) {
                return static::clearOrderTax($orderId, $order);
            }

            // Fee items only exist on checkout-created orders that are edited in
            // admin. Mirror checkout (TaxModule::calculateCartTax()): only taxable,
            // non-zero fees enter the calculator as is_fee lines. Fee item subtotal
            // holds the NET fee amount (CheckoutProcessor::syncFeeItems() stores it
            // tax-free), so it doubles as the net fee base for the total recompute.
            // Guard: when the order has NO fee order items, the stored fee_total
            // column is the only source (legacy / manually set) — keep it as-is and
            // skip fee tax entirely.
            $feeOrderItems = $order->order_items->filter(function ($item) {
                return $item->payment_type === 'fee';
            })->values();

            $hasFeeItems = !$feeOrderItems->isEmpty();
            $netFeeTotal = 0;
            foreach ($feeOrderItems as $feeItem) {
                $feeSubtotal = (int) Arr::get($feeItem, 'subtotal', 0);
                $netFeeTotal += $feeSubtotal;

                $feeOtherInfo = Arr::get($feeItem, 'other_info', []);
                if (!is_array($feeOtherInfo)) {
                    $feeOtherInfo = [];
                }
                if (empty($feeOtherInfo['taxable']) || $feeSubtotal <= 0) {
                    continue;
                }

                $taxItems[] = [
                    'is_fee'          => true,
                    'title'           => (string) Arr::get($feeItem, 'title', ''),
                    'post_id'         => 0,
                    'object_id'       => 0,
                    'subtotal'        => $feeSubtotal,
                    'discount_total'  => 0,
                    'shipping_charge' => 0,
                    'quantity'        => 1,
                    'other_info'      => $feeOtherInfo,
                ];
            }

            $taxResult = AdminOrderTaxService::calculate($taxItems, $taxAddress, $taxSettings);

            if ($taxResult === null) {
                if (!TaxModule::isTaxEnabled()) {
                    // Deterministic: tax was turned off — clear stale tax instead of leaving it.
                    return static::clearOrderTax($orderId, $order);
                }
                // Transient calculation failure: keep existing tax untouched.
                return false;
            }

            $taxTotal          = (int) Arr::get($taxResult, 'tax_total', 0);
            $exclusiveTaxTotal = (int) Arr::get($taxResult, 'exclusive_tax_total', 0);
            $storeTaxBehavior  = (int) Arr::get($taxResult, 'store_tax_behavior', 0);
            $feeTax            = (int) Arr::get($taxResult, 'fee_tax', 0);
            $feeTaxLines       = (array) Arr::get($taxResult, 'fee_tax_lines', []);
            $shippingTax       = (int) Arr::get($taxResult, 'shipping_tax', 0);
            $shippingTaxLines  = Arr::get($taxResult, 'shipping_tax_lines', []);
            $taxLines          = Arr::get($taxResult, 'tax_lines', []);
            $taxCountry        = Arr::get($taxResult, 'tax_country', $taxAddress['country']);
            $taxBehavior       = (int) Arr::get($taxResult, 'tax_behavior', 0);
            $lineItemsFromTax  = Arr::get($taxResult, 'line_items', []);

            // Respect a checkout-time VIES validation: when the order carries a
            // validated VAT number and reverse charge still applies for the
            // (possibly edited) address, zero the recalculated tax and keep the
            // RC audit meta instead of re-adding tax the buyer does not owe.
            $rcMeta    = [];
            $rcContext = static::resolveAdminReverseChargeContext($order, $taxAddress);
            if ($rcContext !== null) {
                $rcMode           = $order->getOrderRcMode();
                // tax_total includes fee tax; the inclusive portion must not
                // (same formula as checkout: taxTotal - exclusiveTaxTotal - feeTax).
                $inclusivePortion = max(0, $taxTotal - $exclusiveTaxTotal - $feeTax);

                $rcMeta = [
                    'reverse_charge_applied'               => true,
                    'vat_reverse'                          => $rcContext,
                    'reverse_charge_original_tax_total'    => $exclusiveTaxTotal + $feeTax + $shippingTax + ($rcMode === 'dynamic' ? $inclusivePortion : 0),
                    'reverse_charge_original_shipping_tax' => $shippingTax,
                    'reverse_charge_price_mode'            => $rcMode,
                ];

                // Zero RC-style — rate rows keep their identity with zero amounts,
                // line items keep their tax_config rates (strikethrough display)
                // while top-level tax_amount is zeroed. Same convention as checkout.
                foreach ($taxLines as $lineIndex => $taxLine) {
                    $taxLines[$lineIndex]['tax_amount'] = 0;
                }
                foreach ($lineItemsFromTax as $itemIndex => $taxLineItem) {
                    $lineItemsFromTax[$itemIndex]['tax_amount']     = 0;
                    $lineItemsFromTax[$itemIndex]['signup_fee_tax'] = 0;
                }
                $taxTotal          = 0;
                $exclusiveTaxTotal = 0;
                $shippingTax       = 0;
                $shippingTaxLines  = [];
                $taxBehavior       = 0;
                $feeTax            = 0;
                $feeTaxLines       = [];
            }

            // Fee base for the total recompute. The stored fee_total column on a
            // behavior-1 checkout order already contains the ORIGINAL fee tax
            // (CheckoutProcessor rolled it in) — trusting it would double-count
            // fee tax against the freshly calculated one. When fee order items
            // exist, their subtotals are the net fee amounts; rebuild fee_total
            // from net + new fee tax (checkout invariant: gateways read fee_total
            // as the gross fee). Without fee items, keep the stored column as-is.
            $feeBaseTotal = (int) $order->fee_total;
            if ($hasFeeItems) {
                $feeBaseTotal = $netFeeTotal;
                $newFeeTotal  = $netFeeTotal;
                if ($feeTax && ($taxBehavior === 1 || ($taxBehavior === 3 && $storeTaxBehavior === 1))) {
                    $newFeeTotal += $feeTax;
                }
                $order->fee_total = $newFeeTotal;
            }

            // Recompute total_amount from first principles so old tax is never double-counted.
            // fee base must be included — checkout orders carry payment/processing fees
            // outside subtotal (see CheckoutProcessor::prepareOrderData()).
            $baseTotal = (int)$order->subtotal
                       + (int)$order->shipping_total
                       + $feeBaseTotal
                       - (int)$order->coupon_discount_total
                       - (int)$order->manual_discount_total;

            $order->tax_behavior = $taxBehavior;
            $order->tax_total    = $taxTotal;
            $order->shipping_tax = $shippingTax;
            $order->total_amount = $baseTotal;

            if ($taxBehavior === 1) {
                // taxTotal already includes feeTax → net fee + fee tax counted exactly once.
                $order->total_amount += $taxTotal + $shippingTax;
            } elseif ($taxBehavior === 3) {
                // exclusiveTaxTotal excludes fee lines → add feeTax explicitly for exclusive stores.
                $order->total_amount += $exclusiveTaxTotal;
                if ($storeTaxBehavior === 1) {
                    $order->total_amount += $feeTax + $shippingTax;
                }
            }

            $DB = App::db();
            $DB->beginTransaction();

            $order->save();

            // Always persist these meta keys so a later recalculation that returns
            // zero values does not leave stale non-zero data from a prior edit.
            $order->updateMeta('exclusive_tax_total', $exclusiveTaxTotal);
            $order->updateMeta('store_tax_behavior', $storeTaxBehavior);
            $order->updateMeta('fee_tax', $feeTax);

            // Same persist/delete pattern as CheckoutProcessor::persistTaxMeta() —
            // a stale checkout-written fee_tax_lines must not survive an admin edit
            // that produced no fee tax.
            if (!empty($feeTaxLines)) {
                $order->updateMeta('fee_tax_lines', $feeTaxLines);
            } else {
                $order->deleteMeta('fee_tax_lines');
            }

            // Patch per-item tax_amount and line_meta so tax badges display correctly.
            // patchSignupFeeTaxMeta() is always called (even when no items have signup-fee tax)
            // so it can zero out items that were previously taxed but are now exempt.
            static::patchOrderItemTaxMeta($productItems->toArray(), $lineItemsFromTax);
            static::patchSignupFeeTaxMeta($orderId, $lineItemsFromTax);

            $taxMeta = array_merge([
                'tax_country'        => $taxCountry,
                'tax_behavior'       => $taxBehavior,
                'inclusive'          => $taxBehavior === 2,
                'shipping_inclusive' => $storeTaxBehavior === 2,
                'source'             => 'admin_order_edit',
            ], $rcMeta);

            OrderTaxRate::query()->where('order_id', $orderId)->delete();
            TaxModule::persistTaxRates($orderId, $taxLines, $taxMeta, $shippingTax, $shippingTaxLines);

            $pendingTx = OrderTransaction::query()
                ->where('order_id', $orderId)
                ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
                ->where('status', 'pending')
                ->first();
            if ($pendingTx) {
                $pendingTx->total = $order->total_amount;
                $pendingTx->save();
            }

            // Paid orders: settled transactions are never touched — reflect the new
            // total as a due / refund-owed state instead.
            static::syncPaymentStatusWithTotals($order);

            $DB->commit();

            return true;
        } catch (\Exception $e) {
            if (isset($DB)) {
                $DB->rollBack();
            }
            fluent_cart_warning_log(
                'Admin order tax recalculation failed on update',
                get_class($e) . ': ' . wp_strip_all_tags($e->getMessage()),
                ['module_name' => 'tax', 'module_id' => $orderId, 'log_type' => 'api']
            );

            return false;
        }
    }

    /**
     * Re-derive payment_status after a tax recalculation changed total_amount on
     * an order that already received money. A fully-paid order whose total grew
     * becomes partially_paid (the admin UI then shows Total Due + Collect
     * Payments); a partially_paid order whose total shrank to within total_paid
     * becomes paid. Overpayment keeps status paid — the Total Refund Owed row is
     * derived from the columns directly. Intentionally event-free: no payment was
     * received, so OrderPaid side effects (emails) must not fire.
     */
    private static function syncPaymentStatusWithTotals($order)
    {
        $totalPaid = (int) $order->total_paid;
        if ($totalPaid <= 0) {
            return; // unpaid orders keep their pending/failed lifecycle
        }

        $totalAmount = (int) $order->total_amount;
        if ($totalPaid < $totalAmount && $order->payment_status === Status::PAYMENT_PAID) {
            $order->updatePaymentStatus(Status::PAYMENT_PARTIALLY_PAID);
        } elseif ($totalPaid >= $totalAmount && $order->payment_status === Status::PAYMENT_PARTIALLY_PAID) {
            $order->updatePaymentStatus(Status::PAYMENT_PAID);
        }
    }

    /**
     * Resolve whether a checkout-time VIES validation still grants reverse charge
     * for an admin order edit.
     *
     * Sources the validated VAT from order business_info (rate-row vat_reverse
     * meta as legacy fallback), then re-checks eligibility against the current
     * tax address: the VAT's member state must match the tax country and the
     * store settings must allow reverse charge for it. When the tax country
     * changed since the order was placed, the VAT is re-validated against VIES —
     * a definitive "invalid" drops reverse charge; an unreachable service trusts
     * the stored validation (fail open, matching checkout behavior).
     *
     * @return array|null vat_reverse payload to persist, or null when reverse
     *                    charge must not apply.
     */
    private static function resolveAdminReverseChargeContext($order, $taxAddress)
    {
        $businessInfo = $order->getBusinessInfo();
        $vatNumber    = (string) Arr::get($businessInfo, 'tax_number', '');
        $validated    = (bool) Arr::get($businessInfo, 'tax_number_validated', false);
        $vatCountry   = (string) Arr::get($businessInfo, 'tax_number_country', '');
        $vatName      = (string) Arr::get($businessInfo, 'tax_number_name', '');

        $primaryRate     = $order->getPrimaryOrderTaxRate();
        $primaryRateMeta = $primaryRate ? (array) $primaryRate->meta : [];

        if (!$validated || !$vatNumber) {
            // Legacy orders: VAT data only exists on the rate-row meta.
            $vatReverse = (array) Arr::get($primaryRateMeta, 'vat_reverse', []);
            if (Arr::get($vatReverse, 'valid', false) && Arr::get($vatReverse, 'vat_number', '')) {
                $vatNumber  = (string) Arr::get($vatReverse, 'vat_number', '');
                $vatCountry = (string) Arr::get($vatReverse, 'country', '');
                $vatName    = (string) Arr::get($vatReverse, 'name', '');
                $validated  = true;
            }
        }

        if (!$validated || !$vatNumber) {
            return null;
        }

        $taxCountry = strtoupper((string) Arr::get($taxAddress, 'country', ''));

        // The validated VAT belongs to one member state — reverse charge only
        // applies while the order is taxed in that country (same rule as checkout).
        if (!$taxCountry || strtoupper($vatCountry) !== $taxCountry) {
            return null;
        }

        $taxModule = new TaxModule();
        if (!$taxModule->canApplyVatValidation($taxCountry)) {
            return null;
        }

        // Excluded categories: refuse reverse charge when any order product belongs
        // to a category listed in eu_vat_settings.vat_reverse_excluded_categories.
        // Checkout applies this only under local_reverse_charge = yes
        // (TaxModule::shouldApplyReverseCharge() / handleVatValidation()) — same gate
        // here for exact parity.
        $taxSettings        = $taxModule->getSettings();
        $excludedCategories = array_map('intval', (array) Arr::get(
            $taxSettings, 'eu_vat_settings.vat_reverse_excluded_categories', []
        ));
        if (Arr::get($taxSettings, 'eu_vat_settings.local_reverse_charge', 'no') === 'yes' && !empty($excludedCategories)) {
            if (!$order->relationLoaded('order_items')) {
                $order->load('order_items');
            }

            $productIds = [];
            foreach ($order->order_items as $orderItem) {
                if (!in_array($orderItem->payment_type, ['fee', 'signup_fee'], true) && $orderItem->post_id) {
                    $productIds[] = (int) $orderItem->post_id;
                }
            }
            $productIds = array_values(array_unique($productIds));

            if (!empty($productIds)) {
                // TaxModule::getTermsByProductIds() is protected — replicate its
                // term_relationships lookup (object_id → term_taxonomy_id).
                $termRows = App::db()->table('term_relationships')
                    ->whereIn('object_id', $productIds)
                    ->get();
                foreach ($termRows as $termRow) {
                    if (in_array((int) $termRow->term_taxonomy_id, $excludedCategories, true)) {
                        return null;
                    }
                }
            }
        }

        // Tax country changed since placement → re-validate the VAT against VIES.
        $previousTaxCountry = strtoupper((string) Arr::get($primaryRateMeta, 'tax_country', ''));
        if ($previousTaxCountry && $previousTaxCountry !== $taxCountry) {
            $revalidation = $taxModule->validateVatForAdmin($vatCountry, $vatNumber);
            if (is_array($revalidation)) {
                if (empty($revalidation['valid'])) {
                    return null;
                }
                $vatName = (string) Arr::get($revalidation, 'name', $vatName);
            } elseif (is_wp_error($revalidation) && $revalidation->get_error_code() === 'invalid') {
                // Definitive VIES answer: the number is no longer registered.
                return null;
            }
            // service_unavailable / soap_fault → VIES unreachable: keep stored validation.
        }

        return [
            'vat_number' => $vatNumber,
            'country'    => $vatCountry,
            'valid'      => true,
            'name'       => $vatName,
        ];
    }

    /**
     * Zero out all tax fields, rate rows, and per-item tax amounts for an order
     * that has become definitively non-taxable (no address, no taxable items).
     * Only called for deterministic states — not on transient calculation failures.
     *
     * @return bool false when the clear rolled back and the stale tax data remains.
     */
    private static function clearOrderTax($orderId, $order)
    {
        try {
            // No tax ⇒ no fee tax. When fee order items exist their subtotals are
            // the net fee amounts — reset fee_total to net so a behavior-1 order
            // whose fee_total had checkout fee tax rolled in doesn't keep it.
            // Orders without fee items keep the stored fee_total untouched.
            $feeSubtotals = OrderItem::query()
                ->where('order_id', $orderId)
                ->where('payment_type', 'fee')
                ->pluck('subtotal')
                ->toArray();
            if (!empty($feeSubtotals)) {
                $order->fee_total = (int) array_sum(array_map('intval', $feeSubtotals));
            }

            $baseTotal = (int)$order->subtotal
                       + (int)$order->shipping_total
                       + (int)$order->fee_total
                       - (int)$order->coupon_discount_total
                       - (int)$order->manual_discount_total;

            $order->tax_behavior = 0;
            $order->tax_total    = 0;
            $order->shipping_tax = 0;
            $order->total_amount = $baseTotal;

            $DB = App::db();
            $DB->beginTransaction();

            $order->save();
            $order->updateMeta('exclusive_tax_total', 0);
            $order->updateMeta('store_tax_behavior', 0);
            $order->updateMeta('fee_tax', 0);
            $order->deleteMeta('fee_tax_lines');

            $productItemIds = OrderItem::query()
                ->where('order_id', $orderId)
                ->whereNotIn('payment_type', ['fee'])
                ->pluck('id')
                ->toArray();
            if (!empty($productItemIds)) {
                OrderItem::query()->whereIn('id', $productItemIds)->update(['tax_amount' => 0]);
            }

            // Strip stale tax_config from signup_fee line_meta so rate pills don't
            // show a previous rate when tax is now zero.
            $signupFeeItems = OrderItem::query()
                ->where('order_id', $orderId)
                ->where('payment_type', 'signup_fee')
                ->get();
            if (!$signupFeeItems->isEmpty()) {
                $signupFeeUpdates = [];
                foreach ($signupFeeItems as $signupFeeItem) {
                    $meta = $signupFeeItem->line_meta ?: [];
                    if (!is_array($meta)) {
                        $meta = json_decode($meta ?: '{}', true, 16) ?: [];
                    }
                    unset($meta['tax_config']);
                    $signupFeeUpdates[] = [
                        'id'        => $signupFeeItem->id,
                        'line_meta' => json_encode($meta),
                    ];
                }
                OrderItem::query()->batchUpdate($signupFeeUpdates);
            }

            // persistTaxRates with empty lines deletes all non-sentinel rate rows and
            // upserts the zero-tax sentinel (tax_rate_id=0) — same guarantee checkout
            // gives that every order keeps at least one fct_order_tax_rate row.
            TaxModule::persistTaxRates($orderId, [], [
                'tax_country' => '',
                'source'      => 'admin_order_edit',
                'note'        => 'tax_cleared',
            ], 0);

            $pendingTx = OrderTransaction::query()
                ->where('order_id', $orderId)
                ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
                ->where('status', 'pending')
                ->first();
            if ($pendingTx) {
                $pendingTx->total = $order->total_amount;
                $pendingTx->save();
            }

            // Paid orders: reflect the lowered total as paid / refund-owed state.
            static::syncPaymentStatusWithTotals($order);

            $DB->commit();

            return true;
        } catch (\Exception $e) {
            if (isset($DB)) {
                $DB->rollBack();
            }
            fluent_cart_warning_log(
                'Admin order tax clear failed on update',
                get_class($e) . ': ' . wp_strip_all_tags($e->getMessage()),
                ['module_name' => 'tax', 'module_id' => $orderId, 'log_type' => 'api']
            );

            return false;
        }
    }

    private static function patchOrderItemTaxMeta(array $savedItems, array $lineItemsFromTax)
    {
        // Custom lines all carry post_id/object_id 0:0, so the composite key
        // cannot tell two of them apart — match by order-item id first and only
        // fall back to the key for tax results that did not carry one.
        $savedById  = [];
        $savedByKey = [];
        foreach ($savedItems as $item) {
            $savedById[(int) $item['id']] = $item;
            $key = $item['post_id'] . ':' . $item['object_id'];
            $savedByKey[$key] = $item;
        }

        $updateData = [];

        foreach ($lineItemsFromTax as $taxLineItem) {
            $itemId = (int) Arr::get($taxLineItem, 'id', 0);

            if ($itemId && isset($savedById[$itemId])) {
                $savedItem = $savedById[$itemId];
            } else {
                $key = Arr::get($taxLineItem, 'post_id', 0) . ':' . Arr::get($taxLineItem, 'object_id', 0);
                if (!isset($savedByKey[$key])) {
                    continue;
                }
                $savedItem = $savedByKey[$key];
            }

            $taxAmount    = (int) Arr::get($taxLineItem, 'tax_amount', 0);
            $taxLineMeta  = Arr::get($taxLineItem, 'line_meta', []);
            $existingMeta = isset($savedItem['line_meta']) ? $savedItem['line_meta'] : [];
            if (!is_array($existingMeta)) {
                $existingMeta = json_decode($existingMeta ?: '{}', true, 16) ?: [];
            }
            if (!empty($taxLineMeta)) {
                $existingMeta = array_merge($existingMeta, $taxLineMeta);
            }
            $updateData[] = [
                'id'         => $savedItem['id'],
                'tax_amount' => $taxAmount,
                'line_meta'  => json_encode($existingMeta),
            ];
        }

        if (!empty($updateData)) {
            OrderItem::query()->batchUpdate($updateData);
        }
    }

    private static function patchSignupFeeTaxMeta($orderId, array $lineItemsFromTax)
    {
        // Build a map of post_id:object_id -> tax data for items that have signup fee tax.
        // Items absent from this map had their signup fee tax recalculated to zero.
        $taxByKey = [];
        foreach ($lineItemsFromTax as $taxLineItem) {
            $signupFeeTax = (int) Arr::get($taxLineItem, 'signup_fee_tax', 0);
            if (!$signupFeeTax) {
                continue;
            }
            $key = Arr::get($taxLineItem, 'post_id', 0) . ':' . Arr::get($taxLineItem, 'object_id', 0);
            $taxByKey[$key] = $taxLineItem;
        }

        // Always fetch ALL signup_fee items for this order — not only those with non-zero
        // tax — so items that became untaxed after recalculation get their tax_amount cleared.
        $signupFeeItems = OrderItem::query()
            ->where('order_id', $orderId)
            ->where('payment_type', 'signup_fee')
            ->get();

        if ($signupFeeItems->isEmpty()) {
            return;
        }

        $updateData = [];
        foreach ($signupFeeItems as $signupFeeItem) {
            $key         = $signupFeeItem->post_id . ':' . $signupFeeItem->object_id;
            $taxLineItem = isset($taxByKey[$key]) ? $taxByKey[$key] : null;

            $signupFeeTax = $taxLineItem ? (int) Arr::get($taxLineItem, 'signup_fee_tax', 0) : 0;
            $existingMeta = $signupFeeItem->line_meta ?: [];
            if (!is_array($existingMeta)) {
                $existingMeta = json_decode($existingMeta ?: '{}', true, 16) ?: [];
            }

            if ($taxLineItem) {
                $signupFeeTaxConfig = Arr::get($taxLineItem, 'signup_fee_tax_config', []);
                if ($signupFeeTaxConfig) {
                    $existingMeta['tax_config'] = $signupFeeTaxConfig;
                } else {
                    unset($existingMeta['tax_config']);
                }
            } else {
                unset($existingMeta['tax_config']);
            }

            $updateData[] = [
                'id'         => $signupFeeItem->id,
                'tax_amount' => $signupFeeTax,
                'line_meta'  => json_encode($existingMeta),
            ];
        }

        if (!empty($updateData)) {
            OrderItem::query()->batchUpdate($updateData);
        }
    }

    /**
     * Patch subscription tax fields after admin order tax calculation.
     *
     * AdminOrderProcessor creates the subscription row before tax runs, with
     * recurring_tax_total = 0 and recurring_total at the untaxed recurring price.
     * Renewals read recurring_tax_total (and the parent item's
     * other_info.recurring_tax for inclusive items) — without this patch every
     * renewal of an admin-created subscription invoices zero tax.
     *
     * Mirrors CheckoutProcessor::prepareSubscriptionData(): the recurring tax is
     * folded into recurring_total only when additive (exclusive store, or mixed
     * cart with this line exclusive).
     */
    private static function patchSubscriptionTax($order, array $lineItemsFromTax, $taxBehavior)
    {
        $subscription = Subscription::query()->where('parent_order_id', $order->id)->first();
        if (!$subscription) {
            return;
        }

        $subscriptionItem = OrderItem::query()
            ->where('order_id', $order->id)
            ->where('payment_type', 'subscription')
            ->first();
        if (!$subscriptionItem) {
            return;
        }

        $taxLine = null;
        foreach ($lineItemsFromTax as $lineItem) {
            if ((int) Arr::get($lineItem, 'post_id', 0) === (int) $subscriptionItem->post_id
                && (int) Arr::get($lineItem, 'object_id', 0) === (int) $subscriptionItem->object_id
            ) {
                $taxLine = $lineItem;
                break;
            }
        }
        if ($taxLine === null) {
            return;
        }

        $recurringTax = (int) Arr::get($taxLine, 'recurring_tax', 0);
        $signupFeeTax = (int) Arr::get($taxLine, 'signup_fee_tax', 0);

        // Renewals fall back to the parent item's other_info for inclusive items;
        // checkout writes both keys on the cart line, mirror that here.
        $otherInfo = $subscriptionItem->other_info ?: [];
        if (!is_array($otherInfo)) {
            $otherInfo = json_decode($otherInfo ?: '{}', true, 16) ?: [];
        }
        $otherInfo['recurring_tax'] = $recurringTax;
        if ($signupFeeTax) {
            $otherInfo['signup_fee_tax'] = $signupFeeTax;
        }
        $subscriptionItem->other_info = $otherInfo;
        $subscriptionItem->save();

        $lineInclusive = (bool) Arr::get($taxLine, 'line_meta.tax_config.inclusive', false);
        $isAdditive    = (int) $taxBehavior === 1 || ((int) $taxBehavior === 3 && !$lineInclusive);

        // Runs once, at order creation, while recurring_tax_total is still the 0 that
        // AdminOrderProcessor wrote. Guard against double-folding tax into
        // recurring_total if a future caller ever invokes this on a patched row.
        if ((int) $subscription->recurring_tax_total !== 0) {
            return;
        }

        $subscription->recurring_tax_total = $recurringTax;
        if ($isAdditive && $recurringTax > 0) {
            $subscription->recurring_total = (int) $subscription->recurring_total + $recurringTax;
        }
        $subscription->save();
    }

    private static function distributeManualDiscount(&$items, $manualDiscountTotal)
    {
        $totalSubtotal = array_reduce($items, function ($carry, $item) {
            return $carry + ((int)Arr::get($item, 'unit_price', 0) * (int)Arr::get($item, 'quantity', 1));
        }, 0);

        if ($totalSubtotal <= 0) {
            return;
        }

        $distributed = 0;
        foreach ($items as &$checkoutItem) {
            $unitPrice = (int)Arr::get($checkoutItem, 'unit_price', 0);
            $quantity = (int)Arr::get($checkoutItem, 'quantity', 1);
            $itemSubtotal = $unitPrice * $quantity;

            $itemManualDiscount = (int) (($itemSubtotal / $totalSubtotal) * $manualDiscountTotal);

            if ($itemManualDiscount > $itemSubtotal) {
                $itemManualDiscount = $itemSubtotal;
            }

            $distributed += $itemManualDiscount;

            Arr::set($checkoutItem, 'manual_discount', $itemManualDiscount);

        }

        $diff = round($manualDiscountTotal - $distributed, 2);
        // Adjust the first item to account for any precision differences
        if ($diff != 0) {
            $items[0]['manual_discount'] = (int) (Arr::get($items[0], 'manual_discount', 0) + $diff);
        }
    }


    private static function getCustomer($data)
    {
        $customer = CustomerResource::find(Arr::get($data, 'customer_id'), [
            'with' => ['primary_billing_address', 'primary_shipping_address']
        ]);
        return Arr::get($customer, 'customer');
    }

    private static function addOrderMeta($orderId, $discount, $shipping, $newLabelIds)
    {
        if (!empty($discount)) {
            static::addOrUpdateOrderMeta([
                'order_id'   => $orderId,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_key'   => 'order_discount',
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'meta_value' => $discount
            ]);
        }

        if (!empty($shipping)) {
            $shipping = is_array($shipping) ? static::resolveShippingTitle($shipping) : $shipping;
            static::addOrUpdateOrderMeta([
                'order_id'   => $orderId,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_key'   => 'order_shipping',
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'meta_value' => $shipping
            ]);
        }

        if (!empty($newLabelIds)) {
            LabelResource::addLabelToLabelRelationships(Order::find($orderId), [
                'labelable_id'   => $orderId,
                'labelable_type' => Order::class,
                'new_label_ids'  => $newLabelIds,
            ]);
        }
    }

    private static function commitEvents($order)
    {

        if (!$order) {
            throw new \Exception(esc_html__('Please process order first', 'fluent-cart'));
        }

        if (!$order->customer) {
            throw new \Exception(esc_html__('Please set customer first', 'fluent-cart'));
        }

        if (!$order->latest_transaction) {
            throw new \Exception(esc_html__('Please set Transaction First', 'fluent-cart'));
        }

        $paymentStatus = $order->payment_status;

        $transactionStatus = $order->latest_transaction->status;

        if (in_array($transactionStatus, Status::getTransactionSuccessStatuses())) {

            do_action('fluent_cart/payment_' . $paymentStatus,
                [
                    'order'       => $order,
                    'customer'    => $order->customer,
                    'transaction' => $order->latest_transaction
                ]);

            do_action('fluent_cart/payment_' . $order->latest_transaction->transaction_type . '_' . $paymentStatus, [
                'order'       => $order,
                'customer'    => $order->customer,
                'transaction' => $order->latest_transaction
            ]);
        }

    }

    private static function createOrderAddresses($orderId, $data, $customerId = 0)
    {
        $billingAddressId  = (int) Arr::get($data, 'billing_address_id', 0);
        $shippingAddressId = (int) Arr::get($data, 'shipping_address_id', 0);

        $billingAddress = $billingAddressId > 0
            ? CustomerAddresses::query()->where('customer_id', $customerId)->find($billingAddressId)
            : null;

        $shippingAddress = $shippingAddressId > 0
            ? CustomerAddresses::query()->where('customer_id', $customerId)->find($shippingAddressId)
            : null;

        if (!empty($billingAddress)) {
            static::createOrderAddress($billingAddress->toArray(), $orderId);
        }
        if (!empty($shippingAddress)) {
            static::createOrderAddress($shippingAddress->toArray(), $orderId);
        }
    }

    private static function triggerStockChangedEvents($order)
    {
        $productIds = OrderService::pluckProductIds($order);
        if (!empty($productIds)) {
//            (new StockChanged($productIds))->dispatch();
        }
    }

    /**
     * Update an order with the provided data.
     *
     * @param array $data Required. Array containing the necessary parameters for order update.
     *        $data = [
     *          'orderData'    => ( array ) Required. Represents the main order details.
     *            [
     *              'id'               => (int) The id for the order.
     *              'status'           => (string) The current status of the order
     *              'parent_id'        => (int) The parent order ID, if applicable.
     *              'receipt_number'     => (int) the unique sequential order number.
     *              'invoice_no'     => (string) The order number assigned to the order.
     *              'fulfillment_type' => (string)  (e.g., 'virtual', 'physical', etc.).
     *              'type'             => (string) Type (e.g., 'sale', 'refund', etc.).
     *              'customer_id'      => (int) The ID of the customer associated with the order.
     *              'payment_method'   => (string) The payment method used for the order.
     *              'payment_method_title'   => (string) The title of the payment method.
     *              'currency'         => (string) The currency used for the order (e.g., 'BDT').
     *              'subtotal'         => (float) The subtotal amount of the order.
     *              'discount_tax'     => (float) The tax amount on discounts.
     *              'manual_discount_total'   => (float) The total discount amount for the order.
     *              'shipping_tax'     => (float) The tax amount on shipping.
     *              'shipping_total'   => (float) The total shipping amount for the order.
     *              'tax_total'        => (float) The total tax amount for the order.
     *              'total_amount'     => (float) The total amount for the order.
     *              'total_paid'       => (float) The total amount paid for the order.
     *              'rate'             => (float) The exchange rate used for currency conversion.
     *              'ip_address'       => (string) The IP address associated with the order.
     *              'completed_at'     => (string|null) date-time order completed|null
     *  *              'refunded_at'      => (string|null) date-time the order was refunded|null
     *  *              'uuid'             => (string) The id for the order.
     *   *              'created_at'       => (string) The date and time the order was created.
     *  *              'updated_at'       => (string) The date and time the order was last updated.
     *  *              'customer'         => (null|array) Info of customer associated with the order.
     *              'order_items'      => (array) Required. Array of order item details.
     *                 [
     *                    'id'             => ( int ) The id for the order item.
     *                    'order_id'       => ( int ) The ID of the order to which the item belongs.
     *                    'post_id'      => ( int ) The product ID associated with the order item.
     *                    'object_id'   => ( int ) The variation ID of the order item.
     *                    'thumbnail'      => ( string ) The URL of the thumbnail of order item.
     *                    'item_price'     => ( float ) The price of the item.
     *                    'item_name'      => ( string ) The name of the item.
     *                    'quantity'       => ( int ) The quantity of the item.
     *                    'type'           => ( string ) Type ( e.g., 'simple', 'variable' ).
     *                    'stockStatus'    => ( string ) ( e.g., 'in-stock'|'out-of-stock' ).
     *                    'stock'          => ( int ) The current stock quantity.
     *                    'tax_amount'     => ( float ) The tax amount for the item.
     *                    'manual_discount_total' => ( float ) The total discount amount for the item.
     *                    'item_total'     => ( float ) The total amount for the item.
     *                    'line_total'     => ( float ) The total amount for the line
     * ]
     * ],
     *     'discount'       => ( array ) Optional. Represents the discount details
     *        [
     *           'type'   => ( string ) Required. type of discount ( e.g., 'amount', 'percentage' )
     *           'label'  => ( string ) Optional. The label associated with the discount
     *           'reason' => ( string ) Optional. The reason for the discount
     *           'value'  => ( float ) Required. The value of the discount
     * ],
     *      'shipping'      => ( array ) Optional. Represents the shipping details.
     *        [
     *           'type'   => ( string ) Optional. The type of shipping.
     *           'value'  => ( float|null ) Optional. Value associated with shipping|null if not
     * ],
     *      'deletedItems'  => ( array ) Optional. IDs of items to be deleted.
     *        [
     *           ( e.g., 100, 501 etc )
     * ]
     * ]
     * @param int $id Required. The ID of the order to update.
     * @param array $params Optional. Additional parameters for order update.
     *        [
     *            // Include optional parameters, if any.
     * ]
     *
     */
    public static function update($data, $id, $params = [])
    {


        $order = static::getQuery()->with(["order_items", "appliedCoupons", "labels"])->where('id', $id)->first();

        if (empty($order) || $order->status === Status::ORDER_COMPLETED || $order->status === Status::ORDER_CANCELED) {
            if (empty($order)) {
                return static::makeErrorResponse([
                    ['code' => 404, 'message' => __('The order information does not match', 'fluent-cart')]
                ]);
            }

            return static::makeErrorResponse([
                ['code' => 404, 'message' => sprintf(
                    /* translators: %s is the order status */
                    __('Your order status is marked as %s and not eligible for any further modifications at this time.', 'fluent-cart'), $order->status)]
            ]);
        }

        // Server-authoritative columns (tax_total, shipping_tax, tax_behavior,
        // discount_tax, total_paid, total_refund, item tax_amount) must never
        // come from the client — see stripClientTaxFields().
        $orderData = static::stripClientTaxFields($data['orderData']);
        $deletedItems = $data['deletedItems'];
        $appliedCoupons = Arr::get($orderData, 'applied_coupon');
        $discount = $data['discount'];
        $shipping = $data['shipping'];

        $orderId = $order->id;

        /**
         * First delete the deleted items
         */
        if (!empty($deletedItems)) {
            // Filter only the custom items that are in the deleted IDs
            $customItems = $order->order_items
                ->filter(fn($item) => $item->is_custom && in_array($item->id, $deletedItems))
                ->values(); // reset keys
            
            if ($customItems->isNotEmpty()) {
                do_action('fluent_cart/order/before_custom_items_deleted', $customItems, $order);
            }

            OrderItem::destroy($deletedItems);

            if ($customItems->isNotEmpty()) {
                do_action('fluent_cart/order/after_custom_items_deleted', $customItems, $order);
            }
        }

        if (!empty($discount)) {
            if (!empty($appliedCoupons) && count($appliedCoupons) > 0) {
                // Remove the custom discount amount if coupon is applied.
                OrderMetaResource::delete($orderId, [
                    //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                    'meta_key' => 'order_discount',
                ]);
            } else {
                static::addOrUpdateOrderMeta([
                    'order_id'   => $orderId,
                    //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                    'meta_key'   => 'order_discount',
                    //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                    'meta_value' => $discount
                ]);
            }
        }
        if (!empty($shipping)) {
            $shipping = is_array($shipping) ? static::resolveShippingTitle($shipping) : $shipping;
            static::addOrUpdateOrderMeta([
                'order_id'   => $orderId,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_key'   => 'order_shipping',
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'meta_value' => $shipping
            ]);
        }

        $items = Arr::get($orderData, 'order_items');
        $isUpdatedOrderItems = OrderItemResource::updateOrInsertOrderItems($order, $orderId, Arr::except($items, ['*']));

        if ($isUpdatedOrderItems) {
            unset($orderData['order_items']);
            unset($orderData['customer']);
            unset($orderData['tax_lines']);


            $orderData['currency'] = Helper::shopConfig('currency');

            $oldOrder = clone $order;
            $isUpdated = $order->update($orderData);

            if ($isUpdated) {
                $newOrder = $order->refresh();

                if (!empty($appliedCoupons)) {
                    $appliedCoupons = Arr::except($appliedCoupons, ['*']);
                    $couponCodes = array_keys($appliedCoupons);
                    if (!empty($couponCodes)) {
                        $coupons = Coupon::query()->whereIn('code', $couponCodes)->get()
                            ->keyBy('code')
                            ->toArray();

                        foreach ($coupons as $code => &$coupon) {
                            $coupon['order_id'] = $orderId;
                            $coupon['coupon_id'] = $appliedCoupons[$code]['id'];
                            $coupon['amount'] = $appliedCoupons[$code]['discount'];
                            $coupon['created_at'] = $order->updated_at;
                            $coupon['updated_at'] = $order->updated_at;
                        }
                        $order->appliedCoupons()->delete();
                        $order->appliedCoupons()->createMany($coupons);
                        Coupon::query()->whereIn('code', $couponCodes)->increment('use_count', 1);
                    }
                }

                if (empty($appliedCoupons) && count($order->appliedCoupons) > 0) {
                    $order->appliedCoupons()->delete();
                }

                // $getOrderNoActionableStatuses = ['unshippable'];
                // if(in_array($newOrder->shipping_status, $getOrderNoActionableStatuses)) {
                //     $newOrder->shipping_status = OrderMetaResource::find($orderId, ['meta_key' => 'shipping_previous_status']);
                // }
                static::reapplyTaxAfterUpdate($orderId, $newOrder);

                $newOrder = $newOrder->refresh();

                (new OrderUpdated($newOrder, $oldOrder))->dispatch();

                $oldOrderItems = json_decode(json_encode(Arr::get($oldOrder, 'order_items', [])), true);
                $newOrderItems = json_decode(json_encode(Arr::get($newOrder, 'order_items', [])), true);
                $pluckOldVariationIds = array_column($oldOrderItems, 'object_id');
                foreach ($newOrderItems as $newItem) {
                    if (!in_array($newItem['object_id'], $pluckOldVariationIds)) {
                        $oldOrderItems[] = $newItem;
                    }
                }

                static::triggerEventsOnStockChanged($oldOrderItems);

                return static::makeSuccessResponse(
                    $isUpdated,
                    __('Order updated successfully', 'fluent-cart')
                );
            }
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Order update failed.', 'fluent-cart')]
        ]);
    }

    /**
     * Strip server-authoritative columns from a client-supplied order payload
     * before it is persisted by update().
     *
     * The admin edit screen sends the whole order object back — including
     * tax_total, shipping_tax, tax_behavior, discount_tax and per-item
     * tax_amount. For normal orders reapplyTaxAfterUpdate() recalculates and
     * overwrites these server-side right after the save, but subscription and
     * refund-type orders skip that recalc — whatever the client sent would
     * become final (stale values from a race, or forged values from a
     * tampered request). These columns must therefore never be
     * client-writable on this path: the existing DB values persist unless
     * the server-side recalc changes them.
     *
     * total_paid / total_refund only move via payment & refund flows. The
     * controller already drops them (OrderRequest::sanitize() is a whitelist
     * and getSafe() only returns whitelisted keys), so stripping them here is
     * defense in depth for direct OrderResource::update() callers.
     *
     * total_amount is intentionally NOT stripped: it is client-computed for
     * legitimate item edits on subscription/refund orders, and for normal
     * orders reapplyTaxAfterUpdate() recomputes it from scratch anyway.
     *
     * Removing the per-item tax_amount key (rather than zeroing it) makes
     * OrderItemResource::updateOrInsertOrderItems() leave the existing DB
     * value untouched on updated rows; inserted rows fall back to the column
     * default (0) and normal orders get patched by patchOrderItemTaxMeta()
     * after the recalc.
     *
     * @param array $orderData The 'orderData' payload consumed by update().
     * @return array
     */
    private static function stripClientTaxFields($orderData)
    {
        $orderData = Arr::except((array) $orderData, [
            'tax_total',
            'shipping_tax',
            'tax_behavior',
            'discount_tax',
            'total_paid',
            'total_refund',
        ]);

        $items = Arr::get($orderData, 'order_items');
        if (is_array($items)) {
            foreach ($items as $itemIndex => $item) {
                if (is_array($item)) {
                    unset($orderData['order_items'][$itemIndex]['tax_amount']);
                }
            }
        }

        return $orderData;
    }

    public static function updateOrderAddressId($data, Order $order)
    {

        $addressType = Arr::get($data, 'address_type') ?? 'billing';
        $addressId = Arr::get($data, 'address_id');
        $addressRelation = $addressType === 'billing' ? 'billing_address' : 'shipping_address';

        $address = CustomerAddresses::query()->find($addressId);
        if (!empty($address)) {
            $order->load($addressRelation);
            $currentAddress = $order->{$addressRelation};
            if (empty($currentAddress)) {
                $result = static::createOrderAddress($address->toArray(), $order->id);
            } else {
                $result = static::mergeOrderAddress($currentAddress, $address->toArray());
            }
            if (!$order->isSubscription() && $order->type !== 'refund') {
                static::reapplyTaxAfterUpdate($order->id, $order->refresh());
            }
            return $result;
        }
    }

    public static function updateOrderAddress($data)
    {
        $orderId = sanitize_text_field(Arr::get($data, 'order_id'));
        $addressId = sanitize_text_field(Arr::get($data, 'id'));
        $orderAddress = OrderAddress::query()->where('order_id', $orderId)->where('id', $addressId)->first();
        if (empty($orderAddress)) {
            return static::makeErrorResponse([
                ['code' => 404, 'message' => __('The address information does not match', 'fluent-cart')]
            ]);
        }

        $updateData = Arr::only($data, ['name', 'first_name', 'last_name', 'full_name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country']);
        // sanitize the data before updating
        $updateData = array_map('sanitize_text_field', $updateData);
        $result = $orderAddress->update($updateData);

        $reloadedOrder = Order::find($orderId);
        if ($reloadedOrder && !$reloadedOrder->isSubscription() && $reloadedOrder->type !== 'refund') {
            static::reapplyTaxAfterUpdate($orderId, $reloadedOrder);
        }

        return $result;

    }

    /**
     * Delete an order and associated data by ID.Including order meta, order items, transactions,
     *
     * @param int $id Required. The ID of the order to delete.
     * @param array $params Optional. Additional parameters for order deletion.
     *        [
     *              // Include optional parameters, if any.
     * ]
     *
     */
    public static function delete($id, $params = [])
    {
        $DB = App::db();

        try {
            /** @var Order $order */
            $order = static::getQuery()->with("order_items")->find($id);
            if (!$order) {
                return static::makeErrorResponse([
                    ['code' => 404, 'message' => __('Order not found', 'fluent-cart')]
                ]);
            }

            $canBeDeleted = $order->canBeDeleted();
            if (is_wp_error($canBeDeleted)) {
                return $canBeDeleted;
            }

            $deletedOrder = clone $order;
            $deletedOrderItems = json_decode(json_encode(Arr::get($order, 'order_items', [])), true);
            $connectedOrderIds = [$order->id];
            $isTestMode = $order->mode === Status::ORDER_MODE_TEST;

            if ($order->type === 'subscription') {
                $childOrderIds = Order::query()->where('parent_id', $order->id)->pluck('id')->toArray();
                $connectedOrderIds = array_merge($childOrderIds, $connectedOrderIds);
            }

            $DB->beginTransaction();

            if ($order->type === 'subscription') {
                $subscriptionIds = Subscription::query()->whereIn('parent_order_id', $connectedOrderIds)->pluck('id')->toArray();
                if ($subscriptionIds) {
                    SubscriptionMeta::query()->whereIn('subscription_id', $subscriptionIds)->delete();
                }

                Subscription::query()->whereIn('parent_order_id', $connectedOrderIds)->delete();
            }

            // Dispatch inside transaction so stock restore is atomic with deletion.
            // Must run before deleteOrderRelatedData() which removes stock_movement meta and order items.
            (new OrderDeleting($order, $connectedOrderIds, $isTestMode, $order->type))->dispatch();

            // Pre-load relations before cleanup so the OrderDeleted event has address data
            $deletedOrder->load('customer', 'shipping_address', 'billing_address');

            static::deleteOrderRelatedData($connectedOrderIds, $isTestMode);
            $DB->commit();

            if (!empty($deletedOrder)) {
                if ($order->type === 'renewal') {
                    (new RenewalOrderDeleted($deletedOrder))->dispatch();
                } else {
                    (new OrderDeleted($deletedOrder, $connectedOrderIds))->dispatch();
                }
            }
            if (!empty($deletedOrderItems)) {
                static::triggerEventsOnStockChanged($deletedOrderItems);
            }

            return static::makeSuccessResponse(
                '',
                __('Selected order and associated data has been deleted', 'fluent-cart')
            );

        } catch (\Exception $e) {
            $DB->rollBack();
            return static::makeErrorResponse([
                ['code' => 400, 'message' => __('Failed to delete', 'fluent-cart')]
            ]);
        }
    }

    protected static function deleteOrderRelatedData(array $orderIds, bool $isTestMode = false): void
    {
        OrderTransaction::query()->whereIn('order_id', $orderIds)->delete();
        OrderAddress::query()->whereIn('order_id', $orderIds)->delete();
        OrderItem::query()->whereIn('order_id', $orderIds)->delete();
        OrderMeta::query()->whereIn('order_id', $orderIds)->delete();
        OrderTaxRate::query()->whereIn('order_id', $orderIds)->delete();
        OrderOperation::query()->whereIn('order_id', $orderIds)->delete();
        AppliedCoupon::query()->whereIn('order_id', $orderIds)->delete();
        Cart::query()->whereIn('order_id', $orderIds)->delete();
        OrderDownloadPermission::query()->whereIn('order_id', $orderIds)->delete();
        LabelRelationship::query()->where('labelable_type', Order::class)
            ->whereIn('labelable_id', $orderIds)->delete();

        if ($isTestMode) {
            Activity::query()->where('module_type', Order::class)
                ->whereIn('module_id', $orderIds)->delete();
        }

        Order::query()->whereIn('id', $orderIds)->delete();
    }

    /**
     * View details of an order by ID.
     *
     * This function retrieves details of an order by the specified ID. It includes information about the customer, order items with variants, transactions, discount meta, shipping meta,
     * and order settings.
     *
     * @param int $id Required. The ID of the order to view.
     *
     */
    public static function view(int $id)
    {
        $orders = static::search(
            ['fct_orders.id' => $id],
            function (Builder $query) {
                return $query
                    ->with(
                        [
                            'parentOrder'    => function ($query) {
                                return $query->select('id')
                                    ->with('subscriptions.product');
                            },
                            'subscriptions.product',
                            'activities.user',
                            'labels',
                            'customer',
                            'children'       => function ($query) {
                                return $query->select('id', 'parent_id', 'created_at');
                            },
                            //'order_items.variants.product_detail',
                            'order_items' => function ($query) {
                                $query->addAppends(['coupon_discount']);
                            },
                            'order_items.variants.media',
                            'transactions',
                            'order_addresses',
                            'orderTaxRates.tax_rate',
                            'billing_address',
                            'shipping_address',
                            'appliedCoupons' => function ($query) {
                                $query->select('*');
                            }
                        ]
                    )
                    ->addAppends(['business_info', 'customer_tax_number', 'is_b2b_order', 'display_tax_lines', 'display_shipping_tax_lines', 'is_reverse_charge_tax_order', 'tax_summary']);
            }
        );

        if (empty($orders[0])) {
            return new \WP_Error('403', __('Order not found!', 'fluent-cart'));
        }

        $subscriptions = Arr::get($orders, '0.subscriptions');

        if (empty($subscriptions)) {
            $config = Arr::get($orders, '0.config', null);
            $upgradedFrom = is_array($config)
                ? Arr::get($config, 'upgraded_from', null)
                : (is_string($config) ? Arr::get(json_decode($config, true), 'upgraded_from', null) : null);

            $orders[0]['subscriptions'] = $upgradedFrom
                ? []
                : Arr::get($orders, '0.parent_order.subscriptions', []);
        }

        $data = [];

        if (isset($orders[0])) {
            $order = $orders[0];
            $selectedLabels = Collection::make($order['labels'])->pluck('label_id');
            $order['custom_checkout_url'] = PaymentHelper::getCustomPaymentLink(Arr::get($order, 'uuid'));

            $orderModel = Order::find($id);
            $rcMode = $orderModel ? $orderModel->getOrderRcMode() : 'fixed';

            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            $shippingMeta = OrderMetaResource::find($order['id'], ['meta_key' => 'order_shipping']);

            $orderConfig = is_array($order['config']) ? $order['config'] : (array)json_decode((string)($order['config'] ?? ''), true);
            $methodId    = (int)Arr::get($orderConfig, 'shipping_method_id', 0);
            $methodTitle = (string)Arr::get($orderConfig, 'shipping_method_title', '');

            if (!$methodId && is_array($shippingMeta) && isset($shippingMeta['id'], $shippingMeta['title'])) {
                $methodId    = (int)$shippingMeta['id'];
                $methodTitle = (string)$shippingMeta['title'];
            }

            // Gate on the title alone. Live-rate carriers use non-numeric method
            // ids (e.g. "carrier:shippo:usps_priority") which (int) casts to 0,
            // so requiring a truthy id silently hid the method name.
            $checkoutShipping = $methodTitle ? [
                'method_id'      => $methodId,
                'method_title'   => $methodTitle,
                'shipping_total' => (int)Arr::get($order, 'shipping_total', 0),
            ] : null;

            $data = [
                'order'             => $order,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'discount_meta'     => OrderMetaResource::find($order['id'], ['meta_key' => 'order_discount']),
                'shipping_meta'     => $shippingMeta,
                'checkout_shipping' => $checkoutShipping,
                'order_settings'    => [
                    'reverse_charge_price_mode' => $rcMode,
                ],
                'selected_labels'   => $selectedLabels,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'tax_id' => OrderMetaResource::find($order['id'], ['meta_key' => 'tax_id'])
            ];
        }

        return $data;
    }

    /**
     * Retrieve an overview of reports based on specified parameters.
     *
     * It calculates total sales, net sales, total discounts, total shipping tax, average order
     * value, and customer order count based on the reports data.
     *
     * @param array $params Required. Additional parameters for report overview.
     *        $params = [
     *              //(Required)
     *               "status" => [
     *                  "column" => "status",
     *                  "operator" => "in",
     *                  "value" => "Order success status e.g. completed,
     *               ],
     *
     *               //(Required)
     *               "payment_status" => [
     *                  "column" => "payment_status",
     *                  "operator" => "in",
     *                  "value" => "Transaction success status e.g. paid,
     *               ],
     *
     *               //(Optional)
     *               "created_at" => [
     *                  "column" => "created_at",
     *                  "operator" => "between"
     *                  "value" => "from and to date"
     *              ]
     *        ]
     *
     */
    /**
     * @deprecated since v1.4. Use OverviewReportController::getOverview() via GET reports/overview instead.
     */
    public static function reportOverview($params = [])
    {
        return static::getQuery()->when(
            $params,
            function ($query) use ($params) {
                return $query->search($params);
            }
        )
            ->selectRaw('sum(total_amount) as total_sales')
            ->selectRaw('sum(total_amount - manual_discount_total - shipping_total - tax_total) as net_sales')
            ->selectRaw('sum(manual_discount_total + coupon_discount_total) as total_discounts')
            ->selectRaw('sum(shipping_total) as total_shipping_tax')
            ->selectRaw('avg(total_amount) as average_order_value')
            ->selectRaw('count(*) as customer_order_count')
            ->get()->first();
    }

    /**
     * Retrieve order summary based on payment methods and specified parameters.
     *
     * This function generates order summary by payment method, applying filters provided in the parameters.
     *
     * It retrieves the count of orders, total transactions, and groups the results by payment method.
     *
     * @param array $params Required. Additional parameters for order summary generation.
     *        $params = [
     *              //(Required)
     *               "status" => [
     *                  "column" => "status",
     *                  "operator" => "in",
     *                  "value" => "Order success status e.g. completed,
     *               ],
     *
     *               //(Required)
     *               "payment_status" => [
     *                  "column" => "payment_status",
     *                  "operator" => "in",
     *                  "value" => "Transaction success status e.g. paid,
     *               ],
     *
     *               //( Optional )
     *               'created_at' => [
     *                  'column' => 'created_at',
     *                  'operator' => 'between'
     *                  'value' => 'from and to date'
     * ]
     * ]
     *
     * @return Collection of orders
     */
    public static function orderSummaryByPayment(array $params = [])
    {
        return static::getQuery()->select('payment_method')
            ->when(
                $params,
                function ($query) use ($params) {
                    return $query->search($params);
                }
            )
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('SUM(total_amount) as transactions')
            ->groupBy('payment_method')
            ->get();
    }

    private static function addOrUpdateOrderMeta($params = [])
    {
        $orderId = Arr::get($params, 'order_id', null);
        //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        $key = Arr::get($params, 'meta_key', '');
        //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $value = Arr::get($params, 'meta_value', '');
        //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        $isExist = OrderMetaResource::find($orderId, ['meta_key' => $key]);

        if ($isExist) {
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            return OrderMetaResource::update($value, $orderId, ['meta_key' => $key]);
        }
        return OrderMetaResource::create($params);
    }

    private static function triggerEventsOnStockChanged($orderItems)
    {
        if (!empty($orderItems)) {
            $productIds = [];
            foreach ($orderItems as $orderItem) {
                $productIds[] = Arr::get($orderItem, 'post_id');
            }
            if (!empty($productIds)) {
//                (new StockChanged($productIds))->dispatch();
            }
        }
    }

    public static function updateStatuses(array $params = [])
    {

        $order = Arr::get($params, 'order');
        if (empty($order)) {
            return static::makeErrorResponse([
                ['code' => 404, 'message' => __('Order not found!', 'fluent-cart')]
            ]);
        }

        $orderId = Arr::get($order, 'id');

        $order = static::getQuery()->with("order_items.variants.product_detail")->where('id', $orderId)->first();

        $action = Arr::get($params, 'action');

        // This endpoint's contract is order/shipping status only — payment-status
        // transitions flow through their dedicated surfaces (mark-as-paid,
        // transaction status updates, refunds, gateway webhooks) so money state
        // stays consistent with transactions. Rejecting unknown actions up front
        // also keeps them out of the order_status fallback below.
        if (!in_array($action, ['change_order_status', 'change_shipping_status'], true)) {
            return static::makeErrorResponse([
                ['code' => 400, 'message' => __('Unsupported action — this endpoint changes order or shipping status only.', 'fluent-cart')]
            ], 400);
        }

        $changeType = $action === 'change_shipping_status' ? 'shipping_status' : 'order_status';
        $actionActivity = [];

        if ($action === 'change_shipping_status') {
            $newStatus = Arr::get($params, 'statuses.shipping_status', null);
            $oldStatus = Arr::get($order, 'shipping_status');
            $validStatuses = Status::getEditableShippingStatuses();
            $actionActivity = [
                'title'   => __('Shipping status updated', 'fluent-cart'),
                'content' => sprintf(
                    /* translators: %1$s is the old status, %2$s is the new status */
                    __('Shipping status has been updated from %1$s to %2$s', 'fluent-cart'), $oldStatus, $newStatus)
            ];

            $orderItems = OrderItem::query()->where('fulfillment_type', 'physical')->where('order_id', $orderId)->get();
            $updateData = [];
            foreach ($orderItems as $item) {
                $updateData[] = [
                    'id'                 => $item->id,
                    'fulfilled_quantity' => in_array($newStatus, ['shipped', 'delivered']) ? $item->quantity : '0'
                ];
            }
            OrderItem::query()->batchUpdate($updateData);
        }
        if ($action === 'change_order_status') {
            $newStatus = Arr::get($params, 'statuses.order_status', null);
            $oldStatus = Arr::get($order, 'status');
            $validStatuses = Status::getEditableOrderStatuses();
            $shippingStatus = Arr::get($order, 'shipping_status');
            $actionActivity = [
                'title'   => __('Order status updated', 'fluent-cart'),
                'content' => sprintf(
                    /* translators: %1$s is the old status, %2$s is the new status */
                    __('Order status has been updated from %1$s to %2$s', 'fluent-cart'), $oldStatus, $newStatus)
            ];
        }

        if ($newStatus !== null) {
            if (isset($validStatuses[$newStatus])) {
                if ($newStatus != $oldStatus) {

                    $getOrderNoActionableStatuses = [Status::SHIPPING_UNSHIPPABLE];

                    if ($action === 'change_order_status') {
                        if ($oldStatus === Status::ORDER_CANCELED) {
                            return static::makeErrorResponse([
                                ['code' => 400, 'message' => __('You cannot change the order status once it has been canceled.', 'fluent-cart')]
                            ]);
                        }

                        $order = $order->updateStatus('status', $newStatus);

                        if ($newStatus === Status::ORDER_CANCELED) {
                            if (in_array($shippingStatus, $getOrderNoActionableStatuses)) {
                                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                                $shippingStatus = OrderMetaResource::find($orderId, ['meta_key' => 'shipping_previous_status']);
                            }
                            (new OrderStatusUpdated($order, $shippingStatus, $newStatus, Arr::get($params, 'manage_stock', true), $actionActivity, $changeType))->dispatch();
                        } else {
                            (new OrderStatusUpdated($order, $oldStatus, $newStatus, false, $actionActivity, $changeType))->dispatch();
                        }
                    }

                    if ($action === 'change_shipping_status') {

                        if (in_array($newStatus, $getOrderNoActionableStatuses)) {
                            static::addOrUpdateOrderMeta([
                                'order_id'   => $orderId,
                                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                                'meta_key'   => 'shipping_previous_status',
                                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                                'meta_value' => $oldStatus
                            ]);
                        }
                        if (in_array($oldStatus, $getOrderNoActionableStatuses)) {
                            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                            $oldStatus = OrderMetaResource::find($orderId, ['meta_key' => 'shipping_previous_status']);
                        }

                        if ($action !== 'change_shipping_status' && Arr::get($params, 'manage_stock') == 'true') {
                            $validationSucceeded = static::validateStock(Arr::get($order, 'order_items', []));

                            if (Arr::get($validationSucceeded, 'status') === true) {
                                return static::makeErrorResponse([
                                    ['code' => 400, 'message' => Arr::get($validationSucceeded, 'message')]
                                ]);
                            }
                        }

                        $order = $order->updateStatus('shipping_status', $newStatus);

                        (new OrderStatusUpdated($order, $oldStatus, $newStatus, Arr::get($params, 'manage_stock'), $actionActivity, $changeType))->dispatch();

                        $orderItems = json_decode(json_encode(Arr::get($order, 'order_items', [])), true);
                        static::triggerEventsOnStockChanged($orderItems);
                    }

                    return static::makeSuccessResponse(
                        $order,
                        __('Status has been updated', 'fluent-cart')
                    );
                }
                return static::makeErrorResponse([
                    ['code' => 400, 'message' => __('Order already has the same status', 'fluent-cart')]
                ]);
            }
            return static::makeErrorResponse([
                ['code' => 400, 'message' => __('Provided status is not valid', 'fluent-cart')]
            ]);
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Failed to update status', 'fluent-cart')]
        ]);
    }

    private static function validateStock($orderItems)
    {
        $outOfStockVariants = [];

        foreach ($orderItems as $orderItem) {
            $quantity = (int)Arr::get($orderItem, 'quantity', 0);
            $stock = (int)Arr::get($orderItem, 'variants.available', 0);
            // $manageStock = (int)Arr::get($orderItem, 'variants.product_detail.manage_stock');
            $manageStock = (int)Arr::get($orderItem, 'variants.manage_stock');
            $variationTitle = Arr::get($orderItem, 'variants.variation_title');

            if ($manageStock == 1 && $stock - $quantity < 0) {
                $outOfStockVariants[] = $variationTitle;
            }
        }

        if (!empty($outOfStockVariants)) {
            $message = (count($outOfStockVariants) > 1)
                ? sprintf(
                    /* translators: %s is the list of out of stock variants */
                    __('%s are out of stock', 'fluent-cart'), implode(', ', $outOfStockVariants))
                : sprintf(
                    /* translators: %s is the out of stock variant */
                    __('%s is out of stock', 'fluent-cart'), reset($outOfStockVariants));


            return [
                'status'  => true,
                'message' => $message
            ];
        }

        return false;
    }

    /**
     * Delete orders and its associated data.
     *
     * @param array $orderIds The ids of the order to be deleted.
     * @param array $params Additional parameters for the deletion process.
     *
     */
    public static function bulkDeleteByOrderIds($orderIds, $params = [])
    {
        $failedOrderIds = [];
        $deletedOrderIds = [];

        foreach ($orderIds as $order) {
            $isDeleted = static::delete($order);

            if (is_wp_error($isDeleted)) {
                $failedOrderIds[] = $order;
            } else {
                $deletedOrderIds[] = $order;
            }
        }

        if (count($failedOrderIds) > 0) {
            $failedOrderIdsString = implode(' , ', $failedOrderIds);
            return count($deletedOrderIds) > 0
                ? static::makeSuccessResponse([
                    'deleted_order_ids' => $deletedOrderIds,
                    'deleted_count'     => count($deletedOrderIds),
                    'failed_order_ids'  => $failedOrderIds,
                    'failed_count'      => count($failedOrderIds)
                ], sprintf(
                    /* translators: %s: The order ID(s) that could not be deleted. */
                    __("The order ID - %s cannot be deleted at the moment as these orders status is not canceled. And remaining order and its associated data have been deleted", 'fluent-cart'), $failedOrderIdsString))
                : static::makeErrorResponse([['code' => 400, 'message' => sprintf(
                    /* translators: %s: The order ID(s) that could not be deleted. */
                    __("The order ID - %s cannot be deleted at the moment as these orders status is not canceled.", 'fluent-cart'), $failedOrderIdsString)]]);
        }

        if (count($deletedOrderIds) > 0 && count($failedOrderIds) < 1) {
            return static::makeSuccessResponse([
                'deleted_order_ids' => $deletedOrderIds,
                'deleted_count'     => count($deletedOrderIds),
                'failed_order_ids'  => [],
                'failed_count'      => 0
            ], __('Selected order and associated data have been deleted', 'fluent-cart'));
        }

        return static::makeSuccessResponse([
            'deleted_order_ids' => [],
            'deleted_count'     => 0,
            'failed_order_ids'  => [],
            'failed_count'      => 0
        ], __('No orders were deleted', 'fluent-cart'));
    }

    public static function updatePaymentStatus(array $params = [])
    {
        $order = Arr::get($params, 'order');
        $transaction = Arr::get($params, 'transaction');
        $newStatus = Arr::get($params, 'status');

        if (empty($transaction)) {
            return static::makeErrorResponse([
                ['code' => 404, 'message' => __('Transaction not found!', 'fluent-cart')]
            ]);
        }

        if ($transaction->status == $newStatus) {
            return static::makeErrorResponse([
                ['code' => 400, 'message' => __('Transaction already has the same status', 'fluent-cart')]
            ]);
        }

        if ($transaction->order_id != $order->id) {
            return static::makeErrorResponse([
                ['code' => 400, 'message' => __('The selected transaction does not match with the provided order', 'fluent-cart')]
            ]);
        }

        $data = [];
        $totalPaid = ($order->total_paid - $transaction->total) < 0 ? 0 : $transaction->total;

        if ($newStatus == Status::PAYMENT_PAID) {
            $data[] = [
                'id'             => $order->id,
                'payment_status' => $newStatus,
                'total_paid'     => ['+', $transaction->total],
            ];
        } elseif ($newStatus == Status::PAYMENT_REFUNDED) {
            $data[] = [
                'id'             => $order->id,
                'payment_status' => $newStatus,
                'refunded_at'    => DateTime::gmtNow(),
                'total_paid'     => ['-', $totalPaid],
                'total_refund'   => ['+', $transaction->total],
            ];
        } elseif ($newStatus == (Status::PAYMENT_PENDING || Status::PAYMENT_FAILED)) {
            $data[] = [
                'id'             => $order->id,
                'payment_status' => $newStatus,
                'total_paid'     => ['-', $totalPaid],
            ];
        }

        $updatedStatus = $transaction->updateStatus($newStatus);

        if (!empty($data) && $updatedStatus) {
            $oldStatus = Arr::get($order, 'payment_status');
            $actionActivity = [
                'title'   => 'Payment status updated',
                'content' => sprintf(
                    /* translators: %1$s is the old status, %2$s is the new status */
                    __('Payment status has been updated from %1$s to %2$s', 'fluent-cart'), $oldStatus, $newStatus)
            ];

            static::getQuery()->batchUpdate($data);

            (new OrderStatusUpdated($order, $oldStatus, $newStatus, false, $actionActivity, 'payment_status'))->dispatch();

            return static::makeSuccessResponse(
                $order,
                __('Payment Status has been updated', 'fluent-cart')
            );

        } else {
            return static::makeErrorResponse([
                ['code' => 400, 'message' => __('Failed to update payment status', 'fluent-cart')]
            ]);
        }
    }

    private static function mergeOrderAddress(OrderAddress $address, array $addressData)
    {
        $keysToInclude = ['type', 'name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country'];
        foreach ($keysToInclude as $key) {
            $address->{$key} = $addressData[$key];
        }

        if (array_key_exists('meta', $addressData)) {
            $address->meta = $addressData['meta'];
        }

        if ($address->save()) {
            return $address;
        }
        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Failed to update address', 'fluent-cart')]
        ]);
    }

    private static function createOrderAddress(array $address, $orderId)
    {
        $keysToInclude = ['order_id', 'type', 'name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'meta'];
        $address = Arr::only($address, $keysToInclude);
        $address['order_id'] = $orderId;

        if (!empty($address)) {
            return OrderAddressResource::create($address);
        }
    }

    public static function getOrderByHash($orderHash)
    {
        return (new Orders())->getByHash($orderHash);
    }

    private static function resolveShippingTitle(array $shipping): array
    {
        if (isset($shipping['id']) && empty($shipping['title'])) {
            $sm = ShippingMethod::find((int)$shipping['id']);
            $shipping['title'] = $sm ? $sm->title : '';
        }
        return $shipping;
    }

}

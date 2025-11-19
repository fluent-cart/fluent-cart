## Refund Payment - Stripe

This document covers the Stripe refund process and what happens when refunds are initiated either by admin or by vendor through Stripe. The system handles two types of refunds: admin-initiated refunds and vendor-initiated refunds through webhooks.

### Refund Flow Overview

1. **Admin-Initiated Refund**: Admin processes refund through FluentCart admin panel
2. **Vendor-Initiated Refund**: Refund initiated directly from Stripe dashboard
3. **Refund Processing**: Stripe API processes the refund request
4. **Webhook Notification**: Stripe sends webhook notification for vendor-initiated refunds
5. **Order Update**: Order status and totals are updated accordingly
6. **Post-Refund Actions**: Stock management, email notifications, and other cleanup

### Key Components

- **Main Refund Class**: `app/Modules/PaymentMethods/Stripe/Refund.php`
- **Stripe Integration**: `app/Modules/PaymentMethods/Stripe/Stripe.php` - `refund()` method
- **Order Controller**: `app/Http/Controllers/OrderController.php` - `refundOrder()` method
- **IPN Handler**: `app/Modules/PaymentMethods/Stripe/IPN.php` - `handleChargeRefunded()` method
- **Payment Helper**: `app/Services/Payments/PaymentHelper.php` - `triggerRefund()` method
- **Frontend**: `resources/admin/Modules/Orders/Modals/Refund.vue`

### Admin-Initiated Refund Flow

#### 1. Frontend Refund Request

The admin initiates a refund through the admin panel:

```javascript
// Refund.vue - processRefund method
processRefund() {
    this.refundProcessing = true;

    // Validate refund amount
    if (!this.refund.amount) {
        return this.handleMessage('Please add refund amount.', 'error');
    }

    if (this.refund.amount > this.totalPaid) {
        return this.handleMessage(`Refund amount should not exceed: ${this.totalPaid}`, 'error');
    }

    // Send refund request to backend
    this.$post("orders/" + this.order_id + '/refund', {
        ...this.order,
        refund_info: this.refund,
    }).then(res => {
        this.handleSuccess(res.data.message)
        this.$emit('close_refund_modal')
    }).catch(errors => {
        this.handleError(errors);
    }).finally(() => {
        this.updateRefundable();
        this.refundProcessing = false;
    })
}
```

#### 2. Order Controller Processing

The `OrderController.refundOrder()` method handles the refund request:

```php
public function refundOrder(Request $request, Order $order)
{
    $orderData = $order->toArray();
    $paymentStatus = Arr::get($orderData, 'payment_status');
    $paymentTotal = Arr::get($orderData, 'total_paid');

    // Validate order has paid amount
    if (!$paymentTotal && $paymentStatus !== 'payment_status') {
        return $this->sendError([
            'message' => __('Order has not any valid paid amount!', 'fluent-cart')
        ], 423);
    }

    // Trigger payment method specific refund
    PaymentHelper::triggerRefund($request->refund_info, $order);
}
```

#### 3. Payment Helper Routing

The `PaymentHelper::triggerRefund()` method routes to the appropriate payment method:

```php
public static function triggerRefund($refundInfo, $orderData)
{
    // Trigger payment method specific refund action
    do_action(
        'fluent_cart/payments/refund_payment_on_' . Arr::get($refundInfo, 'paymentMethod'),
        $refundInfo,
        $orderData
    );
    
    // If no payment provider found, throw error
    wp_send_json_error(
        Arr::get($refundInfo, 'paymentMethod') . __(' refund is not available!', 'fluent-cart'),
        423
    );
}
```

#### 4. Stripe Refund Method

The `Stripe.refund()` method processes the Stripe-specific refund:

```php
public function refund($refundInfo, $orderData)
{
    // 1. Find valid transaction with charge ID
    $transaction = $orderData->transactions
        ->where('id', Arr::get($refundInfo, 'transaction_id'))
        ->whereNotNull('vendor_charge_id')
        ->where('vendor_charge_id', '!=', '')
        ->first();

    if (!$transaction) {
        wp_send_json_error(__('no valid transaction found', 'fluent-cart'), 200);
    }

    // 2. Prepare order data with transaction
    $orderData['transaction'] = $transaction->toArray();

    // 3. Process refund through Stripe API
    $refundInstance = new Refund();
    $refund = $refundInstance->processRefund($refundInfo, $orderData);
    $refund['payment_mode'] = $transaction->payment_mode;

    // 4. Create refund transaction record
    $refundInstance->maybeCreateRefundTransaction($orderData, $refund);

    // 5. Trigger post-refund actions
    $this->triggerAfterRefundProcess($refundInfo, $orderData, $refund);
}
```

### Stripe Refund Processing

#### 1. Refund API Call

The `Refund.processRefund()` method handles the Stripe API interaction:

```php
public function processRefund($refundInfo, $orderData)
{
    // 1. Get payment intent ID and API key
    $intentId = Arr::get($orderData, 'transaction.vendor_charge_id');
    $apiKey = (new StripeSettings())->getApiKey();

    if (!$intentId) {
        wp_send_json_error(__('Please provide a valid charge Id!', 'fluent-cart'));
    }

    // 2. Prepare refund data
    $refundData = [
        'payment_intent' => $intentId,
        'amount' => Helper::toCent(Arr::get($refundInfo, 'amount', 0)),
    ];

    // 3. Add refund reason if provided
    $acceptedReason = ['duplicate', 'fraudulent', 'requested_by_customer'];
    $reason = Arr::get($refundInfo, 'reason', false);

    if ($reason && in_array($reason, $acceptedReason)) {
        $refundData['reason'] = Arr::get($refundInfo, 'reason');
    }

    // 4. Create refund via Stripe API
    $vendorRefund = $this->createRefund($refundData, $apiKey);

    // 5. Validate refund response
    if (is_wp_error($vendorRefund)) {
        wp_send_json_error(array('message' => $vendorRefund->get_error_message()), 423);
    }

    if (Arr::get($vendorRefund, 'status') !== 'succeeded') {
        wp_send_json_error(array('message' => __('Refund not initiated, please try again after reload this page!', 'fluent-cart')), 423);
    }

    return $vendorRefund;
}
```

#### 2. Stripe API Integration

The `createRefund()` method makes the actual API call:

```php
public function createRefund($refundData, $apiKey)
{
    try {
        return (new API())->makeRequest('refunds', $refundData, $apiKey, 'POST');
    } catch (\Exception $e) {
        wp_send_json_error([
            'message' => $e->getMessage(),
        ], 423);
    }
}
```

### Refund Transaction Creation

#### Creating Refund Transaction Record

The `maybeCreateRefundTransaction()` method creates a transaction record for the refund:

```php
public function maybeCreateRefundTransaction($orderData, $refund)
{
    // Check if refund already exists
    if ($this->isRefundExist(Arr::get($refund, 'id'))) {
        return;
    }

    // Create refund transaction record
    $orderTransactions = [
        'order_id' => Arr::get($orderData, 'id'),
        'order_type' => 'payment',
        'payment_method' => 'stripe',
        'payment_mode' => Arr::get($refund, 'payment_mode', Arr::get($orderData, 'mode')),
        'payment_method_type' => 'card' // apple_pay, aliPay...,
        'transaction_type' => 'refund',
        'vendor_charge_id' => Arr::get($refund, 'payment_intent'),
        'status' => Status::TRANSACTION_REFUNDED,
        'currency' => Arr::get($orderData, 'currency'),
        'total' => Arr::get($refund, 'amount'),
        'meta' => json_encode([
            'refund_id' => Arr::get($refund, 'id'),
            'charge_id' => Arr::get($refund, 'payment_intent'),
            'balance_transaction' => Arr::get($refund, 'balance_transaction'),
            'reason' => Arr::get($refund, 'reason')
        ]),
    ];

    // Insert transaction record
    OrderTransaction::query()->insert($orderTransactions);

    // Dispatch refund event
    $order = Order::query()->find(Arr::get($orderData, 'id'));
    (new OrderRefunded($order, true))->dispatch();
}
```

### Vendor-Initiated Refund Flow (Webhooks)

#### 1. Webhook Reception

When a refund is initiated from Stripe dashboard, a webhook is sent to the IPN handler:

```php
// IPN.php - handleChargeRefunded method
public function handleChargeRefunded($event, $order)
{
    // Process refund updates from Stripe webhook
    (new Refund())->maybeUpdateRefunds($event, $order);
    
    // Log the event
    fluent_cart_add_log('Stripe webhook ' . $event->type . ' processed', $event, 'info', [
        'log_type' => 'webhook',
        'module_type' => 'FluentCart\App\Modules\PaymentMethods\Stripe',
        'module_name' => 'Stripe',
        'module_id' => $event->id ?: null,
    ]);
    
    $this->sendResponse(200);
}
```

#### 2. Webhook Refund Processing

The `maybeUpdateRefunds()` method processes webhook refund data:

```php
public function maybeUpdateRefunds($event, $order)
{
    $orderData = $order->toArray();
    $refunds = $event->data->object->refunds->data;
    $transactions = Arr::get($orderData, 'transactions');

    // Map existing transactions by refund ID
    $mapped = (new Collection($transactions))->keyBy('meta.refund_id')->toArray();

    // Process each refund from webhook
    foreach ($refunds as $key => $refund) {
        if (empty($mapped[$refund->id])) {
            // Create new refund transaction
            $this->maybeCreateRefundTransaction($orderData, (array)$refund);
        } else {
            // Update existing transaction status
            OrderTransaction::query()
                ->where('id', $orderData['id'])
                ->update(['status' => Status::TRANSACTION_REFUNDED]);
        }
    }

    // Dispatch refund event
    (new OrderRefunded($order, true))->dispatch();
}
```

### Post-Refund Processing

#### 1. After Refund Actions

The `triggerAfterRefundProcess()` method handles post-refund actions:

```php
public function triggerAfterRefundProcess($refundInfo, $orderData, $refund)
{

    // Handle license revocation if requested
    if (Arr::get($refundInfo, 'revokeLicenses') === 'true') {
        if (has_action('fluent_cart/payments/revoke_licenses_on_refund')) {
            $order = Order::query()->where('id', $orderData->id)->first();
            do_action('fluent_cart/payments/revoke_licenses_on_refund', $order);
        }
    }
}
```

#### 2. Order Refunded Event

The `OrderRefunded` event is dispatched to handle various post-refund tasks:

```php
class OrderRefunded extends EventDispatcher
{
    public string $hook = 'fluent_cart/order_refunded';

    protected array $listeners = [
        Listeners\UpdateStock::class,        // Handle stock restoration
        Listeners\Order\OrderRefunded::class // Handle order totals and notifications
    ];
}
```

#### 3. Order Totals Recalculation

The `OrderRefunded` listener updates order totals:

```php
public static function handle(\FluentCart\App\Events\Order\OrderRefunded $event)
{
    // Recalculate order paid and refund totals
    if ($event->order) {
        $event->order->recountTotalPaidAndRefund();
    }

  
   
}
```

### Stock Management

#### Stock Restoration on Refund

The `UpdateStock` listener handles stock restoration when refunds occur:

```php
public static function handle($event)
{
    $orderItems = Arr::get($event->order, 'order_items', []);
    $shouldUpdateStock = true;

    // Handle refund stock restoration
    if ($event->hook === 'fluent_cart/order_refunded') {
        // Stock management logic for refunded items
        // Restore stock quantities based on refunded items
    }

    // Update product variation stock levels
    if ($shouldUpdateStock === true) {
        foreach ($orderItems as $orderItem) {
            $prepared = self::prepareProductVariantsArray($orderItem, $action, $status);
            if (!empty($prepared)) {
                $productVariants[] = $prepared;
            }
        }
    }

    // Batch update stock levels
    if (!empty($productVariants)) {
        ProductVariation::query()->batchUpdate($productVariants);
    }
}
```

### Refund Validation

#### Amount Validation

The system validates refund amounts before processing:

```php
// Frontend validation
if (this.refund.amount > this.totalPaid) {
    return this.handleMessage(`Refund amount should not exceed: ${this.totalPaid}`, 'error');
}

// Backend validation
if (!$paymentTotal && $paymentStatus !== 'payment_status') {
    return $this->sendError([
        'message' => __('Order has not any valid paid amount!', 'fluent-cart')
    ], 423);
}
```

#### Transaction Validation

The system validates that a valid transaction exists for refund:

```php
$transaction = $orderData->transactions
    ->where('id', Arr::get($refundInfo, 'transaction_id'))
    ->whereNotNull('vendor_charge_id')
    ->where('vendor_charge_id', '!=', '')
    ->first();

if (!$transaction) {
    wp_send_json_error(__('no valid transaction found', 'fluent-cart'), 200);
}
```

### Error Handling

#### Common Error Scenarios

**Invalid Charge ID:**
```php
if (!$intentId) {
    wp_send_json_error(__('Please provide a valid charge Id!', 'fluent-cart'));
}
```

**Stripe API Errors:**
```php
if (is_wp_error($vendorRefund)) {
    wp_send_json_error(array('message' => $vendorRefund->get_error_message()), 423);
}
```

**Refund Status Validation:**
```php
if (Arr::get($vendorRefund, 'status') !== 'succeeded') {
    wp_send_json_error(array('message' => __('Refund not initiated, please try again after reload this page!', 'fluent-cart')), 423);
}
```

### Refund Reasons

#### Supported Refund Reasons

Stripe accepts specific refund reasons:

```php
$acceptedReason = ['duplicate', 'fraudulent', 'requested_by_customer'];
$reason = Arr::get($refundInfo, 'reason', false);

if ($reason && in_array($reason, $acceptedReason)) {
    $refundData['reason'] = Arr::get($refundInfo, 'reason');
}
```

### Order Status Updates

#### Payment Status Changes

When refunds are processed, order payment status is updated:

- **Full Refund**: `PAYMENT_REFUNDED`
- **Partial Refund**: `PAYMENT_PARTIALLY_REFUNDED`
- **Refund Amount**: Added to `total_refund` field
- **Paid Amount**: Reduced by refund amount

#### Transaction Status Updates

Refund transactions are created with:
- **Status**: `TRANSACTION_REFUNDED`
- **Type**: `refund`
- **Amount**: Refund amount in cents
- **Metadata**: Refund ID, charge ID, reason

### Logging and Monitoring

#### Refund Event Logging

The system logs refund events for monitoring:

```php
fluent_cart_add_log('Stripe webhook ' . $event->type . ' processed', $event, 'info', [
    'log_type' => 'webhook',
    'module_type' => 'FluentCart\App\Modules\PaymentMethods\Stripe',
    'module_name' => 'Stripe',
    'module_id' => $event->id ?: null,
]);
```

#### Refund Success/Error Tracking

- **Success**: Refund transaction created, order totals updated
- **Errors**: API failures, validation errors logged
- **Webhooks**: Webhook processing success/failure tracked

### Security Considerations

- **Webhook Verification**: Verify webhook authenticity from Stripe
- **Amount Validation**: Ensure refund doesn't exceed paid amount
- **Transaction Validation**: Verify transaction exists and is refundable
- **Permission Checks**: Ensure admin has refund permissions
- **Duplicate Prevention**: Check for existing refund records

### Testing Refunds

#### Test Scenarios

1. **Admin Full Refund**: Refund entire order amount
2. **Admin Partial Refund**: Refund portion of order amount
3. **Vendor Refund**: Initiate refund from Stripe dashboard
4. **Multiple Refunds**: Process multiple partial refunds
5. **Error Handling**: Test invalid amounts, missing transactions

#### Test Data

- Use Stripe test mode and test payment intents
- Test with various refund amounts and reasons
- Verify webhook delivery and processing
- Check order status and total updates

### Configuration

#### Required Settings

- **Stripe API Keys**: Live/Test secret keys for API access
- **Webhook URL**: Configured in Stripe dashboard
- **Webhook Events**: `charge.refunded` event enabled
- **Refund Permissions**: Admin user permissions for refunds

This completes the comprehensive documentation for Stripe refund processing, covering both admin-initiated and vendor-initiated refund flows.

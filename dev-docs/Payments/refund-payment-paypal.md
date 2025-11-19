## Refund Payment - PayPal

This document covers the PayPal refund process and what happens when refunds are initiated either by admin or by vendor through PayPal. The system handles different refund flows for one-time payments and subscription payments, with separate handlers for admin-initiated and vendor-initiated refunds.

### Refund Flow Overview

1. **Admin-Initiated Refund**: Admin processes refund through FluentCart admin panel
2. **Vendor-Initiated Refund**: Refund initiated directly from PayPal dashboard
3. **Refund Processing**: PayPal API processes the refund request
4. **Webhook Notification**: PayPal sends webhook notification for vendor-initiated refunds
5. **Order Update**: Order status and totals are updated accordingly
6. **Post-Refund Actions**: Stock management, email notifications, and other cleanup

### Key Components

- **Main Refund Class**: `app/Modules/PaymentMethods/PayPal/Refund.php`
- **PayPal Integration**: `app/Modules/PaymentMethods/PayPal/PayPal.php` - `refund()` method
- **Webhook Handler**: `app/Modules/PaymentMethods/PayPal/PayPal.php` - `processPaypalWebhooks()` method
- **One-time Refund Handler**: `app/Modules/PaymentMethods/PayPal/PayPal.php` - `handlePaypalWebhookRefund()` method
- **Subscription Refund Handler**: `app/Modules/Subscriptions/Modules/PayPal/RemoteEventsListener.php` - `handleWebhookRecurringPaymentRefunded()` method
- **IPN Handler**: `app/Modules/PaymentMethods/PayPal/IPN.php`
- **Order Controller**: `app/Http/Controllers/OrderController.php` - `refundOrder()` method
- **Payment Helper**: `app/Services/Payments/PaymentHelper.php` - `triggerRefund()` method

### Admin-Initiated Refund Flow

#### 1. Refund Request Processing

The admin-initiated refund follows the same frontend flow as Stripe, but routes to PayPal-specific handlers:

#### 2. PayPal Refund Method

The `PayPal.refund()` method processes admin-initiated refunds:

```php
public function refund($refundInfo, $orderData)
{
    // 1. Find valid transaction with charge ID
    $transaction = $orderData->transactions
        ->where('id', Arr::get($refundInfo, 'transaction_id'))
        ->whereNotNull('vendor_charge_id')
        ->first();

    if (!$transaction) {
        wp_send_json_error(__('no valid transaction found', 'fluent-cart'), 200);
    }

    // 2. Prepare order data with transaction
    $orderData['transaction'] = $transaction->toArray();

    // 3. Process refund through PayPal API
    $refundInstance = new Refund();
    $refund = $refundInstance->processRefund($refundInfo, $orderData);
    $refund['payment_mode'] = $transaction->payment_mode;

    // 4. Create refund transaction record
    $refundInstance->maybeCreateTransactionRefund($orderData, $refund);

    // 5. Trigger post-refund actions
    $this->triggerAfterRefundProcess($refundInfo, $orderData, $refund);
}
```

### PayPal Refund Processing

#### 1. Refund API Call

The `Refund.processRefund()` method handles the PayPal API interaction:

```php
public function processRefund($refundInfo, $orderData)
{
    // 1. Get capture ID (charge ID)
    $chargeId = Arr::get($orderData, 'transaction.vendor_charge_id');

    if (!$chargeId) {
        wp_send_json_error(__('Please provide a valid charge Id!', 'fluent-cart'));
    }

    // 2. Prepare custom ID for tracking
    $custom_id = 'refund_id_'. Arr::get($refundInfo, 'transaction_id', '') . 
                 '_amount_' . Arr::get($refundInfo, 'amount', 0);

    if (Arr::get($refundInfo, 'cancelSubscription') === 'true') {
        $custom_id .= '_cancel_subscription';
    }

    // 3. Prepare refund data
    $refundData = [
        'custom_id' => $custom_id,
        'amount' => array(
            'value' => floatval(Arr::get($refundInfo, 'amount', 0)),
            'currency_code' => Arr::get($orderData, 'currency', strtoupper(CurrencySettings::get('currency')))
        )
    ];

    // 4. Add refund reason if provided
    $acceptedReason = ['duplicate', 'fraudulent', 'requested_by_customer'];
    $reason = Arr::get($refundInfo, 'reason', false);

    if ($reason && in_array($reason, $acceptedReason)) {
        $refundData['note_to_payer'] = Arr::get($refundInfo, 'reason');
    }

    // 5. Create refund via PayPal API
    $vendorRefund = $this->createRefund($refundData, $chargeId);

    // 6. Validate refund response
    if (is_wp_error($vendorRefund)) {
        $message = $vendorRefund->get_error_message();
        if ($vendorRefund->get_error_message() === 'CAPTURE_FULLY_REFUNDED') {
           $message .= '! Your order is already refunded. Please wait until your order data is updated through webhook.';
        }
        wp_send_json_error(array('message' => $message), 423);
    }

    if (Arr::get($vendorRefund, 'status') !== 'COMPLETED') {
        wp_send_json_error(array('message' => __('Refund not initiated, please try again after reload this page!', 'fluent-cart')), 423);
    }

    $vendorRefund['reason'] = $reason;
    return $vendorRefund;
}
```

#### 2. PayPal API Integration

The `createRefund()` method makes the actual API call:

```php
public function createRefund($refundData, $chargeId)
{
    try {
       return (new API())->makeRequest('payments/captures/'. $chargeId . '/refund', 'v2', 'POST', $refundData);
    } catch (\Exception $e) {
        wp_send_json_error([
            'message' => $e->getMessage(),
        ], 423);
    }
}
```

### Vendor-Initiated Refund Flow (Webhooks)

#### 1. Webhook Processing Entry Point

PayPal sends webhooks to the system, which are processed by the `processPaypalWebhooks()` method:

```php
public function processPaypalWebhooks($event)
{
    $eventType = Arr::get($event, 'event_type', '');
    $data = Arr::get($event, 'resource', []);

    if (empty($eventType) || empty($data)) {
        return;
    }

    // Supported webhook events
    $webhookEvents = [
        'PAYMENT.SALE.COMPLETED',
        'PAYMENT.SALE.REFUNDED',        // Recurring payment refund
        'PAYMENT.CAPTURE.REFUNDED',     // One-time payment refund
        'BILLING.SUBSCRIPTION.CREATED',
        'BILLING.SUBSCRIPTION.ACTIVATED',
        'BILLING.SUBSCRIPTION.CANCELLED',
        'BILLING.SUBSCRIPTION.EXPIRED',
        'BILLING.SUBSCRIPTION.SUSPENDED',
        'BILLING.SUBSCRIPTION.RE-ACTIVATED'
    ];

    if (!in_array($eventType, $webhookEvents)) {
        return;
    }

    // Convert event type to snake case
    $eventType = strtolower(str_replace('.', '_', $eventType));

    // Route to appropriate handler
    if ('payment_sale_refunded' === $eventType) {
        // Handle recurring payment refund
        do_action('fluent_cart/payments/paypal/webhook_payment_sale_refunded', $data);
    } elseif ('payment_capture_refunded' === $eventType) {
        // Handle one-time payment refund
        $this->handlePaypalWebhookRefund($data);
    }
}
```

#### 2. One-Time Payment Refund Handler

The `handlePaypalWebhookRefund()` method processes one-time payment refunds:

```php
public function handlePaypalWebhookRefund($data)
{
    // 1. Validate refund status
    $status = strtolower(sanitize_text_field(Arr::get($data, 'status')));
    if ($status !== 'completed') {
        return;
    }

    // 2. Get parent transaction ID
    $parentTxnId = sanitize_text_field(Arr::get($data, 'capture_id'));
    
    if (!$parentTxnId) {
        // Parse capture_id from links array if not directly available
        foreach ($data['links'] as $link) {
            if ($link['rel'] === 'up') {
                $parentTxnId = basename($link['href']);
                break;
            }
        }
    }

    // 3. Check if refund was initiated from admin (has custom_id)
    $customId = sanitize_text_field(Arr::get($data, 'custom_id', ''));
    if (!$parentTxnId || $customId) {
        return; // Skip admin-initiated refunds
    }

    // 4. Find parent transaction
    $parentTransaction = OrderTransaction::query()
        ->where('vendor_charge_id', $parentTxnId)
        ->with('order')
        ->first();

    if (!$parentTransaction || !$parentTransaction->order) {
        \fluent_cart_add_log('PayPal Refund', 'No transaction or Order found for the refund', 'error', ['log_type' => 'webhook']);
        return;
    }

    // 5. Prepare refund info
    $refundInfo = [
        'transaction_id'     => $parentTransaction->id,
        'amount'             => sanitize_text_field(abs(Arr::get($data, 'amount.value'))),
        'cancelSubscription' => 'false',
        'revokeLicenses'     => apply_filters('fluent_cart/payments/webhook/revoke_licenses_on_onetime_refund', false),
        'reason'             => 'Refunded from PayPal dashboard',
        'availableAmount'    => Helper::toDecimal($parentTransaction->total, false, $parentTransaction->currency, true, true, false),
        'paymentMethod'      => 'paypal',
        'manageStock'        => apply_filters('fluent_cart/payments/webhook/manage_stock_on_onetime_refund', false),
        'payment_method'     => 'paypal',
        'id'                 => Arr::get($data, 'id'),
        'src'                => 'webhook'
    ];

    // 6. Process refund
    $this->refund($refundInfo, $parentTransaction->order, $parentTransaction);
    $this->handleRemoteRefund($refundInfo, $parentTransaction->order, $parentTransaction, $data, 'webhook');
}
```

#### 3. Subscription Payment Refund Handler

The `handleWebhookRecurringPaymentRefunded()` method in `RemoteEventsListener.php` processes subscription refunds:

```php
public function handleWebhookRecurringPaymentRefunded($data)
{
    // 1. Validate refund state
    $state = strtolower(sanitize_text_field(Arr::get($data, 'state')));
    if ($state !== 'completed') {
        return;
    }

    // 2. Get parent transaction ID
    $parentTxnId = sanitize_text_field(Arr::get($data, 'sale_id', ''));
    $customId = sanitize_text_field(Arr::get($data, 'custom_id', ''));

    // 3. Skip admin-initiated refunds (have custom_id)
    if (!$parentTxnId || $customId) {
        return;
    }

    // 4. Find parent transaction
    $parentTransaction = OrderTransaction::query()
        ->where('vendor_charge_id', $parentTxnId)
        ->with('order')
        ->first();

    if (!$parentTransaction || !$parentTransaction->order) {
        \fluent_cart_add_log('PayPal Refund', 'No transaction or Order found for the refund', 'error', ['log_type' => 'webhook']);
        return;
    }

    // 5. Prepare refund info for subscription
    $refundInfo = [
        'transaction_id' => $parentTransaction->id,
        'amount' => sanitize_text_field(abs(Arr::get($data, 'amount.total'))),
        'cancelSubscription' => apply_filters('fluent_cart/payments/webhook/cancel_subscription_on_subscription_refund', false),
        'revokeLicense' => apply_filters('fluent_cart/payments/webhook/revoke_license_on_subscription_refund', false),
        'reason' => 'Refunded from PayPal dashboard',
        'availableAmount' => Helper::toDecimal($parentTransaction->total, false, $parentTransaction->currency, true, true, false),
        'paymentMethod' => 'paypal',
        'manageStock' => apply_filters('fluent_cart/payments/webhook/manage_stock_on_subscription_refund', false),
        'payment_method' => 'paypal',
        'id' => Arr::get($data, 'id'),
        'src' => 'webhook'
    ];

    // 6. Process subscription refund
    (new Paypal())->handleRemoteRefund($refundInfo, $parentTransaction->order, $parentTransaction, $data, 'webhook');
}
```

### Remote Refund Processing

#### Unified Remote Refund Handler

The `handleRemoteRefund()` method provides unified processing for webhook and IPN refunds:

```php
public function handleRemoteRefund($refundInfo, $orderData, $transaction, $data, $src = 'webhook')
{
    $refundInstance = new Refund();
    $orderData['transaction'] = $transaction->toArray();

    // 1. Prepare refund data
    $refund = [
        'custom_id'    => 'refund_id_' . Arr::get($refundInfo, 'transaction_id', '') . '_amount_' . Arr::get($refundInfo, 'amount', 0),
        'amount'       => array(
            'value'         => floatval(Arr::get($refundInfo, 'amount', 0)),
            'currency_code' => Arr::get($refundInfo, 'currency', 'USD')
        ),
        'payment_mode' => $transaction->payment_mode,
        'reason'       => Arr::get($refundInfo, 'reason', 'Refunded from PayPal dashboard'),
        'id'           => Arr::get($refundInfo, 'id'),
    ];

    // 2. Create refund transaction
    $refundInstance->maybeCreateTransactionRefund($orderData, $refund);

    // 3. Trigger post-refund actions
    $this->triggerAfterRefundProcess($refundInfo, $orderData, $refund);

    // 4. Log refund processing
    fluent_cart_add_log('PayPal ' . $src . ' refund processed', $data, 'info', [
        'log_type' => $src,
        'module_type' => 'FluentCart\App\Modules\PaymentMethods\PayPal',
        'module_name' => 'PayPal',
        'module_id' => $orderData->id ?: null,
    ]);
}
```

### Refund Transaction Creation

#### Creating Refund Transaction Record

The `maybeCreateTransactionRefund()` method creates a transaction record for the refund:

```php
public function maybeCreateTransactionRefund($orderData, $refund)
{
    // 1. Check if refund already exists
    if ($this->isRefundExist(Arr::get($refund, 'id'))) {
        return;
    }

    // 2. Create refund transaction record
    $orderTransactions = [
        'order_id' => Arr::get($orderData, 'id'),
        'order_type' => Arr::get($orderData, 'type'),
        'payment_method' => 'paypal',
        'payment_mode' => Arr::get($refund, 'payment_mode', Arr::get($orderData, 'mode')),
        'payment_method_type' => 'card' // applePay, alipay...,
        'transaction_type' => 'refund',
        'vendor_charge_id' => Arr::get($refund, 'id'),
        'status' => Status::TRANSACTION_REFUNDED,
        'currency' => Arr::get($orderData, 'currency'),
        'total' => Helper::toCent(Arr::get($refund, 'amount.value', 0)),
        'meta' => [
            'refund_id' => Arr::get($refund, 'id'),
            'custom_id' => Arr::get($refund, 'custom_id'),
            'reason' => Arr::get($refund, 'reason')
        ],
        'uuid' => md5(time() . wp_generate_uuid4()),
        'created_at' => DateTime::gmtNow(),
        'updated_at' => DateTime::gmtNow(),
    ];

    // 3. Insert transaction record
    OrderTransaction::insert($orderTransactions);

    // 4. Dispatch refund event
    $order = Order::query()->find(Arr::get($orderData, 'id'));
    (new OrderRefunded($order, true))->dispatch();
}
```

#### Refund Existence Check

The `isRefundExist()` method prevents duplicate refund records:

```php
public function isRefundExist($refundId)
{
    return OrderTransaction::query()
        ->where('vendor_charge_id', $refundId)
        ->where('transaction_type', 'refund')
        ->exists();
}
```

### Webhook Event Routing

#### Webhook Events Configuration

PayPal webhook events are configured in the `Webhook.php` class:

```php
const EVENTS = [
    [ "name" => "PAYMENT.SALE.COMPLETED" ],
    [ "name" => "PAYMENT.SALE.REFUNDED" ],      // recurring payment refund
    [ "name" => "PAYMENT.CAPTURE.REFUNDED" ],   // one time payment refund
    [ "name" => "BILLING.SUBSCRIPTION.CREATED" ],
    [ "name" => "BILLING.SUBSCRIPTION.ACTIVATED" ],
    [ "name" => "BILLING.SUBSCRIPTION.SUSPENDED" ],
    [ "name" => "BILLING.SUBSCRIPTION.CANCELLED" ],
    [ "name" => "BILLING.SUBSCRIPTION.EXPIRED" ]
];
```

#### Webhook Verification

Webhooks are verified before processing:

```php
public function processWebhook()
{
    $content_type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    $post_data = file_get_contents('php://input');
    $data = json_decode($post_data, true);
    $eventType = Arr::get($data, 'event_type', '');

    $paymentSettings = self::getPayPalSettings()->get();
    if (strpos($content_type, 'application/json') !== false && $eventType) {
        $mode = (new StoreSettings)->get('order_mode');
        $webhookId = Arr::get($paymentSettings, $mode . '_webhook_id', '');

        if ($this->verifyWebhook($webhookId)) {
            do_action('fluent_cart/payments/process_paypal_webhooks', $data);
            exit(200);
        } else {
            fluent_cart_add_log('PayPal webhook verification failed', $data, 'error', [
                'log_type' => 'webhook',
                'module_type' => 'FluentCart\App\Modules\PaymentMethods\PayPal',
                'module_name' => 'PayPal',
            ]);
            exit(400);
        }
    }
}
```

### Refund Types and Differences

#### One-Time Payment Refunds

**Characteristics:**
- Event: `PAYMENT.CAPTURE.REFUNDED`
- Handler: `handlePaypalWebhookRefund()`
- Transaction Type: Single payment capture
- Subscription Cancellation: Not applicable

**Processing Flow:**
1. Webhook received with `PAYMENT.CAPTURE.REFUNDED` event
2. Extract `capture_id` from webhook data
3. Find original transaction by `vendor_charge_id`
4. Create refund transaction record
5. Update order totals and status

#### Subscription Payment Refunds

**Characteristics:**
- Event: `PAYMENT.SALE.REFUNDED`
- Handler: `handleWebhookRecurringPaymentRefunded()`
- Transaction Type: Recurring payment
- Subscription Cancellation: Optional (configurable)

**Processing Flow:**
1. Webhook received with `PAYMENT.SALE.REFUNDED` event
2. Extract `sale_id` from webhook data
3. Find original subscription transaction
4. Optionally cancel subscription
5. Create refund transaction record
6. Update order totals and status

### Error Handling

#### Common Error Scenarios

**Invalid Capture ID:**
```php
if (!$chargeId) {
    wp_send_json_error(__('Please provide a valid charge Id!', 'fluent-cart'));
}
```

**PayPal API Errors:**
```php
if (is_wp_error($vendorRefund)) {
    $message = $vendorRefund->get_error_message();
    if ($vendorRefund->get_error_message() === 'CAPTURE_FULLY_REFUNDED') {
       $message .= '! Your order is already refunded. Please wait until your order data is updated through webhook.';
    }
    wp_send_json_error(array('message' => $message), 423);
}
```

**Refund Status Validation:**
```php
if (Arr::get($vendorRefund, 'status') !== 'COMPLETED') {
    wp_send_json_error(array('message' => __('Refund not initiated, please try again after reload this page!', 'fluent-cart')), 423);
}
```

**Transaction Not Found:**
```php
if (!$parentTransaction || !$parentTransaction->order) {
    \fluent_cart_add_log('PayPal Refund', 'No transaction or Order found for the refund', 'error', ['log_type' => 'webhook']);
    return;
}
```

### Refund Validation

#### Admin Refund Prevention

The system prevents processing admin-initiated refunds twice by checking for `custom_id`:

```php
$customId = sanitize_text_field(Arr::get($data, 'custom_id', ''));
// custom id means it's a refund from the dashboard so no need to process it again
if (!$parentTxnId || $customId) {
    return;
}
```

#### Duplicate Refund Prevention

The system checks for existing refund records:

```php
public function isRefundExist($refundId)
{
    return OrderTransaction::query()
        ->where('vendor_charge_id', $refundId)
        ->where('transaction_type', 'refund')
        ->exists();
}
```

### Order Status Updates

#### Payment Status Changes

When refunds are processed, order payment status is updated through the `OrderRefunded` event:

- **Full Refund**: `PAYMENT_REFUNDED`
- **Partial Refund**: `PAYMENT_PARTIALLY_REFUNDED`
- **Refund Amount**: Added to `total_refund` field
- **Paid Amount**: Reduced by refund amount

#### Transaction Status Updates

Refund transactions are created with:
- **Status**: `TRANSACTION_REFUNDED`
- **Type**: `refund`
- **Amount**: Refund amount in cents
- **Metadata**: Refund ID, custom ID, reason

### Post-Refund Processing

#### Subscription Management

For subscription refunds, the system can optionally cancel subscriptions:

```php
'cancelSubscription' => apply_filters('fluent_cart/payments/webhook/cancel_subscription_on_subscription_refund', false),
```

#### License Management

The system can revoke licenses on refunds when admin select on refund revoke license from modal:

```php
'revokeLicenses' => apply_filters('fluent_cart/payments/webhook/revoke_licenses_on_onetime_refund', false),
```

#### Stock Management

Stock can be restored on refunds, if admin select on refund manage stock from modal:

```php
'manageStock' => apply_filters('fluent_cart/payments/webhook/manage_stock_on_onetime_refund', false),
```

### Logging and Monitoring

#### Refund Event Logging

The system logs refund events for monitoring:

```php
fluent_cart_add_log('PayPal ' . $src . ' refund processed', $data, 'info', [
    'log_type' => $src,
    'module_type' => 'FluentCart\App\Modules\PaymentMethods\PayPal',
    'module_name' => 'PayPal',
    'module_id' => $orderData->id ?: null,
]);
```

#### Error Logging

Errors are logged for debugging:

```php
\fluent_cart_add_log('PayPal Refund', 'No transaction or Order found for the refund', 'error', ['log_type' => 'webhook']);
```

### Security Considerations

- **Webhook Verification**: Verify webhook authenticity from PayPal
- **Amount Validation**: Ensure refund doesn't exceed paid amount
- **Transaction Validation**: Verify transaction exists and is refundable
- **Duplicate Prevention**: Check for existing refund records
- **Custom ID Tracking**: Prevent double-processing admin refunds

### Configuration

#### Required Settings

- **PayPal API Credentials**: Client ID and Secret for API access
- **Webhook URL**: Configured in PayPal developer dashboard
- **Webhook Events**: Required events enabled (`PAYMENT.CAPTURE.REFUNDED`, `PAYMENT.SALE.REFUNDED`)
- **IPN Settings**: For PayPal Standard payments

#### Webhook URL

```php
public static function getWebhookURL(): string
{
    return site_url() . '/wp-json/fluent-cart/v2/webhook?fct_payment_listener=1&method=paypal';
}
```

### Testing Refunds

#### Test Scenarios

1. **Admin One-Time Refund**: Refund one-time payment from admin
2. **Admin Subscription Refund**: Refund subscription payment from admin
3. **Vendor One-Time Refund**: Initiate refund from PayPal dashboard for one-time payment
4. **Vendor Subscription Refund**: Initiate refund from PayPal dashboard for subscription
5. **Partial Refunds**: Test multiple partial refunds
6. **Error Handling**: Test invalid amounts, missing transactions

#### Test Data

- Use PayPal sandbox environment
- Test with various refund amounts and reasons
- Verify webhook delivery and processing
- Check order status and total updates

### Key Differences from Stripe

1. **Refund Types**: PayPal distinguishes between one-time (`PAYMENT.CAPTURE.REFUNDED`) and subscription (`PAYMENT.SALE.REFUNDED`) refunds
2. **API Endpoints**: Uses `/payments/captures/{id}/refund` endpoint
3. **Custom ID**: Uses custom_id to track admin-initiated refunds
4. **Dual Notification**: Supports both webhooks (modern) and IPN (legacy)
5. **Subscription Handling**: Separate handler for subscription refunds
6. **Status Values**: Uses `COMPLETED` status instead of `succeeded`

This completes the comprehensive documentation for PayPal refund processing, covering both one-time and subscription payment refunds through admin and vendor-initiated flows.

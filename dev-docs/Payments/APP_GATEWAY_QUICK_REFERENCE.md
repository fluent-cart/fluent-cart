# App::gateway() Quick Reference Guide

## Overview

FluentCart provides multiple ways to access payment gateways through the `App::gateway()` facade and `GatewayManager`. This guide covers all available patterns and usage examples.

## Access Patterns

### 1. App Facade Methods

```php
// Get gateway manager instance
$manager = App::gateway();

// Get specific gateway directly
$stripe = App::gateway('stripe');
$paypal = App::gateway('paypal');
$razorpay = App::gateway('razorpay');

// Alternative syntax
$manager = App::getInstance('gateway');
$stripe = App::getInstance('gateway')->get('stripe');
```

### 2. GatewayManager Methods

```php
// Traditional approach
$manager = GatewayManager::getInstance();
$stripe = $manager->get('stripe');

// Direct approach (recommended)
$stripe = GatewayManager::getInstance('stripe');

// Static convenience method
$stripe = GatewayManager::gateway('stripe');
```

## Gateway Operations

### Basic Gateway Access

```php
// Check if gateway exists
if (App::gateway('stripe')) {
    echo "Stripe is available";
}

// Check if gateway is enabled
$stripe = App::gateway('stripe');
if ($stripe && $stripe->isEnabled()) {
    echo "Stripe is active and ready";
}

// Check gateway features
if ($stripe && $stripe->has('subscriptions')) {
    echo "Stripe supports subscriptions";
}
```

### Gateway Information

```php
// Get gateway metadata
$stripe = App::gateway('stripe');
$meta = $stripe->getMeta();
// Returns: ['title' => 'Credit or Debit Card', 'route' => 'stripe', ...]

// Get specific meta value
$title = $stripe->getMeta('title');
$route = $stripe->getMeta('route');

// Get gateway settings
$settings = $stripe->getSettings();
$isActive = $settings->get('is_active');
$mode = $settings->getMode(); // 'test' or 'live'
```

### Manager Operations

```php
// Get all registered gateways
$allGateways = App::gateway()->all();

// Get only enabled gateways
$enabledGateways = App::gateway()->enabled();

// Get gateway names
$gatewayNames = App::gateway()->names();
// Returns: ['stripe', 'paypal', 'cod', 'razorpay']

// Check if gateway is registered
if (App::gateway()->get('stripe')) {
    echo "Stripe is registered";
}

// Alternative check
if (GatewayManager::has('stripe')) {
    echo "Stripe is registered";
}
```

## Subscription Module Access

### Direct Subscription Access

```php
// Access subscription module directly
$stripeSubscriptions = App::gateway('stripe')->subscriptions;
$paypalSubscriptions = App::gateway('paypal')->subscriptions;

// Check if subscription module exists
if (App::gateway('stripe')->subscriptions) {
    echo "Stripe subscriptions available";
}
```

### Subscription Operations

```php
// Fetch subscription details
$stripe = App::gateway('stripe');
if ($stripe && $stripe->subscriptions) {
    $stripe->subscriptions->fetchSubscription($data, $order, $subscription);
}

// Cancel subscription
$paypal = App::gateway('paypal');
if ($paypal && $paypal->subscriptions) {
    $paypal->subscriptions->cancelSubscription($data, $order, $subscription);
}

// Update payment method
$stripe = App::gateway('stripe');
if ($stripe && $stripe->subscriptions) {
    $stripe->subscriptions->cardUpdate($data, $subscriptionId);
}

// Pause/Resume subscription
$stripe->subscriptions->pauseSubscription($data, $order, $subscription);
$stripe->subscriptions->resumeSubscription($data, $order, $subscription);
```

## Payment Processing

### Process Payments

```php
// Process payment
$gateway = App::gateway('stripe');
if ($gateway && $gateway->isEnabled()) {
    try {
        $gateway->makePayment($orderHelper);
    } catch (\Exception $e) {
        // Handle payment error
        error_log('Payment failed: ' . $e->getMessage());
    }
}
```

### Handle Refunds

```php
// Process refund
$gateway = App::gateway('stripe');
if ($gateway && $gateway->has('refund')) {
    $gateway->refund($refundInfo, $order);
}
```

### Handle Webhooks

```php
// Process webhook
$gateway = App::gateway('stripe');
if ($gateway && $gateway->has('webhook')) {
    $gateway->handleWebhook($payload);
}
```

## Practical Examples

### Dynamic Gateway Selection

```php
function processPaymentForGateway($gatewayName, $orderHelper) {
    $gateway = App::gateway($gatewayName);
    
    if (!$gateway) {
        throw new \Exception("Gateway '{$gatewayName}' not found");
    }
    
    if (!$gateway->isEnabled()) {
        throw new \Exception("Gateway '{$gatewayName}' is not active");
    }
    
    return $gateway->makePayment($orderHelper);
}

// Usage
processPaymentForGateway('stripe', $orderHelper);
processPaymentForGateway('paypal', $orderHelper);
```

### Gateway Feature Detection

```php
function getGatewaysWithFeature($feature) {
    $gateways = [];
    
    foreach (App::gateway()->all() as $name => $gateway) {
        if ($gateway->has($feature)) {
            $gateways[$name] = $gateway;
        }
    }
    
    return $gateways;
}

// Get all gateways that support subscriptions
$subscriptionGateways = getGatewaysWithFeature('subscriptions');

// Get all gateways that support refunds
$refundGateways = getGatewaysWithFeature('refund');
```

### Admin Interface Integration

```php
function getGatewayOptionsForAdmin() {
    $options = [];
    
    foreach (App::gateway()->all() as $name => $gateway) {
        $meta = $gateway->getMeta();
        $options[] = [
            'value' => $name,
            'label' => $meta['title'],
            'enabled' => $gateway->isEnabled(),
            'features' => [
                'subscriptions' => $gateway->has('subscriptions'),
                'refunds' => $gateway->has('refund'),
                'webhooks' => $gateway->has('webhook')
            ]
        ];
    }
    
    return $options;
}
```

### Error Handling Patterns

```php
function safeGatewayOperation($gatewayName, $operation, ...$args) {
    try {
        $gateway = App::gateway($gatewayName);
        
        if (!$gateway) {
            return ['error' => "Gateway '{$gatewayName}' not found"];
        }
        
        if (!$gateway->isEnabled()) {
            return ['error' => "Gateway '{$gatewayName}' is not active"];
        }
        
        if (!method_exists($gateway, $operation)) {
            return ['error' => "Operation '{$operation}' not supported"];
        }
        
        $result = $gateway->$operation(...$args);
        return ['success' => true, 'data' => $result];
        
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// Usage
$result = safeGatewayOperation('stripe', 'makePayment', $orderHelper);
if (isset($result['error'])) {
    // Handle error
    echo "Error: " . $result['error'];
} else {
    // Handle success
    echo "Payment processed successfully";
}
```

## Registration Patterns

### Register New Gateway

```php
// In GlobalPaymentHandler or similar
public function init() {
    add_action('init', function () {
        $gateway = GatewayManager::getInstance();
        
        // Register gateways
        $gateway->register('stripe', new Stripe());
        $gateway->register('paypal', new PayPal());
        $gateway->register('razorpay', new Razorpay());
        
        // Hook for third-party registrations
        do_action('fluent_cart/register_payment_methods');
    });
}
```

### Third-party Registration

```php
// In your plugin
add_action('fluent_cart/register_payment_methods', function() {
    $gateway = App::gateway();
    $gateway->register('my_gateway', new MyGateway());
});
```

## Best Practices

### 1. Always Check Gateway Existence

```php
// ✅ Good
$gateway = App::gateway('stripe');
if ($gateway && $gateway->isEnabled()) {
    // Use gateway
}

// ❌ Bad
$gateway = App::gateway('stripe');
$gateway->makePayment($orderHelper); // Could throw error if gateway doesn't exist
```

### 2. Use Feature Detection

```php
// ✅ Good
if ($gateway && $gateway->has('subscriptions')) {
    $gateway->subscriptions->cancelSubscription($data, $order, $subscription);
}

// ❌ Bad
$gateway->subscriptions->cancelSubscription($data, $order, $subscription); // Could fail
```

### 3. Handle Errors Gracefully

```php
// ✅ Good
try {
    $gateway = App::gateway('stripe');
    if ($gateway && $gateway->isEnabled()) {
        $gateway->makePayment($orderHelper);
    } else {
        throw new \Exception('Stripe gateway not available');
    }
} catch (\Exception $e) {
    // Log error and show user-friendly message
    fluent_cart_add_log('Payment Error', $e->getMessage(), 'error');
    wp_send_json_error(['message' => 'Payment processing failed']);
}
```

### 4. Use Consistent Patterns

```php
// ✅ Consistent pattern for all gateways
function processGatewayPayment($gatewayName, $orderHelper) {
    $gateway = App::gateway($gatewayName);
    
    if (!$gateway) {
        throw new \Exception("Gateway not found: {$gatewayName}");
    }
    
    if (!$gateway->isEnabled()) {
        throw new \Exception("Gateway not active: {$gatewayName}");
    }
    
    return $gateway->makePayment($orderHelper);
}
```

This quick reference covers all the essential patterns for using `App::gateway()` and `GatewayManager` in FluentCart. Use these examples as templates for your payment gateway integrations.

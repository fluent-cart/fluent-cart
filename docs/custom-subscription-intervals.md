# Custom Subscription Intervals

Extend FluentCart's default subscription intervals with custom billing cycles.

## 1. Add Interval Option (Required)

**This is the most important filter** - it adds your custom interval to the subscription options.

**Each option must contain three required fields:**
- `label` - Display name shown in dropdown (required)
- `value` - Unique key saved in database (required)
- `map_value` - Formal/readable version of the interval (required)

```php
/**
 * Extend interval options
 * 
 * @param array $intervalOptions Existing interval options
 * @return array Modified interval options
 */
add_filter('fluent_cart/available_subscription_interval_options', function ($intervalOptions) {
    return array_merge($intervalOptions, [
        [
            'label' => __('Every 10th day', 'fluent-cart'),      // Display name
            'value' => 'every_tenth_day',                        // Database key
            'map_value' => '10th Day',                           // Readable format
        ],
    ]);
});
```

## 2. Define Equivalent Days

```php
/**
 * Convert interval to days
 * 
 * @param int   $days Default days
 * @param array $args Contains 'interval' key
 * @return int Number of days
 */
add_filter('fluent_cart/subscription_interval_in_days', function($days, $args) {
    $interval = $args['interval'];
    
    if ($interval == 'every_tenth_day') {
        return 10;
    }
    
    return $days;
}, 10, 2);
```

## 3. Set Max Trial Days (Optional)

```php
/**
 * Maximum trial days for custom interval
 * 
 * @param int   $days Default max trial days
 * @param array $args Contains: repeat_interval, existing_trial_days, interval_in_days
 * @return int Maximum allowed trial days
 */
add_filter('fluent_cart/max_trial_days_allowed', function($days, $args) {
    $interval = $args['repeat_interval'];
    $existingTrialDays = $args['existing_trial_days'];
    $intervalInDays = $args['interval_in_days'];
    
    if ($interval == 'every_tenth_day') {
        return min($existingTrialDays + $intervalInDays, 10);
    }
    
    return $days;
}, 10, 2);
```

## 4. Configure Gateway Billing Period

**Note:** Only use this hook if you're using FluentCart's built-in gateways or third-party gateway addons. If you own the payment gateway code, handle the billing period directly in your gateway processor instead.

```php
/**
 * Format billing period for payment gateways
 * 
 * @param array $billingPeriod Contains: interval_unit, interval_frequency
 * @param array $args Contains: payment_method, subscription_interval
 * @return array Modified billing period
 */
add_filter('fluent_cart/subscription_billing_period', function ($billingPeriod, $args) {
    $paymentMethod = $args['payment_method'];
    $subscriptionInterval = $args['subscription_interval'];
    
    if ($paymentMethod == 'stripe') {
        if ($subscriptionInterval == 'every_tenth_day') {
            $billingPeriod['interval_unit'] = 'day';
            $billingPeriod['interval_frequency'] = 10;
        }
    }
    
    if ($paymentMethod == 'paypal') {
        if ($subscriptionInterval == 'fortnightly') {
            $billingPeriod['interval_unit'] = 'week';
            $billingPeriod['interval_frequency'] = 2;
        }
    }
    
    return $billingPeriod;
}, 10, 2);
```

## Gateway Compatibility

- **Stripe**: Supports `day`, `week`, `month`, `year` with any positive frequency
- **PayPal**: Supports `day`, `week`, `month`, `year` with frequency limitations
- **Custom Gateways**: Format billing period directly in your gateway processor


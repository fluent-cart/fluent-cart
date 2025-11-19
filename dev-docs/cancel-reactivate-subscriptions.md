# Reactivate canceled subscriptions


// Fluent Forms Pro Add-On - Unlimited Sites Annual License
```php
$sameLicenseParchases = [
    '6493393', // Initial Purchase Subscription May 22, 2021 => #15839
    '6747936', // Renewal Subscription May 22, 2022 => #15839
    '7014495', // Existing Subscription Cancelled. New Sub at: July 28, 2023 - #44565
    '7289145', // Existing Subscription Cancelled; Manually Renewed at: July 24, 2024 # 60315
    '7547212' // Existing Subscription Cancelled; Manually Renewed at: July 14, 2025 # 80490
];
```

# Payment 1.
'6493393', // Initial Purchase Subscription May 22, 2021 => #15839
`fct_orders`
    New order created, order id ->#1
`fct_subscriptions`
    New subscription created #1
`fct_transactions`
    New transaction created
`license`
    New license created #1

# Payment 2.
'6747936', // Renewal Subscription May 22, 2022 => #15839
`fct_orders`
    // New child order created, OrderId -> #2
    Parent Order Id -> #1

`fct_subscriptions` 
    // Update existing subscriptions #1

    'id' => 1
    'recurring_total'
    'bill_times'
    'bill_count'
    'expire_at'
    'trial_ends_at'
    'canceled_at'
    'restored_at'
    'collection_method'
    'next_billing_date'
    'trial_days'
    'vendor_customer_id'
    'vendor_plan_id'
    'vendor_subscription_id'
    'status'
    'original_plan'
    'vendor_response'
    'current_payment_method'


`fct_transactions`
    // New transaction created based on amount paid

`license`
    // Update existing license #1
    'expiration_date'
    'status'


# Payment 3
'7014495', // Existing Subscription Cancelled. 
New Sub at: July 28, 2023 - #44565

`fct_orders`
    New order created, order id ->#3
`fct_subscriptions`
    New subscription created #2
`fct_transactions`
    New transaction created
`license`
    New license created #2


# Payment 4
'7289145', // Existing Subscription Cancelled; Manually reactivated at: July 24, 2024 # 60315





## Order Table

**status:**
on-hold/processing/completed/canceled/failed

**parent_id:**
If order is subscription renewal then the first orderId is the parentId.
On plan change , base orderId is the parent orderId of the new order

**receipt_number**
An unique number to use in invoice_no

**invoice_no**
An unique identifier maybe a hash / id of the order

**fulfillment_type**
physical / digital

**type**
payment/subscription/renewal/...

**mode**
test / live

**shipping_status**
unshipped / shipped / delivered / unshippable

**customer_id**
customer id of fct_customer table

**payment_method**
stripe / paypal /....

**payment_status**
paid / pending /partially-paid / refunded /partially-refunded / failed

**payment_method_title**
Name of the Payment method

**currency**
Currency code like USD / EURO / .....

**subtotal**
Sum of all (quantity * unit_price) of orderItems

**discount_tax**
Discount of applied tax if any

**manual_discount_total**
If admin added any discount from admin area without coupon

**coupon_discount_total**
Only discount amount using coupon from anywhere

**shipping_tax**
If shipping have any tax amount

**shipping_total**
Shipping total cost

**tax_total**
Total tax amount for this order

**total_amount**
= subtotal - (manual_discount_total + coupon_discount_total) + shipping_total + shipping_tax + tax_total

**total_paid**
Total amount paid by customer

**total_refund**
Total amount refunded

**rate**
default 1

**config json NULL**

    any plan upgrade data
    -> Old Order:
    - config: { upgraded_to: 1234 }

    -> New Order
    - config: { upgraded_from: 1234 }

### Case 1: Normal Purchase

Someone purchased Product X - $100.00 x 2 and Product Y - $50.00 x 1

```php

$orderItems = [
    [
        'order_id' => 1,
        'post_id' => PRODUCT_ID,
        'object_id' => VARIATION_ID,
        'title' => 'Product X',
        'title'            => VARIATION_TITLE, // If it's a variation then the title is the variation title
        'fulfillment_type' => 'physical/digital',
        'payment_type' => 'payment/subscription/renewal',
        'quantity' => 2,
        'unit_price' => 100.00,
        'subtotal' => quantity * unit_price,
        'tax_amount' => 0,
        'shipping_charge' => CALCULATED_NUMBER_FROM_SHIPPING_CLASS, // BIG ITEM / SMALL ITEM / Soft ITEM Etc
        'discount_total' => 0,
        'line_total' => subtotal - discount_total + tax_amount, // DO NOT ADD SHIPPING CHARGE HERE
        'refund_total' => 0,
    ]
];

$order = [
     'status' => 'on-hold/processing/completed/canceled/failed',
     'customer_id' => CUSTOMER_ID,
     'type' => 'payment/subscription/renewal',
     'parent_id' => null, // If order is subscription renewal then the first orderId is the parentId.
     'receipt_number' => '', // sequential number
     'invoice_no' => 'PREFIX-'.receipt_number,
     'fulfillment_type' => 'physical/digital', // physical or digital
     'mode' => 'test/live',
     'shipping_status' => 'unshipped / shipped / delivered / unshippable', // NULL if it's a digital product
     'payment_method' => 'stripe/paypal/...',
     'payment_status' => 'paid / pending /partially-paid / refunded /partially-refunded / failed',
     'payment_method_title' => 'Stripe / PayPal',
     'currency' => 'USD', // 3 letter currency code UPPERCASE
     'subtotal' => SUM(orderItems.subtotal),
     'discount_tax' => 0, // decide later
     'manual_discount_total' => 0, // it's a custom discount added by admin or programmatically. It's not connected with any coupon or product items
     'coupon_discount_total' => SUM(orderItems.discount_total), // it's a discount applied by coupon
     'shipping_tax' => 'ETA',
     'shipping_total' => SUM(orderItems.shipping_charge) + DELIVARY_CHARGE_FROM_ZONE_METHOD, // DELIVARY_CHARGE is from shipping ZONE not CLASS
     'tax_total' => SUM(orderItems.tax_amount) + discount_tax + shipping_tax, // ETA
     'total_amount' => subtotal + shipping_total + tax_total - (manual_discount_total + coupon_discount_total)
     'total_paid' => 0,// The collected amount from customer. 
     'total_refund' => TOTAL_REFUND_AMOUNT, // The total amount refunded to customer
     'uuid' => '' // unique identifier for the order, can be a UUID
];

$transaction = [
    'order_id' => ORDER_ID,
    'order_type' => 'payment|subscription|renewal', // it's the same as order.type
    'transaction_type' => 'charge|refund ', // charge default
    'subscription_id' => SUBSCRIPTION_ID, // If it's a subscription order then this is the subscription id
    'card_last_4' => '',
    'card_brand' => '',
    'vendor_charge_id' => '', // Vendor charge id from payment gateway
    'payment_method' => 'paypal/stripe',
    'payment_mode' => 'live/test',
    'payment_method_type' => 'card', // card / apple_pay / google_pay / bank_transfer / cash_on_delivery
    'status' => 'succeeded/pending/canceled/failed', // transaction_type == refund ? refunded
    'currency' => 'USD',
    'total' => 0, // The total amount paid by customer
];

```

** Calculating Due (Collect):** `total_amount - total_paid - total_refund` < 0
** Calculating OverPayment (Require Refund):** `total_amount - total_paid - total_refund` > 0

### Case 2: Normal Payment (With Shipping, Tax, Discount, Coupon, Refund)

Someone purchased Product X - $100.00 x 2 and Product Y - $50.00 x 2 and applyied to 20% discount and 5% tax and
shipping charge is $10.00 (Flat from zone -> Method) and later he took $50 refund from the order.

```php

$orderItems = [
    [
        'order_id' => 1,
        'post_id' => PRODUCT_ID,
        'object_id' => VARIATION_ID,
        'title' => 'Product X',
        'fulfillment_type' => 'physical',
        'payment_type' => 'payment',
        'quantity' => 2,
        'unit_price' => 100.00,
        'subtotal' => quantity * unit_price = 200.00,
        'discount_total' => 40.00, // 20% of 200.00
        'tax_amount' => 8.00, // (SUBTOTAL - discount_total) * 5% = 8.00
        'shipping_charge' => CALCULATED_NUMBER_FROM_SHIPPING_CLASS, // Directly attached to the product item. Only apply if it's a physical product
        'line_total' => 160, //200.00 - 40.00 + 0.00, //subtotal - discount_total + tax_amount, // DO NOT ADD SHIPPING CHARGE HERE
        'refund_total' => 0,
    ],
    [
        'order_id' => 1,
        'post_id' => PRODUCT_ID,
        'object_id' => VARIATION_ID,
        'title' => 'Product Y',
        'fulfillment_type' => 'physical',
        'payment_type' => 'payment',
        'quantity' => 2,
        'unit_price' => 50.00,
        'subtotal' => quantity * unit_price = 100.00,
        'discount_total' => 20.00, // 20% of 100.00
        'tax_amount' => 4, // (SUBTOTAL - discount_total) * 5% = 4.00
        'shipping_charge' => CALCULATED_NUMBER_FROM_SHIPPING_CLASS, // Directly attached to the product item. Only apply if it's a physical product
        'line_total' => 80,//100.00 - 20.00 - 0.00, // subtotal - discount_total + tax_amount, // DO NOT ADD SHIPPING CHARGE HERE
        'refund_total' => 0,
    ]
];

$order = [
     'status' => 'completed',
     'parent_id' => null, // If order is subscription renewal then the first orderId is the parentId.
     'receipt_number' => '1234', // sequential number
     'invoice_no' => 'PREFIX-1234',
     'fulfillment_type' => 'physical', // physical or digital
     'type' => 'payment',
     'mode' => 'live',
     'shipping_status' => 'shipped', // NULL if it's a digital product
     'customer_id' => CUSTOMER_ID,
     'payment_method' => 'stripe',
     'payment_status' => 'paid',
     'payment_method_title' => 'Stripe',
     'currency' => 'USD', // 3 letter currency code UPPERCASE
     'subtotal' => 300.00, // SUM(orderItems.subtotal),
     'discount_tax' => 0, // decide later
     'manual_discount_total' => 0, // it's a custom discount added by admin or programmatically. It's not connected with any coupon or product items
     'coupon_discount_total' => 60.00, //SUM(orderItems.discount_total), // it's a discount applied by coupon
     'shipping_tax' => 'ETA',
     'shipping_total' => 10.00,//SUM(orderItems.shipping_charge) + DELIVARY_CHARGE_FROM_ZONE_METHOD, // DELIVARY_CHARGE_FROM_ZONE_METHOD is from shipping_zone -> Method
     'tax_total' => 12.00, //SUM(orderItems.tax_amount) + discount_tax + shipping_tax, // ETA NEED TO REVISIT after tax implementation
     'total_amount' => 300 + 10 + 12 - ( 60 + 0 ) = 262,//subtotal + shipping_total + tax_total - (manual_discount_total + coupon_discount_total)
     'total_paid' => 262,// The collected amount from customer. 
     'total_refund' => 0, // The total amount refunded to customer
     'uuid' => '' // unique identifier for the order, can be a UUID
];


$transaction = [
    'order_id' => ORDER_ID,
    'order_type' => 'payment',
    'transaction_type' => 'charge', // charge default
    'card_last_4' => '4242',
    'card_brand' => 'VISA',
    'vendor_charge_id' => 'PI_kasjhdkashdkah', // Vendor charge id from payment gateway
    'payment_method' => 'stripe',
    'payment_mode' => 'live',
    'payment_method_type' => 'card', // card / apple_pay / google_pay / bank_transfer / cash_on_delivery
    'status' => 'paid',
    'currency' => 'USD',
    'total' => 262, // The total amount paid by customer
];

```

**Data Changes After Refund:**
Refund $50 from the order

- max refunnd total: 160 + 80 = 240

```php
$order['total_refund'] = 50.00; // The total amount refunded to customer
$order['status'] = 'partially-refunded'; //

$orderItems[0]['refund_total'] = 33.33; // 50 * (160 / 240)
$orderItems[1]['refund_total'] = 16.66 + 0.01; // 50 * (80 / 240)  -> From initial calculation add 0.01 (the fractional diff)
$parentTransaction->meta['refunded_total'] = 50.00; // The total amount refunded to customer

`fct_order_transactions`
$returnTransaction = [
    'order_id' => ORDER_ID,
    'order_type' => 'payment', // match with order.order_type
    'transaction_type' => 'refund', // charge default
    'card_last_4' => '4242',
    'card_brand' => 'VISA',
    'vendor_charge_id' => 'RE_kasjhdkashdkah', // Vendor refund id from payment gateway
    'payment_method' => 'stripe',
    'payment_mode' => 'live',
    'payment_method_type' => 'card', // card / apple_pay / google_pay / bank_transfer / cash_on_delivery
    'status' => 'refunded',
    'currency' => 'USD',
    'total' => 50.00, // The total amount paid by customer
];
```

### Case 3: Someone purchased a subscription ($100 / Year) with 20% discount

```php
`fct_order_items`
$orderItems = [
    [
        'order_id' => 30,
        'post_id' => PRODUCT_ID,
        'fulfillment_type' => 'digital',
        'quantity' => 1,
        'payment_type' => 'subscription',
        'object_id' => VARIATION_ID,
        'unit_price' => 100.00,
        'discount_total' => 20.00,
        'line_total' => 80.00, // unit_price - discount_total
        'refund_total' => 0,
    ],
    [
        'order_id' => 30,
        'post_id' => PRODUCT_ID,
        'fulfillment_type' => 'digital',
        'quantity' => 1,
        'payment_type' => 'adjustment',
        'object_id' => VARIATION_ID,
        'unit_price' => 80,
        'discount_total' => 0,
        'line_total' => 80.00, // unit_price - discount_total
        'refund_total' => 0,
    ]
 ];



`fct_orders`
$order = [
    'status' => 'completed',
    'fulfillment_type' => 'digital',
    'type' => 'subscription',
    'mode' => 'live',
    'customer_id' => CUSTOMER_ID,
    'payment_method' => 'stripe',
    'payment_status' => 'paid',
    'payment_method_title' => 'Stripe',
    'subtotal' => 100,
    'coupon_discount_total' => 20,
    'total_amount' => 80,
    'total_paid' => 80,
    'currency' => 'USD',
];

`fct_subscriptions`
$subscriptions = [
    "id" => 10,
    "customer_id" => FLUENTCART_CUSTOMER_ID,
    "parent_order_id" => ORDER_ID,
    "product_id" => PRODUCT_ID,
    "item_name" => 'Product X',
    "variation_id" => VARIATION_ID,
    "billing_interval" => 'year',
    "signup_fee" => 0,
    "initial_tax_total" => 0,
    "recurring_amount" => 100,
    "recurring_tax_total" => 0,
    "recurring_total" => 100,
    "bill_times" => 0,
    "bill_count" => 1,
    "expire_at" => NULL,
    "trial_ends_at" => NULL,
    "canceled_at" => NULL,
    "restored_at" => NULL,
    "collection_method" => 'automatic',
    "next_billing_date" => AFTER_FIRST_PERIOD_ENDS, // 1 year from today's date
    "trial_days" => "365",
    "vendor_customer_id" => 'cus_1234567890',
    "vendor_plan_id" => 'fluent_cart_124617_recurring_30000_yearly_b',
    "vendor_subscription_id" => 'sub_1Rl5DYIyGOUknw8lgHujitWK',
    "status" => 'trialing',
    "original_plan" => '',
    "vendor_response" => '/{}/' // response coming from vendor api like stripe, paypal,
    "current_payment_method" => 'stripe',
    "config" => '{currency: 'usd'}', // JSON format, maybe used to store other infos
]


`fct_transactions`
$transactions = [
 [
    "id" => 1,
    "order_id" => ORDER_ID,
    "order_type" => 'subscription',
    "transaction_type" => 'charge', //should update to charge, currently subscriptions
    "subscription_id" => 10,
    "card_last_4" => '4242',
    "card_brand" => 'VISA',
    "vendor_charge_id" => 'pi_3Rl5DZIyGOUknw8l2fozOYUa',
    "payment_method" => 'stripe',
    "payment_mode" => 'live',
    "payment_method_type" => 'card', // should update to apple pay, alipay etc, currently online
    "status" =>  'completed',
    "currency" => 'USD',
    "total" => 80,
    "rate" => 1,
    "uuid" => 'Uuysgjfkijsdggsu8s7d8ysh9d8',
    "meta" => null,
]
```

Subscription:

```
{
     recurring_amount: 100, // 20% discount for first year
     interval: 'yearly',
}
{
    interval: 'yearly',
    setup_fee: 80,
    trial: '7 days',
    recurring_amount: 100
},
{
    interval: 'yearly',
    setup_fee: 20,
    recurring_amount: 100
},
{
    interval: 'yearly',
    setup_fee: 0,
    trial: '7 days',
    recurring_amount: 100
}
```

```
{
    recurring_amount: 100,
    interval: 'yearly',
}
===
{
    setup_fee: 100,
    trial: '1 year',
    recurring_amount: 100,
    interval: 'yearly',
}
```

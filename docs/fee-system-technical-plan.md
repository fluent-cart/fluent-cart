# FluentCart Fee System — Technical Implementation Plan

> **Status:** Planning (prerequisite for Dynamic Pricing addon)
> **Priority:** High — must be completed before Dynamic Pricing development
> **Scope:** Core FluentCart changes only
> **Impact:** Database migration + ~30 files modified

---

## Table of Contents

1. [Problem Statement](#1-problem-statement)
2. [Design Decisions](#2-design-decisions)
3. [Database Changes](#3-database-changes)
4. [Fee Data Structures](#4-fee-data-structures)
5. [Data Flow](#5-data-flow)
6. [PHP Hook System for Fees](#6-php-hook-system-for-fees)
7. [Cart-Level Fee Handling](#7-cart-level-fee-handling)
8. [Checkout Flow Changes](#8-checkout-flow-changes)
9. [Order Creation Changes](#9-order-creation-changes)
10. [Display: Where Fees Appear](#10-display-where-fees-appear)
11. [File-by-File Change List](#11-file-by-file-change-list)
12. [Implementation Order](#12-implementation-order)
13. [Edge Cases & Testing](#13-edge-cases--testing)

---

## 1. Problem Statement

FluentCart currently has no concept of **fees** (additional charges/surcharges). The system only supports:

| Column | Purpose |
|--------|---------|
| `subtotal` | Sum of item prices × quantities |
| `coupon_discount_total` | Coupon-applied discounts |
| `manual_discount_total` | Admin-applied discounts |
| `shipping_total` | Shipping charges |
| `tax_total` / `shipping_tax` | Tax amounts |

**Missing:** A generic mechanism for addons to attach fees to a cart/order — processing fees, handling fees, small-order surcharges, payment gateway surcharges, environmental levies, etc.

### Current `total_amount` Formula

```
total_amount = subtotal
             - coupon_discount_total
             - manual_discount_total
             + shipping_total
             + tax_total (if exclusive)
             + shipping_tax (if exclusive)
```

### Target `total_amount` Formula

```
total_amount = subtotal
             - coupon_discount_total
             - manual_discount_total
             + fee_total                    ← NEW
             + shipping_total
             + tax_total (if exclusive)     ← now includes tax on fees
             + shipping_tax (if exclusive)
```

---

## 2. Design Decisions

### 2.1 Fees as Order Items (`payment_type = 'fee'`)

**Decision: Store fees as rows in `fct_order_items` with `payment_type = 'fee'`, plus a cached `fee_total` column on `fct_orders` for fast aggregation.**

This mirrors the existing `signup_fee` pattern — signup fees are already stored as separate order items:

```
Existing pattern (signup_fee):
┌────────────────────────────────────────────────────────────────┐
│ fct_order_items                                                │
├──────────┬─────────────┬──────┬────────────┬──────────────────┤
│ id       │ title       │ qty  │ unit_price │ payment_type     │
├──────────┼─────────────┼──────┼────────────┼──────────────────┤
│ 1        │ Pro Plan    │ 1    │ 2999       │ subscription     │
│ 2        │ Setup Fee   │ 1    │ 999        │ signup_fee       │  ← child item
└──────────┴─────────────┴──────┴────────────┴──────────────────┘

New pattern (fee):
┌────────────────────────────────────────────────────────────────┐
│ fct_order_items                                                │
├──────────┬─────────────────┬──────┬────────────┬──────────────┤
│ id       │ title           │ qty  │ unit_price │ payment_type │
├──────────┼─────────────────┼──────┼────────────┼──────────────┤
│ 1        │ Blue T-Shirt    │ 2    │ 2999       │ onetime      │
│ 2        │ Processing Fee  │ 1    │ 450        │ fee          │  ← fee item
│ 3        │ Handling Fee    │ 1    │ 200        │ fee          │  ← fee item
└──────────┴─────────────────┴──────┴────────────┴──────────────┘
```

### 2.2 Why Order Items (Not Separate Meta)

| Approach | Tax | Refunds | Display | Reports | Complexity |
|----------|-----|---------|---------|---------|------------|
| **Order items (chosen)** | Automatic — TaxModule calculates per item | Automatic — proportional distribution includes fees | Natural — items appear in order item lists | JOINable — real columns, no JSON parsing | Low — follows existing `signup_fee` pattern |
| Order meta (JSON) | Manual — special handling needed | Manual — separate refund logic | Manual — inject into every display point | Hard — must parse JSON in queries | Medium |
| Separate table | Manual — new tax integration | Manual — new refund integration | Manual — new queries everywhere | Easy — dedicated table | High — new model, migrations, queries |

The order items approach wins on every dimension because FluentCart's existing infrastructure (tax, refunds, display) already operates on order items.

### 2.3 Key Design Rules

1. **Fees are always positive.** Use discounts (not negative fees) to reduce prices.
2. **Each fee is one order item** with `payment_type = 'fee'`. Multiple fees = multiple rows.
3. **`fee_total` column on orders** is a cached sum for fast queries. It's always the sum of fee item subtotals.
4. **Fees have no `post_id` or `object_id`** — they're not tied to a product (use `0` or `NULL`).
5. **Fees CAN be taxable** — the `taxable` flag controls whether TaxModule calculates tax on the fee item.
6. **Fees CAN be refunded** — they participate in the existing proportional refund distribution.
7. **Fees live in `cart.checkout_data.fees` during checkout** — they become order items only at order creation.

### 2.4 `payment_type` Values (After This Change)

| Value | Description | Has Product? | Existing/New |
|-------|-------------|-------------|-------------|
| `onetime` | Regular one-time product | Yes | Existing |
| `subscription` | Recurring subscription product | Yes | Existing |
| `signup_fee` | Signup fee for subscription | Yes (parent's) | Existing |
| `bundle` | Bundle parent item | Yes | Existing |
| `adjustment` | Plan change proration | Yes | Existing |
| **`fee`** | **Surcharge / additional charge** | **No** | **NEW** |

---

## 3. Database Changes

### 3.1 Migration: Add `fee_total` to `fct_orders`

```php
<?php

namespace FluentCart\Database\Migrations;

class AddFeeColumnsToOrders
{
    public static function migrate()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fct_orders';

        // Add fee_total column after shipping_total
        if (!static::columnExists($table, 'fee_total')) {
            $wpdb->query(
                "ALTER TABLE {$table} ADD COLUMN `fee_total` BIGINT NOT NULL DEFAULT '0' AFTER `shipping_total`"
            );
        }
    }

    private static function columnExists($table, $column)
    {
        global $wpdb;
        $result = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
        return !empty($result);
    }
}
```

**Result on `fct_orders`:**

```sql
...
shipping_total      BIGINT NOT NULL DEFAULT '0'
fee_total           BIGINT NOT NULL DEFAULT '0'    -- NEW: cached sum of fee items
tax_total           BIGINT NOT NULL DEFAULT '0'
...
```

### 3.2 No Changes to `fct_order_items`

The existing schema already has everything we need:

```sql
-- Existing columns we'll use for fee items:
title           VARCHAR(255)    -- Fee display name ("Processing Fee")
unit_price      BIGINT          -- Fee amount in cents
quantity        INT             -- Always 1 for fees
subtotal        BIGINT          -- Same as unit_price (qty × price)
tax_amount      BIGINT          -- Tax on this fee (if taxable)
discount_total  BIGINT          -- Usually 0 for fees
refund_total    BIGINT          -- Refunded amount (proportional)
line_total      BIGINT          -- subtotal - discount_total
payment_type    VARCHAR(50)     -- 'fee'
other_info      TEXT (JSON)     -- Fee metadata (source, key, taxable flag, rule_id, etc.)
post_id         BIGINT          -- 0 (no product)
object_id       BIGINT          -- 0 (no variation)
```

### 3.3 Why We Still Need `fee_total` on Orders

Even though individual fees are in `order_items`, we need the cached column because:

1. **Revenue reports** use `SUM(o.fee_total)` — no JOIN required
2. **Dashboard widget** calculates net revenue from order columns
3. **Total formula** in CheckoutProcessor uses order-level columns
4. **Consistency** — `shipping_total`, `tax_total`, `coupon_discount_total` are all cached this way too
5. **API responses** return `fee_total` without needing to sum items

---

## 4. Fee Data Structures

### 4.1 Fee During Checkout (In `cart.checkout_data`)

```php
// cart.checkout_data structure
[
    // ... existing fields (shipping_data, tax_data, payment_method, etc.)
    'fees' => [
        [
            'key'      => 'processing_fee',        // Unique slug
            'label'    => 'Processing Fee',         // Customer-facing name
            'amount'   => 450,                      // Amount in cents (positive)
            'taxable'  => true,                     // Whether tax should be calculated
            'source'   => 'dynamic-pricing',        // Which addon registered it
            'meta'     => ['rule_id' => 42],        // Optional addon-specific data
        ],
        [
            'key'      => 'handling_fee',
            'label'    => 'Handling Fee',
            'amount'   => 200,
            'taxable'  => false,
            'source'   => 'dynamic-pricing',
            'meta'     => ['rule_id' => 15],
        ],
    ],
    'fee_total' => 650,  // Cached sum for quick access
]
```

### 4.2 Fee as Order Item (In `fct_order_items`)

```php
// OrderItem record for a fee
[
    'order_id'      => 123,
    'post_id'       => 0,                   // No product
    'object_id'     => 0,                   // No variation
    'post_title'    => '',
    'title'         => 'Processing Fee',     // Display name
    'quantity'      => 1,
    'unit_price'    => 450,                  // Amount in cents
    'subtotal'      => 450,                  // unit_price × quantity
    'tax_amount'    => 36,                   // Tax on fee (if taxable & tax enabled)
    'discount_total' => 0,
    'refund_total'  => 0,
    'line_total'    => 450,                  // subtotal - discount
    'payment_type'  => 'fee',               // The key identifier
    'other_info'    => json_encode([
        'fee_key'   => 'processing_fee',
        'source'    => 'dynamic-pricing',
        'taxable'   => true,
        'rule_id'   => 42,
    ]),
    'created_at'    => '2026-03-27 12:00:00',
]
```

### 4.3 Fee Item in Cart Data (Pre-Order)

During checkout, before order placement, fees also need representation in the cart's working data for the tax module to calculate tax on them:

```php
// Added to cart_data array alongside product items
[
    'object_id'      => 0,
    'post_id'        => 0,
    'quantity'       => 1,
    'unit_price'     => 450,
    'price'          => 450,
    'subtotal'       => 450,
    'line_total'     => 450,
    'discount_total' => 0,
    'tax_amount'     => 0,       // Set by TaxModule
    'title'          => 'Processing Fee',
    'post_title'     => '',
    'payment_type'   => 'fee',
    'is_fee'         => true,    // Quick flag for filtering
    'taxable'        => true,
    'other_info'     => [
        'payment_type' => 'fee',
        'fee_key'      => 'processing_fee',
        'source'       => 'dynamic-pricing',
    ],
]
```

---

## 5. Data Flow

### 5.1 Full Lifecycle

```
STEP 1: FEE REGISTRATION
────────────────────────
Addon hooks into: apply_filters('fluent_cart/cart/fees', [], $context)
Returns: [['key' => 'processing_fee', 'label' => 'Processing Fee', 'amount' => 450, ...]]
Core validates: positive amount, required fields, deduplication by key

STEP 2: CART STATE UPDATE
─────────────────────────
Cart::getFees() calls the filter
Cart stores result in checkout_data.fees
Cart::getFeeTotal() returns sum = 650
Cart::getEstimatedTotal() includes fee_total in formula

STEP 3: TAX CALCULATION
───────────────────────
TaxModule receives cart items including fee items (where taxable = true)
TaxModule calculates tax per item (same pipeline as product items)
Fee items with taxable = true get a tax_amount calculated
Fee items with taxable = false get tax_amount = 0
tax_total on the order includes tax from fee items

STEP 4: CHECKOUT SUMMARY (AJAX Fragments)
──────────────────────────────────────────
WebCheckoutHandler recalculates on every cart change
CartSummaryRender::renderFees() outputs fee lines in the summary
Fragments replace DOM elements via JS
Frontend shows: Subtotal → Shipping → Fees → Discounts → Tax → Total

STEP 5: ORDER CREATION
──────────────────────
CheckoutApi reads fees from checkout_data.fees
CheckoutProcessor::prepareOrderItems() creates fee items alongside product items
  - Each fee → one OrderItem with payment_type = 'fee'
  - fee_total = sum of all fee item subtotals
CheckoutProcessor::prepareOrderData() sets:
  - order.fee_total = sum of fee amounts
  - total_amount includes fee_total in the formula
createDraftOrder() writes Order + OrderItems (including fee items) to DB

STEP 6: POST-ORDER DISPLAY
───────────────────────────
Admin order view:    Fee items shown in totals section (filtered from product list)
Customer portal:     Fee rows in totals section
Emails:              Fee rows in items_table.php
PDF receipts:        Fee rows in summary section
Reports:             fee_total used in revenue calculations
Refunds:             Fee items participate in proportional refund distribution
```

### 5.2 Fee Recalculation Triggers

Fees recalculate whenever the checkout summary refreshes. All these already trigger `handleGetCheckoutSummaryViewAjax()`:

| Trigger | Example |
|---------|---------|
| Item added/removed | Cart contents change |
| Quantity changed | Cart subtotal changes |
| Coupon applied/removed | Net amount changes |
| Shipping method changed | Shipping context changes |
| Payment method changed | Gateway-specific fees |
| Address changed | Location-based fees |
| Summary view requested | Full refresh |

---

## 6. PHP Hook System for Fees

### 6.1 Primary Fee Filter

```php
/**
 * Filter: fluent_cart/cart/fees
 *
 * Allows addons to register fees/surcharges on the cart.
 * Called every time fees are recalculated (on every cart/checkout update).
 *
 * @param array $fees    Current fees (may contain fees from other addons)
 * @param array $context Cart context for condition evaluation
 * @return array Modified fees array
 *
 * Fee item structure:
 * [
 *     'key'      => string,  // Unique slug (required, used for deduplication)
 *     'label'    => string,  // Customer-facing name (required)
 *     'amount'   => int,     // Amount in cents, must be positive (required)
 *     'taxable'  => bool,    // Subject to tax calculation (default: false)
 *     'source'   => string,  // Addon identifier (default: 'custom')
 *     'meta'     => array,   // Optional extra data stored in order item other_info
 * ]
 */
$fees = apply_filters('fluent_cart/cart/fees', [], [
    'cart'           => $cart,
    'cart_items'     => $cart->cart_data,
    'cart_subtotal'  => $cart->getItemsSubtotal(),
    'shipping_total' => $cart->getShippingTotal(),
    'customer_id'    => $cart->customer_id,
    'payment_method' => Arr::get($cart->checkout_data, 'payment_method'),
    'checkout_data'  => $cart->checkout_data,
]);
```

### 6.2 Actions

```php
// After fees are calculated and stored on the cart
do_action('fluent_cart/cart/fees_calculated', $fees, $cart);

// After fee order items are created (in CheckoutProcessor)
do_action('fluent_cart/order/fee_items_created', $feeOrderItems, $order);
```

### 6.3 Example: Dynamic Pricing Addon Registering Fees

```php
add_filter('fluent_cart/cart/fees', function (array $fees, array $context) {
    $subtotal = $context['cart_subtotal'];

    // Small order surcharge: $5 fee when cart under $25
    if ($subtotal > 0 && $subtotal < 2500) {
        $fees[] = [
            'key'     => 'small_order_fee',
            'label'   => __('Small Order Fee', 'fluent-cart-dynamic-pricing'),
            'amount'  => 500,
            'taxable' => false,
            'source'  => 'dynamic-pricing',
            'meta'    => ['rule_id' => 42],
        ];
    }

    // Payment processing fee: 3% for installments
    $paymentMethod = $context['payment_method'];
    if ($paymentMethod === 'stripe' && $subtotal > 0) {
        $fees[] = [
            'key'     => 'processing_fee',
            'label'   => __('Processing Fee', 'fluent-cart-dynamic-pricing'),
            'amount'  => (int) round($subtotal * 0.03),
            'taxable' => true,
            'source'  => 'dynamic-pricing',
            'meta'    => ['rule_id' => 15, 'rate' => '3%'],
        ];
    }

    return $fees;
}, 10, 2);
```

---

## 7. Cart-Level Fee Handling

### 7.1 New Methods on Cart Model

**File:** `app/Models/Cart.php`

```php
/**
 * Collect and validate all fees for the current cart state.
 *
 * @return array Validated fee items
 */
public function getFees(): array
{
    $fees = apply_filters('fluent_cart/cart/fees', [], [
        'cart'           => $this,
        'cart_items'     => $this->cart_data ?? [],
        'cart_subtotal'  => $this->getItemsSubtotal(),
        'shipping_total' => $this->getShippingTotal(),
        'customer_id'    => $this->customer_id,
        'payment_method' => Arr::get($this->checkout_data, 'payment_method'),
        'checkout_data'  => $this->checkout_data,
    ]);

    $validFees = [];
    $seenKeys = [];

    foreach ($fees as $fee) {
        // Validate required fields
        if (empty($fee['key']) || empty($fee['label']) || empty($fee['amount'])) {
            continue;
        }

        // Deduplicate by key (first wins)
        if (in_array($fee['key'], $seenKeys, true)) {
            continue;
        }
        $seenKeys[] = $fee['key'];

        // Sanitize
        $validFees[] = [
            'key'     => sanitize_key($fee['key']),
            'label'   => sanitize_text_field($fee['label']),
            'amount'  => absint($fee['amount']),
            'taxable' => !empty($fee['taxable']),
            'source'  => sanitize_key($fee['source'] ?? 'custom'),
            'meta'    => (array) ($fee['meta'] ?? []),
        ];
    }

    return $validFees;
}

/**
 * Get the total of all fees in cents.
 *
 * @return int
 */
public function getFeeTotal(): int
{
    return array_reduce($this->getFees(), function ($carry, $fee) {
        return $carry + (int) $fee['amount'];
    }, 0);
}

/**
 * Build cart-data-compatible items for fee items.
 * Used by the tax module to calculate tax on taxable fees.
 *
 * @return array
 */
public function getFeeCartItems(): array
{
    $items = [];
    foreach ($this->getFees() as $fee) {
        $items[] = [
            'object_id'      => 0,
            'post_id'        => 0,
            'quantity'        => 1,
            'unit_price'      => $fee['amount'],
            'price'           => $fee['amount'],
            'subtotal'        => $fee['amount'],
            'line_total'      => $fee['amount'],
            'discount_total'  => 0,
            'coupon_discount' => 0,
            'tax_amount'      => 0,
            'title'           => $fee['label'],
            'post_title'      => '',
            'payment_type'    => 'fee',
            'is_fee'          => true,
            'fulfillment_type' => 'digital', // Fees are never "physical"
            'other_info'      => [
                'payment_type' => 'fee',
                'fee_key'      => $fee['key'],
                'source'       => $fee['source'],
                'taxable'      => $fee['taxable'],
            ],
        ];
    }
    return $items;
}
```

### 7.2 Update `getEstimatedTotal()` — Include Fee Total

**File:** `app/Models/Cart.php`, method `getEstimatedTotal()`

```php
public function getEstimatedTotal($extraAmount = 0)
{
    $checkoutItems = new CheckoutService($this->cart_data);
    $subscriptionItems = $checkoutItems->subscriptions;
    $onetimeItems = $checkoutItems->onetime;
    $items = array_merge($onetimeItems, $subscriptionItems);

    $total = OrderService::getItemsAmountTotal($items, false, false, $extraAmount);

    $shippingTotal = $this->getShippingTotal();
    if ($shippingTotal) {
        $total += $shippingTotal;
    }

    // ─── NEW: Add fee total ──────────────────────────────
    $feeTotal = $this->getFeeTotal();
    if ($feeTotal > 0) {
        $total += $feeTotal;
    }
    // ─────────────────────────────────────────────────────

    if (Arr::get($this->checkout_data, 'custom_checkout') === 'yes' && !$shippingTotal) {
        $customShippingAmount = (int) Arr::get($this->checkout_data, 'custom_checkout_data.shipping_total', 0);
        $total += $customShippingAmount;
    }

    if ($total < 0) {
        $total = 0;
    }

    return apply_filters('fluent_cart/cart/estimated_total', $total, [
        'cart' => $this
    ]);
}
```

---

## 8. Checkout Flow Changes

### 8.1 WebCheckoutHandler — Store Fees on Cart Update

**File:** `app/Hooks/Cart/WebCheckoutHandler.php`

In `handleGetCheckoutSummaryViewAjax()` (~line 360), after calculating totals, persist fees:

```php
// After total calculation, store fees in checkout_data
$fees = $cart->getFees();
$feeTotal = array_reduce($fees, fn($c, $f) => $c + (int)$f['amount'], 0);

$checkoutData = $cart->checkout_data ?? [];
$checkoutData['fees'] = $fees;
$checkoutData['fee_total'] = $feeTotal;
$cart->checkout_data = $checkoutData;
$cart->save();
```

### 8.2 CartSummaryRender — Display Fee Lines

**File:** `app/Services/Renderer/CartSummaryRender.php`

New method and integration into `renderItemsFooter()`:

```php
/**
 * Render fee line items in the checkout summary.
 * Placed between shipping and coupons.
 */
private function renderFees(): void
{
    $fees = $this->cart->getFees();
    if (empty($fees)) {
        return;
    }
    ?>
    <div class="fct_checkout_summary_fees" data-fluent-cart-checkout-fees>
        <?php foreach ($fees as $fee): ?>
            <div class="fct_checkout_summary_row fct_checkout_fee_row">
                <div class="fct_checkout_label">
                    <?php echo esc_html($fee['label']); ?>
                </div>
                <div class="fct_checkout_value">
                    <span data-fluent-cart-checkout-fee-amount="<?php echo esc_attr($fee['key']); ?>">
                        <?php echo esc_html(Helper::toDecimal($fee['amount'])); ?>
                    </span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}
```

**Updated render order in `renderItemsFooter()`:**

```
1. Subtotal
2. Custom cart summaries (custom checkout)
3. Shipping
4. ★ Fees (NEW — call $this->renderFees())
5. Applied coupons
6. Manual discount
7. fluent_cart/checkout/before_summary_total hook
8. Coupon input field
9. Total
```

### 8.3 Tax Integration — Fee Items in Tax Pipeline

**File:** `app/Modules/Tax/TaxModule.php` (or wherever cart items are assembled for tax calculation)

When the tax module collects items to calculate tax, include fee items:

```php
// In tax calculation, where cart items are collected:
$items = $cart->cart_data ?? [];

// Add taxable fee items to the tax calculation pipeline
$feeItems = $cart->getFeeCartItems();
foreach ($feeItems as $feeItem) {
    if (Arr::get($feeItem, 'other_info.taxable', false)) {
        $items[] = $feeItem;
    }
}

// Now tax calculation runs on products + taxable fees
```

The TaxModule already calculates `tax_amount` per item. Taxable fee items will get their tax_amount set through the same pipeline.

### 8.4 Modal & Block Checkout

**Modal Checkout** (`ModalCheckoutRenderer.php`) — delegates to `CartSummaryRender::renderItemsFooter()`, so fees appear automatically. No changes needed.

**Block Editor Checkout** (`InnerBlocks.php`) — delegates to CartSummaryRender. May need a new `fluent-cart/checkout-fees` inner block registered. Minimal change.

---

## 9. Order Creation Changes

### 9.1 CheckoutProcessor — Create Fee Order Items

**File:** `app/Helpers/CheckoutProcessor.php`

#### In `prepareOrderItems()` (~line 510)

After processing product/subscription items, create fee items:

```php
// At the end of prepareOrderItems(), after all product items:

$fees = (array) Arr::get($this->args, 'fees', []);
$this->feeTotal = 0;

foreach ($fees as $fee) {
    $amount = absint($fee['amount'] ?? 0);
    if ($amount <= 0) {
        continue;
    }

    $this->feeTotal += $amount;

    $this->formattedOrderItems[] = [
        'order_id'       => 0, // Set after order creation
        'post_id'        => 0,
        'object_id'      => 0,
        'post_title'     => '',
        'title'          => sanitize_text_field($fee['label']),
        'quantity'        => 1,
        'unit_price'      => $amount,
        'subtotal'        => $amount,
        'cost'            => 0,
        'tax_amount'      => 0, // Set by tax module if taxable
        'discount_total'  => 0,
        'refund_total'    => 0,
        'line_total'      => $amount,
        'payment_type'    => 'fee',
        'fulfillment_type' => 'digital',
        'other_info'      => [
            'payment_type' => 'fee',
            'fee_key'      => $fee['key'] ?? '',
            'source'       => $fee['source'] ?? 'custom',
            'taxable'      => !empty($fee['taxable']),
            'meta'         => $fee['meta'] ?? [],
        ],
    ];
}
```

#### In `prepareOrderData()` (~line 769)

Add `fee_total` to the order data:

```php
$orderData = [
    // ... existing fields ...
    'shipping_total'        => (int) Arr::get($this->args, 'shipping_charge', 0),
    'fee_total'             => $this->feeTotal,    // ← NEW
    'tax_total'             => (int) Arr::get($this->args, 'tax_total', 0),
    // ...
];
```

Update the `total_amount` formula:

```php
// Current formula:
$totalAmount = $orderData['subtotal']
    - $orderData['coupon_discount_total']
    - $orderData['manual_discount_total']
    + $orderData['shipping_total']
    + $estimatedTaxTotal
    + $estimatedShippingTax;

// New formula (add fee_total):
$totalAmount = $orderData['subtotal']
    - $orderData['coupon_discount_total']
    - $orderData['manual_discount_total']
    + $orderData['fee_total']              // ← NEW LINE
    + $orderData['shipping_total']
    + $estimatedTaxTotal
    + $estimatedShippingTax;
```

#### In `createDraftOrder()` (~line 61)

Fee items are already in `$this->formattedOrderItems`, so they'll be inserted alongside product items. No special handling needed — the existing loop creates all items:

```php
// Existing code that creates order items (simplified):
foreach ($this->formattedOrderItems as $item) {
    // Skip signup_fee items (handled separately)
    if ($item['payment_type'] === 'signup_fee') {
        continue;
    }

    // This naturally includes 'fee' items alongside 'onetime' and 'subscription'
    $orderItem = OrderItem::create(array_merge($item, [
        'order_id' => $this->orderModel->id,
    ]));
}
```

**Important:** Check that the existing signup_fee exclusion logic doesn't accidentally exclude fee items. If the code uses `!= 'onetime' && != 'subscription'` patterns, fee items might be filtered. Each location needs to be verified.

### 9.2 CheckoutApi — Pass Fees to Processor

**File:** `api/Checkout/CheckoutApi.php` in `placeOrder()`

```php
// After shipping calculation (~line 146), extract fees:
$fees = (array) Arr::get($cart->checkout_data, 'fees', []);
$feeTotal = (int) Arr::get($cart->checkout_data, 'fee_total', 0);

// Pass to CheckoutProcessor args (~line 173-191):
$processorArgs = [
    // ... existing args ...
    'fees'      => $fees,        // ← NEW
    'fee_total' => $feeTotal,    // ← NEW
];
```

### 9.3 Order Model Updates

**File:** `app/Models/Order.php`

```php
// Add to $fillable:
'fee_total',

// Add to $casts:
'fee_total' => 'double',

// New method to get fee order items:
/**
 * Get fee items for this order.
 *
 * @return \Illuminate\Database\Eloquent\Collection
 */
public function feeItems()
{
    return $this->order_items()->where('payment_type', 'fee');
}

/**
 * Get fee details as a simple array (for display purposes).
 *
 * @return array
 */
public function getAppliedFees(): array
{
    return $this->feeItems()->get()->map(function ($item) {
        $otherInfo = $item->other_info ?? [];
        return [
            'key'       => Arr::get($otherInfo, 'fee_key', ''),
            'label'     => $item->title,
            'amount'    => (int) $item->subtotal,
            'tax'       => (int) $item->tax_amount,
            'source'    => Arr::get($otherInfo, 'source', 'custom'),
            'item_id'   => $item->id,
        ];
    })->toArray();
}
```

---

## 10. Display: Where Fees Appear

### 10.1 Display Logic: Fees in Totals Section (Not Product List)

Fee items have `payment_type = 'fee'`. They should be **shown in the totals/summary section** (alongside shipping, tax, discounts) and **filtered out of the product line items list**.

This matches the `signup_fee` pattern — signup fees are filtered from the product list and shown separately.

**Filtering pattern (used in all display contexts):**

```php
// Product items (exclude fees and signup_fees):
$productItems = array_filter($order->order_items, function ($item) {
    return !in_array($item->payment_type, ['fee', 'signup_fee']);
});

// Fee items only:
$feeItems = array_filter($order->order_items, function ($item) {
    return $item->payment_type === 'fee';
});
```

In Vue:
```javascript
// Computed property for product items (exclude fees)
const productItems = computed(() =>
    order.items.filter(item => item.payment_type !== 'fee')
);
```

### 10.2 Visual Layout (All Contexts)

```
╔══════════════════════════════════════════════════════════════════╗
║                     UNIVERSAL FEE DISPLAY ORDER                  ║
╠══════════════════════════════════════════════════════════════════╣
║                                                                  ║
║  ┌─ PRODUCT LINE ITEMS ─────────────────────────────────────┐   ║
║  │ Blue T-Shirt (L)         2 × $29.99          $59.98      │   ║
║  │ Black Hoodie (M)         1 × $89.99          $89.99      │   ║
║  └───────────────────────────────────────────────────────────┘   ║
║                                                                  ║
║  ┌─ TOTALS SECTION ─────────────────────────────────────────┐   ║
║  │ Subtotal                                       $149.97    │   ║
║  │ Shipping (Standard)                              $9.99    │   ║
║  │ Processing Fee                                   $4.50    │ ← ║
║  │ Handling Fee                                     $2.00    │ ← ║
║  │ Coupon (SAVE10)                                -$15.00    │   ║
║  │ Tax (Excluded)                                  $11.16    │   ║
║  │ ──────────────────────────────────────────────────────    │   ║
║  │ Total                                          $162.62    │   ║
║  └───────────────────────────────────────────────────────────┘   ║
║                                                                  ║
╚══════════════════════════════════════════════════════════════════╝
```

**Fee placement order:** After Shipping, Before Discounts. This is deliberate:
- Fees are additions (like shipping), not subtractions (like discounts)
- Customer sees: base cost → additions → subtractions → tax → total
- This ordering is intuitive: "your items cost X, plus shipping, plus fees, minus discounts"

### 10.3 Admin Order View

**File:** `resources/admin/Modules/Orders/SingleOrder.vue`

Add between shipping rows (~L451) and coupon rows (~L452):

```vue
<!-- Fee rows -->
<template v-if="order.fee_total > 0">
    <tr v-for="fee in orderFeeItems" :key="fee.id" class="fct-fee-row">
        <td>{{ fee.title }}</td>
        <td align="right">{{ formatNumber(fee.subtotal) }}</td>
    </tr>
</template>
```

With computed property:

```javascript
const orderFeeItems = computed(() => {
    return (order.value.order_items || []).filter(
        item => item.payment_type === 'fee'
    );
});
```

Also filter fees out of the product items list:

```javascript
// Update existing product items filter to exclude fees
const productItems = computed(() => {
    return (order.value.order_items || []).filter(
        item => !['fee', 'signup_fee'].includes(item.payment_type)
    );
});
```

### 10.4 Customer Portal

**File:** `resources/public/customer-profile/Vue/SingleOrder.vue`

Add between shipping (~L155) and tax (~L158):

```vue
<!-- Fee rows -->
<template v-if="order.fee_total > 0">
    <div class="fct_order_summary_row"
         v-for="fee in feeItems" :key="fee.id">
        <span>{{ fee.title }}</span>
        <span>{{ formatNumber(fee.subtotal) }}</span>
    </div>
</template>
```

### 10.5 Email Template

**File:** `app/Views/emails/parts/items_table.php`

Add after shipping row (~line 107), before discount row (~line 123):

```php
<?php
// Fee rows
$feeItems = array_filter($order->order_items()->get()->toArray(), function ($item) {
    return ($item['payment_type'] ?? '') === 'fee';
});

foreach ($feeItems as $feeItem): ?>
    <tr>
        <td colspan="3"
            style="text-align:left; padding: 12px; border-bottom: 1px solid #e5e5e5; color: #636363;">
            <?php echo esc_html($feeItem['title']); ?>
        </td>
        <td style="text-align:right; padding: 12px; border-bottom: 1px solid #e5e5e5; color: #636363;">
            <?php echo esc_html(Helper::toDecimal($feeItem['subtotal'])); ?>
        </td>
    </tr>
<?php endforeach;

// Fallback if no fee items but fee_total exists (edge case)
if (empty($feeItems) && $order->fee_total > 0): ?>
    <tr>
        <td colspan="3"
            style="text-align:left; padding: 12px; border-bottom: 1px solid #e5e5e5; color: #636363;">
            <?php esc_html_e('Additional Fees', 'fluent-cart'); ?>
        </td>
        <td style="text-align:right; padding: 12px; border-bottom: 1px solid #e5e5e5; color: #636363;">
            <?php echo esc_html(Helper::toDecimal($order->fee_total)); ?>
        </td>
    </tr>
<?php endif; ?>
```

### 10.6 PDF / Receipt / Thank You

All receipt renderers follow the same pattern. Add a `renderFees()` method:

```php
// In ReceiptRenderer, ThankYouRender, and receipt_slip.php:
protected function renderFees(): void
{
    $feeItems = $this->order->feeItems()->get();

    if ($feeItems->isEmpty() && $this->order->fee_total <= 0) {
        return;
    }

    foreach ($feeItems as $fee) {
        $this->renderSummaryRow(
            esc_html($fee->title),
            Helper::toDecimal($fee->subtotal)
        );
    }
}
```

Call between `renderShipping()` and `renderDiscount()`.

### 10.7 ShortCode Parser

**File:** `app/Services/ShortCodeParser/Parsers/OrderParser.php`

```php
// Add to $centColumns array:
'fee_total',

// New shortcode: {{order.fee_lines}}
case 'fee_lines':
    $feeItems = $this->order->feeItems()->get();
    if ($feeItems->isEmpty()) return '';

    $html = '';
    foreach ($feeItems as $fee) {
        $html .= '<tr>';
        $html .= '<td colspan="3" style="text-align:left;padding:8px;">' . esc_html($fee->title) . '</td>';
        $html .= '<td style="text-align:right;padding:8px;">' . Helper::toDecimal($fee->subtotal) . '</td>';
        $html .= '</tr>';
    }
    return $html;
```

Update `payment_summary` and `payment_receipt` shortcodes to include fee lines.

### 10.8 Reports

**File:** `app/Services/Report/RevenueReportService.php`

```sql
-- Add to revenue query SELECT:
SUM(o.fee_total) as fee_total

-- net_revenue formula stays the same because fee_total
-- is already included in total_amount (which becomes total_paid)
-- Fee revenue = fee_total (show as supplementary info, not a deduction)
```

Add "Fees Collected" card to Revenue dashboard Vue components for visibility.

### 10.9 E-Invoice (EN16931)

**File:** `app/Services/Invoice/Mapper/En16931InvoiceMapper.php`

Map fee items as document-level charges (same pattern as shipping):

```php
// For each fee order item, create a document-level charge:
$feeItems = $order->feeItems()->get();
foreach ($feeItems as $feeItem) {
    $invoice->addDocumentLevelCharge(
        amount: $feeItem->subtotal,
        tax: $feeItem->tax_amount,
        description: $feeItem->title
    );
}
```

### 10.10 REST API Response

**File:** `api/Resource/OrderResource.php`

The order model already returns all columns via Eloquent, so `fee_total` will be in API responses automatically after adding it to `$fillable`. Additionally, include `applied_fees` in the enriched response:

```php
// In the order response enrichment:
$orderData['applied_fees'] = $order->getAppliedFees();
```

---

## 11. File-by-File Change List

### 11.1 Foundation (Database + Models)

| # | File | Change | Est. Lines |
|---|------|--------|------------|
| 1 | `database/Migrations/AddFeeColumnsToOrders.php` | **NEW FILE** — add `fee_total` column | 25 |
| 2 | `app/Models/Order.php` | Add `fee_total` to `$fillable`, `$casts`; add `feeItems()` relation, `getAppliedFees()` method | 25 |
| 3 | `app/Models/Cart.php` | Add `getFees()`, `getFeeTotal()`, `getFeeCartItems()`; update `getEstimatedTotal()` | 70 |

### 11.2 Checkout & Order Creation

| # | File | Change | Est. Lines |
|---|------|--------|------------|
| 4 | `app/Helpers/CheckoutProcessor.php` | Create fee order items in `prepareOrderItems()`; add `fee_total` to `prepareOrderData()`; update total_amount formula | 40 |
| 5 | `api/Checkout/CheckoutApi.php` | Extract fees from checkout_data; pass to CheckoutProcessor | 6 |
| 6 | `app/Hooks/Cart/WebCheckoutHandler.php` | Store calculated fees in checkout_data during summary recalc | 10 |

### 11.3 Checkout Display

| # | File | Change | Est. Lines |
|---|------|--------|------------|
| 7 | `app/Services/Renderer/CartSummaryRender.php` | Add `renderFees()` method; call in `renderItemsFooter()` | 30 |
| 8 | `app/Hooks/Handlers/BlockEditors/Checkout/InnerBlocks/InnerBlocks.php` | Register fees display for block-based checkout (if applicable) | 10 |

### 11.4 Tax Integration

| # | File | Change | Est. Lines |
|---|------|--------|------------|
| 9 | `app/Modules/Tax/TaxModule.php` | Include taxable fee items in tax calculation pipeline | 10 |

### 11.5 Admin Panel (Vue)

| # | File | Change | Est. Lines |
|---|------|--------|------------|
| 10 | `resources/admin/Modules/Orders/SingleOrder.vue` | Add fee rows in payment table; filter fees from product list | 25 |
| 11 | `resources/admin/Modules/Orders/CreateOrder.vue` | Add fee display (read-only) | 10 |

### 11.6 Customer Portal (Vue)

| # | File | Change | Est. Lines |
|---|------|--------|------------|
| 12 | `resources/public/customer-profile/Vue/SingleOrder.vue` | Add fee rows in totals; filter from product list | 15 |

### 11.7 Email Templates

| # | File | Change | Est. Lines |
|---|------|--------|------------|
| 13 | `app/Views/emails/parts/items_table.php` | Add fee rows after shipping. Covers ALL 8 email templates | 25 |

### 11.8 PDF / Receipt / Thank You

| # | File | Change | Est. Lines |
|---|------|--------|------------|
| 14 | `app/Views/invoice/receipt_slip.php` | Add fee rows after shipping | 15 |
| 15 | `app/Services/Renderer/Receipt/ReceiptRenderer.php` | Add `renderFees()`; call in render flow | 20 |
| 16 | `app/Views/invoice/thank_you.php` | Add fee rows after shipping | 15 |
| 17 | `app/Services/Renderer/Receipt/ThankYouRender.php` | Add `renderFees()`; call in render flow | 20 |
| 18 | `app/Services/PDF/DefaultPdfStructures.php` | Add `{{order.fee_total_formatted}}` and `{{order.fee_lines}}` | 12 |
| 19 | `app/Views/invoice/parts/invoice.php` | Add fee row | 8 |

### 11.9 ShortCode Parser

| # | File | Change | Est. Lines |
|---|------|--------|------------|
| 20 | `app/Services/ShortCodeParser/Parsers/OrderParser.php` | Add `fee_total` to cent columns; add `fee_lines` shortcode; update `payment_summary` | 30 |

### 11.10 Reports

| # | File | Change | Est. Lines |
|---|------|--------|------------|
| 21 | `app/Services/Report/RevenueReportService.php` | Add `SUM(o.fee_total)` to queries | 8 |
| 22 | `resources/admin/Modules/Reports/Revenue/RevenueReportSummary.vue` | Add "Fees" card | 8 |
| 23 | `resources/admin/Modules/Reports/Revenue/Revenue.vue` | Add "Fees" column | 8 |
| 24 | `app/Services/Widgets/DashboardWidget.php` | Verify net_revenue formula (fees are already in total_paid) | 3 |

### 11.11 E-Invoice & API

| # | File | Change | Est. Lines |
|---|------|--------|------------|
| 25 | `app/Services/Invoice/Mapper/En16931InvoiceMapper.php` | Map fee items as document charges | 15 |
| 26 | `api/Resource/OrderResource.php` | Include `applied_fees` in response | 5 |

### 11.12 Refund Handling

| # | File | Change | Est. Lines |
|---|------|--------|------------|
| 27 | `app/Services/OrderService.php` (or refund service) | Verify fee items participate in proportional refund; optionally exclude fees from refund with a filter | 10 |

### 11.13 Stock / Fulfillment Guards

| # | File | Change | Est. Lines |
|---|------|--------|------------|
| 28 | Stock management files | Add `payment_type !== 'fee'` guards wherever `signup_fee` is already excluded | 5-10 |

---

## 12. Implementation Order

### Phase 1: Foundation (Day 1-2)

```
□ Migration: Add fee_total column to fct_orders
□ Order model: Add fillable, cast, feeItems(), getAppliedFees()
□ Cart model: Add getFees(), getFeeTotal(), getFeeCartItems()
□ Cart model: Update getEstimatedTotal() to include fees
□ CheckoutProcessor: Create fee order items, add fee_total, update formula
□ CheckoutApi: Pass fees to processor
□ WebCheckoutHandler: Store fees in checkout_data
```

**Verify:** Add a test filter, place an order. Fee items appear in order_items. fee_total matches. total_amount is correct.

### Phase 2: Checkout Display (Day 3)

```
□ CartSummaryRender: Add renderFees(), integrate into renderItemsFooter()
□ Test with hardcoded fee filter:
    add_filter('fluent_cart/cart/fees', fn($f) => array_merge($f, [[
        'key' => 'test', 'label' => 'Test Fee', 'amount' => 100,
        'taxable' => false, 'source' => 'test'
    ]]));
□ Verify: fee shows in checkout summary, total is correct
□ Verify: modal checkout shows fee
□ Verify: block-editor checkout shows fee
□ Remove test filter
```

### Phase 3: Tax Integration (Day 3-4)

```
□ TaxModule: Include taxable fee items in tax pipeline
□ Test: fee with taxable=true gets tax_amount calculated
□ Test: fee with taxable=false has tax_amount=0
□ Test: order.tax_total includes tax on taxable fees
```

### Phase 4: Post-Order Display (Day 4-5)

```
□ Admin SingleOrder.vue: fee rows in payment table, filter from product list
□ Admin CreateOrder.vue: fee display
□ Customer portal SingleOrder.vue: fee rows
□ Email items_table.php: fee rows
□ Receipt: receipt_slip.php, ReceiptRenderer.php
□ Thank You: thank_you.php, ThankYouRender.php
□ PDF structures: DefaultPdfStructures.php, invoice parts
□ ShortCode parser: fee_total, fee_lines, payment_summary update
```

### Phase 5: Reports, E-Invoice, API (Day 6)

```
□ RevenueReportService: fee_total in queries
□ Revenue Vue components: fee display
□ Dashboard widget: verify formula
□ En16931InvoiceMapper: fee as document charge
□ OrderResource API: include applied_fees
```

### Phase 6: Guards & Edge Cases (Day 7)

```
□ Stock management: exclude fee items from stock operations
□ Fulfillment: exclude fee items from shipping/fulfillment
□ Downloads: exclude fee items from download generation
□ Refunds: verify fee items participate in proportional refund
□ Subscriptions: verify fee items don't create subscriptions
□ Bundle logic: verify fee items aren't treated as bundle children
```

---

## 13. Edge Cases & Testing

### 13.1 Test Matrix

| Test Case | Expected Behavior |
|-----------|-------------------|
| No fees (no addon active) | fee_total = 0, no fee rows anywhere, existing behavior unchanged |
| Single fee | Fee appears in checkout, order, email, PDF, customer portal |
| Multiple fees | All fees shown individually, fee_total = sum |
| Fee + coupon + shipping + tax | All amounts correct, total_amount formula verified |
| Taxable fee (exclusive tax) | Tax calculated on fee, added to tax_total |
| Taxable fee (inclusive tax) | Tax calculated on fee, included in fee amount |
| Non-taxable fee | tax_amount = 0 on fee item |
| Fee + refund | Fee item participates in proportional refund |
| Fee on subscription order | Fee applies to initial checkout only (by default) |
| Fee with trial period | Fee still applies (trial only affects subscription price) |
| Custom checkout with fees | Fees shown in custom checkout summary |
| Modal checkout with fees | Fees shown in modal summary |
| Block-editor checkout with fees | Fees shown in block-based summary |
| Fee amount = 0 | Fee item not created (filtered out) |
| Negative fee amount | Rejected (absint ensures positive) |
| Duplicate fee keys | Second one filtered (deduplication by key) |
| Payment method changes | Fees recalculate (payment-method-specific fees update) |
| Order edit (admin) | Fee items visible but not editable (read-only) |
| PDF receipt download | Fee rows present |
| E-Invoice XML | Fee as document-level charge |
| Revenue reports | fee_total shown as supplementary metric |
| API response | fee_total and applied_fees included |

### 13.2 Backward Compatibility

- **Existing orders:** `fee_total` defaults to 0. No migration of existing data needed.
- **No addon active:** `fluent_cart/cart/fees` filter returns empty array. Zero overhead.
- **Frontend JS:** Fee rows render server-side via PHP fragments. No JS changes needed for checkout.
- **Database queries:** New column has DEFAULT 0, so all existing queries work unchanged.

---

## Summary

| Metric | Value |
|--------|-------|
| New database columns | 1 (`fee_total` on `fct_orders`) |
| New migration files | 1 |
| New `payment_type` value | `'fee'` (on existing `fct_order_items`) |
| PHP files modified | ~22 |
| Vue files modified | ~4 |
| PHP template files modified | ~5 |
| Total files touched | ~32 |
| Estimated new/changed lines | ~450 |
| Estimated development time | 6-7 days |

### Architecture Summary

```
Addon registers fees via filter
         │
         ▼
┌─────────────────────────┐
│  fluent_cart/cart/fees   │ ← Single hook, multiple addons can add fees
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────┐
│  Cart Model              │
│  getFees() → validate    │
│  getFeeTotal() → sum     │
│  getFeeCartItems() → tax │
│  getEstimatedTotal()     │
│    includes fee_total    │
└────────────┬────────────┘
             │
     ┌───────┴────────┐
     │                │
     ▼                ▼
┌──────────┐   ┌─────────────────┐
│ Checkout │   │ Tax Module      │
│ Summary  │   │ Taxable fees    │
│ Display  │   │ get tax_amount  │
└──────────┘   └────────┬────────┘
                        │
                        ▼
              ┌──────────────────┐
              │ CheckoutProcessor │
              │ Fee → OrderItem   │
              │ payment_type=fee  │
              │ fee_total on Order│
              └────────┬─────────┘
                       │
            ┌──────────┴──────────────┐
            │                         │
            ▼                         ▼
     ┌────────────┐           ┌──────────────┐
     │ fct_orders  │           │ fct_order_   │
     │ fee_total=  │           │ items        │
     │ cached sum  │           │ payment_type │
     └────────────┘           │ = 'fee'      │
                              └──────────────┘
                                      │
                    ┌─────────────────┼─────────────────┐
                    │                 │                  │
                    ▼                 ▼                  ▼
             ┌───────────┐   ┌──────────────┐   ┌────────────┐
             │ Admin Vue  │   │ Email/PDF    │   │ Customer   │
             │ Order View │   │ Templates    │   │ Portal     │
             │ fee rows   │   │ fee rows     │   │ fee rows   │
             └───────────┘   └──────────────┘   └────────────┘
```

---

*This plan is a prerequisite for the Dynamic Pricing addon. Complete this first, then Dynamic Pricing hooks into `fluent_cart/cart/fees` to register its fee-type rules.*

---

## GSTACK REVIEW REPORT

| Review | Trigger | Why | Runs | Status | Findings |
|--------|---------|-----|------|--------|----------|
| CEO Review | `/plan-ceo-review` | Scope & strategy | 0 | — | — |
| Codex Review | `/codex review` | Independent 2nd opinion | 1 | ISSUES_FOUND | 13 findings, 6 accepted |
| Eng Review | `/plan-eng-review` | Architecture & tests (required) | 1 | CLEAR (PLAN) | 8 issues, 2 critical gaps |
| Design Review | `/plan-design-review` | UI/UX gaps | 0 | — | — |

**CODEX:** Found 13 issues. 6 accepted (gateway line items, stale fee recalc, centralized filtering, composite dedup key, reject negatives, payment gateway awareness). 7 noted but deferred or overridden (tax deferral acknowledged, scope decision stands).
**CROSS-MODEL:** Both reviewers agree on core architecture (fees as order items). Codex challenged scope (#13) but eng review's reasoning (future tax/refund/display benefits) stands.
**VERDICT:** ENG + CODEX CLEARED — all issues resolved or documented.

### Eng Review Decisions Log

| # | Issue | Decision | Impact |
|---|-------|----------|--------|
| 1A | Tax on fee items | **Defer to Phase 2.** All fees non-taxable initially. Remove `getFeeCartItems()` and TaxModule changes. | -1 file, -10 lines |
| 1B | getFees() called 4x per request + recursion risk | **Add per-request cache + recursion guard** on `Cart::getFees()`. | +10 lines in Cart.php |
| 1C | Fee items appear in product item loops | **Filter from ALL product item loops.** Fees show only in totals section. | +16 lines across 8 files |
| 1D | fee_total vs sum of fee items can drift | **Set once at order creation, treat as source of truth.** | No change |
| 1E | Subscription renewals and fees | **Fees on initial checkout only.** | No change |
| 2A | Fee validation duplicated | **Validate once in `Cart::getFees()`, trust downstream.** | -5 lines |
| 2B | `getFeeCartItems()` has no consumer | **Remove from Phase 1.** | -30 lines |
| 2C | `checkout_data.fee_total` redundant | **Remove.** Compute from fees array. | -3 lines |

### Codex Review Additions

| # | Codex Finding | Decision | Impact |
|---|---------------|----------|--------|
| C2 | Payment gateways don't include fees in line items | **Add fee items to gateway line item builders** (Stripe, PayPal, etc.). | +5 lines per gateway |
| C3-4 | Stale fees between summary and place-order | **Recalculate fees fresh in `placeOrder()`** instead of reading checkout_data. | +3 lines in CheckoutApi |
| C9 | "Filter from product loops" is brittle whack-a-mole | **Add `Order::getProductItems()` centralized helper** that excludes fee + signup_fee. All display loops use this. | +8 lines in Order.php, simpler display code |
| C11 | Dedup by key only — cross-addon collision risk | **Change dedup key to `{source}:{key}` composite.** | ~2 lines in getFees() |
| C12 | absint coerces negatives into valid fees | **Reject negative amounts** (skip the fee) instead of coercing. | ~2 lines in getFees() |

### NOT in Scope

- Taxable fees (Phase 2 — needs TaxCalculator changes for `post_id = 0` items)
- Subscription renewal fees (needs separate `fluent_cart/renewal/fees` hook)
- Admin fee editing on existing orders (fees are read-only post-creation)
- Fee-specific refund UI (proportional refund handles it automatically)
- Fee analytics/reporting dashboard (Dynamic Pricing addon scope)
- `getFeeCartItems()` method (no consumer without tax integration)
- `checkout_data.fee_total` field (redundant, compute from fees array)
- Non-refundable fees (`refundable` field deferred — all fees refundable for now)

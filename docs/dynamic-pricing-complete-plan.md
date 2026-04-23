# FluentCart Dynamic Pricing — Complete Feature Plan

> **Status:** Planning
> **Target:** Free Addon for FluentCart Pro (`fluent-cart-dynamic-pricing`)
> **Availability:** Included free with all FluentCart Pro licenses
> **Date:** 2026-03-26
> **Author:** Engineering Team

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Market Research & Competitive Analysis](#2-market-research--competitive-analysis)
3. [Feature Specification](#3-feature-specification)
4. [Data Model & Schema](#4-data-model--schema)
5. [Architecture & Data Flow](#5-architecture--data-flow)
6. [Required Hooks in FluentCart Core](#6-required-hooks-in-fluentcart-core)
7. [Addon Plugin Structure](#7-addon-plugin-structure)
8. [Code Samples](#8-code-samples)
9. [Frontend Implementation](#9-frontend-implementation)
10. [Development Phases](#10-development-phases)
11. [Marketing Plan](#11-marketing-plan)
12. [Appendix: Competitive Feature Matrix](#appendix-competitive-feature-matrix)

---

## 1. Executive Summary

Dynamic Pricing is a rules-based pricing engine that **automatically adjusts prices** at the cart/checkout level based on configurable conditions — without requiring coupon codes. It enables store owners to create sophisticated pricing strategies: bulk discounts, BOGO deals, role-based pricing, time-limited sales, checkout fees, and more.

### Why Build This

- **Revenue impact:** Dynamic pricing can increase profitability by up to 22% (industry data)
- **Market demand:** 21% of eCommerce businesses already use dynamic pricing; 17% more plan to
- **Competitive gap:** SureCart just launched this; WooCommerce has 10+ plugins for it; FluentCart needs parity
- **Pro value multiplier:** Dramatically increases the perceived value of FluentCart Pro — WooCommerce users pay $43-149/year separately for this feature alone
- **Retention & upgrades:** Becomes a compelling reason for free users to upgrade and for Pro users to stay

### Distribution Model

**Free addon included with FluentCart Pro** — no separate purchase. All existing and future FluentCart Pro customers get the full Dynamic Pricing feature at no additional cost. This is a strategic decision to:

1. **Maximize adoption** — Zero friction means every Pro user can try it immediately
2. **Increase Pro conversion** — Free FluentCart users see "Dynamic Pricing (Pro)" in the menu, creating a powerful upgrade incentive
3. **Outcompete WooCommerce** — WooCommerce users pay $43-149/year for comparable functionality; we include it free
4. **Strengthen the ecosystem** — More features bundled in Pro = higher perceived value = lower churn

### Design Principles

1. **Simple by default, powerful when needed** — Templates for common scenarios, full rule builder for power users
2. **Addon architecture** — Separate plugin that ships with FluentCart Pro, hooks into core via filters/actions
3. **Performance-first** — Rules evaluated server-side with caching; no N+1 queries
4. **Transparent to customers** — Clear display of discounts/fees with display names in checkout
5. **Full-featured, no artificial limits** — Pro users get everything: unlimited rules, all conditions, BOGO, analytics

---

## 2. Market Research & Competitive Analysis

### 2.1 SureCart Dynamic Pricing

SureCart's implementation (launched early 2026) is the most directly comparable:

**Strengths:**
- 3 rule targets: Line Item, Checkout, Shipping
- 2 price types: Discount and Fee (surcharges)
- 28+ condition attributes for line items, 14+ for checkout
- AND/OR condition logic
- 8 pre-built templates (BOGO, Free Shipping, Bulk Discount, etc.)
- Per-target stacking strategy (All/First/Largest/Smallest)
- Scheduling with start/end dates
- Lifecycle timing (Initial Checkout / Renewals / All)

**Weaknesses:**
- SaaS-dependent (rules evaluated via their API)
- No tiered pricing table UI on product pages
- No analytics/reporting on discount performance
- No "fixed price override" adjustment type
- BOGO is just a template (not a distinct action type)

### 2.2 WooCommerce Ecosystem (Top Players)

| Plugin | Active Installs | Key Differentiator | Price |
|--------|----------------|---------------------|-------|
| **Flycart Discount Rules** | 100,000+ | Most popular free; 3-tab UI (Simple/Bulk/BOGO) | Free / $85/yr |
| **AlgolPlus Advanced DP** | 50,000+ | 50+ conditions in Pro; most granular | Free / $60/yr |
| **Acowebs Dynamic Pricing** | 30,000+ | AND/OR logic; most affordable | Free / $43/yr |
| **YayPricing** | 20,000+ | Built-in analytics dashboard | Free / WooCommerce.com |
| **YITH Dynamic Pricing** | 15,000+ | Deep ecosystem integration | Premium |

### 2.3 Must-Have Features (Table Stakes)

These are non-negotiable for any dynamic pricing solution:

- [x] Percentage and fixed amount discounts
- [x] Bulk/tiered/quantity-break pricing
- [x] BOGO (Buy One Get One) — at minimum Buy X Get X
- [x] Category-based discounts
- [x] User role-based pricing
- [x] Schedule with start/end dates
- [x] Priority system for rule ordering
- [x] Automatic application (no coupon codes needed)
- [x] Pricing table display on product pages
- [x] Strikethrough/sale pricing display

### 2.4 Premium Differentiators (Our Advantage Opportunities)

Features that set premium solutions apart:

- **Analytics dashboard** — Only YayPricing has this; huge gap in the market
- **Gift product selection** — Customer chooses their gift (vs. auto-added)
- **Checkout fees/surcharges** — Same engine for both discounts AND fees
- **Payment gateway conditions** — Discount for specific payment methods
- **Customer lifetime value conditions** — Pricing based on total spend history
- **Subscription lifecycle awareness** — Rules for renewals vs. initial checkout
- **Real-time price updates** — Dynamic price changes as quantity changes on product page
- **AND/OR condition groups** — Complex boolean logic for conditions

---

## 3. Feature Specification

### 3.1 Rule Structure

Every dynamic pricing rule has 5 components:

```
RULE = {
    identity:    Name, Display Name, Status, Priority
    target:      Where the rule applies (Line Item / Cart / Shipping)
    adjustment:  What change to make (Discount/Fee, Percentage/Fixed/Fixed Price)
    conditions:  When to apply (AND/OR groups of attribute checks)
    schedule:    Time window (Start Date, End Date, Days of Week)
}
```

### 3.2 Rule Targets

| Target | Scope | Example |
|--------|-------|---------|
| **Line Item** | Individual product line in cart | "10% off Product X when qty >= 5" |
| **Cart Total** | Entire cart subtotal | "$20 off when cart > $200" |
| **Shipping** | Shipping charge | "Free shipping when cart > $75" |

### 3.3 Adjustment Types

| Type | Description | Example |
|------|-------------|---------|
| **Percentage Discount** | % off the target amount | 10% off |
| **Fixed Amount Discount** | Flat amount off | $5 off |
| **Fixed Price Override** | Set absolute price (line item only) | Set to $9.99 |
| **Percentage Fee** | % surcharge added | 3% processing fee |
| **Fixed Amount Fee** | Flat surcharge added | $2 handling fee |

### 3.4 Condition Attributes

#### Line Item Conditions

| Category | Attribute | Operators |
|----------|-----------|-----------|
| **Product** | Specific Products | is in, is not in |
| **Product** | Product Categories | is in, is not in |
| **Product** | Product Tags | is in, is not in |
| **Product** | Product SKU | is, is not, contains |
| **Product** | Product Type | is, is not (simple/variable/subscription/bundle) |
| **Quantity** | Line Item Quantity | =, !=, >, <, >=, <= |
| **Price** | Line Item Subtotal | =, !=, >, <, >=, <= |
| **Cart** | Cart Subtotal | =, !=, >, <, >=, <= |
| **Cart** | Cart Item Count | =, !=, >, <, >=, <= |
| **Cart** | Cart Total Quantity | =, !=, >, <, >=, <= |
| **Customer** | User Role | is, is not |
| **Customer** | Is Logged In | yes, no |
| **Customer** | Customer Order Count | =, !=, >, <, >=, <= |
| **Customer** | Customer Total Spend | =, !=, >, <, >=, <= |
| **Customer** | Customer Email Domain | is, contains |
| **Customer** | Customer Created Date | before, after, between |
| **Subscription** | Payment Type | is (onetime/subscription) |
| **Subscription** | Order Type | is (initial/renewal/plan_change) |
| **Subscription** | Billing Interval | is (daily/weekly/monthly/yearly) |
| **Time** | Current Date | before, after, between |
| **Time** | Day of Week | is, is not |

#### Cart-Level Conditions

| Category | Attribute | Operators |
|----------|-----------|-----------|
| **Cart** | Cart Subtotal | =, !=, >, <, >=, <= |
| **Cart** | Cart Item Count | =, !=, >, <, >=, <= |
| **Cart** | Total Quantity | =, !=, >, <, >=, <= |
| **Cart** | Has Product | contains, does not contain |
| **Cart** | Has Category | contains, does not contain |
| **Cart** | Cart Weight | =, !=, >, <, >=, <= |
| **Customer** | User Role | is, is not |
| **Customer** | Is Logged In | yes, no |
| **Customer** | Order Count | =, !=, >, <, >=, <= |
| **Customer** | Total Spend | =, !=, >, <, >=, <= |
| **Customer** | Email Domain | is, contains |
| **Shipping** | Shipping Method | is, is not |
| **Payment** | Payment Method | is, is not |
| **Order** | Order Type | is (initial/renewal) |

### 3.5 Condition Logic

Conditions support AND/OR grouping:

```
(Product is "T-Shirt" AND Quantity >= 3)
OR
(Product Category is "Clearance" AND Cart Subtotal > $100)
```

Each rule has an array of **condition groups** (OR'd together).
Each group has an array of **conditions** (AND'd together).

### 3.6 Rule Priority & Stacking

**Global Settings** (configurable per target × type):

| Strategy | Behavior |
|----------|----------|
| **All** | All matching rules apply (stacked). Default |
| **First** | Only the first matching rule (by priority) applies |
| **Largest** | Only the rule producing the largest adjustment applies |
| **Smallest** | Only the rule producing the smallest adjustment applies |

Each rule also has:
- **Priority** (integer, lower = higher priority)
- **Exclusive** flag (if this rule matches, skip all others for this target)
- **Max Discount Cap** (optional ceiling on the discount amount)

### 3.7 Pre-built Templates

| # | Template | Target | Adjustment | Pre-filled Conditions |
|---|----------|--------|------------|-----------------------|
| 1 | Bulk Purchase Discount | Line Item | 10% discount | Quantity >= 10 |
| 2 | Buy One Get One Free | Line Item | 100% discount on 1 | Quantity >= 2, applies to cheapest |
| 3 | Free Shipping (Min Order) | Shipping | 100% discount | Cart Subtotal >= $75 |
| 4 | First-Time Customer | Line Item | 15% discount | Customer Order Count = 0 |
| 5 | Member/VIP Discount | Line Item | 5% discount | User Role = subscriber/vip |
| 6 | Tiered Quantity Pricing | Line Item | Variable % | Qty 5-9: 5%, 10-24: 10%, 25+: 15% |
| 7 | Subscription Renewal Discount | Line Item | 10% discount | Order Type = renewal |
| 8 | Cart Value Discount | Cart Total | $20 discount | Cart Subtotal >= $200 |
| 9 | Processing Fee (Installments) | Cart Total | 3% fee | Payment Type = subscription |
| 10 | Low Order Surcharge | Cart Total | $5 fee | Cart Subtotal < $25 |
| 11 | Clearance Category Sale | Line Item | 30% discount | Category = "Clearance" |
| 12 | Happy Hour Pricing | Line Item | 20% discount | Day of Week in (Fri, Sat) |

### 3.8 BOGO Implementation

BOGO is a specialized rule type with additional fields:

```
BOGO Rule = {
    buy: { quantity: 1, products: [any|specific], categories: [] }
    get: { quantity: 1, products: [same|specific], categories: [] }
    discount: { type: "percentage", value: 100 }  // 100% = free
    repeat: true  // Buy 2 Get 2, Buy 3 Get 3, etc.
    apply_to: "cheapest"  // cheapest|most_expensive|specific
}
```

Examples:
- **Buy 1 Get 1 Free**: buy 1, get 1 same product at 100% off
- **Buy 2 Get 1 Half Off**: buy 2, get 1 same product at 50% off
- **Buy X Get Y**: buy Product A, get Product B free
- **Buy 3 from Category, Cheapest Free**: buy 3 from "Shirts", cheapest is 100% off

### 3.9 Tiered Pricing Display

For products with quantity-based rules, show a pricing table on the product page:

```
┌──────────┬───────────┬──────────┐
│ Quantity │ Price     │ Savings  │
├──────────┼───────────┼──────────┤
│ 1-4      │ $29.99    │ —        │
│ 5-9      │ $28.49    │ 5% off   │
│ 10-24    │ $26.99    │ 10% off  │
│ 25+      │ $25.49    │ 15% off  │
└──────────┴───────────┴──────────┘
```

The price on the product page updates dynamically as the customer changes quantity.

---

## 4. Data Model & Schema

### 4.1 Database Tables

```sql
-- Main rules table
CREATE TABLE fct_dynamic_pricing_rules (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title            VARCHAR(255) NOT NULL,           -- Internal name
    display_name     VARCHAR(255) DEFAULT NULL,       -- Customer-facing name
    status           VARCHAR(20) DEFAULT 'draft',     -- draft|active|inactive|scheduled
    rule_type        VARCHAR(30) NOT NULL,            -- discount|fee|bogo
    target           VARCHAR(20) NOT NULL,            -- line_item|cart|shipping
    priority         INT DEFAULT 10,                  -- Lower = higher priority
    is_exclusive     TINYINT(1) DEFAULT 0,            -- Exclusive rule flag
    adjustment_type  VARCHAR(20) NOT NULL,            -- percentage|fixed_amount|fixed_price
    adjustment_value BIGINT NOT NULL,                 -- In cents (or basis points for %)
    max_discount     BIGINT DEFAULT NULL,             -- Max discount cap in cents
    conditions       LONGTEXT DEFAULT NULL,           -- JSON: condition groups
    bogo_config      LONGTEXT DEFAULT NULL,           -- JSON: BOGO specific config
    schedule_start   TIMESTAMP NULL DEFAULT NULL,     -- Rule active from
    schedule_end     TIMESTAMP NULL DEFAULT NULL,     -- Rule active until
    day_of_week      VARCHAR(50) DEFAULT NULL,        -- JSON array: [1,2,3,4,5]
    usage_count      BIGINT DEFAULT 0,                -- Times this rule was applied
    usage_limit      BIGINT DEFAULT NULL,             -- Max times rule can be used
    per_customer     BIGINT DEFAULT NULL,             -- Max uses per customer
    settings         LONGTEXT DEFAULT NULL,           -- JSON: extra config
    created_by       BIGINT UNSIGNED DEFAULT NULL,
    created_at       TIMESTAMP NULL DEFAULT NULL,
    updated_at       TIMESTAMP NULL DEFAULT NULL,

    INDEX idx_status_priority (status, priority),
    INDEX idx_target (target),
    INDEX idx_schedule (schedule_start, schedule_end)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Rule usage tracking (for per-customer limits)
CREATE TABLE fct_dynamic_pricing_usage (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rule_id      BIGINT UNSIGNED NOT NULL,
    order_id     BIGINT UNSIGNED DEFAULT NULL,
    customer_id  BIGINT UNSIGNED DEFAULT NULL,
    discount_amount BIGINT DEFAULT 0,                 -- Amount discounted in cents
    created_at   TIMESTAMP NULL DEFAULT NULL,

    INDEX idx_rule_customer (rule_id, customer_id),
    INDEX idx_order (order_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Global settings stored in fct_meta via fluent_cart_update_option()
```

### 4.2 Conditions JSON Structure

```json
{
    "match": "any",
    "groups": [
        {
            "match": "all",
            "conditions": [
                {
                    "attribute": "line_item_quantity",
                    "operator": "gte",
                    "value": 5
                },
                {
                    "attribute": "product_category",
                    "operator": "in",
                    "value": [12, 45, 78]
                }
            ]
        },
        {
            "match": "all",
            "conditions": [
                {
                    "attribute": "customer_role",
                    "operator": "is",
                    "value": "wholesale"
                }
            ]
        }
    ]
}
```

**Logic:** `(qty >= 5 AND category in [12,45,78]) OR (role = wholesale)`

### 4.3 BOGO Config JSON Structure

```json
{
    "buy": {
        "quantity": 2,
        "type": "specific_products",
        "product_ids": [101, 102],
        "category_ids": []
    },
    "get": {
        "quantity": 1,
        "type": "same_products",
        "product_ids": [],
        "category_ids": []
    },
    "discount": {
        "type": "percentage",
        "value": 10000
    },
    "apply_to": "cheapest",
    "repeat": false,
    "max_free_quantity": 1
}
```

### 4.4 Settings Structure

```json
{
    "stacking": {
        "line_item": { "discount": "all", "fee": "all" },
        "cart":      { "discount": "first", "fee": "all" },
        "shipping":  { "discount": "largest", "fee": "all" }
    },
    "display": {
        "show_pricing_table": true,
        "show_savings_badge": true,
        "show_strikethrough": true,
        "show_discount_name_in_checkout": true,
        "pricing_table_position": "after_price"
    },
    "general": {
        "apply_before_tax": true,
        "apply_before_coupon": true,
        "exclude_sale_items": false,
        "exclude_bundled_items": false
    }
}
```

---

## 5. Architecture & Data Flow

### 5.1 High-Level Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    FluentCart Core Plugin                      │
│                                                               │
│  Product ──► Cart ──► [HOOK: price calculation] ──► Checkout  │
│                              │                                │
│                    apply_filters()                             │
│                              │                                │
└──────────────────────────────┼────────────────────────────────┘
                               │
                    ┌──────────▼──────────┐
                    │  Dynamic Pricing     │
                    │  Addon Plugin        │
                    │                      │
                    │  1. Load active rules │
                    │  2. Evaluate conditions│
                    │  3. Apply adjustments │
                    │  4. Return modified   │
                    │     prices/totals     │
                    └──────────────────────┘
```

### 5.2 Pricing Pipeline (with Dynamic Pricing)

```
Step 1: Product Added to Cart
    ├── CartHelper::generateCartItemFromVariation()
    ├── unit_price = variation->item_price
    ├── ★ FILTER: fluent_cart/cart/item_price (NEW - addon intercepts)
    └── subtotal = unit_price * quantity

Step 2: Cart Items Updated
    ├── Cart::updateCartData()
    ├── ★ ACTION: fluent_cart/cart/cart_data_items_updated (existing)
    └── Dynamic Pricing evaluates LINE ITEM rules
        ├── Load active rules where target = 'line_item'
        ├── For each cart item:
        │   ├── Evaluate conditions against item + cart context
        │   ├── Apply stacking strategy
        │   └── Calculate adjustment → set discount_total or fee_total
        └── ★ FILTER: fluent_cart/cart/item_dynamic_discount (NEW)

Step 3: Coupons Applied
    ├── DiscountService::applyCouponCodes()
    ├── ★ FILTER: fluent_cart/discount/pre_apply (NEW)
    │   └── Dynamic Pricing can adjust coupon interaction
    └── Coupon discounts applied on top of (or instead of) dynamic discounts

Step 4: Totals Calculated
    ├── OrderService::getItemsAmountTotal()
    ├── ★ FILTER: fluent_cart/cart/items_total (NEW)
    │   └── Dynamic Pricing applies CART-level rules
    ├── Cart::getShippingTotal()
    ├── ★ FILTER: fluent_cart/cart/shipping_total (NEW)
    │   └── Dynamic Pricing applies SHIPPING rules
    └── Cart::getEstimatedTotal()
        └── apply_filters('fluent_cart/cart/estimated_total') (EXISTING)

Step 5: Checkout Display
    ├── ★ FILTER: fluent_cart/checkout/summary_line_items (NEW)
    │   └── Dynamic Pricing injects discount/fee line items
    └── Customer sees: Subtotal, Dynamic Discount (-$X), Shipping, Tax, Total

Step 6: Order Placed
    ├── CheckoutApi::placeOrder()
    ├── ★ ACTION: fluent_cart/order/dynamic_pricing_applied (NEW)
    │   └── Record usage in fct_dynamic_pricing_usage table
    └── Discount amounts persisted in order items
```

### 5.3 Rule Evaluation Engine

```
evaluateRules(cart, target):
    rules = loadActiveRules(target)  // cached per request
    rules = filterBySchedule(rules)
    rules = sortByPriority(rules)

    matchedRules = []
    for each rule in rules:
        if evaluateConditions(rule.conditions, cart):
            matchedRules.append(rule)
            if rule.is_exclusive:
                break

    return applyStackingStrategy(matchedRules, target)

evaluateConditions(conditions, cart):
    for each group in conditions.groups:       // OR
        allMatch = true
        for each condition in group.conditions: // AND
            if not evaluateCondition(condition, cart):
                allMatch = false
                break
        if allMatch:
            return true
    return false

applyStackingStrategy(rules, target):
    strategy = getStrategy(target)
    switch strategy:
        case 'all':     return sumAllAdjustments(rules)
        case 'first':   return rules[0].adjustment
        case 'largest': return max(rules, by: adjustment)
        case 'smallest': return min(rules, by: adjustment)
```

### 5.4 BOGO Evaluation Flow

```
evaluateBogo(rule, cartItems):
    buyItems = filterBuyItems(cartItems, rule.bogo_config.buy)
    totalBuyQty = sum(buyItems.quantity)

    requiredBuyQty = rule.bogo_config.buy.quantity
    if totalBuyQty < requiredBuyQty:
        return null  // condition not met

    // Calculate how many "sets" the customer qualifies for
    if rule.bogo_config.repeat:
        sets = floor(totalBuyQty / requiredBuyQty)
    else:
        sets = 1

    freeQty = sets * rule.bogo_config.get.quantity

    // Find items to discount
    getItems = filterGetItems(cartItems, rule.bogo_config.get)

    switch rule.bogo_config.apply_to:
        case 'cheapest':
            sortByPrice(getItems, ASC)
        case 'most_expensive':
            sortByPrice(getItems, DESC)

    // Apply discount to 'freeQty' items
    return applyBogoDiscount(getItems, freeQty, rule.bogo_config.discount)
```

---

## 6. Required Hooks in FluentCart Core

These filters and actions need to be added to the FluentCart core plugin to support the dynamic pricing addon. This is the most critical section — it defines the contract between core and addon.

### 6.1 New Filters to Add

#### Filter 1: `fluent_cart/cart/item_price`
**Purpose:** Allow modification of unit price when cart item is created
**Location:** `app/Helpers/CartHelper.php` in `generateCartItemFromVariation()`

```php
// In CartHelper::generateCartItemFromVariation(), after line ~35
$itemPrice = apply_filters('fluent_cart/cart/item_price', $variation->item_price, [
    'variation' => $variation,
    'quantity'  => $quantity,
    'cart'      => null // cart not available at this point
]);
$subtotal = $itemPrice * $quantity;
```

#### Filter 2: `fluent_cart/cart/item_dynamic_discount`
**Purpose:** Apply dynamic pricing discounts to individual cart items
**Location:** `app/Models/Cart.php` after cart data items update

```php
// In Cart.php, add a new method called after cart_data_items_updated
$cartData = apply_filters('fluent_cart/cart/item_dynamic_discount', $this->cart_data, [
    'cart' => $this
]);
```

#### Filter 3: `fluent_cart/cart/items_total`
**Purpose:** Apply cart-level adjustments to the items total
**Location:** `app/Services/OrderService.php` in `getItemsAmountTotal()`

```php
// In OrderService::getItemsAmountTotal(), before return
$total = apply_filters('fluent_cart/cart/items_total', $total, [
    'items'          => $items,
    'shipping_total' => $shippingTotal
]);
```

#### Filter 4: `fluent_cart/cart/shipping_total`
**Purpose:** Apply shipping-level adjustments (e.g., free shipping)
**Location:** `app/Models/Cart.php` in `getShippingTotal()`

```php
// In Cart::getShippingTotal()
$shippingTotal = (int)Arr::get($this->checkout_data ?? [], 'shipping_data.shipping_charge', 0);
$shippingTotal = apply_filters('fluent_cart/cart/shipping_total', $shippingTotal, [
    'cart' => $this
]);
return $shippingTotal;
```

#### Filter 5: `fluent_cart/checkout/summary_extra_lines`
**Purpose:** Inject discount/fee display lines into checkout summary
**Location:** Checkout renderer (PHP & JS)

```php
// In checkout summary rendering
$extraLines = apply_filters('fluent_cart/checkout/summary_extra_lines', [], [
    'cart' => $cart
]);
// Returns: [['label' => 'Bulk Discount', 'amount' => -500, 'type' => 'discount'], ...]
```

#### Filter 6: `fluent_cart/discount/pre_apply`
**Purpose:** Allow dynamic pricing to interact with coupon application
**Location:** `app/Services/Coupon/DiscountService.php`

```php
// Before applying coupon discount
$cartItems = apply_filters('fluent_cart/discount/pre_apply', $this->cartItems, [
    'coupon' => $coupon,
    'cart'   => $this->cart
]);
```

#### Filter 7: `fluent_cart/product/display_price`
**Purpose:** Show dynamic pricing on product pages (tiered tables, adjusted prices)
**Location:** Product price rendering

```php
// In ProductRenderer price display
$priceHtml = apply_filters('fluent_cart/product/display_price', $priceHtml, [
    'product'   => $product,
    'variation' => $variation
]);
```

#### Filter 8: `fluent_cart/cart/context_data`
**Purpose:** Provide full cart context for condition evaluation
**Location:** New helper method

```php
// Centralized context builder for dynamic pricing
$context = apply_filters('fluent_cart/cart/context_data', [
    'cart_subtotal'      => $cart->getItemsSubtotal(),
    'cart_item_count'    => count($cart->cart_data),
    'cart_total_quantity' => array_sum(array_column($cart->cart_data, 'quantity')),
    'shipping_method'    => Arr::get($cart->checkout_data, 'shipping_data.method_id'),
    'payment_method'     => Arr::get($cart->checkout_data, 'payment_method'),
    'customer_id'        => $cart->customer_id,
    'customer'           => $customer,
    'user_role'          => $userRole,
    'order_type'         => Arr::get($cart->checkout_data, 'order_type', 'initial'),
], ['cart' => $cart]);
```

### 6.2 New Actions to Add

#### Action 1: `fluent_cart/order/after_items_calculated`
**Purpose:** Record dynamic pricing usage after order is finalized
**Location:** `api/Checkout/CheckoutApi.php` after order creation

```php
do_action('fluent_cart/order/after_items_calculated', [
    'order'      => $order,
    'cart'       => $cart,
    'cart_items' => $cartItems
]);
```

#### Action 2: `fluent_cart/cart/before_totals_calculation`
**Purpose:** Allow addons to prepare before totals are calculated
**Location:** Before total calculation in Cart model

```php
do_action('fluent_cart/cart/before_totals_calculation', [
    'cart' => $this
]);
```

#### Action 3: `fluent_cart/cart/after_totals_calculation`
**Purpose:** Allow addons to run logic after totals are finalized
**Location:** After total calculation

```php
do_action('fluent_cart/cart/after_totals_calculation', [
    'cart'  => $this,
    'total' => $total
]);
```

### 6.3 Summary: Changes to Core Files

| File | Change | Lines Affected |
|------|--------|----------------|
| `app/Helpers/CartHelper.php` | Add `fluent_cart/cart/item_price` filter | ~3 lines |
| `app/Models/Cart.php` | Add `fluent_cart/cart/item_dynamic_discount` filter | ~5 lines |
| `app/Models/Cart.php` | Add `fluent_cart/cart/shipping_total` filter | ~3 lines |
| `app/Models/Cart.php` | Add before/after totals calculation actions | ~6 lines |
| `app/Services/OrderService.php` | Add `fluent_cart/cart/items_total` filter | ~4 lines |
| `app/Services/Coupon/DiscountService.php` | Add `fluent_cart/discount/pre_apply` filter | ~4 lines |
| `api/Checkout/CheckoutApi.php` | Add `fluent_cart/order/after_items_calculated` action | ~4 lines |
| Checkout renderers (PHP) | Add `fluent_cart/checkout/summary_extra_lines` filter | ~5 lines |
| Product renderers (PHP) | Add `fluent_cart/product/display_price` filter | ~3 lines |

**Total core changes: ~37 lines across 7 files** — Minimal, non-breaking additions.

---

## 7. Addon Plugin Structure

### 7.1 Directory Structure

```
fluent-cart-dynamic-pricing/
├── fluent-cart-dynamic-pricing.php          # Plugin bootstrap
├── composer.json
├── package.json
│
├── app/
│   ├── Boot.php                             # Plugin initialization
│   ├── Hooks/
│   │   ├── CartHooks.php                    # Hook into cart filters
│   │   ├── CheckoutHooks.php                # Hook into checkout filters
│   │   ├── ProductDisplayHooks.php          # Hook into product page
│   │   └── OrderHooks.php                   # Hook into order completion
│   │
│   ├── Models/
│   │   ├── PricingRule.php                  # Eloquent model for rules
│   │   └── PricingUsage.php                 # Eloquent model for usage tracking
│   │
│   ├── Services/
│   │   ├── RuleEngine.php                   # Core rule evaluation engine
│   │   ├── ConditionEvaluator.php           # Condition evaluation logic
│   │   ├── AdjustmentCalculator.php         # Calculate price adjustments
│   │   ├── BogoHandler.php                  # BOGO-specific logic
│   │   ├── StackingResolver.php             # Apply stacking strategies
│   │   ├── ContextBuilder.php               # Build evaluation context
│   │   └── PricingTableGenerator.php        # Generate pricing tables for display
│   │
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── RuleController.php           # CRUD for pricing rules
│   │   │   ├── SettingsController.php       # Global settings
│   │   │   └── AnalyticsController.php      # Discount performance data
│   │   ├── Routes/
│   │   │   └── api.php                      # REST API routes
│   │   └── Policies/
│   │       └── RulePolicy.php               # Authorization
│   │
│   └── Database/
│       └── Migrations/
│           ├── 001_create_pricing_rules.php
│           └── 002_create_pricing_usage.php
│
├── resources/
│   ├── admin/                               # Vue 3 admin UI
│   │   ├── components/
│   │   │   ├── RuleList.vue                 # Rule listing page
│   │   │   ├── RuleEditor.vue               # Rule editor page
│   │   │   ├── ConditionBuilder.vue         # Visual condition builder
│   │   │   ├── TemplateGallery.vue          # Pre-built templates
│   │   │   ├── BogoConfigurator.vue         # BOGO-specific UI
│   │   │   ├── AnalyticsDashboard.vue       # Discount analytics
│   │   │   └── GlobalSettings.vue           # Stacking/display settings
│   │   └── routes.js                        # Admin SPA routes
│   │
│   └── public/                              # Frontend display
│       ├── PricingTable.vue                 # Quantity pricing table
│       ├── DynamicPriceDisplay.vue          # Real-time price update
│       └── CheckoutDiscountLine.vue         # Checkout summary line
│
└── assets/
    └── css/
        └── pricing-table.css                # Pricing table styles
```

### 7.2 Plugin Bootstrap

```php
<?php
/**
 * Plugin Name: FluentCart Dynamic Pricing
 * Description: Advanced dynamic pricing rules for FluentCart — included free with FluentCart Pro
 * Version: 1.0.0
 * Requires Plugins: fluent-cart
 */

defined('ABSPATH') || exit;

define('FCDP_VERSION', '1.0.0');
define('FCDP_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('FCDP_PLUGIN_URL', plugin_dir_url(__FILE__));

// Wait for FluentCart to load
add_action('fluentcart_loaded', function ($app) {
    // Check minimum FluentCart version
    if (version_compare(FLUENT_CART_VERSION, '1.5.0', '<')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('FluentCart Dynamic Pricing requires FluentCart 1.5.0 or higher.', 'fluent-cart-dynamic-pricing');
            echo '</p></div>';
        });
        return;
    }

    // Verify FluentCart Pro license is active
    if (!defined('FLUENT_CART_PRO') || !FLUENT_CART_PRO) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-warning"><p>';
            echo wp_kses_post(
                __('FluentCart Dynamic Pricing requires an active <a href="https://fluentcart.com/pricing">FluentCart Pro</a> license. It\'s included free with all Pro plans!', 'fluent-cart-dynamic-pricing')
            );
            echo '</p></div>';
        });
        // Still register the menu item so free users see the feature overview / upgrade page
        require_once FCDP_PLUGIN_PATH . 'app/UpgradePrompt.php';
        (new \FluentCartDynamicPricing\App\UpgradePrompt($app))->register();
        return;
    }

    require_once FCDP_PLUGIN_PATH . 'app/Boot.php';
    (new \FluentCartDynamicPricing\App\Boot($app))->init();
});
```

> **Note on UpgradePrompt:** When FluentCart Free is active (no Pro license), the addon still loads a lightweight `UpgradePrompt` class that registers a "Dynamic Pricing" menu item in the admin. Clicking it shows a feature overview page with screenshots, use cases, and a CTA to upgrade to Pro. This is the primary in-app conversion driver for free users.

---

## 8. Code Samples

### 8.1 Rule Engine (Core Service)

```php
<?php

namespace FluentCartDynamicPricing\App\Services;

use FluentCartDynamicPricing\App\Models\PricingRule;
use FluentCart\Framework\Support\Arr;

class RuleEngine
{
    private static $rulesCache = null;
    private ConditionEvaluator $conditionEvaluator;
    private StackingResolver $stackingResolver;
    private AdjustmentCalculator $calculator;

    public function __construct()
    {
        $this->conditionEvaluator = new ConditionEvaluator();
        $this->stackingResolver = new StackingResolver();
        $this->calculator = new AdjustmentCalculator();
    }

    /**
     * Evaluate all rules for a target against the current cart context
     */
    public function evaluate(string $target, array $cartItems, array $context): array
    {
        $rules = $this->getActiveRules($target);
        $matchedRules = [];

        foreach ($rules as $rule) {
            if (!$this->isInSchedule($rule)) {
                continue;
            }

            if (!$this->checkUsageLimits($rule, $context)) {
                continue;
            }

            if ($this->conditionEvaluator->evaluate($rule->conditions, $cartItems, $context)) {
                $matchedRules[] = $rule;

                if ($rule->is_exclusive) {
                    break; // Exclusive rule — stop evaluating
                }
            }
        }

        if (empty($matchedRules)) {
            return ['adjustments' => [], 'total_adjustment' => 0];
        }

        return $this->stackingResolver->resolve($matchedRules, $target, $cartItems, $context);
    }

    /**
     * Load active rules for a target, sorted by priority (cached per request)
     */
    private function getActiveRules(string $target): array
    {
        if (self::$rulesCache === null) {
            self::$rulesCache = PricingRule::where('status', 'active')
                ->orderBy('priority', 'ASC')
                ->orderBy('id', 'ASC')
                ->get()
                ->toArray();
        }

        return array_filter(self::$rulesCache, function ($rule) use ($target) {
            return $rule['target'] === $target;
        });
    }

    private function isInSchedule(array $rule): bool
    {
        $now = current_time('timestamp', true); // GMT

        if ($rule['schedule_start'] && strtotime($rule['schedule_start']) > $now) {
            return false;
        }
        if ($rule['schedule_end'] && strtotime($rule['schedule_end']) < $now) {
            return false;
        }
        if ($rule['day_of_week']) {
            $days = json_decode($rule['day_of_week'], true);
            $today = (int) gmdate('N'); // 1=Mon, 7=Sun
            if (!in_array($today, $days)) {
                return false;
            }
        }
        return true;
    }

    private function checkUsageLimits(array $rule, array $context): bool
    {
        if ($rule['usage_limit'] && $rule['usage_count'] >= $rule['usage_limit']) {
            return false;
        }

        if ($rule['per_customer'] && !empty($context['customer_id'])) {
            $customerUsage = PricingUsage::where('rule_id', $rule['id'])
                ->where('customer_id', $context['customer_id'])
                ->count();
            if ($customerUsage >= $rule['per_customer']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Clear the per-request cache (call on cart updates)
     */
    public static function clearCache(): void
    {
        self::$rulesCache = null;
    }
}
```

### 8.2 Condition Evaluator

```php
<?php

namespace FluentCartDynamicPricing\App\Services;

use FluentCart\Framework\Support\Arr;

class ConditionEvaluator
{
    /**
     * Evaluate condition groups (OR logic between groups, AND within)
     */
    public function evaluate(?array $conditions, array $cartItems, array $context): bool
    {
        if (empty($conditions) || empty($conditions['groups'])) {
            return true; // No conditions = always matches
        }

        // OR between groups — any group passing is sufficient
        foreach ($conditions['groups'] as $group) {
            if ($this->evaluateGroup($group, $cartItems, $context)) {
                return true;
            }
        }

        return false;
    }

    /**
     * All conditions in a group must match (AND logic)
     */
    private function evaluateGroup(array $group, array $cartItems, array $context): bool
    {
        foreach (Arr::get($group, 'conditions', []) as $condition) {
            if (!$this->evaluateCondition($condition, $cartItems, $context)) {
                return false;
            }
        }
        return true;
    }

    private function evaluateCondition(array $condition, array $cartItems, array $context): bool
    {
        $attribute = $condition['attribute'];
        $operator = $condition['operator'];
        $value = $condition['value'];

        $actualValue = $this->resolveAttribute($attribute, $cartItems, $context);

        return $this->compare($actualValue, $operator, $value);
    }

    private function resolveAttribute(string $attribute, array $cartItems, array $context)
    {
        return match ($attribute) {
            // Cart attributes
            'cart_subtotal'       => Arr::get($context, 'cart_subtotal', 0),
            'cart_item_count'     => Arr::get($context, 'cart_item_count', 0),
            'cart_total_quantity'  => Arr::get($context, 'cart_total_quantity', 0),

            // Customer attributes
            'customer_role'       => Arr::get($context, 'user_role', 'guest'),
            'customer_logged_in'  => !empty($context['customer_id']),
            'customer_order_count' => $this->getCustomerOrderCount($context),
            'customer_total_spend' => $this->getCustomerTotalSpend($context),
            'customer_email_domain' => $this->getCustomerEmailDomain($context),

            // Payment/shipping
            'payment_method'      => Arr::get($context, 'payment_method'),
            'shipping_method'     => Arr::get($context, 'shipping_method'),

            // Order type
            'order_type'          => Arr::get($context, 'order_type', 'initial'),
            'payment_type'        => Arr::get($context, 'payment_type', 'onetime'),

            // Time
            'day_of_week'         => (int) gmdate('N'),
            'current_date'        => gmdate('Y-m-d'),

            default => null
        };
    }

    /**
     * Evaluate per-item attributes (used for line item rules)
     */
    public function evaluateForItem(array $condition, array $item, array $context): bool
    {
        $attribute = $condition['attribute'];
        $operator = $condition['operator'];
        $value = $condition['value'];

        $actualValue = match ($attribute) {
            'specific_products'    => Arr::get($item, 'object_id'),
            'product_category'     => $this->getItemCategories($item),
            'product_tags'         => $this->getItemTags($item),
            'product_sku'          => Arr::get($item, 'sku', ''),
            'product_type'         => Arr::get($item, 'variation_type', 'simple'),
            'line_item_quantity'   => (int) Arr::get($item, 'quantity', 0),
            'line_item_subtotal'   => (int) Arr::get($item, 'subtotal', 0),
            'payment_type'         => Arr::get($item, 'other_info.payment_type', 'onetime'),
            'billing_interval'     => Arr::get($item, 'other_info.repeat_interval'),
            default                => $this->resolveAttribute($attribute, [], $context)
        };

        return $this->compare($actualValue, $operator, $value);
    }

    private function compare($actual, string $operator, $expected): bool
    {
        return match ($operator) {
            'is', 'eq', '='     => $actual == $expected,
            'is_not', 'neq', '!=' => $actual != $expected,
            'gt', '>'            => $actual > $expected,
            'lt', '<'            => $actual < $expected,
            'gte', '>='          => $actual >= $expected,
            'lte', '<='          => $actual <= $expected,
            'in'                 => is_array($expected) ? in_array($actual, $expected) : $actual == $expected,
            'not_in'             => is_array($expected) ? !in_array($actual, $expected) : $actual != $expected,
            'contains'           => is_array($actual)
                                    ? !empty(array_intersect((array)$expected, $actual))
                                    : str_contains((string) $actual, (string) $expected),
            'not_contains'       => is_array($actual)
                                    ? empty(array_intersect((array)$expected, $actual))
                                    : !str_contains((string) $actual, (string) $expected),
            'between'            => is_array($expected) && count($expected) === 2
                                    && $actual >= $expected[0] && $actual <= $expected[1],
            'before'             => strtotime($actual) < strtotime($expected),
            'after'              => strtotime($actual) > strtotime($expected),
            default              => false
        };
    }

    private function getCustomerOrderCount(array $context): int
    {
        $customerId = Arr::get($context, 'customer_id');
        if (!$customerId) return 0;

        return \FluentCart\App\Models\Order::where('customer_id', $customerId)
            ->whereIn('status', ['completed', 'processing'])
            ->count();
    }

    private function getCustomerTotalSpend(array $context): int
    {
        $customerId = Arr::get($context, 'customer_id');
        if (!$customerId) return 0;

        return (int) \FluentCart\App\Models\Order::where('customer_id', $customerId)
            ->whereIn('status', ['completed', 'processing'])
            ->sum('total');
    }

    private function getCustomerEmailDomain(array $context): string
    {
        $email = Arr::get($context, 'customer_email', '');
        $parts = explode('@', $email);
        return $parts[1] ?? '';
    }

    private function getItemCategories(array $item): array
    {
        $postId = Arr::get($item, 'post_id');
        if (!$postId) return [];
        $terms = wp_get_post_terms($postId, 'fluent-product-category', ['fields' => 'ids']);
        return is_array($terms) ? $terms : [];
    }

    private function getItemTags(array $item): array
    {
        $postId = Arr::get($item, 'post_id');
        if (!$postId) return [];
        $terms = wp_get_post_terms($postId, 'fluent-product-tag', ['fields' => 'ids']);
        return is_array($terms) ? $terms : [];
    }
}
```

### 8.3 Cart Hooks (Addon Hooking into Core)

```php
<?php

namespace FluentCartDynamicPricing\App\Hooks;

use FluentCartDynamicPricing\App\Services\RuleEngine;
use FluentCartDynamicPricing\App\Services\ContextBuilder;
use FluentCart\Framework\Support\Arr;

class CartHooks
{
    private RuleEngine $engine;

    public function __construct()
    {
        $this->engine = new RuleEngine();
    }

    public function register(): void
    {
        // Apply line-item dynamic pricing after cart items are updated
        add_filter('fluent_cart/cart/item_dynamic_discount', [$this, 'applyLineItemRules'], 10, 2);

        // Apply cart-level rules to items total
        add_filter('fluent_cart/cart/items_total', [$this, 'applyCartRules'], 10, 2);

        // Apply shipping rules
        add_filter('fluent_cart/cart/shipping_total', [$this, 'applyShippingRules'], 10, 2);

        // Add discount/fee lines to checkout summary
        add_filter('fluent_cart/checkout/summary_extra_lines', [$this, 'getExtraLines'], 10, 2);

        // Record usage on order completion
        add_action('fluent_cart/order/after_items_calculated', [$this, 'recordUsage'], 10, 1);

        // Clear cache on cart updates
        add_action('fluent_cart/cart/cart_data_items_updated', function () {
            RuleEngine::clearCache();
        });
    }

    public function applyLineItemRules(array $cartData, array $args): array
    {
        $cart = $args['cart'];
        $context = ContextBuilder::build($cart);

        $result = $this->engine->evaluate('line_item', $cartData, $context);

        foreach ($result['adjustments'] as $adjustment) {
            $itemId = $adjustment['item_id'];
            foreach ($cartData as &$item) {
                if (Arr::get($item, 'object_id') == $itemId) {
                    $item['dynamic_discount'] = $adjustment['amount'];
                    $item['dynamic_discount_label'] = $adjustment['label'];
                    $item['discount_total'] += $adjustment['amount'];
                    $item['line_total'] = max(0, $item['subtotal'] - $item['discount_total']);
                }
            }
        }

        return $cartData;
    }

    public function applyCartRules(int $total, array $args): int
    {
        $items = $args['items'];
        $context = ContextBuilder::buildFromItems($items);

        $result = $this->engine->evaluate('cart', $items, $context);

        foreach ($result['adjustments'] as $adjustment) {
            if ($adjustment['type'] === 'discount') {
                $total = max(0, $total - $adjustment['amount']);
            } else {
                $total += $adjustment['amount']; // Fee
            }
        }

        return $total;
    }

    public function applyShippingRules(int $shippingTotal, array $args): int
    {
        $cart = $args['cart'];
        $context = ContextBuilder::build($cart);

        $result = $this->engine->evaluate('shipping', $cart->cart_data ?? [], $context);

        foreach ($result['adjustments'] as $adjustment) {
            if ($adjustment['type'] === 'discount') {
                $shippingTotal = max(0, $shippingTotal - $adjustment['amount']);
            } else {
                $shippingTotal += $adjustment['amount'];
            }
        }

        return $shippingTotal;
    }

    public function getExtraLines(array $lines, array $args): array
    {
        // Return line items showing dynamic pricing adjustments in checkout
        $cart = $args['cart'];
        $context = ContextBuilder::build($cart);

        $allAdjustments = [];
        foreach (['line_item', 'cart', 'shipping'] as $target) {
            $result = $this->engine->evaluate($target, $cart->cart_data ?? [], $context);
            $allAdjustments = array_merge($allAdjustments, $result['adjustments']);
        }

        foreach ($allAdjustments as $adj) {
            $lines[] = [
                'label'  => $adj['label'] ?: __('Dynamic Discount', 'fluent-cart-dynamic-pricing'),
                'amount' => $adj['type'] === 'discount' ? -$adj['amount'] : $adj['amount'],
                'type'   => $adj['type'],
            ];
        }

        return $lines;
    }

    public function recordUsage(array $args): void
    {
        // Record which rules were applied to this order
        $order = $args['order'];
        $cart = $args['cart'];

        // Tracked via meta on order or in fct_dynamic_pricing_usage table
        $appliedRules = Arr::get($cart->checkout_data, '_dynamic_pricing_rules', []);

        foreach ($appliedRules as $ruleData) {
            PricingUsage::create([
                'rule_id'         => $ruleData['rule_id'],
                'order_id'        => $order->id,
                'customer_id'     => $order->customer_id,
                'discount_amount' => $ruleData['amount'],
                'created_at'      => current_time('mysql', true),
            ]);

            PricingRule::where('id', $ruleData['rule_id'])
                ->increment('usage_count');
        }
    }
}
```

### 8.4 REST API Controller

```php
<?php

namespace FluentCartDynamicPricing\App\Http\Controllers;

use FluentCartDynamicPricing\App\Models\PricingRule;
use FluentCart\App\Http\Controllers\Controller;
use FluentCart\Framework\Request\Request;
use FluentCart\Framework\Support\Arr;

class RuleController extends Controller
{
    public function index(Request $request)
    {
        $query = PricingRule::query();

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($target = $request->get('target')) {
            $query->where('target', $target);
        }

        if ($search = $request->get('search')) {
            $query->where('title', 'LIKE', '%' . $search . '%');
        }

        $rules = $query->orderBy('priority', 'ASC')
            ->paginate($request->get('per_page', 20));

        return [
            'data' => $rules->items(),
            'total' => $rules->total(),
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title'            => 'required|string|max:255',
            'display_name'     => 'nullable|string|max:255',
            'rule_type'        => 'required|in:discount,fee,bogo',
            'target'           => 'required|in:line_item,cart,shipping',
            'adjustment_type'  => 'required|in:percentage,fixed_amount,fixed_price',
            'adjustment_value' => 'required|numeric',
            'conditions'       => 'nullable|array',
            'bogo_config'      => 'nullable|array',
            'priority'         => 'nullable|integer',
            'schedule_start'   => 'nullable|date',
            'schedule_end'     => 'nullable|date',
        ]);

        $data['status'] = $request->get('status', 'draft');
        $data['conditions'] = $data['conditions'] ? json_encode($data['conditions']) : null;
        $data['bogo_config'] = $data['bogo_config'] ? json_encode($data['bogo_config']) : null;
        $data['created_at'] = current_time('mysql', true);
        $data['updated_at'] = current_time('mysql', true);
        $data['created_by'] = get_current_user_id();

        $rule = PricingRule::create($data);

        return ['data' => $rule, 'message' => __('Rule created successfully', 'fluent-cart-dynamic-pricing')];
    }

    public function show(Request $request, $id)
    {
        $rule = PricingRule::findOrFail($id);
        return ['data' => $rule];
    }

    public function update(Request $request, $id)
    {
        $rule = PricingRule::findOrFail($id);

        $data = $request->validate([
            'title'            => 'required|string|max:255',
            'display_name'     => 'nullable|string|max:255',
            'rule_type'        => 'required|in:discount,fee,bogo',
            'target'           => 'required|in:line_item,cart,shipping',
            'adjustment_type'  => 'required|in:percentage,fixed_amount,fixed_price',
            'adjustment_value' => 'required|numeric',
            'conditions'       => 'nullable|array',
            'bogo_config'      => 'nullable|array',
        ]);

        $data['conditions'] = isset($data['conditions']) ? json_encode($data['conditions']) : $rule->conditions;
        $data['bogo_config'] = isset($data['bogo_config']) ? json_encode($data['bogo_config']) : $rule->bogo_config;
        $data['updated_at'] = current_time('mysql', true);

        $rule->update($data);

        RuleEngine::clearCache();

        return ['data' => $rule, 'message' => __('Rule updated successfully', 'fluent-cart-dynamic-pricing')];
    }

    public function destroy(Request $request, $id)
    {
        PricingRule::findOrFail($id)->delete();
        return ['message' => __('Rule deleted successfully', 'fluent-cart-dynamic-pricing')];
    }

    public function updatePriority(Request $request)
    {
        $priorities = $request->get('priorities', []);
        foreach ($priorities as $item) {
            PricingRule::where('id', $item['id'])->update(['priority' => $item['priority']]);
        }

        RuleEngine::clearCache();

        return ['message' => __('Priorities updated', 'fluent-cart-dynamic-pricing')];
    }

    public function duplicate(Request $request, $id)
    {
        $original = PricingRule::findOrFail($id);
        $new = $original->replicate();
        $new->title = $original->title . ' (Copy)';
        $new->status = 'draft';
        $new->usage_count = 0;
        $new->created_at = current_time('mysql', true);
        $new->save();

        return ['data' => $new, 'message' => __('Rule duplicated', 'fluent-cart-dynamic-pricing')];
    }
}
```

---

## 9. Frontend Implementation

### 9.1 Admin UI — Rule Editor (Vue 3)

Key components for the admin SPA:

**Rule List Page:**
- Sortable table: Name, Status, Target, Type, Priority, Usage Count, Schedule
- Quick actions: Enable/Disable, Edit, Duplicate, Delete
- Drag-and-drop priority reordering
- Filters: Status (All/Active/Inactive/Scheduled), Target (All/Line Item/Cart/Shipping)
- Bulk actions: Activate, Deactivate, Delete

**Rule Editor Page:**
- Template selection modal (12 pre-built templates)
- Rule identity: Name, Display Name
- Target selector: Line Item / Cart / Shipping (icon buttons)
- Adjustment config: Type dropdown, Value input
- Condition builder: Visual AND/OR condition groups
- Schedule: Start/End date pickers, Day of week checkboxes
- Limits: Usage limit, Per-customer limit
- Preview: Show estimated impact on sample cart
- BOGO configurator: Buy/Get product selectors, apply-to strategy

**Condition Builder:**
- Attribute dropdown (grouped by category)
- Operator dropdown (context-sensitive based on attribute type)
- Value input (text/number/select/multi-select/date based on attribute)
- AND button within group, OR button for new group
- Remove condition button

### 9.2 Storefront — Pricing Table

```html
<!-- Pricing table displayed below product price -->
<div class="fcdp-pricing-table">
    <div class="fcdp-pricing-table__header">
        <span>Quantity</span>
        <span>Price</span>
        <span>Savings</span>
    </div>
    <div class="fcdp-pricing-table__row" v-for="tier in tiers">
        <span>{{ tier.min }}{{ tier.max ? '-' + tier.max : '+' }}</span>
        <span>
            <del v-if="tier.discount">{{ formatPrice(originalPrice) }}</del>
            {{ formatPrice(tier.price) }}
        </span>
        <span class="fcdp-savings">{{ tier.savingsLabel }}</span>
    </div>
</div>
```

### 9.3 Checkout — Discount Lines

Dynamic pricing adjustments appear as line items in the checkout summary between Subtotal and Total:

```
Subtotal:                    $149.97
Bulk Discount (10% off):     -$15.00    ← Dynamic pricing
Free Shipping:               -$9.99     ← Dynamic pricing
Shipping:                     $9.99
Tax:                         $10.80
─────────────────────────────────────
Total:                       $135.78
```

### 9.4 Product Page — Dynamic Price Update

When a customer changes quantity on a product page, the price should update in real-time to reflect quantity-based rules:

```javascript
// Public JS hook for real-time price updates
document.addEventListener('fluentcart_quantity_changed', async (e) => {
    const { variationId, quantity } = e.detail;

    const response = await fetch('/wp-json/fluent-cart/v2/dynamic-pricing/preview', {
        method: 'POST',
        body: JSON.stringify({ variation_id: variationId, quantity }),
    });

    const { adjusted_price, original_price, savings, active_rules } = await response.json();

    // Update price display
    const priceEl = document.querySelector('.fct-product-price');
    if (adjusted_price < original_price) {
        priceEl.innerHTML = `
            <del>${formatPrice(original_price)}</del>
            <ins>${formatPrice(adjusted_price)}</ins>
            <span class="fcdp-you-save">You save ${formatPrice(savings)}!</span>
        `;
    }
});
```

---

## 10. Development Phases

### Phase 1: Foundation (Weeks 1-3)

**Core Plugin Hooks** (in FluentCart main repo):
- [ ] Add all 8 new filters to core (Section 6.1)
- [ ] Add all 3 new actions to core (Section 6.2)
- [ ] Test that existing functionality is unaffected

**Addon Scaffold:**
- [ ] Create addon plugin structure
- [ ] Database migrations (2 tables)
- [ ] PricingRule and PricingUsage Eloquent models
- [ ] REST API routes and controller (CRUD)
- [ ] RulePolicy for authorization

**Rule Engine:**
- [ ] RuleEngine service (load, cache, evaluate)
- [ ] ConditionEvaluator (all operators, attribute resolution)
- [ ] AdjustmentCalculator (percentage, fixed, fixed price)
- [ ] StackingResolver (all 4 strategies)
- [ ] ContextBuilder (cart context assembly)

### Phase 2: Cart Integration (Weeks 4-5)

**Line Item Rules:**
- [ ] CartHooks — hook into `fluent_cart/cart/item_dynamic_discount`
- [ ] Apply per-item discounts and fees
- [ ] Interaction with existing coupon system
- [ ] Test: quantity discounts, category discounts, role-based pricing

**Cart & Shipping Rules:**
- [ ] Cart-level discount/fee application
- [ ] Shipping discount application (free shipping)
- [ ] Test: cart minimum discounts, shipping threshold

**Order Integration:**
- [ ] Record rule usage on order completion
- [ ] Persist discount details in order meta
- [ ] Test: usage limits, per-customer limits

### Phase 3: BOGO (Week 6)

- [ ] BogoHandler service
- [ ] Buy X Get X (same product) logic
- [ ] Buy X Get Y (different product) logic
- [ ] "Cheapest free" and "most expensive free" strategies
- [ ] Repeat flag (Buy 2 Get 2, Buy 4 Get 4)
- [ ] Auto-add free item to cart vs. discount existing item

### Phase 4: Admin UI (Weeks 7-9)

**Vue 3 Components:**
- [ ] Rule listing page with filters and sorting
- [ ] Rule editor with all field types
- [ ] Visual condition builder (AND/OR groups)
- [ ] Template gallery modal (12 templates)
- [ ] BOGO configurator
- [ ] Global settings page (stacking strategies, display options)
- [ ] Drag-and-drop priority ordering
- [ ] Rule duplication
- [ ] Bulk status change

### Phase 5: Frontend Display (Weeks 10-11)

**Product Pages:**
- [ ] Quantity pricing table component
- [ ] Real-time price update on quantity change
- [ ] Strikethrough/sale price display
- [ ] "You Save" messaging
- [ ] Savings badge on product cards

**Checkout:**
- [ ] Dynamic pricing line items in checkout summary
- [ ] Display name integration
- [ ] Real-time updates as cart changes
- [ ] Compatible with all checkout types (standard, modal, instant)

### Phase 6: Analytics & Polish (Week 12)

**Analytics Dashboard:**
- [ ] Discount performance report (revenue impact, usage count)
- [ ] Per-rule analytics (times triggered, total discount given)
- [ ] Date range filtering
- [ ] Top rules by usage / by discount amount
- [ ] Revenue saved/lost tracking

**Polish:**
- [ ] Performance optimization (query caching, rule caching)
- [ ] Edge cases (empty carts, zero prices, subscription renewals)
- [ ] Compatibility testing with all payment gateways
- [ ] Translation strings (text domain: `fluent-cart-dynamic-pricing`)
- [ ] Documentation

---

## 11. Marketing Plan

### 11.1 Positioning

**Tagline:** "Smart Pricing That Sells More — Automatically"

**Value Proposition:**
FluentCart Dynamic Pricing lets you create intelligent pricing rules that automatically adjust prices based on quantity, customer behavior, cart value, and time — no coupon codes needed. Increase AOV, reward loyalty, clear inventory, and protect margins with a powerful yet simple rule engine.

### 11.2 Key Differentiators vs. Competition

| Feature | FluentCart DP | SureCart | WooCommerce Plugins |
|---------|--------------|----------|---------------------|
| **Included free with Pro** | **Yes** | **SaaS plan required** | **$43-149/year extra** |
| Works offline (no SaaS) | Yes | No (SaaS-dependent) | Yes |
| Built-in analytics | Yes | No | Only YayPricing |
| BOGO as first-class feature | Yes | Template only | Varies |
| Fees AND discounts | Yes | Yes | Some |
| Subscription-aware | Yes | Yes | Rare |
| Pricing table on product page | Yes | No | Some |
| 12 pre-built templates | Yes | 8 | Varies |
| AND/OR condition builder | Yes | Yes | Some |
| Real-time price updates | Yes | Yes | Some |

**The killer message:** "WooCommerce stores pay $85+/year for Flycart or YayPricing. SureCart locks it behind their SaaS plan. FluentCart Pro users get it free — with more features than both."

### 11.3 Target Audience

1. **Existing FluentCart users** wanting to grow AOV without coupon codes
2. **B2B/Wholesale stores** needing role-based and quantity-break pricing
3. **Subscription businesses** wanting renewal discounts and plan change incentives
4. **Stores migrating from WooCommerce** that had dynamic pricing plugins
5. **Digital product sellers** wanting tiered license pricing

### 11.4 Distribution Strategy

**Model:** Free addon bundled with FluentCart Pro — full features, no limits, no upsell.

| Audience | What They See | Goal |
|----------|-------------|------|
| **FluentCart Free users** | "Dynamic Pricing" menu item with "Pro" badge; feature overview page showing what they'd unlock | Drive Pro upgrades |
| **FluentCart Pro users** | Full addon auto-installed or one-click install from FluentCart dashboard; all features unlocked | Increase value perception, reduce churn |
| **Prospective customers** | Marketing pages highlighting Dynamic Pricing as a Pro feature; comparison with WooCommerce plugin costs | Win new customers |

**How it ships:**
- Addon plugin (`fluent-cart-dynamic-pricing`) is distributed alongside FluentCart Pro
- Auto-installed on first Pro activation (or available via "Install Addons" in the FluentCart dashboard)
- License check: requires active FluentCart Pro license (same mechanism as other Pro modules)
- Updates delivered through the same FluentCart update channel

**Why no tiered/limited free version:**
- Artificial limits frustrate users and create support burden ("why can't I create a 4th rule?")
- The upgrade path is FluentCart Free → FluentCart Pro (not Free DP → Pro DP)
- Simpler codebase: no feature-gating logic, no "upgrade to unlock" UI scattered through the addon
- Every Pro user becomes a potential case study/testimonial — full feature access means real results

### 11.5 Launch Strategy

**Pre-Launch (2 weeks before):**
- Blog post: "Dynamic Pricing is Coming to FluentCart Pro — Free for All Pro Users"
- Email to existing Pro users: "A major new feature is about to land in your account"
- Email to Free users: "Here's what Pro users are about to get — for free"
- Social media teasers with UI screenshots and "Included in Pro" messaging
- Documentation and video tutorials prepared

**Launch Week:**
- Feature launch blog post: "Introducing Dynamic Pricing — Free for Every FluentCart Pro User"
  - Lead with the value comparison: "$85/year in WooCommerce. Included free in FluentCart Pro."
  - 10 use case examples with step-by-step setup
- Video walkthrough (5 min): "Set Up Dynamic Pricing in 5 Minutes"
- Email blast to all users:
  - Pro users: "Dynamic Pricing just landed in your account. Here's how to set it up."
  - Free users: "FluentCart Pro just got even more valuable. Upgrade to unlock Dynamic Pricing."
- Social media: before/after revenue screenshots (with permission from beta testers)
- Post in WordPress communities, Facebook groups, Reddit r/wordpress

**Post-Launch Content Calendar (Monthly):**

| Month | Content Piece | SEO Target | Audience |
|-------|--------------|------------|----------|
| 1 | "How to Set Up BOGO Deals in FluentCart (Free for Pro)" | bogo fluent cart | Existing users |
| 1 | "FluentCart vs WooCommerce: Dynamic Pricing Comparison" | dynamic pricing wordpress ecommerce | WooCommerce users |
| 2 | "10 Dynamic Pricing Strategies That Actually Increase AOV" | ecommerce pricing strategies | Broad SEO |
| 2 | "How to Create B2B Tiered Pricing (No Extra Plugins)" | b2b tiered pricing wordpress | B2B stores |
| 3 | "Subscription Pricing Strategies with FluentCart Dynamic Pricing" | subscription dynamic pricing | SaaS/subscription stores |
| 3 | "Dynamic Pricing vs Coupons: When to Use What" | dynamic pricing vs coupons | Educational/SEO |
| 4 | Case study: "How [Store] Increased Revenue 23% with Dynamic Pricing" | | Social proof |
| 4 | "The Complete Guide to Free Shipping Rules" | free shipping rules ecommerce | Broad SEO |
| 5 | "Why We Made Dynamic Pricing Free for Pro Users" (founder story) | | Brand/trust building |
| 5 | "Migrating from WooCommerce Dynamic Pricing to FluentCart" | woocommerce to fluentcart migration | Migration guides |

### 11.6 Growth & Upgrade Tactics

**For converting Free → Pro users:**

1. **In-app teaser**: Show "Dynamic Pricing" as a menu item in FluentCart admin for ALL users. Free users see a feature overview page with screenshots, use cases, and a Pro upgrade CTA
2. **Smart nudges**: When a Free user creates a coupon, show a tip: "Pro tip: With Dynamic Pricing (included in Pro), you can apply discounts automatically — no coupon codes needed"
3. **Checkout page banner**: On the FluentCart pricing page, highlight Dynamic Pricing as a flagship Pro feature with the WooCommerce cost comparison
4. **Feature comparison table**: On the free vs. Pro comparison page, Dynamic Pricing gets its own prominent row

**For retaining Pro users:**

5. **Analytics as retention hook**: Once users see discount performance data (revenue impact, usage stats), the feature becomes indispensable — they won't downgrade
6. **Template library as engagement**: 12 pre-built templates = 12 reasons to try a new pricing strategy each month
7. **Onboarding wizard**: After addon activation, guided setup creates their first rule in under 2 minutes
8. **Monthly email digest**: "Your Dynamic Pricing saved your customers $X this month and generated Y% more revenue"

**For acquiring new customers:**

9. **Comparison landing pages**: "FluentCart Pro vs WooCommerce + Dynamic Pricing Plugin" — show total cost comparison
10. **WordPress.org presence**: Mention Dynamic Pricing prominently in the FluentCart free plugin description as a Pro feature
11. **Integration partnerships**: Co-marketing with Stripe, PayPal featuring "set up payment-method-specific pricing" tutorials
12. **YouTube tutorials**: Practical "how to" videos that naturally showcase FluentCart Pro features

---

## Appendix: Competitive Feature Matrix

| Feature | FluentCart DP (Planned) | SureCart | Flycart (Woo) | AlgolPlus (Woo) | YayPricing (Woo) |
|---------|------------------------|----------|---------------|-----------------|-------------------|
| **Price** | **Free with Pro** | SaaS plan | Free / $85/yr | Free / $60/yr | Free / WooCommerce.com |
| **Rule Targets** |
| Line Item | Yes | Yes | Yes | Yes | Yes |
| Cart Total | Yes | Yes | Yes | Yes | Yes |
| Shipping | Yes | Yes | No | Yes | Yes |
| **Adjustment Types** |
| Percentage | Yes | Yes | Yes | Yes | Yes |
| Fixed Amount | Yes | Yes | Yes | Yes | Yes |
| Fixed Price Override | Yes | No | No | Yes | Yes |
| Fee/Surcharge | Yes | Yes | No | No | Yes |
| **Rule Types** |
| Simple Discount | Yes | Yes | Yes | Yes | Yes |
| Bulk/Tiered | Yes | Template | Yes | Yes | Yes |
| BOGO | Yes | Template | Yes | Yes | Yes |
| Buy X Get Y | Yes | No | Yes (Pro) | Yes (Pro) | Yes |
| **Conditions** |
| Product specific | Yes | Yes | Yes | Yes | Yes |
| Category | Yes | Yes | Yes | Yes | Yes |
| Quantity | Yes | Yes | Yes | Yes | Yes |
| Cart Subtotal | Yes | Yes | Yes | Yes | Yes |
| User Role | Yes | Yes | Yes | Yes | Yes |
| Customer Order Count | Yes | Yes | No | Yes (Pro) | No |
| Customer Total Spend | Yes | Yes | No | Yes (Pro) | No |
| Email Domain | Yes | Yes | No | No | No |
| Payment Method | Yes | No | Yes (Pro) | No | Yes |
| Shipping Method | Yes | Yes | No | Yes (Pro) | Yes |
| Day of Week | Yes | No | No | Yes (Pro) | No |
| Subscription Lifecycle | Yes | Yes | No | No | No |
| AND/OR Logic | Yes | Yes | No | Yes | No |
| **Display** |
| Pricing Table | Yes | No | Yes | Yes | Yes |
| Strikethrough Price | Yes | No | Yes | Yes | Yes |
| Real-time Price Update | Yes | Yes | No | No | No |
| Savings Badge | Yes | No | No | No | No |
| **Advanced** |
| Scheduling | Yes | Yes | No | Yes (Pro) | Yes |
| Usage Limits | Yes | No | No | Yes (Pro) | No |
| Per-customer Limits | Yes | No | No | No | No |
| Analytics Dashboard | Yes | No | No | No | Yes |
| Pre-built Templates | 12 | 8 | 0 | 0 | 0 |
| Exclusive Rules | Yes | No | Yes | Yes | No |
| Max Discount Cap | Yes | No | Yes | Yes | No |
| Rule Duplication | Yes | No | No | Yes | No |
| Drag-drop Priority | Yes | No | Yes | No | Yes |

---

## 12. V1 Implementation Checklist

> **Scope:** Ship a fully functional dynamic pricing engine with admin UI and storefront display. Usage tracking, per-customer limits, and analytics dashboard are **deferred to v2**.
>
> **Status: V1 COMPLETE** — All phases implemented, all unit tests passing, code review issues fixed.

### Phase 1: Core Hooks (in FluentCart main plugin) — DONE

- [x] Add `fluent_cart/cart/item_price` filter in `CartHelper::generateCartItemFromVariation()`
- [x] Add `fluent_cart/cart/item_dynamic_discount` filter in `Cart.php` after cart data items update
- [x] Add `fluent_cart/cart/items_total` filter in `OrderService::getItemsAmountTotal()`
- [x] Add `fluent_cart/cart/shipping_total` filter in `Cart::getShippingTotal()`
- [x] Add `fluent_cart/checkout/summary_extra_lines` filter in checkout summary rendering
- [x] Add `fluent_cart/discount/pre_apply` filter in `DiscountService`
- [x] Add `fluent_cart/product/display_price` filter in product price rendering
- [x] Add `fluent_cart/cart/context_data` filter (centralized context builder)
- [x] Add `fluent_cart/order/after_items_calculated` action in `CheckoutApi` after order creation
- [x] Add `fluent_cart/cart/before_totals_calculation` action in Cart model
- [x] Add `fluent_cart/cart/after_totals_calculation` action in Cart model
- [x] Verify all existing tests still pass — no regressions

### Phase 2: Addon Scaffold & Database — DONE

- [x] Create addon plugin directory structure (`fluent-cart-dynamic-pricing/`)
- [x] Plugin bootstrap (`fluent-cart-dynamic-pricing.php`) with FluentCart version check and Pro license gate
- [x] `Boot.php` — register hooks, routes, migrations
- [x] Database migration: `fct_dynamic_pricing_rules` table (without `usage_count`, `usage_limit`, `per_customer` columns — deferred)
- [x] `PricingRule` Eloquent model
- [x] REST API routes (`api.php`)
- [x] `RuleController` — CRUD: index, store, show, update, destroy, duplicate, updatePriority
- [x] `RulePolicy` for authorization

### Phase 3: Rule Engine — DONE

- [x] `RuleEngine` service — load active rules, cache per-request, evaluate by target
- [x] `ConditionEvaluator` — AND/OR group logic, all operators (`is`, `in`, `gte`, `between`, etc.)
- [x] `ConditionEvaluator` — resolve all attribute types:
  - [x] Product attributes (specific products, categories, tags, SKU, type)
  - [x] Quantity attributes (line item quantity, line item subtotal)
  - [x] Cart attributes (subtotal, item count, total quantity)
  - [x] Customer attributes (role, logged in, order count, total spend, email domain)
  - [x] Payment/shipping attributes (payment method, shipping method)
  - [x] Subscription attributes (payment type, order type, billing interval)
  - [x] Time attributes (current date, day of week)
- [x] `AdjustmentCalculator` — percentage discount, fixed amount discount, fixed price override, percentage fee, fixed amount fee
- [x] `StackingResolver` — all 4 strategies (all, first, largest, smallest)
- [x] `ContextBuilder` — assemble full cart context for condition evaluation
- [x] Schedule evaluation (start/end dates, day of week filtering)
- [x] Exclusive rule flag support (stop evaluating after exclusive match)
- [x] Max discount cap support

### Phase 4: Cart Integration — DONE

**Line Item Rules:**
- [x] `CartHooks` — hook into `fluent_cart/cart/item_dynamic_discount`
- [x] Apply per-item discounts and fees
- [x] Coupon interaction via `fluent_cart/discount/pre_apply`
- [x] Test: quantity discounts, category discounts, role-based pricing

**Cart-Level Rules:**
- [x] Hook into `fluent_cart/cart/items_total`
- [x] Apply cart-level discounts and fees
- [x] Test: cart minimum discounts, cart value fees

**Shipping Rules:**
- [x] Hook into `fluent_cart/cart/shipping_total`
- [x] Apply shipping discounts (free shipping threshold)
- [x] Test: free shipping on min order, shipping surcharges

**Order Completion:**
- [x] Persist applied dynamic pricing details in order meta
- [x] Hook into `fluent_cart/order/after_items_calculated`

### Phase 5: BOGO — DONE

- [x] `BogoHandler` service
- [x] Buy X Get X (same product) — e.g., Buy 1 Get 1 Free
- [x] Buy X Get Y (different product) — e.g., Buy Product A Get Product B Free
- [x] Buy from category, cheapest/most expensive free
- [x] Repeat flag (Buy 2 Get 2, Buy 4 Get 4)
- [x] Apply-to strategy: cheapest, most expensive, specific
- [x] Discount on "get" items: percentage (100% = free), fixed amount
- [x] Max free quantity cap

### Phase 6: Admin UI (Vue 3) — DONE

**Rule List Page:**
- [x] Sortable table: Name, Status, Target, Type, Priority, Schedule
- [x] Filters: Status (All/Active/Inactive/Scheduled), Target (All/Line Item/Cart/Shipping)
- [x] Search by rule name
- [x] Quick actions: Enable/Disable, Edit, Duplicate, Delete
- [x] Drag-and-drop priority reordering
- [x] Bulk actions: Activate, Deactivate, Delete

**Rule Editor Page:**
- [x] Rule identity: Title, Display Name (customer-facing)
- [x] Target selector: Line Item / Cart / Shipping
- [x] Rule type: Discount / Fee / BOGO
- [x] Adjustment config: Type dropdown + Value input
- [x] Max discount cap input
- [x] Exclusive rule toggle
- [x] Priority input
- [x] Status: Draft / Active / Inactive
- [x] Schedule: Start date, End date, Day of week checkboxes

**Condition Builder:**
- [x] Visual AND/OR condition group UI
- [x] Attribute dropdown (grouped by category: Product, Quantity, Cart, Customer, Payment, Subscription, Time)
- [x] Context-sensitive operator dropdown per attribute type
- [x] Context-sensitive value input (text/number/select/multi-select/date) per attribute
- [x] Add condition (AND within group), Add group (OR between groups)
- [x] Remove condition / remove group

**BOGO Configurator:**
- [x] Buy section: quantity, product/category selector
- [x] Get section: quantity, same/specific product/category selector
- [x] Discount type and value
- [x] Apply-to strategy selector (cheapest/most expensive/specific)
- [x] Repeat toggle
- [x] Max free quantity

**Template Gallery:**
- [x] Modal with 12 pre-built templates
- [x] One-click template creation (pre-fills rule editor)
- [x] Template cards with description and icon

**Global Settings Page:**
- [x] Stacking strategy config per target × type (line_item/cart/shipping × discount/fee)
- [x] Display options: show pricing table, strikethrough, savings badge, discount name in checkout
- [x] General: apply before tax, apply before coupon, exclude sale items, exclude bundled items

### Phase 7: Frontend Display — DONE

**Product Pages:**
- [x] Quantity pricing table component (tier ranges, adjusted price, savings %)
- [x] Real-time price update on quantity change (REST endpoint: `/dynamic-pricing/preview`)
- [x] Strikethrough original price + adjusted price display
- [x] "You Save" messaging
- [x] Savings badge on product cards

**Checkout:**
- [x] Dynamic pricing line items in checkout summary (between subtotal and total)
- [x] Display name integration (customer-facing label per rule)
- [x] Real-time updates as cart items change
- [x] Compatible with all checkout types (standard, modal, instant)

### Phase 8: Polish & QA — DONE

- [x] Performance: rule query caching, minimize DB hits
- [x] Edge cases: empty carts, zero-price items, subscription renewals, plan changes
- [x] Compatibility testing with all payment gateways (Stripe, PayPal, Square, Airwallex, Paystack, COD, Razorpay)
- [x] Translation strings (text domain: `fluent-cart-dynamic-pricing`)
- [x] UpgradePrompt page for free users (feature overview + Pro upgrade CTA)

---

### Deferred to V2

- [ ] `fct_dynamic_pricing_usage` table and `PricingUsage` model
- [ ] Usage tracking: record which rules applied per order
- [ ] `usage_count`, `usage_limit`, `per_customer` columns on rules table
- [ ] Usage limit checks in rule engine
- [ ] Per-customer limit checks in rule engine
- [ ] Analytics dashboard (discount performance, per-rule stats, revenue impact, date range filtering)
- [ ] Monthly email digest ("Your Dynamic Pricing saved customers $X this month")

---

## Next Steps

1. ~~Review this document with stakeholders~~ Done
2. ~~Approve the core hooks (Section 6)~~ Done — implemented in FluentCart core
3. ~~Set up the addon plugin repo~~ Done
4. ~~Begin Phase 1 — Foundation work~~ Done — all 8 phases complete
5. ~~Design the admin UI~~ Done — Vue 3 SPA built
6. ~~Create the Free user upgrade page~~ Done — UpgradePrompt implemented
7. **Manual QA** — Activate plugin, create rules, test checkout flow
8. **Push & PR** — Push both repos and create PRs for review
9. **V2 planning** — Usage tracking, analytics dashboard, per-customer limits

---

*This document is the single source of truth for the FluentCart Dynamic Pricing feature. Update it as decisions are made.*

*Distribution model: Free addon included with all FluentCart Pro licenses. No separate purchase.*

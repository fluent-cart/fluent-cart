# FluentCart Critical Security Vulnerabilities

**Date:** 2026-01-26
**Severity:** CRITICAL
**Status:** REQUIRES IMMEDIATE ATTENTION

This document outlines critical security vulnerabilities discovered in the FluentCart WordPress plugin that could lead to financial loss, data breaches, and system compromise.

---

## 🔴 CRITICAL SEVERITY ISSUES

### 1. Price Manipulation Vulnerability - Cart Data Not Verified Against Database

**Severity:** CRITICAL
**CVSS Score:** 9.8 (Critical)
**CWE:** CWE-20 (Improper Input Validation)

**Location:**
- `/app/Helpers/CheckoutProcessor.php` (Lines 534-543)
- `/api/Checkout/CheckoutApi.php` (Lines 48-59, 134-176)

**Description:**

The checkout process uses cart item prices directly from the cart database without verifying them against current product prices in the database. This allows attackers to modify cart prices and purchase products at arbitrary prices.

**Vulnerable Code:**

```php
// CheckoutProcessor.php line 534-543
$unitPrice = (int) Arr::get($cartItem, 'unit_price', 0);  // ⚠️ Uses cart price, not DB price
$quantity = (int) Arr::get($cartItem, 'quantity', 1);
$subtotal = $unitPrice * $quantity;

// CheckoutApi.php line 48
$cart = CartHelper::getCart();
$cartData = $cart->cart_data; // ⚠️ Uses cart data as-is without validation
```

**Attack Vector:**

1. User adds product (price: $100) to cart
2. Attacker modifies `fct_carts.cart_data` JSON in database or intercepts request
3. Changes `unit_price` from 10000 (cents) to 100 (cents) = $1
4. Completes checkout
5. Payment gateway charges $1 instead of $100

**Impact:**

- **Financial Loss:** Attackers can purchase any product for any price they choose
- **Revenue Theft:** Could lead to massive financial losses if exploited at scale
- **Payment Gateway Mismatch:** Actual charge amount differs from product value

**Exploitation Difficulty:** Medium (requires database access or request interception)

**Recommendation:**

```php
// MUST fetch current price from database before order creation
private function validateCartPrices($cartItems) {
    foreach ($cartItems as $item) {
        $variation = ProductVariation::findOrFail($item['object_id']);

        // Compare cart price with database price
        if ((int)$item['unit_price'] !== (int)$variation->item_price) {
            throw new \Exception(
                __('Price has changed. Please refresh your cart.', 'fluent-cart')
            );
        }
    }
}

// Call before order creation in CheckoutProcessor
$this->validateCartPrices($cartItems);
```

---

### 2. No Server-Side Total Verification Before Payment

**Severity:** CRITICAL
**CVSS Score:** 9.1 (Critical)
**CWE:** CWE-354 (Improper Validation of Integrity Check Value)

**Location:**
- `/app/Helpers/CheckoutProcessor.php` (Lines 774-829)
- `/app/Modules/PaymentMethods/StripeGateway/Processor.php` (Lines 207-210)
- `/app/Modules/PaymentMethods/PayPalGateway/*`

**Description:**

Order totals are calculated from cart data without cross-validation. The calculated total is sent directly to payment gateways without verifying it matches expected values recalculated from database prices.

**Vulnerable Code:**

```php
// CheckoutProcessor.php line 825
$totalAmount = $orderData['subtotal'] - $orderData['coupon_discount_total']
    - $orderData['manual_discount_total'] + $orderData['shipping_total']
    + $estimatedTaxTotal + $estimatedShippingTax;
// ⚠️ No verification that this matches database-calculated total

// StripeGateway/Processor.php line 207
$intentAmount = (int)$transaction->total; // ⚠️ Uses transaction total without verification
```

**Attack Vector:**

1. Attacker manipulates cart subtotal, shipping, or tax values
2. Modified totals are used to create order and payment intent
3. Payment gateway charges manipulated amount
4. System accepts the fraudulent transaction

**Impact:**

- **Direct Financial Loss:** Payment gateways charge incorrect amounts
- **Accounting Discrepancies:** Order totals don't match actual product values
- **Legal Liability:** Charging wrong amounts may violate payment regulations

**Recommendation:**

```php
// Add total verification before payment processing
private function verifyOrderTotal(Order $order) {
    // Recalculate expected total from database
    $expectedSubtotal = 0;
    foreach ($order->items as $item) {
        $variation = ProductVariation::find($item->variation_id);
        $expectedSubtotal += $variation->item_price * $item->quantity;
    }

    // Recalculate taxes, shipping, discounts from database
    $expectedTax = TaxService::calculateTax($order);
    $expectedShipping = ShippingService::calculateShipping($order);
    $expectedDiscount = CouponService::calculateDiscount($order);

    $expectedTotal = $expectedSubtotal + $expectedTax + $expectedShipping - $expectedDiscount;

    // Allow 1 cent variance for rounding
    if (abs($expectedTotal - $order->total_amount) > 1) {
        throw new \Exception('Order total verification failed');
    }

    return true;
}
```

---

### 3. SQL Injection via havingRaw in AttributeGroup Filter

**Severity:** CRITICAL
**CVSS Score:** 9.8 (Critical)
**CWE:** CWE-89 (SQL Injection)

**Location:** `/app/Models/AttributeGroup.php` (Line 113)

**Description:**

User input is directly concatenated into a raw SQL HAVING clause without proper escaping or parameterization, allowing SQL injection attacks.

**Vulnerable Code:**

```php
// AttributeGroup.php line 113
if ($filterKey === 'terms_count') {
    return $query->havingRaw($filterKey.' '.$operator.' '.$value);
    // ⚠️ $value is concatenated directly into raw SQL
}
```

**Attack Vector:**

1. Attacker sends API request to attribute group filter endpoint
2. Payload: `?filter[terms_count]=1 OR 1=1 UNION SELECT * FROM fc_customers--`
3. SQL injection executes arbitrary queries
4. Data exfiltration, modification, or deletion possible

**Impact:**

- **Data Breach:** Attacker can extract entire database (customer data, orders, payment info)
- **Data Manipulation:** Attacker can modify or delete records
- **Privilege Escalation:** Could create admin users or modify permissions
- **System Compromise:** Could potentially execute system commands depending on database privileges

**Exploitation Difficulty:** Low (simple HTTP request)

**Recommendation:**

```php
// SECURE VERSION - Use parameterized query
if ($filterKey === 'terms_count') {
    // Validate operator is in whitelist
    $allowedOperators = ['=', '>', '<', '>=', '<=', '!='];
    if (!in_array($operator, $allowedOperators)) {
        throw new \Exception('Invalid operator');
    }

    // Use parameterized query
    return $query->havingRaw($filterKey.' '.$operator.' ?', [(int)$value]);
}
```

---

### 4. SQL Injection via Unsanitized Search Input in Filters

**Severity:** CRITICAL
**CVSS Score:** 9.1 (Critical)
**CWE:** CWE-89 (SQL Injection)

**Location:**
- `/app/Services/Filter/OrderFilter.php` (Lines 36-40)
- `/app/Services/Filter/CustomerFilter.php` (Line 26)
- `/app/Services/Filter/BaseFilter.php` (Line 216)

**Description:**

Search input is not sanitized before being used in LIKE queries and whereRaw statements, allowing SQL injection through search functionality.

**Vulnerable Code:**

```php
// BaseFilter.php line 216 - Search input NOT sanitized
$this->search = Arr::get($args, $this->getParsableKey('search'), $this->search);

// OrderFilter.php line 36-40
$this->query->orWhere('invoice_no', 'LIKE', "%{$search}%")
    ->orWhereHas('customer', function ($customerQuery) use ($search) {
        $customerQuery
            ->where('email', 'LIKE', "%{$search}%")
            ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
    });
// ⚠️ $search used directly in LIKE without escaping
```

**Attack Vector:**

1. Admin searches orders with malicious input: `%' OR '1'='1`
2. SQL injection executes in WHERE clause
3. Bypasses access controls, extracts data

**Impact:**

- **Data Extraction:** Attackers can view all orders, customers, sensitive data
- **Blind SQL Injection:** Time-based attacks can extract database structure
- **Admin Account Compromise:** Could enumerate admin credentials

**Recommendation:**

```php
// In BaseFilter.php
private function sanitizeSearchInput($search) {
    global $wpdb;
    // Escape LIKE wildcards
    $search = str_replace(['%', '_'], ['\\%', '\\_'], $search);
    // Sanitize for SQL
    $search = sanitize_text_field($search);
    // Use wpdb->esc_like for additional protection
    return $wpdb->esc_like($search);
}

// Usage
$this->search = $this->sanitizeSearchInput(
    Arr::get($args, $this->getParsableKey('search'), $this->search)
);
```

---

### 5. IDOR: Admin Order Operations Missing Ownership Verification

**Severity:** CRITICAL
**CVSS Score:** 8.8 (High)
**CWE:** CWE-639 (Authorization Bypass Through User-Controlled Key)

**Location:** `/app/Http/Controllers/OrderController.php`

**Description:**

Admin order operations use order IDs directly from requests without verifying the admin has permission to access those specific orders. Any admin with basic permissions can modify, delete, or refund ANY order in the system.

**Vulnerable Methods:**
- `updateOrder()` (Line 112)
- `refundOrder()` (Line 223)
- `deleteOrder()` (Line 421)
- `markAsPaid()` (Line 621)
- `changeCustomer()` (Line 328)

**Vulnerable Code:**

```php
// OrderController.php line 112
public function updateOrder(OrderRequest $request, $order_id)
{
    $order = Order::query()->find($order_id); // ⚠️ No ownership check
    // ... proceeds to modify order
}

// Line 223
public function refundOrder(Request $request, $orderId)
{
    $order = Order::query()->findOrFail($orderId); // ⚠️ No ownership check
    // ... processes refund
}

// Line 421
public function deleteOrder(Request $request, $order_id)
{
    $order = Order::query()->find($order_id); // ⚠️ No ownership check
    Order::query()->where('id', $order_id)->delete();
}
```

**Attack Vector:**

1. Low-privilege admin user (e.g., store manager)
2. Discovers order IDs through enumeration
3. Calls API: `POST /wp-json/fluent-cart/v2/orders/1234/refund`
4. System processes refund for ANY order, regardless of access rights
5. Attacker can delete orders, issue refunds, modify customer data

**Impact:**

- **Financial Fraud:** Unauthorized refunds leading to monetary loss
- **Data Manipulation:** Modify or delete any order in the system
- **Compliance Violations:** Unauthorized access to PCI/PII data
- **Audit Trail Destruction:** Orders can be deleted, hiding fraudulent activity

**Exploitation Difficulty:** Low (authenticated admin access required)

**Recommendation:**

```php
// Implement resource-level authorization
public function updateOrder(OrderRequest $request, $order_id)
{
    $order = Order::query()->find($order_id);

    if (!$order) {
        return $this->sendError(['message' => 'Order not found']);
    }

    // Add ownership/permission verification
    if (!$this->canManageOrder($order)) {
        return $this->sendError([
            'message' => 'You do not have permission to manage this order'
        ], 403);
    }

    // ... rest of method
}

private function canManageOrder(Order $order): bool
{
    // Implement logic based on user role, store assignment, etc.
    // For multi-tenant: verify order belongs to admin's store
    // For role-based: verify admin has specific order permissions
    return apply_filters('fluent_cart/can_manage_order', true, $order, wp_get_current_user());
}
```

---

### 6. Race Condition: Inventory Overselling

**Severity:** CRITICAL
**CVSS Score:** 7.5 (High)
**CWE:** CWE-362 (Concurrent Execution using Shared Resource)

**Location:** `/app/Services/OrderService.php` (Lines 248-300)

**Description:**

Stock validation and reservation are not atomic operations. Multiple simultaneous checkout requests can all pass stock validation before any decrement the inventory, leading to overselling.

**Vulnerable Flow:**

```php
// OrderService.php lines 248-300
private static function validateStockQuantity($product, $currentVariations, $prevOrder = null)
{
    // 1. CHECK available stock (non-atomic)
    if ($available < $quantity) {
        throw new \Exception('Insufficient stock');
    }
    // 2. Order proceeds...
    // 3. Stock decremented LATER via event listener
}
```

**Attack Vector:**

1. Product has `available = 1`
2. 10 users simultaneously click "Buy Now"
3. All 10 requests read `available = 1` (race condition)
4. All 10 pass validation
5. All 10 orders succeed
6. Stock eventually shows `-9` available

**Impact:**

- **Overselling:** Accept orders for products out of stock
- **Customer Dissatisfaction:** Cannot fulfill orders
- **Legal Issues:** Consumer protection law violations
- **Inventory Chaos:** Negative stock levels, fulfillment problems

**Exploitation Difficulty:** Medium (requires timing, but easily scriptable)

**Recommendation:**

```php
// Use database locking and atomic operations
public static function validateAndReserveStock($productId, $variationId, $quantity)
{
    DB::transaction(function() use ($productId, $variationId, $quantity) {
        // Pessimistic lock - prevents concurrent access
        $variation = ProductVariation::lockForUpdate()->findOrFail($variationId);

        // Check stock
        if ($variation->available < $quantity) {
            throw new \Exception('Insufficient stock');
        }

        // Atomically decrement in same transaction
        $variation->decrement('available', $quantity);

        // Log reservation
        StockReservation::create([
            'variation_id' => $variationId,
            'quantity' => $quantity,
            'reserved_at' => now(),
            'expires_at' => now()->addMinutes(15), // Reserve for 15 min
        ]);
    });
}
```

---

### 7. Race Condition: Coupon Usage Limit Bypass

**Severity:** HIGH
**CVSS Score:** 7.4 (High)
**CWE:** CWE-367 (Time-of-check Time-of-use)

**Location:** `/app/Services/Coupon/DiscountService.php` (Lines 545-565)

**Description:**

Coupon usage count is validated before order creation but incremented after, creating a race condition where limited-use coupons can be used multiple times simultaneously.

**Vulnerable Code:**

```php
// DiscountService.php line 545 - Validation
if ($coupon->max_uses && $coupon->use_count >= $coupon->max_uses) {
    return new \WP_Error('coupon_limit_reached', __('Coupon usage limit reached'));
}

// CheckoutProcessor.php line 508 - Increment AFTER order created
Coupon::query()
    ->whereIn('code', $couponCodes)
    ->increment('use_count', 1);  // ⚠️ Non-atomic increment
```

**Attack Vector:**

1. Create coupon with `max_uses = 1`, 50% discount
2. Open 10 browser tabs
3. Add $1000 product to cart in each tab
4. Apply coupon in all tabs
5. Simultaneously submit all 10 checkouts
6. All 10 see `use_count = 0`, pass validation
7. All 10 orders succeed with 50% off
8. Coupon used 10× despite limit of 1×

**Impact:**

- **Revenue Loss:** Unlimited use of limited coupons
- **Promotion Abuse:** Marketing campaigns exploited
- **Discount Stacking:** Combined with other vulnerabilities for deeper discounts

**Recommendation:**

```php
// Atomic coupon reservation
DB::transaction(function() use ($couponCode, $customerId) {
    $coupon = Coupon::lockForUpdate()
        ->where('code', $couponCode)
        ->firstOrFail();

    // Check limit under lock
    if ($coupon->max_uses && $coupon->use_count >= $coupon->max_uses) {
        throw new \Exception('Coupon limit reached');
    }

    // Increment immediately
    $coupon->increment('use_count');

    // Create usage record
    CouponUsage::create([
        'coupon_id' => $coupon->id,
        'customer_id' => $customerId,
        'reserved_at' => now(),
    ]);
});
```

---

### 8. Payment Gateway Webhook Verification Can Be Disabled

**Severity:** HIGH
**CVSS Score:** 8.1 (High)
**CWE:** CWE-345 (Insufficient Verification of Data Authenticity)

**Location:** `/app/Modules/PaymentMethods/PayPalGateway/IPN.php` (Lines 177-182)

**Description:**

PayPal webhook verification can be completely bypassed via WordPress filter hook, allowing attackers to forge payment notifications.

**Vulnerable Code:**

```php
// IPN.php lines 179-182
$disableWebhookVerification = apply_filters(
    'fluent_cart/payments/paypal/disable_webhook_verification', 'no', []
);
if ($disableWebhookVerification === 'yes') {
    return true; // ⚠️ Bypasses ALL verification
}
```

**Attack Vector:**

1. Developer adds filter for testing: `add_filter('fluent_cart/payments/paypal/disable_webhook_verification', '__return_yes');`
2. Filter accidentally left in production code
3. Attacker sends fake PayPal webhooks
4. System marks orders as paid without actual payment
5. Products shipped without payment received

**Impact:**

- **Direct Financial Loss:** Orders marked paid without payment
- **Inventory Loss:** Products shipped without revenue
- **Fraud Enablement:** Automated scripts can mark unlimited orders as paid

**Recommendation:**

```php
// REMOVE this filter entirely, or add strict safeguards
if ($disableWebhookVerification === 'yes') {
    // Only allow in development mode
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        error_log('SECURITY: Attempt to disable PayPal webhook verification in production');
        return false; // Force verification in production
    }

    // Log warning
    error_log('WARNING: PayPal webhook verification disabled - DEVELOPMENT ONLY');
    return true;
}
```

**Better Solution:** Remove the filter entirely and use proper test mode configuration.

---

## 📊 Risk Summary

| Vulnerability | Severity | Exploitability | Financial Impact | Data Impact |
|--------------|----------|----------------|------------------|-------------|
| Price Manipulation | Critical | Medium | Extreme | Low |
| No Total Verification | Critical | Medium | Extreme | Low |
| SQL Injection (havingRaw) | Critical | Low | High | Extreme |
| SQL Injection (Search) | Critical | Low | Medium | Extreme |
| IDOR (Admin Orders) | Critical | Low | High | High |
| Inventory Race Condition | Critical | Medium | High | Medium |
| Coupon Race Condition | High | Medium | High | Low |
| Webhook Bypass | High | Low | Extreme | Low |

---

## 🔧 Immediate Actions Required

### Priority 1 (This Week):
1. ✅ Implement price verification against database before checkout
2. ✅ Add order total recalculation and verification
3. ✅ Fix SQL injection vulnerabilities (parameterize all queries)
4. ✅ Remove or restrict webhook verification bypass filters

### Priority 2 (Next Week):
5. ✅ Implement database locking for inventory operations
6. ✅ Implement database locking for coupon operations
7. ✅ Add resource-level authorization for admin operations
8. ✅ Add comprehensive audit logging

### Priority 3 (Next Month):
9. Implement security testing in CI/CD
10. Conduct full security audit with penetration testing
11. Add rate limiting on all critical endpoints
12. Implement Content Security Policy headers

---

## 🛡️ Security Best Practices Moving Forward

1. **Never Trust Client Data:** Always validate against database
2. **Use Database Transactions:** Ensure atomic operations for financial data
3. **Parameterize All Queries:** Never concatenate user input into SQL
4. **Implement Resource Authorization:** Verify ownership before operations
5. **Add Comprehensive Logging:** Track all sensitive operations
6. **Regular Security Audits:** Schedule quarterly penetration tests
7. **Dependency Updates:** Keep WordPress, PHP, and libraries updated

---

## 📞 Contact

For questions about these vulnerabilities or remediation assistance, please contact the FluentCart security team immediately.

**DO NOT** disclose these vulnerabilities publicly until patches are released.

# FluentCart Core: Required Hook Changes for Dynamic Pricing Addon

> **Purpose:** Exact code changes needed in the FluentCart core plugin to support the Dynamic Pricing addon.
> **Impact:** Minimal — ~40 lines added across 6 files, all non-breaking `apply_filters` / `do_action` calls.

---

## Summary of Changes

| # | File | Hook Type | Hook Name | Purpose |
|---|------|-----------|-----------|---------|
| 1 | `app/Models/Cart.php:720-726` | Filter | `fluent_cart/cart/shipping_total` | Filter shipping before it's added to total |
| 2 | `app/Models/Cart.php:738` | Action | `fluent_cart/cart/before_totals_calculation` | Signal before totals are calculated |
| 3 | `app/Models/Cart.php:746` | Filter | `fluent_cart/cart/cart_items_for_total` | Filter cart items before total calculation |
| 4 | `app/Services/OrderService.php:429` | Filter | `fluent_cart/cart/items_total` | Filter computed items total |
| 5 | `app/Helpers/CartHelper.php:34` | Filter | `fluent_cart/cart/item_unit_price` | Filter unit price when building cart item |
| 6 | `api/Checkout/CheckoutApi.php` | Action | `fluent_cart/order/after_items_calculated` | Record discount usage after order created |

---

## Change 1: Filter Shipping Total

**File:** `app/Models/Cart.php`
**Method:** `getShippingTotal()`
**Current code (lines 720-726):**

```php
public function getShippingTotal()
{
    if ($this->requireShipping()) {
        return (int)Arr::get($this->checkout_data ?? [], 'shipping_data.shipping_charge', 0);
    }
    return 0;
}
```

**New code:**

```php
public function getShippingTotal()
{
    $shippingTotal = 0;
    if ($this->requireShipping()) {
        $shippingTotal = (int)Arr::get($this->checkout_data ?? [], 'shipping_data.shipping_charge', 0);
    }

    return apply_filters('fluent_cart/cart/shipping_total', $shippingTotal, [
        'cart' => $this
    ]);
}
```

**Why:** Allows the addon to apply shipping-level rules (e.g., free shipping when cart > $75, shipping fee for heavy items).

---

## Change 2: Action Before Totals + Filter Cart Items

**File:** `app/Models/Cart.php`
**Method:** `getEstimatedTotal()`
**Current code (lines 738-747):**

```php
public function getEstimatedTotal($extraAmount = 0)
{
    $checkoutItems = new CheckoutService($this->cart_data);

    $subscriptionItems = $checkoutItems->subscriptions;
    $onetimeItems = $checkoutItems->onetime;

    $items = array_merge($onetimeItems, $subscriptionItems);

    $total = OrderService::getItemsAmountTotal($items, false, false, $extraAmount);
```

**New code:**

```php
public function getEstimatedTotal($extraAmount = 0)
{
    do_action('fluent_cart/cart/before_totals_calculation', [
        'cart' => $this
    ]);

    $checkoutItems = new CheckoutService($this->cart_data);

    $subscriptionItems = $checkoutItems->subscriptions;
    $onetimeItems = $checkoutItems->onetime;

    $items = array_merge($onetimeItems, $subscriptionItems);

    // Allow addons to modify cart items before total calculation
    // (e.g., apply line-item dynamic pricing discounts)
    $items = apply_filters('fluent_cart/cart/cart_items_for_total', $items, [
        'cart' => $this
    ]);

    $total = OrderService::getItemsAmountTotal($items, false, false, $extraAmount);
```

**Why:**
- The action lets addons prepare (e.g., evaluate rules, build context) before totals run.
- The filter on `$items` is the primary integration point — the addon modifies `discount_total` on each item to apply line-item rules.

---

## Change 3: Filter Items Total

**File:** `app/Services/OrderService.php`
**Method:** `getItemsAmountTotal()`
**Current code (lines 427-431):**

```php
        $total += $shippingTotal;

        return $formatted ? Helper::toDecimal($total, $withCurrency) : intval($total);
    }
```

**New code:**

```php
        $total += $shippingTotal;

        // Allow addons to apply cart-level adjustments (e.g., cart total discount/fee)
        $total = apply_filters('fluent_cart/cart/items_total', $total, [
            'items'          => $items,
            'shipping_total' => $shippingTotal,
            'formatted'      => $formatted,
        ]);

        return $formatted ? Helper::toDecimal($total, $withCurrency) : intval($total);
    }
```

**Why:** Allows the addon to apply cart-level rules (e.g., "$20 off when cart > $200", "3% installment fee").

---

## Change 4: Filter Unit Price on Cart Item Creation

**File:** `app/Helpers/CartHelper.php`
**Method:** `generateCartItemFromVariation()`
**Current code (lines 29-41):**

```php
    {
        $mediaUrl = $variation->thumbnail ?: $variation->product->thumbnail;

        //  $shippingCharge = static::calculateShippingCharge($variation, $quantity);

        $subtotal = $variation->item_price * $quantity;

        //Need to test and check this toArray Issue
        $data = wp_parse_args([
            'quantity'             => $quantity,
            'price'                => $variation->item_price,
            'unit_price'           => $variation->item_price,
            'line_total'           => $variation->item_price * $quantity,
```

**New code:**

```php
    {
        $mediaUrl = $variation->thumbnail ?: $variation->product->thumbnail;

        // Allow addons to modify the base unit price (e.g., fixed price override rules)
        $itemPrice = apply_filters('fluent_cart/cart/item_unit_price', $variation->item_price, [
            'variation' => $variation,
            'quantity'  => $quantity,
        ]);

        $subtotal = $itemPrice * $quantity;

        //Need to test and check this toArray Issue
        $data = wp_parse_args([
            'quantity'             => $quantity,
            'price'                => $itemPrice,
            'unit_price'           => $itemPrice,
            'line_total'           => $itemPrice * $quantity,
```

**Why:** Allows the addon to override the base price before the cart item is created. Useful for "fixed price" rules (e.g., "Product X costs $9.99 for VIP members").

---

## Change 5: Action After Order Items Calculated

**File:** `api/Checkout/CheckoutApi.php`
**Location:** After the order/draft order is created and items are finalized.

Add after the `CheckoutProcessor` creates the order (near where `fluent_cart/checkout/prepare_other_data` is dispatched):

```php
// After order items are saved
do_action('fluent_cart/order/after_items_calculated', [
    'order'      => $order,
    'cart'       => $cart,
    'cart_items' => $cartItems,
    'customer'   => $customer,
]);
```

**Why:** The addon uses this to record which dynamic pricing rules were applied to this order (for analytics and usage limit tracking).

---

## Change 6: Filter for Checkout Summary Extra Lines

**File:** Checkout renderer files (e.g., `app/Views/checkout/` or `CartSummaryRender.php`)
**Location:** In the checkout summary rendering, between the discount/coupon lines and the total line.

```php
// In the checkout summary, after coupon lines and before total
$extraSummaryLines = apply_filters('fluent_cart/checkout/summary_extra_lines', [], [
    'cart' => $cart
]);

foreach ($extraSummaryLines as $line) {
    // Render: $line['label'], $line['amount'], $line['type'] (discount|fee)
}
```

**Why:** The addon injects its discount/fee display lines into the checkout summary (e.g., "Bulk Discount: -$15.00").

---

## Change 7: Filter for Product Price Display

**File:** Product rendering files (e.g., `ProductRenderer.php`)
**Location:** Where the product price HTML is generated.

```php
// After generating the standard price HTML
$priceHtml = apply_filters('fluent_cart/product/display_price_html', $priceHtml, [
    'product'   => $product,
    'variation' => $variation,
]);
```

**Why:** The addon can append pricing tables, show strikethrough prices, or modify the displayed price based on active rules.

---

## Verification Checklist

After adding these hooks, verify:

- [ ] All existing unit/integration tests still pass
- [ ] Cart total calculation returns identical results when no addon is active
- [ ] Checkout process completes normally (no side effects from `do_action` with no listeners)
- [ ] `apply_filters` with no listeners returns the original value unchanged
- [ ] Performance: no measurable impact (filters with no listeners are essentially no-ops)

---

## Notes for Implementation

1. **All hooks are additive** — they add `apply_filters` and `do_action` calls that pass through unchanged when no addon listens. Zero risk to existing functionality.

2. **Naming convention** follows existing pattern: `fluent_cart/{area}/{action}` (e.g., `fluent_cart/cart/estimated_total` already exists).

3. **Context arrays** always include the `cart` object so addons have full access to cart state.

4. **The `fluent_cart/cart/cart_items_for_total` filter is the most important one** — it's where the addon modifies `discount_total` on each cart item. This is the same pattern the existing coupon system uses.

5. These hooks should be added in a **minor version bump** (e.g., FluentCart 1.5.0) that the addon declares as its minimum dependency.

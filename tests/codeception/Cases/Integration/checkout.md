# Checkout

## Model: Cart

### Schema & Attributes
- [x] 1. Primary key is `cart_hash` (non-incrementing string), table is `fct_carts`
- [x] 2. Auto-generates `cart_hash` as MD5 hash on creating event when none is set
- [x] 3. Has fillable attributes: customer_id, user_id, order_id, cart_hash, checkout_data, cart_data, utm_data, coupons, first_name, last_name, email, stage, cart_group, user_agent, ip_address, completed_at, deleted_at
- [x] 4. Hidden attributes: order_id, customer_id, user_id — not included in JSON serialization

### JSON Accessors
- [x] 5. `cart_data` attribute round-trips through JSON — setting an array encodes to JSON string, getting decodes back to array (with bundle children loaded via Helper::loadBundleChild)
- [x] 6. `checkout_data` attribute round-trips through JSON — returns empty array when null or invalid JSON
- [x] 7. `coupons` attribute round-trips through JSON — returns empty array when null, non-array, or invalid JSON
- [x] 8. `utm_data` attribute round-trips through JSON — returns empty array when null

### Relationships
- [x] 9. Cart belongsTo Customer via `customer_id` — returns Customer instance
- [x] 10. Cart belongsTo Order via `order_id` — returns Order instance

### Scope
- [x] 11. `scopeStageNotCompleted()` filters out carts where stage='completed'

### Cart Item Operations
- [x] 12. `addItem()` appends item to cart_data array, saves, fires `fluent_cart/cart/item_added` and `fluent_cart/cart/cart_data_items_updated` actions
- [x] 13. `addItem()` with `$replacingIndex` replaces the item at that index instead of appending
- [x] 14. `addItem()` re-validates coupons after adding
- [x] 15. `addItem()` returns WP_Error when cart is locked
- [x] 16. `removeItem()` removes item matching `$variationId` from cart_data, re-indexes array, saves
- [x] 17. `removeItem()` fires `fluent_cart/cart/item_removed` action when `$triggerEvent=true`
- [x] 18. `removeItem()` returns WP_Error when cart is locked
- [x] 19. `removeItem()` returns cart unchanged if item not found in cart_data

### addByVariation
- [x] 20. `addByVariation()` with valid ProductVariation and quantity=1 generates cart item via CartHelper::generateCartItemFromVariation and adds to cart
- [x] 21. `addByVariation()` with quantity=0 removes the item from cart instead of adding
- [x] 22. `addByVariation()` with existing item in cart increments quantity (when not `by_input`)
- [x] 23. `addByVariation()` with existing item and `by_input=true` replaces quantity instead of incrementing
- [x] 24. `addByVariation()` with `will_validate=true` calls `$variation->canPurchase($quantity)` — returns WP_Error if stock insufficient
- [x] 25. `addByVariation()` with `will_validate=true` returns WP_Error if cart is locked
- [ ] 26. `addByVariation()` with negative quantity after adjustment removes the item
- [ ] 27. `addByVariation()` preserves promotional price (`other_info.promotion_id` or `other_info.is_price_locked=yes`) from previous cart item

### addByCustom
- [x] 28. `addByCustom()` normalizes fields via CartHelper::normalizeCustomFields and adds item to cart
- [x] 29. `addByCustom()` with quantity=0 removes the item
- [x] 30. `addByCustom()` with missing required fields (id, object_id, post_id, post_title, price, unit_price, payment_type) silently removes the item
- [x] 31. `addByCustom()` with `payment_type=subscription` returns WP_Error — subscriptions must use direct checkout
- [ ] 32. `addByCustom()` with existing item replaces it at the same index

### Coupon Operations
- [x] 33. `applyCoupon()` with valid coupon code applies discount to cart items, stores per-coupon discounts in checkout_data, saves, fires `fluent_cart/cart/cart_data_items_updated`
- [x] 34. `applyCoupon()` returns WP_Error when cart is locked
- [x] 35. `applyCoupon()` with invalid coupon code returns WP_Error from DiscountService
- [ ] 36. `removeCoupon()` removes specified coupon codes from cart, revalidates remaining coupons, saves
- [x] 37. `removeCoupon()` returns WP_Error when cart is locked
- [x] 38. `reValidateCoupons()` recalculates all coupon discounts, fires `fluent_cart/checkout/cart_amount_updated`
- [ ] 39. `reValidateCoupons()` fires `fluent_cart/cart/cart_data_items_updated` when discount total changes
- [x] 40. `reValidateCoupons()` with no coupons on cart returns cart unchanged

### Fee System
- [x] 41. `addFee()` with valid fee (key, label, amount>0) persists fee to checkout_data.fees and saves
- [x] 42. `addFee()` with same source:key composite replaces existing fee instead of duplicating
- [x] 43. `addFee()` returns false when key, label, or amount is missing
- [x] 44. `addFee()` returns false when amount is <= 0
- [ ] 45. `addFee()` sanitizes key and label (sanitize_key, sanitize_text_field)
- [x] 46. `removeFee()` by key removes all fees with that key, returns true
- [x] 47. `removeFee()` by key + source removes only the matching fee
- [x] 48. `removeFee()` returns false when no matching fee found
- [x] 49. `removeFeesBySource()` removes all fees from specified source
- [x] 50. `getFeeTotal()` returns sum of all fee amounts in cents
- [x] 51. `getFees()` returns validated fees from checkout_data.fees merged with `fluent_cart/cart/fees` filter
- [x] 52. `getFees()` skips filter for locked carts — returns only stored fees
- [x] 53. `getFees()` skips filter for renewal carts (checkout_data.renew_data.is_renewal=yes)
- [x] 54. `getFees()` caches result per request — second call returns same array without re-evaluating
- [x] 55. `clearFeeCache()` resets cached fees so next getFees() re-evaluates
- [x] 56. `buildFeeCartItem()` static method returns cart-data-compatible line item with payment_type=fee, is_fee=true, fulfillment_type=digital

### Totals
- [x] 57. `getEstimatedTotal()` sums onetime + subscription item totals (via OrderService::getItemsAmountTotal), adds shipping and fees
- [x] 58. `getEstimatedTotal()` clamps result to minimum 0 (no negative totals)
- [x] 59. `getEstimatedTotal()` fires `fluent_cart/cart/before_totals_calculation` and `fluent_cart/cart/after_totals_calculation` actions
- [ ] 60. `getEstimatedTotal()` applies `fluent_cart/cart/item_dynamic_discount` filter on cart_data before calculation
- [ ] 61. `getEstimatedTotal()` has recursion guard — hooks calling getEstimatedTotal() get raw calculation without hooks
- [x] 62. `getEstimatedRecurringTotal()` sums only subscription items' (subtotal - recurring_discounts.amount)
- [x] 63. `getEstimatedRecurringTotal()` returns 0 when cart has no subscription items
- [x] 64. `getItemsSubtotal()` returns sum of item subtotals (before discounts) for both onetime and subscription items

### State Checks
- [x] 65. `isLocked()` returns true only when checkout_data.is_locked='yes' AND order_id is set
- [x] 66. `isLocked()` returns false when is_locked='yes' but order_id is null
- [x] 67. `hasSubscription()` returns true when any cart item has other_info.payment_type=subscription
- [x] 68. `hasSubscription()` returns false when cart has only onetime items
- [x] 69. `requireShipping()` returns true when any cart item has fulfillment_type=physical
- [x] 70. `requireShipping()` returns false when all items are digital
- [x] 71. `isZeroPayment()` returns true when estimated total is 0 and cart has no subscriptions
- [x] 72. `isZeroPayment()` returns false when estimated total is 0 but cart has subscriptions

### Address Methods
- [x] 73. `getBillingAddress()` extracts billing fields from checkout_data.form_data (full_name, company, address_1, address_2, city, state, postcode, country)
- [x] 74. `getShippingAddress()` returns shipping fields when `isShipToDifferent()` is true
- [x] 75. `getShippingAddress()` falls back to billing address when `isShipToDifferent()` is false
- [x] 76. `isShipToDifferent()` returns true when checkout_data.form_data.ship_to_different='yes'

### Cart Lookup
- [x] 77. `guessCustomer()` resolves customer by customer_id first, then user_id, then email
- [x] 78. `guessCustomer()` returns null when no matching customer found
- [x] 79. `findExistingItemAndIndex()` finds item by object_id and returns [index, item] tuple
- [x] 80. `findExistingItemAndIndex()` with extraArgs matches additional item properties
- [x] 81. `findExistingItemAndIndex()` returns null when no match found

### Discount Lines
- [x] 82. `getDiscountLines()` with single coupon returns map with code, type, discount amount from sum of cart item coupon_discount values
- [ ] 83. `getDiscountLines()` with multiple coupons returns per-coupon amounts from checkout_data.__per_coupon_discounts
- [x] 84. `getDiscountLines()` returns empty array when no coupons applied
- [x] 85. `getDiscountLines()` with percentage coupon includes formatted_title with percentage suffix (e.g. "CODE10 (10%)")

## Service: CheckoutService

- [x] 86. Constructor with mixed items categorizes into `onetime` and `subscriptions` arrays by other_info.payment_type
- [x] 87. Constructor with mixed items categorizes into `physicalItems` and `digitalItems` arrays by fulfillment_type
- [x] 88. Constructor sets `count` to total number of items
- [x] 89. Constructor accepts both plain arrays and Collection instances
- [x] 90. `isAllPhysical()` returns true when all items are physical
- [x] 91. `isAllPhysical()` returns false when any item is digital
- [x] 92. `isAllDigital()` returns true when all items are digital
- [x] 93. `isAllDigital()` returns false when any item is physical
- [x] 94. `hasDigitalProduct()` returns true when at least one item is digital
- [x] 95. `hasPhysicalProduct()` returns true when at least one item is physical
- [x] 96. Constructor with empty array sets all categories to empty and count=0

## Helper: CartHelper

- [x] 97. `generateCartItemFromVariation()` returns array with keys: id, object_id, post_id, quantity, post_title, title, price, unit_price, line_total, subtotal, fulfillment_type, other_info, variation_type
- [x] 98. `generateCartItemFromVariation()` calculates subtotal as item_price * quantity, line_total as item_price * quantity
- [x] 99. `generateCartItemFromVariation()` applies `fluent_cart/cart/item_price` filter on price, clamps filtered result to non-negative integer
- [x] 100. `generateCartItemCustomItem()` returns array with custom item fields including manual_discount and coupon_discount
- [x] 101. `generateCartItemCustomItem()` calculates line_total as subtotal - discount_total (where discount_total = manual_discount + coupon_discount)
- [x] 102. `calculateShippingCharge()` returns 0 for non-physical (digital) variations
- [x] 103. `calculateShippingCharge()` returns 0 when variation has no shipping class
- [ ] 104. `calculateShippingCharge()` with percentage shipping class calculates as (cost/100) * item_price * factor
- [ ] 105. `calculateShippingCharge()` with fixed shipping class calculates as (cost * 100) * factor
- [ ] 106. `calculateShippingCharge()` multiplies by quantity when shipping class has per_item=true
- [x] 107. `shouldAddItemToCart()` returns true when manage_stock=0 (stock not managed)
- [x] 108. `shouldAddItemToCart()` returns true when requested quantity <= available stock
- [x] 109. `shouldAddItemToCart()` returns false when requested quantity > available stock
- [x] 110. `normalizeCustomFields()` sets id from item_id fallback, item_price from unit_price/price fallbacks, defaults payment_type to 'onetime', fulfillment_type to 'digital'
- [x] 111. `generateCartFromVariation()` creates unsaved Cart model with cart_data containing one item from the variation

## Helper: CheckoutProcessor

### Order Item Preparation
- [ ] 112. `prepareOrderItems()` formats each cart item into order item with: payment_type, post_id, object_id, quantity, unit_price, subtotal (unit_price * quantity), discount_total (manual + coupon), fulfillment_type
- [ ] 113. `prepareOrderItems()` creates separate signup_fee order item when subscription has signup_fee > 0
- [ ] 114. `prepareOrderItems()` distributes discount between subscription item and signup_fee item proportionally when no trial days
- [ ] 115. `prepareOrderItems()` applies full discount to signup_fee when subscription has trial_days > 0
- [ ] 116. `prepareOrderItems()` creates bundle child items with payment_type='bundle' and bundle_parent references in other_info
- [x] 117. `prepareOrderItems()` creates fee order items with payment_type='fee', quantity=1, and fee metadata in other_info

### Order Data Preparation
- [x] 118. `prepareOrderData()` sets order status to 'on-hold' and payment_status to 'pending'
- [x] 119. `prepareOrderData()` sets type to 'subscription' when any item has payment_type=subscription, otherwise 'payment'
- [x] 120. `prepareOrderData()` sets fulfillment_type to 'physical' when any item is physical, otherwise 'digital'
- [x] 121. `prepareOrderData()` calculates subtotal as sum of all non-fee, non-trial item subtotals
- [x] 122. `prepareOrderData()` calculates total_amount as: subtotal - coupon_discount - manual_discount + fee_total + shipping_total + (tax_total if exclusive) + (shipping_tax if exclusive)
- [x] 123. `prepareOrderData()` clamps total_amount to minimum 0

### Subscription Data Preparation
- [ ] 124. `prepareSubscriptionData()` extracts subscription config (billing_interval, bill_times, trial_days, recurring_amount, signup_fee) from the single subscription item
- [x] 125. `prepareSubscriptionData()` does nothing when no subscription items exist
- [ ] 126. `prepareSubscriptionData()` does nothing when more than one subscription item exists (only single subscription per order)
- [ ] 127. `prepareSubscriptionData()` adds shipping charge to recurring_total for physical subscription items

### Draft Order Creation
- [x] 128. `createDraftOrder()` returns WP_Error when customer_id is missing from args
- [x] 129. `createDraftOrder()` creates Order record with prepared order data and customer_id
- [x] 130. `createDraftOrder()` creates OrderItem records for each formatted item (excluding signup_fee items which become additional_items)
- [x] 131. `createDraftOrder()` calculates OrderItem line_total as subtotal - discount_total
- [x] 132. `createDraftOrder()` creates Subscription record when subscriptionData exists, linked to order via parent_order_id
- [x] 133. `createDraftOrder()` creates OrderTransaction with type='charge', status='pending', total matching order total_amount
- [x] 134. `createDraftOrder()` inserts applied coupons and increments Coupon.use_count for each
- [x] 135. `createDraftOrder()` links Cart to Order (sets cart.order_id, cart.stage='intended', cart.customer_id)
- [ ] 136. `createDraftOrder()` executes `__after_draft_created_actions__` from cart checkout_data if present

### Bundle & Additional Items
- [ ] 137. `createDraftOrder()` creates bundle child OrderItems with payment_type='bundle' and line_meta.bundle_parent_item_id referencing parent
- [ ] 138. `createDraftOrder()` stores bundle_item_ids in parent item's line_meta
- [ ] 139. `createDraftOrder()` creates signup_fee additional OrderItems with line_meta.parent_item_id referencing subscription item
- [ ] 140. `createDraftOrder()` stores additional_item_ids in subscription item's line_meta

### Adjusted Order (Re-checkout)
- [x] 141. `createDraftOrder($prevOrder)` delegates to getAdjustedOrder — updates existing order instead of creating new one
- [x] 142. `getAdjustedOrder()` for locked carts only updates: note, payment_method, ip_address, customer_id, shipping_total, total_amount, tax_total, fee_total, shipping_tax
- [x] 143. `getAdjustedOrder()` for non-locked carts replaces all order items, deleting items not in new set
- [ ] 144. `getAdjustedOrder()` for renewal orders finds existing subscription by parent_order_id instead of creating new one
- [x] 145. `getAdjustedOrder()` preserves applied coupon records for locked carts (no re-insert to avoid double use_count increment)

### Accessors
- [x] 146. `getOrder()` returns the created Order model
- [x] 147. `getTransaction()` returns the created OrderTransaction model
- [x] 148. `getSubscription()` returns the created Subscription model (or null if no subscription)

## Helper: CartCheckoutHelper

- [ ] 149. `make()` returns singleton instance — second call returns same object
  > Note: Skipped — singleton uses static variable, not testable across isolated tests without reset seam
- [x] 150. `getItems()` returns cart_data array from cart, empty array when cart is null
- [x] 151. `hasSubscription()` returns 'yes' when any cart item has other_info.payment_type=subscription, otherwise 'no'
- [x] 152. `isZeroPayment()` returns true when items total <= 0 and no subscriptions
- [x] 153. `requireShippingAddress()` returns true when any item has fulfillment_type=physical
- [x] 154. `getItemsAmountTotal()` returns total of onetime + subscription items via OrderService (formatted or raw cents)
- [x] 155. `getItemsAmountSubtotal()` returns subtotal before discounts (formatted or raw cents)
- [ ] 156. `getEmail()` resolves from: logged-in WP user -> customer -> cart.email (priority order)
  > Note: Skipped — depends on CustomerResource::getCurrentCustomer() static cache
- [x] 157. `getFirstName()` resolves from: cart.first_name -> WP user -> customer (priority order)
- [x] 158. `getLastName()` resolves from: cart.last_name -> WP user -> customer (priority order)
- [ ] 159. `getBillingAddress()` merges checkout_data billing with customer's stored billing address
  > Note: Skipped — depends on CustomerResource::getCurrentCustomer() static cache
- [x] 160. `getUtmData()` extracts utm_campaign, utm_content, utm_term, utm_source, utm_medium, utm_id, refer_url, fbclid, gclid from input, merged with cart's utm_data

## Helper: CartHelper (Shipping Method Charge)

- [ ] 161. `calculateShippingMethodCharge()` returns 0 for free_shipping method type
  > Note: Deferred — depends on static state caching in calculateShippingMethodCharge
- [ ] 162. `calculateShippingMethodCharge()` returns 0 when all items are digital (isAllDigital)
- [ ] 163. `calculateShippingMethodCharge()` with configure_rate=per_order calculates as method.amount * 100
- [ ] 164. `calculateShippingMethodCharge()` with configure_rate=per_price calculates as totalItemPrice * (method.amount / 100)
- [ ] 165. `calculateShippingMethodCharge()` with configure_rate=per_item calculates as method.amount * totalQuantity * 100
- [ ] 166. `calculateShippingMethodCharge()` with class_aggregation=highest_class adds only the highest per-item shipping charge
- [ ] 167. `calculateShippingMethodCharge()` with class_aggregation=sum_all adds sum of all per-item shipping charges

## REST: POST /fluent-cart/v2/checkout/place-order

- [x] 168. `CheckoutController::placeOrder()` no longer counts a limiter hit itself; `CheckoutApi::placeOrder()` applies the single limit of 5 per 60 seconds, so the REST route does not count each request twice _(SCAN-08, RateLimitedEntryPointsTest + PlaceOrderRateLimitCest)_
- [ ] 169. Delegates to CheckoutApi::placeOrder() with request data

## REST: GET /fluent-cart/v2/checkout/get-checkout-summary-view

- [ ] 170. Returns response with subtotal, total, shipping_charge, has_subscriptions, shipping_method_id
- [ ] 171. Calculates shipping charge from provided shipping_method_id
- [ ] 172. Returns shipping_charge=0 when no shipping method provided

## REST: Cart Endpoints

- [ ] 173. CartController::getStatus() returns cart status via CartResource::getStatus()
- [ ] 174. CartController::addToCart() with valid item_id and quantity adds item to cart via CartResource::create()
- [ ] 175. CartController::addToCart() returns WP_Error for invalid item
- [ ] 176. CartController::updateCart() updates cart data via CartResource::update()
- [ ] 177. CartController::updateCart() returns error response when CartResource::update() returns WP_Error
- [x] 177a. CartResource::update() creates the device cart only after the item is valid; a missing or unknown item_id from a cookie-less client is rejected and persists no cart row, while a valid item still creates the cart _(SCAN-07, CartUpdateRejectedItemTest)_
- [x] 177b. Place-order re-prices a cart line whose catalog price changed since it was added (same `fluent_cart/cart/item_price` filter), refuses that attempt with 423 + reload and creates no order; the next attempt charges the current price. Skips every cart with `checkout_data.is_locked = yes` (also early-installment carts with no order yet), renewals, custom items, order-bump (promotion) and price-locked lines. A line counts as stale when its unit price, base signup fee, `manage_setup_fee`, `trial_days`, `repeat_interval` or `times` differs from the line built now; the stale line is replaced at its own index and keeps its own `other_info` extras but never the old billing terms (a subscription turned one-time loses `repeat_interval`, `times`, etc.), so a second line for the same variation is fixed and checkout cannot loop on 423 _(SCAN-05, PlaceOrderCurrentPriceTest)_

## Service: RateLimiter

- [x] 178. `isSpamming()` counts hits in a transient when no external object cache is active, and in the object cache when one is (`isActive()` still reports which) _(SCAN-08)_
- [x] 179. `isSpamming()` allows up to $limit requests within $seconds window for same IP + user
- [x] 180. `isSpamming()` with $sendJson=true sends 429 JSON response when limit exceeded
- [x] 180a. Without an external object cache, the request over $limit inside $seconds is refused _(SCAN-08)_
- [x] 180b. Hits from one visitor update the window under a per-visitor MySQL lock (2 s wait); a hit that cannot get the lock counts as over the limit _(SCAN-08)_
- [x] 180c. With an external object cache the window lives in the object cache and the request over $limit is refused the same way _(SCAN-08)_
- [x] 180d. Entry points: `CartResource::generateCartForInstantCheckout()` refuses the 11th call in a minute with 429 and creates only 10 carts; `CheckoutApi::placeOrder()` refuses the 6th attempt in a minute with 429 and creates no order _(SCAN-08, RateLimitedEntryPointsTest)_

## Handler: CartCookieHandler

- [ ] 181. Sets cart hash cookie on WordPress `init` action when no cart cookie exists
- [ ] 182. Sets cart hash cookie via `rest_request_before_callbacks` filter for REST requests when no cart cookie exists
- [ ] 183. Does not overwrite existing cart cookie

## Edge Cases

- [x] 184. Cart with empty cart_data (null/invalid JSON) returns empty array from getCartDataAttribute
- [ ] 185. Cart soft deletes — deleted_at column used, records not physically removed
- [x] 186. Cart with locked state blocks ALL modification methods: addItem, removeItem, addByVariation (with validate), applyCoupon, removeCoupon
- [ ] 187. CheckoutProcessor with subscription + signup_fee + trial_days correctly distributes discount only to signup_fee (not subscription item)
- [ ] 188. CheckoutProcessor with subscription but no trial_days and discount distributes discount proportionally between subscription and signup_fee based on subtotal ratio
- [ ] 189. Cart getEstimatedTotal with custom_checkout=yes and no shipping method uses custom_checkout_data.shipping_total
- [x] 190. Cart static $cache is keyed by cart_hash — setting cart_data invalidates cache for that hash
- [x] 191. Multiple addFee() calls with different source:key pairs accumulate fees; same source:key updates in place
- [x] 192. CheckoutProcessor fee items have fulfillment_type='digital' and quantity=1 regardless of input
- [ ] 193. CheckoutProcessor with subscription validates only single subscription per order — multiple subscription items are silently skipped
- [x] 194. Cart addByCustom enforces that custom subscription items must go through direct checkout flow, not cart
- [x] 195. getAdjustedOrder deletes orphaned OrderItems not in the new formatted set (cleanup of stale items)
- [x] 196. getAdjustedOrder for locked carts syncs fee items even when skipping product item rebuild

# Coupons

## Model: Coupon

### Schema & Attributes
- [x] 1. Table is `fct_coupons`, primary key is `id`
- [x] 2. Has fillable attributes: parent, title, code, status, type, conditions, amount, stackable, priority, use_count, notes, show_on_checkout, start_date, end_date
- [ ] 3. Casts max_uses to integer
  > Note: `max_uses` column does not exist in the base fct_coupons migration — value stored inside `conditions` JSON

### JSON Accessors
- [x] 4. `conditions` attribute round-trips through JSON — setting an array encodes to JSON, getting decodes back to array; returns empty array when null
- [ ] 5. `settings` attribute — column does not exist in base migration (accessor defined but untestable)
- [ ] 6. `other_info` attribute — column does not exist in base migration (accessor defined but untestable)
- [ ] 7. `categories` attribute — column does not exist in base migration (accessor defined but untestable)
- [ ] 8. `products` attribute — column does not exist in base migration (accessor defined but untestable)

### Relationships
- [x] 9. Coupon hasMany AppliedCoupon via `coupon_id` — returns collection of applied records
- [x] 10. Coupon belongsToMany Order via `fct_applied_coupons` pivot (coupon_id, order_id)

### Scope
- [x] 11. `scopeActive()` filters to status='active' AND (end_date is null OR end_date='0000-00-00 00:00:00' OR end_date > now)
- [x] 12. `scopeActive()` excludes coupons with end_date in the past
- [x] 13. `scopeActive()` includes coupons with no end_date set

### Meta
- [x] 14. `getMeta($key)` returns meta value from fct_meta where object_type='coupon' and object_id matches
- [x] 15. `getMeta($key)` returns $default when meta key not found
- [x] 16. `updateMeta($key, $value)` creates new meta row when key doesn't exist
- [x] 17. `updateMeta($key, $value)` updates existing meta row when key already exists

### Methods
- [x] 18. `isRecurringDiscount()` returns true when conditions.is_recurring='yes'
- [x] 19. `isRecurringDiscount()` returns false when conditions.is_recurring is absent or not 'yes'
- [x] 20. `getStatus()` returns current status string
- [x] 21. `setStatus($value)` updates the status attribute

## Model: AppliedCoupon

### Schema & Attributes
- [x] 22. Table is `fct_applied_coupons`, primary key is `id`
- [x] 23. Has fillable attributes: order_id, coupon_id, code, amount

### Relationships
- [x] 24. AppliedCoupon belongsTo Order via `order_id`
- [ ] 25. AppliedCoupon belongsTo Coupon via `code` → `id`
  > Note: Relationship uses wrong FK mapping in model; tested via Coupon.appliedCoupons instead

### JSON Accessors
- [x] 26. `other_info` attribute returns empty array when null (in-memory only — column not in base migration)
- [x] 27. `categories` attribute returns empty array when null (in-memory only)
- [ ] 28. `products` attribute — column does not exist in base migration

## Service: DiscountService

### Constructor & Setup
- [x] 29. Constructor with Cart uses cart.cart_data as cartItems when no explicit cartItems passed
- [x] 30. Constructor with explicit cartItems uses those instead of cart.cart_data
- [x] 31. `resetIndividualItemsDiscounts()` sets coupon_discount=0 on all items, preserves manual_discount, recalculates discount_total and line_total

### applyCouponCodes
- [x] 32. `applyCouponCodes()` with valid active coupon code applies discount and returns result with applied_coupon_codes, coupon_results, cart_items, per_coupon_discounts
- [x] 33. `applyCouponCodes()` merges existing cart coupons with new codes, deduplicates
- [x] 34. `applyCouponCodes()` returns WP_Error when no matching active coupons found in database
- [x] 35. `applyCouponCodes()` with multiple coupons where one is non-stackable keeps only the first valid coupon and marks non-stackable as invalid
- [x] 36. `applyCouponCodes()` with multiple stackable coupons sorts by priority (lower value = higher priority) before applying
- [x] 37. `applyCouponCodes()` resets item discounts before reapplying all valid coupons

### Coupon Validation (isCouponValid)
- [x] 38. Rejects coupon with start_date in the future (coupon not yet started)
- [x] 39. Rejects coupon with end_date in the past (coupon expired)
- [x] 40. Accepts coupon with no start_date and no end_date
- [x] 41. Accepts coupon with start_date in the past and end_date in the future
- [ ] 42. Rejects coupon when conditions.max_purchase_amount is set and cart total (in dollars) exceeds it
- [ ] 43. Rejects coupon when conditions.min_purchase_amount is set and cart total is below it (amount compared in cents)
- [x] 44. Rejects coupon when use_count >= conditions.max_uses
- [x] 45. Accepts coupon when use_count < conditions.max_uses
- [x] 46. Accepts coupon when conditions.max_uses is 0 or not set (unlimited uses)
- [ ] 47. Rejects coupon with conditions.max_per_customer when user is not logged in (requires login)
- [ ] 48. Rejects coupon when customer has already used it conditions.max_per_customer times (checks AppliedCoupon count)
- [ ] 49. Accepts coupon when customer usage is below max_per_customer limit

### Item Filtering (filterApplicableItems)
- [x] 50. Excludes items in conditions.excluded_products by object_id
- [x] 51. Includes only items in conditions.included_products by object_id when set (non-matching excluded)
- [ ] 52. Excludes items whose product category is in conditions.excluded_categories
- [ ] 53. Includes only items whose product category is in conditions.included_categories when set
- [x] 54. Skips items where other_info.is_locked='yes'
- [x] 55. Respects `fluent_cart/coupon/will_skip_item` filter — items returning true are excluded
- [x] 56. Filters by conditions.email_restrictions — only cart emails matching pattern (supports * wildcard) pass
- [ ] 57. Returns empty array when no items match, causing apply() to return WP_Error

### Discount Calculation
- [x] 58. `calculateDiscountPercent()` for fixed coupon with amount < total returns (amount/total)*100
- [x] 59. `calculateDiscountPercent()` for fixed coupon with amount >= total returns 100.0 (caps at item total)
- [x] 60. `calculateDiscountPercent()` for percentage coupon returns the coupon amount clamped to [0, 100]
- [ ] 61. `applyDiscountToItems()` distributes discount proportionally across items based on remaining total after existing discounts
- [ ] 62. `applyDiscountToItems()` for subscription items without trial_days applies recurring discount when coupon.isRecurringDiscount()
- [ ] 63. `applyDiscountToItems()` for subscription items with trial_days > 0 does NOT apply recurring discount
- [ ] 64. `correctFixedCouponRounding()` adds remaining cents when rounding left discount short of coupon amount
- [ ] 65. `correctFixedCouponRounding()` removes excess cents when rounding exceeded coupon amount
- [x] 66. `updateItemTotals()` sets discount_total = manual_discount + coupon_discount and line_total = subtotal - discount_total (clamped to 0)

### Subscription Effective Subtotal
- [x] 67. `getItemEffectiveSubtotal()` for subscription with trial_days > 0 uses signup_fee * quantity as subtotal (not item subtotal)
- [x] 68. `getItemEffectiveSubtotal()` for non-subscription or no trial_days returns item subtotal

### apply() Integration
- [x] 69. `apply()` fires `fluent_cart/discount/pre_apply` filter on cartItems before applying
- [x] 70. `apply()` fires `fluent_cart/coupon/can_use_coupon` filter — returns WP_Error if filter returns false or WP_Error
- [ ] 71. `apply()` returns WP_Error('no_applicable_items') when no items pass filtering
- [ ] 72. `apply()` returns WP_Error('no_applicable_items') when all items already fully discounted (remaining total <= 0)
- [ ] 73. `apply()` returns WP_Error('no_discount_applied') when calculated discount is 0
- [x] 74. `apply()` on success adds coupon code to appliedCoupons and stores per-coupon discount total

### Accessors
- [x] 75. `getCartItems()` returns updated cart items after discount application
- [x] 76. `getAppliedCoupons()` returns array of applied coupon codes
- [x] 77. `getPerCouponDiscounts()` returns map of coupon_code => discount_total_in_cents

## Resource: CouponResource

### CRUD
- [x] 78. `create()` with type='fixed' converts amount from dollars to cents via Helper::toCent()
- [x] 79. `create()` with type='percentage' stores amount as-is (not converted to cents)
- [x] 80. `create()` converts conditions.min_purchase_amount to cents
- [x] 81. `create()` converts conditions.max_discount_amount to cents when present
- [x] 82. `update()` finds existing coupon by id, updates attributes, returns success response
- [x] 83. `update()` returns WP_Error when id is missing
- [x] 84. `update()` returns WP_Error when coupon not found
- [x] 85. `delete()` removes coupon record, returns success
- [x] 86. `delete()` returns WP_Error when id is missing
- [x] 87. `delete()` returns WP_Error when coupon not found
- [x] 88. `find()` by id returns Coupon model
- [x] 89. `find()` with type='byCode' does case-sensitive lookup via BINARY comparison
- [x] 90. `listCoupon()` returns array of codes for active coupons only

## Helper: CouponHelper

### Status Updates
- [x] 91. `updateCouponStatus()` sets status to 'expired' when end_date has passed and status is not already 'expired'
- [x] 92. `updateCouponStatus()` sets status to 'scheduled' when start_date is in the future and status is not 'disabled' or 'scheduled'
- [x] 93. `updateCouponStatus()` sets status to 'active' when start_date has passed and status is not 'disabled' or 'active'
- [x] 94. `updateCouponStatus()` does nothing when coupon is empty

### Stackability
- [x] 95. `checkStackability()` returns error when coupon code already in applied list (duplicate)
- [x] 96. `checkStackability()` returns error when last applied coupon has stackable='no'
- [x] 97. `checkStackability()` returns error when new coupon has stackable='no' and applied list is non-empty

## REST: GET /fluent-cart/v2/coupons

- [ ] 98. Returns paginated coupon list with search by title, code, or id
- [ ] 99. Requires `coupons/view` permission — unauthorized user gets 403
- [ ] 100. Filters by active view tab (active, expired)
- [ ] 101. Search with '%' suffix searches by percentage amount in type=percentage coupons

## REST: GET /fluent-cart/v2/coupons/{id}

- [ ] 102. Returns coupon details with activities
- [ ] 103. Auto-expires coupon if end_date has passed

## REST: POST /fluent-cart/v2/coupons

- [ ] 104. Creates coupon with converted amount (cents for fixed, raw for percentage)
- [ ] 105. Converts start_date and end_date to GMT
- [ ] 106. Fires `fluent_cart/coupon_created` action after creation
- [ ] 107. Logs activity with module_name='coupon'
- [ ] 108. Requires `coupons/manage` permission

## REST: PUT /fluent-cart/v2/coupons/{id}

- [ ] 109. Updates coupon attributes
- [ ] 110. Fires `fluent_cart/coupon_updated` action after update
- [ ] 111. Requires `coupons/manage` permission

## REST: DELETE /fluent-cart/v2/coupons/{id}

- [ ] 112. Deletes coupon by id
- [ ] 113. Requires `coupons/delete` permission

## REST: POST /fluent-cart/v2/coupons/apply

- [ ] 114. Applies coupon to order items, returns applied_coupons and calculated_items
- [ ] 115. Returns WP_Error for invalid or inapplicable coupon codes

## REST: POST /fluent-cart/v2/coupons/cancel

- [ ] 116. Cancels applied coupon, removes AppliedCoupon record, decrements Coupon.use_count
- [ ] 117. Returns recalculated items without the cancelled coupon

## REST: GET /fluent-cart/v2/coupons/listCoupons

- [ ] 118. Returns array of active coupon codes only
- [ ] 119. Requires `coupons/view` or `orders/create` or `orders/manage` permission

## Permissions

- [ ] 120. CouponPolicy uses hasRoutePermissions() — delegates to route meta permissions
- [ ] 121. User with `coupons/view` can GET /coupons but cannot POST, PUT, or DELETE
- [ ] 122. User with `coupons/manage` can POST and PUT but cannot DELETE
- [ ] 123. User with `coupons/delete` can DELETE coupons
- [ ] 124. User with no coupon capabilities gets 403 on all coupon endpoints

## Edge Cases

- [x] 125. Fixed coupon with amount greater than cart subtotal caps discount at subtotal (no negative line_total)
- [x] 126. Percentage coupon of 100% zeroes out all applicable item line_totals
- [ ] 127. Percentage coupon of 0% applies no discount
- [x] 128. Two stackable coupons applied sequentially — second coupon calculates on remaining total after first
- [ ] 129. Non-stackable coupon applied alone works; adding a second coupon fails with stackability error
- [ ] 130. Coupon with both included_products and excluded_categories — included_products takes precedence over category exclusion
- [x] 131. Coupon applied to cart with subscription item using trial_days — discount uses signup_fee as effective subtotal, not recurring amount
- [x] 132. Coupon with email_restrictions='*@company.com' matches 'user@company.com' but not 'user@other.com'
- [x] 133. Applying same coupon code twice is deduplicated — only applied once
- [x] 134. Coupon with max_uses=1 and use_count=1 is rejected as expired uses
- [ ] 135. Cart coupon application via Cart::applyCoupon() integrates with DiscountService and persists per-coupon discounts to checkout_data
- [x] 136. CouponResource::formatAmount() does NOT convert percentage amount to cents — only fixed amounts and condition amounts

## New scenarios (added 2026-08-07)

<!-- Regression: DiscountService (the storefront checkout calculator) never read conditions.max_discount_amount — only the admin-side CouponServiceAdmin (CanCalculateLineTotal::calculateApplicableAmount) applied the cap. A 50% coupon with a $20 cap discounted a $750 cart by $375 at checkout. Applies to every amount/type whose computed discount exceeds the cap, including the recurring-discount branch. -->

- [x] 137. Percentage coupon discount at checkout is capped at the coupon's max discount amount
- [x] 138. Percentage coupon whose computed discount is below the max discount cap is unaffected by the cap
- [x] 139. Fixed coupon discount at checkout is capped at the coupon's max discount amount
- [x] 140. Max discount cap distributes the capped discount across multiple applicable items without exceeding the cap
- [x] 141. Recurring subscription discount is also capped at the coupon's max discount amount

## New scenarios (added 2026-08-10)

<!-- Audit finding C5: DiscountService's stacking filter kept ALL stackable coupons and
     dropped EVERY non-stackable one, consulting position only when nothing was stackable.
     Under the agreed rule — the first coupon applied always stays (already implemented on
     the admin path by CanValidateCoupon::canBeStacked) — a customer who applied a large
     non-stackable coupon and then tried a second code silently lost the large one.
     applyCouponCodes() merges existing cart coupons before new codes and formatCoupons()
     preserves that order, so index 0 is genuinely the first-applied valid coupon. -->

- [x] 142. When a non-stackable coupon is already applied, adding another coupon keeps the non-stackable coupon and rejects the newcomer as not stackable.
- [x] 143. Submitting a non-stackable coupon first and a stackable coupon together in one batch keeps the non-stackable coupon and rejects the stackable one.
- [x] 144. When a stackable coupon is already applied, a later non-stackable coupon is rejected and the stackable coupon stays.
- [x] 145. Stackable coupons still stack on top of a first-applied stackable coupon after the first-stays rule.

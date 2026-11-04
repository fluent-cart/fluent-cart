# Stock

Covers `FluentCart\App\Modules\StockManagement\StockManagement` (single class, 6 hook handlers), `ProductVariation` stock-related accessors (`canPurchase`, `isStock`, `isBundleChildrenInStock`), `ProductDetail::getStockAvailability`, `StockChanged` event, and the legacy `UpdateStock` listener (currently unregistered but retained as public API on `app/Listeners/UpdateStock.php`). Stock data lives on `fct_product_variations` (`total_stock`, `available`, `committed`, `on_hold`, `manage_stock`, `stock_status`, `backorders`, `fulfillment_type`, `item_status`) and `fct_product_details` (`manage_stock`, `stock_availability`). The module is gated by `ModuleSettings::isActive('stock_management')` — inactive means only the setting filters register; no stock mutation runs.

Movement ledger: persisted as JSON on `fct_order_meta` key `stock_movement` keyed by `order_item_id` → `{ on_hold, committed }`. Every handler merges into this ledger and writes it back.

## Module bootstrap (`StockManagement::register`)

- [x] 1. Adds `stock_management` field to `fluent_cart/module_setting/fields` filter with title/description/type=component/component=ModuleSettings — registers regardless of active state
- [x] 2. `fluent_cart/module_setting/default_values` filter sets `stock_management.active = 'no'` when unset
- [x] 3. `fluent_cart/shop_query` filter hook is always registered (priority 10, 2 args) even when module inactive
- [x] 4. When `ModuleSettings::isActive('stock_management') === false`, `register()` returns early — no order/shipping/refund listeners added
      _Test pair uses a `$wp_filter` walker to strip residue + `ModuleSettings::getAllSettings(false)` to defeat the static cache._
- [x] 5. When active: registers actions `fluent_cart/order_created`, `fluent_cart/shipping_status_changed`, `fluent_cart/order_status_changed`, `fluent_cart/order_refunded`, `fluent_cart/order_paid`, `fluent_cart/order_updated`, plus filter `fluent_cart/variation/can_purchase_bundle`

## Shop query filter (`filterShopQuery`)

- [x] 6. With no `selected_status` param → query returned unchanged (no search applied)
- [x] 7. With `selected_status` set + stock_management active + `allow_out_of_stock=false` → scopes via `whereHas('detail', ...)` filtering `stock_availability = 'in-stock'` (uses `Helper::IN_STOCK` constant)
- [x] 8. With `selected_status` set + `allow_out_of_stock=true` → skips `stock_availability` whereHas, keeps status search
- [x] 9. With `selected_status` set + stock_management inactive → skips `stock_availability` whereHas even if allow_out_of_stock=false

## Bundle purchase filter (`fluent_cart/variation/can_purchase_bundle`)

- [x] 10. Non-bundle variation → returns `true` unchanged
- [ ] 11. Bundle variation with child missing `product_detail` → `WP_Error('unpublished')` with translated message
- [x] 12. Bundle variation, child `product_detail.manage_stock=1` + `child.manage_stock=1` + `quantity > child.available` → `WP_Error('insufficient_stock')`
- [x] 13. Bundle variation, child `manage_stock=0` (either side) → not checked for stock, returns `true`
- [x] 14. Bundle variation, all children pass → returns `true`

## `manageStockOnOrderCreated` (fluent_cart/order_created)

- [x] 15. Early return when `prev_order` is present (handler is order-creation-only; updates handled by `manageStockOnOrderUpdated`)
- [x] 16. Early return when `order` or `order_items` empty
- [x] 17. Filters out order items with `payment_type` in `['signup_fee', 'fee']` before deduction
- [x] 18. Only variations with `manage_stock = 1` are mutated — non-managed variations skipped silently
- [x] 19. For each managed variation: `available = max(0, available - quantity)`, `on_hold = on_hold + quantity`, `stock_status = 'out-of-stock'` if new_available ≤ 0 else `'in-stock'`
- [x] 20. Quantity ≤ 0 in order item → that variation is skipped (no update queued)
- [ ] 21. `ProductVariation::batchUpdate()` called once with all updated variants (not per-variation)
- [x] 22. Bundle parent (`product_detail.other_info.is_bundle_product = 'yes'`) → each bundle child with `manage_stock=1` also gets available decremented + on_hold += quantity
- [x] 23. Bundle child with `manage_stock=0` → skipped (no stock mutation)
- [x] 24. `OrderMeta` `stock_movement` entry created keyed by `order_item_id` containing `{ on_hold: quantity }`
- [ ] 25. `OrderMeta` existing `stock_movement` merged (not overwritten) on re-run
- [x] 26. For each affected `post_id`: `fct_product_details.stock_availability` recomputed — 'in-stock' if any variation `stock_status='in-stock'` exists, else 'out-of-stock'

## `manageStockOnShippingStatusChanged` (fluent_cart/shipping_status_changed)

- [x] 27. Only considers variations with `manage_stock=1` AND `fulfillment_type='physical'`
- [x] 28. Transition to `new_status='delivered'`: `committed += quantity`, `on_hold -= quantity`; `stock_movement[orderItemId] = { committed: qty, on_hold: 0 }`
- [x] 29. Transition away from delivered (`old_status='delivered'` && new_status != 'delivered'): `committed -= quantity`, `on_hold += quantity`; `stock_movement[orderItemId] = { committed: 0, on_hold: qty }`
- [x] 30. Transition between two non-delivered statuses (e.g. pending → shipped) → no stock mutation (updatedVariants empty after loop), but `stock_movement` OrderMeta still written with existing value
- [x] 31. Bundle parent (is_bundle_product=yes) → bundle children with `manage_stock=1` mirrored for the same on_hold↔committed swap
- [x] 32. Signup_fee / fee items filtered out before processing

## `manageStockOnOrderStatusChanged` (fluent_cart/order_status_changed)

- [x] 33. Early return when `new_status !== 'canceled'` (handler is cancel-only)
- [ ] 34. Early return when order or order_items missing
- [ ] 35. Signup_fee / fee items filtered out
- [ ] 36. Only variations with `manage_stock=1` mutated
- [x] 37. Removal priority: when `shipping_status='delivered'` OR (`fulfillment_type='digital'` && `payment_status='paid'`) → deduct from `committed` first then `on_hold`; otherwise `on_hold` first then `committed`
- [x] 38. Variation update: `available = max(0, available + quantity)`, `stock_status` recomputed, and the bucket(s) actually drained get `['-', removed]` batch ops
- [x] 39. `stock_movement[orderItemId].on_hold` decremented by `min(oldOnHold, remaining)`; likewise for committed, tracked across the priority order
- [ ] 40. Bundle parent → children with `manage_stock=1` mirror the same removal priority against child's own `on_hold` and `committed`
- [x] 41. After variation updates, `fct_product_details.stock_availability` recomputed per affected post_id (same in-stock-exists check as order-created)

## `manageStockOnOrderRefunded` (fluent_cart/order_refunded)

- [x] 42. Early return when `manage_stock=false` flag in event payload
- [x] 43. Uses `new_refunded_items` payload (each item: `variation_id`, `restore_quantity`, `id`) — not raw order_items
- [ ] 44. Only variations with `manage_stock=1` mutated
- [x] 45. Restock: `available = max(0, available + restore_quantity)`, `stock_status` recomputed
- [x] 46. Same removal priority (committed-first for delivered/digital-paid, else on_hold-first) applied against `stock_movement` ledger _(two-bucket fixture)_
- [ ] 47. Bundle parent → children with `manage_stock=1` restocked with same priority
- [x] 48. Order items with `quantity - restore_quantity <= 0` deleted from `fct_order_items` via `whereIn('id', ...)->delete()`
- [x] 49. Order items with remaining quantity > 0 have `quantity` updated via `batchUpdate`
- [ ] 50. `fct_product_details.stock_availability` recomputed per affected post_id

## `manageStockOnOrderPaid` (fluent_cart/order_paid)

- [x] 51. Only variations with `manage_stock=1` AND `fulfillment_type='digital'` mutated (physical wait for shipping)
- [x] 52. Moves `on_hold -= quantity`, `committed += quantity` for each managed digital item
- [x] 53. `stock_movement[orderItemId] = { committed: qty, on_hold: 0 }` written
- [ ] 54. Bundle parent → digital bundle children with `manage_stock=1` mirrored (note: bundle children filtered only by manage_stock — parent's fulfillment_type gates entry)
- [x] 55. Early return when no digital managed items matched
- [ ] 56. Signup_fee / fee items filtered out

## `manageStockOnOrderUpdated` (fluent_cart/order_updated)

- [x] 57. Computes diff per `object_id` across merged(old + new) order items: `diff = newQuantity - oldQuantity`
- [x] 58. `diff === 0` → variation skipped
- [x] 59. Variation with `manage_stock=0` → skipped
- [x] 60. `available = max(0, available - diff)` (diff > 0 deducts, diff < 0 restocks)
- [x] 61. When order is `shipping_status=delivered` OR digital+paid → diff affects `committed`; else affects `on_hold`
- [x] 62. `stock_movement[orderItemId]` updated with new on_hold or committed value clamped at ≥0
- [x] 63. Bundle parent → children with `manage_stock=1` mirrored using same committed/on_hold rule (note: bundle child check evaluates `child.fulfillment_type` for digital, not parent's)
- [x] 64. Removed items (present in old, absent in new) with positive oldQuantity → negative diff applied (restock)
- [ ] 65. Added items (absent in old, present in new) with positive newQuantity → positive diff applied (deduct)
- [x] 66. `fct_product_details.stock_availability` recomputed per affected post_id

## `ProductVariation::canPurchase`
_Implementation location: `tests/Integration/Models/ProductVariationTest.php`._

- [x] 67. Returns `WP_Error('unpublished')` when `item_status !== 'active'`
- [x] 68. Returns `WP_Error('unpublished')` when product's `post_status` not in `['publish', 'private']`
- [x] 69. Returns `WP_Error('invalid_subscription_quantity')` when `payment_type='subscription'` and quantity > 1
- [x] 70. Returns `WP_Error('unpublished')` when `product_detail` relation missing
- [x] 71. With stock_management active + `product_detail.manage_stock=1` + `variation.manage_stock=1` + `quantity > available` → `WP_Error('insufficient_stock')`
- [x] 72. With stock_management inactive → available check skipped regardless of quantity
- [x] 73. With `product_detail.manage_stock=0` OR `variation.manage_stock=0` → available check skipped
- [x] 74. Bundle product without Pro active → `WP_Error('invalid_bundle_product')` with translated user-facing message (`'Sorry, this product is not available for purchase.'`)
- [x] 75. `can_purchase_bundle` filter returning `WP_Error` → that error returned as-is
- [x] 76. `can_purchase_bundle` filter returning `false` → `WP_Error('insufficient_stock')`
- [x] 77. All gates pass → returns `true`

## `ProductVariation::isStock`
_Implementation location: `tests/Integration/Models/ProductVariationTest.php`._

- [x] 78. `item_status !== 'active'` → false
- [x] 79. `manage_stock=0` + non-bundle → returns `stock_status === 'in-stock'`
- [ ] 80. `manage_stock=0` + bundle product → defers to `isBundleChildrenInStock()`
- [x] 81. `manage_stock=1` + non-bundle + `available > 0` + `stock_status='in-stock'` → true
- [x] 82. `manage_stock=1` + non-bundle + `available = 0` → false
- [ ] 83. `manage_stock=1` + non-bundle + `stock_status='out-of-stock'` → false (even if available > 0)
- [ ] 84. `manage_stock=1` + bundle + parent in-stock + all children pass → true
- [x] 85. `manage_stock=1` + bundle + parent in-stock + any child fails → false
- [ ] 86. `manage_stock=1` + bundle + parent out-of-stock → false (no child check reached)

## `ProductVariation::isBundleChildrenInStock`
_Exercised transitively via `isStock` tests (items 85). Standalone item coverage below is open._

- [ ] 87. `other_info.bundle_child_ids` empty or not array → true (no children means in stock)
- [ ] 88. Child with `item_status !== 'active'` → false
- [ ] 89. Child with `manage_stock=0` → passes regardless of stock_status (digital/untracked children never block)
- [x] 90. Child with `manage_stock=1` + `available <= 0` → false
- [ ] 91. Child with `manage_stock=1` + `stock_status !== 'in-stock'` → false
- [ ] 92. All children pass → true
- [ ] 93. Query selects only columns: id, manage_stock, available, stock_status, item_status, post_id, other_info (nested bundle recursion intentionally disabled — each bundle handles its own children)

## `ProductDetail::getStockAvailability`
_Implementation location: `tests/Integration/Models/ProductDetailTest.php`._

- [x] 94. `manage_stock=0` → `['manage_stock' => false, 'availability' => 'In Stock', 'class' => 'in-stock', 'available_quantity' => null]`
- [x] 95. `manage_stock=1` + truthy `stock_availability` → `['manage_stock' => true, 'availability' => 'In Stock', 'class' => 'in-stock', 'available_quantity' => stock_availability]`
- [ ] 96. `manage_stock=1` + falsy `stock_availability` → `['manage_stock' => true, 'availability' => 'Out of Stock', 'class' => 'out-of-stock', 'available_quantity' => stock_availability]`
- [ ] 97. Result passed through `fluent_cart/product_stock_availability` filter with context `['detail' => $this, 'variation_id' => $variationId]`

## `StockChanged` event
_Implementation location: `tests/Integration/Events/MiscEventsTest.php`._

- [x] 98. `hook = 'fluent_cart/product_stock_changed'`
- [x] 99. `listeners` array empty (UpdateDefaultVariation commented out) — dispatch invokes only auto-appended Activity
- [x] 100. `shouldCreateActivity()` returns `false` → no `fct_activity` row created
- [ ] 101. `getActivityEventModel()` returns `null`
- [x] 102. `toArray()` returns `['post_ids' => postIds, 'other_info' => otherInfo]`
- [ ] 103. Constructor accepts `(postIds, otherInfo=null)` and exposes both as public properties

## Legacy `UpdateStock` listener (unregistered public API)
_Implementation location: `tests/Integration/Listeners/UpdateStockListenerTest.php`._

- [x] 104. `handle()` skips variations where `manage_stock=0` (via `shouldSkipManageStock`)
- [x] 105. On `order_created` with `shipping_status=unshipped` → `on_hold += qty`, `available = totalStock - committed - (onHold + qty)`, `stock_status` by available > 0
- [x] 106. On `order_status_updated` with old=unshipped new=canceled → `action='delete'`, `status='unshipped'` → decrements on_hold
- [x] 107. On `order_status_updated` with old in `[shipped, delivered]` and new=canceled → `action='delete'`, `status='shipped'` → decrements committed
- [x] 108. On `order_status_updated` with old=unshipped new in `[shipped, delivered]` → `status='shipped'` → on_hold−=qty, committed+=qty
- [x] 109. On `order_status_updated` with old in `[shipped, delivered]` new=unshipped → `status='restore'` → on_hold+=qty, committed−=qty
- [x] 110. On `order_status_updated` with `$event->manageStock != true` → `shouldUpdateStock=false`, no mutations written
- [x] 111. On `order_refunded` with `manageStock=true` → `action='refund'` → `committed-=qty`, `available+=qty`, stock_status recomputed
- [x] 112. On `order_updated` with added item (new not in old) → `action='add'`, `+` operation on on_hold/committed column
- [x] 113. On `order_updated` with deleted item (old not in new) → `action='delete'`, `-` operation
- [x] 114. On `order_updated` with edited item (same id, different qty) → diff = abs(newQty - oldQty), operator `+` if new > old else `-`
- [x] 115. After variant batch update, any product whose remaining variations have `manage_stock=1` AND `available > 0` gets `product_detail.stock_availability = Helper::IN_STOCK` written

## Schema / columns

- [x] 116. `fct_product_variations` has stock columns: `total_stock`, `available`, `committed`, `on_hold` (all bigint/integer casts) _(ProductVariationTest)_
- [x] 117. `fct_product_variations` has flags: `manage_stock` (tinyint), `stock_status` (string: `in-stock`|`out-of-stock`), `backorders`, `fulfillment_type` (`physical`|`digital`), `item_status` _(ProductVariationTest)_
- [x] 118. `fct_product_details` has `manage_stock` and `stock_availability` columns _(ProductDetailTest)_
- [x] 119. `fct_order_meta` persists `stock_movement` rows keyed by `(order_id, meta_key='stock_movement')`, value JSON map `{order_item_id: {on_hold, committed}}`

## Edge cases

- [ ] 120. Duplicate dispatch of `manageStockOnOrderCreated` MUST be idempotent (no double-deduct). _Asserted as failing regression via `markTestSkipped` with concrete bug reference — unskip when handler adds stock_movement-keyed guard._
- [ ] 121. Oversell handled at the `canPurchase()` guard; if it reaches the handler, `on_hold` MUST cap at `available` (not take full requested qty). _Split into two tests: guard test passes; failing-regression test skipped until cap logic lands._
- [x] 122. Order with mixed managed + unmanaged variations → only managed ones appear in batchUpdate and ProductDetail recompute
- [ ] 123. `ModuleSettings::getAllSettings()` is function-static cached — toggling `stock_management.active` mid-process does NOT re-arm the listeners (affects test isolation; add `markTestSkipped` with reference to `api/ModuleSettings.php:31` for any test that flips the flag between Arrange and Act)
      _Documented and applied as markTestSkipped reasons on items 4 & 5. Left unchecked because the issue itself has no dedicated assertion; observable only via the skips above._
- [x] 124. Order created with only `signup_fee`/`fee` items → no variations matched → early return, no OrderMeta row written
- [ ] 125. Order refunded with `restore_quantity=0` on every item → skips mutation per item (`quantity <= 0` guard), still writes empty OrderMeta merge
- [ ] 126. Bundle parent with `manage_stock=0` but children with `manage_stock=1` — parent's own variation is NOT decremented; children ARE decremented (is_bundle branch runs regardless of parent manage_stock; parent filter on `where('manage_stock', 1)` excludes parent from variations list so child branch never runs — record this as a coverage gap: bundle-children-only-tracking requires parent manage_stock=1)
- [x] 127. `ProductDetail.stock_availability` recompute: product with all variations `stock_status='out-of-stock'` → written as `'out-of-stock'`; at least one `in-stock` → `'in-stock'`
- [x] 128. `UpdateStock::handle` is registered nowhere (commented in `OrderCreated::$listeners` and `OrderStatusUpdated::$listeners`) — tests invoke it directly via `UpdateStock::handle($event)` to cover the legacy contract _(UpdateStockListenerTest)_

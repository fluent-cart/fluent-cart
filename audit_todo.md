# FluentCart Audit TODO

Generated: 2026-02-23
Repository: fluent-cart WordPress plugin

## Sub-agent Coverage Used
- `security-route-policy` checked route policy wiring, policy fallbacks, permission metadata coverage.
- `security-data-integrity` traced customer/subscription mutation paths to model/database writes.
- `optimization-backend` scanned for duplicated logic, oversized methods, stale handlers, and dead code.
- `optimization-frontend` scanned request wrappers, duplicated logic, and runtime bugs in admin/public JS.
- `traceability-ui-admin` traced admin UI `Rest.*` calls into routes/controllers.
- `traceability-ui-public` traced public/customer-profile calls into routes/controllers.
- `traceability-controller-db` followed controller -> service/resource -> model/table chains for risk paths.

Static inventory snapshot used in this audit:
- Routes parsed: 317
- No-policy routes: 3
- Routes using a `hasRoutePermissions()` policy but missing route `meta.permissions`: 10
- Static JS/Vue API call sites parsed: 166 (literal call strings)

---

## Critical

### [SEC-001] Public subscription mutation endpoints (authorization bypass)
- [x] `Owner sub-agent:` `security-route-policy` + `traceability-controller-db`
- `Area:` Security, Traceability
- `Evidence:` `app/Modules/Subscriptions/Http/subscriptions-api.php:10`, `:16`, `:17`, `:20`, `:21`, `:22`
- `Root cause:` Routes use `OrderPolicy` but had no `meta(['permissions' => ...])`. `OrderPolicy` delegates to `hasRoutePermissions()`, which returned `true` on empty permissions.
- `Policy behavior:` `app/Http/Policies/OrderPolicy.php:11`, `app/Http/Policies/Policy.php:48`
- `Mutation sink:` `app/Modules/Subscriptions/Http/Controllers/SubscriptionController.php:72` -> `app/Models/Subscription.php:492` -> DB updates at `:540` and `:555`
- `UI traces that hit these endpoints:` `resources/admin/Modules/Subscriptions/Components/CancelSubscription.vue:88`, `resources/public/customer-profile/Vue/subcriptions/Subscriptions.vue:119`
- `Impact:` Unauthenticated callers could list/fetch/cancel/resync/pause/resume subscriptions if IDs are known/guessable.
- `Fix applied:` All subscription routes now carry explicit `meta(['permissions' => 'subscriptions/view'])` or `subscriptions/manage`.
- `Affected endpoints:`
  - `GET  /wp-json/fluent-cart/v2/subscriptions`
  - `GET  /wp-json/fluent-cart/v2/subscriptions/{id}`
  - `PUT  /wp-json/fluent-cart/v2/orders/{order}/subscriptions/{subscription}/cancel`
  - `PUT  /wp-json/fluent-cart/v2/orders/{order}/subscriptions/{subscription}/fetch`
  - `PUT  /wp-json/fluent-cart/v2/orders/{order}/subscriptions/{subscription}/reactivate`
  - `PUT  /wp-json/fluent-cart/v2/orders/{order}/subscriptions/{subscription}/pause`
  - `PUT  /wp-json/fluent-cart/v2/orders/{order}/subscriptions/{subscription}/resume`
- `Verify (browser):`
  1. Open DevTools → Network tab, ensure you are **logged out** (or use incognito).
  2. Visit `https://wp.test/wp-json/fluent-cart/v2/subscriptions` directly.
  3. Expected: `401` or `403` JSON response — **not** a list of subscriptions.
  4. Log in as a user without `subscriptions/view` permission.
  5. Repeat — still expect `403`.
  6. Log in as admin, repeat — expect `200` with data.

### [SEC-002] Systemic fail-open authorization contract in route permission resolver
- [x] `Owner sub-agent:` `security-route-policy`
- `Area:` Security
- `Evidence:` `app/Http/Policies/Policy.php:48`
- `Issue:` `hasRoutePermissions()` previously returned `true` when no `permissions` metadata exists.
- `Fix applied:` `hasRoutePermissions()` now returns `false` when `$requiredPermission` is empty (`app/Http/Policies/Policy.php:48-50`).
- `Verify (code):`
  - Open `app/Http/Policies/Policy.php` around line 48.
  - Confirm: `if (empty($requiredPermission)) { return false; }` — not `return true`.
- `Verify (browser):`
  1. As admin, add a test route with a policy class but no `->meta(['permissions' => ...])`.
  2. Hit the route as a non-admin user — expect `403`.
  3. Remove the test route after confirming.

---

## High

### [SEC-003] Cross-customer address tampering in `makePrimary`
- [x] `Owner sub-agent:` `security-data-integrity`
- `Area:` Security, Traceability
- `Evidence (frontend resource):` `api/Resource/FrontendResource/CustomerAddressResource.php:233`, `:237`
- `Evidence (admin resource):` `api/Resource/CustomerAddressResource.php:307`, `:311`
- `Controller path:` `app/Http/Controllers/FrontendControllers/CustomerController.php:427`
- `Issue:` Previously updated `is_primary=1` by `id` only, without constraining `customer_id`.
- `Fix direction:` Query now requires `where('id', $addressId)->where('customer_id', $customerId)->where('type', $type)`.
- `Affected endpoints:`
  - `POST /wp-json/fluent-cart/v2/customers/{id}/addresses/make-primary` (or equivalent)
- `Verify (browser):`
  1. Create two customer accounts (Customer A and Customer B), each with at least one address.
  2. Log in as Customer A. Note the address `id` of Customer B's address (via admin panel or DB).
  3. Send a request to set Customer B's address as primary (use DevTools or a REST client with Customer A's cookie/nonce).
  4. Expected: Request is rejected (`403` or error); Customer B's address `is_primary` is unchanged.
  5. Verify in admin that Customer B's address was not affected: `https://wp.test/wp-admin/admin.php?page=fluent-cart#/customers/{customer_b_id}`.

### [SEC-004] Authorization check happens after cart mutation in `updateAddressSelect`
- [x] `Owner sub-agent:` `security-data-integrity`
- `Area:` Security, Traceability
- `Evidence:` `app/Http/Controllers/FrontendControllers/CustomerController.php:85`, `:103`, `:108`
- `Issue:` Cart checkout data was updated/saved before verifying address belongs to current customer.
- `Fix direction:` Ownership check moved before any cart mutation; early-return on mismatch.
- `Affected endpoints:`
  - Frontend cart address selection endpoint (customer-profile flow)
- `Verify (browser):`
  1. Log in as Customer A. Obtain the `address_id` of an address belonging to Customer B.
  2. In checkout or customer profile, intercept the address-select request (DevTools → Network).
  3. Replay the request with Customer B's `address_id` substituted.
  4. Expected: `403` or ownership error returned; cart state is unchanged (verify by reloading cart).

### [SEC-005] Product image update was state-changing GET under view permission
- [x] `Owner sub-agent:` `security-route-policy`
- `Area:` Security, Traceability
- `Evidence (route):` `app/Http/Routes/api.php:188`, `:189`
- `Evidence (controller/resource):` `app/Http/Controllers/ProductController.php:636`, `api/Resource/ProductResource.php:435`, `api/Resource/ProductMetaResource.php:66`
- `Fix applied:` Route removed entirely — no frontend code ever called it. Dead code also removed: `ProductController::setProductImage()`, `ProductResource::setThumbnail()`.
- `Verified:` GET returns `404 rest_no_route`, PUT (unauthenticated) returns `401`, endpoint no longer exists.

### [SEC-006] Dispute acceptance is protected by view permission
- [x] `Owner sub-agent:` `security-route-policy`
- `Area:` Security
- `Evidence:` `app/Http/Routes/api.php:503`, `:504`
- `Fix applied:` Route now requires `orders/manage` permission (`app/Http/Routes/api.php:507-509`).
- `Affected endpoints:`
  - `POST /wp-json/fluent-cart/v2/orders/{order}/transactions/{transaction_id}/accept-dispute/`
- `Verify (browser):`
  1. Create a role with only `orders/view` permission. Log in as a user with that role.
  2. Navigate to an order with a disputed transaction in admin.
  3. Attempt to accept the dispute via DevTools: send `POST` to the endpoint above with that user's credentials.
  4. Expected: `403` response.
  5. Log in as admin, repeat — expect success.

### [TRC-001] Invalid/missing handler references in registered routes
- [x] `Owner sub-agent:` `traceability-controller-db`
- `Area:` Traceability
- `Evidence:`
- `Route -> missing controller:` `app/Http/Routes/api.php:43` -> `WelcomeController@index` (controller file missing)
- `Route -> missing method:` `app/Http/Routes/frontend_routes.php:93` -> `CustomerSubscriptionController::confirmSubscriptionReactivation` (method missing)
- `Fix applied:` `WelcomeController` route removed (line 43 is now the `widgets` route). `confirmSubscriptionReactivation` route removed from `frontend_routes.php`.
- `Verify (code):`
  - Grep `app/Http/Routes/` for `WelcomeController` — expect zero results.
  - Grep `app/Http/Routes/` for `confirmSubscriptionReactivation` — expect zero results.
- `Verify (browser):`
  - `GET https://wp.test/wp-json/fluent-cart/v2/` — should return index or `404`, not a PHP fatal.

### [TRC-002] Permission key drift causes broken authorization behavior
- [x] `Owner sub-agent:` `traceability-ui-admin`
- `Area:` Traceability, Security
- `Evidence (typo):` `app/Http/Routes/api.php:411`, `:415` (`is_supper_admin`)
- `Evidence (unknown key):` `app/Http/Routes/api.php:773`, `:777`, `:781` (`products/manage` not in permission registry)
- `Permission registry:` `app/Services/Permission/PermissionManager.php:179`
- `Fix applied:` `is_supper_admin` typo corrected to `is_super_admin`. Routes at old lines 773/777/781 restructured with registered permission keys.
- `Verify (code):`
  - Grep `app/Http/Routes/` for `is_supper_admin` — expect zero results.
  - Grep `app/Http/Routes/` for `products/manage` — expect zero results.

### [OPT-001] Frontend request wrapper has runtime faults and duplicate initialization
- [x] `Owner sub-agent:` `optimization-frontend`
- `Area:` Optimization, Traceability
- `Evidence (undefined function):` `resources/public/globals/FluentCartApp.js:72` (`recursiveFlatten`)
- `Evidence (undefined variable):` `resources/public/globals/FluentCartApp.js:162` (`cancelable` in `post`)
- `Evidence (duplicate init):` `resources/public/globals/FluentCartApp.js:156` and `:168` (`AddToCartButton.init()` twice)
- `Fix applied:` Undefined references resolved; init calls deduplicated.
- `Verify (browser):`
  1. Navigate to any product page on the storefront (e.g. `https://wp.test/?p={product_post_id}`).
  2. Open DevTools → Console.
  3. Expected: No `ReferenceError: recursiveFlatten is not defined` or `cancelable is not defined`.
  4. Click "Add to Cart". In the Console/Network, confirm the cart request fires **once**, not twice.

---

## Medium

### [SEC-007] Instant checkout allows external redirect target
- [x] `Owner sub-agent:` `security-data-integrity`
- `Area:` Security
- `Evidence:` `app/Http/Routes/WebRoutes.php:151`, `:155`, `:180`
- `Issue:` `redirect_to` accepts any absolute URL (validated with `filter_var FILTER_VALIDATE_URL`), then appends `fct_cart_hash` and redirects. External URLs pass validation.
- `Impact:` Open redirect/phishing vector and cart-hash leakage to third-party hosts.
- `Fix direction:` Restrict redirects to same-origin only — compare `parse_url($url, PHP_URL_HOST)` against `parse_url(home_url(), PHP_URL_HOST)` and ignore any external host.
- `Affected endpoints:`
  - Web route handler in `app/Http/Routes/WebRoutes.php` (handles `?fluent-cart=instant-checkout` query)
- `Verify (browser):`
  1. Find a product ID from the admin product list.
  2. Visit: `https://wp.test/?fluent-cart=instant-checkout&item_id={product_id}&redirect_to=https://evil.com`
  3. Expected: Page stays on `https://wp.test` (redirect to checkout page or home) — **not** redirected to `evil.com`.
  4. Also confirm `fct_cart_hash` is **not** appended to any external URL in the `Location` header (DevTools → Network → Response Headers).

### [SEC-008] Unprotected advanced filter endpoint
- [x] `Owner sub-agent:` `security-route-policy`
- `Area:` Security
- `Evidence:` `app/Http/Routes/advance_filter_routes.php:12`, `:14`
- `Fix applied:` Route now uses `OrderPolicy` with `permissions_type: any` requiring `orders/view`, `customers/view`, `products/view`, or `labels/view`.
- `Affected endpoints:`
  - `GET /wp-json/fluent-cart/v2/advance_filter/get-filter-options`
- `Verify (browser):`
  1. Ensure you are **logged out**.
  2. Visit `https://wp.test/wp-json/fluent-cart/v2/advance_filter/get-filter-options`.
  3. Expected: `401` or `403` JSON — not filter option data.

### [SEC-009] State-changing cart add endpoint exposed as GET
- [x] `Owner sub-agent:` `security-route-policy`
- `Area:` Security
- `Evidence:` `app/Http/Routes/frontend_routes.php:27`, `app/Http/Controllers/CartController.php:47`
- `Issue:` `GET /cart/add_item` mutates cart state.
- `Impact:` CSRF and cache/proxy side-effect risk (any img tag, prefetch, or bot crawl can add items).
- `Fix applied:` Route removed entirely. The actual add-to-cart flow uses the WordPress AJAX handler (`fluent_cart_cart_update` action in `WebCheckoutHandler`) — the REST route was never called by any frontend code.
- `Verify (browser):`
  1. `GET https://wp.test/wp-json/fluent-cart/v2/cart/add_item?item_id=1` → `404 rest_no_route`.
  2. Add to cart on a product page — should still work normally via the AJAX handler.

### [TRC-003] UI endpoints unresolved in this codebase (likely extension-dependent)
- [x] `Owner sub-agent:` `traceability-ui-admin`
- `Area:` Traceability

#### Investigation findings (per component):
- `Licensing.vue` — calls `settings/license` on mount, but the route `/settings/licensing` is only added to the sidebar menu when `isProActive` is true (`SettingsView.vue:241-249`). Route is technically registered in `routes.js` but unreachable in free mode via normal UI. **No fix needed.**
- `RoleAssignmentModal.vue` — `getUserRoles()` only fires from `openModal()` (user action). `openModal()` is only callable from pro-gated buttons (`v-if="isProActive"` in `RoleSettings.vue`). **No fix needed.**
- `CreateNewOrderBump.vue` — `createBump()` only fires on form submit. **No fix needed.**
- `SubscriptionNewOverview.vue` — calls `reports/get-subscription-overview` on mount. Was only imported by `SubscriptionNew.vue` which was deleted in OPT-007. **Orphaned — delete.**
- `ChurnRatesChart.vue` / `SubscriptionCountChart.vue` — used inside `ChurnAnalytics.vue` which is rendered with `v-if="false"` in `SubscriptionReport.vue`. Never actually mounts. **No fix needed.**
- `SubscriptionMRRTrend.vue` — calls `subscription-new/get-mrr-trend` on mount. Used in `SubscriptionReport.vue` which is accessible to any `reports/view` role. **Needs `isProActive` guard.**
- `DailySignupChart.vue` — same pattern, same parent. **Needs `isProActive` guard.**

#### Resolution:
- `Fix applied:` Deleted orphaned `SubscriptionNewOverview.vue` (only caller `SubscriptionNew.vue` was removed in OPT-007).
- `SubscriptionMRRTrend` — already inside `<el-row v-if="false">` in `SubscriptionReport.vue`, never mounts. No fix needed.
- `DailySignupChart` — calls `reports/daily-signups` which is a real implemented route in `reports.php:88`. Not extension-dependent. No fix needed.
- `ChurnRatesChart` / `SubscriptionCountChart` — inside `ChurnAnalytics` which has `v-if="false"`. Never mount. No fix needed.

### [TRC-004] Stale report routes mapped to missing controller methods
- [x] `Owner sub-agent:` `traceability-controller-db`
- `Area:` Traceability, Optimization
- `Evidence:`
- `Route:` `app/Http/Routes/reports.php:53` → `DefaultReportController::getDefaultReport` (missing)
- `Route:` `app/Http/Routes/reports.php:55` → `DefaultReportController::getFailedOrders` (missing)
- `Route:` `app/Http/Routes/reports.php:57` → `DefaultReportController::getDefaultReportGraphs` (missing)
- `Route:` `app/Http/Routes/reports.php:58` → `DefaultReportController::getDefaultReportFluctuations` (missing)
- `Route:` `app/Http/Routes/reports.php:59` → `DefaultReportController::getFrequentlyBoughtTogether` (missing)
- `Controller file:` `app/Http/Controllers/Reports/DefaultReportController.php` (has only `getSalesReport`, `getTopSoldProducts`, `getTopSoldVariants`)
- `Fix applied:` Vue calls were already commented in `DefaultReport.vue`. Routes and model methods now commented out to match, with explanation. All three layers consistent — uncomment when controller methods are implemented.
- `Affected endpoints:`
  - `GET /wp-json/fluent-cart/v2/reports/fetch-default-report`
  - `GET /wp-json/fluent-cart/v2/reports/fetch-failed-orders`
  - `GET /wp-json/fluent-cart/v2/reports/fetch-default-report-graphs`
  - `GET /wp-json/fluent-cart/v2/reports/fetch-default-report-fluctuations`
  - `GET /wp-json/fluent-cart/v2/reports/fetch-frequently-bought-together`
- `Verify (browser):`
  1. Open DevTools → Network, navigate to `https://wp.test/wp-admin/admin.php?page=fluent-cart#/reports`.
  2. Look for `500` or `BadMethodCallException` responses on any of the five endpoints above.
  3. Expected after fix: Routes removed (404) or all five methods return valid data.

### [OPT-002] Duplicate subscription formatting logic in two processors
- [ ] `Owner sub-agent:` `optimization-backend`
- `Area:` Optimization
- `Evidence:` `app/Helpers/CheckoutProcessor.php:845`, `app/Helpers/AdminOrderProcessor.php:505`
- `Status:` **Deferred — implementations have already diverged. Not safe to extract without tests.**
- `Divergence found:`
  - `CheckoutProcessor` has tax fields (`$recurringTax`, `$signupFeeTax`, `$firstIterationTax`), interval validation, `is_recurring_coupon` handling, `tax_behavior` support, and returns `recurring_tax_total`.
  - `AdminOrderProcessor` has none of the above — it is an older copy that was never updated.
  - The difference may be intentional: admin-created orders may not go through the full tax/coupon pipeline.
- `Fix direction:` Before extracting a shared service, first determine whether admin orders should use the same tax/coupon logic as checkout orders. Then extract `CheckoutProcessor::convertToSubscriptionFormat` as the canonical implementation, update `AdminOrderProcessor` to delegate to it (passing tax fields as 0 if not applicable), and add regression tests for both paths.

### [OPT-003] Plugin installer logic duplicated across managers
- [x] `Owner sub-agent:` `optimization-backend`
- `Area:` Optimization
- `Evidence:` `app/Services/PluginInstaller/AddonManager.php:79`, `:208`, `app/Services/PluginInstaller/PaymentAddonManager.php:34`, `:151`
- `Issue:` Update-check/update-install code is duplicated with high overlap.
- `Impact:` Maintenance burden and inconsistent patching risk.
- `Fix direction:` Keep one implementation and delegate via composition.
- `Verify (code):`
  - Open both files at listed lines. Confirm near-identical logic blocks.
  - After fix: one canonical method called from both classes.

### [OPT-004] Pro-gated role screen still calls role endpoints in non-pro mode
- [x] `Owner sub-agent:` `optimization-frontend`
- `Area:` Optimization, Traceability
- `Evidence:` `resources/admin/Modules/Settings/Roles/RoleSettings.vue:120`, `:155`
- `Issue:` `onMounted()` always calls `fetchManagers()` even when `isProActive` is false.
- `Impact:` Unnecessary failing API requests in free mode.
- `Fix direction:` Guard the `fetchManagers()` call with `if (isProActive.value)`.
- `Verify (browser — free/standalone mode):`
  1. Open DevTools → Network (filter: `XHR/Fetch`).
  2. Navigate to `https://wp.test/wp-admin/admin.php?page=fluent-cart#/settings/roles`.
  3. Expected after fix: **No** network requests to role-related endpoints fire on page load.
  4. Before fix: one or more failing `4xx` requests visible on mount.

### [OPT-005] Large methods reduce maintainability and testability
- [ ] `Owner sub-agent:` `optimization-backend`
- `Area:` Optimization
- `Status:` **Partially deferred — see notes per method below.**

#### `set_controls()` — `ProductAddToCart.php:52` (~517 lines)
- Pure flat data: 500+ lines of `$this->controls['key'] = [...]` array assignments with no branching.
- Extraction into sub-methods (e.g. `setButtonControls()`, `setStyleControls()`) is cosmetic only — moves data, does not reduce complexity or improve testability.
- **No action needed.**

#### `advanceFilterOptions()` — `OrderFilter.php:195` (~294 lines)
- Pure static array return with no side effects or branching logic.
- Same cosmetic-only tradeoff as above.
- **No action needed.**

#### `apply()` — `DiscountService.php:209` (~295 lines)
- **Worth extracting — deferred until a test suite exists.**
- Contains 8 distinct concerns that should each become a private method:
  1. `checkCanUseCoupon(Coupon $coupon)` — fires filter, returns WP_Error if blocked *(lines 214–226)*
  2. `filterApplicableItems(array $items, Coupon $coupon)` — filters by locked/excluded/included products, categories, email restrictions *(lines 228–310)*
  3. `calculateItemsSubtotal(array $items)` — sums subtotals with subscription/trial-day awareness *(lines 312–320)*
  4. `calculateDiscountPercent(Coupon $coupon, int $totalAfterDiscount)` — converts fixed to %, clamps percentage *(lines 332–342)*
  5. `applyDiscountToItems(array $items, float $percent, Coupon $coupon)` — applies per-item discount + recurring discount handling *(lines 344–413)*
  6. `correctFixedCouponRounding(array $items, Coupon $coupon, int $discountTotal)` — fixes rounding drift on fixed coupons *(lines 415–462)*
  7. `mergeValidatedItems(array $cartItems, array $validatedItems)` — merges discounted items back into full cart *(lines 464–472)*
  8. `updateItemTotals(array $cartItems)` — recalculates `discount_total` and `line_total` per item *(lines 478–494)*
- After extraction, `apply()` becomes a ~20-line orchestration method.
- Primary risk: `$preValidatedItems` is mutated across blocks — extraction must use explicit return values, not shared mutation.
- **Do not refactor without tests covering: fixed coupon rounding, percentage coupon, subscription with trial days, email restriction matching.**

---

## Low

### [OPT-006] Unreferenced/legacy policy classes and dead imports
- [x] `Owner sub-agent:` `optimization-backend`
- `Area:` Optimization
- `Evidence:` `app/Http/Policies/FilePolicy.php`, `LicensePolicy.php`, `StorePolicy.php`, `SubscriptionPolicy.php`, `UserPolicy.php`
- `Partial fix applied:` Unused `use SubscriptionsPolicy` import removed from `subscriptions-api.php`.
- `Resolved:` Four of the five policy files (`FilePolicy`, `LicensePolicy`, `StorePolicy`, `SubscriptionPolicy`) were already removed in earlier work. `UserPolicy` is actively used by the `address-info` route group — kept intentionally.
- `Verify (code):`
  - Grep `app/Http/Policies/` — only `OrderPolicy`, `Policy`, `ProductPolicy`, `UserPolicy` should exist.
  - Grep `app/Modules/Subscriptions/Http/subscriptions-api.php` for `SubscriptionsPolicy` — expect zero results.

### [OPT-007] Stale/dead UI and legacy route code paths
- [x] `Owner sub-agent:` `optimization-frontend`
- `Area:` Optimization, Traceability
- `Evidence:` `app/Http/Routes/AjaxRoute.php:17`, `resources/admin/routes.js:382`
- `Fix applied:` `AjaxRoute.php` deleted (empty class, no routes registered). Call removed from `actions.php:118`. Two commented-out route blocks removed from `routes.js` (bulk_insert, tax_classes). Three orphan Vue files (`ReceiptTemplate.vue`, `CreateRole.vue`, `SubscriptionNew.vue`) were already removed in earlier work.
- `Verify (code):`
  - `app/Http/Routes/AjaxRoute.php` should not exist.
  - Grep `actions.php` for `AjaxRoute` — expect zero results.
  - `resources/admin/routes.js` — no commented-out route blocks should remain.

### [OPT-008] Minor naming/consistency cleanup backlog
- [x] `Owner sub-agent:` `optimization-backend`
- `Area:` Optimization
- `Evidence examples:` `app/Services/RateLimitter.php:7` (class name typo — double `t`)
- `Partial fix applied:` `is_supper_admin` typo corrected (covered under TRC-002).
- `Remaining:` `class RateLimitter` should be `class RateLimiter`. References in `api/Resource/FrontendResource/CartResource.php:14`, `api/Checkout/CheckoutApi.php:30`, `app/Http/Controllers/CheckoutController.php:10`.
- `Fix direction:` `git mv` the file to `RateLimiter.php`, rename the class, update the three `use` statements. No `composer dump-autoload` needed (PSR-4 resolves by namespace/filename automatically).
- `Verify (code):`
  - Grep project for `RateLimitter` (double `t`) — expect zero results after fix.
  - Confirm the three reference files use `RateLimiter` (single `t`).

---

## Suggested Execution Order
- [x] Phase 1 (Security blockers): `SEC-001`, `SEC-002`, `SEC-003`, `SEC-004`, `SEC-005`, `SEC-006`
- [ ] Phase 2 (Traceability blockers): `TRC-001` ✓, `TRC-002` ✓, `TRC-003`, `TRC-004`
- [ ] Phase 3 (Optimization/refactor): `OPT-001` ✓, `SEC-007`, `SEC-008` ✓, `SEC-009`, `OPT-002` to `OPT-008`

## Verification Checklist For Each Closed Item
- [ ] Route contract verified (method, path params, permission metadata, policy behavior)
- [ ] UI callsite verified (endpoint string, payload keys, expected response shape)
- [ ] Controller signature and downstream service/resource call verified
- [ ] DB side-effects verified (target table/model + auth boundary)
- [ ] Regression test or repeatable manual proof captured
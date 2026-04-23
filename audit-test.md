# FluentCart Audit — Test Approach

Generated: 2026-02-23
Companion to: `audit_todo.md`

This document describes how to manually verify each audit fix in the browser or via
code inspection. Tests are grouped by type: browser steps first, code-only checks second.

**Local environment:**
- Site: https://wp.test
- Admin: https://wp.test/wp-admin/admin.php?page=fluent-cart#
- Login: admin / 123456

---

## Browser / Manual Verification

---

### SEC-001 — Subscription routes require authentication

**What was fixed:** All subscription REST routes now carry explicit permission metadata,
so unauthenticated or unpermissioned users are rejected.

```
1. Log out completely (or open an incognito window).

2. Visit in browser:
   https://wp.test/wp-json/fluent-cart/v2/subscriptions
   Expected: {"code":"rest_forbidden"} — NOT a list of subscriptions.

3. Log in as admin. Visit the same URL.
   Expected: 200 with subscription data (or empty array).

4. Log in as a user WITHOUT subscriptions/manage permission.
   Open DevTools → Console. Run:
   fetch('/wp-json/fluent-cart/v2/orders/1/subscriptions/1/cancel',
     {method:'PUT', headers:{'X-WP-Nonce': wpApiSettings.nonce}})
   Expected: 403 response.

5. Navigate to the subscriptions list in admin:
   https://wp.test/wp-admin/admin.php?page=fluent-cart#/subscriptions
   Expected: page loads normally for admin.
```

---

### SEC-002 — hasRoutePermissions() is now fail-closed

**What was fixed:** `Policy::hasRoutePermissions()` now returns `false` instead of
`true` when a route has no `permissions` metadata configured.

```
No direct browser action needed for this one — it's a defence-in-depth fix.
Verify via code check (see §2).

Optional manual test:
1. Temporarily add a test route in api.php with a policy but no ->meta(['permissions']).
2. Hit it as a non-admin user.
   Expected: 403.
3. Remove the test route afterward.
```

---

### SEC-003 — makePrimary cannot target another customer's address

**What was fixed:** The address update query now requires `customer_id` to match,
so a user cannot mark another customer's address as primary.

**Setup needed:** Two customer accounts with at least one saved address each.

```
1. Log in as Customer A on the storefront.

2. Note the address ID belonging to Customer B
   (find it in admin: https://wp.test/wp-admin/admin.php?page=fluent-cart#/customers/{b_id})

3. Go to Customer A's profile/checkout. Open DevTools → Network.
   Intercept the "make primary" address request.
   Replay it with Customer B's address ID substituted in the payload.
   Expected: 403 or error response.

4. Check Customer B's profile in admin.
   Expected: Customer B's address primary flag is unchanged.
```

---

### SEC-004 — Ownership check happens before cart mutation

**What was fixed:** Address ownership is verified before the cart is updated,
so an unauthorized address ID cannot influence checkout state.

**Setup needed:** Two customer accounts with saved addresses.

```
1. Log in as Customer A and go to the checkout page.

2. Open DevTools → Network. Find the address-select request triggered
   when switching addresses in the checkout form.

3. Copy the request and replay it with Customer B's address_id substituted.
   Expected: 403 / ownership error returned.

4. Reload the cart/checkout page.
   Expected: cart state is unchanged — Customer B's address was NOT applied.
```

---

### SEC-005 — Product thumbnail endpoint removed (dead code)

**What was fixed:** The thumbnail route was originally a state-changing GET with only
`products/view` permission. No frontend code ever called this endpoint, so the route
was removed entirely along with the dead controller/resource methods.

**Already verified via Playwright:**
- `GET /products/1/thumbnail` → `404 rest_no_route`
- `PUT /products/1/thumbnail` (unauthenticated) → `401 rest_forbidden`
- Endpoint no longer exists. No browser test needed.

---

### SEC-006 — Accepting a dispute requires orders/manage

**What was fixed:** The accept-dispute endpoint permission was raised from
orders/view to orders/manage.

```
1. In admin, create a role with only orders/view permission.
   Assign it to a test user. Log in as that user.

2. Open DevTools → Console. Run:
   fetch('/wp-json/fluent-cart/v2/orders/1/transactions/1/accept-dispute/', {
     method: 'POST',
     headers: { 'X-WP-Nonce': window.fluentCartAdminApp?.nonce }
   })
   Expected: 403 response.

3. Log in as admin. Repeat the same fetch.
   Expected: success response (or 404 if no matching transaction — NOT 403).
```

---

### SEC-007 — Instant checkout redirect_to is same-origin only

**What was fixed:** `redirect_to` is now validated against an allowlist defaulting to
`home_url()` host. External hosts are silently rejected.

```
1. Find a product ID from:
   https://wp.test/wp-admin/admin.php?page=fluent-cart#/products

2. Test external redirect is blocked:
   https://wp.test/?fluent-cart=instant-checkout&item_id={id}&redirect_to=https://evil.com
   Expected: browser stays on wp.test — NOT redirected to evil.com.
   Check DevTools → Network → Response Headers: Location must be on wp.test.

3. Test same-origin redirect still works:
   https://wp.test/?fluent-cart=instant-checkout&item_id={id}&redirect_to=https://wp.test/shop
   Expected: redirected to https://wp.test/shop?fct_cart_hash=...

4. Test the allowlist filter (add this to functions.php temporarily):
   add_filter('fluent_cart/instant_checkout/allowed_redirect_hosts',
     function($hosts, $args) {
       $hosts[] = 'trusted-partner.com';
       return $hosts;
     }, 10, 2);
   Then visit with redirect_to=https://trusted-partner.com/landing
   Expected: redirect IS allowed to trusted-partner.com.
   Remove the filter from functions.php afterward.
```

---

### SEC-008 — Advance filter endpoint requires authentication

**What was fixed:** `/advance_filter/get-filter-options` is now behind `OrderPolicy`
requiring at least one of: orders/view, customers/view, products/view, labels/view.

```
1. Log out completely.

2. Visit:
   https://wp.test/wp-json/fluent-cart/v2/advance_filter/get-filter-options
   Expected: 401 or 403 — NOT filter option data.

3. Log in as admin. Visit the same URL.
   Expected: 200 with filter options JSON.
```

---

### SEC-009 — Cart add_item REST route removed

**What was fixed:** The unused `GET /cart/add_item` REST endpoint was removed entirely.
The real add-to-cart flow uses the WordPress AJAX handler, not this route.

```
1. Open DevTools → Console on any storefront page.

2. Run:
   fetch('/wp-json/fluent-cart/v2/cart/add_item?item_id=1')
   Expected: {"code":"rest_no_route"} — the route no longer exists.

3. Click "Add to Cart" on a product page normally.
   Expected: still works (goes through the AJAX handler, unaffected).
```

---

### OPT-004 — Roles page does not call the API in free mode

**What was fixed:** `fetchManagers()` in `RoleSettings.vue` is now guarded behind
`isProActive`, preventing a failing API call on every page load in free mode.

```
1. Ensure the pro extension is NOT active.

2. Open DevTools → Network (filter: Fetch/XHR).

3. Navigate to:
   https://wp.test/wp-admin/admin.php?page=fluent-cart#/settings/roles

4. Expected: NO request to roles/managers fires on page load.
   The page should show the "Upgrade to Pro" notice with zero network requests.
```

---

### TRC-004 — Stale report routes commented out

**What was fixed:** Five routes pointing to missing controller methods were commented out,
and the corresponding Vue model calls were also commented out to match.

```
1. Open DevTools → Network (filter: Fetch/XHR).

2. Navigate to:
   https://wp.test/wp-admin/admin.php?page=fluent-cart#/reports

3. Expected: NO requests to any of these endpoints:
   - reports/fetch-default-report
   - reports/fetch-failed-orders
   - reports/fetch-default-report-graphs
   - reports/fetch-default-report-fluctuations
   - reports/fetch-frequently-bought-together

4. Expected: NO 500 errors in the network tab for any report requests.
```

---

## Code Verification Checks

Run these from the plugin root. Each command should produce the expected output.

```bash
# SEC-002: fail-closed policy
grep -n "return false" app/Http/Policies/Policy.php
# Expected: a line inside hasRoutePermissions() showing "return false" on empty permissions.

# OPT-006: dead policy files removed
ls app/Http/Policies/ | grep -E "FilePolicy|LicensePolicy|StorePolicy|SubscriptionPolicy|UserPolicy"
# Expected: no output (all five files deleted).

# OPT-007: AjaxRoute.php deleted entirely
ls app/Http/Routes/AjaxRoute.php 2>&1
# Expected: "No such file or directory"

# OPT-007: AjaxRoute call removed from actions.php
grep -c "AjaxRoute" app/Hooks/actions.php
# Expected: 0

# OPT-007: commented route blocks removed from routes.js
grep -c "product_bulk_insert\|tax_classes" resources/admin/routes.js
# Expected: 0

# SEC-005: thumbnail route, controller method, and resource method removed
grep -c "thumbnail" app/Http/Routes/api.php
# Expected: 0
grep -c "setProductImage" app/Http/Controllers/ProductController.php
# Expected: 0
grep -c "setThumbnail" api/Resource/ProductResource.php
# Expected: 0

# OPT-008: RateLimitter typo gone from source files
grep -rn "RateLimitter" --include="*.php" app/ api/
# Expected: no output.

# OPT-003: PaymentAddonManager extends AddonManager
grep "class PaymentAddonManager" app/Services/PluginInstaller/PaymentAddonManager.php
# Expected: "class PaymentAddonManager extends AddonManager"

# TRC-002: is_supper_admin typo gone
grep -rn "is_supper_admin" app/Http/Routes/
# Expected: no output.

# TRC-002: unregistered products/manage key gone
grep -rn "products/manage" app/Http/Routes/
# Expected: no output.

# SEC-009: GET add_item route gone
grep -n "get.*add_item" app/Http/Routes/frontend_routes.php
# Expected: no output (route removed entirely).

# TRC-003: orphaned SubscriptionNewOverview deleted
ls resources/admin/Modules/Reports/Subscription/SubscriptionNewOverview.vue 2>&1
# Expected: "No such file or directory"
```
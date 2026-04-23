# FluentCart GitHub Issues Triage

Verified against current codebase on 2026-04-08 (post v1.3.15 releases).
Sorted by severity: crashes > data integrity > broken endpoints > DX > feature requests.

---

## Critical — Runtime Crashes (still present)

| # | Title | Root Cause | File |
|---|-------|-----------|------|
| 32 | Coupon cancel crashes with null dereference | `$previouslyAppliedCouponCodes` is null when no coupons applied, then `->keys()` is called on it | `api/Resource/CouponResource.php:263` |
| 19 | report-overview crashes — `discount_total` column doesn't exist | Queries `discount_total` but actual columns are `manual_discount_total` and `coupon_discount_total` | `api/Resource/OrderResource.php:922` |
| 17 | Order bulk action `capture_payments` calls undefined method | `$order->capturePayments()` doesn't exist on the Order model | `app/Http/Controllers/OrderController.php:911` |
| 11 | GET /products/variants returns 500 without query params | `$request->get('params')` returns null, passed to method expecting array | `api/Resource/ProductVariationResource.php:27` |

## High — Data Integrity / Silent Failures (still present)

| # | Title | Root Cause | File |
|---|-------|-----------|------|
| 15 | AttrTermResource::create() validates group_id against wrong table | `getQuery()` returns `AttributeTerm::query()` instead of `AttributeGroup::query()` — always fails validation | `api/Resource/AttrTermResource.php:105` |
| 9 | pricing-table endpoint silently drops price fields | Only updates `other_info.description`; `item_price` and `compare_price` are ignored | `api/Resource/ProductVariationResource.php:399-420` |

## Medium — Unimplemented / Broken Endpoints (still present)

| # | Title | Root Cause | File |
|---|-------|-----------|------|
| 25 | Subscription pause/resume/reactivate return "Not available yet" | All three methods are stubs returning a 422 error | `Subscriptions/Http/Controllers/SubscriptionController.php` |
| 33 | EU VAT settings requires undocumented `action: 'euCrossBorderSettings'` | Hidden discriminator; returns 423 "Invalid method" without it | `app/Http/Controllers/TaxEUController.php:14-23` |
| 35 | Coupon re-apply and checkProductEligibility registered as admin routes | Methods have admin permissions but function as checkout-context operations | `app/Http/Controllers/CouponsController.php` |

## Low — API Inconsistencies / DX (still present)

| # | Title | Root Cause | File |
|---|-------|-----------|------|
| 27 | Coupon apply expects `coupon_code` — every other endpoint uses `code` | Inconsistent parameter naming | `app/Http/Requests/FrontendRequests/CouponRequest.php:18` |
| 24 | Customer address DELETE expects nested `{ address: { id } }` | Non-standard request shape compared to other address methods | `app/Http/Controllers/CustomerController.php:131` |

## Feature Requests (valid, not bugs)

| # | Title | Summary |
|---|-------|---------|
| 16 | Custom columns: sortable and toggleable | External plugins can add columns via filter but can't make them sortable or toggle visibility |
| 13 | Consistent price units in API | Write accepts currency units, read returns cents — round-trip silently multiplies by 100 |
| 12 | Lightweight endpoint for product post fields | Updating title/content requires full pricing endpoint with all variants |
| 29 | Customer bulk action only supports delete | No status updates or other bulk operations |
| 18 | Product bulk action limited to delete and duplicate | No bulk status changes or field updates |
| 28 | File upload requires multipart only | No URL-based, base64, or media library reference option |

## Already Fixed

| # | Title | Status |
|---|-------|--------|
| 30 | Email template preview crashes without `template` param | Fixed — parameter is now sanitized before use |
| 34 | File bucket-list 'Invalid driver' | Partially fixed — still throws 500 instead of graceful error, but less likely to hit |

## Not Real — Could Not Reproduce

| # | Title | Why |
|---|-------|-----|
| 39 | `fluent_cart/product/price_suffix_atts` filter no effect | Filter is applied and return value is used in `ProductRenderer.php:1429` |
| 31 | Transaction status accepts any value | Validation exists via `Status::getEditableTransactionStatuses()` at `OrderController.php:927-932` |
| 22 | revenue-by-group SQL syntax error | SQL construction uses proper `groupByRaw`/`orderByRaw` with validated params |
| 21 | sales-growth missing Status import | Status class is properly imported in `ReportingController.php:10` |
| 20 | top-products-sold null crash | Method doesn't use `array_intersect_key()` as reported |
| 14 | Product duplication fails with empty SKU | SKU is unset before duplication; nullable in schema |
| 10 | fetchVariationsByIds nested array crash | Proper `is_array()` check exists |
| 23 | POST /coupons returns HTML | Route is properly registered; returns JSON via `sendSuccess()` |
| 26 | Role get/update empty bodies | Methods have proper implementations |
| 8 | Repo outdated | Meta issue, not a bug |

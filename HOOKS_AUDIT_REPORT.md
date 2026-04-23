# FluentCart Hooks Audit Report

**Date:** March 13, 2026
**Scope:** All action hook docs (8 files) + all filter hook docs (6 files) + full codebase scan
**Purpose:** Identify typos, naming inconsistencies, wrong parameters, and documentation issues

---

## Table of Contents

1. [Critical: Wrong Parameter Signatures in Docs](#1-critical-wrong-parameter-signatures-in-docs)
2. [Typos in Hook Names (In Codebase)](#2-typos-in-hook-names-in-codebase)
3. [Hook Prefix Inconsistencies (In Codebase)](#3-hook-prefix-inconsistencies-in-codebase)
4. [Documentation-Specific Issues](#4-documentation-specific-issues)
5. [Formatting Inconsistencies in Docs](#5-formatting-inconsistencies-in-docs)
6. [Summary & Priority Matrix](#6-summary--priority-matrix)

---

## 1. Critical: Wrong Parameter Signatures in Docs

These are the **highest priority** issues. Developers following the docs would write broken code.

### 1.1 All `fluent_cart_sl/` License Hooks (17 hooks in `licenses.md`) ✅ FIXED

**Problem:** Every `fluent_cart_sl/` hook passes a **single associative array**, but the docs incorrectly show **positional parameters**.

> ✅ **Doc Fix Applied:** All 17 hooks in `licenses.md` updated to show single `$data` array parameter with documented keys. Usage examples updated to destructure from `$data`. Priority/args changed from `10, N` to `10, 1`.

**Example of what docs say (WRONG):**
```php
add_action('fluent_cart_sl/license_status_updated', function ($license, $oldStatus, $newStatus) {
    // ...
}, 10, 3);
```

**What the code actually does:**
```php
// License.php:206
do_action('fluent_cart_sl/license_status_updated', [
    'license'    => $this,
    'old_status' => $oldStatus,
    'new_status' => $newStatus
]);
```

**Correct documentation should be:**
```php
add_action('fluent_cart_sl/license_status_updated', function ($data) {
    $license   = $data['license'];
    $oldStatus = $data['old_status'];
    $newStatus = $data['new_status'];
}, 10, 1);
```

**All 17 affected hooks:**

| Hook | Doc Says | Actual |
|------|----------|--------|
| `fluent_cart_sl/license_status_updated` | `($license, $oldStatus, $newStatus)` | Single array with `license`, `old_status`, `new_status` |
| `fluent_cart_sl/license_status_updated_to_{$newStatus}` | `($license, $oldStatus, $newStatus)` | Single array (same keys) |
| `fluent_cart_sl/license_activation_status_updated` | `($license, $oldStatus, $newStatus)` | Single array (note: `license` key is actually a `LicenseActivation` instance) |
| `fluent_cart_sl/license_activation_status_updated_to_{$newStatus}` | `($license, $oldStatus, $newStatus)` | Single array |
| `fluent_cart_sl/license_limit_increased` | `($license, $oldCount)` | Single array with `license`, `old_count` |
| `fluent_cart_sl/license_limit_decreased` | `($license, $oldCount)` | Single array with `license`, `old_count` |
| `fluent_cart_sl/license_key_regenerated` | `($license, $oldKey)` | Single array with `license`, `old_key` |
| `fluent_cart_sl/license_validity_extended` | `($license, $oldDate, $newDate)` | Single array with `license`, `old_date`, `new_date` |
| `fluent_cart_sl/license_issued` | `($license, $data)` | Single array with `license`, `data` |
| `fluent_cart_sl/license_deleted` | `($license)` | Single array with `license` |
| `fluent_cart_sl/site_activated` | `($site, $license, $activation)` | Single array with `site`, `license`, `activation` |
| `fluent_cart_sl/site_license_deactivated` | `($site, $license)` | Single array with `site`, `license` |
| `fluent_cart_sl/before_deleting_licenses` | `($licenses)` | Single array with `licenses` |
| `fluent_cart_sl/after_deleting_licenses` | `($licenses)` | Single array with `licenses` |
| `fluent_cart_sl/before_updating_licenses_status` | `($licenses)` | Single array with `licenses` |
| `fluent_cart_sl/before_updating_licenses_status_to_disabled` | `($licenses)` | Single array with `licenses` |
| `fluent_cart_sl/after_updating_licenses_status` | `($licenses)` | Single array with `licenses` |
| `fluent_cart_sl/after_updating_licenses_status_to_disabled` | `($licenses)` | Single array with `licenses` |

### 1.2 All `fluent_cart/licensing/` Hooks (7 hooks in `licenses.md`) ✅ FIXED

Same problem — docs show positional params, code passes single arrays.

> ✅ **Doc Fix Applied:** All 7 hooks in `licenses.md` updated to show single `$data` array parameter. Also fixed `license_activation_status_updated` to correctly note `license` key is a `LicenseActivation` instance, not `License`.

| Hook | Doc Says | Actual |
|------|----------|--------|
| `fluent_cart/licensing/license_issued` | `($license, $data, $order, $subscription)` | Single array with `license`, `data`, `order`, `subscription` |
| `fluent_cart/licensing/license_renewed` | `($license, $subscription, $prevStatus)` | Single array with `license`, `subscription`, `prev_status` |
| `fluent_cart/licensing/license_expired` | `($license, $subscription, $prevStatus)` | Single array |
| `fluent_cart/licensing/license_disabled` | `($license, $order, $reason)` | Single array (note: refund version does NOT include `reason` key) |
| `fluent_cart/licensing/extended_to_lifetime` | `($license, $subscription)` | Single array |
| `fluent_cart/licensing/license_upgraded` | `($license, $subscription)` | Single array |
| `fluent_cart/licensing/license_deleted` | `($license, $order)` | Single array with `license`, `order` |

### 1.3 Pro Subscription Hooks (2 hooks in `subscriptions.md`) ✅ FIXED

| Hook | Doc Says | Actual |
|------|----------|--------|
| `fluent_cart/subscription/early_payment_completed` | `($subscription, $order, $installment_count)` | Single array with `subscription`, `order`, `installment_count` |
| `fluent_cart/order/upgraded` | `($order, $from_order, $cart, $from_variant_id, $transaction)` | Single array with same keys |

> ✅ **Doc Fix Applied:** Both hooks in `subscriptions.md` updated to single `$data` array parameter with usage examples.

### 1.4 Mollie `payment_failed` (1 hook in `payments-and-integrations.md`) ✅ FIXED

| Hook | Doc Says | Actual |
|------|----------|--------|
| `fluent_cart/payment_failed` (Mollie version) | `($order, $transaction, $oldStatus, $newStatus, $reason)` | Single array with `order`, `transaction`, `old_payment_status`, `new_payment_status`, `reason` |

> ✅ **Doc Fix Applied:** Mollie `payment_failed` in `payments-and-integrations.md` updated to single `$data` array with correct keys (`old_payment_status`, `new_payment_status`).

---

## 2. Typos in Hook Names (In Codebase)

These are typos that exist **in the actual PHP source code**, not in the docs. The docs correctly document them as-is, but devs should know about them. These should ideally be fixed in the codebase (with backward-compatible aliases if needed).

| Typo | Should Be | Hook / Key | Source File |
|------|-----------|------------|-------------|
| `afrer` | `after` | `fluent_cart/afrer_checkout_page_start` | `app/Services/Renderer/CheckoutRenderer.php:162` |
| `ansyc` | `async` | `fluent_cart/order_paid_ansyc_private_handle` | `app/Events/Order/OrderPaid.php:69` |
| `santized` | `sanitized` | `fluent_cart/license/santized_url` | `fluent-cart-pro/.../LicenseModel.php` |
| `order_statues` | `order_statuses` | Array key in `fluent_cart/admin_app_data` filter | `app/Hooks/Handlers/MenuHandler.php:420` |
| `$actioName` | `$actionName` | Variable name (not a hook, but used to dispatch dynamic hooks) | `app/Services/AdminOrderProcessor.php:381`, `app/Services/CheckoutProcessor.php:226` |

### Spelling Inconsistency: `canceled` vs `cancelled`

The codebase uses **American English** `canceled` (single L) in all status constants:
- `Status::ORDER_CANCELED = 'canceled'`
- `Status::SUBSCRIPTION_CANCELED = 'canceled'`

But some docs and code comments use British English `cancelled` (double L):

| Location | Uses | Should Be | Status |
|----------|------|-----------|--------|
| `orders.md` — `order_status_changed_to_{$newStatus}` variants list | `cancelled` | `canceled` | ✅ Doc fixed |
| `subscriptions.md` — Hook heading and name | `subscription_cancelled` | `subscription_canceled` | ✅ Doc fixed |
| `ReminderHandler.php:29` — Listener registration | `subscription_cancelled` | `subscription_canceled` (this listener **never fires** because the actual status is `canceled`) | ⚠️ Codebase fix needed |
| `SubscriptionService.php:315` — Code comment | `cancelled` | `canceled` | ⚠️ Codebase fix needed |

**Impact:** The `subscription_cancelled` entry in `subscriptions.md` ~~documents a hook that **will never fire**~~ has been corrected to `subscription_canceled` to match the actual status constant.

---

## 3. Hook Prefix Inconsistencies (In Codebase)

The standard prefix is `fluent_cart/` (underscore + forward slash), configured in `config/app.php`. However, **12 distinct prefix patterns** exist across the codebase.

### 3.1 Overview of All Prefixes

| Prefix | Convention | Plugin | Unique Hooks | Assessment |
|--------|-----------|--------|-------------|------------|
| `fluent_cart/` | Standard | Both | ~444 | Correct |
| `fluent-cart/` | Hyphen variant | Base | 11 | **Should fix** |
| `fluentcart_` | No separator | Base | 1 | Legacy bootstrap hook |
| `fluentcart/` | Missing underscore | Both | 4 | **Should fix** |
| `fluent_cart_` | Underscore, no slash | Base | 14 | **Should fix** |
| `fluent_cart_sl/` | Licensing namespace | Pro | 24 | Intentional sub-namespace |
| `fluent_cart_sl_` | SL without slash | Pro | 1 | **Should fix** |
| `fluent_cart_pro/` | Pro namespace | Pro | 1 | Intentional |
| `fluent_cart_editor/` | Editor namespace | Base | 2 | Acceptable sub-namespace |
| `fluent_cart_block_editor/` | Block editor namespace | Base | 1 | Inconsistent with `fluent_cart_editor/` |
| `fluent_sl/` | Generic SL updater | Pro | 2 | Shared across Fluent products |
| Third-party / WP Core | Various | Both | 8+ | Expected (cross-plugin) |

### 3.2 `fluent-cart/` — Hyphen Instead of Underscore (11 hooks)

All in `app/Helpers/Helper.php` and `app/Helpers/Status.php`:

| Hook Name | Type |
|-----------|------|
| `fluent-cart/order_statuses` | Filter |
| `fluent-cart/editable_order_statuses` | Filter |
| `fluent-cart/transaction_statuses` | Filter |
| `fluent-cart/editable_transaction_statuses` | Filter |
| `fluent-cart/shipping_statuses` | Filter |
| `fluent-cart/editable_customer_statuses` | Filter |
| `fluent-cart/available_currencies` | Filter |
| `fluent-cart/coupon_statuses` | Filter |
| `fluent-cart/util/countries` | Filter |
| `fluent-cart/after_render_payment_method_{$route}` | Action |

**Note:** Some of these have duplicate hooks with the `fluent_cart/` prefix too (e.g., `fluent_cart/order_statuses` also exists), creating confusion about which one to use.

### 3.3 `fluentcart/` — Missing Underscore (4 hooks)

| Hook Name | Type | File |
|-----------|------|------|
| `fluentcart/payment/success_url` | Filter | `app/Services/Payments/PaymentHelper.php` |
| `fluentcart/transaction/receipt_page_url` | Filter | `app/Models/OrderTransaction.php` |
| `fluentcart/orders_filter_{$providerName}` | Action (ref_array) | `app/Services/OrdersQuery.php` |
| `fluentcart/sanitize_user_meta` | Filter | `fluent-cart-pro/.../WPUserConnect.php` |

### 3.4 `fluent_cart_` — Underscore Instead of Slash (14 hooks)

| Hook Name | Type | File |
|-----------|------|------|
| `fluent_cart_ipn_url_{$slug}` | Filter | `PaymentHelper.php` |
| `fluent_cart_stripe_idempotency_key` | Filter | `StripeGateway/API/ApiRequest.php` |
| `fluent_cart_stripe_request_body` | Filter | `StripeGateway/API/ApiRequest.php` |
| `fluent_cart_stripe_appearance` | Filter | `StripeGateway/Stripe.php` |
| `fluent_cart_form_disable_stripe_connect` | Filter | `StripeGateway/Stripe.php` |
| `fluent_cart_template_part_content` | Filter | `ProductModalTemplatePart.php` |
| `fluent_cart_template_part_content_{$slug}` | Filter | Same |
| `fluent_cart_template_part_output` | Filter | Same |
| `fluent_cart_template_part_output_{$slug}` | Filter | Same |
| `fluent_cart_payment_method_list_class` | Filter | `CheckoutRenderer.php` |
| `fluent_cart_editor/skip_no_conflict` | Filter | `FluentCartBlockEditorHandler.php` |
| `fluent_cart_editor/asset_listed_slugs` | Filter | Same |
| `fluent_cart_enqueue_block_editor_assets` | Action | Same |
| `fluent_cart_action_{$page}` | Action | `WebRoutes.php` |

### 3.5 Other One-Off Inconsistencies

| Hook Name | Prefix Used | Should Be | File |
|-----------|------------|-----------|------|
| `fluent_cart_sl_encoded_package_url` | `fluent_cart_sl_` | `fluent_cart_sl/` | `LicenseManager.php` |
| `fluent_cart_block_editor/head` | `fluent_cart_block_editor/` | `fluent_cart_editor/` (for consistency) | `FluentCartBlockEditorHandler.php` |
| `fluent_community/bricks/rendering_ajax_collection` | `fluent_community/` | `fluent_cart/` (copy-paste from FluentCommunity) | `BricksLoader.php:66` |

---

## 4. Documentation-Specific Issues

### 4.1 Missing Non-Standard Prefix Warning Notes (7 filter hooks) ✅ FIXED

These hooks use non-standard prefixes but their docs don't include a warning note (while similar hooks in the same files do):

| File | Hook | Status |
|------|------|--------|
| `filters/orders-and-payments.md` | `fluent-cart/editable_order_statuses` | ✅ Warning added |
| `filters/orders-and-payments.md` | `fluent-cart/editable_transaction_statuses` | ✅ Warning added |
| `filters/orders-and-payments.md` | `fluentcart/payment/success_url` | ✅ Warning added |
| `filters/orders-and-payments.md` | `fluentcart/transaction/receipt_page_url` | ✅ Warning added |
| `filters/cart-and-checkout.md` | `fluent_cart_payment_method_list_class` | ✅ Warning added |
| `filters/integrations-and-advanced.md` | `fluent-cart/util/countries` | ✅ Warning added |
| `filters/integrations-and-advanced.md` | `fluentcart/sanitize_user_meta` | ✅ Warning added |

> ✅ **Doc Fix Applied:** All 7 hooks now have blockquote warning notes about their non-standard prefix.

### 4.2 Incomplete Source File Paths

**Paddle hooks (12 hooks in `filters/orders-and-payments.md`):** All have source path ending at the directory level (`app/Modules/PaymentMethods/Paddle/`) without specifying the actual file.

**Authorize.net hook (1 hook):** Same issue — `app/Modules/PaymentMethods/AuthorizeNet/` without filename.

**Integration hooks (~40 hooks in `filters/integrations-and-advanced.md`):** Use filename-only format (e.g., `IntegrationActionResource.php`) instead of full relative paths.

### 4.3 Duplicate Hook Entry

`fluent_cart/payment_failed` is documented twice in `actions/payments-and-integrations.md`:
- Once for Airwallex (correctly shows single array param) ✅
- Once for Mollie (~~incorrectly shows positional params~~) ✅ Fixed to single array

Same hook name but dispatched with different array structures from different gateways. Both entries now correctly show single array params, but with different keys per gateway. ⚠️ Consider consolidating or more clearly differentiating in a future pass.

---

## 5. Formatting Inconsistencies in Docs

### 5.1 Source File Labels (5 formats across 6 filter files) ✅ FIXED

| Format | Used In | Status |
|--------|---------|--------|
| `**Source:**` | settings-and-configuration, orders-and-payments, customers-and-subscriptions, integrations-and-advanced | Standard ✅ |
| `**Source file:**` | products-and-pricing | ✅ Changed to `**Source:**` |
| `**Source files:**` | products-and-pricing (when multiple) | ✅ Changed to `**Source:**` |
| `**File reference:**` | cart-and-checkout | ✅ Changed to `**Source:**` |
| `**File references:**` | cart-and-checkout (when multiple) | ✅ Changed to `**Source:**` |

> ✅ **Doc Fix Applied:** All 63 label instances standardized to `**Source:**` across `products-and-pricing.md` (24) and `cart-and-checkout.md` (39).

---

## 6. Summary & Priority Matrix

### P0 — Critical (Broken code if devs follow the docs) ✅ ALL FIXED

| Issue | Scope | Files Fixed | Status |
|-------|-------|-------------|--------|
| Wrong parameter signatures (positional vs array) | 27 hooks | `actions/licenses.md`, `actions/subscriptions.md`, `actions/payments-and-integrations.md` | ✅ Fixed |
| `subscription_cancelled` hook name (will never fire) | 1 hook | `actions/subscriptions.md` | ✅ Fixed |

### P1 — High (Codebase bugs to fix)

| Issue | Scope | Files to Fix |
|-------|-------|-------------|
| `afrer` typo in hook name | 1 hook | `app/Services/Renderer/CheckoutRenderer.php` |
| `ansyc` typo in hook name | 1 hook | `app/Events/Order/OrderPaid.php` |
| `santized` typo in hook name | 1 hook | `fluent-cart-pro/.../LicenseModel.php` |
| `order_statues` typo in array key | 1 key | `app/Hooks/Handlers/MenuHandler.php` |
| `$actioName` variable typo | 2 files | `AdminOrderProcessor.php`, `CheckoutProcessor.php` |
| `subscription_cancelled` listener (dead code) | 1 listener | `ReminderHandler.php:29` |
| `fluent_community/` prefix (copy-paste bug) | 1 hook | `BricksLoader.php:66` |

### P2 — Medium (Naming inconsistencies to standardize)

| Issue | Scope | Suggestion |
|-------|-------|------------|
| `fluent-cart/` hyphen prefix | 11 hooks | Migrate to `fluent_cart/` with backward compat |
| `fluentcart/` missing underscore | 4 hooks | Migrate to `fluent_cart/` with backward compat |
| `fluent_cart_` underscore prefix | 14 hooks | Migrate to `fluent_cart/` with backward compat |
| `fluent_cart_sl_` (no slash) | 1 hook | Change to `fluent_cart_sl/` |
| Duplicate prefixed hooks (`fluent-cart/order_statuses` + `fluent_cart/order_statuses`) | ~5 hooks | Deprecate the hyphen versions |
| `fluent_cart_block_editor/` vs `fluent_cart_editor/` inconsistency | 1 hook | Pick one prefix |

### P3 — Low (Documentation polish)

| Issue | Scope | Status |
|-------|-------|--------|
| 7 filter hooks missing non-standard prefix warning notes | 7 hooks | ✅ Fixed |
| Incomplete source paths (Paddle, Authorize.net, integrations) | ~53 hooks | ⚠️ Pending |
| Inconsistent source file labels across filter docs | 2 files | ✅ Fixed |
| `canceled` vs `cancelled` spelling in orders.md variant list | 1 entry | ✅ Fixed |

---

## Appendix: Hook Count by Prefix

| Prefix | Actions | Filters | Total |
|--------|---------|---------|-------|
| `fluent_cart/` | ~171 | ~273 | ~444 |
| `fluent-cart/` | 1 | 10 | 11 |
| `fluentcart/` | 1 | 3 | 4 |
| `fluentcart_` | 1 | 0 | 1 |
| `fluent_cart_` | 3 | 11 | 14 |
| `fluent_cart_sl/` | 21 | 3 | 24 |
| `fluent_cart_sl_` | 0 | 1 | 1 |
| `fluent_cart_pro/` | 0 | 1 | 1 |
| `fluent_cart_editor/` | 0 | 2 | 2 |
| `fluent_cart_block_editor/` | 1 | 0 | 1 |
| `fluent_sl/` | 0 | 2 | 2 |
| **Total** | **~199** | **~306** | **~505** |

*Cross-plugin hooks (e.g., `fluent_auth/`, `fluent_support/`, WP core) are excluded from the count above.*

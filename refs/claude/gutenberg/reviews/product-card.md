# Review: fluent-cart/product-card

> **Reviewed:** 2026-02-26
> **Type:** Standalone (reference block — simplest pattern)
> **PHP:** `app/Hooks/Handlers/BlockEditors/ProductCardBlockEditor.php`
> **JSX:** `resources/admin/BlockEditor/ProductCard/ProductCardBlockEditor.jsx`

---

## Checks

### A. PHP Registration

| # | Check | Status | Notes |
|---|---|---|---|
| A1–A8 | All standard checks | PASS | Reference block — follows all patterns correctly |

### B. JSX Editor

| # | Check | Status | Notes |
|---|---|---|---|
| B1–B5 | Standard checks | PASS | |
| B6 | `save: () => null` | PASS | |
| B7 | Category `fluent-cart` | PASS | |
| B4 | `<ErrorBoundary>` | PASS | Reference implementation |

### D. Styling

| # | Check | Status | Notes |
|---|---|---|---|
| D5 | Safelist no duplicates | FAIL | Duplicate `'xl'` in variants |
| D11 | Content paths match | WARN | May point to wrong directory (copy-paste) |

### F. Render

| # | Check | Status | Notes |
|---|---|---|---|
| F1 | `Arr::get()` | PASS | |
| F2.1 | `absint()` on IDs | FAIL | `product_id` not sanitized with `absint()` |
| F2.7 | Raw attribute interpolation | FAIL | Shortcode injection risk — attributes interpolated into shortcode string |

### F1. Product Retrieval

| # | Check | Status | Notes |
|---|---|---|---|
| F1.1 | Explicit `product_id` first, fallback to `fluent_cart_get_current_product()` | PASS | |

### Issues

| Severity | Issue | Location |
|---|---|---|
| CRIT | Shortcode injection — raw attributes interpolated into shortcode string | PHP render method |
| WARN | Missing `absint()` on `product_id` | PHP render method |
| INFO | Duplicate `'xl'` in Tailwind safelist | Style config |

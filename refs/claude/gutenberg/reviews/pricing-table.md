# Review: fluent-cart/product-pricing-table

> **Reviewed:** 2026-02-26
> **Type:** Standalone
> **PHP:** `app/Hooks/Handlers/BlockEditors/PricingTableBlockEditor.php`
> **JSX:** `resources/admin/BlockEditor/PricingTable/PricingTableBlockEditor.jsx`
> **Renderer:** `app/Services/Renderer/PricingTableRenderer.php`

---

## Checks

### A. PHP Registration — PASS

### B. JSX Editor

| # | Check | Status | Notes |
|---|---|---|---|
| B1–B3 | Standard | PASS | |
| B4 | `<ErrorBoundary>` | FAIL | Missing |
| B5 | `blocktranslate()` | PASS | |
| B6 | `save: () => null` | PASS | |

### F. Render

| # | Check | Status | Notes |
|---|---|---|---|
| F1 | `Arr::get()` | PASS | |
| F2.1 | `absint()` on IDs | FAIL | `product_id` not sanitized |
| F2.7 | Raw attribute interpolation | FAIL | Shortcode injection risk |
| F3 | No direct DB queries | PASS | |

### Issues

| Severity | Issue | Location |
|---|---|---|
| CRIT | Shortcode injection — raw attribute values interpolated into shortcode string | PHP render method |
| WARN | Missing `absint()` on `product_id` | PHP render method |
| WARN | `renderDirectCheckoutButton` builds data but never outputs HTML | `PricingTableRenderer.php` |
| WARN | No `<ErrorBoundary>` | JSX edit component |
| WARN | Tailwind content path may point to wrong directory | Style config |

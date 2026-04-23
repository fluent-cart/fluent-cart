# Review: fluent-cart/product-image

> **Reviewed:** 2026-02-26
> **Type:** Standalone (also acts as parent — accepts InnerBlocks)
> **PHP:** `app/Hooks/Handlers/BlockEditors/ProductImageBlockEditor.php`
> **JSX:** `resources/admin/BlockEditor/ProductImage/ProductImageBlockEditor.jsx`

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
| F3.4 | `esc_url()` for URLs | PASS | Image URLs escaped |

### F1. Product Retrieval

| # | Check | Status | Notes |
|---|---|---|---|
| F1.1 | `product_id` first, fallback | PASS | |

### Issues

| Severity | Issue |
|---|---|
| WARN | Missing `absint()` on `product_id` |
| WARN | No `<ErrorBoundary>` |

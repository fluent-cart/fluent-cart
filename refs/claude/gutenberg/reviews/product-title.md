# Review: fluent-cart/product-title

> **Reviewed:** 2026-02-26
> **Type:** Standalone (leaf block)
> **PHP:** `app/Hooks/Handlers/BlockEditors/ProductTitleBlockEditor.php`
> **JSX:** `resources/admin/BlockEditor/ProductTitle/ProductTitleBlockEditor.jsx`

---

## Checks

### A. PHP Registration — PASS (all standard checks)

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
| F3.2 | `esc_html()` for text | PASS | Title escaped |

### F1. Product Retrieval

| # | Check | Status | Notes |
|---|---|---|---|
| F1.1 | `product_id` first, fallback | PASS | |

### Issues

| Severity | Issue |
|---|---|
| WARN | Missing `absint()` on `product_id` |
| WARN | No `<ErrorBoundary>` |

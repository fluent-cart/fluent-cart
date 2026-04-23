# Review: fluent-cart/price-range

> **Reviewed:** 2026-02-26
> **Type:** Standalone (leaf block)
> **PHP:** `app/Hooks/Handlers/BlockEditors/PriceRangeBlockEditor.php`
> **JSX:** `resources/admin/BlockEditor/PriceRange/PriceRangeBlockEditor.jsx`

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

### F4. JSX Security

| # | Check | Status | Notes |
|---|---|---|---|
| F4.2 | `dangerouslySetInnerHTML` | WARN | Used for formatted price HTML from API. Low risk (internal trusted data), but worth noting. |

### Issues

| Severity | Issue |
|---|---|
| WARN | Missing `absint()` on `product_id` |
| WARN | No `<ErrorBoundary>` |
| INFO | `dangerouslySetInnerHTML` for price display from internal API |

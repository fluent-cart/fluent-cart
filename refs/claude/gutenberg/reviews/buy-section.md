# Review: fluent-cart/buy-section

> **Reviewed:** 2026-02-26
> **Type:** Standalone (complex — variant selector + buy button)
> **PHP:** `app/Hooks/Handlers/BlockEditors/BuySectionBlockEditor.php`
> **JSX:** `resources/admin/BlockEditor/BuySection/BuySectionBlockEditor.jsx`

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
| F3 | No direct DB queries | PASS | Uses services/renderers |

### Issues

| Severity | Issue |
|---|---|
| WARN | Missing `absint()` on `product_id` |
| WARN | No `<ErrorBoundary>` |

# Review: fluent-cart/stock

> **Reviewed:** 2026-02-26
> **Type:** Standalone (conditionally registered — only when stock feature enabled)
> **PHP:** `app/Hooks/Handlers/BlockEditors/StockBlock.php`
> **JSX:** `resources/admin/BlockEditor/Stock/StockBlockEditor.jsx`

---

## Checks

### A. PHP Registration

| # | Check | Status | Notes |
|---|---|---|---|
| A1–A7 | Standard | PASS | |
| A8 | Registered in `actions.php` | PASS | Conditional: only when stock module active (line ~93) |

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
| F3.2 | `esc_html()` for text | PASS | Stock status text escaped |

### Issues

| Severity | Issue |
|---|---|
| WARN | Missing `absint()` on `product_id` |
| WARN | No `<ErrorBoundary>` |

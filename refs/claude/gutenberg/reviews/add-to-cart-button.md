# Review: fluent-cart/add-to-cart-button

> **Reviewed:** 2026-02-26
> **Type:** Standalone (button block)
> **PHP:** `app/Hooks/Handlers/BlockEditors/Buttons/AddToCartButtonBlockEditor.php`
> **JSX:** `resources/admin/BlockEditor/Buttons/AddToCartButton/AddToCartButtonBlockEditor.jsx`

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
| B8 | Category | PASS | `fluent-cart-buttons` |

### F. Render

| # | Check | Status | Notes |
|---|---|---|---|
| F1 | `Arr::get()` | PASS | |
| F2.1 | `absint()` on IDs | FAIL | `product_id` not sanitized |

### F4. JSX Security

| # | Check | Status | Notes |
|---|---|---|---|
| F4.2 | `dangerouslySetInnerHTML` | WARN | Used for button HTML from internal API |

### Issues

| Severity | Issue |
|---|---|
| WARN | Missing `absint()` on `product_id` |
| WARN | No `<ErrorBoundary>` |
| INFO | `dangerouslySetInnerHTML` for button content |

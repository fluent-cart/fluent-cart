# Review: fluent-cart/customer-profile

> **Reviewed:** 2026-02-26
> **Type:** Standalone
> **PHP:** `app/Hooks/Handlers/BlockEditors/CustomerProfileBlockEditor.php`
> **JSX:** `resources/admin/BlockEditor/CustomerProfile/CustomerProfileBlockEditor.jsx`

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
| F2.7 | Raw attribute interpolation | FAIL | Shortcode injection risk |

### D. Styling

| # | Check | Status | Notes |
|---|---|---|---|
| D11 | Content paths match | WARN | Tailwind content path may point to wrong directory |

### Issues

| Severity | Issue | Location |
|---|---|---|
| CRIT | Shortcode injection — raw attribute values interpolated into shortcode string | PHP render method |
| WARN | No `<ErrorBoundary>` | JSX edit component |
| WARN | Tailwind content path copy-paste error | Style config |

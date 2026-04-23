# Review: fluent-cart/customer-dashboard-button

> **Reviewed:** 2026-02-26
> **Type:** Standalone (button block)
> **PHP:** `app/Hooks/Handlers/BlockEditors/CustomerDashboardButtonBlockEditor.php`
> **JSX:** `resources/admin/BlockEditor/CustomerDashboardButton/CustomerDashboardButtonBlockEditor.jsx`

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
| F2 | Graceful fallback | PASS | |
| F3.4 | `esc_url()` | PASS | Dashboard URL escaped |

### Issues

| Severity | Issue |
|---|---|
| WARN | No `<ErrorBoundary>` |

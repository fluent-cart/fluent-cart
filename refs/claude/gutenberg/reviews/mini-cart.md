# Review: fluent-cart/mini-cart

> **Reviewed:** 2026-02-26
> **Type:** Standalone
> **PHP:** `app/Hooks/Handlers/BlockEditors/MiniCartBlockEditor.php`
> **JSX:** `resources/admin/BlockEditor/MiniCart/MiniCartBlockEditor.jsx`
> **Renderer:** `app/Services/Renderer/MiniCartRenderer.php`

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
| F2.1 | `absint()` on IDs | N/A | No product ID in this block |
| F3 | No direct DB queries | PASS | |

### F3. Output Escaping

| # | Check | Status | Notes |
|---|---|---|---|
| F3.3 | `esc_attr()` for HTML attributes | FAIL | `$buttonClass` echoed without `esc_attr()` in MiniCartRenderer |

### Issues

| Severity | Issue | Location |
|---|---|---|
| WARN | `$buttonClass` variable output without `esc_attr()` — potential XSS if class value is user-influenced | `app/Services/Renderer/MiniCartRenderer.php` |
| WARN | No `<ErrorBoundary>` | JSX edit component |
